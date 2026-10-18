<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/tagihan_tahunan.php';
require_once '../includes/tagihan_sekali.php';
require_once '../includes/reports.php';
require_once '../includes/receipt_balances.php';
require_once '../includes/spp_billing.php';
require_once '../includes/komite_billing.php';
requireRole(['admin', 'bendahara', 'kasir']);
require_once __DIR__.'/../includes/payment_permissions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$paymentId = max(0, (int)($_GET['id'] ?? 0));
if ($paymentId <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Transaksi untuk struk tidak valid.'];
    header('Location: index.php');
    exit;
}

function receipt_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function receipt_money($value, bool $showZero = false): string {
    $amount = (float)$value;
    if (!$showZero && abs($amount) < 0.005) return '';
    return number_format($amount, 0, ',', '.');
}

function receipt_month($value): string {
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    $legacy = array_flip($months);
    if (isset($legacy[$value])) return $value;
    $code = str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
    return $months[$code] ?? (string)$value;
}

function receipt_date($value): string { return spp_date_label($value,true); }

function receipt_words(int $number): string {
    $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($number < 12) return $words[$number];
    if ($number < 20) return receipt_words($number - 10) . ' belas';
    if ($number < 100) return receipt_words(intdiv($number, 10)) . ' puluh' . ($number % 10 ? ' ' . receipt_words($number % 10) : '');
    if ($number < 200) return 'seratus' . ($number - 100 ? ' ' . receipt_words($number - 100) : '');
    if ($number < 1000) return receipt_words(intdiv($number, 100)) . ' ratus' . ($number % 100 ? ' ' . receipt_words($number % 100) : '');
    if ($number < 2000) return 'seribu' . ($number - 1000 ? ' ' . receipt_words($number - 1000) : '');
    if ($number < 1000000) return receipt_words(intdiv($number, 1000)) . ' ribu' . ($number % 1000 ? ' ' . receipt_words($number % 1000) : '');
    if ($number < 1000000000) return receipt_words(intdiv($number, 1000000)) . ' juta' . ($number % 1000000 ? ' ' . receipt_words($number % 1000000) : '');
    return receipt_words(intdiv($number, 1000000000)) . ' miliar' . ($number % 1000000000 ? ' ' . receipt_words($number % 1000000000) : '');
}

function receipt_month_code($value): string {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12',
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
}

function receipt_du_paid(mysqli $db, int $billId): float {
    if ($billId <= 0) return 0.0;
    $stmt = $db->prepare('SELECT COALESCE(SUM(jumlah), 0) AS paid FROM bayar_du WHERE tagihan_daftar_ulang_id = ?');
    $stmt->bind_param('i', $billId);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function receipt_biaya_lain_paid(mysqli $db, string $noInduk, int $masterId): float {
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

function receipt_add_remaining_line(array &$lines, string $label, float $currentAmount, float $total, float $paid): void {
    if (abs($currentAmount) < 0.005 || $total <= 0) return;
    $lines[] = [$label, max(0, $total - $paid)];
}

function receipt_remaining_lines(mysqli $db,array $payment,array $otherDetails): array {return payment_receipt_remaining($db,$payment);}

$stmt = $koneksi->prepare("
    SELECT
        b.*,
        s.NAMA,
        s.KELAS AS KELAS_SISWA,
        s.PSB,
        s.NO_induk_diknas,
        s.SPP_PERBULAN,
        s.POMG,
        s.DAFTAR_ULANG,
        s.potong_du,
        s.tot_du,
        du.tagihan_daftar_ulang_id,
        du.th_ajaran AS du_tahun_ajaran,
        COALESCE(du.jumlah, 0) AS uang_du,
        COALESCE(tdu.nominal_tagihan, 0) AS du_nominal_tagihan,
        COALESCE(op.nama, NULLIF(b.user_id, '')) AS operator_name,
        COALESCE((SELECT SUM(bd.jumlah) FROM bayar_du bd WHERE bd.no_induk = b.NO_INDUK AND bd.unit_id=b.unit_id), 0) AS total_du_bayar
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
    LEFT JOIN admin op ON op.id = CAST(b.user_id AS UNSIGNED)
    LEFT JOIN bayar_du du ON du.bayar_id = b.id
    LEFT JOIN tagihan_daftar_ulang tdu ON tdu.id = du.tagihan_daftar_ulang_id
    WHERE b.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
if($payment) unit_set_context($koneksi,(int)$payment['unit_id']);
$stmt->close();
$sppAllocation = spp_billing_schema_ready($koneksi) ? spp_payment_allocation_summary($koneksi, $paymentId) : null;

if (!$payment) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Transaksi tidak ditemukan atau siswa sudah tidak tersedia.'];
    header('Location: index.php');
    exit;
}

if (!payment_capabilities($koneksi,$payment,null,false,[])['can_print']) { http_response_code(403); exit('Struk terkunci: hanya pembuat awal atau Super Admin yang dapat mencetak transaksi ini.'); }

$otherDetails = [];
$stmt = $koneksi->prepare('
    SELECT d.master_biaya_lain_id, d.nama_biaya_snapshot, d.nominal_snapshot, d.keterangan, m.nominal AS master_nominal
    FROM bayar_biaya_lain d
    LEFT JOIN master_biaya_lain m ON m.id = d.master_biaya_lain_id
    WHERE d.bayar_id = ?
    ORDER BY d.urutan, d.id
');
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$detailResult = $stmt->get_result();
while ($detail = $detailResult->fetch_assoc()) $otherDetails[] = $detail;
$stmt->close();

$komiteReceipt=komite_receipt_summary($koneksi,$paymentId);
$primaryLines = [
    ['Uang PSB', $payment['U_PSB']],
    ['Uang Daftar Ulang', $payment['uang_du']],
    ['Uang SPP', $payment['U_SPP']],
    ['Komite Sekolah',$payment['U_KOMITE']],
    ['Tabungan Wajib',0],
];
$otherLines = [];
foreach ($otherDetails as $detail) {
    $label = $detail['nama_biaya_snapshot'];
    if (trim((string)$detail['keterangan']) !== '') $label .= ' - ' . $detail['keterangan'];
    $otherLines[] = [$label, $detail['nominal_snapshot']];
}
if ((float)$payment['potong_spp'] > 0) $otherLines[] = ['Potongan SPP', -(float)$payment['potong_spp']];
$otherLines = array_values(array_filter($otherLines, fn($line) => abs((float)$line[1]) >= 0.005));

$remainingLines = receipt_remaining_lines($koneksi, $payment, $otherDetails);
$total = (float)$payment['total_jumlah'];
$signer = $payment['operator_name'] ?: ($_SESSION['admin_nama'] ?? 'Bagian Keuangan');
require_once __DIR__.'/../includes/receipt_print.php';
receipt_http_output([[
    'payment'=>$payment, 'school'=>unit_school_name((int)$payment['unit_id']),
    'primary'=>$primaryLines, 'remaining'=>$remainingLines, 'other'=>$otherLines,
    'period'=>receipt_month($payment['BULAN']).' '.$payment['TAHUN'],
    'words'=>ucfirst(receipt_words((int)round($total))).' rupiah',
    'date'=>receipt_date($payment['TGL_BYR']), 'signer'=>$signer,
]], 'Struk #'.$paymentId.' - '.$payment['NAMA'], 'Struk siap dicetak');
