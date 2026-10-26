<?php
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/legacy_du.php';require_once __DIR__.'/../includes/registration_combined.php';require_once __DIR__.'/../includes/payment_obligations.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>2];$checks=0;$report=[];
function dui_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
unit_set_context($db,2);$protected=lmr_snapshot($db,2);$db->begin_transaction();
try{
    $result=ldu_reconcile($db,1);$position=ldu_verify($db);$raisa=du_find_bill($db,'252607034',10,2026);
    dui_check($raisa&&(float)$raisa['nominal_tagihan']===4310000.0&&$raisa['terbayar']===4310000.0&&(float)$raisa['sisa']===0.0,'Raisa bill linked to full source payment');
    dui_check($raisa['tahun_status']==='published','Draft DU year published automatically');
    dui_check(ldu_reconcile($db,1)['changes']===0,'Repeated reconciliation is a no-op');
    $page=rc_page($db,['q'=>'252607034','year'=>'2026/2027']);$group=$page['rows'][0];$e=rc_tariff_evidence($db,$group);$receipts=rc_receipts($db,$group);
    foreach(['system','legacy'] as $source){$filtered=rc_page($db,['q'=>'252607034','year'=>'2026/2027','source'=>$source]);$ev=rc_tariff_evidence($db,$filtered['rows'][0]);dui_check($ev['status']==='Lunas'&&(float)$ev['remaining']===0.0,'Source filter preserves full settlement credit: '.$source);}
    dui_check($e['status']==='Lunas'&&$e['tariff']===4310000.0&&(float)$e['remaining']===0.0,'History and student bill agree');
    dui_check(array_sum(array_column($receipts,'du_amount'))==4310000.0,'History drills down to source DU amount');
    $summary=payment_obligations($db,'252607034');dui_check(!array_filter($summary['rows'],fn($r)=>$r['kind']==='du'),'No false DU popup after fully paid legacy bill is created');
    $ob=hp_one($db,"SELECT * FROM legacy_op_obligations WHERE category='daftar_ulang' AND owner_id=? AND period='2026/2027'",[lb_student($db,'252607034')['id']]);$evidence=json_decode($ob['evidence'],true);
    dui_check($evidence['du']['bill_id']===(int)$raisa['id']&&$evidence['du']['trace_count']>0,'Obligation trace links bill and receipts');
    $audit=hp_one($db,"SELECT after_data FROM legacy_op_audit WHERE entity_type='du_trace' AND entity_id=? ORDER BY id DESC LIMIT 1",[$ob['id']]);$trace=json_decode($audit['after_data'],true);
    dui_check(count($trace['traces'])===$evidence['du']['trace_count']&&$trace['tariff_row_keys']&&$trace['recap_row_keys'],'Tariff, matched recap and transaction source retained');
    foreach($position['positions'] as $p)if($p['status']!=='unknown'){$b=du_find_bill_by_id($db,$p['bill_id']);dui_check(lb_result_cents($b['sisa'])===$p['remaining_cents'],'Every student position equals cashier');}
    $billId=(int)$raisa['id'];lf_query($db,"UPDATE tagihan_daftar_ulang SET nominal_awal=5000000,nominal_tagihan=5000000 WHERE id=?",[$billId]);
    ldu_reconcile($db,1);dui_check(du_find_bill_by_id($db,$billId)['nominal_tagihan']===5000000.0,'Existing individual bill is preserved');
    $r=du_find_bill_by_id($db,$billId);dui_check($r['terbayar']===4310000.0&&$r['sisa']===690000.0,'Partial position combines legacy with corrected principal');
    $outstanding=array_values(array_filter(payment_obligations($db,'252607034')['rows'],fn($r)=>$r['kind']==='du'));dui_check(count($outstanding)===1&&$outstanding[0]['remaining']===690000.0,'Only real DU remainder is shown');
    $db->query('SAVEPOINT du_native');$db->query('SET @legacy_full_apply=1');
    lf_query($db,"INSERT INTO bayar(unit_id,NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,total_jumlah) VALUES(2,'252607034','2026-10-26','Oktober','2026','1',690000)");$nativeId=(int)$db->insert_id;
    lf_query($db,"INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,'252607034','8','2026/2027',690000)",[$nativeId,$billId]);lb_cache($db,true);
    $r=du_find_bill_by_id($db,$billId);dui_check((float)$r['terbayar']===5000000.0&&(float)$r['sisa']===0.0,'Native remainder settles bill alongside source cash: '.json_encode($r));
    $rejected=false;try{du_require_selectable_bill($db,$billId,'252607034');}catch(DaftarUlangSelectionException $e){$rejected=$e->reason==='settled';}dui_check($rejected,'Duplicate payment rejected after settlement');
    $db->query('ROLLBACK TO SAVEPOINT du_native');$db->query('SET @legacy_full_apply=0');lb_cache($db,true);dui_check(du_find_bill_by_id($db,$billId)['sisa']===690000.0,'Removing native payment restores remainder');
    $payment=hp_one($db,"SELECT p.id,p.version FROM legacy_op_payments p WHERE p.owner_id=? AND JSON_SEARCH(p.items,'one','daftar_ulang',NULL,'$[*].category') IS NOT NULL AND p.cancelled=0 LIMIT 1",[lb_student($db,'252607034')['id']]);
    $db->query('SAVEPOINT du_cancel');require_once __DIR__.'/../includes/legacy_operational_corrections.php';
    $input=['confirmation'=>'SIMPAN KOREKSI','cancelled'=>true,'reason'=>'Fixture DU cancellation preserves source and changes eligible credit','preview_expires'=>time()+240];
    $preview=lop_correction_preview($db,1,2,'payment',(int)$payment['id'],(int)$payment['version'],$input);
    lop_correction_apply($db,1,2,'payment',(int)$payment['id'],(int)$payment['version'],$input,$preview['digest'],hash('sha256',random_bytes(24)),false);lb_cache($db,true);
    dui_check(du_find_bill_by_id($db,$billId)['sisa']>690000.0,'Authorized legacy cancellation removes its DU credit');ldu_verify($db);dui_check(true,'Cancellation keeps stored source trace verifiable');
    $db->query('ROLLBACK TO SAVEPOINT du_cancel');lb_cache($db,true);
    $db->query('SAVEPOINT du_manual');$unproven=array_values(array_filter(ldu_plan($db)['positions'],fn($p)=>empty($p['proof']['known'])&&!$p['bill']&&$p['placement']))[0];$pl=$unproven['placement'];$db->query('SET @legacy_full_apply=1');
    lf_query($db,'INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES(?,?,?,1000000)',[$pl['tahun_ajaran_id'],'2026/2027',$pl['kelas']]);$manualMaster=(int)$db->insert_id;
    lf_query($db,'INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,1000000,1000000)',[$pl['tahun_ajaran_id'],$pl['id'],$manualMaster,$unproven['nis'],$pl['kelas'],'2026/2027']);$manualBill=(int)$db->insert_id;$db->query('SET @legacy_full_apply=0');lb_cache($db,true);
    ldu_reconcile($db,1);dui_check(du_find_bill_by_id($db,$manualBill)['nominal_tagihan']===1000000.0,'Existing manual bill survives incomplete source tariff');ldu_verify($db);
    $db->query('ROLLBACK TO SAVEPOINT du_manual');lb_cache($db,true);
    $db->query('SAVEPOINT du_closed');lf_query($db,"UPDATE tahun_ajaran SET status='closed' WHERE id=?",[$raisa['tahun_ajaran_id']]);$closed=ldu_reconcile($db,1);dui_check($closed['bills_created']===0,'Closed year is preserved');$db->query('ROLLBACK TO SAVEPOINT du_closed');lb_cache($db,true);
    $db->query('SAVEPOINT du_alumni');lf_query($db,"UPDATE siswa SET is_active=0 WHERE NO_INDUK='252607034'");lb_cache($db,true);dui_check(!isset(ldu_plan($db)['positions']['2|252607034']),'Alumni excluded from new DU planning');$db->query('ROLLBACK TO SAVEPOINT du_alumni');lb_cache($db,true);
    $db->query('SAVEPOINT du_bill_cancel');lf_query($db,"UPDATE tagihan_daftar_ulang SET status='cancelled',cancel_reason='Fixture explicit cancellation' WHERE id=?",[$billId]);ldu_reconcile($db,1);dui_check(hp_one($db,'SELECT status FROM tagihan_daftar_ulang WHERE id=?',[$billId])['status']==='cancelled','Cancelled individual bill is never republished');$db->query('ROLLBACK TO SAVEPOINT du_bill_cancel');lb_cache($db,true);
    dui_check(lmr_snapshot($db,2)===$protected,'Archive, identity, cash and savings remain unchanged');
}finally{$db->rollback();lb_cache($db,true);}
foreach([1,2,3] as $unit){unit_set_context($db,$unit);$_SESSION['active_unit_id']=$unit;$db->begin_transaction();try{$r=ldu_reconcile($db,1);$v=ldu_verify($db);dui_check(ldu_reconcile($db,1)['changes']===0,'Replay zero unit '.$unit);$report[$unit]=array_diff_key($v,['positions'=>true]);}finally{$db->rollback();lb_cache($db,true);}}
echo json_encode(['checks'=>$checks,'units'=>$report],JSON_PRETTY_PRINT).PHP_EOL;
