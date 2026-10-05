<?php
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_(?:audit|test)_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    error_log('FAILED: tes mutasi memerlukan CLI, clone db_spp_audit_* atau db_spp_test_*, dan SPP_TEST_ALLOW_MUTATION=1.');
    exit(1);
}

session_start();
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

function unit_sequence_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$failure = null;
$koneksi->begin_transaction();
try {
    $sourceYear = '2098/2099';
    $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$sourceYear;
    $targetYear = '2099/2100';
    $cohorts = [];
    foreach ([2 => [9, 8], 3 => [12, 11]] as $unit => [$last, $previous]) {
        $_SESSION['active_unit_id'] = $unit;
        unit_set_context($koneksi, $unit);
        $yearId = class_ensure_academic_year($koneksi, $sourceYear);
        $cohorts[$unit] = [];
        foreach ([$last, $previous, $previous] as $index => $level) {
            $stmt = $koneksi->prepare("SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_active=1 LIMIT 1");
            $stmt->bind_param('i', $level); $stmt->execute();
            $classId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
            unit_sequence_assert($classId > 0, 'Rombel uji unit tidak tersedia.');
            $nis = (string)random_int(9700000000, 9799999999);
            $name = 'UJI UNIT ' . $unit . ' ' . $index;
            $classText = (string)$level;
            $snapshot = $classText . 'A';
            $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,?,?,?,1)');
            $stmt->bind_param('sssi', $nis, $name, $classText, $classId); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'aktif')");
            $stmt->bind_param('issis', $yearId, $nis, $classText, $classId, $snapshot); $stmt->execute(); $stmt->close();
            $cohorts[$unit][] = ['nis' => $nis, 'class_id' => $classId];
        }
        unit_sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === $last, 'Tahap awal unit salah.');
    }

    $_SESSION['active_unit_id'] = 2;
    unit_set_context($koneksi, 2);
    class_manual_graduate_student($koneksi, $cohorts[2][0]['nis'], $targetYear);
    unit_sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === 8, 'Tahap SMP tidak turun setelah kelulusan.');
    $targetClassId = $cohorts[2][0]['class_id'];
    class_manual_promote_student($koneksi, $cohorts[2][1]['nis'], $targetClassId, $targetYear);
    unit_sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === 8, 'Siswa baru kelas 9 mengulang kelulusan SMP.');

    $_SESSION['active_unit_id'] = 3;
    unit_set_context($koneksi, 3);
    unit_sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === 12, 'Kenaikan SMP mengubah tahap SMA.');
    class_manual_graduate_student($koneksi, $cohorts[3][0]['nis'], $targetYear);
    unit_sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === 11, 'Tahap SMA tidak turun setelah kelulusan.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $koneksi->rollback();
    $_SESSION['active_unit_id'] = 1;
    unit_set_context($koneksi, 1);
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: tahap kenaikan SMP dan SMA terpisah per unit.\n";
