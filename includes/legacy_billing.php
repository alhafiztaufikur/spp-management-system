<?php
/** Shared, source-backed opening obligations. Historical credit is never new cash. */
require_once __DIR__.'/legacy_operational_read.php';
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/legacy_migration_automation.php';
require_once __DIR__.'/history_performance.php';
require_once __DIR__.'/legacy_monthly_planner.php';
require_once __DIR__.'/legacy_du_planner.php';
const LB_RULE_VERSION = 7;
// Only for arithmetic results already proven from exact source cents.
function lb_result_cents(float|string $value): int {return lf_cents(number_format((float)$value,2,'.',''));}

/** Limit evidence reads for a resolved student on a read-only request. Never used by writes. */
function lb_scope_student_read(mysqli $db,string $nis): void {
    if(PHP_SAPI!=='cli'&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new LogicException('Student read scope requires GET.');
    $GLOBALS['lb_read_student'][$db->thread_id]=$nis;
}

function lb_cache(mysqli $db, bool $refresh=false): array {
    $profileStarted=microtime(true);
    static $cache=[];$scope=(int)($GLOBALS['app_unit_id']??unit_active_id());$compact=!empty($GLOBALS['lb_report_compact'][$db->thread_id]);$readProjection=!empty($GLOBALS['lb_read_projection'][$db->thread_id]);$prefix=$db->thread_id.($compact?':report:':($readProjection?':read:':':'));
    $nis=$GLOBALS['lb_read_student'][$db->thread_id]??null;$key=$prefix.$scope.($nis===null?'':':student:'.$nis);
    $studentWhere=$nis===null?'':" AND s.NO_INDUK='".$db->real_escape_string($nis)."'";
    if($refresh){$GLOBALS['lb_cache_generation'][$db->thread_id]=($GLOBALS['lb_cache_generation'][$db->thread_id]??0)+1;unset($GLOBALS['lb_monthly_plan_cache'][$db->thread_id]);if(function_exists('du_psb_invalidate'))du_psb_invalidate($db);foreach(array_keys($cache) as $cachedKey)if(str_starts_with($cachedKey,$db->thread_id.':'))unset($cache[$cachedKey]);}
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
        foreach(['paid','original_psb','monthly_payments','owner_students'] as $name)$out[$name]=array_intersect_key($all[$name]??[],$owners);
        foreach(['native_psb','native_du','native_monthly'] as $name)$out[$name]=array_filter($all[$name]??[],static fn($k)=>str_starts_with((string)$k,$scope.'|'),ARRAY_FILTER_USE_KEY);
        return $cache[$key]=$out;
    }
    $out=['students'=>[],'tariffs'=>[],'paid'=>[],'original_psb'=>[],'native_psb'=>[],'native_du'=>[],'owner_units'=>[],'monthly_payments'=>[],'native_monthly'=>[],'owner_students'=>[]];
    if(!lop_ready($db))return $cache[$key]=$out;
    foreach(hp_rows($db,"SELECT o.*,b.source_id,b.year_id,b.cutoff,t.label migration_year,f.settings,s.NO_INDUK,s.PSB,s.potongan_psb_nominal,s.DAFTAR_ULANG,s.potong_du,s.tot_du FROM legacy_op_owners o JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_students f ON f.student_id=s.id AND f.unit_id=s.unit_id LEFT JOIN tahun_ajaran t ON t.id=b.year_id AND t.unit_id=b.unit_id WHERE (b.state='completed'".(!empty($GLOBALS['lb_pending_batch'])?" OR b.id='".$db->real_escape_string($GLOBALS['lb_pending_batch'])."'":'').")".$studentWhere) as $r){$r['settings']=json_decode($r['settings'],true);$r['raw']=null;$out['students'][$r['unit_id'].'|'.$r['NO_INDUK']]=$r;}
    if($nis!==null&&!$out['students'])return $cache[$key]=$out;
    $owners=implode(',',array_map('intval',array_column($out['students'],'id')))?:'0';
    $sources=implode(',',array_map('intval',array_column($out['students'],'source_id')))?:'0';
    $studentIds=implode(',',array_map('intval',array_column($out['students'],'student_id')))?:'0';
    $number=$nis===null?'':"'".$db->real_escape_string($nis)."'";
    $placements=[];foreach(hp_rows($db,'SELECT p.unit_id,p.no_induk,t.label,p.kelas FROM siswa_tahun_ajaran p JOIN tahun_ajaran t ON t.id=p.tahun_ajaran_id AND t.unit_id=p.unit_id'.($nis===null?'':" WHERE p.no_induk=$number")) as $p)$placements[$p['unit_id'].'|'.$p['no_induk']][$p['label']]=(int)$p['kelas'];
    // An early DU invoice supplies the financial grade until promotion creates
    // its academic destination. Other components still require their placement.
    $duGrades=[];foreach(hp_rows($db,'SELECT unit_id,no_induk,tahun_ajaran_snapshot,kelas_snapshot FROM tagihan_daftar_ulang'.($nis===null?'':" WHERE no_induk=$number")) as $b)$duGrades[$b['unit_id'].'|'.$b['no_induk']][$b['tahun_ajaran_snapshot']]=(int)$b['kelas_snapshot'];
    foreach(hp_rows($db,"SELECT unit_id,entity_type,entity_id,after_data,id FROM legacy_op_audit WHERE entity_type IN ('billing_override','billing_rule')".($nis===null?'':" AND (entity_type='billing_rule' OR entity_id IN ($studentIds))")." ORDER BY id") as $audit){
        $decision=json_decode($audit['after_data'],true);
        foreach($out['students'] as &$student){if((int)$student['unit_id']!==(int)$audit['unit_id'])continue;$identity=$student['unit_id'].'|'.$student['NO_INDUK'];$grade=$placements[$identity][$decision['year']]??(($decision['kind']??'')==='du_master'?($duGrades[$identity][$decision['year']]??-1):-1);$matches=$audit['entity_type']==='billing_override'?(int)$student['student_id']===(int)$audit['entity_id']:in_array($grade,$decision['levels'],true);
            if($matches)$student['settings']['billing_v1'][$decision['kind']][$decision['year']]=['rule'=>$decision['rule'],'version'=>$audit['id']];
        }unset($student);
    }
    $bySource=[];foreach($out['students'] as $k=>$r)$bySource[$r['source_id'].'|'.$r['source_identity']]=$k;
    $sourceMatches=[];foreach($out['students'] as $s)$sourceMatches[]="(source_id=".(int)$s['source_id']." AND TRIM(source_nis)='".$db->real_escape_string(trim((string)$s['source_identity']))."')";
    foreach(hp_rows($db,"SELECT source_id,source_nis,row_key,raw_data FROM legacy_finance_rows WHERE source_table='siswa'".($nis===null?'':' AND ('.implode(' OR ',$sourceMatches).')')) as $r){$k=$bySource[$r['source_id'].'|'.trim((string)$r['source_nis'])]??null;if(!$k)continue;$raw=json_decode($r['raw_data'],true);if($out['students'][$k]['raw']!==null&&$out['students'][$k]['raw']!==$raw){$out['students'][$k]['ambiguous']=true;continue;}$out['students'][$k]['raw']=$raw;$out['students'][$k]['source_row_key']=$r['row_key'];}
    foreach(hp_rows($db,"SELECT source_id,row_key,raw_data FROM legacy_finance_rows WHERE source_table IN ('Daftar_ulang','du')".($nis===null?'':" AND source_id IN ($sources)")) as $r){$raw=json_decode($r['raw_data'],true);$year=trim((string)($raw['th_ajaran']??''));$grade=trim((string)($raw['kelas']??''));$raw['_source_row_key']=$r['row_key'];$out['tariffs'][$r['source_id']][$year][$grade][]=$raw;}
    foreach(hp_rows($db,'SELECT unit_id,NO_INDUK,SUM(U_PSB) psb FROM bayar'.($nis===null?'':" WHERE NO_INDUK=$number").' GROUP BY unit_id,NO_INDUK') as $r)$out['native_psb'][$r['unit_id'].'|'.$r['NO_INDUK']]=lb_amount($r['psb'])??0;
    foreach(hp_rows($db,'SELECT t.unit_id,t.no_induk,t.tahun_ajaran_snapshot,SUM(b.jumlah) paid FROM tagihan_daftar_ulang t JOIN bayar_du b ON b.tagihan_daftar_ulang_id=t.id AND b.unit_id=t.unit_id'.($nis===null?'':" WHERE t.no_induk=$number").' GROUP BY t.unit_id,t.no_induk,t.tahun_ajaran_snapshot') as $r)$out['native_du'][$r['unit_id'].'|'.$r['no_induk']][$r['tahun_ajaran_snapshot']]=lb_amount($r['paid'])??0;
    $originals=[];foreach(hp_rows($db,"SELECT entity_id,before_data FROM legacy_op_audit WHERE entity_type='payment'".($nis===null?'':" AND entity_id IN (SELECT id FROM legacy_op_payments WHERE owner_id IN ($owners))").' ORDER BY id') as $a)if(!isset($originals[$a['entity_id']]))$originals[$a['entity_id']]=json_decode($a['before_data'],true);
    // Annual/history readers never consume monthly raw tariff evidence. Avoid
    // joining and extracting the payment archive when that evidence is unused.
    $profilePayments=microtime(true);
    $rawProjection=$compact?'NULL':"JSON_OBJECT('U_SPP',JSON_EXTRACT(r.raw_data,'$.U_SPP'),'U_SORGA',JSON_EXTRACT(r.raw_data,'$.U_SORGA'),'potong_spp',JSON_EXTRACT(r.raw_data,'$.potong_spp'))";
    $rawJoin=$compact?'':' LEFT JOIN legacy_finance_rows r ON r.source_id=b.source_id AND r.unit_id=b.unit_id AND r.row_key=p.row_key';
    $cursor=$db->query(hp_sql($db,"SELECT p.*,$rawProjection raw_data FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id$rawJoin".($nis===null?'':" WHERE p.owner_id IN ($owners)")),MYSQLI_USE_RESULT);
    while($p=$cursor->fetch_assoc()){
        $out['owner_units'][(int)$p['owner_id']]=(int)$p['unit_id'];
        $items=json_decode($p['items'],true);$initial=$originals[$p['id']]['items']??$items;
        $monthlyPeriod=lb_payment_month((string)$p['source_period']);
        if(!$compact&&$monthlyPeriod!==''&&$monthlyPeriod>='2020-01'){
            // The monthly planner needs only component and amount. Keep full
            // receipt/trace metadata in its original storage and receipt reader.
            $monthlyItems=[];foreach($items as $item)if(in_array($item['category']??'',['spp','komite'],true))$monthlyItems[]=['category'=>$item['category'],'amount'=>$item['amount']];
            if($monthlyItems)$out['monthly_payments'][$p['owner_id']][]=['row_key'=>$p['row_key'],'source_period'=>$p['source_period'],'class_snapshot'=>$p['class_snapshot'],'items'=>$monthlyItems,'cancelled'=>$p['cancelled'],'raw'=>json_decode($p['raw_data']??'{}',true)];
        }
        foreach($initial as $i)if(lb_psb_item($i))$out['original_psb'][$p['owner_id']]=($out['original_psb'][$p['owner_id']]??0)+lf_cents($i['amount']);
        if($p['cancelled'])continue;$owner=$p['owner_id'];
        foreach($items as $i)if(lb_psb_item($i))$out['paid'][$owner]['psb']=($out['paid'][$owner]['psb']??0)+lf_cents($i['amount']);
        $du=$p['source_table']==='bayar_du'?lb_result_cents($p['total']):0;if($p['source_table']!=='bayar_du')foreach($items as $i)if(($i['category']??'')==='daftar_ulang')$du+=lf_cents($i['amount']);
        $month=lb_payment_month((string)$p['source_period']);if($month!=='')foreach($items as $i)if(in_array($i['category']??'',['spp','komite'],true))$out['paid'][$owner][$i['category']][$month]=($out['paid'][$owner][$i['category']][$month]??0)+lf_cents($i['amount']);
        $year=lb_payment_year((string)$p['source_period']);
        if($p['source_table']!=='bayar_du'&&array_filter($items,static fn($i)=>($i['category']??'')==='daftar_ulang'&&lma_metadata_supported($i['migration']['version']??0))){
            foreach($items as $item)if(($item['category']??'')==='daftar_ulang'){$itemYear=lb_payment_year(lma_item_period($item,(string)$p['source_period']));$out['paid'][$owner]['du'][$itemYear]=($out['paid'][$owner]['du'][$itemYear]??0)+lf_cents($item['amount']);}
        }else $out['paid'][$owner]['du'][$year]=($out['paid'][$owner]['du'][$year]??0)+$du;
    }$cursor->free();
    if(getenv('SPP_REQUEST_METRICS')==='1')$GLOBALS['report_read_phases'][]=['phase'=>'billing_payments','scope'=>$scope,'compact'=>$compact,'ms'=>(microtime(true)-$profilePayments)*1000];
    foreach($out['students'] as $student)$out['owner_students'][$student['id']]=$student;
    // Report/master readers query native bill/payment positions themselves.
    // Keep the full native map for the reconciliation/verification engine;
    // monthly source evidence remains available to both read profiles.
    if(!$compact&&!$readProjection)foreach(['spp','komite'] as $category){
        $sql=$category==='spp'?"SELECT t.unit_id,t.no_induk,t.bulan,t.tahun,t.nominal_tagihan,t.status,COALESCE(SUM(CASE WHEN b.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid FROM tagihan_spp t LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=t.id AND a.unit_id=t.unit_id LEFT JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id":"SELECT t.unit_id,t.no_induk,t.bulan,t.tahun,t.nominal_tagihan,t.status,COALESCE(SUM(p.nominal),0) paid FROM tagihan_komite t LEFT JOIN bayar_komite p ON p.tagihan_komite_id=t.id AND p.unit_id=t.unit_id";
        foreach(hp_rows($db,$sql.($nis===null?'':" WHERE t.no_induk=$number").' GROUP BY t.id') as $bill)$out['native_monthly'][$bill['unit_id'].'|'.$bill['no_induk']][$category.'|'.$bill['tahun'].'-'.str_pad($bill['bulan'],2,'0',STR_PAD_LEFT)]=$bill;
    }
    if(getenv('SPP_REQUEST_METRICS')==='1')$GLOBALS['report_read_phases'][]=['phase'=>'billing_cache','scope'=>$scope,'compact'=>$compact,'ms'=>(microtime(true)-$profileStarted)*1000];
    return $cache[$key]=$out;
}
function lb_psb_item(array $item): bool {return strtoupper((string)($item['column']??''))==='U_PANGKAL'||($item['category']??'')==='psb';}
function lb_student(mysqli $db,string $nis,?int $unit=null): ?array {return lb_cache($db)['students'][($unit??(int)($GLOBALS['app_unit_id']??unit_active_id())).'|'.$nis]??null;}
function lb_amount($value): ?int {try{return $value===null||$value===''?null:lf_cents($value);}catch(Throwable $e){return null;}}
function lb_du_credit(mysqli $db,string $nis,string $year,?int $unit=null): float {$s=lb_student($db,$nis,$unit);return $s?(lb_cache($db)['paid'][$s['id']]['du'][$year]??0)/100:0.0;}
function lb_psb_source(mysqli $db,string $nis): ?array {
    $s=lb_student($db,$nis);if(!$s)return null;$raw=$s['raw']??[];$data=lb_cache($db);
    return lb_psb_evidence($raw,!empty($s['ambiguous']),$data['original_psb'][$s['id']]??0,$data['paid'][$s['id']]['psb']??0,$s['source_row_key']??'');
}

