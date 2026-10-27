<?php
/** Real evidence + transactional fixtures on a separately owned MySQL instance only. */
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require __DIR__.'/../includes/reports.php';require __DIR__.'/../includes/biaya_lain.php';require __DIR__.'/../includes/other_fee_reconciliation.php';
$db=$koneksi;$checks=[];
function fb_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;echo "PASS $label\n";}
function fb_sql(string $sql,array $params=[]):int {global $db;ofb_write($db,$sql,$params);return (int)$db->insert_id;}
function fb_report(int $master,string $nis,array $extra=[]):array {global $db;$q=array_replace(['template'=>'per-item','kategori'=>'biaya_lain:'.$master,'q'=>$nis,'siswa_status'=>'all','tanggal_awal'=>'2000-01-01','tanggal_akhir'=>'2030-12-31','tahun_ajaran'=>'2026/2027'],$extra);return report_build($db,'per-item',report_filters($db,$q));}
fb_check(ofb_schema_apply($db)===0,'Installer repeat makes zero changes');
$r=fb_report(28,'212207015',['tanggal_awal'=>'2021-04-10','tanggal_akhir'=>'2022-09-16']);
fb_check(count($r['rows'])===1&&$r['rows'][0]['terbayar']===99000.0,'Buku Ummi five source receipts total 99000 in Dibayar');
fb_check($r['rows'][0]['tagihan']===null&&$r['rows'][0]['sisa']===null&&$r['rows'][0]['kelas']==='1C','Unknown Buku Ummi obligation and historical class preserved');
fb_check(!in_array('pembayaran_opsional',array_column($r['columns'],0),true)&&!str_contains(json_encode($r['columns']),'Dibayar Tagihan'),'One public paid column');
$db->begin_transaction();$db->query('SET @legacy_full_apply=1');
try{
 foreach([1,2,3] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);
    $student=hp_one($db,'SELECT s.*,mk.kode_rombel,mk.tingkat FROM siswa s JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.is_active=1 AND s.legacy_pending=0 AND mk.is_active=1 AND mk.is_placeholder=0 AND mk.tingkat NOT IN (6,9,12) ORDER BY s.id LIMIT 1');$nis=$student['NO_INDUK'];$class=class_label($student);
    $master=fb_sql("INSERT INTO master_biaya_lain(nama,nominal,is_active,jenis_pembayaran) VALUES(?,100000,1,'berulang')",['QA bound fee '.bin2hex(random_bytes(5))]);
    $oldBill=fb_sql("INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot) VALUES(?,?,'QA opening debt',50000,?)",[$master,$nis,$class]);
    $payment=fb_sql("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_LAIN,TGL_BYR,BULAN,TAHUN,total_jumlah,sistem_pembayaran) VALUES(?,?,?,?,100000,'2026-10-20 09:00:00','10','2026',100000,'Tunai')",[$nis,$student['KELAS'],$student['master_kelas_id'],$class]);
    $line=['master_id'=>$master,'nama'=>'QA bound fee','nominal'=>'100000.00','tarif_referensi'=>'100000.00'];$line=ofb_purchase_line($db,$payment,$line);$purchase=$line['bill_id'];
    fb_check($purchase!==$oldBill,'Unit '.$unit.' recurring purchase has separate bill');
    fb_check(ofb_purchase_line($db,$payment,$line)['bill_id']===$purchase,'Unit '.$unit.' purchase retry reuses bill');
    fb_sql("INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot,tarif_referensi_snapshot) VALUES(?,?,?,'QA bound fee',100000,100000)",[$payment,$master,$purchase]);
    fb_check(other_fee_bill_find($db,$purchase,$nis)['sisa']===0.0,'Unit '.$unit.' purchase bill is paid');
    // Paying after promotion must not move the bill or hide its linked credit.
    $later=hp_one($db,'SELECT * FROM master_kelas WHERE is_active=1 AND is_placeholder=0 AND tingkat>? ORDER BY tingkat,id LIMIT 1',[$student['KELAS']]);
    fb_sql('UPDATE bayar SET KELAS=?,master_kelas_id=?,kelas_rombel_snapshot=? WHERE id=?',[$later['tingkat'],$later['id'],class_label($later),$payment]);
    foreach(['tingkat:'.$student['KELAS'],'rombel:'.$student['master_kelas_id'],['tingkat:'.$student['KELAS']]] as $classFilter){
        $filtered=fb_report($master,$nis,['kelas'=>$classFilter]);$billRows=array_values(array_filter($filtered['rows'],static fn($r)=>(int)($r['bill_id']??0)===$purchase));
        fb_check(count($billRows)===1&&(float)$billRows[0]['terbayar']===100000.0&&(float)$billRows[0]['sisa']===0.0,'Unit '.$unit.' linked payment follows obligation class filter '.json_encode($classFilter));
    }
    fb_sql('UPDATE bayar SET KELAS=?,master_kelas_id=?,kelas_rombel_snapshot=? WHERE id=?',[$student['KELAS'],$student['master_kelas_id'],$class,$payment]);
    fb_check(other_fee_paid_for_bill($db,$oldBill)===0.0,'Unit '.$unit.' old debt untouched');
    fb_check(ofb_paid($db,$purchase,$payment,true)===0.0,'Unit '.$unit.' editing excludes own allocation');
    $beforeCount=(int)hp_one($db,'SELECT COUNT(*) n FROM bayar')['n'];$beforeCash=hp_one($db,'SELECT SUM(total_jumlah) amount FROM bayar')['amount'];
    $r=fb_report($master,$nis);fb_check(array_sum(array_column($r['rows'],'terbayar'))===100000.0,'Unit '.$unit.' report counts each receipt once');
    $second=fb_sql("INSERT INTO bayar(NO_INDUK,KELAS,U_LAIN,TGL_BYR,BULAN,TAHUN,total_jumlah,sistem_pembayaran) VALUES(?,?,100000,'2026-10-21','10','2026',100000,'Tunai')",[$nis,$student['KELAS']]);$secondLine=ofb_purchase_line($db,$second,['master_id'=>$master,'nama'=>'QA bound fee','nominal'=>'100000.00']);fb_check($secondLine['bill_id']!==$purchase,'Unit '.$unit.' repeat purchase is a different occurrence');
    fb_sql('DELETE FROM bayar_biaya_lain WHERE bayar_id=?',[$payment]);ofb_native_changed($db,['details'=>['bayar_biaya_lain'=>[['tagihan_biaya_lain_id'=>$purchase]]]],null);
    fb_check(hp_one($db,'SELECT status FROM tagihan_biaya_lain WHERE id=?',[$purchase])['status']==='cancelled','Unit '.$unit.' cancelling purchase cancels its bill');
    fb_check(hp_one($db,'SELECT status FROM tagihan_biaya_lain WHERE id=?',[$oldBill])['status']==='open','Unit '.$unit.' issued debt remains open');
    // Explicitly authorised historical obligation fixture; receipt amount is NOT its tariff.
    $details=ofb_legacy_details($db);$source=reset($details);$p=hp_one($db,'SELECT * FROM legacy_op_payments WHERE id=? FOR UPDATE',[$source['payment_id']]);
    $principal=ofb_cents($source['amount'])+50000;$ob=fb_sql('INSERT INTO legacy_op_obligations(unit_id,owner_id,category,period,principal,remaining,evidence) VALUES(?,?,?,?,?,500,?)',[$unit,$p['owner_id'],'biaya_lain:'.$source['master_id'],ofb_period($source['source_period'])??'2026',ofb_money($principal),'QA explicit historical principal fixture; isolated rollback only']);
    $items=json_decode($p['items'],true);foreach($items as &$i)if($i['column']===$source['source_column'])$i['obligation_id']=$ob;unset($i);fb_sql('UPDATE legacy_op_payments SET items=? WHERE id=?',[json_encode($items),$p['id']]);ofb_invalidate($db);
    $countNative=(int)hp_one($db,'SELECT COUNT(*) n FROM bayar')['n'];$result=ofb_reconcile($db,1);fb_check($result['bills_created']===1&&$result['credits_bound']===1,'Unit '.$unit.' proven obligation creates and binds one historical bill');
    $manifestProof=json_decode(hp_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$source['batch_id']])['manifest'],true);fb_check(hash_equals($manifestProof['working_digest'],lop_working_digest($db,$source['batch_id'])),'Unit '.$unit.' official working fingerprint updated after binding');
    $bound=hp_one($db,'SELECT * FROM other_fee_legacy_credits WHERE op_payment_id=?',[$p['id']]);$position=other_fee_bill_find($db,(int)$bound['bill_id'],$source['nis']);
    fb_check($position['sisa']===500.0&&$position['terbayar']===ofb_cents($source['amount'])/100.0,'Unit '.$unit.' native reader sees Legacy credit');
    fb_check((int)hp_one($db,'SELECT COUNT(*) n FROM bayar')['n']===$countNative,'Unit '.$unit.' binding creates no native cash');
    fb_check(ofb_reconcile($db,1)['changes']===0,'Unit '.$unit.' historical reconciliation repeats with zero changes');
    $r=fb_report((int)$source['master_id'],$source['nis']);$boundRows=array_values(array_filter($r['rows'],static fn($r)=>(int)$r['bill_id']===(int)$bound['bill_id']));
    fb_check(count($boundRows)===1&&$boundRows[0]['sisa']===500.0,'Unit '.$unit.' report preserves the proven bill balance');
    fb_check((int)hp_one($db,'SELECT bill_id FROM legacy_op_obligations WHERE id=?',[$ob])['bill_id']===(int)$bound['bill_id'],'Unit '.$unit.' obligation points to the gross historical bill');
    $cash=fb_sql("INSERT INTO bayar(NO_INDUK,KELAS,U_LAIN,TGL_BYR,BULAN,TAHUN,total_jumlah,sistem_pembayaran) VALUES(?,?,500,'2026-10-27','10','2026',500,'Tunai')",[$source['nis'],'1']);
    fb_sql("INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot) VALUES(?,?,?,'QA historical instalment',500)",[$cash,$source['master_id'],$bound['bill_id']]);
    fb_check(other_fee_bill_find($db,(int)$bound['bill_id'],$source['nis'])['sisa']===0.0,'Unit '.$unit.' native instalment settles the remaining proven debt');
    require_once __DIR__.'/../includes/legacy_operational_corrections.php';
    fb_check(lop_obligation_paid($db,$ob)===$principal,'Unit '.$unit.' obligation counts native plus Legacy once');
    fb_sql('DELETE FROM bayar_biaya_lain WHERE bayar_id=?',[$cash]);fb_sql('DELETE FROM bayar WHERE id=?',[$cash]);
    fb_check(other_fee_bill_find($db,(int)$bound['bill_id'],$source['nis'])['sisa']===500.0,'Unit '.$unit.' cancelling an instalment restores its remaining debt');
    fb_sql('UPDATE legacy_op_payments SET cancelled=1 WHERE id=?',[$p['id']]);ofb_invalidate($db);
    fb_check(ofb_legacy_paid($db,(int)$bound['bill_id'])===0,'Unit '.$unit.' cancellation removes Legacy credit');
    fb_sql('UPDATE legacy_op_payments SET cancelled=0 WHERE id=?',[$p['id']]);$items=json_decode($p['items'],true);foreach($items as &$i)if($i['column']===$source['source_column'])$i['amount']=ofb_money(ofb_cents($i['amount'])+100);unset($i);fb_sql('UPDATE legacy_op_payments SET items=? WHERE id=?',[json_encode($items),$p['id']]);ofb_invalidate($db);
    fb_check(ofb_legacy_paid($db,(int)$bound['bill_id'])===0,'Unit '.$unit.' changed source cannot retain stale credit');
    // Scope is connection-owned: another unit cannot select this bill.
    unit_set_context($db,$unit===3?1:$unit+1);fb_check(other_fee_bill_find($db,$purchase,$nis)===null,'Unit '.$unit.' bill inaccessible across units');unit_set_context($db,$unit);
 }
}finally{$db->rollback();$db->query('SET @legacy_full_apply=0');ofb_invalidate($db);}
$private=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));file_put_contents($private.'/billing-integration.json',json_encode(['passed'=>true,'checks'=>$checks],JSON_PRETTY_PRINT));echo 'PASS '.count($checks)." fee billing checks; fixtures rolled back.\n";
