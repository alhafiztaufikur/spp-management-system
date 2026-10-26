<?php
require_once __DIR__.'/legacy_billing.php';
require_once __DIR__.'/spp_billing.php';
require_once __DIR__.'/legacy_full_read.php';
require_once __DIR__.'/legacy_fee_reconstruction.php';
require_once __DIR__.'/legacy_du.php';
require_once __DIR__.'/legacy_du_reconstruction.php';

function lbr_audit(mysqli $db,int $actor,string $type,int $id,array $before,array $after,string $key): void {
    lf_query($db,'INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,?,?,?,?,?,?)',[unit_active_id(),$actor,$type,$id,hash('sha256','lbr2|'.$key),'Penyelarasan bukti Legacy; tanpa perubahan penerimaan kas',lf_json($before),lf_json($after)]);
}

/** Correct category only if no user correction has touched that payment. */
function lbr_map_komite(mysqli $db,int $actor,bool $apply,array &$summary): array {
    $batches=[];$corrected=[];$updates=[];
    foreach(hp_rows($db,"SELECT DISTINCT entity_id FROM legacy_op_audit WHERE entity_type='payment'") as $a)$corrected[(int)$a['entity_id']]=true;
    foreach(hp_rows($db,"SELECT p.* FROM legacy_op_payments p WHERE JSON_SEARCH(items,'one','U_SORGA',NULL,'$[*].column') IS NOT NULL") as $p){
        $items=json_decode($p['items'],true);$next=$items;$changed=false;
        foreach($next as &$item)if(strtoupper($item['column']??'')==='U_SORGA'&&str_starts_with($item['category']??'','histori:')){$item['category']='komite';$item['label']='Komite (POMG)';$item['mapping_rule']=LMP_VERSION;$changed=true;}unset($item);
        if(!$changed)continue;
        if(isset($corrected[(int)$p['id']])){$summary['exceptions'][]=['payment_id'=>$p['id'],'owner_id'=>$p['owner_id'],'reason'=>'U_SORGA pernah dikoreksi; pemetaan dipertahankan untuk peninjauan.'];continue;}
        $summary['komite_recategorized']++;$summary['changes']++;
        if($apply){$updates[]=['id'=>(int)$p['id'],'before'=>lf_json(['items'=>$items]),'after'=>lf_json(['items'=>$next]),'items'=>lf_json($next)];$batches[]=$p['batch_id'];}
    }
    foreach(array_chunk($updates,150) as $chunk){
        $cases=[];$params=[];$ids=[];$audits=[];$auditParams=[];
        foreach($chunk as $u){$cases[]='WHEN ? THEN ?';$params[]=$u['id'];$params[]=$u['items'];$ids[]=$u['id'];$audits[]='(?,?,?,?,?,?,?,?)';array_push($auditParams,unit_active_id(),$actor,'component_mapping',$u['id'],hash('sha256','lbr2|mapping|'.$u['id']),'U_SORGA menjadi Komite/POMG; penerimaan tetap',$u['before'],$u['after']);}
        lf_query($db,'UPDATE legacy_op_payments SET items=CASE id '.implode(' ',$cases).' END,version=version+1 WHERE id IN ('.implode(',',$ids).')',$params);
        lf_query($db,'INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES'.implode(',',$audits),$auditParams);
    }
    return $batches;
}