/** Header credit is cumulative; invalid headers never erase corrected receipt evidence. */
function lb_psb_evidence(array $raw,bool $ambiguous,int $original,int $current,string $sourceRow=''): array {
    $gross=lb_amount($raw['tot_pangkal']??null);$discount=lb_amount($raw['potong_pangkal']??null);$net=lb_amount($raw['PANGKAL']??null);$paid=lb_amount($raw['PANGKAL_BAYAR']??null);
    $unknown=['known'=>false,'total'=>null,'gross'=>null,'discount'=>null,'paid'=>max(0,$current)/100,'paid_known'=>true,'remaining'=>null,'billing_state'=>'reconciliation','basis'=>'legacy','reason'=>'Dasar atau pembayaran PSB sumber belum lengkap.'];
    if($ambiguous||$gross===null||$discount===null||$net===null||$paid===null||$gross<0||$discount<0||$net<0||$gross-$discount!==$net||$paid<$original)return $unknown+['credit_basis'=>'detail','source_row_key'=>$sourceRow];
    // The cumulative source header includes its detailed receipts. Only the correction delta is added.
    $credit=$paid+$current-$original;
    return ['known'=>true,'total'=>$net/100,'paid'=>max(0,$credit)/100,'remaining'=>max(0,$net-$credit)/100,'billing_state'=>'known','basis'=>'legacy','gross'=>$gross/100,'discount'=>$discount/100,'paid_known'=>true,'credit_basis'=>'cumulative_header_delta','source_row_key'=>$sourceRow];
}
function lb_du_source(mysqli $db,string $nis,string $year): ?array {
    $s=lb_student($db,$nis);if(!$s)return null;return ldu_source_proof($s,lb_cache($db)['tariffs'][$s['source_id']]??[],$year);
}
function lb_spp_override(mysqli $db,string $nis,string $year): bool {$s=lb_student($db,$nis);return !empty($s['settings']['billing_v1']['spp_master'][$year]);}
function lb_mark_spp_master(mysqli $db,string $year,array $levels): void {lb_mark_master($db,$year,$levels,'spp_master');}
function lb_set_override(mysqli $db,array $student,string $year,string $kind): void {
    $actor=(int)($_SESSION['admin_id']??0);$decision=['rule'=>LB_RULE_VERSION,'kind'=>$kind,'year'=>$year,'basis'=>'system','actor'=>$actor];
    lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'billing_override',?,?,?,'{}',?)",[$student['unit_id'],$actor,$student['student_id'],bin2hex(random_bytes(32)),'Perubahan tarif eksplisit; hanya tagihan tanpa pembayaran yang mengikuti',lf_json($decision)]);
}
/** A proven source obligation or existing explicit bill anchors the receipt's DU year. */
function lb_du_bill_credit(mysqli $db,string $nis,string $year): float {
    $proof=lb_du_source($db,$nis,$year);
    $bill=empty($proof['known'])?hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=?',[$nis,$year]):null;
    return !empty($proof['known'])||$bill?lb_du_credit($db,$nis,$year):0.0;
}
function lb_du_override(mysqli $db,string $nis,string $year): bool {return !empty(lb_student($db,$nis)['settings']['billing_v1']['du_master'][$year])||!empty(lb_student($db,$nis)['settings']['billing_v1']['du_individual'][$year]);}
function lb_mark_du_master(mysqli $db,string $year,array $levels): void {lb_mark_master($db,$year,$levels,'du_master');}
function lb_mark_master(mysqli $db,string $year,array $levels,string $kind): void {
    if(!lop_ready($db))return;$record=hp_one($db,'SELECT id FROM tahun_ajaran WHERE label=?',[$year]);if(!$record)throw new DomainException('Tahun tarif tidak ditemukan.');
    $decision=['rule'=>LB_RULE_VERSION,'kind'=>$kind,'year'=>$year,'levels'=>array_map('intval',$levels),'basis'=>'system','actor'=>(int)($_SESSION['admin_id']??0)];
    lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'billing_rule',?,?,?,'{}',?)",[unit_active_id(),$decision['actor'],$record['id'],bin2hex(random_bytes(32)),'Master disimpan eksplisit untuk tahun/tingkat; snapshot terbayar dipertahankan',lf_json($decision)]);lb_cache($db,true);
}
function lb_refresh_manifests(mysqli $db,array $batchIds): void {
    require_once __DIR__.'/legacy_operational_corrections.php';
    foreach(array_unique($batchIds) as $id){$b=hp_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=? FOR UPDATE',[$id]);$m=json_decode($b['manifest'],true);$m['working_digest']=lop_working_digest($db,(string)$id);lf_query($db,'UPDATE legacy_full_batches SET manifest=? WHERE id=?',[lf_json($m),$id]);}
}
/** Read the same source-backed position for synchronization and integrity checks. */
function lb_obligation_position(mysqli $db,array $ob): ?array {
    $e=json_decode($ob['evidence'],true);
    if(!is_array($e)||!in_array($e['rule']??null,[1,2,3,4,5,6,LB_RULE_VERSION],true))return null;
    $data=lb_cache($db);$student=$data['owner_students'][$ob['owner_id']]??null;
    if(!$student||($e['source_row_key']??'')!==($student['source_row_key']??null))throw new DomainException('Bukti kewajiban tidak sesuai arsip pemilik.');
    $s=$student;
    if($ob['category']==='psb'){
        $p=lb_psb_source($db,$s['NO_INDUK']);if(!$p||!$p['known'])return null;
        $position=lb_psb_position($db,$s['NO_INDUK'],$s,($data['native_psb'][$s['unit_id'].'|'.$s['NO_INDUK']]??0)/100);
        $principal=lb_result_cents($position['total']);$credit=lb_result_cents($position['paid']);
    }elseif($ob['category']==='daftar_ulang'){
        $p=lb_du_source($db,$s['NO_INDUK'],$ob['period']);
        $bill=hp_one($db,"SELECT nominal_tagihan,status FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=?",[$s['NO_INDUK'],$ob['period']]);
        if(!$bill&&empty($p['known']))return null;
        if($bill&&$bill['status']==='cancelled')return ['principal'=>0,'credit'=>0,'remaining'=>0];
        $principal=lb_result_cents($bill['nominal_tagihan']??$p['total']);$credit=lb_result_cents(lb_du_credit($db,$s['NO_INDUK'],$ob['period']))+($data['native_du'][$s['unit_id'].'|'.$s['NO_INDUK']][$ob['period']]??0);
    }elseif(in_array($ob['category'],['spp','komite'],true)){
        [$year,$month]=explode('-',$ob['period']);
        $bill=$data['native_monthly'][$s['unit_id'].'|'.$s['NO_INDUK']][$ob['category'].'|'.$ob['period']]??null;
        if(!$bill)return null;$principal=$bill['status']==='cancelled'?0:lf_cents($bill['nominal_tagihan']);$credit=lb_result_cents(lb_monthly_credit($db,$s['NO_INDUK'],$ob['category'],$month,$year))+lf_cents($bill['paid']);
    }else return null;
    return ['principal'=>$principal,'credit'=>$credit,'remaining'=>max(0,$principal-$credit)];
}
/** Recalculate proven opening obligations inside the caller's financial transaction. */
function lb_sync_owners(mysqli $db,array $ownerIds): array {
    lb_cache($db,true);$batches=[];$data=lb_cache($db);
    foreach($data['students'] as $s){if(!in_array((int)$s['id'],array_map('intval',$ownerIds),true))continue;
        foreach(hp_rows($db,'SELECT * FROM legacy_op_obligations WHERE owner_id=? ORDER BY id FOR UPDATE',[$s['id']]) as $ob){
            $position=lb_obligation_position($db,$ob);if(!$position)continue;
            $principal=$position['principal'];$remaining=$position['remaining'];
            if($remaining!==lf_cents($ob['remaining'])||$principal!==lf_cents($ob['principal'])){lf_query($db,'UPDATE legacy_op_obligations SET principal=?,remaining=?,version=version+1 WHERE id=?',[lf_money($principal),lf_money($remaining),$ob['id']]);$GLOBALS['lb_sync_changes'][$db->thread_id]=($GLOBALS['lb_sync_changes'][$db->thread_id]??0)+1;$batches[]=$s['batch_id'];}
        }
    }return array_unique($batches);
}
/** Caller owns the transaction. Dry run reports exactly the writes the apply will make. */
function lb_reconcile(mysqli $db,int $actor,bool $apply=false): array {
    $summary=['psb_ready'=>0,'du_ready'=>0,'du_created'=>0,'du_corrected'=>0,'du_masters_created'=>0,'unknown'=>0,'existing'=>0,'changes'=>0];if(!lop_ready($db))return $summary;
    $batches=[];$data=lb_cache($db,true);
    foreach($data['students'] as $s){
        $psb=lb_psb_source($db,$s['NO_INDUK']);$proofs=[];if($psb['known']){$summary['psb_ready']++;$proofs['psb|once']=$psb;}else $summary['unknown']++;
        $year=$s['migration_year'];$du=lb_du_source($db,$s['NO_INDUK'],$year);$active=hp_one($db,'SELECT is_active FROM siswa WHERE id=?',[$s['student_id']]);
        if($s['kind']==='active'&&!empty($active['is_active'])&&$year===du_current_academic_year()){
            $covered=function_exists('du_psb_state')&&!empty(du_psb_state($db,$s['NO_INDUK'],$year)['covered_psb']);
            if($covered){$summary['du_covered_psb']=($summary['du_covered_psb']??0)+1;}
            elseif($du['known']){$summary['du_ready']++;$proofs['daftar_ulang|'.$year]=$du;}else $summary['unknown']++;
        }
        foreach($data['paid'][$s['id']]['spp']??[] as $period=>$paidCents){
            if($period<($s['settings']['start_period']??''))continue;[$calendar,$month]=explode('-',$period);
            $bill=hp_one($db,"SELECT id,tarif_dasar_snapshot,potongan_nominal_snapshot,nominal_tagihan FROM tagihan_spp WHERE no_induk=? AND bulan=? AND tahun=? AND status='open'",[$s['NO_INDUK'],$month,$calendar]);
            if($bill)$proofs['spp|'.$period]=['known'=>true,'total'=>(float)$bill['nominal_tagihan'],'gross'=>(float)$bill['tarif_dasar_snapshot'],'discount'=>(float)$bill['potongan_nominal_snapshot'],'paid'=>$paidCents/100,'source_row_key'=>$s['source_row_key'],'basis'=>'legacy','bill_id'=>$bill['id']];
        }
        foreach($proofs as $key=>$proof){[$category,$period]=explode('|',$key);$ob=hp_one($db,'SELECT * FROM legacy_op_obligations WHERE owner_id=? AND category=? AND period=?',[$s['id'],$category,$period]);if($ob){$summary['existing']++;continue;}
            $credit=$category==='spp'?$proof['paid']:( $category==='psb'?$proof['paid']+($data['native_psb'][$s['unit_id'].'|'.$s['NO_INDUK']]??0)/100:lb_du_credit($db,$s['NO_INDUK'],$period)+($data['native_du'][$s['unit_id'].'|'.$s['NO_INDUK']][$period]??0)/100);
            $remaining=max(0,$proof['total']-$credit);$summary['changes']++;
            if(!$apply)continue;$evidence=lf_json(['rule'=>LB_RULE_VERSION,'basis'=>'legacy','source_row_key'=>$proof['source_row_key'],'gross'=>$proof['gross'],'discount'=>$proof['discount'],'opening_credit'=>$category==='psb'?$proof['paid']:null]);
            lf_query($db,'INSERT INTO legacy_op_obligations(unit_id,owner_id,category,period,principal,remaining,evidence) VALUES(?,?,?,?,?,?,?)',[$s['unit_id'],$s['id'],$category,$period,number_format($proof['total'],2,'.',''),number_format($remaining,2,'.',''),$evidence]);$id=(int)$db->insert_id;$batches[]=$s['batch_id'];
            lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'billing_reconcile',?,?,?,'{}',?)",[$s['unit_id'],$actor,$id,hash('sha256','lb1|'.$s['batch_id'].'|'.$s['id'].'|'.$key),'Rekonsiliasi kewajiban dari bukti sumber tanpa kas baru',lf_json($proof)]);
        }

    }
    if($apply){$batches=array_merge($batches,lb_sync_owners($db,array_column($data['students'],'id')));lb_refresh_manifests($db,$batches);}return $summary;
}

