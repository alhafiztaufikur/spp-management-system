<?php
require_once __DIR__.'/legacy_billing.php';
require_once __DIR__.'/legacy_du_planner.php';
require_once __DIR__.'/daftar_ulang.php';

/** The current academic year is frozen in preview, never derived from NIS or cash date. */
function ldu_target_year(): string {return du_current_academic_year();}

/** One row per receipt and DU year; mixed-year slots never inherit MIN(year). */
function ldu_receipt_lines_sql(): string {
    $fallback=lma_legacy_du_year_sql('p');
    $year="CASE WHEN j.version IN (1,2,3,4) AND j.period REGEXP '^20[0-9]{2}/20[0-9]{2}$' AND CAST(RIGHT(j.period,4) AS UNSIGNED)=CAST(LEFT(j.period,4) AS UNSIGNED)+1 THEN j.period ELSE $fallback END";
    return "SELECT p.id payment_id,p.unit_id,$year year,SUM(j.amount) amount FROM legacy_op_payments p JOIN JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(category VARCHAR(100) PATH '$.category',period VARCHAR(20) PATH '$.migration.period' NULL ON EMPTY,version INT PATH '$.migration.version' NULL ON EMPTY,amount DECIMAL(20,2) PATH '$.amount')) j WHERE j.category='daftar_ulang' GROUP BY p.unit_id,p.id,$year";
}

/** Per-slot read model for both old/new metadata. Recaps remain archive evidence. */
function ldu_payment_items(mysqli $db,?int $owner=null): array {
    $where=" WHERE (p.source_table='bayar_du' OR JSON_SEARCH(p.items,'one','daftar_ulang',NULL,'$[*].category') IS NOT NULL)".($owner===null?'':' AND p.owner_id=?');$args=$owner===null?[]:[$owner];$out=[];
    foreach(hp_rows($db,"SELECT p.id,p.unit_id,p.batch_id,p.owner_id,p.row_key,p.source_table,p.source_period,p.source_date,p.cancelled,p.items,b.source_id,s.backup_hash,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.raw_data,'$.id')),JSON_UNQUOTE(JSON_EXTRACT(r.raw_data,'$.ID'))) source_record_id FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id LEFT JOIN legacy_finance_rows r ON r.unit_id=p.unit_id AND r.source_id=b.source_id AND r.row_key=p.row_key$where",$args) as $p){
        foreach(json_decode($p['items'],true,512,JSON_THROW_ON_ERROR) as $item){if(($item['category']??'')!=='daftar_ulang')continue;
            $year=lb_payment_year(lma_item_period($item,(string)$p['source_period']));$column=(string)($item['column']??$item['source_column']??'');
            $key=$p['unit_id'].'|'.$p['owner_id'].'|'.$year;
            $out[$key][]=['payment_id'=>(int)$p['id'],'unit_id'=>(int)$p['unit_id'],'source_id'=>(int)$p['source_id'],'source_hash'=>$p['backup_hash'],'source_row_key'=>$p['row_key'],'source_column'=>$column,'source_record_id'=>$item['migration']['source_record_id']??$p['source_record_id'],'year'=>$year,'amount_cents'=>lf_cents($item['amount']),'cancelled'=>(bool)$p['cancelled'],
                'detail_key'=>hash('sha256',lf_json([$p['unit_id'],$p['backup_hash'],$p['row_key'],$column]))];
        }
    }foreach($out as &$traces)usort($traces,static fn($a,$b)=>strcmp($a['detail_key'],$b['detail_key']));unset($traces);return $out;
}

