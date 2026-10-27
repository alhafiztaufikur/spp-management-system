<?php
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/legacy_savings_resume.php';
require_once __DIR__.'/../includes/savings_owners.php';
$file=getenv('SPP_SAVINGS_RESUME_SETTINGS');if(!$file||!is_file($file))throw new RuntimeException('Private settings fixture required');
$request=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR)['savings_fix'];$db=$koneksi;$unit=(int)$request['unit_id'];$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);$checks=0;
function lsr_test(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
$db->begin_transaction();$db->query('SET @legacy_full_apply=1');try{
    $originalBatch=lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$request['batch_id']]);$originalSummary=json_decode($originalBatch['manifest'],true)['summary'];
    $before=lsr_guard($db,$request);
    foreach(['source_hash'=>str_repeat('0',64),'pending_version'=>999999,'unit_id'=>2,'batch_version'=>999999,'confirmed'=>false] as $key=>$value){$bad=$request;$bad[$key]=$value;$failed=false;try{lsr_reconcile($db,1,$bad);}catch(DomainException $e){$failed=true;}lsr_test($failed,'Reject stale/mismatched '.$key);}
    $r=lsr_reconcile($db,1,$request);lsr_test($r['changes']===1&&$r['balance']===$request['amount'],'Only explicit approved balance activates account');
    lsr_test($r['adjustment']==='20000.00'&&$r['source_journals']===92,'All original journals plus one adjustment');
    $account=lf_one($db,'SELECT * FROM legacy_op_accounts WHERE owner_id=?',[$r['owner_id']]);
    lsr_test($account['source_balance']===$request['amount']&&$account['opening']===$request['amount']&&$account['balance']===$request['amount'],'Source/opening/current balance agree');
    $owner=lf_one($db,'SELECT * FROM legacy_op_owners WHERE id=?',[$r['owner_id']]);$student=lf_one($db,'SELECT * FROM siswa WHERE id=?',[$owner['student_id']]);
    $resolved=so_resolve($db,['student_id'=>$owner['student_id']],true);lsr_test($resolved['state']==='ready'&&(float)$resolved['balance']===(float)$request['amount'],'Existing savings service can access the account');
    lsr_test(lsr_guard($db,$request)===$before,'Every unrelated database row is byte-identical');
    $tables=lmr_database_checksums($db);lsr_test(lsr_reconcile($db,1,$request)['changes']===0&&lmr_database_checksums($db)===$tables,'Replay has identical all-table checksums');
    $changed=$request;$changed['amount']='1350001.00';$failed=false;try{lsr_reconcile($db,1,$changed);}catch(DomainException $e){$failed=true;}lsr_test($failed,'Different FIX cannot overwrite an active account');
    $draft=lful_draft($request['batch_id']);lsr_test($draft['settings']['op_savings'][$request['identity']]['amount']===$request['amount'],'GUI uses the same audited FIX');
    $stored=lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$request['batch_id']]);$mapping=json_decode($stored['manifest'],true)['mapping'];
    $summary=json_decode($stored['manifest'],true)['summary'];lsr_test(lf_cents($summary['savings_total'])===lf_cents($originalSummary['savings_total'])+lf_cents($request['amount'])&&$summary['journals']===$originalSummary['journals']+93,'Publication summary includes the restored account and its journals');
    $adjustment=lf_one($db,"SELECT row_key FROM legacy_op_journals WHERE owner_id=? AND origin='adjustment'",[$r['owner_id']]);
    lsr_test($adjustment['row_key']===hash('sha256','adjustment:'.$request['identity'].':'.$mapping['source_fingerprint']),'Initial publication and FIX replay use the same adjustment identity');
    lop_check_result($db,$request['batch_id']);lsr_test(true,'Existing migration integrity agrees');
}finally{$db->rollback();$db->query('SET @legacy_full_apply=0');}
echo json_encode(['checks'=>$checks,'rolled_back'=>true],JSON_PRETTY_PRINT).PHP_EOL;
