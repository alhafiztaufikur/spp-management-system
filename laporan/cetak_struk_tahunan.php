<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/tagihan_tahunan.php';
require_once '../includes/tagihan_sekali.php';
require_once '../includes/reports.php';
require_once '../includes/receipt_balances.php';
require_once '../includes/komite_billing.php';
requireRole(['admin', 'bendahara', 'kasir']);
require_once __DIR__.'/../includes/payment_permissions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$batchToken = strtolower(trim((string)($_GET['batch'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $batchToken)) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Kelompok struk tahunan tidak valid.'];
    header('Location: index.php');
    exit;
}

function annual_receipt_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function annual_receipt_money($value, bool $showZero = false): string {
    $amount = (float)$value;
    if (!$showZero && abs($amount) < 0.005) return '';
    return number_format($amount, 0, ',', '.');
}

function annual_receipt_month($value): string {
    $months = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $code = str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
    return $months[$code] ?? (string)$value;
}

function annual_receipt_date($value): string { return spp_date_label($value,true); }

function annual_receipt_words(int $number): string {
    $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($number < 12) return $words[$number];
    if ($number < 20) return annual_receipt_words($number - 10) . ' belas';
    if ($number < 100) return annual_receipt_words(intdiv($number, 10)) . ' puluh' . ($number % 10 ? ' ' . annual_receipt_words($number % 10) : '');
    if ($number < 200) return 'seratus' . ($number - 100 ? ' ' . annual_receipt_words($number - 100) : '');
    if ($number < 1000) return annual_receipt_words(intdiv($number, 100)) . ' ratus' . ($number % 100 ? ' ' . annual_receipt_words($number % 100) : '');
    if ($number < 2000) return 'seribu' . ($number - 1000 ? ' ' . annual_receipt_words($number - 1000) : '');
    if ($number < 1000000) return annual_receipt_words(intdiv($number, 1000)) . ' ribu' . ($number % 1000 ? ' ' . annual_receipt_words($number % 1000) : '');
    if ($number < 1000000000) return annual_receipt_words(intdiv($number, 1000000)) . ' juta' . ($number % 1000000 ? ' ' . annual_receipt_words($number % 1000000) : '');
    return annual_receipt_words(intdiv($number, 1000000000)) . ' miliar' . ($number % 1000000000 ? ' ' . annual_receipt_words($number % 1000000000) : '');
}

function annual_receipt_month_code($value): string {
    $map = ['Januari'=>'01','Februari'=>'02','Maret'=>'03','April'=>'04','Mei'=>'05','Juni'=>'06','Juli'=>'07','Agustus'=>'08','September'=>'09','Oktober'=>'10','November'=>'11','Desember'=>'12'];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
}

function annual_receipt_period_paid(mysqli $db, string $noInduk, string $bulan, string $tahun): array {
    $monthCode = annual_receipt_month_code($bulan);
    $monthLabel = annual_receipt_month($monthCode);
    $legacyMonth = (string)(int)$monthCode;
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(U_SPP), 0) AS spp, COALESCE(SUM(U_KOMITE), 0) AS komite
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = ? AND (BULAN = ? OR BULAN = ? OR BULAN = ?)
    ");
    $stmt->bind_param('sssss', $noInduk, $tahun, $monthCode, $monthLabel, $legacyMonth);
    $stmt->execute();
    $paid = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return ['spp' => (float)($paid['spp'] ?? 0), 'komite' => (float)($paid['komite'] ?? 0)];
}

