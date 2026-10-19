<?php
/** A native deposit and its remote correction flow must carry the Legacy opening once. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require __DIR__.'/../includes/savings_authorization.php';require __DIR__.'/../includes/savings_book_print.php';require __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$actor=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1")['id'];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);$nis='232400001';
foreach(lf_rows($db,"SELECT id FROM transaksi_m WHERE NO_INDUK=? AND keterangan IN ('Simpanan baru sesudah migrasi','Koreksi simpanan baru') AND TANGGAL='2026-10-19 08:01:00'",[$nis]) as $owned)savings_correct($db,'direct_hapus','masuk',(int)$owned['id'],['reason'=>'Membersihkan transaksi fixture terisolasi yang terputus.'],$actor);
$db->begin_transaction();try{$ledger=savings_locked_ledger($db,$nis);lft_assert($ledger['balance']===85000&&$ledger['opening']===85000,'Legacy fixture not at opening');
    lf_query($db,"INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES(?,'2026-10-19 08:01:00',100,0,?,'Simpanan baru sesudah migrasi')",[$nis,(string)$actor]);$id=(int)$db->insert_id;
    lf_query($db,'UPDATE tabungan SET SALDO=950 WHERE NO_INDUK=?',[$nis]);$db->commit();
}catch(Throwable $e){$db->rollback();throw $e;}
$result=savings_correct($db,'direct_edit','masuk',$id,['nominal'=>'150','tanggal'=>'2026-10-19 08:01:00','keterangan'=>'Koreksi simpanan baru','reason'=>'Bukti koreksi simpanan baru pada fixture terisolasi.'],$actor);
$ledger=savings_locked_ledger($db,$nis);lft_assert($result===$id&&$ledger['balance']===100000&&$ledger['opening']===85000,'Remote correction lost/doubled Legacy opening');
[, $book]=savings_book_load($db,$nis);lft_assert($book['balance']===100000,'Combined savings book differs after native correction');
savings_correct($db,'direct_hapus','masuk',$id,['reason'=>'Pembatalan fixture simpanan baru setelah pengujian.'],$actor);
$ledger=savings_locked_ledger($db,$nis);lft_assert($ledger['balance']===85000&&$ledger['opening']===85000,'Native cancellation changed Legacy opening');
echo "PASS: native deposit, Super Admin correction, combined savings book and cancellation preserve Legacy opening once.\n";
