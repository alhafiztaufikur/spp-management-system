<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/reports.php';
require_once '../includes/receipt_balances.php';
require_once '../includes/payment_period.php';
requireRole(['admin', 'bendahara', 'kasir']);
require_once __DIR__.'/../includes/payment_permissions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$paymentId = (int)($_GET['id'] ?? 0);
if ($paymentId === 0) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Transaksi untuk struk tidak valid.'];
    header('Location: index.php');
    exit;
}

require_once __DIR__.'/../includes/other_fee_usage.php';
try { other_fee_assert_history_payment($koneksi,$_GET,$paymentId); }
catch(InvalidArgumentException $e) { http_response_code(400);exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8')); }

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

function receipt_date($value): string { return $value?spp_date_label($value,true):'Tidak tercatat'; }

if ($paymentId < 0) {
    require_once __DIR__.'/../includes/payment_history.php';
    require_once __DIR__.'/../includes/receipt_print.php';
    try {
        $model = payment_history_model($koneksi,$paymentId,false);
        $payment = $model['payment'] ?? null;
        if (!$payment) throw new DomainException('Struk arsip belum tersedia.');
        if (unit_active_id() === 0) unit_set_context($koneksi,(int)$payment['unit_id']);
        $payment['owner_type'] = empty($payment['student_id']) ? 'account' : 'student';
        if (!empty($payment['student_id']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            lb_scope_student_read($koneksi,(string)$payment['NO_INDUK']);
        }
        session_write_close();
        receipt_http_output([[
            'payment'=>$payment, 'school'=>unit_school_name((int)$payment['unit_id']),
            'primary'=>$model['components'], 'remaining'=>payment_receipt_remaining($koneksi,$payment,singleStudent:true), 'other'=>[],
            'period'=>payment_history_period($payment), 'words'=>receipt_money_words((float)$payment['total_jumlah']),
            'date'=>receipt_date($payment['TGL_BYR']), 'signer'=>$payment['source_operator'] ?: 'Tidak tercatat',
        ]], 'Struk Pembayaran', 'Struk siap dicetak');
    } catch (Throwable $error) {
        http_response_code(404);
        echo htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8');
    }
    exit;
}

$stmt = $koneksi->prepare(hp_sql($koneksi, "
    SELECT
        b.*,
        s.NAMA,
        s.KELAS AS KELAS_SISWA,
        s.NO_induk_diknas,
        du.tagihan_daftar_ulang_id,
        du.th_ajaran AS du_tahun_ajaran,du.kelas AS du_kelas,
        COALESCE(du.jumlah, 0) AS uang_du,
        COALESCE(op.nama, NULLIF(b.user_id, '')) AS operator_name
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
    LEFT JOIN admin op ON op.id = CAST(b.user_id AS UNSIGNED)
    LEFT JOIN bayar_du du ON du.bayar_id = b.id AND du.unit_id=b.unit_id
    WHERE b.id = ?
    LIMIT 1
"));
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Transaksi tidak ditemukan atau siswa sudah tidak tersedia.'];
    header('Location: index.php');
    exit;
}

$unit = (int)$payment['unit_id'];
$owner = 0;
if (!operational_role()) {
    $events = payment_activity_for_payments($koneksi, [$paymentId])[$paymentId] ?? [];
    $owner = (int)(payment_activity_summary($events)['creator']['actor_id'] ?? 0);
}
if (!payment_print_allowed($unit,$owner)) { http_response_code(403); exit('Struk terkunci: hanya pembuat awal atau Super Admin yang dapat mencetak transaksi ini.'); }
unit_set_context($koneksi,$unit);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') lb_scope_student_read($koneksi,(string)$payment['NO_INDUK']);
session_write_close();

$range = payment_period_meta($koneksi,$paymentId);
if ($range) {
    require_once __DIR__.'/../includes/receipt_print.php';
    $document = payment_period_receipt_document($koneksi,$payment,payment_period_range_label($range),
        $payment['operator_name'] ?: 'Bagian Keuangan',singleStudent:true);
    receipt_http_output([$document], 'Struk #'.$paymentId.' - '.$payment['NAMA'], 'Struk siap dicetak');
    exit;
}

$otherDetails = [];
$stmt = $koneksi->prepare(hp_sql($koneksi, '
    SELECT d.nama_biaya_snapshot, d.nominal_snapshot, d.keterangan
    FROM bayar_biaya_lain d
    WHERE d.bayar_id = ?
    ORDER BY d.urutan, d.id
'));
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$detailResult = $stmt->get_result();
while ($detail = $detailResult->fetch_assoc()) $otherDetails[] = $detail;
$stmt->close();

$otherLines = [];
foreach ($otherDetails as $detail) {
    $label = $detail['nama_biaya_snapshot'];
    if (trim((string)$detail['keterangan']) !== '') $label .= ' - ' . $detail['keterangan'];
    $otherLines[] = [$label, $detail['nominal_snapshot']];
}
if ((float)$payment['potong_spp'] > 0) $otherLines[] = ['Potongan SPP', -(float)$payment['potong_spp']];
$otherLines = array_values(array_filter($otherLines, fn($line) => abs((float)$line[1]) >= 0.005));

$remainingLines = payment_receipt_remaining($koneksi, $payment, singleStudent:true);
$total = (float)$payment['total_jumlah'];
$signer = $payment['operator_name'] ?: ($_SESSION['admin_nama'] ?? 'Bagian Keuangan');
require_once __DIR__.'/../includes/receipt_print.php';
receipt_http_output([[
    'payment'=>$payment, 'school'=>unit_school_name((int)$payment['unit_id']),
    'primary'=>receipt_school_primary($payment), 'remaining'=>$remainingLines, 'other'=>$otherLines,
    'period'=>receipt_month($payment['BULAN']).' '.$payment['TAHUN'],
    'words'=>receipt_money_words($total),
    'date'=>receipt_date($payment['TGL_BYR']), 'signer'=>$signer,
]], 'Struk #'.$paymentId.' - '.$payment['NAMA'], 'Struk siap dicetak');
