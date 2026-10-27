<?php
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_(?:audit|test)_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    error_log('FAILED: tes mutasi memerlukan CLI, clone db_spp_audit_* atau db_spp_test_*, dan SPP_TEST_ALLOW_MUTATION=1.');
    exit(1);
}

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
    du_selector_assert(count($studentBills) === 3, 'Payload memuat tagihan berjalan, tunggakan lama, dan tagihan mendatang yang telah terbit.');
    $currentBill = array_values(array_filter($studentBills, fn($row) => $row['is_current']))[0] ?? null;
    $arrearBill = array_values(array_filter($studentBills, fn($row) => $row['is_arrear']))[0] ?? null;
    du_selector_assert($currentBill && abs((float)$currentBill['sisa']) < .001, 'Tagihan tahun berjalan yang lunas tidak dipertahankan sebagai pilihan awal.');
    du_selector_assert($arrearBill && $arrearBill['tahun_ajaran'] === $previous, 'Tunggakan tahun sebelumnya tidak ditandai dengan benar.');

    $selected = du_require_selectable_bill($koneksi, $billIds[$previous], $nis, 0, true);
    du_selector_assert($selected['tahun_ajaran'] === $previous && abs($selected['sisa'] - 900000) < .001, 'Tagihan lama tidak dapat dipilih berdasarkan ID.');
    du_assert_payment_amount($selected, 100000);
    du_assert_payment_amount($selected, 900000);
    $tooMuch = false;
    try { du_assert_payment_amount($selected, 900001); }
    catch (DaftarUlangSelectionException $error) { $tooMuch = $error->reason === 'over_limit'; }
    du_selector_assert($tooMuch, 'Pembayaran melebihi sisa tagihan tidak ditolak.');
    du_assert_bill_snapshot($selected, 900000, 0);
    $changed = false;
    try { du_assert_bill_snapshot($selected, 900000, 100000); }
    catch (DaftarUlangSelectionException $error) { $changed = $error->reason === 'changed'; }
    du_selector_assert($changed, 'Saldo tagihan yang berubah tidak terdeteksi.');

    $settled = false;
    try { du_require_selectable_bill($koneksi, $billIds[$current], $nis); }
    catch (DaftarUlangSelectionException $error) { $settled = $error->reason === 'settled'; }
    du_selector_assert($settled, 'Tagihan yang sudah lunas masih dapat dibayar.');
    $editable = du_require_selectable_bill($koneksi, $billIds[$current], $nis, $paymentId, true);
    du_selector_assert(abs($editable['sisa'] - 1000000) < .001, 'Saldo edit tidak mengembalikan pembayaran lama sebelum validasi.');

    $early=du_require_selectable_bill($koneksi,$billIds[$future],$nis);
    du_selector_assert($early['tahun_ajaran']===$future,'Tagihan mendatang yang telah terbit dapat dibayar.');

    $missingBillId = (int)$koneksi->query('SELECT COALESCE(MAX(id),0)+1000000 FROM tagihan_daftar_ulang')->fetch_row()[0];
    foreach ([
        'missing' => fn() => du_require_selectable_bill($koneksi, 0, $nis),
        'not_found' => fn() => du_require_selectable_bill($koneksi, $missingBillId, $nis),
        'wrong_student' => fn() => du_require_selectable_bill($koneksi, $billIds[$previous], '0000000000'),
    ] as $reason => $invalidCall) {
        $rejected = false;
        try { $invalidCall(); }
        catch (DaftarUlangSelectionException $error) { $rejected = $error->reason === $reason; }
        du_selector_assert($rejected, 'Alasan penolakan Daftar Ulang tidak sesuai: ' . $reason);
    }
    $previousBillId = $billIds[$previous];
    $stmt = $koneksi->prepare("UPDATE tagihan_daftar_ulang SET status='cancelled' WHERE id=?");
    $stmt->bind_param('i', $previousBillId); $stmt->execute(); $stmt->close();
    $cancelled = false;
    try { du_require_selectable_bill($koneksi, $previousBillId, $nis); }
    catch (DaftarUlangSelectionException $error) { $cancelled = $error->reason === 'cancelled'; }
    du_selector_assert($cancelled, 'Tagihan yang dibatalkan masih dapat dibayar.');
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
