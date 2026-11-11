<?php
/** Fixture changes are confined to one rolled-back transaction on a disposable restore. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)getenv('SPP_DB_PORT')===3306)throw new RuntimeException('Use an isolated server.');
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/registration_combined_ui.php';require_once __DIR__.'/../includes/legacy_transactions.php';
$db=$koneksi;$checks=0;
function hwi_check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function hwi_same(array $a,array $b,string $message): void {ksort($a);ksort($b);hwi_check($a===$b,$message);}
function hwi_reject(callable $fn,string $message): void {try{$fn();}catch(InvalidArgumentException|OutOfBoundsException $error){hwi_check(true,$message);return;}throw new RuntimeException($message);}
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($db,0);
$db->begin_transaction();
try{
    $groups=hp_rows($db,'SELECT * FROM '.rc_groups($db).' x ORDER BY x.unit_id,x.id');$samples=[];
    foreach($groups as $group){$key=$group['unit_id'].'|'.($group['id']>0?'native':($group['id']<=-2000000000?'covered':'legacy'));$samples[$key]??=$group;}
    foreach($samples as $g){
        hwi_same(rc_selected_group($db,['selected'=>(string)$g['id'],'selected_year'=>$g['th_ajaran']??''],(int)$g['unit_id']),$g,'Bounded owner/year matches complete grouping');
        $receipts=rc_receipts($db,$g);$legacy=[];
        foreach($receipts as $r)if($r['id']<-1000000000)$legacy[]=hp_one($db,'SELECT -(1000000000+p.id) id,p.unit_id,p.source_date,p.row_key,b.source_id FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE p.id=? AND p.unit_id=?',[-$r['id']-1000000000,$r['unit_id']]);
        $times=payment_time_records($db,$legacy);foreach($legacy as $r)hwi_same($times[$r['unit_id'].'|'.$r['source_id'].'|'.$r['row_key']],payment_time_record($db,$r),'Bounded clock equals complete evidence index');
    }
    $doc0=hp_json_document($db,['scope'=>'all']);unit_set_context($db,1);$doc1=hp_json_document($db,['scope'=>'unit']);hwi_check($doc0!==$doc1&&hp_one($db,"SELECT JSON_UNQUOTE(JSON_EXTRACT($doc0,'$.scope')) value")['value']==='all','A retained JSON projection survives another document/scope');
    foreach(['daftar_ulang','DAFTAR_ULANG','dáftar_ulang','spp','komite','',"daftar_ulang ","daftar\u{0000}_ulang"] as $category)foreach([JSON_UNESCAPED_UNICODE,0] as $encoding){
        $json=json_encode([['category'=>$category,'amount'=>'100.00']],$encoding|JSON_THROW_ON_ERROR);
        foreach([$json,str_replace('category','\\u0063ategory',str_replace('daftar','\\u0064aftar',$json))] as $value){
            $candidate=hp_json_category_candidate('p.items','daftar_ulang');$indexed=history_performance_du_expression('p').'>0';
            $sql="SELECT COUNT(*) n,SUM($candidate) general,SUM($indexed) indexed FROM (SELECT ? items) p JOIN JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(category VARCHAR(100) PATH '$.category')) j WHERE j.category='daftar_ulang'";
            $r=hp_one($db,$sql,[$value]);hwi_check((int)$r['n']===0||((int)$r['general']===(int)$r['n']&&(int)$r['indexed']===(int)$r['n']),'Candidate preserves JSON escaping/collation/old-format proof');
        }
    }
    $nis='97'.random_int(1000000,9999999);$fixtures=[];
    foreach([1=>2,2=>8] as $unit=>$level){
        $_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);$batch=hp_one($db,"SELECT id FROM legacy_full_batches WHERE state='completed' LIMIT 1");$class=hp_one($db,"SELECT id FROM master_kelas WHERE jenis='reguler' AND tingkat=? LIMIT 1",[$level]);
        if(!$class)$class=hp_one($db,"SELECT id FROM master_kelas WHERE jenis='regular' AND tingkat=? LIMIT 1",[$level]);
        if(!$class)$class=hp_one($db,'SELECT id FROM master_kelas WHERE tingkat=? LIMIT 1',[$level]);
        lf_query($db,'INSERT INTO siswa(unit_id,NO_INDUK,NAMA,KELAS,master_kelas_id,is_active,legacy_pending) VALUES(?,?,?,?,?,1,0)',[$unit,$nis,'QA history identity '.$unit,(string)$level,$class['id']]);$student=(int)$db->insert_id;
        lf_query($db,"INSERT INTO legacy_op_owners(unit_id,batch_id,source_identity,name,kind,student_id,source_class) VALUES(?,?,?,?,'active',?,?)",[$unit,$batch['id'],$nis,'QA history owner '.$unit,$student,$level.'A']);$owner=(int)$db->insert_id;
        $items=[['category'=>'daftar_ulang','column'=>'U_DU','label'=>'DU','amount'=>'100.00','migration'=>['version'=>3,'period'=>'2026/2027']],['category'=>'daftar_ulang','column'=>'U_DU2','label'=>'DU','amount'=>'200.00','migration'=>['version'=>3,'period'=>'2027/2028']],['category'=>'komite','column'=>'U_SORGA','label'=>'Komite','amount'=>'15.00']];
        lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_operator,source_date,source_period,class_snapshot,items,total) VALUES(?,?,?,?,'bayar','QA Operator',NULL,'10 2026',?,?,315)",[$unit,$batch['id'],$owner,hash('sha256',random_bytes(32)),$level.'A',lf_json($items)]);$id=(int)$db->insert_id;
        rc_evidence_sources($db,true);
        foreach(['2026/2027'=>100.0,'2027/2028'=>200.0] as $year=>$amount){
            $g=rc_selected_group($db,['selected'=>(string)(-1000000000-$id),'selected_year'=>$year],$unit);hwi_check((int)$g['student_id']===$student&&$g['th_ajaran']===$year&&(float)$g['legacy_paid']===$amount,'One mixed-year receipt resolves the chosen year and unit');
            $ev=rc_tariff_evidence($db,$g);hwi_check($ev['tariff']===null&&$ev['remaining']===null&&$ev['status']==='Status belum dapat ditentukan','Unproven obligation remains unknown');
            $receipts=rc_receipts($db,$g);hwi_check(count($receipts)===1&&(float)$receipts[0]['du_amount']===$amount&&(float)$receipts[0]['total_jumlah']===315.0&&$receipts[0]['time_precision']==='none','Mixed receipt does not double count cash or invent a date');
        }
        $fixtures[$unit]=['id'=>$id,'student'=>$student];
        foreach(['CaseOperator','caseoperator','CaseOperator ',null,''] as $operator)lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_operator,source_date,source_period,class_snapshot,items,total) VALUES(?,?,?,?,'bayar',?,'2026-10-01','10 2026',?,'[]',0)",[$unit,$batch['id'],$owner,hash('sha256',random_bytes(32)),$operator,$level.'A']);
        $options=lful_operator_options($db);foreach(['CaseOperator','caseoperator','CaseOperator ',null,''] as $operator)hwi_check(isset($options[lful_operator_key($unit,$operator)]),'Operator exact bytes survive JSON grouping');
    }
    $_SESSION['active_unit_id']=1;unit_set_context($db,1);hwi_reject(fn()=>rc_selected_group($db,['selected'=>(string)(-1000000000-$fixtures[2]['id']),'selected_year'=>'2026/2027'],2),'Same NIS in another unit does not grant access');
    foreach([['selected_year'=>['2026/2027']],['selected_year'=>'2026/2027 OR 1=1']] as $bad)hwi_reject(fn()=>rc_selected_year_sql($db,$bad),'Malformed selection year rejected');
    $p=hp_one($db,'SELECT p.*,b.source_id FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE p.id=?',[$fixtures[1]['id']]);$p['id']=-1000000000-$fixtures[1]['id'];
    lf_query($db,"UPDATE legacy_op_payments SET source_date='2026-11-01 00:00:00' WHERE id=?",[$fixtures[1]['id']]);
    lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(1,1,'payment',?,?,'QA clock evidence',?,?)",[$fixtures[1]['id'],hash('sha256',random_bytes(32)),lf_json(['source_date'=>null]),lf_json(['source_date'=>'2026-11-01 00:00:00','_time_precision'=>'datetime'])]);
    $p['source_date']='2026-11-01 00:00:00';payment_time_invalidate($db);$bounded=payment_time_records($db,[$p]);hwi_same($bounded[$p['unit_id'].'|'.$p['source_id'].'|'.$p['row_key']],payment_time_record($db,$p),'Applied date/precision correction agrees with full clock reader');
    foreach(['super_admin','admin'] as $role){$_SESSION['admin_role']=$role;foreach([true,false] as $out){$caps=lt_capabilities($db,['NO_INDUK'=>$nis,'unit_id'=>1],null,false,$out);hwi_check($caps['can_edit']===!$out&&$caps['can_delete']===!$out,'OUT capability policy preserved');}hwi_check(!lt_capabilities($db,['NO_INDUK'=>$nis,'unit_id'=>1],['status'=>'pending'],false,false)['can_edit'],'Pending authorization blocks edits');hwi_check(!lt_capabilities($db,['NO_INDUK'=>$nis,'unit_id'=>1],null,true,false)['can_print'],'Deleted receipt cannot print');}
    echo "PASS $checks grouping, clocks, candidates, identity and permission checks; fixtures rolled back.\n";
}finally{$db->rollback();}
