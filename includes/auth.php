<?php
// ============================================
// includes/auth.php — Role-based Access Control
// ============================================

/**
 * Pastikan user sudah login dan memiliki role yang diizinkan.
 * Jika tidak, redirect ke halaman yang sesuai.
 *
 * @param array $roles  Contoh: ['admin'], atau ['admin','bendahara']
 */
function requireRole(array $roles): void {
    if(PHP_SAPI!=='cli'&&defined('DB_NAME')&&function_exists('database_access_session'))database_access_session(DB_NAME,(int)(getenv('SPP_DB_PORT')?:3306));
    $dir  = basename(dirname($_SERVER['PHP_SELF']));
    $root = in_array($dir, ['pembayaran', 'siswa', 'tabungan', 'laporan'], true) ? '../' : '';

    if (!isset($_SESSION['admin_id'])) {
        header('Location: ' . $root . 'login.php');
        exit;
    }

    $currentRole = $_SESSION['admin_role'] ?? '';
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['role_management.php','otorisasi_transaksi.php','otorisasi_detail.php','otorisasi_aktivitas.php','otorisasi_export_pdf.php'], true)
        && $currentRole !== 'super_admin') {
        http_response_code(403);
        exit('Hanya Super Admin yang dapat mengakses halaman ini.');
    }
    if (!in_array($currentRole, ['super_admin', 'admin', 'bendahara', 'kasir'], true)) {
        session_unset();
        session_destroy();
        header('Location: ' . $root . 'login.php');
        exit;
    }

    if (!in_array($currentRole, $roles, true) && !($currentRole === 'super_admin' && in_array('admin', $roles, true))) {
        // Redirect ke halaman default sesuai role
        if ($currentRole === 'kasir') {
            header('Location: ' . $root . 'tabungan/masuk.php');
        } elseif ($currentRole === 'bendahara') {
            header('Location: ' . $root . 'laporan/index.php');
        } else {
            header('Location: ' . $root . 'dashboard.php');
        }
        exit;
    }
    if (function_exists('unit_guard_request')) unit_guard_request();
}

/**
 * Cek apakah user yang sedang login memiliki role tertentu.
 */
function isRole(string $role): bool {
    return ($_SESSION['admin_role'] ?? '') === $role
        || ($role === 'admin' && ($_SESSION['admin_role'] ?? '') === 'super_admin');
}

/**
 * Cek apakah user memiliki salah satu dari beberapa role.
 */
function hasRole(array $roles): bool {
    return in_array($_SESSION['admin_role'] ?? '', $roles, true)
        || (($_SESSION['admin_role'] ?? '') === 'super_admin' && in_array('admin', $roles, true));
}
