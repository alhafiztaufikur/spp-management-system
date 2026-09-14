<?php
// ============================================
// koneksi.php - Database Connection
// ============================================

define('DB_HOST', getenv('SPP_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('SPP_DB_USER') ?: 'root');
define('DB_PASS', getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '');
define('DB_NAME', getenv('SPP_DB_NAME') ?: 'db_spp');

date_default_timezone_set('Asia/Jakarta');

$koneksi = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($koneksi->connect_error) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Koneksi database gagal: ' . $koneksi->connect_error
    ]));
}

$koneksi->set_charset('utf8mb4');
$koneksi->query("SET time_zone = '+07:00'");
