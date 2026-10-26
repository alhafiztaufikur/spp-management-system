<?php
// ============================================
// koneksi.php - Database Connection
// ============================================

define('DB_HOST', getenv('SPP_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('SPP_DB_USER') ?: 'root');
define('DB_PASS', getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '');
define('DB_NAME', getenv('SPP_DB_NAME') ?: 'db_spp');
if (PHP_SAPI !== 'cli' && DB_NAME === 'db_spp'
    && is_file(__DIR__ . '/tmp/financial_migration.lock')) {
    http_response_code(503);
    header('Retry-After: 60');
    exit('SistemSPP sedang dalam pemeliharaan. Silakan coba kembali setelah selesai.');
}
$dbPort = filter_var(getenv('SPP_DB_PORT') ?: '3306', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535]
]);
if ($dbPort === false) {
    throw new RuntimeException('Port database tidak valid.');
}
require_once __DIR__.'/includes/database_access.php';
if(PHP_SAPI!=='cli')database_access_http(DB_NAME,(int)$dbPort);

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__.'/includes/date_format.php';

try {
    $reportMeasured=getenv('SPP_REQUEST_METRICS')==='1'||PHP_SAPI!=='cli'&&in_array(str_replace('\\','/',realpath($_SERVER['SCRIPT_FILENAME']??'')?:''),array_map(static fn($path)=>str_replace('\\','/',__DIR__.'/'.$path),['laporan/export_global.php','laporan/export_pdf.php','laporan/export_excel.php','otorisasi_export_pdf.php']),true);
    if($reportMeasured)require_once __DIR__.'/includes/report_metrics.php';
    $koneksi = $reportMeasured?new ReportMeasuredConnection(DB_HOST, DB_USER, DB_PASS, DB_NAME, $dbPort):new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $dbPort);
    if ($koneksi->connect_error) {
        throw new RuntimeException($koneksi->connect_error);
    }
    $koneksi->set_charset('utf8mb4');
    $koneksi->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    $koneksi->query("SET time_zone = '+07:00'");
    require_once __DIR__.'/includes/workflow_readiness.php';
    workflow_require_ready($koneksi);
    require_once __DIR__ . '/includes/units.php';
    unit_bootstrap_context($koneksi);
} catch (Throwable $error) {
    error_log('Koneksi database SistemSPP gagal: ' . $error->getMessage());
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(503);
    }
    exit('Layanan database sementara tidak tersedia.');
}
