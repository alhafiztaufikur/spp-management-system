<?php
/** All writes are rolled back on an explicitly owned isolated instance. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)getenv('SPP_DB_PORT')===3306)throw new RuntimeException('Use an isolated MySQL port.');
require_once __DIR__.'/../includes/migrated_history.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;$checks=0;
function fum_check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function fum_reject(callable $f,string $message): void {try{$f();}catch(InvalidArgumentException|DomainException $e){fum_check(true,$message);return;}throw new RuntimeException($message);}
function fum_page(array $input): array {global $db;return mh_payment_page($db,$input+['all_periods'=>'1'],['values'=>['*']],['*'],'2026-01-01','2027-01-01',1,10);}
unit_set_context($db,1);$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
if(getenv('SPP_FEE_TEST3_ACCEPTANCE')==='1'){
    foreach([1=>39,2=>51,3=>23] as $unit=>$count){unit_set_context($db,$unit);$rows=other_fee_master_usage($db);fum_check(count($rows)===$count,'Snapshot master count');fum_check(!array_filter($rows,fn($m)=>$m['can_delete']),'All source masters protected');}
    unit_set_context($db,1);$book=lf_one($db,"SELECT id FROM master_biaya_lain WHERE nama='Buku Ummi'");$usage=other_fee_master_usage($db,(int)$book['id'])[0];
    fum_check($usage['jumlah_pemakaian']===2873&&$usage['jumlah_rincian']===3547&&lf_cents($usage['nominal_pemakaian'])===6717750000,'Buku Ummi source acceptance');
    $page=fum_page(['fee_master_id'=>(string)$book['id']]);fum_check($page['total']===2873,'History and master counts match');
    $s=hp_one($db,'SELECT COUNT(*) n,SUM(fee_amount) amount FROM ('.$page['read_sql'].') x',$page['read_args']);fum_check((int)$s['n']===2873&&lf_cents($s['amount'])===6717750000,'Export and master amounts match');
}
$db->begin_transaction();
try{
    unit_set_context($db,1);$owner=lf_one($db,'SELECT o.*,s.NO_INDUK FROM legacy_op_owners o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LIMIT 1');if(!$owner)throw new RuntimeException('A migrated clone is required.');
    $unit=1;$masterIds=[];foreach(['slots','history','unused'] as $name){lf_query($db,"INSERT INTO master_biaya_lain(unit_id,nama,nominal,is_active,jenis_pembayaran,legacy_tariff_pending) VALUES(?,?,0,0,'berulang',1)",[$unit,'QA fee usage '.$name.' '.bin2hex(random_bytes(4))]);$masterIds[]=(int)$db->insert_id;}
    [$master,$historical,$unused]=$masterIds;$category='biaya_lain:'.$master;
    $items=[];foreach([100,200,300,400] as $n=>$amount)$items[]=['column'=>'JUMLAH'.($n+1),'category'=>$category,'label'=>'QA fee slots','amount'=>$amount.'.00','migration'=>['version'=>($n%3)+1,'source_code_raw'=>'0022','source_slot'=>$n+1]];
    lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_date,source_period,class_snapshot,items,total) VALUES(?,?,?,?,'bayar','2026-10-17','10 2026','1A',?,1000)",[1,$owner['batch_id'],$owner['id'],hash('sha256',random_bytes(32)),lf_json($items)]);$op=(int)$db->insert_id;
    $u=other_fee_master_usage($db,$master)[0];fum_check($u['jumlah_pemakaian']===1&&$u['jumlah_rincian']===4&&lf_cents($u['nominal_pemakaian'])===100000&&!$u['can_delete'],'Four repeated slots are one receipt');
    $p=fum_page(['fee_master_id'=>(string)$master]);fum_check($p['total']===1&&lf_cents($p['rows'][0]['fee_amount'])===100000,'Per-master history preserves slot sum');
    other_fee_assert_history_payment($db,['fee_master_id'=>(string)$master],-1000000000-$op);fum_check(true,'Source receipt is traceable');
    fum_reject(fn()=>other_fee_assert_history_payment($db,['fee_master_id'=>(string)$unused],-1000000000-$op),'Unrelated receipt is rejected');
    foreach(['0','-1','28 OR 1=1',['28']] as $bad)fum_reject(fn()=>other_fee_history_context($db,['fee_master_id'=>$bad]),'Invalid master context rejected');
    unit_set_context($db,2);fum_reject(fn()=>other_fee_history_context($db,['fee_master_id'=>(string)$master]),'Cross-unit context rejected');fum_reject(fn()=>other_fee_lock_items($db,2,$items),'Cross-unit write rejected');unit_set_context($db,1);
    // Old archive format has no v3 metadata. Its category is still authoritative.
    lf_query($db,"INSERT INTO legacy_full_payments(unit_id,batch_id,student_id,row_key,source_table,source_date,class_snapshot,total) VALUES(?,?,?,?,'bayar','2026-10-17','1A',500)",[1,$owner['batch_id'],$owner['student_id'],hash('sha256',random_bytes(32))]);$full=(int)$db->insert_id;
    lf_query($db,"INSERT INTO legacy_full_items(unit_id,payment_id,source_column,category,label,amount) VALUES(?,?,'JUMLAH1',?,'QA archive',500)",[1,$full,$category]);
    lf_query($db,"INSERT INTO bayar(unit_id,NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,U_LAIN,total_jumlah) VALUES(?,?,'2026-10-26','Oktober','2026','1',600,600)",[1,$owner['NO_INDUK']]);$native=(int)$db->insert_id;
    foreach([250,350] as $n=>$amount)lf_query($db,'INSERT INTO bayar_biaya_lain(unit_id,bayar_id,master_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot,urutan) VALUES(?,?,?,\'QA native\',?,?)',[1,$native,$master,$amount,$n+1]);
    $u=other_fee_master_usage($db,$master)[0];fum_check($u['jumlah_pemakaian']===3&&$u['jumlah_rincian']===7&&lf_cents($u['nominal_pemakaian'])===210000,'Native, archive and operational receipts combine without duplication');
    fum_check(fum_page(['fee_master_id'=>(string)$master])['total']===3,'Mixed history has three receipts');
    lf_query($db,'UPDATE legacy_op_payments SET cancelled=1 WHERE id=?',[$op]);$u=other_fee_master_usage($db,$master)[0];fum_check($u['jumlah_pemakaian']===2&&$u['jumlah_batal']===1&&!$u['can_delete'],'Cancelled receipt is protected and excluded from active money');
    fum_check(fum_page(['fee_master_id'=>(string)$master,'view'=>'deleted'])['total']===1,'Cancelled master context remains traceable');
    require_once __DIR__.'/../includes/payment_activity.php';
    $snap=['payment'=>lf_one($db,'SELECT * FROM bayar WHERE id=?',[$native]),'details'=>['bayar_biaya_lain'=>lf_rows($db,'SELECT * FROM bayar_biaya_lain WHERE bayar_id=?',[$native])]];
    payment_activity_record($db,$native,'deleted',1,$snap,null,bin2hex(random_bytes(16)),null,'Isolated archive fixture',null,true);
    lf_query($db,'DELETE FROM bayar_biaya_lain WHERE bayar_id=?',[$native]);lf_query($db,'DELETE FROM bayar WHERE id=?',[$native]);
    $u=other_fee_master_usage($db,$master)[0];fum_check($u['jumlah_pemakaian']===1&&$u['jumlah_batal']===2&&lf_cents($u['nominal_pemakaian'])===50000,'Native deletion archive does not inflate active money');
    fum_check(fum_page(['fee_master_id'=>(string)$master,'view'=>'deleted'])['total']===2,'Native and Legacy deleted context share history');
    $traced=lfet_receipt_items(['items'=>$items,'unit_id'=>1,'row_key'=>'qa']);fum_check(count($traced)===4&&$traced[0]['source_trace']['code_raw']==='0022'&&$traced[3]['source_trace']['slot']===4,'Old metadata preserves leading zeros and four slots');
    $corrected=$items;foreach($corrected as &$i)$i['category']='histori:'.str_repeat('a',32);unset($i);
    lf_query($db,"INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(1,1,'payment',?,?,'QA preservation',?,?)",[$op,hash('sha256',random_bytes(32)),lf_json(['items'=>[['category'=>'biaya_lain:'.$historical,'amount'=>'1000.00']]]),lf_json(['items'=>$corrected])]);
    fum_check(!other_fee_master_usage($db,$historical)[0]['can_delete'],'Correction before-image protects old master');
    fum_check(other_fee_master_usage($db,$unused)[0]['can_delete'],'Unused master stays deletable');
    other_fee_lock_items($db,1,$items);$second=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME,(int)getenv('SPP_DB_PORT'));$second->query('SET @app_unit_id=1');$second->query('SET SESSION innodb_lock_wait_timeout=1');$second->begin_transaction();
    try{other_fee_lock_items($second,1,$items);throw new RuntimeException('Concurrent master lock was not held.');}catch(mysqli_sql_exception $e){fum_check($e->getCode()===1205,'Writer and deletion share master lock');}finally{$second->rollback();$second->close();}
    $a=other_fee_master_usage($db,$master);$b=other_fee_master_usage($db,$master);fum_check($a===$b,'Repeated reads are stable');
}finally{$db->rollback();}
class FumFailingReader extends mysqli {
    public function prepare(string $query): mysqli_stmt|false {if(str_contains($query,'legacy_op_payments'))throw new RuntimeException('Injected source read failure');return parent::prepare($query);}
}
$failure=new FumFailingReader(DB_HOST,DB_USER,DB_PASS,DB_NAME,(int)getenv('SPP_DB_PORT'));$failure->query('SET @app_unit_id=1');
try{other_fee_master_usage($failure);throw new LogicException('A failed source was treated as unused.');}catch(RuntimeException $e){fum_check($e->getMessage()==='Injected source read failure','Source failure propagates instead of zero usage');}finally{$failure->close();}
echo "PASS $checks master usage checks; fixture rolled back.\n";
