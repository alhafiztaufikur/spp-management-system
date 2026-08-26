<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
test_require_audit_database($koneksi);

function optional_fee_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$koneksi->begin_transaction();
try {
    $requiredColumns = ['MAKAN', 'SORGA', 'INFAQ'];
    $result = $koneksi->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa'
          AND COLUMN_NAME IN ('MAKAN','SORGA','INFAQ')");
    $columns = array_column($result->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    sort($columns); sort($requiredColumns);
    optional_fee_assert($columns === $requiredColumns, 'Kolom tarif opsional siswa belum lengkap.');

    $noInduk = '9908069999';
    $name = 'UJI REGRESI TARIF';
    $class = '1';
    $makan = 100000.0; $sorga = 200000.0; $infaq = 300000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,MAKAN,SORGA,INFAQ) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('sssddd', $noInduk, $name, $class, $makan, $sorga, $infaq);
    $stmt->execute(); $stmt->close();

    $date = '2026-08-06 10:00:00'; $month = '08'; $year = '2026';
    foreach ([40000.0, 60000.0] as $amount) {
        $stmt = $koneksi->prepare("INSERT INTO bayar
            (NO_INDUK,KELAS,U_MAKAN,TGL_BYR,BULAN,TAHUN,total_jumlah,payment_link_version)
            VALUES (?,?,?,?,?,?,?,1)");
        $stmt->bind_param('ssdsssd', $noInduk, $class, $amount, $date, $month, $year, $amount);
        $stmt->execute(); $stmt->close();
    }

    $row = $koneksi->query("SELECT MIN(id) first_id,SUM(U_MAKAN) paid FROM bayar WHERE NO_INDUK='9908069999'")->fetch_assoc();
    optional_fee_assert(abs((float)$row['paid'] - 100000) < .001, 'Dua cicilan Makan tidak terakumulasi menjadi Rp100.000.');

    $firstId = (int)$row['first_id']; $editedAmount = 30000.0;
    $stmt = $koneksi->prepare('UPDATE bayar SET U_MAKAN=?,total_jumlah=? WHERE id=?');
    $stmt->bind_param('ddi', $editedAmount, $editedAmount, $firstId); $stmt->execute(); $stmt->close();
    $paid = (float)$koneksi->query("SELECT SUM(U_MAKAN) paid FROM bayar WHERE NO_INDUK='9908069999'")->fetch_assoc()['paid'];
    optional_fee_assert(abs($paid - 90000) < .001, 'Edit cicilan tidak mengembalikan sisa tagihan dengan benar.');

    $stmt = $koneksi->prepare('DELETE FROM bayar WHERE NO_INDUK=?');
    $stmt->bind_param('s', $noInduk); $stmt->execute(); $stmt->close();
    $paid = (float)$koneksi->query("SELECT COALESCE(SUM(U_MAKAN),0) paid FROM bayar WHERE NO_INDUK='9908069999'")->fetch_assoc()['paid'];
    optional_fee_assert(abs($paid) < .001, 'Hapus cicilan tidak memulihkan saldo ke nol.');

    $koneksi->rollback();
    echo "OK: schema tarif opsional, cicilan satu kali, edit, dan pemulihan saldo.\n";
} catch (Throwable $error) {
    $koneksi->rollback();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
