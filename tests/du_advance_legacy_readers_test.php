<?php
/** Run after the advance browser fixture on a verified, separately owned restore. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/../includes/legacy_du.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/registration_combined.php';
$db=$koneksi;$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
$year=du_next_year();$plan=ldu_plan($db,$year);
$known=array_filter($plan['positions'],fn($p)=>$p['known']&&$p['bill']);
if(count($known)!==83)throw new RuntimeException('Prepare the 83 SD projected invoices through the existing Master DU browser fixture.');
$report=report_annual_evidence($db,report_filters($db,['template'=>'per-item','kategori'=>'daftar_ulang','tahun_ajaran'=>$year]));
$rows=[];foreach($report['rows'] as $row)$rows[$row['nis']]=$row;
$history=[];foreach(hp_rows($db,'SELECT * FROM '.rc_groups($db).' q WHERE th_ajaran=?',[$year]) as $row)$history[$row['student_id']]=$row;
foreach($known as $position){
    $bill=du_find_bill_by_id($db,(int)$position['bill']['id']);$row=$rows[$position['nis']]??null;
    $group=$history[$position['student_id']]??null;$evidence=$group?rc_tariff_evidence($db,$group):null;
    if($position['reason']!==null||$position['proof']['basis']!=='system'||$position['proof']['grade']!==2||$position['placement']!==null
        ||!$bill||$bill['tariff_basis']!=='system'||!$row||$row['kelas']!=='2'||$row['tagihan']!==$bill['nominal_tagihan']||$row['sisa']!==$bill['sisa']
        ||!$evidence||$evidence['remaining']!==$bill['sisa'])throw new RuntimeException('An unplaced early invoice differs across the Legacy planner, cashier, history or report.');
}
echo json_encode(['checks'=>83,'year'=>$year,'placement_not_created'=>true,'tariff_basis'=>'system','history_reports_cashier_agree'=>true],JSON_PRETTY_PRINT).PHP_EOL;
