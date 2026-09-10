<?php

require_once __DIR__ . '/../includes/student_tariff_consistency.php';

function tariff_test_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$postMap = [
    'SPP_PERBULAN'=>'spp_perbulan', 'PANGKAL'=>'pangkal', 'BANGUNAN'=>'bangunan',
    'SERAGAM'=>'seragam', 'KEGIATAN'=>'kegiatan', 'MAKAN'=>'makan', 'SORGA'=>'sorga',
    'INFAQ'=>'infaq', 'POMG'=>'pomg', 'DAFTAR_ULANG'=>'daftar_ulang',
    'potong_pangkal'=>'potong_pangkal', 'potong_du'=>'potong_du',
];
$openingMap = [
    'PANGKAL_BAYAR'=>'pangkal_bayar', 'BANGUNAN_BAYAR'=>'bangunan_bayar',
    'SERAGAM_BAYAR'=>'seragam_bayar', 'KEGIATAN_BAYAR'=>'kegiatan_bayar',
];
$student = [
    'SPP_PERBULAN'=>'100000.00', 'PANGKAL'=>'1000000.00', 'BANGUNAN'=>'0.00',
    'SERAGAM'=>'0.00', 'KEGIATAN'=>'0.00', 'MAKAN'=>'0.00', 'SORGA'=>'0.00',
    'INFAQ'=>'0.00', 'POMG'=>'0.00', 'DAFTAR_ULANG'=>'1000000.00',
    'potong_pangkal'=>'0.00', 'potong_du'=>'0.00', 'PANGKAL_BAYAR'=>'100000.00',
    'BANGUNAN_BAYAR'=>'0.00', 'SERAGAM_BAYAR'=>'0.00', 'KEGIATAN_BAYAR'=>'0.00',
    'NO_induk_diknas'=>null,
];
$unchangedPost = [
    'spp_perbulan'=>'100.000', 'pangkal'=>'1.000.000', 'bangunan'=>'0', 'seragam'=>'0',
    'kegiatan'=>'0', 'makan'=>'0', 'sorga'=>'0', 'infaq'=>'0', 'pomg'=>'0',
    'daftar_ulang'=>'1.000.000', 'potong_pangkal'=>'0', 'potong_du'=>'0',
    'pangkal_bayar'=>'100.000', 'bangunan_bayar'=>'0', 'seragam_bayar'=>'0',
    'kegiatan_bayar'=>'0', 'no_induk_diknas'=>'',
];

tariff_test_assert(student_advanced_change_attempts($unchangedPost, $student, $postMap, $openingMap) === [], 'Nilai terformat yang sama dianggap berubah.');
$changedPost = $unchangedPost;
$changedPost['bangunan'] = '250.000';
$changedPost['pangkal_bayar'] = '150.000';
$changedPost['no_induk_diknas'] = '1234567890';
$attempts = student_advanced_change_attempts($changedPost, $student, $postMap, $openingMap);
tariff_test_assert(in_array('bangunan', $attempts, true), 'Perubahan tarif saat Advance nonaktif tidak terdeteksi.');
tariff_test_assert(in_array('pangkal_bayar', $attempts, true), 'Perubahan saldo awal saat Advance nonaktif tidak terdeteksi.');
tariff_test_assert(in_array('no_induk_diknas', $attempts, true), 'Perubahan NIS Diknas saat Advance nonaktif tidak terdeteksi.');

tariff_test_assert(!student_snapshots_differ(['SPP_PERBULAN'=>'100000.00'], ['SPP_PERBULAN'=>100000]), 'Representasi angka yang sama dianggap berubah.');
tariff_test_assert(student_snapshots_differ(['NAMA'=>'Hafizz'], ['NAMA'=>'Hafiz']), 'Perubahan data dasar tidak terdeteksi.');
$components = student_tariff_component_changes(
    ['SPP_PERBULAN'=>100000, 'BANGUNAN'=>0, 'PANGKAL'=>1000000, 'potong_pangkal'=>0, 'tot_pangkal'=>1000000],
    ['SPP_PERBULAN'=>100000, 'BANGUNAN'=>250000, 'PANGKAL'=>1000000, 'potong_pangkal'=>100000, 'tot_pangkal'=>900000]
);
tariff_test_assert($components === ['pangkal', 'bangunan'], 'Pemetaan komponen tarif berubah tidak tepat.');

echo "OK: proteksi Advance, deteksi no-op, dan pemetaan perubahan tarif tervalidasi.\n";
