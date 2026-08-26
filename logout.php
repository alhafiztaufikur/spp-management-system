<?php
// ============================================
// logout.php
// ============================================
require_once __DIR__ . '/includes/security.php';
security_bootstrap_session();
security_require_post();
security_require_csrf('logout');
security_destroy_session();
header('Location: login.php');
exit;
