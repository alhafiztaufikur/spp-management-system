<?php
define('ROMBEL_HELPERS_ONLY',true);require __DIR__.'/rombel_payment_http_test.php';
require_once __DIR__.'/../includes/transaction_authorization.php';
$fixture=json_decode(file_get_contents($root.'/rombel-fixture.json'),true);$super=$fixture['sessions']['super_admin'];$admin=$fixture['sessions']['admin'];
ini_set('session.save_path',getenv('SPP_QA_SESSION_PATH'));
session_id($super['id']);session_start();$_SESSION['csrf_transaction_authorization']=$super['csrf'];session_write_close();
foreach([1,2,3] as $unit){
    foreach([$super,$admin] as $ss)rp_http($ss['id'],'unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$ss['csrf'],'next'=>'/master_kelas.php']);
    $_SESSION=['admin_role'=>'super_admin','admin_id'=>$super['actor'],'active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
    $item=$fixture['units'][$unit];$pid=(int)$item['payment_id'];$mid=(int)$item['master_id'];
    $original=$koneksi->query("SELECT * FROM bayar WHERE id=$pid")->fetch_assoc();$line=$koneksi->query("SELECT * FROM bayar_biaya_lain WHERE bayar_id=$pid")->fetch_assoc();
    [, $html]=rp_http($admin['id'],'pembayaran/edit.php?id='.$pid);$form=rp_fields($html,'uang_psb');
    $base=array_replace($form,['aksi'=>'update','id'=>$pid,'no_induk'=>$item['nis'],'tanggal_bayar'=>substr($original['TGL_BYR'],0,10),'bulan_bayar'=>$original['BULAN'],'tahun_bayar'=>$original['TAHUN'],'sistem_pembayaran'=>'Tunai','uang_psb'=>0,'uang_spp'=>0,'uang_komite'=>0,'uang_du'=>0,'potongan_spp'=>0,'biaya_lain_detail_id'=>[$line['id']],'biaya_lain_tagihan_id'=>['master:'.$mid],'biaya_lain_nominal'=>[140000],'biaya_lain_keterangan'=>['Koreksi nominal sesuai tarif baru'],'authorization_reason'=>'Uji tarif berubah sebelum keputusan','_other_fee_tariffs'=>['master:'.$mid=>140000],'_operational_unit'=>$unit]);
    $koneksi->query("UPDATE master_biaya_lain SET nominal=140000 WHERE id=$mid");rp_http($admin['id'],'pembayaran/proses.php',$base);
    $request=$koneksi->query("SELECT * FROM transaksi_otorisasi WHERE bayar_id=$pid AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();rp_assert((bool)$request,'Valid recurring edit not queued');
    $koneksi->query("UPDATE master_biaya_lain SET nominal=150000 WHERE id=$mid");
    rp_http($super['id'],'pembayaran/proses.php',['aksi'=>'otorisasi_setujui','request_id'=>$request['id'],'csrf_token'=>$super['csrf'],'_operational_unit'=>$unit]);
    $state=$koneksi->query('SELECT status,decision_note FROM transaksi_otorisasi WHERE id='.(int)$request['id'])->fetch_assoc();
    rp_assert($state['status']==='failed'&&str_contains(strtolower($state['decision_note']),'tarif'),'Stale approval not rejected by tariff guard');
    rp_assert((float)$koneksi->query("SELECT total_jumlah FROM bayar WHERE id=$pid")->fetch_row()[0]===125000.0,'Failed approval changed receipt');
    $base['biaya_lain_nominal']=[150000];$base['_other_fee_tariffs']=['master:'.$mid=>150000];rp_http($admin['id'],'pembayaran/proses.php',$base);
    $request=$koneksi->query("SELECT * FROM transaksi_otorisasi WHERE bayar_id=$pid AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();rp_assert((bool)$request,'Revised exact edit not queued');
    rp_http($super['id'],'pembayaran/proses.php',['aksi'=>'otorisasi_setujui','request_id'=>$request['id'],'csrf_token'=>$super['csrf'],'_operational_unit'=>$unit]);
    $changed=$koneksi->query("SELECT * FROM bayar WHERE id=$pid")->fetch_assoc();$detail=$koneksi->query("SELECT * FROM bayar_biaya_lain WHERE bayar_id=$pid")->fetch_assoc();
    rp_assert((float)$changed['total_jumlah']===150000.0&&(float)$detail['tarif_referensi_snapshot']===150000.0,'Approved exact tariff not saved');
    rp_assert($changed['KELAS']===$original['KELAS']&&$changed['kelas_rombel_snapshot']===$original['kelas_rombel_snapshot']&&$changed['master_kelas_id']===$original['master_kelas_id'],'Correction rewrote historical rombel');
    // Current tariff may change later; unchanged legacy amount and snapshot remain valid.
    $koneksi->query("UPDATE master_biaya_lain SET nominal=160000 WHERE id=$mid");$seen=[];
    $unchanged=other_fee_optional_line($koneksi,'master:'.$mid,150000,'Note only',$detail,$seen);
    rp_assert((float)$unchanged['tarif_referensi']===150000.0,'Unchanged historical line invalidated');$koneksi->rollback();
    $koneksi->query("UPDATE master_biaya_lain SET nominal=125000 WHERE id=$mid");
}
$result='PASS: '.$checks." recurring edit/authorization assertions across SD/SMP/SMA.\n";file_put_contents($root.'/authorization-result.txt',$result);echo $result;
