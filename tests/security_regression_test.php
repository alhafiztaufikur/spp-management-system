<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/login_rate_limit.php';
test_require_audit_database($koneksi);

function security_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function security_test_request(string $url, string $method, array $data, array &$cookies): array
{
    $headers = [];
    if ($data) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            if ($value !== '') {
                $pairs[] = $name . '=' . $value;
            }
        }
        if ($pairs) {
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $data ? http_build_query($data) : '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP request ke server audit gagal.');
    }

    $status = 0;
    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $header) {
        $responseHeaders[] = $header;
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) {
            $status = (int)$match[1];
        }
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
    }

    return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
}

function security_test_header(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }
    return null;
}

function security_test_csrf(string $body, ?string $formId = null): string
{
    $subject = $body;
    if ($formId !== null && preg_match('/<form\b[^>]*id="' . preg_quote($formId, '/') . '"[^>]*>(.*?)<\/form>/si', $body, $formMatch)) {
        $subject = $formMatch[1];
    }
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $subject, $match)) {
        throw new RuntimeException('Token CSRF tidak ditemukan pada halaman audit.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

$baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$username = (string)getenv('SPP_TEST_ADMIN_USERNAME');
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
security_test_assert($baseUrl === 'http://127.0.0.1:8099', 'Security test hanya boleh memakai server audit http://127.0.0.1:8099.');
security_test_assert($username !== '' && $password !== '', 'Kredensial akun audit wajib disediakan lewat environment.');

$stmtAudit = $koneksi->prepare('SELECT id, session_version FROM admin WHERE username = ? AND role = \'admin\' LIMIT 1');
$stmtAudit->bind_param('s', $username);
$stmtAudit->execute();
$auditAccount = $stmtAudit->get_result()->fetch_assoc();
$stmtAudit->close();
security_test_assert((bool)$auditAccount, 'Akun administrator audit tidak ditemukan.');
$auditId = (int)$auditAccount['id'];
$originalSessionVersion = (int)$auditAccount['session_version'];

$legacyUsername = 'legacy_audit_' . bin2hex(random_bytes(5));
$legacyPassword = 'LegacyAudit-Only-2026!';
$legacyHash = md5($legacyPassword);
$legacyId = 0;
$ratePrefix = 'rate_audit_' . bin2hex(random_bytes(5));
$accountProbeUsername = 'account_audit_' . bin2hex(random_bytes(5));
$legacyRateBuckets = [];
$rateBuckets = [];
$accountProbeBuckets = [];
$sessionVersionChanged = false;
$failure = null;

try {
    $originalHttps = $_SERVER['HTTPS'] ?? null;
    $_SERVER['HTTPS'] = 'on';
    putenv('SPP_ENABLE_HSTS');
    security_test_assert(!security_hsts_enabled(), 'HSTS aktif tanpa opt-in eksplisit.');
    putenv('SPP_ENABLE_HSTS=1');
    security_test_assert(security_hsts_enabled(), 'HSTS tidak dapat diaktifkan secara eksplisit pada HTTPS.');
    putenv('SPP_ENABLE_HSTS');
    if ($originalHttps === null) {
        unset($_SERVER['HTTPS']);
    } else {
        $_SERVER['HTTPS'] = $originalHttps;
    }

    $bucketsIdentityA = login_rate_limit_buckets('audit-identity-a', '127.0.0.1');
    $bucketsIdentityB = login_rate_limit_buckets('audit-identity-b', '127.0.0.1');
    $bucketsSourceB = login_rate_limit_buckets('audit-identity-a', '127.0.0.2');
    security_test_assert(
        $bucketsIdentityA['account'] === $bucketsSourceB['account']
        && $bucketsIdentityA['source'] === $bucketsIdentityB['source']
        && $bucketsIdentityA['pair'] !== $bucketsIdentityB['pair']
        && $bucketsIdentityA['pair'] !== $bucketsSourceB['pair'],
        'Bucket rate-limit belum memisahkan domain akun, source, dan pasangan.'
    );

    $inboundRequestId = str_repeat('a', 24);
    $_SERVER['HTTP_X_REQUEST_ID'] = $inboundRequestId;
    $localRequestId = security_request_id();
    security_test_assert(
        preg_match('/^[a-f0-9]{24}$/', $localRequestId) === 1
        && $localRequestId !== $inboundRequestId
        && security_request_id() === $localRequestId,
        'Request ID tidak dibuat lokal atau masih mempercayai header inbound.'
    );
    unset($_SERVER['HTTP_X_REQUEST_ID']);

    $rateColumns = $koneksi->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_rate_limit'"
    )->fetch_all(MYSQLI_ASSOC);
    $rateColumnNames = array_column($rateColumns, 'COLUMN_NAME');
    security_test_assert(
        in_array('bucket_hash', $rateColumnNames, true)
        && !in_array('username', $rateColumnNames, true)
        && !in_array('source', $rateColumnNames, true)
        && !in_array('password', $rateColumnNames, true),
        'Schema rate-limit menyimpan identifier atau password plaintext.'
    );
    $rateLimitSource = file_get_contents(__DIR__ . '/../includes/login_rate_limit.php');
    security_test_assert($rateLimitSource !== false && str_contains($rateLimitSource, 'LIMIT 100'), 'Cleanup rate-limit belum dibatasi per request.');

    $cookies = [];
    $loginPage = security_test_request($baseUrl . '/login.php', 'GET', [], $cookies);
    security_test_assert($loginPage['status'] === 200, 'Halaman login audit tidak mengembalikan HTTP 200.');
    security_test_assert(security_test_header($loginPage['headers'], 'X-Content-Type-Options') === 'nosniff', 'Header nosniff tidak aktif.');
    security_test_assert(security_test_header($loginPage['headers'], 'X-Frame-Options') === 'DENY', 'Header anti-framing tidak aktif.');
    security_test_assert(security_test_header($loginPage['headers'], 'Referrer-Policy') === 'same-origin', 'Referrer-Policy tidak aktif.');
    security_test_assert(security_test_header($loginPage['headers'], 'Content-Security-Policy') !== null, 'Content-Security-Policy tidak aktif.');
    security_test_assert(security_test_header($loginPage['headers'], 'Strict-Transport-Security') === null, 'HSTS tidak boleh aktif default pada server HTTP audit.');
    security_test_assert(security_test_header($loginPage['headers'], 'X-Powered-By') === null, 'X-Powered-By masih membocorkan versi PHP.');
    security_test_assert(preg_match('/^[a-f0-9]{24}$/', (string)security_test_header($loginPage['headers'], 'X-Request-ID')) === 1, 'X-Request-ID tidak tersedia atau formatnya tidak valid.');
    $cookieHeader = implode("\n", array_filter($loginPage['headers'], static fn(string $header): bool => stripos($header, 'Set-Cookie:') === 0));
    security_test_assert(stripos($cookieHeader, 'HttpOnly') !== false, 'Cookie session belum HttpOnly.');
    security_test_assert(stripos($cookieHeader, 'SameSite=Lax') !== false, 'Cookie session belum SameSite=Lax.');

    $missingLoginCsrf = security_test_request($baseUrl . '/login.php', 'POST', [
        'username' => $username,
        'password' => $password,
    ], $cookies);
    security_test_assert($missingLoginCsrf['status'] === 403, 'Login tanpa CSRF tidak ditolak dengan HTTP 403.');
    security_test_assert(
        security_test_header($missingLoginCsrf['headers'], 'X-Request-ID') !== security_test_header($loginPage['headers'], 'X-Request-ID'),
        'X-Request-ID tidak berganti antar-request.'
    );

    $loginToken = security_test_csrf($loginPage['body'], 'form-login');
    $preLoginSession = $cookies['PHPSESSID'] ?? '';
    $login = security_test_request($baseUrl . '/login.php', 'POST', [
        'csrf_token' => $loginToken,
        'username' => $username,
        'password' => $password,
    ], $cookies);
    security_test_assert($login['status'] === 302, 'Login audit dengan CSRF valid gagal.');
    security_test_assert(($cookies['PHPSESSID'] ?? '') !== '' && ($cookies['PHPSESSID'] ?? '') !== $preLoginSession, 'Session ID tidak dirotasi setelah login.');

    $dashboard = security_test_request($baseUrl . '/dashboard.php', 'GET', [], $cookies);
    security_test_assert($dashboard['status'] === 200, 'Session login audit tidak dapat membuka dashboard.');
    $logoutToken = security_test_csrf($dashboard['body']);

    $getLogout = security_test_request($baseUrl . '/logout.php', 'GET', [], $cookies);
    security_test_assert($getLogout['status'] === 405, 'Logout melalui GET belum ditolak dengan HTTP 405.');
    $missingLogoutCsrf = security_test_request($baseUrl . '/logout.php', 'POST', [], $cookies);
    security_test_assert($missingLogoutCsrf['status'] === 403, 'Logout tanpa CSRF belum ditolak dengan HTTP 403.');
    $stillAuthenticated = security_test_request($baseUrl . '/dashboard.php', 'GET', [], $cookies);
    security_test_assert($stillAuthenticated['status'] === 200, 'CSRF logout yang ditolak justru menghapus session.');

    $getDelete = security_test_request($baseUrl . '/pembayaran/proses.php?aksi=hapus&id=1', 'GET', [], $cookies);
    security_test_assert($getDelete['status'] === 405, 'Endpoint pembayaran masih menerima GET untuk mutasi.');

    $paymentCountBefore = (int)$koneksi->query('SELECT COUNT(*) AS total FROM bayar')->fetch_assoc()['total'];
    $savingsCountBefore = (int)$koneksi->query('SELECT (SELECT COUNT(*) FROM transaksi_m) + (SELECT COUNT(*) FROM transaksi_k) AS total')->fetch_assoc()['total'];
    $paymentWithoutCsrf = security_test_request($baseUrl . '/pembayaran/proses.php', 'POST', ['aksi' => 'hapus', 'id' => 1], $cookies);
    security_test_assert($paymentWithoutCsrf['status'] === 403, 'Mutasi pembayaran tanpa CSRF belum ditolak.');
    $savingsWithoutCsrf = security_test_request($baseUrl . '/tabungan/proses.php', 'POST', [
        'aksi' => 'masuk', 'no_induk' => '9999999999', 'nominal' => 1000,
    ], $cookies);
    security_test_assert($savingsWithoutCsrf['status'] === 403, 'Mutasi tabungan tanpa CSRF belum ditolak.');
    $paymentCountAfter = (int)$koneksi->query('SELECT COUNT(*) AS total FROM bayar')->fetch_assoc()['total'];
    $savingsCountAfter = (int)$koneksi->query('SELECT (SELECT COUNT(*) FROM transaksi_m) + (SELECT COUNT(*) FROM transaksi_k) AS total')->fetch_assoc()['total'];
    security_test_assert($paymentCountAfter === $paymentCountBefore && $savingsCountAfter === $savingsCountBefore, 'Penolakan CSRF masih mengubah data keuangan.');

    $testSessionVersion = $originalSessionVersion + 1;
    $stmtBump = $koneksi->prepare('UPDATE admin SET session_version = ? WHERE id = ? AND session_version = ?');
    $stmtBump->bind_param('iii', $testSessionVersion, $auditId, $originalSessionVersion);
    $stmtBump->execute();
    security_test_assert($stmtBump->affected_rows === 1, 'Session version akun audit berubah di luar test; revocation test dibatalkan.');
    $sessionVersionChanged = true;
    $stmtBump->close();
    $revoked = security_test_request($baseUrl . '/dashboard.php', 'GET', [], $cookies);
    security_test_assert($revoked['status'] === 302 && security_test_header($revoked['headers'], 'Location') === 'login.php', 'Perubahan session_version belum mencabut session aktif.');

    $stmtLegacy = $koneksi->prepare("INSERT INTO admin (username, password, nama, role, password_reset_required) VALUES (?, ?, 'Legacy Audit', 'kasir', 1)");
    $stmtLegacy->bind_param('ss', $legacyUsername, $legacyHash);
    $stmtLegacy->execute();
    $legacyId = (int)$koneksi->insert_id;
    $stmtLegacy->close();

    $legacyCookies = [];
    $legacyLoginPage = security_test_request($baseUrl . '/login.php', 'GET', [], $legacyCookies);
    $legacyToken = security_test_csrf($legacyLoginPage['body'], 'form-login');
    $legacyLogin = security_test_request($baseUrl . '/login.php', 'POST', [
        'csrf_token' => $legacyToken,
        'username' => $legacyUsername,
        'password' => $legacyPassword,
    ], $legacyCookies);
    security_test_assert($legacyLogin['status'] === 200, 'Akun MD5 legacy tidak mengikuti penolakan login generik.');
    security_test_assert(str_contains($legacyLogin['body'], 'Username atau password salah'), 'Akun MD5 legacy tidak menerima pesan kredensial generik.');
    $legacyRateBuckets = array_values(login_rate_limit_buckets($legacyUsername, '127.0.0.1'));
    login_rate_limit_clear($koneksi, $legacyRateBuckets);
    $legacyRateBuckets = [];

    $rateCookies = [];
    $ratePage = security_test_request($baseUrl . '/login.php', 'GET', [], $rateCookies);
    $rateToken = security_test_csrf($ratePage['body'], 'form-login');
    $rateResponse = null;
    for ($attempt = 1; $attempt <= SPP_LOGIN_MAX_FAILURES; $attempt++) {
        $sprayUsername = $ratePrefix . '_' . $attempt;
        $rateBuckets = array_merge($rateBuckets, array_values(login_rate_limit_buckets($sprayUsername, '127.0.0.1')));
        $rateResponse = security_test_request($baseUrl . '/login.php', 'POST', [
            'csrf_token' => $rateToken,
            'username' => $sprayUsername,
            'password' => 'Wrong-Password-Only!',
        ], $rateCookies);
    }
    security_test_assert($rateResponse !== null && $rateResponse['status'] === 429, 'Percobaan source-wide kelima belum mengaktifkan throttling.');
    security_test_assert(str_contains($rateResponse['body'], 'Username atau password salah'), 'Respons throttling tidak memakai pesan login generik.');
    security_test_assert(security_test_header($rateResponse['headers'], 'Retry-After') !== null, 'Respons throttling belum memiliki Retry-After.');
    $sourceBucket = login_rate_limit_buckets($ratePrefix . '_1', '127.0.0.1')['source'];
    $stmtRate = $koneksi->prepare('SELECT failure_count FROM login_rate_limit WHERE bucket_hash = ?');
    $stmtRate->bind_param('s', $sourceBucket);
    $stmtRate->execute();
    $rateRow = $stmtRate->get_result()->fetch_assoc();
    $stmtRate->close();
    security_test_assert($rateRow && (int)$rateRow['failure_count'] >= SPP_LOGIN_MAX_FAILURES, 'Bucket source-wide tidak mengakumulasi pergantian identity.');
    login_rate_limit_clear($koneksi, $rateBuckets);
    $rateBuckets = [];

    $accountBlocked = false;
    for ($attempt = 1; $attempt <= SPP_LOGIN_MAX_FAILURES; $attempt++) {
        $attemptBuckets = login_rate_limit_buckets($accountProbeUsername, '198.51.100.' . $attempt);
        $accountProbeBuckets = array_merge($accountProbeBuckets, array_values($attemptBuckets));
        $accountState = login_rate_limit_lock($koneksi, $attemptBuckets);
        $accountBlocked = login_rate_limit_record_failure($koneksi, $accountState['failure_counts']);
        $koneksi->commit();
    }
    security_test_assert($accountBlocked, 'Bucket account-wide belum memblokir pergantian source.');
    $accountBucket = login_rate_limit_buckets($accountProbeUsername, '198.51.100.1')['account'];
    $stmtRate = $koneksi->prepare('SELECT failure_count FROM login_rate_limit WHERE bucket_hash = ?');
    $stmtRate->bind_param('s', $accountBucket);
    $stmtRate->execute();
    $accountRow = $stmtRate->get_result()->fetch_assoc();
    $stmtRate->close();
    security_test_assert($accountRow && (int)$accountRow['failure_count'] >= SPP_LOGIN_MAX_FAILURES, 'Bucket account-wide tidak mengakumulasi pergantian source.');
    login_rate_limit_clear($koneksi, $accountProbeBuckets);
    $accountProbeBuckets = [];

    $roleSource = file_get_contents(__DIR__ . '/../role_management.php');
    $transactionPosition = $roleSource !== false ? strpos($roleSource, 'begin_transaction()') : false;
    $adminLockPosition = $roleSource !== false ? strpos($roleSource, "WHERE role = 'admin' ORDER BY id FOR UPDATE") : false;
    $deletePosition = $roleSource !== false ? strpos($roleSource, 'DELETE FROM admin WHERE id = ?') : false;
    security_test_assert(
        $roleSource !== false
        && $transactionPosition !== false
        && $adminLockPosition !== false
        && $deletePosition !== false
        && $transactionPosition < $adminLockPosition
        && $adminLockPosition < $deletePosition,
        'Guard transaksional last-admin tidak ditemukan.'
    );

    $freshCookies = [];
    $freshPage = security_test_request($baseUrl . '/login.php', 'GET', [], $freshCookies);
    $freshToken = security_test_csrf($freshPage['body'], 'form-login');
    $freshLogin = security_test_request($baseUrl . '/login.php', 'POST', [
        'csrf_token' => $freshToken,
        'username' => $username,
        'password' => $password,
    ], $freshCookies);
    security_test_assert($freshLogin['status'] === 302, 'Login ulang setelah revokasi session gagal.');
    $freshDashboard = security_test_request($baseUrl . '/dashboard.php', 'GET', [], $freshCookies);
    $freshLogoutToken = security_test_csrf($freshDashboard['body']);
    $logout = security_test_request($baseUrl . '/logout.php', 'POST', ['csrf_token' => $freshLogoutToken], $freshCookies);
    security_test_assert($logout['status'] === 302 && security_test_header($logout['headers'], 'Location') === 'login.php', 'Logout POST dengan CSRF valid gagal.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        $koneksi->rollback();
    } catch (Throwable) {
        // Pastikan lock test tidak tertinggal bila assertion gagal.
    }
    if ($sessionVersionChanged) {
        $testSessionVersion = $originalSessionVersion + 1;
        $stmtRestore = $koneksi->prepare('UPDATE admin SET session_version = ? WHERE id = ? AND session_version = ?');
        $stmtRestore->bind_param('iii', $originalSessionVersion, $auditId, $testSessionVersion);
        $stmtRestore->execute();
        $stmtRestore->close();
    }
    if ($legacyId > 0) {
        $stmtCleanup = $koneksi->prepare('DELETE FROM admin WHERE id = ?');
        $stmtCleanup->bind_param('i', $legacyId);
        $stmtCleanup->execute();
        $stmtCleanup->close();
    }
    $remainingRateBuckets = array_values(array_unique(array_merge($legacyRateBuckets, $rateBuckets, $accountProbeBuckets)));
    foreach ($remainingRateBuckets as $bucketToDelete) {
        $stmtRateCleanup = $koneksi->prepare('DELETE FROM login_rate_limit WHERE bucket_hash = ?');
        $stmtRateCleanup->bind_param('s', $bucketToDelete);
        $stmtRateCleanup->execute();
        $stmtRateCleanup->close();
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "PASS: request ID, header/cookie/HSTS, CSRF, POST-only, revokasi, MD5 block, multi-bucket throttling, last-admin guard, dan logout aman.\n";
