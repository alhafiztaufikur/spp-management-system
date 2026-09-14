<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/tagihan_sekali.php';

function optional_fee_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$koneksi->begin_transaction();
try {
    $columns = $koneksi->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='siswa' AND COLUMN_NAME IN ('PSB','asal_psb'))
          OR (TABLE_NAME='bayar' AND COLUMN_NAME='U_PSB'))")->fetch_all(MYSQLI_ASSOC);
    optional_fee_assert(count($columns) === 3, 'Kolom PSB belum lengkap.');

    $removed = (int)$koneksi->query("SELECT COUNT(*) total FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='siswa' AND COLUMN_NAME IN
          ('BANGUNAN','SERAGAM','KEGIATAN','MAKAN','SORGA','INFAQ','PANGKAL_BAYAR','BANGUNAN_BAYAR','SERAGAM_BAYAR','KEGIATAN_BAYAR'))
          OR (TABLE_NAME='bayar' AND COLUMN_NAME IN ('U_BANGUNAN','U_SERAGAM','U_KEGIATAN','U_MAKAN','U_SORGA','U_INFAQ')))")->fetch_assoc()['total'];
    optional_fee_assert($removed === 0, 'Kolom komponen pembayaran lama masih tersedia.');

    $noInduk = (string)random_int(9800000000, 9899999999);
    $name = 'UJI UANG PSB';
    $class = '0';
    $pangkal = 700000.0;
    $psb = 3600000.0;
    $origin = 1;
    $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,PANGKAL,tot_pangkal,PSB,asal_psb) VALUES (?,?,?,?,?,?,?)');
    $stmt->bind_param('sssdddi', $noInduk, $name, $class, $pangkal, $pangkal, $psb, $origin);
    $stmt->execute(); $stmt->close();

    $date = '2026-08-06 10:00:00'; $month = '08'; $year = '2026';
    foreach ([400000.0, 600000.0] as $amount) {
        $stmt = $koneksi->prepare("INSERT INTO bayar
            (NO_INDUK,KELAS,U_PSB,TGL_BYR,BULAN,TAHUN,total_jumlah,payment_link_version)
            VALUES (?,?,?,?,?,?,?,1)");
        $stmt->bind_param('ssdsssd', $noInduk, $class, $amount, $date, $month, $year, $amount);
        $stmt->execute(); $stmt->close();
    }

    $status = one_time_fee_status($koneksi, $noInduk);
    optional_fee_assert(abs($status['psb']['paid'] - 1000000) < .001, 'Dua cicilan PSB tidak terakumulasi.');
    optional_fee_assert(abs($status['psb']['remaining'] - 2600000) < .001, 'Sisa PSB salah.');

    $overpaymentRejected = false;
    try {
        validate_one_time_fee_payments($koneksi, $noInduk, ['psb'=>2700000]);
    } catch (RuntimeException $error) {
        $overpaymentRejected = str_contains($error->getMessage(), 'melebihi sisa');
    }
    optional_fee_assert($overpaymentRejected, 'Pembayaran PSB melebihi sisa tidak ditolak.');

    $escaped = $koneksi->real_escape_string($noInduk);
    $row = $koneksi->query("SELECT MIN(id) first_id FROM bayar WHERE NO_INDUK='$escaped'")->fetch_assoc();
    $firstId = (int)$row['first_id']; $editedAmount = 300000.0;
    $stmt = $koneksi->prepare('UPDATE bayar SET U_PSB=?,total_jumlah=? WHERE id=?');
    $stmt->bind_param('ddi', $editedAmount, $editedAmount, $firstId); $stmt->execute(); $stmt->close();
    $status = one_time_fee_status($koneksi, $noInduk);
    optional_fee_assert(abs($status['psb']['paid'] - 900000) < .001, 'Edit cicilan PSB tidak dihitung ulang.');

    $koneksi->rollback();
    echo "OK: schema PSB, cicilan, batas pembayaran, dan edit tervalidasi.\n";
} catch (Throwable $error) {
    $koneksi->rollback();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
