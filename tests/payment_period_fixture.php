<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/http_form_scope.php';
spp_test_isolated_manifest();
require __DIR__.'/../koneksi.php';
spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/payment_period.php';
require_once __DIR__.'/../includes/kelas.php';

$db = $koneksi;
if (!payment_period_schema_ready($db)) throw new RuntimeException('Install the feature schema on the isolated clone.');
unit_set_context($db,1);

$command = $argv[1] ?? 'seed';
if ($command === 'reports') {
    require_once __DIR__.'/../includes/reports.php';
    $nis = $argv[2];
    $common = ['q'=>$nis,'tahun_ajaran'=>'2026/2027','tanggal_awal'=>'2026-11-23','tanggal_akhir'=>'2026-11-23'];
    $receipts = report_receipt_data($db,report_filters($db,$common+['template'=>'penerimaan','kategori'=>'semua'],false))['rows'];
    $months = report_academic_months('2026/2027');
    $monthly = [];
    foreach (['spp','komite'] as $kind) {
        $rows = report_monthly_evidence($db,$kind,$months,report_filters($db,$common+['kategori'=>$kind],false));
        foreach ($rows as $row) if ($row['nis'] === $nis) {
            foreach ($row['periods'] as $period=>$cell) $monthly[$kind][$period] = $cell['paid'];
        }
    }
    echo json_encode(['receipts'=>$receipts,'monthly'=>$monthly],JSON_THROW_ON_ERROR);
    exit;
}
if ($command === 'legacy_positions') {
    $cache = lb_cache($db);
    $positions = 0;
    foreach ($cache['students'] as $student) {
        $nis = $student['NO_INDUK'];
        $bills = $cache['native_monthly'][$student['unit_id'].'|'.$nis] ?? [];
        foreach ($bills as $key=>$bill) {
            [$kind,$period] = explode('|',$key);
            $legacy = (int)($cache['paid'][$student['id']][$kind][$period] ?? 0);
            if ($legacy <= 0 || $bill['status'] !== 'open') continue;
            $expected = max(0,lf_cents($bill['nominal_tagihan'])-lf_cents($bill['paid'])-$legacy);
            $actual = payment_period_positions($db,$nis)[$period][$kind]['remaining'] ?? null;
            if ($actual !== $expected) throw new RuntimeException('The range planner did not honor recognized Legacy credit.');
            if (++$positions === 20) break 2;
        }
    }
    echo json_encode(['checked'=>$positions],JSON_THROW_ON_ERROR);
    exit;
}
if ($command === 'inject_failure' || $command === 'remove_failure') {
    $db->query('DROP TRIGGER IF EXISTS qa_payment_period_rollback');
    if ($command === 'inject_failure') {
        $bill = lf_one($db,"SELECT id FROM tagihan_komite WHERE no_induk=? AND bulan='12' AND tahun='2026'",[$argv[2]]);
        if (!(int)($bill['id'] ?? 0)) throw new RuntimeException('Test bill not found.');
        $id = (int)$bill['id'];
        $db->query("CREATE TRIGGER qa_payment_period_rollback BEFORE INSERT ON bayar_komite_data FOR EACH ROW
            BEGIN IF NEW.tagihan_komite_id=$id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA: alokasi gagal'; END IF; END");
    }
    exit;
}
if ($command === 'state') {
    $nis = $argv[2];
    $payments = lf_rows($db,'SELECT id,U_SPP,U_KOMITE,total_jumlah,TGL_BYR,sistem_pembayaran FROM bayar WHERE NO_INDUK=? ORDER BY id',[$nis]);
    foreach ($payments as &$payment) {
        $payment['range'] = payment_period_meta($db,(int)$payment['id']);
        $payment['allocations'] = payment_period_receipt_rows($db,(int)$payment['id']);
    }
    unset($payment);
    $requests = lf_rows($db,'SELECT id,status,action,bayar_id FROM transaksi_otorisasi WHERE no_induk_snapshot=? ORDER BY id',[$nis]);
    $events = lf_rows($db,"SELECT payment_id,action FROM pembayaran_aktivitas
        WHERE JSON_UNQUOTE(JSON_EXTRACT(COALESCE(after_snapshot,before_snapshot),'$.payment.NO_INDUK'))=? ORDER BY id",[$nis]);
    echo json_encode(['payments'=>$payments,'requests'=>$requests,'events'=>$events],JSON_THROW_ON_ERROR);
    exit;
}
if ($command !== 'seed') throw new InvalidArgumentException('Unknown fixture command.');

$year = '2026/2027';
$db->begin_transaction();
try {
    $actors = [];
    $actors['super_admin'] = (int)(lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1")['id'] ?? 0);
    if (!$actors['super_admin']) throw new RuntimeException('The isolated clone needs a Super Admin.');
    foreach (['admin','cashier'] as $actor) {
        $login = 'qa_period_'.$actor.'_'.bin2hex(random_bytes(4));
        lf_query($db,'INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,?,?,?,1)',
            [$login,password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),'Petugas Uji '.$actor,'admin',1]);
        $actors[$actor] = (int)$db->insert_id;
    }
    $masters = [$year=>spp_master_ensure_year($db,$year,true),'2027/2028'=>spp_master_ensure_year($db,'2027/2028',true)];
    $class = lf_one($db,"SELECT id,tingkat,kode_rombel,is_placeholder FROM master_kelas WHERE tingkat=4
        AND jenis='reguler' AND is_active=1 AND is_placeholder=0 LIMIT 1");
    $classLabel = class_label($class);
    $fixtures = [];
    foreach (['main','annual','arrears','three','missing','race','monthly','rollback'] as $scenario) {
        $academicYear = $scenario === 'annual' ? '2027/2028' : $year;
        $master = $masters[$academicYear];
        $startOrder = match($scenario) {'annual'=>0,'arrears'=>3,default=>4};
        $nis = (string)random_int(9400000000,9499999999);
        $label = 'Uji Periode '.$scenario;
        $fixtures[$scenario] = ['nis'=>$nis,'label'=>$label];
        lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,PSB,is_active)
            VALUES (?,?,4,?,250000.25,50000,?,1)",[$nis,$label,$class['id'],$scenario==='main'?100000:0]);
        lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,
            spp_perbulan_snapshot,komite_snapshot,komite_mulai_bulan,status)
            VALUES (?,?,4,?,?,250000.25,50000,?,'aktif')",
            [$master['tahun_ajaran_id'],$nis,$class['id'],$classLabel,spp_academic_periods($academicYear)[$startOrder]['bulan']]);
        $placement = (int)$db->insert_id;
        foreach (spp_academic_periods($academicYear) as $period) {
            if ($period['order'] < $startOrder) continue;
            $month = $period['bulan'];
            $calendar = $period['tahun'];
            lf_query($db,"INSERT INTO tagihan_spp(master_spp_tahun_id,tahun_ajaran_id,penempatan_id,no_induk,tingkat_snapshot,
                master_kelas_id,kelas_rombel_snapshot,bulan,tahun,tarif_dasar_snapshot,nominal_tagihan,status)
                VALUES (?,?,?,?,4,?,?,?,?,250000.25,250000.25,'open')",
                [$master['id'],$master['tahun_ajaran_id'],$placement,$nis,$class['id'],$classLabel,$month,$calendar]);
            if ($scenario==='missing' && $month==='12') continue;
            lf_query($db,"INSERT INTO tagihan_komite(tahun_ajaran_id,penempatan_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan)
                VALUES (?,?,?,?,?,?,50000)",[$master['tahun_ajaran_id'],$placement,$nis,$classLabel,$month,$calendar]);
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
$sessions = [];
foreach ($actors as $role=>$actor) {
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION = ['admin_id'=>$actor,'admin_role'=>$role === 'super_admin' ? $role : 'admin',
        'admin_nama'=>'Petugas Uji '.$role,'active_unit_id'=>1,'admin_unit_id'=>1];
    $sessions[$role] = session_id();
    session_write_close();
}
echo json_encode(['database'=>DB_NAME,'cases'=>$fixtures,'sessions'=>$sessions],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
