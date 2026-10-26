<?php
/** Report regressions on a verified isolated restore. Every fixture is rolled back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
require_once __DIR__.'/../includes/excel.php';
$db=$koneksi;$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
$checks=0;
function gft_check(bool $ok,string $label): void {
    global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";
}
function gft_report(string $template='per-item',array $extra=[]): array {
    global $db;
    return report_build($db,$template,report_filters($db,array_replace([
        'template'=>$template,'kategori'=>'biaya_lain:20','komponen_tagihan'=>'biaya_lain:20',
        'q'=>'252601095','siswa_status'=>'all','tahun_ajaran'=>'2026/2027',
        'tanggal_awal'=>$template==='riwayat-tagihan'?'':'2026-10-01',
        'tanggal_akhir'=>$template==='riwayat-tagihan'?'':'2026-10-26',
    ],$extra)));
}
function gft_optional(array $report): int {
    return array_sum(array_map(static fn($r)=>lb_result_cents($r['pembayaran_opsional']??0),$report['rows']));
}
function gft_items(array $items,int $id): void {
    global $db;lf_query($db,'UPDATE legacy_op_payments SET items=? WHERE id=?',[lf_json($items),$id]);
}
$db->begin_transaction();$oldGate=(int)$db->query('SELECT COALESCE(@legacy_full_apply,0)')->fetch_row()[0];
try {
    $db->query('SET @legacy_full_apply=1');
    $owner=hp_one($db,"SELECT o.* FROM legacy_op_owners o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE s.NO_INDUK='252601095'");
    if(!$owner)throw new RuntimeException('Restore the documented test3 snapshot containing the Aqila regression.');
    $payment=hp_one($db,"SELECT * FROM legacy_op_payments WHERE owner_id=? AND cancelled=0 AND source_date='2026-10-02 00:00:00'",[$owner['id']]);
    $id=(int)$payment['id'];$items=json_decode($payment['items'],true,512,JSON_THROW_ON_ERROR);
    $slot=array_search('biaya_lain:20',array_column($items,'category'),true);
    gft_check($slot!==false&&lf_cents($items[$slot]['amount'])===45000000,'Source item proves the Robotik payment');
    foreach(['252601095','Aqila Areta Azzahra'] as $q){
        $r=gft_report('per-item',['q'=>$q]);
        gft_check(gft_optional($r)===45000000&&$r['rows'][0]['status']==='Ada Pembayaran','Name/NIS search finds the exact historical fee');
        gft_check($r['rows'][0]['tagihan']===null&&$r['rows'][0]['sisa']===null,'A receipt does not invent principal or settlement');
    }
    gft_check(gft_optional(gft_report('per-item',['tanggal_awal'=>'2026-10-02','tanggal_akhir'=>'2026-10-02']))===45000000,'Exact cash date includes the Legacy payment');
    $outside=gft_report('per-item',['tanggal_awal'=>'2026-10-26','tanggal_akhir'=>'2026-10-26']);
    gft_check(gft_optional($outside)===0&&$outside['rows'][0]['status']==='Ada Pembayaran di Luar Periode','Outside-date evidence survives without adding period cash');
    gft_check(count(gft_report('per-item',['tanggal_awal'=>'2026-10-26','tanggal_akhir'=>'2026-10-26','status'=>['ada_pembayaran']])['rows'])===1,'Existing payment status checkbox also finds outside-period evidence');
    $history=gft_report('riwayat-tagihan');
    gft_check(array_sum(array_column($history['rows'],'terbayar'))===450000.0,'Billing history includes payment-only Legacy fees once');
    $currentClass=hp_one($db,'SELECT master_kelas_id FROM siswa WHERE id=?',[$owner['student_id']]);
    $classHistory=gft_report('riwayat-tagihan',['kelas'=>'rombel:'.$currentClass['master_kelas_id']]);
    gft_check(array_sum(array_column($classHistory['rows'],'terbayar'))===450000.0,'Existing billing history rombel filter finds the historical fee');
    gft_check($history['rows'][0]['tanggal_dibuat']===null&&$history['rows'][0]['tagihan']===null,'Billing history preserves unknown issue date and principal');
    $grouped=report_billing_history_group_students($history['rows']);
    gft_check($grouped[0]['total_sisa']===null&&$grouped[0]['components'][0]['sisa']===null,'Grouped history never turns an unknown remainder into zero');
    $print=global_report_billing_print_matrix($grouped,report_billing_history_component_columns($grouped),1);
    gft_check(str_contains(global_report_matrix_html($print),'Dibayar Rp 450.000'),'Existing billing PDF component cell displays payment evidence');
    $excel=spp_excel_report_document($history,'riwayat-tagihan',1);
    gft_check(str_contains(spp_excel_text($excel['sheets'][0]['sections'][0]['rows'][0]['component_0']),'Dibayar Rp 450.000'),'Existing billing Excel component cell displays payment evidence');
    gft_check(!gft_report('riwayat-tagihan',['tanggal_awal'=>'2026-10-02','tanggal_akhir'=>'2026-10-02'])['rows'],'An issue-date filter does not invent a historical issue date');
    gft_check(gft_optional(gft_report('status'))===45000000,'Status report and item report agree for the academic year');
    $receipt=gft_report('penerimaan');gft_check(lb_result_cents($receipt['rows'][0]['total_penerimaan'])===45000000,'Receipt recap counts the selected fee once');
    $matrix=global_report_matrix(gft_report(),'per-item',1);
    gft_check(lb_result_cents($matrix['rows'][0]['g0_pembayaran_opsional'])===45000000,'Screen and export matrix retain the same amount');

    $db->query('SAVEPOINT fee_cases');
    foreach([1,2,3,4,5] as $version){$changed=$items;$changed[$slot]['migration']['version']=$version;gft_items($changed,$id);gft_check(gft_optional(gft_report())===45000000,'Metadata version '.$version.' uses the effective category');}
    $changed=$items;$second=$items[$slot];$second['amount']='123.45';$second['column']='JUMLAH2';$second['migration']['source_slot']=2;$changed[]=$second;gft_items($changed,$id);
    gft_check(gft_optional(gft_report())===45012345,'Repeated code in separate slots retains exact cents');
    $other=hp_one($db,'SELECT id FROM master_biaya_lain WHERE id<>20 ORDER BY id LIMIT 1');
    $changed[$slot]['category']='biaya_lain:'.$other['id'];gft_items($changed,$id);
    gft_check(gft_optional(gft_report())===12345,'Corrected effective master overrides stale migration metadata and identical labels');
    gft_check(gft_optional(gft_report('per-item',['kategori'=>'biaya_lain:'.$other['id']]))>=45000000,'The corrected master receives its own payment evidence');
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');

    $oldKomite=$items;
    foreach($oldKomite as &$item)if($item['category']==='komite')$item['category']=lcs_sorga_history_key();unset($item);
    gft_items($oldKomite,$id);
    $periods=report_legacy_periods($db,['2026-10','1900-01']);
    gft_check(($periods['payments']['252601095']['komite']['2026-10']['paid']??0)===1500000,'Old U_SORGA metadata is still read as Komite in obligation reports');
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');
    $duBefore=report_legacy_periods($db)['payments']['252601095']['daftar_ulang']['2026/2027']['paid']??0;
    $mixed=$items;$mixed[$slot]['category']='daftar_ulang';$mixed[$slot]['migration']['period']='2026/2027';gft_items($mixed,$id);
    lf_query($db,"UPDATE legacy_op_payments SET source_period='' WHERE id=?",[$id]);
    $periods=report_legacy_periods($db,['2026/2027','1901/1902']);
    gft_check(($periods['payments']['252601095']['daftar_ulang']['2026/2027']['paid']??0)===$duBefore+45000000,'Explicit DU item year survives a missing receipt-header period');
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');

    lf_query($db,"UPDATE legacy_op_payments SET source_period='' WHERE id=?",[$id]);
    gft_check(gft_optional(gft_report())===45000000&&gft_optional(gft_report('status'))===45000000,'A proven cash date tracks fees without a monthly source period');
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');
    lf_query($db,'UPDATE legacy_op_payments SET source_date=NULL WHERE id=?',[$id]);
    gft_check(gft_optional(gft_report())===0,'Unchecked undated receipts stay outside cash totals');
    gft_check(gft_optional(gft_report('per-item',['include_undated'=>'1']))===45000000,'Checked undated receipts require matching period proof');
    lf_query($db,"UPDATE legacy_op_payments SET source_period='' WHERE id=?",[$id]);
    gft_check(gft_optional(gft_report('per-item',['include_undated'=>'1']))===0,'Missing date and period never create dated cash');
    gft_check(array_sum(array_column(gft_report('riwayat-tagihan')['rows'],'terbayar'))===450000.0,'Undated evidence remains traceable in unfiltered billing history');
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');
    lf_query($db,'UPDATE legacy_op_payments SET cancelled=1 WHERE id=?',[$id]);
    gft_check(gft_optional(gft_report())===0&&gft_report()['rows'][0]['status']!=='Ada Pembayaran di Luar Periode','Cancelled receipts are excluded from current and historical evidence');
    gft_check(!gft_report('riwayat-tagihan')['rows'],'Cancelled receipt does not remain in active billing evidence');
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');

    lf_query($db,'UPDATE legacy_op_payments SET class_snapshot=? WHERE id=?',['1A',$id]);
    $r=gft_report();gft_check(count($r['rows'])===1&&$r['rows'][0]['kelas']==='1A','Payment-only rows retain the transaction class after promotion');
    $historicalClass=hp_one($db,"SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' AND is_placeholder=0");
    foreach(['status','per-item'] as $template){$r=gft_report($template,['kelas'=>'rombel:'.$historicalClass['id']]);gft_check(count($r['rows'])===1&&$r['rows'][0]['kelas']==='1A'&&gft_optional($r)===45000000,'Historical class filter tracks payment-only fees in '.$template);}
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');
    lf_query($db,'INSERT INTO legacy_full_payments(unit_id,batch_id,student_id,row_key,source_table,source_date,source_period,class_snapshot,total) VALUES(1,?,?,?,\'bayar\',\'2026-10-03\',\'10 2026\',\'2C\',100.25)',[$owner['batch_id'],$owner['student_id'],hash('sha256','global-fee-archive-regression')]);
    $archived=(int)$db->insert_id;
    lf_query($db,"INSERT INTO legacy_full_items(unit_id,payment_id,source_column,category,label,amount) VALUES(1,?,'JUMLAH2','biaya_lain:20','Eskul Robotik',100.25)",[$archived]);
    gft_check(gft_optional(gft_report())===45010025,'Old archive receipts and operational receipts combine exactly once');
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');

    $class=hp_one($db,'SELECT master_kelas_id FROM siswa WHERE id=?',[$owner['student_id']]);
    lf_query($db,"INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,status,created_by,master_kelas_id,kelas_rombel_snapshot) VALUES(20,'252601095','Saldo awal Robotik',100000,'open',1,?,'2C')",[$class['master_kelas_id']]);$bill=(int)$db->insert_id;
    $r=gft_report()['rows'][0];gft_check((float)$r['sisa']===100000.0&&$r['terbayar']===0.0&&$r['pembayaran_opsional']===450000.0,'Historical cash does not repay an opening bill a second time');
    lf_query($db,"INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_LAIN,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,total_jumlah) VALUES('252601095','2',?,'2C',50000.25,'2026-10-01','10','2026','1','Tunai',50000.25)",[$class['master_kelas_id']]);$native=(int)$db->insert_id;
    lf_query($db,"INSERT INTO bayar_biaya_lain(bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot) VALUES(?,20,?,'Robotik',50000.25)",[$native,$bill]);
    $r=gft_report()['rows'][0];gft_check($r['terbayar']===50000.25&&(float)$r['sisa']===49999.75,'Native bill allocations combine with separate Legacy receipt evidence');
    $r=gft_report('per-item',['tanggal_awal'=>'2026-10-26','tanggal_akhir'=>'2026-10-26'])['rows'][0];
    gft_check($r['terbayar']===0.0&&(float)$r['sisa']===49999.75&&$r['status']==='Cicilan','A date filter never recreates already paid bill debt');
    lf_query($db,"UPDATE tagihan_biaya_lain SET status='cancelled' WHERE id=?",[$bill]);
    $r=gft_report();gft_check(count($r['rows'])===2&&array_sum(array_column($r['rows'],'pembayaran_opsional'))===450000.0,'Bill cancellation preserves separate active receipt evidence');
    $db->query('ROLLBACK TO SAVEPOINT fee_cases');
    unit_set_context($db,0);$r=gft_report();
    gft_check(gft_optional($r)===45000000&&count($r['rows'])===1&&(int)$r['rows'][0]['unit_id']===1,'Combined units do not merge another unit into the student identity');
    unit_set_context($db,2);gft_check(!gft_report()['rows'],'Another unit cannot find the SD fee by NIS');
    echo "PASS $checks Legacy fee tracking checks; fixtures rolled back.\n";
} finally {$db->rollback();$db->query('SET @legacy_full_apply='.$oldGate);}
