<?php

// Centralized authentication and role authorization.
require_once __DIR__ . '/security.php';

function auth_root_prefix(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    $dir = basename(dirname($script));
    return in_array($dir, ['pembayaran', 'siswa', 'tabungan', 'laporan'], true) ? '../' : '';
}

function auth_invalidate_session(): void
{
    security_destroy_session();
}

function auth_revalidate_account(): ?array
{
    static $checked = false;
    static $account = null;

    if ($checked) {
        return $account;
    }
    $checked = true;

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        return null;
    }

    global $koneksi;
    if (!isset($koneksi) || !($koneksi instanceof mysqli)) {
        throw new LogicException('Database connection must be available before authorization.');
    }

    try {
        $stmt = $koneksi->prepare('SELECT id, username, nama, role, session_version, password_reset_required FROM admin WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    } catch (Throwable $exception) {
        $reference = security_error_reference($exception, 'auth-revalidation');
        http_response_code(500);
        exit('Autentikasi sementara tidak dapat diverifikasi. Referensi: ' . $reference . '.');
    }

    $validRoles = ['admin', 'bendahara', 'kasir'];
    $sessionVersion = (int)($_SESSION['admin_session_version'] ?? 0);
    if (!$account
        || !in_array((string)$account['role'], $validRoles, true)
        || (int)$account['password_reset_required'] !== 0
        || $sessionVersion <= 0
        || $sessionVersion !== (int)$account['session_version']) {
        $account = null;
        auth_invalidate_session();
        return null;
    }

    $_SESSION['admin_username'] = (string)$account['username'];
    $_SESSION['admin_nama'] = (string)$account['nama'];
    $_SESSION['admin_role'] = (string)$account['role'];
    return $account;
}

function requireRole(array $roles): void
{
    $root = auth_root_prefix();
    $account = auth_revalidate_account();
    if (!$account) {
        header('Location: ' . $root . 'login.php');
        exit;
    }

    $currentRole = (string)$account['role'];
    if (!in_array($currentRole, $roles, true)) {
        if ($currentRole === 'kasir') {
            header('Location: ' . $root . 'tabungan/masuk.php');
        } elseif ($currentRole === 'bendahara') {
            header('Location: ' . $root . 'laporan/index.php');
        } else {
            header('Location: ' . $root . 'dashboard.php');
        }
        exit;
    }
}

function requireRoleJson(array $roles): void
{
    header('Content-Type: application/json; charset=utf-8');
    $account = auth_revalidate_account();
    if (!$account) {
        http_response_code(401);
        echo json_encode(['error' => 'Autentikasi diperlukan.']);
        exit;
    }
    if (!in_array((string)$account['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Akses ditolak.']);
        exit;
    }
}

function isRole(string $role): bool
{
    return ($_SESSION['admin_role'] ?? '') === $role;
}

function hasRole(array $roles): bool
{
    return in_array($_SESSION['admin_role'] ?? '', $roles, true);
}
