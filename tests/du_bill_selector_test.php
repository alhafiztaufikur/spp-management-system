<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/daftar_ulang.php';

function du_selector_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function du_selector_year_id(mysqli $db, string $label): int {
    $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1');
    $stmt->bind_param('s', $label); $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    if ($id > 0) return $id;
    [$start, $end] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status,published_at) VALUES(?,?,?,'published',NOW())");
    $stmt->bind_param('sss', $label, $start, $end); $stmt->execute();
    $id = (int)$db->insert_id; $stmt->close();
    return $id;
}

$failure = null;
$koneksi->begin_transaction();
try {
    $current = du_current_academic_year();
    $previousStart = (int)substr($current, 0, 4) - 1;
    $previous = $previousStart . '/' . ($previousStart + 1);
    $futureStart = (int)substr($current, 0, 4) + 1;
    $future = $futureStart . '/' . ($futureStart + 1);
    $yearIds = [
        $previous => du_selector_year_id($koneksi, $previous),
        $current => du_selector_year_id($koneksi, $current),
        $future => du_selector_year_id($koneksi, $future),
    ];

    $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_active=1 ORDER BY is_placeholder,id LIMIT 1")->fetch_assoc();
    du_selector_assert((bool)$class, 'Kelas 1 tidak tersedia untuk pengujian.');
    $classId = (int)$class['id'];
    $nis = (string)random_int(9700000000, 9799999999);
    $name = 'UJI PEMILIH DU';
    $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,is_active) VALUES(?,?,'1',?,250000,1)");
    $stmt->bind_param('ssi', $nis, $name, $classId); $stmt->execute(); $stmt->close();

    $billIds = [];
    foreach ($yearIds as $label => $yearId) {
        $status = $label === $current ? 'aktif' : 'pindah';
        $snapshot = '1A'; $classText = '1'; $tariff = 250000.0; $komite = 100000.0;
        $stmt = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->bind_param('issisdds', $yearId, $nis, $classText, $classId, $snapshot, $tariff, $komite, $status);
        $stmt->execute(); $placementId = (int)$koneksi->insert_id; $stmt->close();
        $amount = $label === $previous ? 900000.0 : 1000000.0;
        $stmt = $koneksi->prepare('INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,?)');
        $stmt->bind_param('iisssdd', $yearId, $placementId, $nis, $classText, $label, $amount, $amount);
        $stmt->execute(); $billIds[$label] = (int)$koneksi->insert_id; $stmt->close();
    }

    $date = date('Y-m-d H:i:s'); $month = date('m'); $calendarYear = date('Y');
    $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,TGL_BYR,BULAN,TAHUN,total_jumlah,payment_link_version) VALUES(?,'1',?,?,?,?,1)");
    $paidCurrent = 1000000.0;
    $stmt->bind_param('ssssd', $nis, $date, $month, $calendarYear, $paidCurrent);
    $stmt->execute(); $paymentId = (int)$koneksi->insert_id; $stmt->close();
    $stmt = $koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('iisssd', $paymentId, $billIds[$current], $nis, $classText, $current, $paidCurrent);
    $stmt->execute(); $stmt->close();

    $payload = du_selectable_bills_payload($koneksi);
    $studentBills = $payload[$nis] ?? [];
    du_selector_assert(count($studentBills) === 2, 'Payload harus memuat tagihan tahun berjalan dan tunggakan lama, tetapi bukan masa depan.');
    $currentBill = array_values(array_filter($studentBills, fn($row) => $row['is_current']))[0] ?? null;
    $arrearBill = array_values(array_filter($studentBills, fn($row) => $row['is_arrear']))[0] ?? null;
    du_selector_assert($currentBill && abs((float)$currentBill['sisa']) < .001, 'Tagihan tahun berjalan yang lunas tidak dipertahankan sebagai pilihan awal.');
    du_selector_assert($arrearBill && $arrearBill['tahun_ajaran'] === $previous, 'Tunggakan tahun sebelumnya tidak ditandai dengan benar.');

    $selected = du_require_selectable_bill($koneksi, $billIds[$previous], $nis, 0, true);
    du_selector_assert($selected['tahun_ajaran'] === $previous && abs($selected['sisa'] - 900000) < .001, 'Tagihan lama tidak dapat dipilih berdasarkan ID.');
    $editable = du_require_selectable_bill($koneksi, $billIds[$current], $nis, $paymentId, true);
    du_selector_assert(abs($editable['sisa'] - 1000000) < .001, 'Saldo edit tidak mengembalikan pembayaran lama sebelum validasi.');

    foreach ([
        fn() => du_require_selectable_bill($koneksi, 0, $nis),
        fn() => du_require_selectable_bill($koneksi, $billIds[$previous], '0000000000'),
        fn() => du_require_selectable_bill($koneksi, $billIds[$future], $nis),
    ] as $invalidCall) {
        $rejected = false;
        try { $invalidCall(); } catch (RuntimeException $error) { $rejected = true; }
        du_selector_assert($rejected, 'Tagihan tanpa ID, milik siswa lain, atau masa depan tidak ditolak.');
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
echo "OK: payload dan validasi pemilih tunggakan Daftar Ulang tervalidasi.\n";
