<?php
/** Correct/cancel a real inconsistent-header receipt through the official service, then rollback. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest();
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/legacy_operational_corrections.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;unit_set_context($db,1);$nis='141507012';$checks=[];
$check=static function(bool $ok,string $name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks[]=$name;};
$before=lmr_snapshot($db,1);$db->begin_transaction();
try{
    $student=hp_one($db,'SELECT * FROM siswa WHERE NO_INDUK=?',[$nis]);$p=hp_one($db,'SELECT * FROM legacy_op_payments WHERE id=8315');$items=json_decode($p['items'],true,512,JSON_THROW_ON_ERROR);$original=0;
    foreach($items as &$item)if(lb_psb_item($item)){$original+=lf_cents($item['amount']);$item['amount']=lf_money(lf_cents($item['amount'])-125);}unset($item);
    $check($original>125,'Real receipt PSB detail found');
    $position=lb_psb_position($db,$nis,$student,125);$check(!$position['known']&&abs($position['paid']-4850125)<.001,'Native credit composes with proven detail without principal');
    $input=['owner_id'=>(int)$p['owner_id'],'items'=>$items,'date'=>$p['source_date'],'period'=>$p['source_period'],'reason'=>'Fixture PSB credit correction on isolated restore only','preview_expires'=>time()+300,'confirmation'=>'SIMPAN KOREKSI'];
    $preview=lop_correction_preview($db,1,1,'payment',(int)$p['id'],(int)$p['version'],$input);$key=bin2hex(random_bytes(32));
    lop_correction_apply($db,1,1,'payment',(int)$p['id'],(int)$p['version'],$input,$preview['digest'],$key,false);
    $after=lb_psb_position($db,$nis,$student);$check(!$after['known']&&abs($after['paid']-4849998.75)<.001&&$after['total']===null,'Corrected detail remains visible under inconsistent header');
    $replay=lop_correction_apply($db,1,1,'payment',(int)$p['id'],(int)$p['version'],$input,$preview['digest'],$key,false);$check($replay['replayed'],'Repeated correction replays without second credit');
    $input['cancelled']=true;$input['preview_expires']=time()+300;$input['reason']='Fixture PSB cancellation on isolated restore only';
    $preview=lop_correction_preview($db,1,1,'payment',(int)$p['id'],(int)$p['version']+1,$input);
    lop_correction_apply($db,1,1,'payment',(int)$p['id'],(int)$p['version']+1,$input,$preview['digest'],bin2hex(random_bytes(32)),false);
    $after=lb_psb_position($db,$nis,$student);$check(!$after['known']&&abs($after['paid']-(4850000-$original/100))<.001,'Cancelled detail is removed from credit exactly once');
    $check((int)$db->query('SELECT COUNT(*) FROM bayar_data')->fetch_row()[0]===0,'No duplicate native cash was created');
}finally{$db->rollback();lb_cache($db,true);}
$check(lmr_snapshot($db,1)===$before,'Rollback preserves identities, archive, receipts, accounts and journals');
$result=['checks'=>$checks,'passed'=>true];file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/psb-correction-tests.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($result),PHP_EOL;
