<?php
/** Shared, source-backed opening obligations. Historical credit is never new cash. */
require_once __DIR__.'/legacy_operational_read.php';
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/history_performance.php';
const LB_RULE_VERSION = 1;
// Only for arithmetic results already proven from exact source cents.
function lb_result_cents(float|string $value): int {return lf_cents(number_format((float)$value,2,'.',''));}

/** Limit evidence reads for a resolved student on a read-only request. Never used by writes. */
function lb_scope_student_read(mysqli $db,string $nis): void {
    if(PHP_SAPI!=='cli'&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new LogicException('Student read scope requires GET.');
    $GLOBALS['lb_read_student'][$db->thread_id]=$nis;
}

function lb_cache(mysqli $db, bool $refresh=false): array {
    static $cache=[];$scope=(int)($GLOBALS['app_unit_id']??unit_active_id());$prefix=$db->thread_id.':';
    $nis=$GLOBALS['lb_read_student'][$db->thread_id]??null;$key=$prefix.$scope.($nis===null?'':':student:'.$nis);
    $studentWhere=$nis===null?'':" AND s.NO_INDUK='".$db->real_escape_string($nis)."'";
    if($refresh)foreach(array_keys($cache) as $cachedKey)if(str_starts_with($cachedKey,$prefix))unset($cache[$cachedKey]);
    if(!$refresh&&isset($cache[$key]))return $cache[$key];
    // An all-unit report has already read this evidence under the connection's
    // authorized scope. Partition it by immutable owner/source IDs instead of
    // decoding the same payment archive again for each school.
    if($nis===null&&$scope>0&&isset($cache[$prefix.'0'])){
        $all=$cache[$prefix.'0'];$out=$all;$owners=[];$sources=[];
        $out['owner_units']=array_filter($all['owner_units'],static fn($unit)=>(int)$unit===$scope);
        $owners=array_fill_keys(array_keys($out['owner_units']),true);
        $out['students']=array_filter($all['students'],static fn($s)=>(int)$s['unit_id']===$scope);
        foreach($out['students'] as $s){$owners[(int)$s['id']]=true;$sources[(int)$s['source_id']]=true;}
        $out['tariffs']=array_intersect_key($all['tariffs'],$sources);
        foreach(['paid','original_psb'] as $name)$out[$name]=array_intersect_key($all[$name],$owners);
        foreach(['native_psb','native_du'] as $name)$out[$name]=array_filter($all[$name]??[],static fn($k)=>str_starts_with((string)$k,$scope.'|'),ARRAY_FILTER_USE_KEY);
        return $cache[$key]=$out;
    }
    $out=['students'=>[],'tariffs'=>[],'paid'=>[],'original_psb'=>[],'native_psb'=>[],'native_du'=>[],'owner_units'=>[]];
    if(!lop_ready($db))return $cache[$key]=$out;
    foreach(hp_rows($db,"SELECT o.*,b.source_id,b.year_id,b.cutoff,t.label migration_year,f.settings,s.NO_INDUK,s.PSB,s.potongan_psb_nominal,s.DAFTAR_ULANG,s.potong_du,s.tot_du FROM legacy_op_owners o JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_students f ON f.student_id=s.id AND f.unit_id=s.unit_id LEFT JOIN tahun_ajaran t ON t.id=b.year_id AND t.unit_id=b.unit_id WHERE b.state='completed'".$studentWhere) as $r){$r['settings']=json_decode($r['settings'],true);$r['raw']=null;$out['students'][$r['unit_id'].'|'.$r['NO_INDUK']]=$r;}
    if($nis!==null&&!$out['students'])return $cache[$key]=$out;
    $owners=implode(',',array_map('intval',array_column($out['students'],'id')))?:'0';
    $sources=implode(',',array_map('intval',array_column($out['students'],'source_id')))?:'0';
    $studentIds=implode(',',array_map('intval',array_column($out['students'],'student_id')))?:'0';
    $number=$nis===null?'':"'".$db->real_escape_string($nis)."'";
    $placements=[];foreach(hp_rows($db,'SELECT p.unit_id,p.no_induk,t.label,p.kelas FROM siswa_tahun_ajaran p JOIN tahun_ajaran t ON t.id=p.tahun_ajaran_id AND t.unit_id=p.unit_id'.($nis===null?'':" WHERE p.no_induk=$number")) as $p)$placements[$p['unit_id'].'|'.$p['no_induk']][$p['label']]=(int)$p['kelas'];
    foreach(hp_rows($db,"SELECT unit_id,entity_type,entity_id,after_data,id FROM legacy_op_audit WHERE entity_type IN ('billing_override','billing_rule')".($nis===null?'':" AND (entity_type='billing_rule' OR entity_id IN ($studentIds))")." ORDER BY id") as $audit){
        $decision=json_decode($audit['after_data'],true);
        foreach($out['students'] as &$student){if((int)$student['unit_id']!==(int)$audit['unit_id'])continue;$matches=$audit['entity_type']==='billing_override'?(int)$student['student_id']===(int)$audit['entity_id']:in_array($placements[$student['unit_id'].'|'.$student['NO_INDUK']][$decision['year']]??-1,$decision['levels'],true);
            if($matches)$student['settings']['billing_v1'][$decision['kind']][$decision['year']]=['rule'=>$decision['rule'],'version'=>$audit['id']];
        }unset($student);
    }
    $bySource=[];foreach($out['students'] as $k=>$r)$bySource[$r['source_id'].'|'.$r['source_identity']]=$k;
    $sourceMatches=[];foreach($out['students'] as $s)$sourceMatches[]="(source_id=".(int)$s['source_id']." AND TRIM(source_nis)='".$db->real_escape_string(trim((string)$s['source_identity']))."')";
    foreach(hp_rows($db,"SELECT source_id,source_nis,row_key,raw_data FROM legacy_finance_rows WHERE source_table='siswa'".($nis===null?'':' AND ('.implode(' OR ',$sourceMatches).')')) as $r){$k=$bySource[$r['source_id'].'|'.trim((string)$r['source_nis'])]??null;if(!$k)continue;$raw=json_decode($r['raw_data'],true);if($out['students'][$k]['raw']!==null&&$out['students'][$k]['raw']!==$raw){$out['students'][$k]['ambiguous']=true;continue;}$out['students'][$k]['raw']=$raw;$out['students'][$k]['source_row_key']=$r['row_key'];}
    foreach(hp_rows($db,"SELECT source_id,raw_data FROM legacy_finance_rows WHERE source_table IN ('Daftar_ulang','du')".($nis===null?'':" AND source_id IN ($sources)")) as $r){$raw=json_decode($r['raw_data'],true);$year=trim((string)($raw['th_ajaran']??''));$grade=trim((string)($raw['kelas']??''));$out['tariffs'][$r['source_id']][$year][$grade][]=$raw;}
    foreach(hp_rows($db,'SELECT unit_id,NO_INDUK,SUM(U_PSB) psb FROM bayar'.($nis===null?'':" WHERE NO_INDUK=$number").' GROUP BY unit_id,NO_INDUK') as $r)$out['native_psb'][$r['unit_id'].'|'.$r['NO_INDUK']]=lb_amount($r['psb'])??0;
    foreach(hp_rows($db,'SELECT t.unit_id,t.no_induk,t.tahun_ajaran_snapshot,SUM(b.jumlah) paid FROM tagihan_daftar_ulang t JOIN bayar_du b ON b.tagihan_daftar_ulang_id=t.id AND b.unit_id=t.unit_id'.($nis===null?'':" WHERE t.no_induk=$number").' GROUP BY t.unit_id,t.no_induk,t.tahun_ajaran_snapshot') as $r)$out['native_du'][$r['unit_id'].'|'.$r['no_induk']][$r['tahun_ajaran_snapshot']]=lb_amount($r['paid'])??0;
    $originals=[];foreach(hp_rows($db,"SELECT entity_id,before_data FROM legacy_op_audit WHERE entity_type='payment'".($nis===null?'':" AND entity_id IN (SELECT id FROM legacy_op_payments WHERE owner_id IN ($owners))").' ORDER BY id') as $a)if(!isset($originals[$a['entity_id']]))$originals[$a['entity_id']]=json_decode($a['before_data'],true);
    $cursor=$db->query(hp_sql($db,'SELECT id,unit_id,owner_id,source_table,source_period,items,total,cancelled FROM legacy_op_payments'.($nis===null?'':" WHERE owner_id IN ($owners)")),MYSQLI_USE_RESULT);
    while($p=$cursor->fetch_assoc()){
        $out['owner_units'][(int)$p['owner_id']]=(int)$p['unit_id'];
        $items=json_decode($p['items'],true);$initial=$originals[$p['id']]['items']??$items;
        foreach($initial as $i)if(lb_psb_item($i))$out['original_psb'][$p['owner_id']]=($out['original_psb'][$p['owner_id']]??0)+lf_cents($i['amount']);
        if($p['cancelled'])continue;$owner=$p['owner_id'];
        foreach($items as $i)if(lb_psb_item($i))$out['paid'][$owner]['psb']=($out['paid'][$owner]['psb']??0)+lf_cents($i['amount']);
        $du=$p['source_table']==='bayar_du'?lb_result_cents($p['total']):0;if($p['source_table']!=='bayar_du')foreach($items as $i)if(($i['category']??'')==='daftar_ulang')$du+=lf_cents($i['amount']);
        $month=lb_payment_month((string)$p['source_period']);if($month!=='')foreach($items as $i)if(($i['category']??'')==='spp')$out['paid'][$owner]['spp'][$month]=($out['paid'][$owner]['spp'][$month]??0)+lf_cents($i['amount']);
        $year=lb_payment_year((string)$p['source_period']);$out['paid'][$owner]['du'][$year]=($out['paid'][$owner]['du'][$year]??0)+$du;
    }$cursor->free();
    return $cache[$key]=$out;
}
function lb_psb_item(array $item): bool {return strtoupper((string)($item['column']??''))==='U_PANGKAL'||($item['category']??'')==='psb';}
function lb_student(mysqli $db,string $nis,?int $unit=null): ?array {return lb_cache($db)['students'][($unit??(int)($GLOBALS['app_unit_id']??unit_active_id())).'|'.$nis]??null;}
function lb_amount($value): ?int {try{return $value===null||$value===''?null:lf_cents($value);}catch(Throwable $e){return null;}}
function lb_du_credit(mysqli $db,string $nis,string $year,?int $unit=null): float {$s=lb_student($db,$nis,$unit);return $s?(lb_cache($db)['paid'][$s['id']]['du'][$year]??0)/100:0.0;}
function lb_psb_source(mysqli $db,string $nis): ?array {
    $s=lb_student($db,$nis);if(!$s)return null;$raw=$s['raw']??[];$gross=lb_amount($raw['tot_pangkal']??null);$discount=lb_amount($raw['potong_pangkal']??null);$net=lb_amount($raw['PANGKAL']??null);$paid=lb_amount($raw['PANGKAL_BAYAR']??null);$data=lb_cache($db);
    $unknown=['known'=>false,'total'=>0.0,'paid'=>0.0,'remaining'=>0.0,'billing_state'=>'unknown','basis'=>'legacy','reason'=>'Dasar atau pembayaran PSB sumber belum lengkap.'];
    if(!empty($s['ambiguous'])||$gross===null||$discount===null||$net===null||$paid===null||$gross-$discount!==$net||$paid<($data['original_psb'][$s['id']]??0))return $unknown;
    // The cumulative source header includes its detailed receipts. Only the correction delta is added.
    $credit=$paid+($data['paid'][$s['id']]['psb']??0)-($data['original_psb'][$s['id']]??0);
    return ['known'=>true,'total'=>$net/100,'paid'=>max(0,$credit)/100,'remaining'=>max(0,$net-$credit)/100,'billing_state'=>'known','basis'=>'legacy','gross'=>$gross/100,'discount'=>$discount/100,'source_row_key'=>$s['source_row_key']];
}
function lb_du_source(mysqli $db,string $nis,string $year): ?array {
    $s=lb_student($db,$nis);if(!$s)return null;$unknown=['known'=>false,'basis'=>'legacy','reason'=>'Tarif, kelas, periode, atau potongan DU belum dapat dibuktikan.'];$raw=$s['raw']??[];
    if(!empty($s['ambiguous'])||!preg_match('/^([0-9]{1,2})[A-Za-z]?$/D',trim((string)($raw['KELAS']??'')),$m))return $unknown;
    $grade=$m[1];$data=lb_cache($db);$years=array_keys($data['tariffs'][$s['source_id']]??[]);sort($years,SORT_STRING);
    // A student header describes the source's current year only, never an inferred historical discount.
    if($year!==$s['migration_year']||$year!==end($years))return $unknown;
    $fees=[];foreach($data['tariffs'][$s['source_id']][$year][$grade]??[] as $row){$v=lb_amount($row['Jumlah']??$row['jumlah']??null);if($v===null)return $unknown;$fees[$v]=true;}
    $discount=lb_amount($raw['potong_du']??null);if(count($fees)!==1||$discount===null)return $unknown;$gross=(int)array_key_first($fees);if($discount>$gross)return $unknown;
    $declared=lb_amount($raw['DAFTAR_ULANG']??null);$net=lb_amount($raw['tot_du']??null);
    if($declared!==null&&$declared>0&&($declared!==$gross||($net!==null&&$net!==$gross-$discount)))return $unknown;
    return ['known'=>true,'basis'=>'legacy','total'=>($gross-$discount)/100,'gross'=>$gross/100,'discount'=>$discount/100,'year'=>$year,'grade'=>(int)$grade,'source_row_key'=>$s['source_row_key'],'reason'=>'Tarif tahun/kelas sumber unik; potongan eksplisit pada header tahun peralihan dan tahun terbaru sumber.'];
}
function lb_spp_override(mysqli $db,string $nis,string $year): bool {$s=lb_student($db,$nis);return !empty($s['settings']['billing_v1']['spp_master'][$year]);}
function lb_mark_spp_master(mysqli $db,string $year,array $levels): void {lb_mark_master($db,$year,$levels,'spp_master');}
function lb_set_override(mysqli $db,array $student,string $year,string $kind): void {
    $actor=(int)($_SESSION['admin_id']??0);$decision=['rule'=>LB_RULE_VERSION,'kind'=>$kind,'year'=>$year,'basis'=>'system','actor'=>$actor];
    lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'billing_override',?,?,?,'{}',?)",[$student['unit_id'],$actor,$student['student_id'],bin2hex(random_bytes(32)),'Perubahan tarif eksplisit; hanya tagihan tanpa pembayaran yang mengikuti',lf_json($decision)]);
}
/** Historical credit is eligible for an operational bill only with complete evidence. */
function lb_du_bill_credit(mysqli $db,string $nis,string $year): float {
    $proof=lb_du_source($db,$nis,$year);
    return $proof&&!empty($proof['known'])?lb_du_credit($db,$nis,$year):0.0;
}
function lb_du_override(mysqli $db,string $nis,string $year): bool {return !empty(lb_student($db,$nis)['settings']['billing_v1']['du_master'][$year])||!empty(lb_student($db,$nis)['settings']['billing_v1']['du_individual'][$year]);}
function lb_mark_du_master(mysqli $db,string $year,array $levels): void {lb_mark_master($db,$year,$levels,'du_master');}
function lb_mark_master(mysqli $db,string $year,array $levels,string $kind): void {
    if(!lop_ready($db))return;$record=lf_one($db,'SELECT id FROM tahun_ajaran WHERE label=?',[$year]);if(!$record)throw new DomainException('Tahun tarif tidak ditemukan.');
    $decision=['rule'=>LB_RULE_VERSION,'kind'=>$kind,'year'=>$year,'levels'=>array_map('intval',$levels),'basis'=>'system','actor'=>(int)($_SESSION['admin_id']??0)];
    lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'billing_rule',?,?,?,'{}',?)",[unit_active_id(),$decision['actor'],$record['id'],bin2hex(random_bytes(32)),'Master disimpan eksplisit untuk tahun/tingkat; snapshot terbayar dipertahankan',lf_json($decision)]);lb_cache($db,true);
}
function lb_refresh_manifests(mysqli $db,array $batchIds): void {
    require_once __DIR__.'/legacy_operational_corrections.php';
    foreach(array_unique($batchIds) as $id){$b=lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=? FOR UPDATE',[$id]);$m=json_decode($b['manifest'],true);$m['working_digest']=lop_working_digest($db,(string)$id);lf_query($db,'UPDATE legacy_full_batches SET manifest=? WHERE id=?',[lf_json($m),$id]);}
}
/** Read the same source-backed position for synchronization and integrity checks. */
function lb_obligation_position(mysqli $db,array $ob): ?array {
    $e=json_decode($ob['evidence'],true);
    if(!is_array($e)||($e['rule']??null)!==LB_RULE_VERSION)return null;
    $data=lb_cache($db);$student=null;
    foreach($data['students'] as $candidate)if((int)$candidate['id']===(int)$ob['owner_id']&&(int)$candidate['unit_id']===(int)$ob['unit_id']){$student=$candidate;break;}
    if(!$student||($e['source_row_key']??'')!==($student['source_row_key']??null))throw new DomainException('Bukti kewajiban tidak sesuai arsip pemilik.');
    $s=$student;
    if($ob['category']==='psb'){
        $p=lb_psb_source($db,$s['NO_INDUK']);if(!$p||!$p['known'])return null;
        $principal=lb_result_cents($p['total']);$credit=lb_result_cents($p['paid'])+($data['native_psb'][$s['unit_id'].'|'.$s['NO_INDUK']]??0);
    }elseif($ob['category']==='daftar_ulang'){
        $p=lb_du_source($db,$s['NO_INDUK'],$ob['period']);if(!$p||!$p['known'])return null;
        $bill=hp_one($db,"SELECT nominal_tagihan FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=? AND status='open'",[$s['NO_INDUK'],$ob['period']]);
        $principal=lb_result_cents($bill['nominal_tagihan']??$p['total']);$credit=lb_result_cents(lb_du_credit($db,$s['NO_INDUK'],$ob['period']))+($data['native_du'][$s['unit_id'].'|'.$s['NO_INDUK']][$ob['period']]??0);
    }elseif($ob['category']==='spp'){
        [$year,$month]=explode('-',$ob['period']);
        $bill=hp_one($db,"SELECT t.id,t.nominal_tagihan,COALESCE(SUM(CASE WHEN b.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid FROM tagihan_spp t LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=t.id LEFT JOIN spp_alokasi_batch b ON b.id=a.batch_id WHERE t.no_induk=? AND t.bulan=? AND t.tahun=? AND t.status='open' GROUP BY t.id",[$s['NO_INDUK'],$month,$year]);
        if(!$bill)return null;$principal=lf_cents($bill['nominal_tagihan']);$credit=lb_result_cents(lb_spp_credit($db,$s['NO_INDUK'],$month,$year))+lf_cents($bill['paid']);
    }else return null;
    return ['principal'=>$principal,'credit'=>$credit,'remaining'=>max(0,$principal-$credit)];
}
/** Recalculate proven opening obligations inside the caller's financial transaction. */
function lb_sync_owners(mysqli $db,array $ownerIds): array {
    lb_cache($db,true);$batches=[];$data=lb_cache($db);
    foreach($data['students'] as $s){if(!in_array((int)$s['id'],array_map('intval',$ownerIds),true))continue;
        foreach(lf_rows($db,'SELECT * FROM legacy_op_obligations WHERE owner_id=? ORDER BY id FOR UPDATE',[$s['id']]) as $ob){
            $position=lb_obligation_position($db,$ob);if(!$position)continue;
            $principal=$position['principal'];$remaining=$position['remaining'];
            if($remaining!==lf_cents($ob['remaining'])||$principal!==lf_cents($ob['principal'])){lf_query($db,'UPDATE legacy_op_obligations SET principal=?,remaining=?,version=version+1 WHERE id=?',[lf_money($principal),lf_money($remaining),$ob['id']]);$batches[]=$s['batch_id'];}
        }
    }return array_unique($batches);
}
/** Caller owns the transaction. Dry run reports exactly the writes the apply will make. */
function lb_reconcile(mysqli $db,int $actor,bool $apply=false): array {
    $summary=['psb_ready'=>0,'du_ready'=>0,'du_created'=>0,'du_corrected'=>0,'unknown'=>0,'existing'=>0,'changes'=>0];if(!lop_ready($db))return $summary;
    $batches=[];$data=lb_cache($db,true);
    foreach($data['students'] as $s){
        $psb=lb_psb_source($db,$s['NO_INDUK']);$proofs=[];if($psb['known']){$summary['psb_ready']++;$proofs['psb|once']=$psb;}else $summary['unknown']++;
        $year=$s['migration_year'];$du=lb_du_source($db,$s['NO_INDUK'],$year);if($du['known']){$summary['du_ready']++;$proofs['daftar_ulang|'.$year]=$du;}else $summary['unknown']++;
        foreach($data['paid'][$s['id']]['spp']??[] as $period=>$paidCents){
            if($period<($s['settings']['start_period']??''))continue;[$calendar,$month]=explode('-',$period);
            $bill=lf_one($db,"SELECT id,tarif_dasar_snapshot,potongan_nominal_snapshot,nominal_tagihan FROM tagihan_spp WHERE no_induk=? AND bulan=? AND tahun=? AND status='open'",[$s['NO_INDUK'],$month,$calendar]);
            if($bill)$proofs['spp|'.$period]=['known'=>true,'total'=>(float)$bill['nominal_tagihan'],'gross'=>(float)$bill['tarif_dasar_snapshot'],'discount'=>(float)$bill['potongan_nominal_snapshot'],'paid'=>$paidCents/100,'source_row_key'=>$s['source_row_key'],'basis'=>'legacy','bill_id'=>$bill['id']];
        }
        foreach($proofs as $key=>$proof){[$category,$period]=explode('|',$key);$ob=lf_one($db,'SELECT * FROM legacy_op_obligations WHERE owner_id=? AND category=? AND period=?',[$s['id'],$category,$period]);if($ob){$summary['existing']++;continue;}
            $credit=$category==='spp'?$proof['paid']:( $category==='psb'?$proof['paid']+($data['native_psb'][$s['unit_id'].'|'.$s['NO_INDUK']]??0)/100:lb_du_credit($db,$s['NO_INDUK'],$period)+($data['native_du'][$s['unit_id'].'|'.$s['NO_INDUK']][$period]??0)/100);
            $remaining=max(0,$proof['total']-$credit);$summary['changes']++;
            if(!$apply)continue;$evidence=lf_json(['rule'=>LB_RULE_VERSION,'basis'=>'legacy','source_row_key'=>$proof['source_row_key'],'gross'=>$proof['gross'],'discount'=>$proof['discount'],'opening_credit'=>$category==='psb'?$proof['paid']:null]);
            lf_query($db,'INSERT INTO legacy_op_obligations(unit_id,owner_id,category,period,principal,remaining,evidence) VALUES(?,?,?,?,?,?,?)',[$s['unit_id'],$s['id'],$category,$period,number_format($proof['total'],2,'.',''),number_format($remaining,2,'.',''),$evidence]);$id=(int)$db->insert_id;$batches[]=$s['batch_id'];
            lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'billing_reconcile',?,?,?,'{}',?)",[$s['unit_id'],$actor,$id,hash('sha256','lb1|'.$s['batch_id'].'|'.$s['id'].'|'.$key),'Rekonsiliasi kewajiban dari bukti sumber tanpa kas baru',lf_json($proof)]);
        }
        if(!$du['known']||$s['kind']!=='active')continue;
        $placement=lf_one($db,"SELECT p.*,t.status year_status FROM siswa_tahun_ajaran p JOIN tahun_ajaran t ON t.id=p.tahun_ajaran_id AND t.unit_id=p.unit_id WHERE p.no_induk=? AND p.tahun_ajaran_id=? AND p.status='aktif'",[$s['NO_INDUK'],$s['year_id']]);if(!$placement||$placement['year_status']!=='published')continue;
        $bill=lf_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_id=?',[$s['NO_INDUK'],$s['year_id']]);
        if($bill){
            if($bill['status']==='open'&&!lb_du_override($db,$s['NO_INDUK'],$year)&&empty($data['native_du'][$s['unit_id'].'|'.$s['NO_INDUK']][$year])&&(lf_cents($bill['nominal_awal'])!==lb_result_cents($du['gross'])||lf_cents($bill['nominal_tagihan'])!==lb_result_cents($du['total']))){
                $summary['du_corrected']++;$summary['changes']++;
                if($apply){lf_query($db,'UPDATE tagihan_daftar_ulang SET nominal_awal=?,nominal_tagihan=? WHERE id=?',[$du['gross'],$du['total'],$bill['id']]);lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'billing_snapshot',?,?,?, ?,?)",[$s['unit_id'],$actor,$bill['id'],hash('sha256','lb1bill|'.$bill['id']),'Tarif awal mengikuti bukti Legacy; belum ada pembayaran native',lf_json($bill),lf_json($du)]);}
            }continue;
        }
        $master=lf_one($db,'SELECT id FROM Daftar_ulang WHERE tahun_ajaran_id=? AND kelas=?',[$s['year_id'],(string)$du['grade']]);
        if(!$master){$summary['unknown']++;continue;}$summary['du_created']++;$summary['changes']++;
        if(!$apply)continue;
        lf_query($db,"INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,?,?)",[$s['year_id'],$placement['id'],$master['id'],$s['NO_INDUK'],$placement['kelas'],$year,$du['gross'],$du['total']]);$billId=(int)$db->insert_id;
        lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'billing_snapshot',?,?,?,'{}',?)",[$s['unit_id'],$actor,$billId,hash('sha256','lb1bill|'.$billId),'Melengkapi tagihan tahun terbit dari bukti Legacy',lf_json($du)]);
    }
    if($apply){$batches=array_merge($batches,lb_sync_owners($db,array_column($data['students'],'id')));lb_refresh_manifests($db,$batches);}return $summary;
}

