<?php
// tabungan/get_saldo.php — AJAX: ambil saldo tabungan siswa
require_once __DIR__ . '/../includes/security.php';
security_bootstrap_session();
require_once '../koneksi.php';
require_once '../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
requireRoleJson(['admin', 'kasir']);

$rawNis = $_GET['nis'] ?? '';
$nis = is_scalar($rawNis) ? trim((string)$rawNis) : '';
$saldo = 0;

if ($nis) {
    $stmt = $koneksi->prepare("SELECT SALDO FROM tabungan WHERE NO_INDUK = ? LIMIT 1");
    $stmt->bind_param('s', $nis);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $saldo = (float)($row['SALDO'] ?? 0);
    $stmt->close();
}

echo json_encode(['saldo' => $saldo]);
