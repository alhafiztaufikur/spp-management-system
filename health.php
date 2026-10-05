<?php

require_once __DIR__ . '/koneksi.php';

try {
    require_once __DIR__.'/includes/legacy_schema.php';
    require_once __DIR__.'/includes/financial_components_schema.php';
    if(!financial_components_ready($koneksi))throw new RuntimeException('Migrasi PSB/potongan nominal belum lengkap.');
    if(!legacy_schema_ready($koneksi))throw new RuntimeException('Migrasi identitas Legacy belum lengkap.');
    $admin = $koneksi->query('SELECT id FROM admin LIMIT 1');
    $koneksi->query('SELECT id FROM tagihan_komite LIMIT 1');
    $koneksi->query('SELECT request_key FROM keuangan_request LIMIT 0');
    $koneksi->query('SELECT keterangan FROM transaksi_m LIMIT 0');
    $koneksi->query('SELECT keterangan FROM transaksi_k LIMIT 0');
    if ($admin->num_rows === 0) {
        throw new RuntimeException('Administrator awal belum tersedia.');
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
} catch (Throwable $error) {
    error_log('Pemeriksaan kesehatan SistemSPP gagal: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'unavailable';
}
