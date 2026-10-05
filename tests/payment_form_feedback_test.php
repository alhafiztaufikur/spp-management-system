<?php

require_once __DIR__ . '/../includes/payment_form_feedback.php';

function feedback_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$draft = payment_capture_draft([
    'no_induk' => '20270001',
    'bulan_bayar' => '07',
    'uang_spp' => '250000',
    'sistem_pembayaran' => 'Tunai',
    'biaya_lain_tagihan_id' => [12, 13],
    'biaya_lain_nominal' => ['10000', '20000'],
    'biaya_lain_keterangan' => ['Buku', 'Seragam'],
    'csrf_token' => 'jangan-disimpan',
]);
feedback_assert($draft['no_induk'] === '20270001' && $draft['uang_spp'] === '250000', 'Isian pembayaran tidak dipulihkan.');
feedback_assert($draft['biaya_lain_nominal'] === ['10000', '20000'], 'Rincian Biaya Lain tidak dipulihkan.');
feedback_assert(!array_key_exists('csrf_token', $draft), 'Token tidak boleh masuk ke draft pembayaran.');
feedback_assert(count(payment_capture_draft(['biaya_lain_nominal' => array_fill(0, 15, '1')])['biaya_lain_nominal']) === 12, 'Jumlah rincian draft tidak dibatasi.');

$cases = [
    ['Pembayaran Uang PSB melebihi sisa tagihan', 'over_limit', 'psb-input'],
    ['Tagihan Biaya Lain tidak tersedia untuk siswa ini.', 'billing_changed', 'biaya-lain-list'],
    ['Tagihan Komite belum tersedia.', 'billing_changed', 'komite-input'],
    ['SPP Juli tidak boleh menjadi tunggakan.', 'prior_unpaid_edit', 'bulan-bayar'],
];
foreach ($cases as [$message, $code, $target]) {
    $flash = payment_failure_flash(new RuntimeException($message), 'Gagal menyimpan: ');
    feedback_assert(($flash['spp_status']['code'] ?? null) === $code, 'Kode popup tidak sesuai: ' . $message);
    feedback_assert(($flash['spp_status']['target'] ?? null) === $target, 'Target fokus popup tidak sesuai: ' . $message);
}

$typed = payment_failure_flash(new SppPaymentException(['code' => 'komite_required', 'message' => 'Isi Komite.']), 'Gagal: ');
feedback_assert($typed['msg'] === 'Isi Komite.' && $typed['spp_status']['code'] === 'komite_required', 'Error terstruktur tidak dipertahankan.');
$prior = payment_failure_flash(new SppBillingOrderException('07', '2026'), 'Gagal menyimpan: ');
feedback_assert($prior['msg'] === 'Lunasi dahulu SPP Juli 2026.'
    && ($prior['spp_status']['code'] ?? '') === 'prior_unpaid'
    && ($prior['spp_status']['target'] ?? '') === 'bulan-bayar',
    'Periode SPP tertua hilang dari pesan penolakan kasir.');
$generic = payment_failure_flash(new RuntimeException('Kesalahan lain.'), 'Gagal menyimpan: ');
feedback_assert($generic['msg'] === 'Gagal menyimpan: Kesalahan lain.' && !isset($generic['spp_status']), 'Fallback error berubah.');
$concurrent = payment_failure_flash(new mysqli_sql_exception('Deadlock', 1213), 'Gagal: ');
feedback_assert(($concurrent['spp_status']['code'] ?? null) === 'billing_changed', 'Konflik kasir tidak ditangani.');
$databaseFailure = payment_failure_flash(new mysqli_sql_exception('Unknown column internal_secret', 1054), 'Gagal: ');
feedback_assert(($databaseFailure['spp_status']['code'] ?? null) === 'database_error'
    && !str_contains($databaseFailure['msg'], 'internal_secret'), 'Detail skema bocor ke kasir.');

foreach (['missing', 'not_found', 'wrong_student', 'cancelled', 'future', 'settled', 'changed', 'over_limit', 'overpaid'] as $reason) {
    $du = payment_failure_flash(new DaftarUlangSelectionException($reason, 'Pesan Daftar Ulang'), 'Gagal: ');
    feedback_assert(($du['spp_status']['code'] ?? null) === 'du_' . $reason, 'Alasan Daftar Ulang hilang: ' . $reason);
    feedback_assert(($du['spp_status']['target'] ?? null) === 'du-input', 'Fokus Daftar Ulang keliru: ' . $reason);
    feedback_assert($du['msg'] === 'Pesan Daftar Ulang', 'Pesan Daftar Ulang berubah: ' . $reason);
}

echo "OK: draft pembayaran dan pesan popup gagal simpan.\n";
