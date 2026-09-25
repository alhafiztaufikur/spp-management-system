<?php

/**
 * Memuat Dompdf dari dependency Composer di root project.
 *
 * Endpoint export memanggil helper ini setelah otorisasi agar kegagalan
 * instalasi dependency tidak berubah menjadi fatal error yang membocorkan path.
 */
function require_pdf_library(): void
{
    if (class_exists(\Dompdf\Dompdf::class)) {
        return;
    }

    $autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (class_exists(\Dompdf\Dompdf::class)) {
        return;
    }

    error_log('Dependency PDF SistemSPP belum tersedia. Jalankan composer install pada root project.');
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Export PDF belum tersedia</title>'
        . '<style>body{margin:0;font-family:Arial,sans-serif;background:#f4faf6;color:#173326}'
        . '.box{max-width:620px;margin:10vh auto;padding:28px;background:#fff;border:1px solid #cde4d7;border-radius:10px}'
        . 'h1{font-size:22px;margin:0 0 10px}p{line-height:1.6;margin:6px 0}'
        . 'code{background:#edf7f1;padding:3px 7px;border-radius:4px}</style></head><body>'
        . '<main class="box"><h1>Export PDF belum dapat dijalankan</h1>'
        . '<p>Komponen PDF belum terpasang pada instalasi aplikasi ini.</p>'
        . '<p>Jalankan <code>composer install</code> dari folder utama project, lalu coba export kembali.</p>'
        . '</main></body></html>';
    exit;
}
