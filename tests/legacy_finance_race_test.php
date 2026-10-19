<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);require_once __DIR__.'/../includes/legacy_finance.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$root=dirname((string)getenv('SPP_TEST_ISOLATED_INSTANCE'));$actor=(int)$db->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
if(($argv[1]??'')==='cashier-child'){
    $student=(int)$argv[2];$marker=$argv[3];if(dirname($marker)!==$root||!preg_match('/^race-[a-f0-9]+\.ready$/D',basename($marker)))throw new RuntimeException('Unsafe marker');
    unit_set_context($db,1);$db->begin_transaction();$s=lf_one($db,'SELECT id,NO_INDUK FROM siswa WHERE id=? FOR UPDATE',[$student]);
    lf_query($db,'INSERT INTO tabungan(NO_INDUK,SALDO) VALUES(?,20)',[$s['NO_INDUK']]);lf_query($db,'INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id) VALUES(?,NOW(),20,0,?)',[$s['NO_INDUK'],$actor]);
    file_put_contents($marker,'student locked');usleep(2000000);$db->commit();exit;
}
$f=lft_seed($db,$actor,1);$p=['effective_date'=>date('Y-m-d'),'evidence'=>'Bukti yayasan pengujian konkurensi','attested'=>true,'savings'=>true,'debts'=>[]];lf_save_review($db,$actor,1,$f['id'],1,$p);
$marker=$root.'/race-'.bin2hex(random_bytes(8)).'.ready';$process=proc_open([PHP_BINARY,__FILE__,'cashier-child',(string)$f['student'],$marker],[0=>['pipe','r'],1=>['file',$root.'/race.out.log','a'],2=>['file',$root.'/race.err.log','a']],$pipes);fclose($pipes[0]);
$until=microtime(true)+10;while(!is_file($marker)&&microtime(true)<$until)usleep(50000);lft_assert(is_file($marker),'Child did not acquire student lock');$start=microtime(true);
lft_reject(fn()=>lf_apply($db,$actor,1,$f['id'],2,'TERAPKAN LEGACY'),'Concurrent cashier transaction overwritten');lft_assert(microtime(true)-$start>1,'Apply did not wait for cashier lock');lft_assert(proc_close($process)===0,'Cashier child failed');
lft_assert(!lf_one($db,'SELECT id FROM legacy_savings_openings WHERE student_id=?',[$f['student']]),'Concurrent opening survived');lft_assert((float)lf_one($db,'SELECT SALDO FROM tabungan WHERE NO_INDUK=?',[$f['nis']])['SALDO']===20.0,'Cashier balance damaged');
// A new same-period bill after review invalidates debt application atomically.
$debtFixture=lft_seed($db,$actor,1);$p['debts']=[['component'=>'Race kewajiban','period'=>'2018-07','amount'=>'50','kind'=>'other','source_row_key'=>$debtFixture['key'],'distinction'=>'Bukti kewajiban terpisah sebelum tagihan baru masuk.']];lf_save_review($db,$actor,1,$debtFixture['id'],1,$p);
lf_query($db,"INSERT INTO master_biaya_lain(nama,nominal) VALUES(?,50)",['Race kewajiban '.bin2hex(random_bytes(5))]);$master=(int)$db->insert_id;lf_query($db,"INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan) VALUES(?,?,'Race kewajiban 2018-07',50)",[$master,$debtFixture['nis']]);
lft_reject(fn()=>lf_apply($db,$actor,1,$debtFixture['id'],2,'TERAPKAN LEGACY'),'New bill conflict accepted');lft_assert(!lf_one($db,'SELECT id FROM legacy_savings_openings WHERE student_id=?',[$debtFixture['student']]),'Opening survived conflicting debt');
echo "PASS: real concurrent cashier lock, current-read balance rejection and new-bill conflict with atomic rollback.\n";
