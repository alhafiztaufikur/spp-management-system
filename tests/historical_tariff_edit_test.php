<?php

/** Regression for tariff edits after promotion; only run on a disposable audit database. */
session_start();
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1' || !str_starts_with(DB_NAME, 'db_spp_audit_')) {
    fwrite(STDERR, "SKIPPED: requires SPP_TEST_ALLOW_MUTATION=1 and db_spp_audit_* database.\n");
    exit(0);
}

function historical_tariff_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function historical_tariff_summary(mysqli $db, string $table, int $placementId): array {
    if (!in_array($table, ['tagihan_komite', 'tagihan_spp'], true)) throw new InvalidArgumentException('Invalid bill table.');
    $stmt = $db->prepare("SELECT COUNT(*) AS bills, MIN(nominal_tagihan) AS minimum, MAX(nominal_tagihan) AS maximum FROM {$table} WHERE penempatan_id=?");
    $stmt->bind_param('i', $placementId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result;
}

$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);
$koneksi->begin_transaction();
$failure = null;
try {
    $classes = [];
    foreach ([5, 6] as $level) {
        $stmt = $koneksi->prepare("SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1");
        $stmt->bind_param('i', $level); $stmt->execute();
        $classes[$level] = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
        historical_tariff_assert($classes[$level] > 0, 'Rombel uji tidak tersedia.');
    }

    $nis = (string)random_int(9600000000, 9699999999);
    $name = 'UJI TARIF HISTORIS';
    $sourceLevel = '5';
    $sourceClass = $classes[5];
    $currentClass = $classes[6];
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,potongan_spp_nominal,POMG,is_active) VALUES(?,?,?,?,250000,0,15000,1)');
    $stmt->bind_param('sssi', $nis, $name, $sourceLevel, $sourceClass);
    $stmt->execute(); $stmt->close();

    $sourceYear = '2197/2198';
    $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$sourceYear;
    $targetYear = '2198/2199';
    $oldMaster = spp_master_ensure_year($koneksi, $sourceYear, true);
    $sourceYearId = (int)$oldMaster['tahun_ajaran_id'];
    $sourceSnapshot = '5A';
    $sourceStatus = 'aktif';
    $oldSpp = 250000.0;
    $oldKomite = 15000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,?)');
    $stmt->bind_param('issisdds', $sourceYearId, $nis, $sourceLevel, $sourceClass, $sourceSnapshot, $oldSpp, $oldKomite, $sourceStatus);
    $stmt->execute(); $oldId = (int)$koneksi->insert_id; $stmt->close();
    historical_tariff_assert(komite_sync_placement($koneksi, $oldId) === 12, 'Komite tahun asal tidak terbit.');
    spp_master_save_rates($koneksi, (int)$oldMaster['id'], array_fill(1, 6, 250000.0));
    historical_tariff_assert(spp_publish_students($koneksi, (int)$oldMaster['id'], [$nis])['created'] === 12, 'SPP tahun asal tidak terbit.');

    $promotion = class_manual_promote_student($koneksi, $nis, $currentClass, $targetYear);
    historical_tariff_assert($promotion['action'] === 'naik', 'Kenaikan 5A ke 6A gagal.');
    $stmt = $koneksi->prepare('SELECT sta.id,sta.status FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=? AND ta.label=? LIMIT 1');
    $stmt->bind_param('ss', $nis, $targetYear); $stmt->execute();
    $newPlacement = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $newId = (int)($newPlacement['id'] ?? 0);
    historical_tariff_assert($newId > 0 && $newPlacement['status'] === 'aktif', 'Penempatan tahun tujuan tidak dibuat oleh kenaikan.');
    historical_tariff_assert(spp_student_effective_year($koneksi, $nis) === $targetYear, 'Tahun tarif Data Siswa tidak mengikuti kenaikan.');
    historical_tariff_assert(spp_current_effective_rate($koneksi, '6', 0.0, $targetYear)['year'] === 'Belum disiapkan', 'Master tahun asal dipakai sebelum tarif tujuan tersedia.');

    $newMaster = spp_master_ensure_year($koneksi, $targetYear, true);
    spp_master_save_rates($koneksi, (int)$newMaster['id'], array_fill(1, 6, 300000.0));
    historical_tariff_assert(spp_current_effective_rate($koneksi, '6', 0.0, $sourceYear)['net'] === 250000.0
        && spp_current_effective_rate($koneksi, '6', 0.0, $targetYear)['net'] === 300000.0,
        'Tarif efektif tidak mengikuti master tahun yang diminta.');
    $stmt = $koneksi->prepare('UPDATE siswa SET SPP_PERBULAN=300000,POMG=20000 WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
    $sync = null;
    historical_tariff_assert(class_sync_student_current_year($koneksi, $nis, $currentClass, 300000.0, 20000.0, true, $sync) === $newId,
        'Tarif tahun tujuan tidak tersinkron setelah kenaikan.');
    historical_tariff_assert(spp_publish_students($koneksi, (int)$newMaster['id'], [$nis])['created'] === 12, 'SPP tahun tujuan tidak terbit.');

    $stmt = $koneksi->prepare('UPDATE siswa SET POMG=30000,potongan_spp_nominal=30000 WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
    $sync = null;
    $actualPlacement = class_sync_student_current_year($koneksi, $nis, $currentClass, 300000.0, 30000.0, true, $sync);
    historical_tariff_assert($actualPlacement === $newId, 'Edit Data Siswa tidak memilih penempatan tahun terbaru.');
    $discount = spp_sync_student_discount($koneksi, $nis, 30000.0, $actualPlacement);
    historical_tariff_assert($discount['updated'] === 12, 'Potongan SPP tahun yang diedit tidak disinkronkan.');

    foreach ([
        ['placement' => $oldId, 'spp' => 250000.0, 'komite' => 15000.0],
        ['placement' => $newId, 'spp' => 270000.0, 'komite' => 30000.0],
    ] as $expected) {
        foreach (['tagihan_spp' => 'spp', 'tagihan_komite' => 'komite'] as $table => $key) {
            $summary = historical_tariff_summary($koneksi, $table, $expected['placement']);
            historical_tariff_assert((int)$summary['bills'] === 12
                && abs((float)$summary['minimum'] - $expected[$key]) < .001
                && abs((float)$summary['maximum'] - $expected[$key]) < .001,
                "Tarif {$table} pada penempatan {$expected['placement']} berubah tidak sesuai.");
        }
    }
    $stmt = $koneksi->prepare('SELECT komite_snapshot,spp_perbulan_snapshot FROM siswa_tahun_ajaran WHERE id=?');
    $stmt->bind_param('i', $oldId); $stmt->execute(); $oldPlacement = $stmt->get_result()->fetch_assoc(); $stmt->close();
    historical_tariff_assert((float)$oldPlacement['komite_snapshot'] === 15000.0
        && (float)$oldPlacement['spp_perbulan_snapshot'] === 250000.0,
        'Snapshot penempatan tahun asal berubah setelah edit tahun tujuan.');

    try {
        komite_sync_student_rate($koneksi, $nis, 0.0, $oldId);
        throw new RuntimeException('Penempatan historis masih dapat disinkronkan tarif Komite.');
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), 'masih dapat')) throw $error;
    }
    try {
        spp_sync_student_discount($koneksi, $nis, 100.0, $oldId);
        throw new RuntimeException('Penempatan historis masih dapat disinkronkan potongan SPP.');
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), 'masih dapat')) throw $error;
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $koneksi->rollback();
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: edit tarif Komite dan potongan SPP tidak mengubah tagihan/snapshot tahun asal.\n";
