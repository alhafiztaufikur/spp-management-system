<?php
// ============================================
// koneksi.php - Database Connection
// ============================================

define('DB_HOST', getenv('SPP_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('SPP_DB_USER') ?: 'root');
define('DB_PASS', getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '');
define('DB_NAME', getenv('SPP_DB_NAME') ?: 'db_spp');
$dbPort = filter_var(getenv('SPP_DB_PORT') ?: '3306', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535]
]);
if ($dbPort === false) {
    throw new RuntimeException('Port database tidak valid.');
}

date_default_timezone_set('Asia/Jakarta');

try {
    $koneksi = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $dbPort);
    if ($koneksi->connect_error) {
        throw new RuntimeException($koneksi->connect_error);
    }
    $koneksi->set_charset('utf8mb4');
    $koneksi->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    $koneksi->query("SET time_zone = '+07:00'");
} catch (Throwable $error) {
    error_log('Koneksi database SistemSPP gagal: ' . $error->getMessage());
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(503);
    }
    exit('Layanan database sementara tidak tersedia.');
}
