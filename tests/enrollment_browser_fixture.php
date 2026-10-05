<?php
/** Prepare and verify real-browser student registration on a disposable clone. */
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "FAILED: fixture pendaftaran hanya boleh berjalan pada clone audit dengan flag tes.\n");
    exit(1);
}

$action = $argv[1] ?? '';
if (!in_array($action, ['setup', 'verify'], true)) {
    fwrite(STDERR, "Usage: php tests/enrollment_browser_fixture.php setup|verify\n");
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';
unit_set_context($koneksi, 1);

function enrollment_browser_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function enrollment_browser_student(mysqli $db, string $nis): ?array {
    $stmt = $db->prepare('SELECT * FROM siswa WHERE NO_INDUK=? LIMIT 1');
    $stmt->bind_param('s', $nis);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function enrollment_browser_count(mysqli $db, string $table, string $nis): int {
    $allowed = ['siswa_tahun_ajaran', 'tagihan_daftar_ulang', 'tagihan_komite', 'tagihan_spp', 'siswa_audit_log'];
    enrollment_browser_assert(in_array($table, $allowed, true), 'Tabel verifikasi tidak dikenal.');
    $column = $table === 'siswa_audit_log' ? 'no_induk_snapshot' : 'no_induk';
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM {$table} WHERE {$column}=?");
    $stmt->bind_param('s', $nis);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $count;
}

$regularNis = '9988222001';
$psbNis = '9988222002';
$invalidNis = '9988222003';

try {
    $database = (string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0];
    enrollment_browser_assert($database === DB_NAME, 'Database aktual berbeda dari clone yang diminta.');
    $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' AND unit_id=1 AND is_active=1 AND is_placeholder=0 LIMIT 1")->fetch_assoc();
    $psbClass = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=0 AND kode_rombel='PSB' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_assoc();
    enrollment_browser_assert((bool)$class && (bool)$psbClass, 'Kelas 1A atau PSB SD tidak tersedia.');
    $classId = (int)$class['id'];
    $psbClassId = (int)$psbClass['id'];
    $year = $koneksi->query("SELECT id FROM tahun_ajaran WHERE label='2026/2027' AND status='published' LIMIT 1")->fetch_assoc();
    enrollment_browser_assert((bool)$year, 'Tahun ajaran terbit 2026/2027 tidak tersedia.');
    $yearId = (int)$year['id'];
    $rate = $koneksi->query("SELECT t.nominal_dasar FROM master_spp_tahun m JOIN master_spp_tarif t ON t.master_spp_tahun_id=m.id WHERE m.tahun_ajaran_id={$yearId} AND m.status IN ('published','draft') AND t.tingkat=1 LIMIT 1")->fetch_assoc();
    enrollment_browser_assert($rate && (float)$rate['nominal_dasar'] > 0, 'Tarif SPP kelas 1 belum tersedia.');
    $duMaster = $koneksi->query("SELECT Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id={$yearId} AND kelas='1' LIMIT 1")->fetch_assoc();
    enrollment_browser_assert($duMaster && (float)$duMaster['Jumlah'] > 0, 'Tarif DU kelas 1 belum tersedia.');

    if ($action === 'setup') {
        $passwordFile = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE');
        enrollment_browser_assert($passwordFile !== '' && is_file($passwordFile), 'File sandi admin latihan wajib ada.');
        $password = trim((string)file_get_contents($passwordFile));
        enrollment_browser_assert($password !== '', 'Sandi admin latihan kosong.');
        $koneksi->begin_transaction();
        foreach ([$regularNis, $psbNis, $invalidNis] as $nis) {
            enrollment_browser_assert(enrollment_browser_student($koneksi, $nis) === null, 'NIS fixture sudah digunakan. Gunakan clone baru.');
        }
        $admin = $koneksi->query("SELECT id FROM admin WHERE username='admin' AND role='admin' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_assoc();
        enrollment_browser_assert((bool)$admin, 'Akun admin SD aktif tidak tersedia.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $adminId = (int)$admin['id'];
        $stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
        $stmt->bind_param('si', $hash, $adminId);
        $stmt->execute();
        $stmt->close();
        $koneksi->commit();
        echo "OK: clone siap untuk pendaftaran browser reguler dan PSB.\n";
        exit(0);
    }

    $regular = enrollment_browser_student($koneksi, $regularNis);
    $psb = enrollment_browser_student($koneksi, $psbNis);
    enrollment_browser_assert($regular && $psb && enrollment_browser_student($koneksi, $invalidNis) === null,
        'Pendaftaran reguler/PSB atau penolakan invalid tidak sesuai.');
    $expectedSpp = max(0,(float)$rate['nominal_dasar'] - 25000);
    enrollment_browser_assert($regular['NAMA'] === 'UJI BROWSER REGULER' && $regular['KELAS'] === '1'
        && (int)$regular['master_kelas_id'] === $classId && (int)$regular['is_active'] === 1
        && (int)$regular['asal_psb'] === 0 && (float)$regular['PSB'] === 0.0
        && (float)$regular['DAFTAR_ULANG'] === 500000.0 && (float)$regular['tot_du'] === 450000.0
        && (float)$regular['POMG'] === 100000.0 && (float)$regular['SPP_PERBULAN'] === $expectedSpp,
        'Identitas, potongan, atau tarif siswa reguler tidak sesuai.');
    $stmt = $koneksi->prepare('SELECT sta.*,ta.label FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=?');
    $stmt->bind_param('s', $regularNis);
    $stmt->execute();
    $placements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    enrollment_browser_assert(count($placements) === 1, 'Siswa reguler harus punya tepat satu penempatan.');
    $placement = $placements[0];
    enrollment_browser_assert($placement['label'] === '2026/2027' && $placement['kelas'] === '1'
        && $placement['kelas_rombel_snapshot'] === '1A' && $placement['status'] === 'aktif'
        && (int)$placement['master_kelas_id'] === $classId
        && (float)$placement['spp_perbulan_snapshot'] === $expectedSpp
        && (float)$placement['komite_snapshot'] === 100000.0
        && $placement['komite_mulai_bulan'] === '09',
        'Penempatan, snapshot, atau bulan awal Komite siswa reguler salah.');
    $bill = $koneksi->query('SELECT nominal_awal,nominal_tagihan,kelas_snapshot,tahun_ajaran_snapshot FROM tagihan_daftar_ulang WHERE penempatan_id=' . (int)$placement['id'])->fetch_assoc();
    enrollment_browser_assert($bill && (float)$bill['nominal_awal'] === 500000.0
        && (float)$bill['nominal_tagihan'] === 450000.0 && $bill['kelas_snapshot'] === '1'
        && $bill['tahun_ajaran_snapshot'] === '2026/2027',
        'Tagihan DU siswa reguler tidak mengikuti potongan dan penempatan.');
    $komite = $koneksi->query('SELECT COUNT(*) n,MIN(bulan) first_month,MAX(nominal_tagihan) max_amount FROM tagihan_komite WHERE penempatan_id=' . (int)$placement['id'])->fetch_assoc();
    enrollment_browser_assert((int)$komite['n'] === 10 && $komite['first_month'] === '01'
        && (float)$komite['max_amount'] === 100000.0,
        'Tagihan Komite September-Juni siswa reguler salah.');
    $early = $koneksi->query("SELECT COUNT(*) n FROM tagihan_komite WHERE penempatan_id=" . (int)$placement['id'] . " AND tahun='2026' AND bulan IN ('07','08')")->fetch_assoc();
    enrollment_browser_assert((int)$early['n'] === 0, 'Komite Juli/Agustus terbit sebelum bulan mulai.');
    enrollment_browser_assert(enrollment_browser_count($koneksi, 'tagihan_spp', $regularNis) === 0,
        'Pendaftaran siswa menerbitkan SPP tanpa tindakan penerbitan.');

    enrollment_browser_assert($psb['NAMA'] === 'UJI BROWSER PSB' && $psb['KELAS'] === '0'
        && (int)$psb['master_kelas_id'] === $psbClassId && (int)$psb['is_active'] === 1
        && (int)$psb['asal_psb'] === 1 && (float)$psb['PSB'] === 3600000.0
        'Identitas atau tarif siswa PSB salah.');
    foreach (['siswa_tahun_ajaran', 'tagihan_spp', 'tagihan_komite', 'tagihan_daftar_ulang'] as $table) {
        enrollment_browser_assert(enrollment_browser_count($koneksi, $table, $psbNis) === 0,
            'Siswa PSB menerima penempatan/tagihan reguler: ' . $table);
    }
    enrollment_browser_assert(enrollment_browser_count($koneksi, 'siswa_audit_log', $regularNis) === 1
        && enrollment_browser_count($koneksi, 'siswa_audit_log', $psbNis) === 1,
        'Audit pendaftaran siswa tidak tercatat tepat satu kali.');
    echo "OK: browser mendaftarkan reguler dan PSB; identitas, snapshot, DU, Komite, serta audit cocok dengan database.\n";
} catch (Throwable $error) {
    if ($action === 'setup') {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
    }
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
