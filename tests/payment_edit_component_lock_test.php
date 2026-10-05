<?php

// Pemeriksaan read-only: tidak menyimpan perubahan ke database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$role = $argv[1] ?? 'admin';
if (!in_array($role, ['admin', 'kasir'], true)) {
    fwrite(STDERR, "FAILED: role test tidak dikenal.\n");
    exit(1);
}

chdir(__DIR__ . '/../pembayaran');
require_once '../koneksi.php';
require_once '../includes/tagihan_sekali.php';
require_once '../includes/komite_billing.php';

$payment = $koneksi->query('SELECT id,NO_INDUK,BULAN,TAHUN,U_SPP,U_KOMITE FROM bayar WHERE id=959 AND payment_link_version=1 LIMIT 1')->fetch_assoc();
if (!$payment) {
    echo "SKIPPED: transaksi demo #959 tidak tersedia.\n";
    exit(0);
}

$availability = one_time_fee_status($koneksi, (string)$payment['NO_INDUK'], (int)$payment['id']);
if ($availability['psb']['total'] > .001) {
    echo "SKIPPED: tagihan Pangkal/PSB transaksi #959 sudah berubah.\n";
    exit(0);
}

session_id('edit-lock-' . $role . '-' . bin2hex(random_bytes(5)));
session_start();
$_SESSION = ['admin_id' => -1, 'admin_role' => $role, 'admin_nama' => 'Lock Smoke Test'];
session_write_close();
$_GET = ['id' => (int)$payment['id']];

ob_start();
include 'edit.php';
$html = ob_get_clean();

foreach (['psb'] as $key) {
    if (!preg_match('/<input\b[^>]*\bid="' . $key . '-input"[^>]*>/s', $html, $match)) {
        throw new RuntimeException('Input ' . $key . ' tidak tersedia.');
    }
    if (!preg_match('/\breadonly\b/', $match[0]) || !str_contains($match[0], 'value="0"')
        || !str_contains($match[0], 'aria-readonly="true"')
        || !str_contains($html, 'id="' . $key . '-context-label"')) {
        throw new RuntimeException('Komponen ' . $key . ' tanpa tagihan belum terkunci sejak render.');
    }
}

foreach (['spp' => 'U_SPP', 'komite' => 'U_KOMITE'] as $key => $column) {
    if (!preg_match('/<input\b[^>]*\bid="' . $key . '-input"[^>]*>/s', $html, $match)
        || !str_contains($match[0], 'value="' . number_format((float)$payment[$column], 0, ',', '.') . '"')) {
        throw new RuntimeException('Nominal asli ' . $key . ' hilang sebelum pemeriksaan tagihan selesai.');
    }
}

foreach (['psb' => 1000.0] as $key => $amount) {
    try {
        validate_one_time_fee_payments($koneksi, (string)$payment['NO_INDUK'], [$key => $amount], (int)$payment['id']);
        throw new RuntimeException('Backend menerima nominal ' . $key . ' tanpa tagihan.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'belum diatur')) throw $error;
    }
}

if ((float)$payment['U_KOMITE'] > .001) {
    $month = str_pad((string)$payment['BULAN'], 2, '0', STR_PAD_LEFT);
    $bill = komite_bill($koneksi, (string)$payment['NO_INDUK'], $month, (string)$payment['TAHUN'], false, (int)$payment['id']);
    if (!$bill || (float)$bill['remaining'] + .001 < (float)$payment['U_KOMITE']) {
        throw new RuntimeException('Sisa Komite di luar transaksi edit tidak memuat nominal aslinya.');
    }
    komite_validate_amount($koneksi, (string)$payment['NO_INDUK'], $month, (string)$payment['TAHUN'], (float)$bill['remaining'], false, (int)$payment['id']);
    try {
        komite_validate_amount($koneksi, (string)$payment['NO_INDUK'], $month, (string)$payment['TAHUN'], (float)$bill['remaining'] + 1000, false, (int)$payment['id']);
        throw new RuntimeException('Backend menerima Komite di atas sisa tagihan.');
    } catch (SppPaymentException $error) {
        if (($error->status()['code'] ?? '') !== 'komite_amount') throw $error;
    }
}

echo "OK: edit #959 untuk {$role} mengunci Pangkal/PSB, menerima Komite yang sah, dan menolak nominal tidak sah.\n";