/** Plan available from installed archive; reads do not publish years or create bills. */
function ldu_plan(mysqli $db,?string $year=null): array {
    $year=$year??ldu_target_year();$data=lb_cache($db);$positions=[];$items=ldu_payment_items($db);$live=[];$placements=[];$bills=[];$otherDebts=[];$recaps=[];
    foreach(hp_rows($db,"SELECT source_id,source_nis,row_key,raw_data FROM legacy_finance_rows WHERE source_table='bayar_du' AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(raw_data,'$.th_ajaran')))=?",[$year]) as $r){$v=json_decode($r['raw_data'],true);$amount=lb_amount($v['jumlah']??null);if($amount!==null)$recaps[$r['source_id'].'|'.trim((string)$r['source_nis'])][]=['row_key'=>$r['row_key'],'grade'=>(int)trim((string)($v['kelas']??'')),'amount_cents'=>$amount];}
    foreach(hp_rows($db,"SELECT d.student_id FROM legacy_debts d JOIN legacy_debt_masters m ON m.id=d.master_id AND m.unit_id=d.unit_id WHERE m.period=? AND m.component IN ('Daftar Ulang','DU')",[$year]) as $d)$otherDebts[(int)$d['student_id']]=true;
    foreach(hp_rows($db,'SELECT id,unit_id,NO_INDUK,is_active,KELAS FROM siswa') as $s)$live[$s['unit_id'].'|'.$s['NO_INDUK']]=$s;
    $target=hp_one($db,'SELECT * FROM tahun_ajaran WHERE label=?',[$year]);
    foreach(hp_rows($db,'SELECT p.* FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE y.label=?',[$year]) as $p)$placements[$p['unit_id'].'|'.$p['no_induk']]=$p;
    foreach(hp_rows($db,'SELECT * FROM tagihan_daftar_ulang WHERE tahun_ajaran_snapshot=?',[$year]) as $b)$bills[$b['unit_id'].'|'.$b['no_induk']]=$b;
    foreach($data['students'] as $key=>$s){$current=$live[$key]??null;if(!$current||!(int)$current['is_active']||$s['kind']!=='active')continue;
        $proof=lb_du_source($db,$s['NO_INDUK'],$year);$placement=$placements[$key]??null;$bill=$bills[$key]??null;
        $traces=$items[$s['unit_id'].'|'.$s['id'].'|'.$year]??[];$legacy=0;foreach($traces as $trace)if(!$trace['cancelled'])$legacy+=$trace['amount_cents'];
        // Preserve old archive-mode receipts through the existing credit reader.
        if(!$traces)$legacy=lb_result_cents(lb_du_credit($db,$s['NO_INDUK'],$year));
        $recapRows=[];foreach($recaps[$s['source_id'].'|'.trim($s['source_identity'])]??[] as $r)if($r['grade']===(int)($proof['grade']??0)&&$r['amount_cents']===$legacy)$recapRows[]=$r['row_key'];
        $native=$data['native_du'][$s['unit_id'].'|'.$s['NO_INDUK']][$year]??0;
        $position=ldu_position($proof,$legacy,$native,$bill&&$bill['status']==='open'?lf_cents($bill['nominal_tagihan']):null);
        $reason=null;
        if(empty($proof['known']))$reason=$proof['reason'];
        elseif(!$placement||$placement['status']!=='aktif'||(int)$placement['kelas']!==$proof['grade'])$reason='Penempatan aktif tahun DU belum cocok dengan bukti kelas sumber.';
        elseif(($target['status']??'')==='closed')$reason='Tahun DU tertutup; tagihan existing dipertahankan.';
        elseif($bill&&$bill['status']!=='open')$reason='Tagihan dibatalkan pengguna; tidak diterbitkan kembali.';
        elseif(!$bill&&lb_du_override($db,$s['NO_INDUK'],$year))$reason='Penetapan DU pengguna dipertahankan; tidak dibuat tagihan dari tarif sumber.';
        elseif(!$bill&&isset($otherDebts[(int)$s['student_id']]))$reason='Penagihan DU Legacy sudah tersedia; tagihan kedua tidak dibuat.';
        $positions[$key]=$position+['unit_id'=>(int)$s['unit_id'],'student_id'=>(int)$s['student_id'],'owner_id'=>(int)$s['id'],'nis'=>$s['NO_INDUK'],'source_identity'=>$s['source_identity'],'batch_id'=>$s['batch_id'],'source_id'=>(int)$s['source_id'],'year'=>$year,'proof'=>$proof,'placement'=>$placement,'bill'=>$bill,'traces'=>$traces,'recap_row_keys'=>$recapRows,'reason'=>$reason,'can_create'=>$reason===null&&!$bill];
    }
    return ['rule'=>LDU_VERSION,'year'=>$year,'target'=>$target,'positions'=>$positions];
}

/** Official year publication used by migration; per-student evidence may be partial. */
function du_publish_proven_legacy_year(mysqli $db,int $yearId,string $year,int $actor): void {
    $row=hp_one($db,'SELECT * FROM tahun_ajaran WHERE id=? AND label=? FOR UPDATE',[$yearId,$year]);
    if(!$row||$row['status']==='closed')throw new DomainException('Tahun DU tidak tersedia untuk penerbitan.');
    if($row['status']==='draft'){
        lf_query($db,"UPDATE tahun_ajaran SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=? AND status='draft'",[$yearId]);
        du_write_audit($db,$yearId,null,'terbitkan_tagihan',['status'=>'draft'],['status'=>'published','basis'=>'legacy_proven_students','actor'=>$actor],0);
    }
}

