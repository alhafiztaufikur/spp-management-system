<?php
// ============================================
// koneksi.php - Database Connection
// ============================================

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Jakarta');

$localConfigPath = __DIR__ . '/config/app.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (!is_array($localConfig)) {
    $localConfig = [];
}

$configValue = static function (string $environmentKey, string $localKey, $default = null) use ($localConfig) {
    $environmentValue = getenv($environmentKey);
    if ($environmentValue !== false && $environmentValue !== '') {
        return $environmentValue;
    }
    return array_key_exists($localKey, $localConfig) ? $localConfig[$localKey] : $default;
};

$dbHost = (string)$configValue('SPP_DB_HOST', 'db_host', '');
$dbPort = (int)$configValue('SPP_DB_PORT', 'db_port', 3306);
$dbUser = (string)$configValue('SPP_DB_USER', 'db_user', '');
$dbPass = (string)$configValue('SPP_DB_PASS', 'db_pass', '');
$dbName = (string)$configValue('SPP_DB_NAME', 'db_name', '');
$appEnvironment = (string)$configValue('SPP_APP_ENV', 'app_env', 'production');

if ($dbHost === '' || $dbUser === '' || $dbName === '' || $dbPort < 1 || $dbPort > 65535) {
    error_log('[SistemSPP] Konfigurasi database belum lengkap.');
    http_response_code(500);
    exit('Layanan sedang tidak tersedia. Hubungi administrator.');
}

if ($appEnvironment === 'production' && $dbPass === '') {
    error_log('[SistemSPP] Password database produksi tidak boleh kosong.');
    http_response_code(500);
    exit('Layanan sedang tidak tersedia. Hubungi administrator.');
}

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_NAME', $dbName);

try {
    $koneksi = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $koneksi->set_charset('utf8mb4');
    $koneksi->query("SET time_zone = '+07:00'");
} catch (mysqli_sql_exception $exception) {
    $correlationId = bin2hex(random_bytes(6));
    error_log(sprintf(
        '[SistemSPP][DB:%s] %s',
        $correlationId,
        $exception->getMessage()
    ));
    http_response_code(500);
    exit('Layanan database sedang tidak tersedia. Kode: ' . $correlationId);
}