function annual_receipt_du_paid(mysqli $db, int $billId): float {
    if ($billId <= 0) return 0.0;
    $stmt = $db->prepare('SELECT COALESCE(SUM(jumlah), 0) AS paid FROM bayar_du WHERE tagihan_daftar_ulang_id = ?');
    $stmt->bind_param('i', $billId);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function annual_receipt_biaya_lain_paid(mysqli $db, string $noInduk, int $masterId): float {
    if ($masterId <= 0) return 0.0;
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(d.nominal_snapshot), 0) AS paid
        FROM bayar_biaya_lain d
        JOIN bayar b ON b.id = d.bayar_id
        WHERE b.NO_INDUK = ? AND d.master_biaya_lain_id = ?
    ");
    $stmt->bind_param('si', $noInduk, $masterId);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function annual_receipt_add_remaining_line(array &$lines, string $label, float $currentAmount, float $total, float $paid): void {
    if (abs($currentAmount) < 0.005 || $total <= 0) return;
    $lines[] = [$label, max(0, $total - $paid)];
}

function annual_receipt_remaining_lines(mysqli $db,array $payment,array $otherDetails): array {return payment_receipt_remaining($db,$payment);}

$stmt = $koneksi->prepare("SELECT id FROM bayar WHERE payment_batch_token = ? ORDER BY payment_batch_sequence, id");
$stmt->bind_param('s', $batchToken);
$stmt->execute();
$ids = array_map(fn($row) => (int)$row['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
$stmt->close();
if (!$ids) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Struk tahunan tidak ditemukan.'];
    header('Location: index.php');
    exit;
}

if (!payment_can_print_batch($koneksi,$batchToken)) { http_response_code(403); exit('Kelompok struk terkunci: setiap transaksi harus milik pembuat awal.'); }

$paymentStmt = $koneksi->prepare("
    SELECT b.*, s.NAMA, s.NO_induk_diknas, s.KELAS AS KELAS_SISWA, s.PSB,
           s.SPP_PERBULAN, s.POMG,
           s.DAFTAR_ULANG, s.potong_du, s.tot_du,
           du.tagihan_daftar_ulang_id, du.th_ajaran AS du_tahun_ajaran, COALESCE(du.jumlah, 0) AS uang_du,
           COALESCE(tdu.nominal_tagihan, 0) AS du_nominal_tagihan,
           COALESCE(op.nama, NULLIF(b.user_id, '')) AS operator_name,
           COALESCE((SELECT SUM(bd.jumlah) FROM bayar_du bd WHERE bd.no_induk = b.NO_INDUK AND bd.unit_id=b.unit_id), 0) AS total_du_bayar
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
    LEFT JOIN admin op ON op.id = CAST(b.user_id AS UNSIGNED)
    LEFT JOIN bayar_du du ON du.bayar_id = b.id
    LEFT JOIN tagihan_daftar_ulang tdu ON tdu.id = du.tagihan_daftar_ulang_id
    WHERE b.id = ? LIMIT 1
");
$otherStmt = $koneksi->prepare('
    SELECT d.master_biaya_lain_id, d.nama_biaya_snapshot, d.nominal_snapshot, d.keterangan, m.nominal AS master_nominal
    FROM bayar_biaya_lain d
    LEFT JOIN master_biaya_lain m ON m.id = d.master_biaya_lain_id
    WHERE d.bayar_id = ?
    ORDER BY d.urutan, d.id
');
$receipts = [];
$receiptScope=(int)$GLOBALS['app_unit_id'];
foreach ($ids as $paymentId) {
    unit_set_context($koneksi,$receiptScope);
    $paymentStmt->bind_param('i', $paymentId);
    $paymentStmt->execute();
    $payment = $paymentStmt->get_result()->fetch_assoc();
    if (!$payment) continue;
    unit_set_context($koneksi,(int)$payment['unit_id']);
    $otherStmt->bind_param('i', $paymentId);
    $otherStmt->execute();
    $otherDetails = $otherStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $payment['primary_lines'] = [
        ['Uang PSB', $payment['U_PSB']], ['Uang Daftar Ulang' . (!empty($payment['du_tahun_ajaran']) ? ' (TA ' . $payment['du_tahun_ajaran'] . ')' : ''), $payment['uang_du']],
        ['Uang SPP', $payment['U_SPP']], ['Komite Sekolah', $payment['U_KOMITE']],
        ['Tabungan Wajib',0],
    ];
    $payment['other_lines'] = [];
    foreach ($otherDetails as $detail) {
        $label = $detail['nama_biaya_snapshot'];
        if (trim((string)$detail['keterangan']) !== '') $label .= ' - ' . $detail['keterangan'];
        $payment['other_lines'][] = [$label, $detail['nominal_snapshot']];
    }
    if ((float)$payment['potong_spp'] > 0) $payment['other_lines'][] = ['Potongan SPP', -(float)$payment['potong_spp']];
    $payment['other_lines'] = array_values(array_filter($payment['other_lines'], fn($line) => abs((float)$line[1]) >= 0.005));
    $payment['remaining_lines'] = annual_receipt_remaining_lines($koneksi, $payment, $otherDetails);
    $receipts[] = $payment;
}
$paymentStmt->close();
$otherStmt->close();
require_once __DIR__.'/../includes/receipt_print.php';
$documents=[];
foreach($receipts as $receipt) $documents[]=[
    'payment'=>$receipt, 'school'=>unit_school_name((int)$receipt['unit_id']),
    'primary'=>$receipt['primary_lines'], 'remaining'=>$receipt['remaining_lines'], 'other'=>$receipt['other_lines'],
    'period'=>annual_receipt_month($receipt['BULAN']).' '.$receipt['TAHUN'],
    'reference'=>' - No. #'.$receipt['id'].' - '.$receipt['payment_batch_sequence'].'/'.$receipt['payment_batch_count'],
    'words'=>ucfirst(annual_receipt_words((int)round((float)$receipt['total_jumlah']))).' rupiah',
    'date'=>annual_receipt_date($receipt['TGL_BYR']),
    'signer'=>$receipt['operator_name'] ?: ($_SESSION['admin_nama']??'Bagian Keuangan'),
];
receipt_http_output($documents, 'Struk Tahunan', count($documents).' struk tahunan siap dicetak');