/** Native records only when the class AND academic year are explicit in the plan. */
function lbr_placement(mysqli $db,array $s,array $position): ?array {
    $year=$position['year'];$class=$position['class'];
    $existing=hp_one($db,'SELECT p.*,t.label,m.id master_id FROM siswa_tahun_ajaran p JOIN tahun_ajaran t ON t.id=p.tahun_ajaran_id AND t.unit_id=p.unit_id LEFT JOIN master_spp_tahun m ON m.tahun_ajaran_id=t.id AND m.unit_id=t.unit_id WHERE p.no_induk=? AND t.label=?',[$s['NO_INDUK'],$year]);
    if($existing)return $existing['status']!=='dibatalkan'&&strcasecmp($existing['kelas_rombel_snapshot'],$class)===0?$existing:null;
    // Paying a later school year does not prove that year's placement/class.
    if($year>$s['migration_year'])return null;
    preg_match('/^([0-9]{1,2})([A-Za-z]?)$/D',$class,$m);if(!$m)return null;
    [$min,$max]=unit_level_bounds();$level=(int)$m[1];if($level<$min||$level>$max)return null;
    $master=spp_master_ensure_year($db,$year,true);
    lf_query($db,'INSERT IGNORE INTO master_kelas(tingkat,kode_rombel,is_placeholder,is_active) VALUES(?,?,0,0)',[$level,strtoupper($m[2])]);
    $c=hp_one($db,"SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel=? AND jenis='reguler'",[$level,strtoupper($m[2])]);if(!$c)return null;
    // Historical membership is recorded, never made the student's current class.
    lf_query($db,"INSERT IGNORE INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,komite_mulai_bulan,status) VALUES(?,?,?,?,?,?,0,?,'pindah')",[$master['tahun_ajaran_id'],$s['NO_INDUK'],(string)$level,$c['id'],$class,$position['principal']/100,substr($position['period'],5,2)]);
    return hp_one($db,'SELECT p.*,? master_id FROM siswa_tahun_ajaran p WHERE p.no_induk=? AND p.tahun_ajaran_id=?',[$master['id'],$s['NO_INDUK'],$master['tahun_ajaran_id']]);
}

