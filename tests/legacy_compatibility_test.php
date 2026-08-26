<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
require_once __DIR__ . '/../includes/daftar_ulang.php';
test_require_audit_database($koneksi);

function legacy_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function legacy_random_student_number(mysqli $db): string {
    do {
        $number = (string)random_int(9800000000, 9899999999);
        $stmt = $db->prepare('SELECT 1 FROM siswa WHERE NO_INDUK=?');
        $stmt->bind_param('s', $number);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    } while ($exists);
    return $number;
}

$koneksi->begin_transaction();
try {
    $widths = [];
    $result = $koneksi->query("SELECT TABLE_NAME,COLUMN_NAME,CHARACTER_MAXIMUM_LENGTH AS width
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND (
            (TABLE_NAME='bayar' AND COLUMN_NAME IN ('user_id','KETERANGAN','LAIN_LAIN1','LAIN_LAIN2','LAIN_LAIN3','LAIN_LAIN4'))
            OR (TABLE_NAME IN ('transaksi_m','transaksi_k') AND COLUMN_NAME='user_id')
        )");
    while ($row = $result->fetch_assoc()) $widths[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = (int)$row['width'];
    legacy_assert(($widths['bayar.user_id'] ?? 0) >= 100, 'Kolom bayar.user_id belum diperbesar.');
    legacy_assert(($widths['bayar.KETERANGAN'] ?? 0) >= 255, 'Kolom bayar.KETERANGAN belum diperbesar.');
    legacy_assert(($widths['transaksi_m.user_id'] ?? 0) >= 100, 'Kolom transaksi_m.user_id belum diperbesar.');
    legacy_assert(($widths['transaksi_k.user_id'] ?? 0) >= 100, 'Kolom transaksi_k.user_id belum diperbesar.');
    for ($slot = 1; $slot <= 4; $slot++) {
        legacy_assert(($widths['bayar.LAIN_LAIN' . $slot] ?? 0) >= 100, 'Slot nama Biaya Lain legacy belum diperbesar.');
    }

    $duplicateIndex = (int)$koneksi->query("SELECT COUNT(*) total FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa'
          AND INDEX_NAME='uk_siswa_no_induk_diknas' AND NON_UNIQUE=0")->fetch_assoc()['total'];
    legacy_assert($duplicateIndex === 1, 'Indeks unik NIS Diknas belum tersedia.');

    $number = legacy_random_student_number($koneksi);
    $duplicateNumber = legacy_random_student_number($koneksi);
    $diknas = (string)random_int(9700000000, 9799999999);
    $name = 'UJI LEGACY SISWA';
    $class = '1';
    $duInitial = 900000.0;
    $duDiscount = 100000.0;
    $duTotal = 800000.0;
    $pangkal = 700000.0;
    $pangkalDiscount = 50000.0;
    $pangkalTotal = 650000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa
        (NO_INDUK,NAMA,KELAS,NO_induk_diknas,PANGKAL,potong_pangkal,tot_pangkal,DAFTAR_ULANG,potong_du,tot_du)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param(
        'ssssdddddd',
        $number,
        $name,
        $class,
        $diknas,
        $pangkal,
        $pangkalDiscount,
        $pangkalTotal,
        $duInitial,
        $duDiscount,
        $duTotal
    );
    $stmt->execute();
    $stmt->close();

    $duplicateRejected = false;
    try {
        $duplicateName = 'UJI DUPLIKAT DIKNAS';
        $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,NO_induk_diknas) VALUES (?,?,?,?)');
        $stmt->bind_param('ssss', $duplicateNumber, $duplicateName, $class, $diknas);
        $duplicateRejected = !$stmt->execute() && (int)$stmt->errno === 1062;
        $stmt->close();
    } catch (mysqli_sql_exception $error) {
        $duplicateRejected = $error->getCode() === 1062;
    }
    legacy_assert($duplicateRejected, 'NIS Diknas duplikat tidak ditolak database.');

    $label = du_current_academic_year();
    $stmt = $koneksi->prepare("SELECT ta.id,ta.status,du.id AS master_id,du.Jumlah
        FROM tahun_ajaran ta
        JOIN Daftar_ulang du ON du.tahun_ajaran_id=ta.id AND du.kelas=?
        WHERE ta.label=? LIMIT 1");
    $stmt->bind_param('ss', $class, $label);
    $stmt->execute();
    $year = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    legacy_assert((bool)$year && $year['status'] === 'published', 'Tes membutuhkan tahun ajaran berjalan yang sudah diterbitkan dan memiliki tarif kelas 1.');

    $yearId = (int)$year['id'];
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran (tahun_ajaran_id,no_induk,kelas,status) VALUES (?,?,?,'aktif')");
    $stmt->bind_param('iss', $yearId, $number, $class);
    $stmt->execute();
    $placementId = (int)$koneksi->insert_id;
    $stmt->close();

    $billId = du_create_bill_for_placement($koneksi, $placementId);
    legacy_assert((int)$billId > 0, 'Tagihan Daftar Ulang siswa legacy tidak diterbitkan.');
    $bill = du_find_bill($koneksi, $number, 8, (int)substr($label, 0, 4));
    legacy_assert(
        abs((float)$bill['nominal_awal'] - 900000.0) < 0.001
        && abs((float)$bill['nominal_tagihan'] - 800000.0) < 0.001,
        'Penerbitan tagihan tidak memprioritaskan nominal dan potongan siswa.'
    );

    $standardNumber = legacy_random_student_number($koneksi);
    $standardName = 'UJI LEGACY STANDAR';
    $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS) VALUES (?,?,?)');
    $stmt->bind_param('sss', $standardNumber, $standardName, $class);
    $stmt->execute();
    $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran (tahun_ajaran_id,no_induk,kelas,status) VALUES (?,?,?,'aktif')");
    $stmt->bind_param('iss', $yearId, $standardNumber, $class);
    $stmt->execute();
    $standardPlacementId = (int)$koneksi->insert_id;
    $stmt->close();
    $standardBillId = du_create_bill_for_placement($koneksi, $standardPlacementId);
    $oldClassAmount = (float)$year['Jumlah'];
    $newClassAmount = $oldClassAmount + 100000.0;
    $updatedBills = du_sync_open_bills_for_master_rate(
        $koneksi,
        (int)$year['master_id'],
        $label,
        $oldClassAmount,
        $newClassAmount
    );
    legacy_assert($updatedBills >= 1, 'Perubahan tarif tidak memperbarui siswa standar.');
    $standardBill = $koneksi->query('SELECT nominal_awal,nominal_tagihan FROM tagihan_daftar_ulang WHERE id=' . (int)$standardBillId)->fetch_assoc();
    $standardStudent = $koneksi->query("SELECT DAFTAR_ULANG,potong_du,tot_du FROM siswa WHERE NO_INDUK='" . $koneksi->real_escape_string($standardNumber) . "'")->fetch_assoc();
    $customBill = $koneksi->query('SELECT nominal_awal,nominal_tagihan FROM tagihan_daftar_ulang WHERE id=' . (int)$billId)->fetch_assoc();
    legacy_assert(
        abs((float)$standardBill['nominal_tagihan'] - $newClassAmount) < 0.001
        && abs((float)$standardStudent['DAFTAR_ULANG'] - $newClassAmount) < 0.001
        && abs((float)$standardStudent['potong_du']) < 0.001
        && abs((float)$standardStudent['tot_du'] - $newClassAmount) < 0.001,
        'Siswa standar tidak mengikuti perubahan tarif kelas.'
    );
    legacy_assert(
        abs((float)$customBill['nominal_awal'] - 900000.0) < 0.001
        && abs((float)$customBill['nominal_tagihan'] - 800000.0) < 0.001,
        'Override Daftar Ulang siswa ikut tertimpa perubahan tarif kelas.'
    );

    $newInitial = 950000.0;
    $newDiscount = 150000.0;
    $newTotal = 800000.0;
    $stmt = $koneksi->prepare('UPDATE siswa SET DAFTAR_ULANG=?,potong_du=?,tot_du=? WHERE NO_INDUK=?');
    $stmt->bind_param('ddds', $newInitial, $newDiscount, $newTotal, $number);
    $stmt->execute();
    $stmt->close();
    du_apply_current_student_override($koneksi, $number);
    $bill = du_find_bill($koneksi, $number, 8, (int)substr($label, 0, 4));
    legacy_assert(
        abs((float)$bill['nominal_awal'] - 950000.0) < 0.001
        && abs((float)$bill['nominal_tagihan'] - 800000.0) < 0.001,
        'Perubahan override siswa tidak menyinkronkan tagihan aktif.'
    );

    $paymentDate = date('Y-m-d H:i:s');
    $month = '08';
    $yearNumber = substr($label, 0, 4);
    $operator = 'Administrator Regression Test';
    $stmt = $koneksi->prepare("INSERT INTO bayar (NO_INDUK,KELAS,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran)
        VALUES (?,?,?,?,?,?,'Tunai')");
    $stmt->bind_param('ssssss', $number, $class, $paymentDate, $month, $yearNumber, $operator);
    $stmt->execute();
    $paymentId = (int)$koneksi->insert_id;
    $stmt->close();
    $paid = 300000.0;
    $stmt = $koneksi->prepare('INSERT INTO bayar_du
        (bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('iisssd', $paymentId, $billId, $number, $class, $label, $paid);
    $stmt->execute();
    $stmt->close();

    $invalidInitial = 250000.0;
    $invalidDiscount = 50000.0;
    $invalidTotal = 200000.0;
    $stmt = $koneksi->prepare('UPDATE siswa SET DAFTAR_ULANG=?,potong_du=?,tot_du=? WHERE NO_INDUK=?');
    $stmt->bind_param('ddds', $invalidInitial, $invalidDiscount, $invalidTotal, $number);
    $stmt->execute();
    $stmt->close();
    $underpaidRejected = false;
    try {
        du_apply_current_student_override($koneksi, $number);
    } catch (RuntimeException $error) {
        $underpaidRejected = str_contains($error->getMessage(), 'lebih kecil dari cicilan');
    }
    legacy_assert($underpaidRejected, 'Total Daftar Ulang di bawah cicilan tidak ditolak.');
    $bill = du_find_bill($koneksi, $number, 8, (int)substr($label, 0, 4));
    legacy_assert(abs((float)$bill['nominal_tagihan'] - 800000.0) < 0.001, 'Penolakan override merusak nilai tagihan sebelumnya.');

    $storedOperator = $koneksi->query('SELECT user_id FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['user_id'];
    legacy_assert($storedOperator === $operator, 'Nama operator panjang masih terpotong.');

    $mismatchedPangkal = (int)$koneksi->query('SELECT COUNT(*) total FROM siswa WHERE ABS(tot_pangkal-GREATEST(PANGKAL-potong_pangkal,0))>0.001')->fetch_assoc()['total'];
    legacy_assert($mismatchedPangkal === 0, 'Masih ada tot_pangkal yang tidak sesuai rumus legacy.');

    $koneksi->rollback();
    echo "OK: NIS Diknas unik, override dan tarif Daftar Ulang, batas cicilan, tot_pangkal, dan lebar kolom legacy tervalidasi.\n";
} catch (Throwable $error) {
    $koneksi->rollback();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