/** Shared report adaptor: credit changes outstanding balances, never cash totals. */
function lb_report_amounts(mysqli $db,string $nis,string $category,string $year,float $bill,float $paid): ?array {
    $s=lb_student($db,$nis);if(!$s)return null;
    if($category==='psb'){$position=lb_psb_position($db,$nis,$s,$paid);return $position;}
    elseif($category==='daftar_ulang'){$proof=lb_du_source($db,$nis,$year);$issued=hp_one($db,"SELECT nominal_tagihan,status FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=?",[$nis,$year]);if(!$issued&&empty($proof['known']))return ['known'=>false,'total'=>null,'paid'=>$paid,'remaining'=>null];if($issued&&$issued['status']!=='open')return ['known'=>false,'total'=>null,'paid'=>$paid,'remaining'=>null];$bill=$issued?(float)$issued['nominal_tagihan']:(float)$proof['total'];$paid+=lb_du_credit($db,$nis,$year);}
    else return null;
    return ['known'=>true,'total'=>$bill,'paid'=>$paid,'remaining'=>max(0,$bill-$paid)];
}

function lb_mark_du_individual(mysqli $db,string $nis,string $year): void {
    $s=lb_student($db,$nis);if(!$s)return;lb_set_override($db,$s,$year,'du_individual');lb_cache($db,true);
}

