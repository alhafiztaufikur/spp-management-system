<?php
// Run only on a disposable restore clone. This fixture intentionally remains in the clone.
$databaseName = (string)getenv('SPP_DB_NAME');
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $databaseName)) {
    fwrite(STDERR, "Use a named audit clone and SPP_TEST_ALLOW_MUTATION=1.\n");
    exit(2);
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

function rombel_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
rombel_assert((string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0] === $databaseName,
    'Koneksi tidak menuju clone yang dinyatakan.');

try {
    $nis = 'AUDR000001';
    $sourceYear = '2098/2099';
    $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$sourceYear;
    $targetYear = '2099/2100';
    $classes = [];
    $result = $koneksi->query("SELECT id, tingkat, kode_rombel FROM master_kelas
        WHERE tingkat IN (5,6) AND kode_rombel IN ('A','B') AND is_active=1 AND is_placeholder=0");
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $classes[(int)$row['tingkat'] . $row['kode_rombel']] = (int)$row['id'];
    }
    foreach (['5A','5B','6A','6B'] as $label) {
        rombel_assert(isset($classes[$label]), "Master rombel $label tidak tersedia.");
    }
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM siswa WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute();
    rombel_assert((int)$stmt->get_result()->fetch_row()[0] === 0, 'NIS fixture sudah dipakai.');
    $stmt->close();

    $yearId = class_ensure_academic_year($koneksi, $sourceYear);
    $stmt = $koneksi->prepare("INSERT INTO siswa
        (NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,DAFTAR_ULANG,tot_du,is_active)
        VALUES (?,'UJI PINDAH ROMBEL','5',?,250000,100000,100,100,1)");
    $stmt->bind_param('si', $nis, $classes['5A']); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status)
        VALUES (?,?,'5',?,'5A',250000,100000,'aktif')");
    $stmt->bind_param('isi', $yearId, $nis, $classes['5A']); $stmt->execute();
    $sourcePlacementId = (int)$koneksi->insert_id; $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO tagihan_daftar_ulang
        (tahun_ajaran_id,penempatan_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan,status)
        VALUES (?,?,?,'5','2098/2099',100,100,'open')");
    $stmt->bind_param('iis', $yearId, $sourcePlacementId, $nis); $stmt->execute();
    $billId = (int)$koneksi->insert_id; $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO bayar
        (NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,TGL_BYR,BULAN,TAHUN,th_ajaran,kelas_du,total_jumlah)
        VALUES (?,'5',?,'5A','2099-01-10 10:00:00','01','2099','2098/2099','5',100)");
    $stmt->bind_param('si', $nis, $classes['5A']); $stmt->execute();
    $paymentId = (int)$koneksi->insert_id; $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO bayar_du
        (bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah)
        VALUES (?,?,?,'5','2098/2099',100)");
    $stmt->bind_param('iis', $paymentId, $billId, $nis); $stmt->execute(); $stmt->close();

    // A paid bill must retain the 5A source snapshot while the student's active rombel moves to 5B.
    $stmt = $koneksi->prepare('UPDATE siswa SET master_kelas_id=? WHERE NO_INDUK=?');
    $stmt->bind_param('is', $classes['5B'], $nis); $stmt->execute(); $stmt->close();
    $sync = null;
    class_sync_student_current_year($koneksi, $nis, $classes['5B'], 250000, 100000, true, $sync);
    rombel_assert(($sync['kelas_dipertahankan'] ?? false) === true, 'Kelas tagihan sumber tidak dipertahankan.');
    $students = class_students_for_manual_step($koneksi, 5, $targetYear);
    $candidate = null;
    foreach ($students as $student) if ($student['NO_INDUK'] === $nis) $candidate = $student;
    rombel_assert($candidate !== null && $candidate['kelas_label'] === '5A', 'Kelas historis siswa hilang.');
    $promotionCode = (string)($candidate['promotion_kode_rombel'] ?? $candidate['kode_rombel']);
    rombel_assert($promotionCode === 'B',
        "Tujuan otomatis harus mengikuti rombel aktif 5B, bukan snapshot 5A; kode terpilih: $promotionCode.");

    $result = class_process_year_promotion($koneksi, $targetYear);
    rombel_assert((int)$result['promoted'] === 1 && (int)$result['graduated'] === 0,
        'Proses massal tidak menaikkan tepat satu siswa.');
    $stmt = $koneksi->prepare("SELECT sta.kelas_rombel_snapshot,sta.status,ta.label
        FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id
        WHERE sta.no_induk=? ORDER BY ta.label");
    $stmt->bind_param('s', $nis); $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    rombel_assert(count($history) === 2
        && $history[0]['kelas_rombel_snapshot'] === '5A' && $history[0]['status'] === 'pindah'
        && $history[1]['kelas_rombel_snapshot'] === '6B' && $history[1]['status'] === 'aktif',
        'Penempatan hasil kenaikan tidak mempertahankan 5A atau tidak menuju 6B.');
    $stmt = $koneksi->prepare('SELECT master_kelas_id FROM siswa WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute();
    rombel_assert((int)$stmt->get_result()->fetch_row()[0] === $classes['6B'], 'Rombel aktif hasil kenaikan bukan 6B.');
    $stmt->close();
    echo "PASS: pembayaran tetap pada 5A; pindah aktif ke 5B; kenaikan massal menuju 6B.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED at ' . basename($error->getFile()) . ':' . $error->getLine()
        . ': ' . $error->getMessage() . "\n");
    exit(1);
}
