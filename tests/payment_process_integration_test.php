<?php

/**
 * Tes HTTP ini sengaja memutasi database. Jalankan hanya pada database
 * disposable dengan SPP_TEST_ALLOW_MUTATION=1 dan kredensial admin test.
 */
require_once __DIR__ . '/../koneksi.php';

function payment_process_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function payment_process_request(string $url, array $data, array &$cookies): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $data ? 'POST' : 'GET',
        'header' => implode("\r\n", $headers),
        'content' => $data ? http_build_query($data) : '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('HTTP request ke aplikasi gagal.');

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function payment_process_flash(string $baseUrl, array &$cookies): string {
    $page = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $page['body'], $match)) {
        return trim(html_entity_decode(strip_tags($match[1])));
    }
    if (preg_match('/window\.sppFlashWarning\s*=\s*([^\r\n]+);/', $page['body'], $match)) {
        $warning = json_decode(trim($match[1]), true);
        if (is_array($warning)) return (string)($warning['message'] ?? '');
    }
    return '';
}

function payment_process_unique_nis(mysqli $db): string {
    do {
        $nis = (string)random_int(9900000000, 9999999999);
        $stmt = $db->prepare('SELECT 1 FROM siswa WHERE NO_INDUK = ?');
        $stmt->bind_param('s', $nis);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    } while ($exists);
    return $nis;
}

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    fwrite(STDERR, "SKIPPED: set SPP_TEST_ALLOW_MUTATION=1 hanya pada database disposable.\n");
    exit(0);
}
if (!(string)getenv('SPP_TEST_ADMIN_PASSWORD')) {
    fwrite(STDERR, "FAILED: SPP_TEST_ADMIN_PASSWORD wajib diisi untuk akun admin database test.\n");
    exit(1);
}

$baseUrl = getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1/sppaman/spp-management-system';
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
$testNis = [payment_process_unique_nis($koneksi), payment_process_unique_nis($koneksi), payment_process_unique_nis($koneksi), payment_process_unique_nis($koneksi)];
$createdYearIds = [];
$failure = null;