/** Only an explicit payment period supplies an academic year; dates/NIS do not. */
function lb_payment_year(string $period): string {
    static $years=[];
    if(isset($years[$period]))return $years[$period];
    if(count($years)>=4096)$years=[];
    return $years[$period]=lb_parse_payment_year($period);
}
function lb_parse_payment_year(string $period): string {
    $period=trim($period);
    if(preg_match('~^(\d{4})/(\d{4})$~D',$period,$m))return (int)$m[2]===(int)$m[1]+1?$period:'';
    $normalized=lb_payment_month($period);if($normalized!=='')$period=$normalized;
    if(preg_match('~^(\d{1,2}) (\d{4})$~D',$period,$m)){$month=(int)$m[1];$year=(int)$m[2];}
    elseif(preg_match('~^(\d{4})-(\d{2})$~D',$period,$m)){$month=(int)$m[2];$year=(int)$m[1];}
    else return '';
    if($month<1||$month>12||$year<2000||$year>2200)return '';$first=$year-($month<7?1:0);return $first.'/'.($first+1);
}
function lma_legacy_du_year_sql(string $alias='p'): string {
    if(!preg_match('/^[a-z]+$/D',$alias))throw new LogicException('Invalid alias');$v="TRIM($alias.source_period)";
    return "CASE WHEN $v REGEXP '^[12][0-9]{3}/[12][0-9]{3}$' AND CAST(RIGHT($v,4) AS UNSIGNED)=CAST(LEFT($v,4) AS UNSIGNED)+1 THEN $v WHEN $v REGEXP '^(0?[1-9]|1[0-2]) [12][0-9]{3}$' THEN CONCAT(CAST(RIGHT($v,4) AS UNSIGNED)-(CAST(SUBSTRING_INDEX($v,' ',1) AS UNSIGNED)<7),'/',CAST(RIGHT($v,4) AS UNSIGNED)+(CAST(SUBSTRING_INDEX($v,' ',1) AS UNSIGNED)>=7)) WHEN $v REGEXP '^[12][0-9]{3}-(0[1-9]|1[0-2])$' THEN CONCAT(CAST(LEFT($v,4) AS UNSIGNED)-(CAST(RIGHT($v,2) AS UNSIGNED)<7),'/',CAST(LEFT($v,4) AS UNSIGNED)+(CAST(RIGHT($v,2) AS UNSIGNED)>=7)) ELSE NULL END";
}