/** Caller owns transaction + verified backup gate. Changes only DU billing/evidence. */
function ldu_reconcile(mysqli $db,int $actor,?string $year=null): array {
    $year=$year??ldu_target_year();$plan=ldu_plan($db,$year);$out=['rule'=>LDU_VERSION,'year'=>$year,'changes'=>0,'masters_created'=>0,'bills_created'=>0,'existing'=>0,'unknown'=>0,'lunas'=>0,'belum_lunas'=>0,'principal_cents'=>0,'paid_cents'=>0,'remaining_cents'=>0,'exceptions'=>[]];
    if($year!==ldu_target_year())throw new DomainException('Penerbitan DU otomatis hanya untuk tahun ajaran berjalan.');
    $batches=[];$valid=array_filter($plan['positions'],static fn($p)=>$p['can_create']);
    if($valid&&$plan['target']&&$plan['target']['status']==='draft'){du_publish_proven_legacy_year($db,(int)$plan['target']['id'],$year,$actor);$out['changes']++;}
    foreach($plan['positions'] as $p){
        if($p['reason']!==null){$out['unknown']++;$out['exceptions'][]=['student_id'=>$p['student_id'],'nis'=>$p['nis'],'year'=>$year,'reason'=>$p['reason']];}
        $bill=$p['bill'];$proof=$p['proof'];
        if($p['can_create']){
            $placement=hp_one($db,'SELECT id FROM siswa_tahun_ajaran WHERE id=? FOR UPDATE',[$p['placement']['id']]);
            $master=hp_one($db,'SELECT * FROM Daftar_ulang WHERE tahun_ajaran_id=? AND kelas=? FOR UPDATE',[$plan['target']['id'],(string)$proof['grade']]);
            if(!$master){lf_query($db,'INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES(?,?,?,?)',[$plan['target']['id'],$year,(string)$proof['grade'],$proof['gross']]);$master=['id'=>(int)$db->insert_id];$out['masters_created']++;$out['changes']++;ldu_audit($db,$actor,'du_master',$p['student_id'],[],['master_id'=>$master['id'],'proof'=>$proof],$p);}
            $gate=(int)$db->query('SELECT COALESCE(@legacy_full_apply,0)')->fetch_row()[0];$db->query('SET @legacy_full_apply=1');
            try{lf_query($db,'INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,?,?)',[$plan['target']['id'],$placement['id'],$master['id'],$p['nis'],(string)$proof['grade'],$year,$proof['gross'],$proof['total']]);$createdBillId=(int)$db->insert_id;}
            finally{$db->query('SET @legacy_full_apply='.$gate);}
            $bill=hp_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$createdBillId]);$out['bills_created']++;$out['changes']++;ldu_audit($db,$actor,'du_bill',$p['student_id'],[],['bill_id'=>$bill['id'],'proof'=>$proof],$p);
        }elseif($bill)$out['existing']++;
        if(!$bill||$bill['status']!=='open')continue;
        $position=ldu_position($proof,$p['legacy_paid_cents'],$p['native_paid_cents'],lf_cents($bill['nominal_tagihan']));
        $out[$position['status']]++;foreach(['principal_cents','paid_cents','remaining_cents'] as $name)$out[$name]+=$position[$name];
        if(empty($proof['known']))continue; // Existing manual bill is preserved, not recertified from missing source evidence.
        if(($plan['target']['status']??'')==='closed')continue;
        $ob=hp_one($db,"SELECT * FROM legacy_op_obligations WHERE owner_id=? AND category='daftar_ulang' AND period=? FOR UPDATE",[$p['owner_id'],$year]);
        // Existing manually certified or legacy debt obligations are not rewritten.
        $old=$ob?json_decode($ob['evidence'],true):[];
        if($ob&&(!is_array($old)||!in_array($old['rule']??0,[1,2,3],true)||!empty($ob['bill_id'])))continue;
        $evidence=$old+['rule'=>LB_RULE_VERSION,'basis'=>'legacy','source_row_key'=>$proof['source_row_key'],'gross'=>$proof['gross'],'discount'=>$proof['discount']];
        $evidence['du']=['rule'=>LDU_VERSION,'year'=>$year,'master_id'=>(int)$bill['master_daftar_ulang_id'],'bill_id'=>(int)$bill['id'],'placement_id'=>(int)$bill['penempatan_id'],'source_id'=>$p['source_id'],'source_row_key'=>$proof['source_row_key'],'trace_count'=>count($p['traces']),'trace_digest'=>hash('sha256',lf_json($p['traces']))];
        $next=lf_json($evidence);
        if(!$ob){lf_query($db,"INSERT INTO legacy_op_obligations(unit_id,owner_id,category,period,principal,remaining,evidence) VALUES(?,?,'daftar_ulang',?,?,?,?)",[$p['unit_id'],$p['owner_id'],$year,lf_money($position['principal_cents']),lf_money($position['remaining_cents']),$next]);$ob=['id'=>(int)$db->insert_id,'evidence'=>null];}
        elseif(lf_canonical($old)===lf_canonical($evidence))continue;
        else lf_query($db,'UPDATE legacy_op_obligations SET evidence=?,version=version+1 WHERE id=?',[$next,$ob['id']]);
        $out['changes']++;$batches[]=$p['batch_id'];ldu_audit($db,$actor,'du_trace',(int)$ob['id'],['evidence'=>$ob['evidence']],['evidence'=>$evidence,'traces'=>$p['traces'],'tariff_row_keys'=>$proof['tariff_row_keys']??[],'recap_row_keys'=>$p['recap_row_keys']],$p);
    }
    $wanted=[];foreach($out['exceptions'] as $e){$row=hash('sha256','du|'.$year.'|'.$e['student_id']);$wanted[$row]=true;$p=reset($plan['positions']);foreach($plan['positions'] as $candidate)if($candidate['student_id']===$e['student_id']){$p=$candidate;break;}
        if(!hp_one($db,"SELECT id FROM legacy_op_pending WHERE batch_id=? AND row_key=? AND code='du_evidence'",[$p['batch_id'],$row])){lf_query($db,"INSERT INTO legacy_op_pending(unit_id,batch_id,source_identity,row_key,section,code,message,payload) VALUES(?,?,?,?,'billing','du_evidence',?,?)",[$p['unit_id'],$p['batch_id'],$p['source_identity'],$row,$e['reason'],lf_json($e)]);$out['changes']++;}
    }
    foreach(hp_rows($db,"SELECT id,row_key FROM legacy_op_pending WHERE code='du_evidence' AND resolved=0 AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.year'))=?",[$year]) as $p)if(!isset($wanted[$p['row_key']])){lf_query($db,'UPDATE legacy_op_pending SET resolved=1,version=version+1 WHERE id=?',[$p['id']]);$out['changes']++;}
    lb_cache($db,true);lb_sync_owners($db,array_column($plan['positions'],'owner_id'));lb_refresh_manifests($db,array_unique(array_merge($batches,array_column($valid,'batch_id'))));
    return $out;
}
function ldu_verify(mysqli $db): array {
    $plan=ldu_plan($db);$out=['rule'=>LDU_VERSION,'year'=>$plan['year'],'positions'=>[],'unknown'=>0,'lunas'=>0,'belum_lunas'=>0,'principal_cents'=>0,'paid_cents'=>0,'remaining_cents'=>0];
    foreach($plan['positions'] as $p){
        if($p['can_create'])throw new DomainException('Tagihan DU yang terbukti belum dibentuk untuk siswa '.$p['nis']);
        if(!$p['bill']||$p['bill']['status']!=='open'){$out['unknown']++;$out['positions'][]=['unit_id'=>$p['unit_id'],'student_id'=>$p['student_id'],'nis'=>$p['nis'],'status'=>'unknown','reason'=>$p['reason']];continue;}
        $bill=du_find_bill_by_id($db,(int)$p['bill']['id']);
        if(!$bill||lb_result_cents($bill['terbayar'])!==$p['paid_cents']||lb_result_cents($bill['sisa'])!==$p['remaining_cents'])throw new DomainException('Posisi DU tidak sama antara perencana dan kasir.');
        $master=hp_one($db,'SELECT id FROM Daftar_ulang WHERE id=? AND tahun_ajaran_id=?',[$p['bill']['master_daftar_ulang_id'],$p['bill']['tahun_ajaran_id']]);if(!$master)throw new DomainException('Master DU tidak sesuai tahun tagihan.');
        $ob=hp_one($db,"SELECT evidence FROM legacy_op_obligations WHERE owner_id=? AND category='daftar_ulang' AND period=?",[$p['owner_id'],$p['year']]);$evidence=json_decode($ob['evidence']??'{}',true);
        if(isset($evidence['du'])&&((int)$evidence['du']['bill_id']!==(int)$bill['id']||(int)$evidence['du']['master_id']!==(int)$master['id']||!hash_equals($evidence['du']['trace_digest'],hash('sha256',lf_json($p['traces'])))))throw new DomainException('Jejak DU tidak sesuai tagihan atau transaksi sumber.');
        $out[$p['status']]++;foreach(['principal_cents','paid_cents','remaining_cents'] as $k)$out[$k]+=$p[$k];
        $out['positions'][]=['unit_id'=>$p['unit_id'],'student_id'=>$p['student_id'],'nis'=>$p['nis'],'bill_id'=>(int)$bill['id'],'master_id'=>(int)$master['id'],'status'=>$p['status'],'principal_cents'=>$p['principal_cents'],'paid_cents'=>$p['paid_cents'],'remaining_cents'=>$p['remaining_cents'],'source_details'=>count($p['traces'])];
    }
    // Public history/report readers intentionally hide unpublished batches.
    // Cash, bill and master checks above still run inside the atomic apply;
    // the official worker repeats this full reader check after publication.
    if(!empty($GLOBALS['lb_pending_batch'])){$out['readers_verified']=0;$out['readers_deferred_until_completed']=true;return $out;}
    require_once __DIR__.'/reports.php';require_once __DIR__.'/registration_combined.php';
    $filters=report_filters($db,['template'=>'per-item','kategori'=>'daftar_ulang','tahun_ajaran'=>$plan['year'],'siswa_status'=>'active']);$report=[];
    foreach(report_annual_evidence($db,$filters)['rows'] as $r)$report[$r['unit_id'].'|'.$r['nis']]=$r;
    $history=[];foreach(hp_rows($db,'SELECT * FROM '.rc_groups($db).' x WHERE th_ajaran=?',[$plan['year']]) as $r)$history[$r['unit_id'].'|'.$r['student_id']]=$r;
    $out['readers_verified']=0;
    foreach($out['positions'] as $p){$key=$p['unit_id'].'|'.$p['nis'];$r=$report[$key]??null;
        if($p['status']==='unknown'){if(empty($plan['positions'][$key]['proof']['known'])&&$r&&($r['tagihan']!==null||$r['sisa']!==null))throw new DomainException('DU belum terbukti ditampilkan sebagai nominal diketahui pada laporan.');continue;}
        $g=$history[$p['unit_id'].'|'.$p['student_id']]??null;$e=$g?rc_tariff_evidence($db,$g):null;
        if(!$r||!$e||lb_result_cents($r['tagihan'])!==$p['principal_cents']||lb_result_cents($r['terbayar'])!==$p['paid_cents']||lb_result_cents($r['sisa'])!==$p['remaining_cents']||lb_result_cents($e['tariff'])!==$p['principal_cents']||lb_result_cents($e['remaining'])!==$p['remaining_cents'])throw new DomainException('DU berbeda antara transaksi, tagihan siswa, riwayat dan laporan.');
        $out['readers_verified']++;
    }
    return $out;
}
function ldu_audit(mysqli $db,int $actor,string $type,int $id,array $before,array $after,array $position): void {
    lf_query($db,'INSERT IGNORE INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,?,?,?,?,?,?)',[$position['unit_id'],$actor,$type,$id,hash('sha256','ldu1|'.$type.'|'.$id.'|'.lf_json($after)),'Pelacakan DU otomatis tahun berjalan; penerimaan kas dipertahankan',lf_json($before),lf_json($after)]);
}

