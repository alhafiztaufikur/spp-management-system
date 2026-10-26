<?php
/** Historical fee-to-DU reconstruction on an explicitly UUID-guarded fixture server. */
if(PHP_SAPI!=='cli'||(int)getenv('SPP_DB_PORT')===3306||!getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Owned isolated server required');
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/legacy_du.php';
require_once __DIR__.'/../includes/legacy_du_reconstruction.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;if($db->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Wrong server');
unit_set_context($db,2);$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>2];$checks=0;
function dur_check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$db->begin_transaction();try{
    $fixture=lft_seed($db,1,2);$batch=bin2hex(random_bytes(16));$year=spp_master_ensure_year($db,'2026/2027');
    lf_query($db,"INSERT INTO legacy_full_batches(unit_id,id,source_id,version,digest,cutoff,year_id,state,manifest,before_backup,actor_id) VALUES(2,?,?,1,?,'2026-10-17',?,'completed','{}',?,1)",[$batch,$fixture['source'],str_repeat('a',64),$year['tahun_ajaran_id'],str_repeat('a',32)]);
    lf_query($db,"INSERT INTO legacy_op_groups(unit_id,code,label,kind,aliases) VALUES(2,?,?,'academic','[]')",['DU-'.substr($batch,0,8),'Fixture DU']);$group=(int)$db->insert_id;
    lf_query($db,"INSERT INTO legacy_op_owners(unit_id,batch_id,source_identity,name,kind,group_id,student_id,source_class,state) VALUES(2,?,?,'Fixture DU','active',?,?,'7C','ready')",[$batch,$fixture['nis'],$group,$fixture['student']]);$owner=(int)$db->insert_id;
    lf_query($db,"INSERT INTO master_biaya_lain(unit_id,nama,nominal,is_active,legacy_tariff_pending) VALUES(2,'Daftar Ulang QA historis',0,0,1)");$master=(int)$db->insert_id;
    $records=[];$ids=[];
    foreach(['plain'=>0,'cancelled'=>1,'corrected'=>0] as $mode=>$cancelled){
        $raw=['id'=>$mode,'NO_INDUK'=>$fixture['nis'],'KELAS'=>'PSB','th_ajaran'=>'2026/2027','kelas_du'=>'7','LAIN_LAIN1'=>'0001','JUMLAH1'=>'500000','LAIN_LAIN2'=>'0001','JUMLAH2'=>'700000','U_LAIN'=>'0'];
        $row=hash('sha256',$batch.$mode);$records[]=['table'=>'bayar','row_key'=>$row,'raw'=>$raw];
        $items=[];foreach([1=>'500000',2=>'700000'] as $slot=>$amount)$items[]=['column'=>'JUMLAH'.$slot,'category'=>'biaya_lain:'.$master,'label'=>'Daftar Ulang','amount'=>$amount];
        lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_date,source_period,class_snapshot,items,total,cancelled) VALUES(2,?,?,?,'bayar','2025-10-01','10 2025','PSB',?,1200000,?)",[$batch,$owner,$row,lf_json($items),$cancelled]);$ids[$mode]=(int)$db->insert_id;
    }
    $records[]=['table'=>'lain','row_key'=>hash('sha256',$batch.'master'),'raw'=>['KODE'=>'0001','NAMA'=>'Daftar Ulang']];
    foreach($records as $r)lf_query($db,'INSERT INTO legacy_finance_rows(unit_id,source_id,source_table,row_key,row_hash,occurrence,source_nis,amounts,raw_data,reference_only) VALUES(2,?,?,?,?,1,?,?,?,?)',[$fixture['source'],$r['table'],$r['row_key'],hash('sha256',lf_canonical($r['raw'])),$r['table']==='bayar'?$fixture['nis']:'','{}',lf_json($r['raw']),$r['table']==='lain'?1:0]);
    ldu_audit($db,1,'payment',$ids['corrected'],[],['fixture'=>'User correction must survive'],['unit_id'=>2]);
    $before=hp_rows($db,'SELECT id,owner_id,source_date,source_period,total,cancelled FROM legacy_op_payments WHERE batch_id=? ORDER BY id',[$batch]);
    $result=ldu_reconstruct_payments($db,1);dur_check($result['receipts']===2&&$result['items']===4&&$result['amount_cents']===240000000,'Both slots reconstructed, including preserved cancellation');
    foreach(['plain','cancelled'] as $mode){$p=hp_one($db,'SELECT * FROM legacy_op_payments WHERE id=?',[$ids[$mode]]);$items=json_decode($p['items'],true);dur_check($items[0]['category']==='daftar_ulang'&&$items[1]['category']==='daftar_ulang','Direct source master determines DU');dur_check($items[0]['migration']['period']==='2026/2027','DU year differs from cash year');dur_check($items[0]['migration']['source_code']==='0001'&&$items[0]['migration']['detail_key']!==$items[1]['migration']['detail_key'],'Code and distinct slot lineage retained');}
    dur_check(str_starts_with(json_decode(hp_one($db,'SELECT items FROM legacy_op_payments WHERE id=?',[$ids['corrected']])['items'],true)[0]['category'],'biaya_lain:'),'User correction is preserved');
    dur_check(hp_rows($db,'SELECT id,owner_id,source_date,source_period,total,cancelled FROM legacy_op_payments WHERE batch_id=? ORDER BY id',[$batch])===$before,'Receipt identity, cash date, amounts and cancellation unchanged');
    dur_check(ldu_reconstruct_payments($db,1)['changes']===0,'Reclassification replay changes nothing');
    $usage=other_fee_master_usage($db,$master)[0];dur_check(!$usage['can_delete']&&$usage['history_used'],'Former fee master remains protected by reclassification audit');
    $lines=ldu_payment_items($db,$owner);$traces=$lines['2|'.$owner.'|2026/2027'];dur_check(count($traces)===4&&count(array_filter($traces,fn($i)=>!$i['cancelled']))===2,'Active credit excludes cancelled receipt but trace preserves it');
}finally{$db->rollback();lb_cache($db,true);}
echo "PASS $checks isolated DU reclassification checks; fixture rolled back.\n";