/** Shared report adaptor: credit changes outstanding balances, never cash totals. */
function lb_report_amounts(mysqli $db,string $nis,string $category,string $year,float $bill,float $paid): ?array {
    $s=lb_student($db,$nis);if(!$s)return null;
    if($category==='psb'){$proof=lb_psb_source($db,$nis);if(!$proof['known'])return ['known'=>false,'total'=>null,'paid'=>$paid,'remaining'=>null];$bill=(float)$s['PSB']>0?max(0,(float)$s['PSB']-(float)$s['potongan_psb_nominal']):$proof['total'];$paid+=$proof['paid'];}
    elseif($category==='daftar_ulang'){$proof=lb_du_source($db,$nis,$year);if(!$proof['known']&&$bill<=0)return ['known'=>false,'total'=>null,'paid'=>$paid,'remaining'=>null];if(!$proof['known'])return null;$bill=$bill>0?$bill:$proof['total'];$paid+=lb_du_credit($db,$nis,$year);}
    else return null;
    return ['known'=>true,'total'=>$bill,'paid'=>$paid,'remaining'=>max(0,$bill-$paid)];
}

function lb_mark_du_individual(mysqli $db,string $nis,string $year): void {
    $s=lb_student($db,$nis);if(!$s)return;lb_set_override($db,$s,$year,'du_individual');lb_cache($db,true);
}

