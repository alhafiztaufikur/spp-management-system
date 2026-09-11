<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__ . '/../laporan');
require_once '../koneksi.php';

try {
    $class = $koneksi->query("SELECT id FROM master_kelas WHERE is_active=1 AND is_placeholder=0 ORDER BY tingkat,kode_rombel LIMIT 1")->fetch_assoc();
    if (!$class) {
        echo "SKIPPED: belum ada rombel aktif non-placeholder untuk smoke test render.\n";
        exit(0);
    }

    session_id('codex-billing-' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = [
        'admin_id' => -1,
        'admin_role' => 'admin',
        'admin_nama' => 'UI Smoke Test',
    ];
    session_write_close();

    $_SERVER['PHP_SELF'] = '/laporan/template.php';
    $_GET = [
        'template' => 'riwayat-tagihan',
        'kelas' => 'rombel:' . (int)$class['id'],
        'siswa_status' => 'all',
    ];

    ob_start();
    include 'template.php';
    $html = ob_get_clean();

    if (!str_contains($html, 'report-billing-group-table')) {
        throw new RuntimeException('Tabel kelompok siswa tidak dirender.');
    }
    if (!str_contains($html, 'Siswa/Halaman')) {
        throw new RuntimeException('Pagination belum memakai satuan siswa.');
    }
    if (!str_contains($html, 'rincian tagihan')) {
        throw new RuntimeException('Jumlah rincian tagihan tidak ditampilkan.');
    }
    if (!str_contains($html, 'assets/css/style.css?v=9.6')) {
        throw new RuntimeException('Versi cache stylesheet laporan belum diperbarui.');
    }

    session_write_close();
    $_GET['format'] = 'print';
    ob_start();
    include 'export_global.php';
    $exportHtml = ob_get_clean();
    if (!str_contains($exportHtml, 'billing-group-table')) {
        throw new RuntimeException('Cetak laporan tidak memakai tabel kelompok siswa.');
    }
    if (!str_contains($exportHtml, '<th>Ringkasan</th><th>Rincian Tagihan</th>')) {
        throw new RuntimeException('Kolom ringkasan ekspor tidak lengkap.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    echo "OK: kontrak HTML web dan cetak mode kelompok berhasil dirender.\n";
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
