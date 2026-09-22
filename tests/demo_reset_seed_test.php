<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function demo_seed_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function demo_seed_run(mysqli $db, string $sql): void {
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do {
        if ($result = $db->store_result()) $result->free();
    } while ($db->more_results() && $db->next_result());
    if ($db->error !== '') throw new RuntimeException($db->error);
}

$database = 'db_spp_demo_seed_test';
$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$pass = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$db = new mysqli($host, $user, $pass);
$db->set_charset('utf8mb4');

try {
    $db->query("DROP DATABASE IF EXISTS `{$database}`");
    $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
    if ($schema === false) throw new RuntimeException('sql/schema.sql tidak dapat dibaca.');
    $schema = str_replace(
        ['CREATE DATABASE IF NOT EXISTS `db_spp`', 'USE `db_spp`'],
        ["CREATE DATABASE IF NOT EXISTS `{$database}`", "USE `{$database}`"],
        $schema,
        $replacementCount
    );
    demo_seed_assert($replacementCount === 2, 'Kontrak nama database schema.sql berubah.');
    demo_seed_run($db, $schema);
    $db->select_db($database);

    $reset = file_get_contents(__DIR__ . '/../sql/reset_demo_students_and_finance.sql');
    if ($reset === false) throw new RuntimeException('Script reset demo tidak dapat dibaca.');
    $reset = str_replace("SET @spp_reset_confirmation := '';", "SET @spp_reset_confirmation := 'RESET_DEMO_2026';", $reset, $replacementCount);
    demo_seed_assert($replacementCount === 1, 'Token konfirmasi reset tidak ditemukan.');
    demo_seed_run($db, $reset);

    $seed = file_get_contents(__DIR__ . '/../sql/seed_students_psb.sql');
    if ($seed === false) throw new RuntimeException('Seeder standar tidak dapat dibaca.');
    demo_seed_run($db, $seed);
    demo_seed_run($db, $seed);

    $counts = $db->query("SELECT
        (SELECT COUNT(*) FROM siswa WHERE is_active=1) siswa,
        (SELECT COUNT(*) FROM siswa WHERE is_active=1 AND KELAS IN ('1','2','3','4','5','6')) reguler,
        (SELECT COUNT(*) FROM siswa WHERE is_active=1 AND KELAS='PSB') psb,
        (SELECT COUNT(*) FROM siswa WHERE is_active=1 AND PANGKAL-potong_pangkal>0) pangkal,
        (SELECT COUNT(*) FROM tagihan_spp) spp,
        (SELECT COUNT(*) FROM tagihan_komite) komite,
        (SELECT COUNT(*) FROM tagihan_daftar_ulang) daftar_ulang,
        (SELECT COUNT(*) FROM bayar) bayar,
        (SELECT COUNT(*) FROM transaksi_m)+(SELECT COUNT(*) FROM transaksi_k) mutasi,
        (SELECT COUNT(*) FROM titipan_spp_mutasi) titipan,
        (SELECT COUNT(*) FROM tagihan_spp WHERE no_induk LIKE 'PSB%') spp_psb,
        (SELECT COUNT(*) FROM tagihan_komite WHERE no_induk LIKE 'PSB%') komite_psb")->fetch_assoc();

    demo_seed_assert((int)$counts['siswa'] === 150, 'Jumlah siswa baseline harus 150.');
    demo_seed_assert((int)$counts['reguler'] === 144 && (int)$counts['psb'] === 6, 'Komposisi siswa reguler/PSB salah.');
    demo_seed_assert((int)$counts['pangkal'] === 30, 'Tagihan Pangkal baseline harus hanya 30 siswa.');
    demo_seed_assert((int)$counts['spp'] === 1728 && (int)$counts['komite'] === 1728 && (int)$counts['daftar_ulang'] === 144, 'Jumlah tagihan baseline salah atau terduplikasi.');
    demo_seed_assert((int)$counts['bayar'] === 0 && (int)$counts['mutasi'] === 0 && (int)$counts['titipan'] === 0, 'Baseline tidak boleh memiliki transaksi atau saldo titipan.');
    demo_seed_assert((int)$counts['spp_psb'] === 0 && (int)$counts['komite_psb'] === 0, 'Siswa PSB tidak boleh memiliki tagihan bulanan.');

    echo "OK: reset demo dan seeder baseline 150 siswa tervalidasi.\n";
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$database}`");
    $db->close();
}
