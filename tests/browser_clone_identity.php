<?php
// This endpoint is available only to explicitly flagged local audit/isolation tests.
$name = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/http_form_scope.php';
$isolated = null;
try {
    if (getenv('SPP_TEST_ISOLATED_INSTANCE')) {
        $isolated = spp_test_isolated_manifest();
    } elseif (!preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $name)) {
        throw new RuntimeException('Explicit audit target required.');
    }
} catch (Throwable $error) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../koneksi.php';
$actual = (string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0];
if ($actual !== $name) {
    http_response_code(503);
    exit;
}
if ($isolated !== null) {
    try { spp_test_isolated_manifest($koneksi); } catch (Throwable $error) { http_response_code(503); exit; }
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$identity = ['database' => $actual];
if ($isolated !== null) {
    $identity += ['isolated_test' => true, 'server_uuid' => $isolated['server_uuid'],
        'datadir' => $isolated['datadir'], 'port' => (int)$isolated['port']];
}
echo json_encode($identity, JSON_THROW_ON_ERROR);