try {
    $currentYear = (int)date('Y');
    $startYear = 0;
    for ($candidate = $currentYear + 2; $candidate <= $currentYear + 9; $candidate++) {
        $labels = [($candidate - 2) . '/' . ($candidate - 1), ($candidate - 1) . '/' . $candidate, $candidate . '/' . ($candidate + 1)];
        $placeholders = implode(',', array_fill(0, count($labels), '?'));
        $stmt = $koneksi->prepare("SELECT COUNT(*) AS total FROM tahun_ajaran WHERE label IN ($placeholders)");
        $types = str_repeat('s', count($labels));
        $stmt->bind_param($types, ...$labels);
        $stmt->execute();
        $exists = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        if ($exists === 0) { $startYear = $candidate; break; }
    }
    payment_process_assert($startYear > 0, 'Tidak menemukan tiga tahun ajaran kosong untuk tes disposable.');

    $labels = [($startYear - 2) . '/' . ($startYear - 1), ($startYear - 1) . '/' . $startYear, $startYear . '/' . ($startYear + 1)];
    $stmtYear = $koneksi->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, 'published')");
    foreach ($labels as $label) {
        [$start, $end] = array_map('intval', explode('/', $label));
        $dateStart = $start . '-07-01';
        $dateEnd = $end . '-06-30';
        $stmtYear->bind_param('sss', $label, $dateStart, $dateEnd);
        $stmtYear->execute();
        $createdYearIds[$label] = (int)$koneksi->insert_id;
    }
    $stmtYear->close();

    $classId = (int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_placeholder=1 LIMIT 1")->fetch_assoc()['id'];
    payment_process_assert($classId > 0, 'Kelas placeholder tingkat 1 tidak tersedia untuk tes.');
    $stmtStudent = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, master_kelas_id, SPP_PERBULAN) VALUES (?, ?, \'1\', ?, 275000)');
    foreach (['UJI SPP LINTAS', 'UJI SPP HISTORI', 'UJI SPP PINDAH', 'UJI SPP BARU'] as $index => $name) {
        $stmtStudent->bind_param('ssi', $testNis[$index], $name, $classId);
        $stmtStudent->execute();
    }
    $stmtStudent->close();

    $stmtPlacement = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran (tahun_ajaran_id, no_induk, kelas, master_kelas_id, kelas_rombel_snapshot, spp_perbulan_snapshot, komite_snapshot, status) VALUES (?, ?, \'1\', ?, \'Kelas 1 (Belum Ditentukan)\', ?, 0, ?)');
    $addPlacement = static function (int $yearId, string $nis, float $tariff, string $status) use ($stmtPlacement, $classId): void {
        $stmtPlacement->bind_param('isids', $yearId, $nis, $classId, $tariff, $status);
        $stmtPlacement->execute();
    };

    $addPlacement($createdYearIds[$labels[1]], $testNis[0], 250000, 'aktif');
    $addPlacement($createdYearIds[$labels[2]], $testNis[0], 275000, 'aktif');
    foreach ($labels as $label) $addPlacement($createdYearIds[$label], $testNis[1], 250000, 'aktif');
    $addPlacement($createdYearIds[$labels[1]], $testNis[2], 250000, 'pindah');
    $addPlacement($createdYearIds[$labels[2]], $testNis[2], 275000, 'aktif');
    $addPlacement($createdYearIds[$labels[2]], $testNis[3], 275000, 'aktif');
    $stmtPlacement->close();

    $stmtPaid = $koneksi->prepare('INSERT INTO bayar (NO_INDUK, KELAS, U_SPP, TGL_BYR, BULAN, TAHUN, total_jumlah, payment_link_version) VALUES (?, \'1\', 250000, ?, ?, ?, 250000, 1)');
    for ($month = 7; $month <= 12; $month++) {
        $date = ($startYear - 1) . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-10 10:00:00';
        $code = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
        $year = (string)($startYear - 1);
        $stmtPaid->bind_param('ssss', $testNis[0], $date, $code, $year);
        $stmtPaid->execute();
    }
    for ($month = 1; $month <= 5; $month++) {
        $date = $startYear . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-10 10:00:00';
        $code = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
        $year = (string)$startYear;
        $stmtPaid->bind_param('ssss', $testNis[0], $date, $code, $year);
        $stmtPaid->execute();
    }
    $stmtPaid->close();

    $cookies = [];
    $login = payment_process_request($baseUrl . '/login.php', ['username' => 'admin', 'password' => $password], $cookies);
    payment_process_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin database test gagal.');

    $payment = static function (string $nis, string $month, string $year, float $amount) use ($baseUrl, &$cookies): array {
        return payment_process_request($baseUrl . '/pembayaran/proses.php', [
            'aksi' => 'input', 'payment_plan' => 'monthly', 'no_induk' => $nis,
            'bulan_bayar' => $month, 'tahun_bayar' => $year,
            'sistem_pembayaran' => 'Tunai', 'uang_spp' => $amount,
        ], $cookies);
    };

    $blockedJuly = $payment($testNis[0], '07', (string)$startYear, 275000);
    payment_process_assert($blockedJuly['status'] === 302, 'SPP Juli lintas tahun tidak mengembalikan redirect.');
    payment_process_assert(str_contains(payment_process_flash($baseUrl, $cookies), 'tunggakan Juni ' . $startYear), 'SPP Juli tahun baru tidak ditolak saat Juni tahun sebelumnya menunggak.');

    $statusUrl = $baseUrl . '/pembayaran/status_spp.php?' . http_build_query([
        'no_induk' => $testNis[0], 'bulan' => '07', 'tahun' => (string)$startYear,
    ]);
    $liveBlocked = payment_process_request($statusUrl, [], $cookies);
    $liveBlockedPayload = json_decode($liveBlocked['body'], true);
    payment_process_assert($liveBlocked['status'] === 200 && ($liveBlockedPayload['status'] ?? '') === 'arrears', 'Endpoint status tidak melaporkan tunggakan Juli.');
    payment_process_assert(($liveBlockedPayload['blocking_period']['label'] ?? '') === 'Juni ' . $startYear, 'Endpoint status tidak memilih tunggakan pertama yang tepat.');

    payment_process_assert($payment($testNis[0], '06', (string)$startYear, 250000)['status'] === 302, 'Pelunasan Juni tahun sebelumnya gagal.');
    $livePayable = payment_process_request($statusUrl, [], $cookies);
    $livePayablePayload = json_decode($livePayable['body'], true);
    payment_process_assert($livePayable['status'] === 200 && ($livePayablePayload['status'] ?? '') === 'payable', 'Endpoint status belum berubah menjadi dapat dibayar setelah Juni lunas.');
    payment_process_assert($payment($testNis[0], '07', (string)$startYear, 275000)['status'] === 302, 'SPP Juli setelah Juni lunas gagal.');

    $stmtJuly = $koneksi->prepare("SELECT id, U_SPP FROM bayar WHERE NO_INDUK=? AND BULAN='07' AND TAHUN=? ORDER BY id DESC LIMIT 1");
    $targetYear = (string)$startYear;
    $stmtJuly->bind_param('ss', $testNis[0], $targetYear);
    $stmtJuly->execute();
    $julyPayment = $stmtJuly->get_result()->fetch_assoc();
    $stmtJuly->close();
    payment_process_assert(abs((float)($julyPayment['U_SPP'] ?? 0) - 275000) < 0.001, 'SPP Juli tidak memakai tarif snapshot tahun ajaran baru.');

    $stmtJune = $koneksi->prepare("SELECT id FROM bayar WHERE NO_INDUK=? AND BULAN='06' AND TAHUN=? ORDER BY id DESC LIMIT 1");
    $stmtJune->bind_param('ss', $testNis[0], $targetYear);
    $stmtJune->execute();
    $junePaymentId = (int)$stmtJune->get_result()->fetch_assoc()['id'];
    $stmtJune->close();

    $editJune = payment_process_request($baseUrl . '/pembayaran/proses.php', [
        'aksi' => 'update', 'id' => $junePaymentId, 'no_induk' => $testNis[0],
        'bulan_bayar' => '06', 'tahun_bayar' => (string)$startYear,
        'sistem_pembayaran' => 'Tunai', 'uang_spp' => 0,
    ], $cookies);
    payment_process_assert($editJune['status'] === 302, 'Edit prasyarat lintas tahun tidak mengembalikan redirect.');
    payment_process_assert(str_contains(payment_process_flash($baseUrl, $cookies), 'tidak bisa dikosongkan karena Juli ' . $startYear . ' sudah dibayar'), 'Edit Juni tidak ditolak saat Juli tahun berikutnya sudah dibayar.');

    $deleteJune = payment_process_request($baseUrl . '/pembayaran/proses.php?aksi=hapus&id=' . $junePaymentId, [], $cookies);
    payment_process_assert($deleteJune['status'] === 302, 'Hapus prasyarat lintas tahun tidak mengembalikan redirect.');
    payment_process_assert(str_contains(payment_process_flash($baseUrl, $cookies), 'tidak bisa dihapus karena Juli ' . $startYear . ' sudah dibayar'), 'Hapus Juni tidak ditolak saat Juli tahun berikutnya sudah dibayar.');

    $blockedHistory = $payment($testNis[1], '07', (string)$startYear, 250000);
    payment_process_assert($blockedHistory['status'] === 302, 'Tunggakan historis tidak mengembalikan redirect.');
    payment_process_assert(str_contains(payment_process_flash($baseUrl, $cookies), 'tunggakan Juli ' . ($startYear - 2)), 'Periode tunggakan aktif paling awal tidak dipilih sebagai penghalang.');

    payment_process_assert($payment($testNis[2], '07', (string)$startYear, 275000)['status'] === 302, 'Penempatan pindah justru memblokir SPP tahun aktif.');
    payment_process_assert($payment($testNis[3], '07', (string)$startYear, 275000)['status'] === 302, 'Siswa baru tanpa penempatan aktif sebelumnya justru terblokir.');
    payment_process_assert($payment($testNis[0], '08', (string)$startYear, 275000)['status'] === 302, 'Urutan setelah Juli tidak dapat diteruskan.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($testNis) {
        $placeholders = implode(',', array_fill(0, count($testNis), '?'));
        $types = str_repeat('s', count($testNis));
        $stmt = $koneksi->prepare("DELETE FROM bayar WHERE NO_INDUK IN ($placeholders)");
        $stmt->bind_param($types, ...$testNis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("DELETE FROM siswa_tahun_ajaran WHERE no_induk IN ($placeholders)");
        $stmt->bind_param($types, ...$testNis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("DELETE FROM siswa WHERE NO_INDUK IN ($placeholders)");
        $stmt->bind_param($types, ...$testNis); $stmt->execute(); $stmt->close();
    }
    if ($createdYearIds) {
        $yearIds = array_values($createdYearIds);
        $placeholders = implode(',', array_fill(0, count($yearIds), '?'));
        $types = str_repeat('i', count($yearIds));
        $stmt = $koneksi->prepare("DELETE FROM tahun_ajaran WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$yearIds); $stmt->execute(); $stmt->close();
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "OK: endpoint SPP penuh menegakkan urutan lintas tahun, snapshot tarif, dan penempatan aktif.\n";