/** Only an explicit payment period supplies an academic year; dates/NIS do not. */
function lb_payment_year(string $period): string {
    $period=trim($period);
    if(preg_match('~^(\d{4})/(\d{4})$~D',$period,$m))return (int)$m[2]===(int)$m[1]+1?$period:'';
    if(preg_match('~^(\d{1,2}) (\d{4})$~D',$period,$m)){$month=(int)$m[1];$year=(int)$m[2];}
    elseif(preg_match('~^(\d{4})-(\d{2})$~D',$period,$m)){$month=(int)$m[2];$year=(int)$m[1];}
    else return '';
    if($month<1||$month>12||$year<2000||$year>2200)return '';$first=$year-($month<7?1:0);return $first.'/'.($first+1);
}
function lb_du_year_sql(string $alias='p'): string {
    if(!preg_match('/^[a-z]+$/D',$alias))throw new LogicException('Invalid alias');$v="TRIM($alias.source_period)";
    return "CASE WHEN $v REGEXP '^[12][0-9]{3}/[12][0-9]{3}$' AND CAST(RIGHT($v,4) AS UNSIGNED)=CAST(LEFT($v,4) AS UNSIGNED)+1 THEN $v WHEN $v REGEXP '^(0?[1-9]|1[0-2]) [12][0-9]{3}$' THEN CONCAT(CAST(RIGHT($v,4) AS UNSIGNED)-(CAST(SUBSTRING_INDEX($v,' ',1) AS UNSIGNED)<7),'/',CAST(RIGHT($v,4) AS UNSIGNED)+(CAST(SUBSTRING_INDEX($v,' ',1) AS UNSIGNED)>=7)) WHEN $v REGEXP '^[12][0-9]{3}-(0[1-9]|1[0-2])$' THEN CONCAT(CAST(LEFT($v,4) AS UNSIGNED)-(CAST(RIGHT($v,2) AS UNSIGNED)<7),'/',CAST(LEFT($v,4) AS UNSIGNED)+(CAST(RIGHT($v,2) AS UNSIGNED)>=7)) ELSE NULL END";
}

function lb_payment_month(string $period): string {
    $period=trim($period);if(preg_match('~^(\d{1,2}) (\d{4})$~D',$period,$m)){$month=(int)$m[1];$year=(int)$m[2];}elseif(preg_match('~^(\d{4})-(\d{2})$~D',$period,$m)){$year=(int)$m[1];$month=(int)$m[2];}else return '';
    return $month>=1&&$month<=12&&$year>=2000&&$year<=2200?sprintf('%04d-%02d',$year,$month):'';
}
function lb_spp_credit(mysqli $db,string $nis,string $month,string $year): float {
    $student=lb_student($db,$nis);if(!$student)return 0.0;$key=sprintf('%04d-%02d',(int)$year,(int)$month);return (lb_cache($db)['paid'][$student['id']]['spp'][$key]??0)/100;
}

function lb_native_du_cents(mysqli $db,string $nis,string $year): int {
    $unit=(int)($GLOBALS['app_unit_id']??unit_active_id());return lb_cache($db)['native_du'][$unit.'|'.$nis][$year]??0;
}