function lb_du_year_sql(string $alias='p'): string {
    $legacy=lma_legacy_du_year_sql($alias);
    $period="(SELECT MIN(mp.period) FROM JSON_TABLE(CAST($alias.items AS JSON),'$[*]' COLUMNS(category VARCHAR(100) PATH '$.category',version INT PATH '$.migration.version' NULL ON EMPTY,period VARCHAR(9) PATH '$.migration.period' NULL ON EMPTY)) mp WHERE mp.category='daftar_ulang' AND mp.version IN (1,2,3,4,5) AND mp.period REGEXP '^20[0-9]{2}/20[0-9]{2}$' AND CAST(RIGHT(mp.period,4) AS UNSIGNED)=CAST(LEFT(mp.period,4) AS UNSIGNED)+1)";
    return "CASE WHEN JSON_CONTAINS_PATH($alias.items,'one','$[0].migration') THEN COALESCE($period,$legacy) ELSE $legacy END";
}
function lb_payment_month(string $period): string {
    static $months=[];
    if(isset($months[$period]))return $months[$period];
    if(count($months)>=4096)$months=[];
    return $months[$period]=lb_parse_payment_month($period);
}
function lb_parse_payment_month(string $period): string {
    $names=['JANUARI'=>1,'FEBRUARI'=>2,'MARET'=>3,'APRIL'=>4,'MEI'=>5,'JUNI'=>6,'JULI'=>7,'AGUSTUS'=>8,'SEPTEMBER'=>9,'OKTOBER'=>10,'NOVEMBER'=>11,'NOPEMBER'=>11,'DESEMBER'=>12];
    if(preg_match('/^\s*([A-Za-z]+)\s+(\d{4})\s*$/D',$period,$m)&&isset($names[strtoupper($m[1])]))$period=sprintf('%04d-%02d',(int)$m[2],$names[strtoupper($m[1])]);
    $period=trim($period);if(preg_match('~^(\d{1,2}) (\d{4})$~D',$period,$m)){$month=(int)$m[1];$year=(int)$m[2];}elseif(preg_match('~^(\d{4})-(\d{2})$~D',$period,$m)){$year=(int)$m[1];$month=(int)$m[2];}else return '';
    return $month>=1&&$month<=12&&$year>=2000&&$year<=2200?sprintf('%04d-%02d',$year,$month):'';
}
function lb_spp_credit(mysqli $db,string $nis,string $month,string $year): float {
    return lb_monthly_credit($db,$nis,'spp',$month,$year);
}

