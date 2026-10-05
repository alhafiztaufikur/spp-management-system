<?php
/** Reproduce a stale target-class read across two database connections. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', DB_NAME)) {
    throw new RuntimeException('Tes konkurensi hanya untuk clone audit dengan flag mutasi.');
}

$expectUnsafe = in_array('--expect-unsafe', $argv, true);
$bulkDeactivate = in_array('--bulk', $argv, true);
$sourceYear = '2190/2191';
    $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$sourceYear;
$targetYear = '2191/2192';
$nis = (string)random_int(9800000000, 9899999999);
$connectionB = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $dbPort);
$connectionB->set_charset('utf8mb4');
unit_set_context($koneksi, 1);
unit_set_context($connectionB, 1);

$sourceClass = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=5 AND kode_rombel='A' AND is_active=1 LIMIT 1")->fetch_assoc();
$targetClass = $koneksi->query("SELECT mk.id FROM master_kelas mk
    WHERE mk.tingkat=6 AND mk.is_placeholder=0 AND mk.is_active=1
      AND NOT EXISTS(SELECT 1 FROM siswa s WHERE s.master_kelas_id=mk.id AND s.is_active=1)
    ORDER BY mk.id DESC LIMIT 1")->fetch_assoc();
if (!$sourceClass || !$targetClass) throw new RuntimeException('Rombel sumber atau target kosong untuk tes tidak tersedia.');
$sourceClassId = (int)$sourceClass['id'];
$targetClassId = (int)$targetClass['id'];
$sourceYearId = 0;
$fixtureCreated = false;
$targetDisabled = false;
$originalActiveClassIds = [];
$transactionA = false;
$transactionB = false;
$failure = null;

try {
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label IN (?,?)');
    $stmt->bind_param('ss', $sourceYear, $targetYear); $stmt->execute();
    if ((int)$stmt->get_result()->fetch_row()[0] !== 0) throw new RuntimeException('Tahun fixture sudah ada.');
    $stmt->close();

    $koneksi->begin_transaction();
    $transactionA = true;
    $sourceYearId = class_ensure_academic_year($koneksi, $sourceYear);
    $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active)
        VALUES(?,'UJI BALAP ROMBEL','5',?,1)");
    $stmt->bind_param('si', $nis, $sourceClassId); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status)
        VALUES(?,?,'5',?,'5A','aktif')");
    $stmt->bind_param('isi', $sourceYearId, $nis, $sourceClassId); $stmt->execute(); $stmt->close();
    $koneksi->commit();
    $transactionA = false;
    $fixtureCreated = true;

    // A begins a repeatable-read snapshot while the target class is still active.
    $koneksi->begin_transaction();
    $transactionA = true;
    if (!class_find($koneksi, $targetClassId, true)) throw new RuntimeException('Rombel target tidak aktif pada awal tes.');

    // B deactivates an empty target after A has read it without a row lock.
    if ($bulkDeactivate) {
        $originalActiveClassIds = array_map('intval', array_column(
            $connectionB->query('SELECT id FROM master_kelas WHERE is_placeholder=0 AND is_active=1')->fetch_all(MYSQLI_ASSOC), 'id'));
        class_disable_empty_rombel($connectionB);
        $stmt = $connectionB->prepare('SELECT is_active FROM master_kelas WHERE id=?');
        $stmt->bind_param('i', $targetClassId); $stmt->execute();
        if ((int)$stmt->get_result()->fetch_row()[0] !== 0) throw new RuntimeException('Rombel target tidak dinonaktifkan oleh proses massal.');
        $stmt->close();
    } else {
        $connectionB->begin_transaction();
        $transactionB = true;
        $stmt = $connectionB->prepare('SELECT COUNT(*) FROM siswa WHERE master_kelas_id=? AND is_active=1');
        $stmt->bind_param('i', $targetClassId); $stmt->execute();
        if ((int)$stmt->get_result()->fetch_row()[0] !== 0) throw new RuntimeException('Rombel target tidak kosong.');
        $stmt->close();
        $stmt = $connectionB->prepare('UPDATE master_kelas SET is_active=0 WHERE id=? AND is_active=1');
        $stmt->bind_param('i', $targetClassId); $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Penonaktifan rombel target gagal.');
        $stmt->close();
        $connectionB->commit();
        $transactionB = false;
    }
    $targetDisabled = true;

    $promotionError = null;
    try {
        class_manual_promote_student($koneksi, $nis, $targetClassId, $targetYear);
    } catch (Throwable $error) {
        $promotionError = $error;
    }
    $stmt = $koneksi->prepare('SELECT master_kelas_id,KELAS FROM siswa WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($expectUnsafe) {
        if ($promotionError || (int)$student['master_kelas_id'] !== $targetClassId || $student['KELAS'] !== '6') {
            throw new RuntimeException('Reproduksi sebelum perbaikan tidak menghasilkan penempatan ke rombel nonaktif.');
        }
    } elseif (!$promotionError
        || !str_contains($promotionError->getMessage(), 'Pilih rombel target yang aktif')
        || (int)$student['master_kelas_id'] !== $sourceClassId || $student['KELAS'] !== '5') {
        throw new RuntimeException('Kenaikan harus menolak rombel yang dinonaktifkan setelah snapshot awal. '
            . ($promotionError?->getMessage() ?? 'Kenaikan justru berhasil.'));
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        if ($transactionA) $koneksi->rollback();
        if ($transactionB) $connectionB->rollback();
        if ($bulkDeactivate && $originalActiveClassIds) {
            $stmt = $connectionB->prepare('UPDATE master_kelas SET is_active=1 WHERE id=? AND is_active=0');
            foreach ($originalActiveClassIds as $classId) {
                $stmt->bind_param('i', $classId); $stmt->execute();
            }
            $stmt->close();
        } elseif ($targetDisabled) {
            $stmt = $connectionB->prepare('UPDATE master_kelas SET is_active=1 WHERE id=? AND is_active=0');
            $stmt->bind_param('i', $targetClassId); $stmt->execute(); $stmt->close();
        }
        if ($fixtureCreated) {
            $stmt = $connectionB->prepare('DELETE FROM siswa_tahun_ajaran WHERE no_induk=?');
            $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
            $stmt = $connectionB->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
            $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
            $stmt = $connectionB->prepare('DELETE FROM tahun_ajaran WHERE id=?');
            $stmt->bind_param('i', $sourceYearId); $stmt->execute(); $stmt->close();
        }
    } catch (Throwable $cleanupError) {
        $failure = new RuntimeException('Pembersihan fixture gagal: ' . $cleanupError->getMessage(), 0, $failure);
    }
    $connectionB->close();
}

if ($failure) { fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL); exit(1); }
echo $expectUnsafe
    ? "REPRODUCED: snapshot kelas aktif membolehkan kenaikan ke rombel yang kini nonaktif.\n"
    : ($bulkDeactivate
        ? "PASS: kenaikan menolak rombel yang dinonaktifkan proses massal setelah snapshot awal.\n"
        : "PASS: kenaikan menolak rombel yang nonaktif setelah snapshot awal.\n");
