<?php

require_once __DIR__ . '/../includes/student_tariff_consistency.php';

function tariff_test_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$postMap = [
    'SPP_PERBULAN'=>'spp_perbulan', 'PANGKAL'=>'pangkal', 'PSB'=>'psb',
    'POMG'=>'pomg', 'DAFTAR_ULANG'=>'daftar_ulang',
    'potong_pangkal'=>'potong_pangkal', 'potong_du'=>'potong_du',
];
$student = [
    'SPP_PERBULAN'=>'100000.00', 'PANGKAL'=>'1000000.00', 'PSB'=>'3500000.00',
    'POMG'=>'0.00', 'DAFTAR_ULANG'=>'1000000.00',
    'potong_pangkal'=>'0.00', 'potong_du'=>'0.00', 'NO_induk_diknas'=>null,
];
$unchangedPost = [
    'spp_perbulan'=>'100.000', 'pangkal'=>'1.000.000', 'psb'=>'3.500.000',
    'pomg'=>'0', 'daftar_ulang'=>'1.000.000', 'potong_pangkal'=>'0',
    'potong_du'=>'0', 'no_induk_diknas'=>'',
];

tariff_test_assert(student_advanced_change_attempts($unchangedPost, $student, $postMap) === [], 'Nilai terformat yang sama dianggap berubah.');
$changedPost = $unchangedPost;
$changedPost['psb'] = '3.750.000';
$changedPost['pangkal'] = '1.100.000';
$changedPost['no_induk_diknas'] = '1234567890';
$attempts = student_advanced_change_attempts($changedPost, $student, $postMap);
tariff_test_assert(in_array('psb', $attempts, true), 'Perubahan PSB saat Advance nonaktif tidak terdeteksi.');
tariff_test_assert(in_array('pangkal', $attempts, true), 'Perubahan Pangkal saat Advance nonaktif tidak terdeteksi.');
tariff_test_assert(in_array('no_induk_diknas', $attempts, true), 'Perubahan NIS Diknas saat Advance nonaktif tidak terdeteksi.');

tariff_test_assert(!student_snapshots_differ(['SPP_PERBULAN'=>'100000.00'], ['SPP_PERBULAN'=>100000]), 'Representasi angka yang sama dianggap berubah.');
tariff_test_assert(student_snapshots_differ(['NAMA'=>'Hafizz'], ['NAMA'=>'Hafiz']), 'Perubahan data dasar tidak terdeteksi.');
$components = student_tariff_component_changes(
    ['SPP_PERBULAN'=>100000, 'PSB'=>3500000, 'PANGKAL'=>1000000, 'potong_pangkal'=>0, 'tot_pangkal'=>1000000],
    ['SPP_PERBULAN'=>100000, 'PSB'=>3750000, 'PANGKAL'=>1000000, 'potong_pangkal'=>100000, 'tot_pangkal'=>900000]
);
tariff_test_assert($components === ['pangkal', 'psb'], 'Pemetaan komponen tarif berubah tidak tepat.');

echo "OK: proteksi Advance, deteksi no-op, dan pemetaan Pangkal/PSB tervalidasi.\n";
