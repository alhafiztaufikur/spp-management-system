<?php
/** Grouped reads must not change fractional money or typed export values. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/report_history_page.php';
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
$koneksi->begin_transaction();
try{
    $bill=$koneksi->query("SELECT t.id,t.nominal_tagihan FROM tagihan_spp t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id WHERE t.status='open' AND t.nominal_tagihan>0 AND s.legacy_pending=0 AND s.is_active=1 ORDER BY t.id LIMIT 1")->fetch_assoc();
    if(!$bill)throw new RuntimeException('A restored monthly bill is required');
    $statement=$koneksi->prepare('UPDATE tagihan_spp SET tarif_dasar_snapshot=tarif_dasar_snapshot+0.13,nominal_tagihan=nominal_tagihan+0.13 WHERE id=?');
    $statement->bind_param('i',$bill['id']);$statement->execute();$statement->close();
    $_GET=[];$filters=report_filters($koneksi,[]);
    $reference=report_build($koneksi,'riwayat-tagihan',$filters);
    $optimized=report_history_page_data($koneksi,$filters,true,true);
    if($optimized!==null)throw new RuntimeException('Fractional exports must retain the full reader arithmetic');
    $actual=report_build($koneksi,'riwayat-tagihan',$filters);
    if($reference!==$actual)throw new RuntimeException('Fractional report rows, types, totals or order changed');
    if(isset($GLOBALS['report_history_sink'][$koneksi->thread_id])||isset($GLOBALS['report_history_pending'][$koneksi->thread_id]))throw new RuntimeException('Read state leaked');
    echo "PASS fractional money retains identical full report/export input; fixture rolled back.\n";
}finally{$koneksi->rollback();}