/** Keep stored provenance current after an authorized payment correction/cancellation. */
function ldu_sync_owner_trace(mysqli $db,int $ownerId,int $actor): void {
    $owner=hp_one($db,'SELECT id,unit_id FROM legacy_op_owners WHERE id=?',[$ownerId]);if(!$owner)return;
    $items=ldu_payment_items($db,$ownerId);
    foreach(hp_rows($db,"SELECT * FROM legacy_op_obligations WHERE owner_id=? AND category='daftar_ulang'",[$ownerId]) as $ob){$e=json_decode($ob['evidence'],true);if(!isset($e['du']))continue;
        $traces=$items[$owner['unit_id'].'|'.$ownerId.'|'.$ob['period']]??[];$digest=hash('sha256',lf_json($traces));if(hash_equals($e['du']['trace_digest'],$digest))continue;
        $before=$e;$e['du']['trace_digest']=$digest;$e['du']['trace_count']=count($traces);lf_query($db,'UPDATE legacy_op_obligations SET evidence=?,version=version+1 WHERE id=?',[lf_json($e),$ob['id']]);
        ldu_audit($db,$actor,'du_trace',(int)$ob['id'],['evidence'=>$before],['evidence'=>$e,'traces'=>$traces],['unit_id'=>$owner['unit_id']]);
    }
}
