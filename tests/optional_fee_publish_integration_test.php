<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
test_require_disposable_audit_database($koneksi);

function fee_publish_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function fee_publish_request(string $url, array $data, array &$cookies): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers),
        'content' => http_build_query($data), 'ignore_errors' => true,
        'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('Request publish biaya gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function fee_publish_get(string $url, array &$cookies): array {
    $headers = ['Accept: text/html'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $headers),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('GET form biaya gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function fee_publish_csrf(string $html): string {
    if (!preg_match('/<form\b[^>]*id="form-terbit-biaya"[^>]*>(.*?)<\/form>/si', $html, $formMatch)
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $formMatch[1], $match)) {
        throw new RuntimeException('CSRF master biaya tidak ditemukan.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

$baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$username = (string)getenv('SPP_TEST_ADMIN_USERNAME');
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
fee_publish_assert($baseUrl === 'http://127.0.0.1:8099', 'Test hanya boleh memakai server audit 8099.');
fee_publish_assert($username !== '' && $password !== '', 'Kredensial test wajib melalui environment.');

$suffix = bin2hex(random_bytes(5));
$nis = '98' . random_int(10000000, 99999999);
$name = 'UJI TARGET BIAYA ' . $suffix;
$masterIds = [];
$billMasterIds = [];
$failure = null;
try {
    $classRow = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_active=1 ORDER BY is_placeholder DESC,id LIMIT 1")->fetch_assoc();
    fee_publish_assert((bool)$classRow, 'Master kelas 1 aktif tidak tersedia.');
    $classId = (int)$classRow['id'];
    $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES (?,?,?,?,1)');
    $class = '1';
    $stmt->bind_param('sssi', $nis, $name, $class, $classId);
    $stmt->execute(); $stmt->close();

    $stmtMaster = $koneksi->prepare('INSERT INTO master_biaya_lain (nama,nominal,is_active) VALUES (?, ?, 1)');
    foreach ([1100.0, 1200.0, 1300.0, 1400.0] as $index => $amount) {
        $masterName = "UJI TARGET BIAYA {$suffix} #{$index}";
        $stmtMaster->bind_param('sd', $masterName, $amount);
        $stmtMaster->execute(); $masterIds[] = (int)$koneksi->insert_id;
    }
    $stmtMaster->close();

    $cookies = [];
    $loginPage = fee_publish_get($baseUrl . '/login.php', $cookies);
    fee_publish_assert($loginPage['status'] === 200, 'Login GET gagal.');
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $loginMatch)) throw new RuntimeException('CSRF login tidak ditemukan.');
    $login = fee_publish_request($baseUrl . '/login.php', [
        'csrf_token' => html_entity_decode($loginMatch[1], ENT_QUOTES, 'UTF-8'),
        'username' => $username, 'password' => $password,
    ], $cookies);
    fee_publish_assert($login['status'] === 302, 'Login admin gagal.');

    $publish = static function (int $masterId, string $target, array $extra = []) use (&$cookies, $baseUrl): void {
        $form = fee_publish_get($baseUrl . '/master_biaya_lain.php', $cookies);
        if ($form['status'] !== 200) throw new RuntimeException('Form master biaya tidak dapat dibuka.');
        if (!isset($cookies['PHPSESSID'])) throw new RuntimeException('Cookie PHPSESSID tidak tersedia untuk publish biaya.');
        $csrf = fee_publish_csrf($form['body']);
        if (strlen($csrf) < 32) throw new RuntimeException('Token CSRF master biaya kosong atau terlalu pendek.');
        $GLOBALS['fee_last_csrf_hash'] = hash('sha256', $csrf);
        $data = array_merge([
            'csrf_token' => $csrf,
            'aksi' => 'terbitkan_tagihan', 'master_id' => $masterId, 'target' => $target,
        ], $extra);
        $response = fee_publish_request($baseUrl . '/master_biaya_lain.php', $data, $cookies);
        if ($response['status'] !== 302) throw new RuntimeException("Publish target {$target} tidak mengembalikan redirect.");
    };

    $eligibleAll = (int)$koneksi->query('SELECT COUNT(*) total FROM siswa WHERE is_active=1')->fetch_assoc()['total'];
    $publish($masterIds[0], 'all');
    $stmt = $koneksi->prepare('SELECT COUNT(*) total FROM tagihan_biaya_lain WHERE master_biaya_lain_id=?');
    $stmt->bind_param('i', $masterIds[0]); $stmt->execute(); $allCount = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    if ($allCount !== $eligibleAll) {
        $debugPage = fee_publish_get($baseUrl . '/master_biaya_lain.php', $cookies);
        $debugMessage = '';
        if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/si', $debugPage['body'], $debugMatch)) $debugMessage = trim(strip_tags(html_entity_decode($debugMatch[1], ENT_QUOTES, 'UTF-8')));
        $debugTokenHash = '';
        try { $debugTokenHash = hash('sha256', fee_publish_csrf($debugPage['body'])); } catch (Throwable) {}
        $cookieValueLength = isset($cookies['PHPSESSID']) ? strlen((string)$cookies['PHPSESSID']) : 0;
        throw new RuntimeException("Target all menghasilkan {$allCount}, expected {$eligibleAll}; flash={$debugMessage}; csrf_sent={$GLOBALS['fee_last_csrf_hash']}; csrf_now={$debugTokenHash}; cookie_value_length={$cookieValueLength}");
    }
    $stmtSnapshot = $koneksi->prepare('SELECT nama_snapshot, nominal_tagihan FROM tagihan_biaya_lain WHERE master_biaya_lain_id = ? ORDER BY id LIMIT 1');
    $stmtSnapshot->bind_param('i', $masterIds[0]);
    $stmtSnapshot->execute();
    $snapshotBefore = $stmtSnapshot->get_result()->fetch_assoc();
    $stmtSnapshot->close();
    $changedName = 'UJI TARGET BIAYA MASTER BERUBAH ' . $suffix;
    $changedNominal = 999999.0;
    $stmtMasterChange = $koneksi->prepare('UPDATE master_biaya_lain SET nama = ?, nominal = ? WHERE id = ?');
    $stmtMasterChange->bind_param('sdi', $changedName, $changedNominal, $masterIds[0]);
    $stmtMasterChange->execute();
    $stmtMasterChange->close();
    $stmtSnapshot = $koneksi->prepare('SELECT nama_snapshot, nominal_tagihan FROM tagihan_biaya_lain WHERE master_biaya_lain_id = ? ORDER BY id LIMIT 1');
    $stmtSnapshot->bind_param('i', $masterIds[0]);
    $stmtSnapshot->execute();
    $snapshotAfter = $stmtSnapshot->get_result()->fetch_assoc();
    $stmtSnapshot->close();
    fee_publish_assert(
        $snapshotBefore && $snapshotAfter
        && $snapshotBefore['nama_snapshot'] === $snapshotAfter['nama_snapshot']
        && abs((float)$snapshotBefore['nominal_tagihan'] - (float)$snapshotAfter['nominal_tagihan']) < 0.001,
        'Snapshot tagihan Biaya Lain berubah setelah master diubah.'
    );

    $eligibleLevel = (int)$koneksi->query("SELECT COUNT(*) total FROM siswa WHERE is_active=1 AND KELAS='1'")->fetch_assoc()['total'];
    $publish($masterIds[1], 'tingkat', ['tingkat' => 1]);
    $stmt = $koneksi->prepare('SELECT COUNT(*) total FROM tagihan_biaya_lain WHERE master_biaya_lain_id=?');
    $stmt->bind_param('i', $masterIds[1]); $stmt->execute(); $levelCount = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    fee_publish_assert($levelCount === $eligibleLevel, "Target tingkat menghasilkan {$levelCount}, expected {$eligibleLevel}.");

    $eligibleClass = (int)$koneksi->query("SELECT COUNT(*) total FROM siswa WHERE is_active=1 AND master_kelas_id={$classId}")->fetch_assoc()['total'];
    $publish($masterIds[2], 'rombel', ['master_kelas_id' => $classId]);
    $stmt = $koneksi->prepare('SELECT COUNT(*) total FROM tagihan_biaya_lain WHERE master_biaya_lain_id=?');
    $stmt->bind_param('i', $masterIds[2]); $stmt->execute(); $classCount = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    fee_publish_assert($classCount === $eligibleClass, "Target rombel menghasilkan {$classCount}, expected {$eligibleClass}.");

    $publish($masterIds[3], 'siswa', ['no_induk' => [$nis]]);
    $stmt = $koneksi->prepare('SELECT COUNT(*) total FROM tagihan_biaya_lain WHERE master_biaya_lain_id=? AND no_induk=?');
    $stmt->bind_param('is', $masterIds[3], $nis); $stmt->execute(); $studentCount = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    fee_publish_assert($studentCount === 1, 'Target siswa tidak menghasilkan tepat satu tagihan.');
    echo "OK: publish Biaya Lain target all/tingkat/rombel/siswa menghasilkan snapshot tepat sasaran.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($masterIds) {
        $placeholders = implode(',', array_fill(0, count($masterIds), '?'));
        $types = str_repeat('i', count($masterIds));
        $stmt = $koneksi->prepare("DELETE FROM tagihan_biaya_lain WHERE master_biaya_lain_id IN ($placeholders)");
        $stmt->bind_param($types, ...$masterIds); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("DELETE FROM master_biaya_lain WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$masterIds); $stmt->execute(); $stmt->close();
    }
    $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=? AND NAMA=?');
    $stmt->bind_param('ss', $nis, $name); $stmt->execute(); $stmt->close();
}
if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
