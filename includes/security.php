<?php

declare(strict_types=1);

const SPP_SESSION_IDLE_TIMEOUT = 1800;
const SPP_SESSION_ABSOLUTE_TIMEOUT = 28800;
const SPP_SESSION_REGENERATE_INTERVAL = 900;

/** Read a request field only when it is scalar; list fields must be handled explicitly. */
function security_input_scalar(array $source, string $key, $default = '')
{
    $value = $source[$key] ?? $default;
    return is_scalar($value) ? $value : $default;
}

function security_request_id(): string
{
    static $requestId = null;
    if ($requestId === null) {
        try {
            $requestId = bin2hex(random_bytes(12));
        } catch (Throwable) {
            $requestId = substr(hash('sha256', uniqid('', true)), 0, 24);
        }
    }
    return $requestId;
}

function security_is_https(): bool
{
    return isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

function security_hsts_enabled(): bool
{
    $configured = strtolower(trim((string)(getenv('SPP_ENABLE_HSTS') ?: '')));
    return security_is_https() && in_array($configured, ['1', 'true', 'yes', 'on'], true);
}

function security_send_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Request-ID: ' . security_request_id());
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'");
    header('Cache-Control: no-store');

    if (security_hsts_enabled()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function security_clear_session_cookie(): void
{
    if (!ini_get('session.use_cookies')) {
        return;
    }

    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool)($params['secure'] ?? false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function security_destroy_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        security_clear_session_cookie();
        session_destroy();
    }
}

function security_bootstrap_session(): void
{
    date_default_timezone_set('Asia/Jakarta');
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(static function (Throwable $exception): void {
        $reference = security_error_reference($exception, 'uncaught');
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Terjadi kesalahan sistem. Referensi: ' . $reference . '.';
    });
    security_send_headers();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', security_is_https() ? '1' : '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => security_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    $now = time();
    $createdAt = (int)($_SESSION['__security_created_at'] ?? $now);
    $lastSeen = (int)($_SESSION['__security_last_seen'] ?? $now);
    $expired = ($now - $lastSeen) > SPP_SESSION_IDLE_TIMEOUT
        || ($now - $createdAt) > SPP_SESSION_ABSOLUTE_TIMEOUT;

    if ($expired) {
        security_destroy_session();
        session_start();
        $createdAt = $now;
    }

    $_SESSION['__security_created_at'] = $createdAt;
    $_SESSION['__security_last_seen'] = $now;

    $lastRegeneration = (int)($_SESSION['__security_regenerated_at'] ?? $now);
    if (($now - $lastRegeneration) >= SPP_SESSION_REGENERATE_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['__security_regenerated_at'] = $now;
    } elseif (!isset($_SESSION['__security_regenerated_at'])) {
        $_SESSION['__security_regenerated_at'] = $now;
    }
}

function security_rotate_after_login(): void
{
    session_regenerate_id(true);
    $now = time();
    $_SESSION['__security_created_at'] = $now;
    $_SESSION['__security_last_seen'] = $now;
    $_SESSION['__security_regenerated_at'] = $now;
    unset($_SESSION['__security_csrf']);
}

function security_csrf_token(string $scope): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new LogicException('Session must be active before generating a CSRF token.');
    }

    if (!isset($_SESSION['__security_csrf']) || !is_array($_SESSION['__security_csrf'])) {
        $_SESSION['__security_csrf'] = [];
    }

    if (empty($_SESSION['__security_csrf'][$scope])) {
        $_SESSION['__security_csrf'][$scope] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['__security_csrf'][$scope];
}

function security_csrf_is_valid(string $scope, mixed $submittedToken): bool
{
    $expected = $_SESSION['__security_csrf'][$scope] ?? '';
    return is_string($submittedToken)
        && $submittedToken !== ''
        && is_string($expected)
        && $expected !== ''
        && hash_equals($expected, $submittedToken);
}

function security_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('Metode permintaan tidak diizinkan.');
    }
}

function security_require_csrf(string $scope): void
{
    if (!security_csrf_is_valid($scope, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Permintaan tidak valid atau sesi telah kedaluwarsa.');
    }
}

function security_error_reference(Throwable $exception, string $context): string
{
    $reference = security_request_id();

    error_log(sprintf(
        '[SistemSPP][%s][%s] %s in %s:%d',
        $reference,
        $context,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    return $reference;
}

function security_exception_message(Throwable $exception, string $fallback, string $context): string
{
    if ($exception instanceof mysqli_sql_exception || $exception instanceof Error) {
        $reference = security_error_reference($exception, $context);
        return $fallback . ' Referensi: ' . $reference . '.';
    }

    return $exception->getMessage();
}
