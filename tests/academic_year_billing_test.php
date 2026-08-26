<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
require_once __DIR__ . '/../includes/daftar_ulang.php';
test_require_audit_database($koneksi);

function test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$mappingCases = [
    [1, 2026, '2025/2026'], [6, 2026, '2025/2026'],
    [7, 2026, '2026/2027'], [8, 2026, '2026/2027'], [9, 2026, '2026/2027'],
    [1, 2027, '2026/2027'],
];
foreach ($mappingCases as [$month, $year, $expected]) {
    test_assert(du_academic_year_label($month, $year) === $expected, "Pemetaan $month/$year salah.");
}

$koneksi->begin_transaction();
try {
    $student = $koneksi->query("SELECT NO_INDUK,KELAS FROM siswa WHERE is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    test_assert((bool)$student, 'Minimal satu siswa aktif dibutuhkan untuk regression test.');

    $publishLabel = '2097/2098'; $publishStart = '2097-07-01'; $publishEnd = '2098-06-30';
    $stmt = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status) VALUES (?,?,?,'draft')");
    $stmt->bind_param('sss', $publishLabel, $publishStart, $publishEnd); $stmt->execute();
    $publishYearId = (int)$koneksi->insert_id; $stmt->close();
    for ($testClass = 1; $testClass <= 6; $testClass++) {
        $testClassText = (string)$testClass; $testAmount = 500000.0;
        $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES (?,?,?,?)');
        $stmt->bind_param('issd', $publishYearId, $publishLabel, $testClassText, $testAmount); $stmt->execute(); $stmt->close();
    }
    $activeStudentCount = (int)$koneksi->query("SELECT COUNT(*) total FROM siswa WHERE is_active=1 AND KELAS IN ('1','2','3','4','5','6')")->fetch_assoc()['total'];
    test_assert(du_publish_year_from_active_students($koneksi, $publishYearId, $publishLabel) === $activeStudentCount, 'Penerbitan atomik tidak membuat seluruh tagihan siswa aktif.');
    test_assert(du_publish_year_from_active_students($koneksi, $publishYearId, $publishLabel) === $activeStudentCount, 'Penerbitan ulang tidak idempoten.');
    $stmt = $koneksi->prepare('SELECT status FROM tahun_ajaran WHERE id=?'); $stmt->bind_param('i', $publishYearId); $stmt->execute();
    test_assert($stmt->get_result()->fetch_assoc()['status'] === 'published', 'Tahun ajaran tidak berubah menjadi published.'); $stmt->close();

    $label = '2098/2099'; $start = '2098-07-01'; $end = '2099-06-30';
    $stmt = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status,published_at) VALUES (?,?,?,'published',NOW())");
    $stmt->bind_param('sss', $label, $start, $end); $stmt->execute();
    $yearId = (int)$koneksi->insert_id; $stmt->close();

    $number = (string)$student['NO_INDUK']; $class = (string)$student['KELAS']; $amount = 600000.0;
    $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES (?,?,?,?)');
    $stmt->bind_param('issd', $yearId, $label, $class, $amount); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,status) VALUES (?,?,?,'aktif')");
    $stmt->bind_param('iss', $yearId, $number, $class); $stmt->execute();
    $placementId = (int)$koneksi->insert_id; $stmt->close();

    $billId = du_create_bill_for_placement($koneksi, $placementId);
    test_assert((int)$billId > 0, 'Tagihan tidak terbentuk dari penempatan terbit.');
    test_assert(du_create_bill_for_placement($koneksi, $placementId) === $billId, 'Penerbitan idempoten membuat tagihan berbeda.');

    $bill = du_require_bill($koneksi, $number, 9, 2098, true);
    test_assert(abs($bill['sisa'] - 600000) < .001, 'Saldo awal tagihan salah.');
    $koneksi->query("UPDATE tahun_ajaran SET status='closed',closed_at=NOW() WHERE id=" . (int)$yearId);
    test_assert((int)du_require_bill($koneksi, $number, 9, 2098, false)['id'] === $billId, 'Tunggakan tahun tertutup tidak dapat diakses.');
    test_assert(du_create_bill_for_placement($koneksi, $placementId) === null, 'Tahun tertutup masih menerbitkan tagihan baru.');

    $date = '2098-09-10 08:00:00'; $month = '09'; $year = '2098'; $user = 'test';
    $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,payment_link_version) VALUES (?,?,?,?,?,?,'Tunai',1)");
    $stmt->bind_param('ssssss', $number, $class, $date, $month, $year, $user); $stmt->execute();
    $paymentId = (int)$koneksi->insert_id; $stmt->close();
    $installment = 200000.0;
    $stmt = $koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('iisssd', $paymentId, $billId, $number, $class, $label, $installment); $stmt->execute(); $stmt->close();

    // Dua header sengaja memakai timestamp dan NIS yang sama. Koreksi satu
    // header harus menyentuh child berdasarkan bayar_id, bukan NIS/tanggal.
    $secondInstallment = 100000.0;
    $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,payment_link_version) VALUES (?,?,?,?,?,?,'Tunai',1)");
    $stmt->bind_param('ssssss', $number, $class, $date, $month, $year, $user); $stmt->execute();
    $secondPaymentId = (int)$koneksi->insert_id; $stmt->close();
    $stmt = $koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('iisssd', $secondPaymentId, $billId, $number, $class, $label, $secondInstallment); $stmt->execute(); $stmt->close();

    $bill = du_require_bill($koneksi, $number, 9, 2098, false);
    test_assert(abs($bill['terbayar'] - 300000) < .001 && abs($bill['sisa'] - 300000) < .001, 'Cicilan tidak mengurangi saldo tagihan dengan benar.');
    $stmt = $koneksi->prepare('DELETE FROM bayar WHERE id=?'); $stmt->bind_param('i', $paymentId); $stmt->execute(); $stmt->close();
    $bill = du_require_bill($koneksi, $number, 9, 2098, false);
    test_assert(abs($bill['terbayar'] - $secondInstallment) < .001 && abs($bill['sisa'] - 500000) < .001, 'Penghapusan satu pembayaran ikut menghapus cicilan child lain.');
    $stmt = $koneksi->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(jumlah), 0) AS jumlah FROM bayar_du WHERE bayar_id=?');
    $stmt->bind_param('i', $secondPaymentId); $stmt->execute(); $secondChild = $stmt->get_result()->fetch_assoc(); $stmt->close();
    test_assert((int)$secondChild['total'] === 1 && abs((float)$secondChild['jumlah'] - $secondInstallment) < .001, 'Child pembayaran kedua dengan timestamp sama tidak tetap terisolasi.');
    $stmt = $koneksi->prepare('DELETE FROM bayar WHERE id=?'); $stmt->bind_param('i', $secondPaymentId); $stmt->execute(); $stmt->close();
    $bill = du_require_bill($koneksi, $number, 9, 2098, false);
    test_assert(abs($bill['terbayar']) < .001, 'Penghapusan pembayaran kedua tidak memulihkan saldo tagihan.');

    $koneksi->rollback();
    echo "OK: mapping Juli-Juni, simpan-terbit atomik, penerbitan idempoten, tunggakan, cicilan, isolasi child timestamp identik, dan pemulihan saldo.\n";
} catch (Throwable $error) {
    $koneksi->rollback();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
