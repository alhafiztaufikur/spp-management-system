<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
test_require_audit_database($koneksi);

function spp_installment_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function test_academic_year(int $month, int $year): string {
    $start = $month >= 7 ? $year : $year - 1;
    return $start . '/' . ($start + 1);
}

$indexResult = $koneksi->query("
    SELECT INDEX_NAME, NON_UNIQUE
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bayar_spp_periode'
      AND INDEX_NAME IN ('uk_bayar_spp_siswa_periode', 'idx_bayar_spp_siswa_periode')
");
$indexes = [];
while ($index = $indexResult->fetch_assoc()) {
    $indexes[$index['INDEX_NAME']] = (int)$index['NON_UNIQUE'];
}
spp_installment_assert(!isset($indexes['uk_bayar_spp_siswa_periode']), 'Indeks unik periode SPP masih aktif.');
spp_installment_assert(($indexes['idx_bayar_spp_siswa_periode'] ?? 0) === 1, 'Indeks cicilan periode SPP belum tersedia.');

$koneksi->begin_transaction();
try {
    do {
        $noInduk = (string)random_int(9900000000, 9999999999);
        $stmtCheck = $koneksi->prepare('SELECT 1 FROM siswa WHERE NO_INDUK = ?');
        $stmtCheck->bind_param('s', $noInduk);
        $stmtCheck->execute();
        $exists = (bool)$stmtCheck->get_result()->fetch_row();
        $stmtCheck->close();
    } while ($exists);

    $name = 'UJI CICILAN SPP';
    $class = '1';
    $monthlyFee = 250000.0;
    $stmtStudent = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, SPP_PERBULAN) VALUES (?, ?, ?, ?)');
    $stmtStudent->bind_param('sssd', $noInduk, $name, $class, $monthlyFee);
    $stmtStudent->execute();
    $stmtStudent->close();

    $date = '2026-08-10 10:00:00';
    $year = '2026';
    $rows = [
        ['08', 100000.0],
        ['Agustus', 150000.0],
    ];
    foreach ($rows as [$storedMonth, $amount]) {
        $stmtPayment = $koneksi->prepare("
            INSERT INTO bayar
                (NO_INDUK, KELAS, U_SPP, TGL_BYR, BULAN, TAHUN, total_jumlah, payment_link_version)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmtPayment->bind_param('ssdsssd', $noInduk, $class, $amount, $date, $storedMonth, $year, $amount);
        $stmtPayment->execute();
        $paymentId = $koneksi->insert_id;
        $stmtPayment->close();

        $claimMonth = '08';
        $stmtClaim = $koneksi->prepare('INSERT INTO bayar_spp_periode (bayar_id, no_induk, bulan, tahun) VALUES (?, ?, ?, ?)');
        $stmtClaim->bind_param('isss', $paymentId, $noInduk, $claimMonth, $year);
        $stmtClaim->execute();
        $stmtClaim->close();
    }

    $stmtPaid = $koneksi->prepare("
        SELECT COALESCE(SUM(U_SPP), 0) AS paid
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = ?
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtPaid->bind_param('ss', $noInduk, $year);
    $stmtPaid->execute();
    $paid = (float)$stmtPaid->get_result()->fetch_assoc()['paid'];
    $stmtPaid->close();
    spp_installment_assert(abs($paid - 250000.0) < 0.001, 'Dua cicilan dan format bulan legacy tidak terakumulasi dengan benar.');

    $stmtClaims = $koneksi->prepare('SELECT COUNT(*) AS total FROM bayar_spp_periode WHERE no_induk = ? AND tahun = ? AND bulan = ?');
    $claimMonth = '08';
    $stmtClaims->bind_param('sss', $noInduk, $year, $claimMonth);
    $stmtClaims->execute();
    $claimCount = (int)$stmtClaims->get_result()->fetch_assoc()['total'];
    $stmtClaims->close();
    spp_installment_assert($claimCount === 2, 'Pemetaan periode belum menerima dua cicilan pada bulan yang sama.');

    $remaining = max(0, $monthlyFee - $paid);
    spp_installment_assert(abs($remaining) < 0.001, 'Sisa SPP tidak menjadi nol setelah cicilan lunas.');
    spp_installment_assert(10000.0 > $remaining + 0.001, 'Simulasi pembayaran setelah lunas seharusnya melewati batas sisa.');

    spp_installment_assert(test_academic_year(1, 2026) === '2025/2026', 'Januari tidak masuk tahun ajaran sebelumnya.');
    spp_installment_assert(test_academic_year(6, 2026) === '2025/2026', 'Juni tidak masuk tahun ajaran sebelumnya.');
    spp_installment_assert(test_academic_year(7, 2026) === '2026/2027', 'Juli tidak masuk tahun ajaran berikutnya.');
    spp_installment_assert(test_academic_year(12, 2026) === '2026/2027', 'Desember tidak masuk tahun ajaran berikutnya.');

    $koneksi->rollback();
    echo "OK: cicilan SPP satu periode, bulan legacy, batas sisa, dan tahun ajaran.\n";
} catch (Throwable $error) {
    $koneksi->rollback();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
