<?php

require_once __DIR__ . '/../includes/spp_payment_status.php';

function spp_status_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$student = ['exists' => true, 'is_active' => 1, 'tingkat' => 2, 'kode_rombel' => 'A'];

$payable = spp_payment_status_from_state($student, '07', '2026', 275000, 0, [
    ['bulan' => '06', 'tahun' => '2026', 'label' => 'Juni 2026', 'tarif' => 250000, 'paid' => 250000],
]);
spp_status_assert($payable['code'] === 'payable' && $payable['lock_spp'] === false, 'Periode valid harus dapat dibayar.');

$arrears = spp_payment_status_from_state($student, '07', '2026', 275000, 0, [
    ['bulan' => '05', 'tahun' => '2026', 'label' => 'Mei 2026', 'tarif' => 250000, 'paid' => 200000],
    ['bulan' => '06', 'tahun' => '2026', 'label' => 'Juni 2026', 'tarif' => 250000, 'paid' => 0],
]);
spp_status_assert($arrears['code'] === 'arrears', 'Tunggakan harus memblokir SPP.');
spp_status_assert($arrears['blocking_period']['label'] === 'Mei 2026', 'Tunggakan paling awal harus ditampilkan.');
spp_status_assert(abs($arrears['blocking_period']['remaining'] - 50000) < 0.001, 'Sisa tunggakan harus tepat.');

$paid = spp_payment_status_from_state($student, '07', '2026', 275000, 275000, []);
spp_status_assert($paid['code'] === 'already_paid', 'SPP lunas tidak boleh dibayar dua kali.');

$legacyPartial = spp_payment_status_from_state($student, '07', '2026', 275000, 50000, []);
spp_status_assert($legacyPartial['code'] === 'partial_history', 'Pembayaran lama sebagian harus diarahkan ke koreksi.');
spp_status_assert(str_contains($legacyPartial['message'], 'Rp 50.000'), 'Pesan pembayaran lama harus menampilkan nominal.');

$missingTariff = spp_payment_status_from_state($student, '07', '2026', 0, 0, []);
spp_status_assert($missingTariff['code'] === 'tariff_missing', 'Tarif kosong harus memblokir SPP.');

$psb = spp_payment_status_from_state(
    ['exists' => true, 'is_active' => 1, 'tingkat' => 0, 'kode_rombel' => 'PSB'],
    '07',
    '2026',
    275000,
    0,
    []
);
spp_status_assert($psb['code'] === 'student_ineligible', 'Siswa PSB belum boleh membayar SPP.');

$inactive = spp_payment_status_from_state(
    ['exists' => true, 'is_active' => 0, 'tingkat' => 2, 'kode_rombel' => 'A'],
    '07',
    '2026',
    275000,
    0,
    []
);
spp_status_assert($inactive['code'] === 'student_ineligible', 'Siswa tidak aktif harus diblokir untuk transaksi baru.');

$allowedInactive = spp_payment_status_from_state(
    ['exists' => true, 'is_active' => 0, 'allow_inactive' => true, 'tingkat' => 2, 'kode_rombel' => 'A'],
    '07',
    '2026',
    275000,
    0,
    []
);
spp_status_assert($allowedInactive['code'] === 'payable', 'Siswa arsip pada transaksi Edit miliknya sendiri harus tetap dapat diperiksa.');

$amount = spp_payment_amount_status('07', '2026', 275000);
spp_status_assert($amount['code'] === 'amount_mismatch', 'Nominal tidak penuh harus memiliki status khusus.');
spp_status_assert(str_contains($amount['message'], 'Rp 275.000'), 'Pesan nominal harus menampilkan tagihan penuh.');

echo "SPP payment status tests passed.\n";