function lb_monthly_credit(mysqli $db,string $nis,string $category,string $month,string $year): float {
    $student=lb_student($db,$nis);if(!$student)return 0.0;$key=sprintf('%04d-%02d',(int)$year,(int)$month);return (lb_cache($db)['paid'][$student['id']][$category][$key]??0)/100;
}

function lb_monthly_plan(mysqli $db,string $nis): array {
    $s=lb_student($db,$nis);if(!$s)return ['positions'=>[],'exceptions'=>[]];
    // Repeated months/components use the same immutable request-local evidence.
    // Share the pure plan only for that evidence generation and unit/profile.
    $key=$db->thread_id.':'.($GLOBALS['lb_cache_generation'][$db->thread_id]??0).':'.(int)($GLOBALS['app_unit_id']??unit_active_id()).':'.(int)!empty($GLOBALS['lb_report_compact'][$db->thread_id]).':'.$nis;
    return $GLOBALS['lb_monthly_plan_cache'][$db->thread_id][$key]??=lmp_plan($s,lb_cache($db)['monthly_payments'][$s['id']]??[]);
}

/** Used by forms and writes too; zero import defaults must not mask proven PSB. */
function lb_psb_effective(mysqli $db,array $student): array {
    if((float)($student['PSB']??0)!==0.0||(float)($student['potongan_psb_nominal']??0)!==0.0)return $student;
    $p=lb_psb_source($db,(string)$student['NO_INDUK']);
    if(!empty($p['known'])){
        if(lb_result_cents($p['gross'])===0&&lb_result_cents($p['discount'])===0)return $student;
        $s=lb_student($db,(string)$student['NO_INDUK']);
        foreach(hp_rows($db,'SELECT before_data,after_data FROM siswa_audit_log WHERE siswa_id=?',[$s['student_id']]) as $a){$before=json_decode($a['before_data']??'{}',true)??[];$after=json_decode($a['after_data']??'{}',true)??[];foreach(['PSB','potongan_psb_nominal'] as $f)if(isset($before[$f],$after[$f])&&lb_amount($before[$f])!==lb_amount($after[$f]))return $student;}
        $student['PSB']=$p['gross'];$student['potongan_psb_nominal']=$p['discount'];
    }
    return $student;
}

/** Canonical PSB position for master forms, cashier, obligations, and reports. */
function lb_psb_position(mysqli $db,string $nis,array $student,float $nativePaid=0): array {
    $proof=lb_psb_source($db,$nis);$student['NO_INDUK']=$nis;$effective=lb_psb_effective($db,$student);
    $gross=(float)($effective['PSB']??0);$discount=(float)($effective['potongan_psb_nominal']??0);$total=max(0,$gross-$discount);$paid=$nativePaid+($proof['paid']??0);$known=$proof===null||!empty($proof['known']);
    return ['known'=>$known,'total'=>$known?$total:null,'paid'=>$paid,'paid_known'=>true,'remaining'=>$known?max(0,$total-$paid):null,'gross'=>$known?$gross:null,'discount'=>$known?$discount:null,'basis'=>$proof['basis']??'system','billing_state'=>$known?'known':'reconciliation','reason'=>$proof['reason']??null,'credit_basis'=>$proof['credit_basis']??'native'];
}

function lb_native_du_cents(mysqli $db,string $nis,string $year): int {
    $unit=(int)($GLOBALS['app_unit_id']??unit_active_id());return lb_cache($db)['native_du'][$unit.'|'.$nis][$year]??0;
}
