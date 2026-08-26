<?php
// ============================================
// index.php - Entry Point
// ============================================
require_once __DIR__ . '/includes/security.php';
security_bootstrap_session();
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
