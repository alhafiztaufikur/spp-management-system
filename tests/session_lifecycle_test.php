<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
require_once __DIR__ . '/../includes/security.php';
test_require_disposable_audit_database($koneksi);

function session_lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function session_lifecycle_request(string $url, string $method, array $data, array &$cookies): array
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
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $data ? http_build_query($data) : '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP lifecycle request gagal.');
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

function session_lifecycle_csrf(string $body): string
{
    if (!preg_match('/<form\b[^>]*id="form-login"[^>]*>(.*?)<\/form>/si', $body, $formMatch)
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $formMatch[1], $match)) {
        throw new RuntimeException('Token login lifecycle tidak ditemukan.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function session_lifecycle_login(string $baseUrl, string $username, string $password, array &$cookies): array
{
    $page = session_lifecycle_request($baseUrl . '/login.php', 'GET', [], $cookies);
    session_lifecycle_assert($page['status'] === 200, 'Halaman login lifecycle gagal.');
    $login = session_lifecycle_request($baseUrl . '/login.php', 'POST', [
        'csrf_token' => session_lifecycle_csrf($page['body']),
        'username' => $username,
        'password' => $password,
    ], $cookies);
    session_lifecycle_assert($login['status'] === 302, "Login lifecycle {$username} gagal.");
    return $login;
}

function session_lifecycle_age(string $sessionId, string $field, int $secondsAgo): void
{
    if ($sessionId === '') {
        throw new RuntimeException('Session ID lifecycle kosong.');
    }
    session_id($sessionId);
    session_start();
    $_SESSION[$field] = time() - $secondsAgo;
    session_write_close();
}

$baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$auditPassword = 'Lifecycle-' . bin2hex(random_bytes(16)) . '-Aa9!';
$firstUsername = 'session_audit_' . bin2hex(random_bytes(5));
$secondUsername = 'session_timeout_' . bin2hex(random_bytes(5));
$thirdUsername = 'session_absolute_' . bin2hex(random_bytes(5));
$fourthUsername = 'session_delete_' . bin2hex(random_bytes(5));
$firstId = 0;
$secondId = 0;
$thirdId = 0;
$fourthId = 0;
$failure = null;

try {
    session_lifecycle_assert($baseUrl === 'http://127.0.0.1:8099', 'Lifecycle test hanya memakai server audit 8099.');
    $passwordHash = password_hash($auditPassword, PASSWORD_DEFAULT);
    $insert = $koneksi->prepare("INSERT INTO admin (username,password,nama,role,session_version,password_reset_required) VALUES (?, ?, ?, 'admin', 1, 0)");
    $name = 'UJI SESSION ROLE';
    $insert->bind_param('sss', $firstUsername, $passwordHash, $name);
    $insert->execute();
    $firstId = (int)$koneksi->insert_id;
    $insert->close();

    $firstCookies = [];
    session_lifecycle_login($baseUrl, $firstUsername, $auditPassword, $firstCookies);
    $dashboard = session_lifecycle_request($baseUrl . '/dashboard.php', 'GET', [], $firstCookies);
    session_lifecycle_assert($dashboard['status'] === 200, 'Admin lifecycle tidak dapat membuka dashboard.');

    $newRole = 'kasir';
    $updateRole = $koneksi->prepare('UPDATE admin SET role = ? WHERE id = ?');
    $updateRole->bind_param('si', $newRole, $firstId);
    $updateRole->execute();
    $updateRole->close();
    $roleChanged = session_lifecycle_request($baseUrl . '/dashboard.php', 'GET', [], $firstCookies);
    session_lifecycle_assert(
        $roleChanged['status'] === 302
        && in_array('tabungan/masuk.php', array_map('trim', array_filter(array_map(static function (string $header): string {
            return stripos($header, 'Location:') === 0 ? substr($header, 9) : '';
        }, $roleChanged['headers']))), true),
        'Perubahan role tidak langsung membatasi dashboard admin.'
    );

    $secondName = 'UJI SESSION TIMEOUT';
    $insert = $koneksi->prepare("INSERT INTO admin (username,password,nama,role,session_version,password_reset_required) VALUES (?, ?, ?, 'admin', 1, 0)");
    $insert->bind_param('sss', $secondUsername, $passwordHash, $secondName);
    $insert->execute();
    $secondId = (int)$koneksi->insert_id;
    $insert->close();
    $secondCookies = [];
    session_lifecycle_login($baseUrl, $secondUsername, $auditPassword, $secondCookies);
    session_lifecycle_age($secondCookies['PHPSESSID'] ?? '', '__security_last_seen', SPP_SESSION_IDLE_TIMEOUT + 60);
    $expired = session_lifecycle_request($baseUrl . '/dashboard.php', 'GET', [], $secondCookies);
    session_lifecycle_assert(
        $expired['status'] === 302
        && in_array('login.php', array_map('trim', array_filter(array_map(static function (string $header): string {
            return stripos($header, 'Location:') === 0 ? substr($header, 9) : '';
        }, $expired['headers']))), true),
        'Idle timeout tidak mencabut session yang sudah kedaluwarsa.'
    );

    $thirdName = 'UJI SESSION ABSOLUTE';
    $insert = $koneksi->prepare("INSERT INTO admin (username,password,nama,role,session_version,password_reset_required) VALUES (?, ?, ?, 'admin', 1, 0)");
    $insert->bind_param('sss', $thirdUsername, $passwordHash, $thirdName);
    $insert->execute();
    $thirdId = (int)$koneksi->insert_id;
    $insert->close();
    $thirdCookies = [];
    session_lifecycle_login($baseUrl, $thirdUsername, $auditPassword, $thirdCookies);
    session_lifecycle_age($thirdCookies['PHPSESSID'] ?? '', '__security_created_at', SPP_SESSION_ABSOLUTE_TIMEOUT + 60);
    $absoluteExpired = session_lifecycle_request($baseUrl . '/dashboard.php', 'GET', [], $thirdCookies);
    session_lifecycle_assert(
        $absoluteExpired['status'] === 302
        && in_array('login.php', array_map('trim', array_filter(array_map(static function (string $header): string {
            return stripos($header, 'Location:') === 0 ? substr($header, 9) : '';
        }, $absoluteExpired['headers']))), true),
        'Absolute timeout tidak mencabut session yang sudah melewati umur maksimum.'
    );

    $fourthName = 'UJI SESSION DELETE';
    $insert = $koneksi->prepare("INSERT INTO admin (username,password,nama,role,session_version,password_reset_required) VALUES (?, ?, ?, 'admin', 1, 0)");
    $insert->bind_param('sss', $fourthUsername, $passwordHash, $fourthName);
    $insert->execute();
    $fourthId = (int)$koneksi->insert_id;
    $insert->close();
    $fourthCookies = [];
    session_lifecycle_login($baseUrl, $fourthUsername, $auditPassword, $fourthCookies);
    $delete = $koneksi->prepare('DELETE FROM admin WHERE id = ?');
    $delete->bind_param('i', $fourthId);
    $delete->execute();
    $delete->close();
    $deleted = session_lifecycle_request($baseUrl . '/dashboard.php', 'GET', [], $fourthCookies);
    session_lifecycle_assert(
        $deleted['status'] === 302
        && in_array('login.php', array_map('trim', array_filter(array_map(static function (string $header): string {
            return stripos($header, 'Location:') === 0 ? substr($header, 9) : '';
        }, $deleted['headers']))), true),
        'Penghapusan akun tidak langsung mencabut session aktif.'
    );
    echo "OK: role change, idle timeout, dan penghapusan akun mencabut kewenangan session aktif.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    foreach ([$firstId, $secondId, $thirdId, $fourthId] as $id) {
        if ($id > 0) {
            $cleanup = $koneksi->prepare('DELETE FROM admin WHERE id = ?');
            $cleanup->bind_param('i', $id);
            $cleanup->execute();
            $cleanup->close();
        }
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