/** Caller owns unit transaction. Preview executes the same algorithm in a rollback. */
function lbr_reconcile(mysqli $db,int $actor): array {
    require_once __DIR__.'/legacy_operational.php';
    $syncBefore=(int)($GLOBALS['lb_sync_changes'][$db->thread_id]??0);
    $summary=['rule'=>LMP_VERSION,'psb_aligned'=>0,'komite_recategorized'=>0,'monthly_created'=>0,'start_month_aligned'=>0,'obligations_created'=>0,'changes'=>0,'components'=>[],'exceptions'=>[]];
    $summary['manifest_repairs']=0;
    foreach(hp_rows($db,"SELECT id,manifest FROM legacy_full_batches WHERE state='completed'") as $batch){
        $manifest=json_decode($batch['manifest'],true);$actual=lop_working_digest($db,$batch['id']);
        if(hash_equals($manifest['working_digest']??'',$actual))continue;
        require_once __DIR__.'/legacy_billing_digest.php';$prior=lop_prior_billing_digest($db,$batch['id']);
        if(!$prior||!hash_equals($manifest['working_digest']??'',$prior['digest']))throw new DomainException('Fingerprint batch berbeda; rekonsiliasi tidak diterapkan.');
        lbr_audit($db,$actor,'billing_digest',0,['digest'=>$manifest['working_digest']],['batch'=>$batch['id'],'digest'=>$actual,'derived_rows'=>$prior['derived_rows'],'reason'=>'Koreksi Master SPP lama tersimpan sah, pembaruan fingerprint tertunda.'],'digest|'.$batch['id'].'|'.$actual);
        lb_refresh_manifests($db,[$batch['id']]);$summary['manifest_repairs']++;$summary['changes']++;
    }
    $summary['fee_trace']=lfer_reconcile($db,$actor);$summary['changes']+=$summary['fee_trace']['changes'];
    $summary['du_payments']=ldu_reconstruct_payments($db,$actor);$summary['changes']+=$summary['du_payments']['changes'];
    $batches=lbr_map_komite($db,$actor,true,$summary);$data=lb_cache($db,true);
    $bills=[];$obligations=[];$coverage=[];
    foreach(['spp'=>'tagihan_spp','komite'=>'tagihan_komite'] as $category=>$table)foreach(hp_rows($db,"SELECT no_induk,bulan,tahun FROM $table") as $b)$bills[$b['no_induk']][$category.'|'.$b['tahun'].'-'.str_pad($b['bulan'],2,'0',STR_PAD_LEFT)]=true;
    foreach(hp_rows($db,'SELECT owner_id,category,period FROM legacy_op_obligations') as $o)$obligations[$o['owner_id']][$o['category'].'|'.$o['period']]=true;
    foreach(hp_rows($db,'SELECT student_id,category,period FROM legacy_full_coverage') as $c)$coverage[$c['student_id']][$c['category'].'|'.$c['period']]=true;
    foreach($data['students'] as $s){
        $native=hp_one($db,'SELECT * FROM siswa WHERE id=? FOR UPDATE',[$s['student_id']]);
        $psb=lb_psb_source($db,$s['NO_INDUK']);
        if(!empty($psb['known'])&&(lb_amount($native['PSB'])!==lb_result_cents($psb['gross'])||lb_amount($native['potongan_psb_nominal'])!==lb_result_cents($psb['discount']))){
            // Explicit user edits survive, including an explicit change to zero.
            $edited=false;
            foreach(hp_rows($db,'SELECT before_data,after_data FROM siswa_audit_log WHERE siswa_id=?',[$s['student_id']]) as $a){$before=json_decode($a['before_data']??'{}',true)??[];$after=json_decode($a['after_data']??'{}',true)??[];foreach(['PSB','potongan_psb_nominal'] as $f)if(isset($before[$f],$after[$f])&&lb_amount($before[$f])!==lb_amount($after[$f]))$edited=true;}
            if(!$edited&&(float)$native['PSB']===0.0&&(float)$native['potongan_psb_nominal']===0.0){
                lf_query($db,'UPDATE siswa SET PSB=?,potongan_psb_nominal=? WHERE id=?',[$psb['gross'],$psb['discount'],$s['student_id']]);
                lbr_audit($db,$actor,'psb_master',(int)$s['student_id'],array_intersect_key($native,array_flip(['PSB','potongan_psb_nominal'])),['PSB'=>$psb['gross'],'potongan_psb_nominal'=>$psb['discount'],'source_row_key'=>$psb['source_row_key']],'psb|'.$s['student_id']);$summary['psb_aligned']++;$summary['changes']++;
            }else $summary['exceptions'][]=['student_id'=>$s['student_id'],'reason'=>'Nilai PSB master/koreksi pengguna dipertahankan; berbeda dari sumber.'];
        }
        $context=$s;if(!(int)$native['is_active']&&$context['kind']==='active')$context['kind']='out';
        $plan=lmp_plan($context,$data['monthly_payments'][$s['id']]??[]);
        foreach($plan['exceptions'] as $e)if(!isset($coverage[$s['student_id']][$e['category'].'|'.$e['period']]))$summary['exceptions'][]=$e+['student_id'=>$s['student_id']];
        $placements=[];$insertSpp=[];$insertKomite=[];$insertOb=[];$created=[];
        foreach($plan['positions'] as $key=>$position){
            $period=$position['period'];$category=$position['category'];$principal=$position['principal'];
            if(isset($coverage[$s['student_id']][$key]))continue;
            $bucket=&$summary['components'][$category][$period];$bucket??=['count'=>0,'principal_cents'=>0,'legacy_paid_cents'=>0,'remaining_cents'=>0];$bucket['count']++;$bucket['principal_cents']+=$principal;$bucket['legacy_paid_cents']+=$position['paid'];$bucket['remaining_cents']+=$position['remaining'];unset($bucket);
            if(!array_key_exists($position['year'],$placements))$placements[$position['year']]=lbr_placement($db,$s,$position);
            $placement=$placements[$position['year']];
            if(!$placement||strcasecmp($placement['kelas_rombel_snapshot'],$position['class'])!==0){$summary['exceptions'][]=['student_id'=>$s['student_id'],'period'=>$period,'category'=>$category,'reason'=>'Penempatan dibatalkan atau kelas belum terbukti; tagihan tidak dibuat.'];continue;}
            [$calendar,$month]=explode('-',$period);
            $covered=$category==='spp'&&!empty($placement['spp_covered_by_psb']);
            if($covered){$principal=0;$position['gross']=0;$position['discount']=0;}
            if(!isset($bills[$s['NO_INDUK']][$key])){
                if($category==='spp'){
                    $master=$placement['master_id']?:spp_master_ensure_year($db,$position['year'],true)['id'];
                    $insertSpp[]=[$master,$placement['tahun_ajaran_id'],$placement['id'],$s['NO_INDUK'],$placement['kelas'],$placement['master_kelas_id'],$placement['kelas_rombel_snapshot'],$month,$calendar,$position['gross']/100,$position['discount']/100,$position['discount']/100,$principal/100,$covered?'covered_psb':($principal>0?'open':'waived')];
                }else $insertKomite[]=[$placement['tahun_ajaran_id'],$placement['id'],$s['NO_INDUK'],$placement['kelas_rombel_snapshot'],$month,$calendar,$principal/100];
                $created[]=$position;$summary['monthly_created']++;$summary['changes']++;
            }
            if(!isset($obligations[$s['id']][$key])){
                $evidence=$position['evidence']+['rule'=>LB_RULE_VERSION,'source_row_key'=>$s['source_row_key'],'gross'=>$position['gross']/100,'discount'=>$position['discount']/100];
                $insertOb[]=[$s['unit_id'],$s['id'],$category,$period,$principal/100,$position['remaining']/100,lf_json($evidence)];$summary['obligations_created']++;$summary['changes']++;
            }
        }
        lbr_insert_rows($db,'tagihan_spp','master_spp_tahun_id,tahun_ajaran_id,penempatan_id,no_induk,tingkat_snapshot,master_kelas_id,kelas_rombel_snapshot,bulan,tahun,tarif_dasar_snapshot,potongan_nominal_ditetapkan_snapshot,potongan_nominal_snapshot,nominal_tagihan,status',$insertSpp);
        foreach(array_unique(array_column($insertSpp,0)) as $masterId)lf_query($db,"UPDATE master_spp_tahun SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=? AND status='draft'",[$masterId]);
        lbr_insert_rows($db,'tagihan_komite','tahun_ajaran_id,penempatan_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan',$insertKomite);
        lbr_insert_rows($db,'legacy_op_obligations','unit_id,owner_id,category,period,principal,remaining,evidence',$insertOb);
        if($created)lbr_audit($db,$actor,'monthly_plan',(int)$s['student_id'],[],['positions'=>$created],'plan|'.$s['id'].'|'.hash('sha256',lf_json($created)));
        $current=$placements[$s['migration_year']]??null;
        if($current&&(int)$native['is_active']===1&&$current['status']==='aktif'&&$current['komite_mulai_bulan']===substr($s['settings']['start_period']??'',5,2)
            &&!hp_one($db,"SELECT id FROM siswa_audit_log WHERE siswa_id=? AND aksi<>'tambah' LIMIT 1",[$s['student_id']])
            &&!hp_one($db,"SELECT id FROM spp_audit_log WHERE no_induk=? AND aksi='ubah_mulai_komite' LIMIT 1",[$s['NO_INDUK']])){
            $periods=[];foreach($plan['positions'] as $position)if($position['year']===$s['migration_year']&&$position['class']===$current['kelas_rombel_snapshot'])$periods[]=$position['period'];
            if($periods){sort($periods);$month=substr($periods[0],5,2);if($month!==$current['komite_mulai_bulan']){hp_one($db,'SELECT id FROM siswa_tahun_ajaran WHERE id=? FOR UPDATE',[$current['id']]);lf_query($db,'UPDATE siswa_tahun_ajaran SET komite_mulai_bulan=? WHERE id=?',[$month,$current['id']]);lbr_audit($db,$actor,'billing_start',(int)$current['id'],['month'=>$current['komite_mulai_bulan']],['month'=>$month,'period'=>$periods[0]],'start|'.$current['id'].'|'.$periods[0]);$summary['start_month_aligned']++;$summary['changes']++;}}
        }
        $batches[]=$s['batch_id'];
    }
    // A stable key allows reruns to update only a changed exception, without noise.
    $byStudent=[];$wantedPending=[];foreach($data['students'] as $s)$byStudent[$s['student_id']]=$s;
    foreach($summary['exceptions'] as $e){$s=$byStudent[$e['student_id']??0]??null;if(!$s&&!empty($e['owner_id']))$s=hp_one($db,'SELECT * FROM legacy_op_owners WHERE id=?',[$e['owner_id']]);if(!$s)continue;
        $row=hash('sha256','lbr2|'.$s['id'].'|'.($e['category']??'psb').'|'.($e['period']??'once').'|'.($e['payment_id']??'').'|'.$e['reason']);
        $wantedPending[$row]=true;
        lf_query($db,"INSERT INTO legacy_op_pending(unit_id,batch_id,source_identity,row_key,section,code,message,payload) VALUES(?,?,?,?,'billing','monthly_evidence',?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)",[$s['unit_id'],$s['batch_id'],$s['source_identity'],$row,$e['reason'],lf_json($e)]);
    }
    $summary['review_resolved']=0;
    foreach(hp_rows($db,"SELECT id,row_key FROM legacy_op_pending WHERE code='monthly_evidence' AND resolved=0") as $p)if(!isset($wantedPending[$p['row_key']])){lf_query($db,'UPDATE legacy_op_pending SET resolved=1 WHERE id=?',[$p['id']]);$summary['review_resolved']++;$summary['changes']++;}
    lb_cache($db,true);$summary['du']=ldu_reconcile($db,$actor);$summary['changes']+=$summary['du']['changes'];
    $summary['one_time']=lb_reconcile($db,$actor,true);$summary['obligations_aligned']=(int)($GLOBALS['lb_sync_changes'][$db->thread_id]??0)-$syncBefore;$summary['changes']+=$summary['one_time']['changes']+$summary['obligations_aligned'];
    lb_refresh_manifests($db,$batches);
    foreach(array_unique($batches) as $id){
        $b=hp_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$id]);$m=json_decode($b['manifest'],true);
        if(($m['monthly_reconstruction_rule']??0)!==LMP_VERSION||$summary['changes']>0){$before=['rule'=>$m['monthly_reconstruction_rule']??null];$m['monthly_reconstruction_rule']=LMP_VERSION;$m['monthly_reconstruction_summary']=array_intersect_key($summary,array_flip(['psb_aligned','komite_recategorized','monthly_created','start_month_aligned','obligations_created','obligations_aligned']));lf_query($db,'UPDATE legacy_full_batches SET manifest=? WHERE id=?',[lf_json($m),$id]);lbr_audit($db,$actor,'billing_rules',0,$before,['batch'=>$id,'rule'=>LMP_VERSION,'summary'=>$m['monthly_reconstruction_summary']],'rules|'.$id.'|'.hash('sha256',lf_json($m['monthly_reconstruction_summary']).$m['working_digest']));$summary['changes']++;}
    }
    return $summary;
}

/** Bounded multi-row statements keep historical reconstruction practical. */
function lbr_insert_rows(mysqli $db,string $table,string $columns,array $rows): void {
    if(!$rows)return;
    foreach(array_chunk($rows,100) as $chunk){$slots=[];$params=[];foreach($chunk as $row){$slots[]='('.implode(',',array_fill(0,count($row),'?')).')';array_push($params,...$row);}lf_query($db,'INSERT INTO '.$table.'('.$columns.') VALUES'.implode(',',$slots),$params);}
}
