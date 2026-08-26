<?php

declare(strict_types=1);

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';

if (!function_exists('test_require_disposable_audit_database')) {
    throw new RuntimeException('Guard database disposable belum tersedia.');
}
test_require_disposable_audit_database($koneksi);

function role_audit_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function role_audit_request(string $url, string $method, array $data, array &$cookies): array
{
    $headers = [];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $method === 'POST' ? http_build_query($data) : '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP request Role Management gagal.');
    }

    $status = 0;
    $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) {
            $status = (int)$match[1];
        }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
}

function role_audit_csrf(string $body, string $formId): string
{
    if (!preg_match('/<form\b[^>]*id="' . preg_quote($formId, '/') . '"[^>]*>(.*?)<\/form>/si', $body, $form)
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $form[1], $match)) {
        throw new RuntimeException('Token CSRF Role Management tidak ditemukan.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

$baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$loginUsername = (string)getenv('SPP_TEST_ADMIN_USERNAME');
$loginPassword = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
role_audit_assert($baseUrl === 'http://127.0.0.1:8099', 'Tes Role Management hanya boleh memakai server audit :8099.');
role_audit_assert($loginUsername !== '' && $loginPassword !== '', 'Kredensial admin audit wajib disediakan melalui environment.');

$suffix = bin2hex(random_bytes(5));
$targetUsername = 'role_audit_' . $suffix;
$targetName = 'UJI AUDIT AKUN ' . strtoupper($suffix);
$initialPassword = 'Initial-' . $suffix . '-Aa1!';
$replacementPassword = 'Replacement-' . $suffix . '-Bb2!';
$targetId = 0;
$failure = null;

try {
    $stmtActor = $koneksi->prepare('SELECT id FROM admin WHERE username = ? AND role = \'admin\' LIMIT 1');
    $stmtActor->bind_param('s', $loginUsername);
    $stmtActor->execute();
    $actor = $stmtActor->get_result()->fetch_assoc();
    $stmtActor->close();
    role_audit_assert((bool)$actor, 'Admin pelaksana test Role Management tidak ditemukan.');
    $actorId = (int)$actor['id'];

    $cookies = [];
    $loginPage = role_audit_request($baseUrl . '/login.php', 'GET', [], $cookies);
    $loginToken = role_audit_csrf($loginPage['body'], 'form-login');
    $login = role_audit_request($baseUrl . '/login.php', 'POST', [
        'csrf_token' => $loginToken,
        'username' => $loginUsername,
        'password' => $loginPassword,
    ], $cookies);
    role_audit_assert($login['status'] === 302, 'Login admin untuk test Role Management gagal.');

    $rolePage = role_audit_request($baseUrl . '/role_management.php', 'GET', [], $cookies);
    role_audit_assert($rolePage['status'] === 200, 'Halaman Role Management tidak dapat dibuka.');
    $csrfToken = role_audit_csrf($rolePage['body'], 'form-tambah-akun');

    $create = role_audit_request($baseUrl . '/role_management.php', 'POST', [
        'csrf_token' => $csrfToken,
        'aksi' => 'tambah',
        'nama' => $targetName,
        'username' => $targetUsername,
        'role' => 'kasir',
        'password' => $initialPassword,
        'password_confirmation' => $initialPassword,
    ], $cookies);
    role_audit_assert($create['status'] === 302, 'Pembuatan akun uji tidak mengikuti redirect.');

    $stmtTarget = $koneksi->prepare('SELECT id, password, password_reset_required, session_version FROM admin WHERE username = ?');
    $stmtTarget->bind_param('s', $targetUsername);
    $stmtTarget->execute();
    $target = $stmtTarget->get_result()->fetch_assoc();
    $stmtTarget->close();
    role_audit_assert((bool)$target && password_verify($initialPassword, $target['password']), 'Akun uji tidak dibuat dengan password_hash modern.');
    $targetId = (int)$target['id'];
    $initialVersion = (int)$target['session_version'];
    $initialHash = (string)$target['password'];

    $missingResetReason = role_audit_request($baseUrl . '/role_management.php', 'POST', [
        'csrf_token' => $csrfToken,
        'aksi' => 'reset_password',
        'account_id' => $targetId,
        'new_password' => $replacementPassword,
        'new_password_confirmation' => $replacementPassword,
    ], $cookies);
    role_audit_assert($missingResetReason['status'] === 302, 'Reset tanpa alasan tidak mengikuti alur penolakan.');
    $stmtUnchanged = $koneksi->prepare('SELECT password, session_version FROM admin WHERE id = ?');
    $stmtUnchanged->bind_param('i', $targetId);
    $stmtUnchanged->execute();
    $unchanged = $stmtUnchanged->get_result()->fetch_assoc();
    $stmtUnchanged->close();
    role_audit_assert($unchanged['password'] === $initialHash && (int)$unchanged['session_version'] === $initialVersion, 'Reset tanpa alasan tetap mengubah akun.');

    $resetReason = 'Rotasi kredensial akun fixture audit';
    $reset = role_audit_request($baseUrl . '/role_management.php', 'POST', [
        'csrf_token' => $csrfToken,
        'aksi' => 'reset_password',
        'account_id' => $targetId,
        'new_password' => $replacementPassword,
        'new_password_confirmation' => $replacementPassword,
        'audit_reason' => $resetReason,
    ], $cookies);
    role_audit_assert($reset['status'] === 302, 'Reset password dengan alasan tidak mengikuti redirect.');
    $stmtReset = $koneksi->prepare('SELECT password, password_reset_required, session_version FROM admin WHERE id = ?');
    $stmtReset->bind_param('i', $targetId);
    $stmtReset->execute();
    $resetTarget = $stmtReset->get_result()->fetch_assoc();
    $stmtReset->close();
    role_audit_assert(
        password_verify($replacementPassword, $resetTarget['password'])
        && (int)$resetTarget['password_reset_required'] === 0
        && (int)$resetTarget['session_version'] === $initialVersion + 1,
        'Reset password tidak memperbarui hash, marker, atau session_version secara atomik.'
    );

    $missingDeleteReason = role_audit_request($baseUrl . '/role_management.php', 'POST', [
        'csrf_token' => $csrfToken,
        'aksi' => 'hapus',
        'account_id' => $targetId,
    ], $cookies);
    role_audit_assert($missingDeleteReason['status'] === 302, 'Delete tanpa alasan tidak mengikuti alur penolakan.');
    $stmtStillThere = $koneksi->prepare('SELECT COUNT(*) AS total FROM admin WHERE id = ?');
    $stmtStillThere->bind_param('i', $targetId);
    $stmtStillThere->execute();
    $stillThere = (int)$stmtStillThere->get_result()->fetch_assoc()['total'];
    $stmtStillThere->close();
    role_audit_assert($stillThere === 1, 'Delete tanpa alasan tetap menghapus akun.');

    $deleteReason = 'Akun fixture selesai digunakan dalam pengujian';
    $delete = role_audit_request($baseUrl . '/role_management.php', 'POST', [
        'csrf_token' => $csrfToken,
        'aksi' => 'hapus',
        'account_id' => $targetId,
        'audit_reason' => $deleteReason,
    ], $cookies);
    role_audit_assert($delete['status'] === 302, 'Delete akun dengan alasan tidak mengikuti redirect.');
    $remainingAccount = (int)$koneksi->query('SELECT COUNT(*) AS total FROM admin WHERE id = ' . $targetId)->fetch_assoc()['total'];
    role_audit_assert($remainingAccount === 0, 'Akun uji tidak terhapus setelah audit berhasil ditulis.');

    $targetIdText = (string)$targetId;
    $stmtEvents = $koneksi->prepare(
        "SELECT event_type, actor_admin_id, request_id, reason, before_data, after_data, metadata
         FROM audit_event WHERE entity_type = 'admin' AND entity_id = ? ORDER BY id"
    );
    $stmtEvents->bind_param('s', $targetIdText);
    $stmtEvents->execute();
    $events = $stmtEvents->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtEvents->close();
    role_audit_assert(array_column($events, 'event_type') === [
        'account.created',
        'account.password_reset',
        'account.deleted',
    ], 'Lifecycle akun tidak menghasilkan tepat tiga event audit berurutan.');
    role_audit_assert(count(array_unique(array_column($events, 'request_id'))) === 3, 'Correlation ID event lifecycle akun tidak unik per request.');
    foreach ($events as $event) {
        $serialized = implode('|', $event);
        role_audit_assert((int)$event['actor_admin_id'] === $actorId, 'Actor event akun tidak sesuai admin login.');
        role_audit_assert(!str_contains($serialized, $initialPassword) && !str_contains($serialized, $replacementPassword), 'Password plaintext bocor ke audit akun.');
        role_audit_assert(preg_match('/^[a-f0-9]{24}$/', (string)$event['request_id']) === 1, 'Request ID event akun tidak valid.');
    }
    role_audit_assert($events[1]['reason'] === $resetReason && $events[2]['reason'] === $deleteReason, 'Alasan reset/delete akun tidak tersimpan utuh.');
    $resetBefore = json_decode((string)$events[1]['before_data'], true, 512, JSON_THROW_ON_ERROR);
    $resetAfter = json_decode((string)$events[1]['after_data'], true, 512, JSON_THROW_ON_ERROR);
    role_audit_assert(
        !array_key_exists('password', $resetBefore)
        && !array_key_exists('password', $resetAfter)
        && (int)$resetAfter['session_version'] === (int)$resetBefore['session_version'] + 1,
        'Snapshot reset menyimpan password atau tidak mencatat revokasi sesi.'
    );
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($targetId > 0) {
        $stmtCleanup = $koneksi->prepare('DELETE FROM admin WHERE id = ?');
        $stmtCleanup->bind_param('i', $targetId);
        $stmtCleanup->execute();
        $stmtCleanup->close();
    } else {
        $stmtCleanup = $koneksi->prepare('DELETE FROM admin WHERE username = ?');
        $stmtCleanup->bind_param('s', $targetUsername);
        $stmtCleanup->execute();
        $stmtCleanup->close();
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "OK: lifecycle akun create/reset/delete atomik, beralasan, ter-audit, dan tanpa password plaintext.\n";
