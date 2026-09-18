<?php

require_once __DIR__ . '/koneksi.php';

try {
    $admin = $koneksi->query('SELECT id FROM admin LIMIT 1');
    $koneksi->query('SELECT id FROM tagihan_komite LIMIT 1');
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
