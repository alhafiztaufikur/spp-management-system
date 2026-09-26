<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$database = 'db_spp_schema_install_test';
$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$pass = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$db = new mysqli($host, $user, $pass);
$db->set_charset('utf8mb4');

try {
    $db->query("DROP DATABASE IF EXISTS `{$database}`");
    $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
    if ($sql === false) throw new RuntimeException('sql/schema.sql tidak dapat dibaca.');
    $sql = str_replace(
        ['CREATE DATABASE IF NOT EXISTS `db_spp`', 'USE `db_spp`'],
        ["CREATE DATABASE IF NOT EXISTS `{$database}`", "USE `{$database}`"],
        $sql,
        $replacementCount
    );
    if ($replacementCount !== 2) throw new RuntimeException('Kontrak nama database pada schema.sql berubah.');

    $db->multi_query($sql);
    while ($db->more_results()) $db->next_result();

    $required = ['master_spp_tahun', 'master_spp_tarif', 'tagihan_spp', 'spp_alokasi_batch', 'spp_alokasi', 'titipan_spp_mutasi', 'spp_audit_log', 'transaksi_otorisasi'];
    $quoted = implode(',', array_fill(0, count($required), '?'));
    $stmt = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ({$quoted})");
    $params = array_merge([$database], $required);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $tables = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $stmt->close();
    sort($tables); sort($required);
    if ($tables !== $required) throw new RuntimeException('Tabel Master SPP pada instalasi baru tidak lengkap.');

    $empty = $db->query("SELECT
        (SELECT COUNT(*) FROM `{$database}`.siswa) siswa,
        (SELECT COUNT(*) FROM `{$database}`.bayar) pembayaran,
        (SELECT COUNT(*) FROM `{$database}`.tahun_ajaran) tahun_ajaran")->fetch_assoc();
    if ((int)$empty['siswa'] !== 0 || (int)$empty['pembayaran'] !== 0 || (int)$empty['tahun_ajaran'] !== 0) {
        throw new RuntimeException('schema.sql tidak boleh menyisipkan siswa, pembayaran, atau tahun ajaran demo.');
    }

    echo "OK: schema.sql membangun tabel Master SPP tanpa data demo.\n";
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$database}`");
    $db->close();
}
