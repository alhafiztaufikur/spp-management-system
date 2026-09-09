<?php

require_once __DIR__ . '/../includes/spp_sequence.php';

function spp_sequence_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

try {
    $placements = [
        ['tahun_ajaran' => '2025/2026', 'spp_perbulan_snapshot' => 250000, 'status' => 'aktif'],
        ['tahun_ajaran' => '2026/2027', 'spp_perbulan_snapshot' => 275000, 'status' => 'aktif'],
        ['tahun_ajaran' => '2027/2028', 'spp_perbulan_snapshot' => 300000, 'status' => 'pindah'],
    ];

    $july2026Prior = spp_sequence_prior_periods($placements, '07', '2026');
    spp_sequence_assert(count($july2026Prior) === 12, 'Juli 2026 harus memeriksa seluruh tahun ajaran 2025/2026.');
    spp_sequence_assert($july2026Prior[0]['key'] === '07-2025', 'Tunggakan lintas tahun harus dimulai dari Juli 2025.');
    spp_sequence_assert($july2026Prior[11]['key'] === '06-2026', 'Tunggakan lintas tahun harus berakhir di Juni 2026.');
    spp_sequence_assert(abs($july2026Prior[11]['tarif'] - 250000) < 0.001, 'Tarif periode lama harus memakai snapshot tahun ajaran lama.');

    $august2026Prior = spp_sequence_prior_periods($placements, '08', '2026');
    spp_sequence_assert(count($august2026Prior) === 13, 'Agustus 2026 harus menambahkan Juli 2026 setelah seluruh tahun sebelumnya.');
    spp_sequence_assert($august2026Prior[12]['key'] === '07-2026', 'Urutan tahun ajaran baru harus dimulai dari Juli.');
    spp_sequence_assert(abs($august2026Prior[12]['tarif'] - 275000) < 0.001, 'Tarif Juli 2026 harus memakai snapshot 2026/2027.');

    $following = spp_sequence_following_periods($placements, '06', '2026');
    spp_sequence_assert($following[0]['key'] === '07-2026', 'Pembayaran setelah Juni harus menemukan Juli tahun ajaran berikutnya.');
    spp_sequence_assert(!in_array('07-2027', array_column($following, 'key'), true), 'Penempatan pindah tidak boleh menjadi kewajiban lintas tahun.');

    $newStudentPrior = spp_sequence_prior_periods([
        ['tahun_ajaran' => '2026/2027', 'spp_perbulan_snapshot' => 275000, 'status' => 'aktif'],
    ], '07', '2026');
    spp_sequence_assert($newStudentPrior === [], 'Siswa baru tidak boleh memiliki tunggakan sebelum penempatan aktif pertamanya.');

    spp_sequence_assert(
        abs(spp_sequence_tariff_for_period($placements, '07', '2026', 999999) - 275000) < 0.001,
        'Tarif periode aktif harus mengutamakan snapshot tahun ajaran.'
    );
    spp_sequence_assert(
        abs(spp_sequence_tariff_for_period($placements, '07', '2027', 125000) - 125000) < 0.001,
        'Periode tanpa penempatan aktif harus memakai fallback tanpa menciptakan kewajiban historis.'
    );

    echo "OK: urutan SPP penuh lintas tahun ajaran, snapshot tarif, dan status penempatan.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
