<?php
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_(?:audit|test)_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    error_log('FAILED: tes mutasi memerlukan CLI, clone db_spp_audit_* atau db_spp_test_*, dan SPP_TEST_ALLOW_MUTATION=1.');
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

function sequence_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$failure = null;
$koneksi->begin_transaction();
try {
    $sourceYear = '2098/2099';
    $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$sourceYear;
    $targetYear = '2099/2100';
    $sourceYearId = class_ensure_academic_year($koneksi, $sourceYear);
    $classes = [];
    foreach ([5, 6] as $level) {
        $stmt = $koneksi->prepare("SELECT id,tingkat,kode_rombel,is_placeholder FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_active=1 LIMIT 1");
        $stmt->bind_param('i', $level);
        $stmt->execute();
        $classes[$level] = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        sequence_assert((bool)$classes[$level], 'Rombel uji tidak tersedia.');
    }
    $students = [];
    foreach ([6, 5, 5] as $index => $level) {
        $nis = (string)random_int(9700000000, 9799999999);
        $students[] = $nis;
        $name = 'UJI URUTAN ' . $index;
        $classText = (string)$level;
        $classId = (int)$classes[$level]['id'];
        $snapshot = class_label($classes[$level]);
        $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,?,?,?,1)');
        $stmt->bind_param('sssi', $nis, $name, $classText, $classId);
        $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'aktif')");
        $stmt->bind_param('issis', $sourceYearId, $nis, $classText, $classId, $snapshot);
        $stmt->execute(); $stmt->close();
    }

    sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === 6, 'Tahap awal harus kelulusan kelas 6.');
    class_manual_graduate_student($koneksi, $students[0], $targetYear);
    sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === 5, 'Tahap tidak turun ke kelas 5 setelah kelulusan.');

    $first = class_process_students_batch($koneksi, [$students[1]], [$students[1] => (int)$classes[6]['id']], $targetYear, 5);
    sequence_assert(count($first['successes']) === 1, 'Kenaikan siswa pertama gagal.');
    $sync = null;
    class_sync_student_current_year($koneksi, $students[1], (int)$classes[6]['id'], 200000, 100000, true, $sync);
    sequence_assert($sync['tahun_ajaran'] === $targetYear, 'Edit Data Siswa menulis ulang tahun ajaran asal.');
    $gradeEditBlocked = false;
    try { class_sync_student_current_year($koneksi, $students[1], (int)$classes[5]['id'], 200000, 100000, true, $sync); }
    catch (RuntimeException $error) { $gradeEditBlocked = true; }
    sequence_assert($gradeEditBlocked, 'Edit Data Siswa dapat mengubah tingkat kelas tanpa proses kenaikan.');
    sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === 5, 'Siswa baru kelas 6 mengulang tahap kelulusan.');
    $remaining = class_students_for_manual_step($koneksi, 5, $targetYear);
    sequence_assert(count($remaining) === 1 && $remaining[0]['NO_INDUK'] === $students[2], 'Daftar siswa tersisa tidak sesuai tahun asal.');

    $reprocessed = false;
    try { class_manual_graduate_student($koneksi, $students[1], $targetYear); }
    catch (RuntimeException $error) { $reprocessed = true; }
    sequence_assert($reprocessed, 'Siswa yang baru naik masih dapat diluluskan.');

    $second = class_process_students_batch($koneksi, [$students[2]], [$students[2] => (int)$classes[6]['id']], $targetYear, 5);
    sequence_assert(count($second['successes']) === 1, 'Kenaikan siswa kedua gagal.');
    sequence_assert(class_highest_active_regular_level($koneksi, $targetYear) === 0, 'Tahap belum selesai setelah seluruh siswa asal diproses.');
    $stale = class_process_students_batch($koneksi, [$students[1]], [$students[1] => (int)$classes[6]['id']], $targetYear, 5);
    sequence_assert(!$stale['successes'] && count($stale['failures'])===1, 'Pengiriman ulang diterima.');

    $stmt = $koneksi->prepare('SELECT ta.label,sta.kelas,sta.status FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=? ORDER BY ta.label');
    $stmt->bind_param('s', $students[1]);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    sequence_assert(count($history) === 2 && $history[0]['kelas'] === '5' && $history[0]['status'] === 'pindah'
        && $history[1]['kelas'] === '6' && $history[1]['status'] === 'aktif', 'Riwayat asal atau tujuan berubah keliru.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $koneksi->rollback();
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: urutan kelulusan dan dua kenaikan terpisah aman.\n";
