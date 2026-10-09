<?php

require_once __DIR__ . '/../includes/student_tariff_consistency.php';

function tariff_test_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

tariff_test_assert(student_amount('30.000')===30000.0, 'Nominal terformat tidak dibaca.');
tariff_test_assert(!student_snapshots_differ(['SPP_PERBULAN'=>'100000.00'], ['SPP_PERBULAN'=>100000]), 'Representasi angka yang sama dianggap berubah.');
tariff_test_assert(student_snapshots_differ(['NAMA'=>'Hafizz'], ['NAMA'=>'Hafiz']), 'Perubahan data dasar tidak terdeteksi.');
$components = student_tariff_component_changes(
    ['SPP_PERBULAN'=>100000, 'PSB'=>3500000, 'DAFTAR_ULANG'=>1000000, 'potong_du'=>0, 'tot_du'=>1000000],
    ['SPP_PERBULAN'=>100000, 'PSB'=>3750000, 'DAFTAR_ULANG'=>1000000, 'potong_du'=>100000, 'tot_du'=>900000]
);
tariff_test_assert($components === ['psb', 'daftar_ulang'], 'Pemetaan komponen tarif berubah tidak tepat.');

echo "OK: nominal tanpa Advanced, deteksi no-op, dan pemetaan PSB/Daftar Ulang tervalidasi.\n";
