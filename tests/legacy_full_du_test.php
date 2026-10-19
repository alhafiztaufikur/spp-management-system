<?php
if(PHP_SAPI!=='cli')exit(1);require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);require_once __DIR__.'/../includes/legacy_full_read.php';require_once __DIR__.'/legacy_finance_support.php';require_once __DIR__.'/../includes/daftar_ulang.php';
$db=$koneksi;$_SESSION=['admin_id'=>41,'admin_role'=>'super_admin','active_unit_id'=>2];unit_set_context($db,2);$b=lf_one($db,"SELECT * FROM legacy_full_batches WHERE state='completed'");$yearId=(int)$b['year_id'];$year=lf_one($db,'SELECT * FROM tahun_ajaran WHERE id=?',[$yearId]);$legacy=lf_one($db,"SELECT * FROM siswa WHERE NO_INDUK='9810000001'");
$db->begin_transaction();try{
    lf_query($db,"UPDATE siswa SET is_active=0 WHERE NO_INDUK<>'9810000001'");lf_query($db,"UPDATE tahun_ajaran SET status='draft',published_at=NULL WHERE id=?",[$yearId]);
    foreach([7,8,9] as $level)lf_query($db,'INSERT INTO Daftar_ulang(tahun_ajaran_id,kelas,Jumlah) VALUES(?,?,200000) ON DUPLICATE KEY UPDATE Jumlah=200000',[$yearId,$level]);
    lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES('9810000002','SISWA BARU QA DU',?,?,1)",[$legacy['KELAS'],$legacy['master_kelas_id']]);
    lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,'9810000002',?,?,?,250000,10000,'aktif')",[$yearId,$legacy['KELAS'],$legacy['master_kelas_id'],'7A']);
    $created=du_publish_year_from_active_students($db,$yearId,$year['label']);lft_assert($created===1,'DU publication did not skip migrated student');
    lft_assert(!lf_one($db,"SELECT id FROM tagihan_daftar_ulang WHERE no_induk='9810000001'"),'Covered Legacy DU reissued');lft_assert((bool)lf_one($db,"SELECT id FROM tagihan_daftar_ulang WHERE no_induk='9810000002'"),'Ordinary student DU missing');
    $placement=lf_one($db,"SELECT id FROM siswa_tahun_ajaran WHERE no_induk='9810000001'");lft_assert(du_create_bill_for_placement($db,(int)$placement['id'])===null,'Single placement reissued covered DU');
    echo "PASS: mixed ordinary/migrated bulk DU publication, covered period skipped, single-placement protection.\n";
}finally{$db->rollback();}
