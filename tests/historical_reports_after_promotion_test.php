<?php

/** Run only against a disposable database. Rolls back unless fixture persistence is requested for HTTP export checks. */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/spp_payment_status.php';

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1' || !str_starts_with(DB_NAME, 'db_spp_audit_')) {
    fwrite(STDERR, "SKIPPED: use SPP_TEST_ALLOW_MUTATION=1 and a db_spp_audit_* database.\n");
    exit(0);
}

function historical_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function historical_rows(mysqli $db, string $template, array $values): array {
    $filters = report_filters($db, $values);
    return report_build($db, $template, $filters)['rows'];
}

function historical_class(mysqli $db, int $level): int {
    $stmt = $db->prepare("SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1");
    $stmt->bind_param('i', $level);
    $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    historical_assert($id > 0, 'Rombel A kelas ' . $level . ' tidak tersedia.');
    return $id;
}

$source = '2090/2091';
$_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$source;
$target = '2091/2092';
$third = '2092/2093';
$fixtures = [];
$failure = null;
$persist = getenv('SPP_TEST_PERSIST_FIXTURE') === '1';
$koneksi->begin_transaction();
try {
    foreach ([1 => 5, 2 => 8, 3 => 11] as $unit => $level) {
        $_SESSION['active_unit_id'] = $unit;
        unit_set_context($koneksi, $unit);
        $nis = (string)random_int(9800000000, 9899999999);
        $fromClass = historical_class($koneksi, $level);
        $toClass = historical_class($koneksi, $level + 1);
        $fromLabel = $level . 'A';
        $toLabel = ($level + 1) . 'A';
        $name = 'UJI HISTORIS ' . unit_label($unit);
        $levelText = (string)$level;
        $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,300000,20000,1)');
        $stmt->bind_param('sssi', $nis, $name, $levelText, $fromClass);
        $stmt->execute(); $stmt->close();
        $sourceId = class_ensure_academic_year($koneksi, $source);
        $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,300000,20000,'aktif')");
        $stmt->bind_param('issis', $sourceId, $nis, $levelText, $fromClass, $fromLabel);
        $stmt->execute(); $placementId = (int)$koneksi->insert_id; $stmt->close();
        komite_sync_placement($koneksi, $placementId);
        $stmt = $koneksi->prepare('INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,400000,400000)');
        $stmt->bind_param('iisss', $sourceId, $placementId, $nis, $levelText, $source);
        $stmt->execute(); $stmt->close();

        $master = spp_master_ensure_year($koneksi, $source, true);
        spp_master_save_rates($koneksi, (int)$master['id'], array_fill(1, 12, 300000));
        historical_assert(spp_publish_students($koneksi, (int)$master['id'], [$nis])['created'] === 12, 'Tagihan SPP tahun asal tidak lengkap.');
        $before = spp_period_placements($koneksi, $nis, false);
        historical_assert(count($before) === 1 && count(spp_sequence_periods_for_placements($before)) === 12, 'Jalur kompatibilitas sebelum kenaikan kehilangan periode.');

        class_manual_promote_student($koneksi, $nis, $toClass, $target);
        $after = spp_period_placements($koneksi, $nis, false);
        historical_assert(count($after) === 2 && count(spp_sequence_periods_for_placements($after)) === 24, 'Jalur kompatibilitas setelah kenaikan kehilangan periode lama.');
        historical_assert(spp_sequence_tariff_for_period($after, '07', '2090', 0) === 300000.0, 'Tarif snapshot tahun asal berubah.');
        historical_assert(count(spp_sequence_prior_periods($after, '07', '2091')) === 12, 'Tunggakan tahun asal tidak mendahului tahun tujuan.');
        historical_assert(count(spp_sequence_prior_periods($after, '07', '2089')) === 0, 'Tahun sebelum penempatan pertama direkonstruksi.');

        $targetMaster = spp_master_ensure_year($koneksi, $target, true);
        spp_master_save_rates($koneksi, (int)$targetMaster['id'], array_fill(1, 12, 320000));
        historical_assert(spp_publish_students($koneksi, (int)$targetMaster['id'], [$nis])['created'] === 12, 'Tagihan SPP tahun tujuan tidak lengkap.');
        $base = ['q' => $nis, 'siswa_status' => 'active', 'kelas' => 'rombel:' . $fromClass, 'tahun_ajaran' => $source, 'bulan_awal' => '07'];
        foreach (['spp' => 300000, 'komite' => 20000, 'daftar_ulang' => 400000] as $category => $amount) {
            $rows = historical_rows($koneksi, 'status', $base + ['kategori' => $category]);
            historical_assert(count($rows) === 1 && $rows[0]['kelas'] === $fromLabel && (float)$rows[0]['tagihan'] === (float)$amount, 'Status Pembayaran ' . $category . ' tahun asal salah.');
            historical_assert(historical_rows($koneksi, 'status', array_replace($base, ['kategori' => $category, 'siswa_status' => 'archived'])) === [], 'Siswa aktif masuk Arsip/Lulus.');
            historical_assert(count(historical_rows($koneksi, 'status', array_replace($base, ['kategori' => $category, 'siswa_status' => 'all']))) === 1, 'Filter Semua kehilangan siswa.');
        }
        $yearRows = historical_rows($koneksi, 'spp-tahunan', $base);
        historical_assert(count($yearRows) === 1 && $yearRows[0]['kelas'] === $fromLabel && (float)$yearRows[0]['total_tagihan'] === 3600000.0, 'SPP Tahun Ajaran asal salah.');
        historical_assert(historical_rows($koneksi, 'spp-tahunan', array_replace($base, ['siswa_status' => 'archived'])) === [], 'SPP tahunan mengarsipkan siswa aktif.');

        foreach (['spp' => [300000, 320000], 'komite' => [20000, 20000]] as $category => [$oldAmount, $newAmount]) {
            $range = ['q' => $nis, 'kategori' => $category, 'bulan_awal' => '06', 'tahun_awal' => 2091, 'bulan_akhir' => '07', 'tahun_akhir' => 2091, 'siswa_status' => 'active'];
            $rows = historical_rows($koneksi, 'per-item', $range);
            historical_assert(count($rows) === 2, 'Per Item ' . $category . ' tidak memisahkan tahun ajaran.');
            $byYear = array_column($rows, null, 'tahun_ajaran');
            historical_assert($byYear[$source]['kelas'] === $fromLabel && $byYear[$target]['kelas'] === $toLabel, 'Kelas historis Per Item ' . $category . ' salah.');
            historical_assert((float)$byYear[$source]['total_tagihan'] === (float)$oldAmount && (float)$byYear[$target]['total_tagihan'] === (float)$newAmount, 'Total Per Item ' . $category . ' melintasi tahun.');
            historical_assert($byYear[$source]['m2091_07']['text'] === '—' && $byYear[$target]['m2091_06']['text'] === '—', 'Bulan di luar tahun ajaran tidak diberi tanda.');
            historical_assert(count(historical_rows($koneksi, 'per-item', $range + ['kelas' => 'rombel:' . $fromClass])) === 1, 'Filter rombel asal Per Item salah.');
            historical_assert(count(historical_rows($koneksi, 'per-item', $range + ['kelas' => 'rombel:' . $toClass])) === 1, 'Filter rombel tujuan Per Item salah.');
            historical_assert(historical_rows($koneksi, 'per-item', array_replace($range, ['siswa_status' => 'archived'])) === [], 'Per Item mengarsipkan siswa aktif.');
        }
        $fixtures[$unit] = ['nis' => $nis, 'from' => $fromLabel, 'to' => $toLabel];
    }

    foreach ($fixtures as $unit => $fixture) {
        $_SESSION['active_unit_id'] = $unit;
        unit_set_context($koneksi, $unit);
        foreach ($fixtures as $otherUnit => $other) {
            if ($otherUnit === $unit) continue;
            historical_assert(historical_rows($koneksi, 'per-item', ['q' => $other['nis'], 'kategori' => 'spp', 'bulan_awal' => '07', 'tahun_awal' => 2090, 'bulan_akhir' => '07', 'tahun_akhir' => 2090]) === [], 'Laporan melintasi batas unit.');
        }
        $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$target;
        class_manual_graduate_student($koneksi, $fixture['nis'], $third);
        $base = ['q' => $fixture['nis'], 'tahun_ajaran' => $source, 'kategori' => 'spp', 'bulan_awal' => '07'];
        historical_assert(historical_rows($koneksi, 'status', $base) === [], 'Lulusan masih masuk filter Aktif.');
        historical_assert(count(historical_rows($koneksi, 'status', $base + ['siswa_status' => 'archived'])) === 1, 'Lulusan hilang dari Arsip/Lulus tahun asal.');
        historical_assert(count(historical_rows($koneksi, 'status', array_replace($base, ['tahun_ajaran' => $target, 'siswa_status' => 'all']))) === 1, 'Rekap tahun tujuan hilang setelah lulus.');
        foreach (['komite', 'daftar_ulang'] as $category) {
            historical_assert(historical_rows($koneksi, 'status', array_replace($base, ['kategori' => $category])) === [], 'Lulusan masih masuk Aktif ' . $category . '.');
            historical_assert(count(historical_rows($koneksi, 'status', array_replace($base, ['kategori' => $category, 'siswa_status' => 'archived']))) === 1, 'Lulusan hilang dari Arsip/Lulus ' . $category . '.');
        }
        historical_assert(historical_rows($koneksi, 'spp-tahunan', $base) === [], 'Lulusan masih masuk SPP Tahunan Aktif.');
        historical_assert(count(historical_rows($koneksi, 'spp-tahunan', $base + ['siswa_status' => 'archived'])) === 1, 'Lulusan hilang dari SPP Tahunan Arsip/Lulus.');
        $range = ['q' => $fixture['nis'], 'kategori' => 'spp', 'bulan_awal' => '06', 'tahun_awal' => 2091, 'bulan_akhir' => '07', 'tahun_akhir' => 2091];
        historical_assert(historical_rows($koneksi, 'per-item', $range) === [], 'Lulusan masih masuk Per Item Aktif.');
        historical_assert(count(historical_rows($koneksi, 'per-item', $range + ['siswa_status' => 'archived'])) === 2, 'Lulusan hilang dari Per Item Arsip/Lulus.');
    }
    if ($persist) $koneksi->commit();
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (!$persist || $failure) $koneksi->rollback();
}
if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo 'OK: rekap tahun asal/tujuan, Per Item lintas tahun, kompatibilitas SPP, kelulusan, dan isolasi SD/SMP/SMA.' . PHP_EOL;
if ($persist) echo json_encode($fixtures, JSON_UNESCAPED_SLASHES) . PHP_EOL;
