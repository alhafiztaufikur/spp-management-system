<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function demo_payment_seed_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function demo_payment_seed_run(mysqli $db, string $sql): void {
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do {
        if ($result = $db->store_result()) $result->free();
    } while ($db->more_results() && $db->next_result());
    if ($db->error !== '') throw new RuntimeException($db->error);
}

$database = 'db_spp_demo_payment_seed_test';
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
    demo_payment_seed_assert($replacementCount === 2, 'Kontrak nama database schema.sql berubah.');
    demo_payment_seed_run($db, $schema);
    $db->select_db($database);

    $reset = file_get_contents(__DIR__ . '/../sql/reset_demo_students_and_finance.sql');
    $baseSeed = file_get_contents(__DIR__ . '/../sql/seed_students_psb.sql');
    $paymentSeed = file_get_contents(__DIR__ . '/../sql/seed_demo_payments.sql');
    demo_payment_seed_assert($reset !== false && $baseSeed !== false && $paymentSeed !== false, 'Salah satu script demo tidak dapat dibaca.');

    $reset = str_replace("SET @spp_reset_confirmation := '';", "SET @spp_reset_confirmation := 'RESET_DEMO_2026';", $reset, $replacementCount);
    demo_payment_seed_assert($replacementCount === 1, 'Token reset demo tidak ditemukan.');
    $paymentSeed = str_replace("SET @seed_demo_payment_confirmation := '';", "SET @seed_demo_payment_confirmation := 'SEED_PAYMENT_DEMO_2026';", $paymentSeed, $replacementCount);
    demo_payment_seed_assert($replacementCount === 1, 'Token seeder pembayaran tidak ditemukan.');

    demo_payment_seed_run($db, $reset);
    demo_payment_seed_run($db, $baseSeed);
    demo_payment_seed_run($db, $paymentSeed);

    $counts = $db->query("SELECT
        (SELECT COUNT(*) FROM bayar) pembayaran,
        (SELECT COUNT(*) FROM bayar WHERE KETERANGAN LIKE 'SEED-DEMO-SPP-%') spp,
        (SELECT COUNT(*) FROM bayar WHERE KETERANGAN LIKE 'SEED-DEMO-PSB-%') psb,
        (SELECT COUNT(*) FROM bayar WHERE KETERANGAN LIKE 'SEED-DEMO-TITIPAN-%') titipan,
        (SELECT COUNT(*) FROM bayar WHERE KETERANGAN LIKE 'SEED-DEMO-LAIN-%') biaya_lain,
        (SELECT COUNT(*) FROM spp_alokasi) alokasi_spp,
        (SELECT COUNT(*) FROM bayar_komite) bayar_komite,
        (SELECT COUNT(*) FROM bayar_du) bayar_du,
        (SELECT COUNT(*) FROM bayar_biaya_lain) bayar_biaya_lain,
        (SELECT COUNT(*) FROM titipan_spp_mutasi) mutasi_titipan,
        (SELECT COUNT(*) FROM transaksi_m) + (SELECT COUNT(*) FROM transaksi_k) mutasi_tabungan")->fetch_assoc();

    demo_payment_seed_assert((int)$counts['pembayaran'] === 1000, 'Seeder harus membuat tepat 1.000 pembayaran.');
    demo_payment_seed_assert((int)$counts['spp'] === 970 && (int)$counts['psb'] === 6 && (int)$counts['titipan'] === 12 && (int)$counts['biaya_lain'] === 12, 'Komposisi 1.000 pembayaran demo salah.');
    demo_payment_seed_assert((int)$counts['alokasi_spp'] === 970 && (int)$counts['bayar_komite'] === 970, 'Relasi SPP dan Komite demo tidak lengkap.');
    demo_payment_seed_assert((int)$counts['bayar_du'] === 100 && (int)$counts['bayar_biaya_lain'] === 12 && (int)$counts['mutasi_titipan'] === 12, 'Rincian Daftar Ulang, Biaya Lain, atau Titipan salah.');
    demo_payment_seed_assert((int)$counts['mutasi_tabungan'] === 0, 'Seeder pembayaran tidak boleh membuat mutasi tabungan.');

    // Pemanggilan ulang harus ditolak oleh guard, tanpa menambah transaksi.
    demo_payment_seed_run($db, $paymentSeed);
    demo_payment_seed_assert((int)$db->query('SELECT COUNT(*) total FROM bayar')->fetch_assoc()['total'] === 1000, 'Seeder pembayaran tidak boleh menambah transaksi saat dijalankan ulang.');

    $invalid = $db->query("SELECT
        (SELECT COUNT(*) FROM (
            SELECT ts.id FROM tagihan_spp ts
            LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id
            LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active'
            GROUP BY ts.id,ts.nominal_tagihan
            HAVING COALESCE(SUM(CASE WHEN ab.id IS NOT NULL THEN a.nominal_dari_bayar+a.nominal_dari_titipan ELSE 0 END),0) > ts.nominal_tagihan + .001
        ) x) spp_lebih_bayar,
        (SELECT COUNT(*) FROM (
            SELECT tk.id FROM tagihan_komite tk LEFT JOIN bayar_komite bk ON bk.tagihan_komite_id=tk.id
            GROUP BY tk.id,tk.nominal_tagihan
            HAVING COALESCE(SUM(bk.nominal),0) > tk.nominal_tagihan + .001
        ) x) komite_lebih_bayar,
        (SELECT COUNT(*) FROM bayar b
            WHERE ABS(b.total_jumlah - (b.U_PANGKAL+b.U_PSB+b.U_SPP+b.U_TITIPAN_SPP+b.U_KOMITE+b.U_LAIN+COALESCE((SELECT SUM(d.jumlah) FROM bayar_du d WHERE d.bayar_id=b.id),0))) > .001
        ) total_tidak_sesuai")->fetch_assoc();
    demo_payment_seed_assert((int)$invalid['spp_lebih_bayar'] === 0 && (int)$invalid['komite_lebih_bayar'] === 0 && (int)$invalid['total_tidak_sesuai'] === 0, 'Nominal atau alokasi pembayaran demo tidak konsisten.');

    echo "OK: seeder 1.000 pembayaran demo tervalidasi.\n";
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$database}`");
    $db->close();
}
