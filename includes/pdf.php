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
        spp_pdf_prepare_system_font();
        return;
    }

    $autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (class_exists(\Dompdf\Dompdf::class)) {
        spp_pdf_prepare_system_font();
        return;
    }

    error_log('Dependency PDF SistemSPP belum tersedia. Jalankan composer install pada root project.');
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Export PDF belum tersedia</title>'
        . '<style>body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f4faf6;color:#173326}'
        . '.box{max-width:620px;margin:10vh auto;padding:28px;background:#fff;border:1px solid #cde4d7;border-radius:10px}'
        . 'h1{font-size:22px;margin:0 0 10px}p{line-height:1.6;margin:6px 0}'
        . 'code{background:#edf7f1;padding:3px 7px;border-radius:4px}</style></head><body>'
        . '<main class="box"><h1>Export PDF belum dapat dijalankan</h1>'
        . '<p>Komponen PDF belum terpasang pada instalasi aplikasi ini.</p>'
        . '<p>Jalankan <code>composer install</code> dari folder utama project, lalu coba export kembali.</p>'
        . '</main></body></html>';
    exit;
}

/** Use the installed UI font in PDF output without shipping system font binaries. */
function spp_pdf_prepare_system_font(): string
{
    static $family = null;
    if ($family !== null) return $family;
    $family = 'DejaVu Sans';
    $faces = ['normal'=>['segoeui.ttf',400,'normal'],'bold'=>['segoeuib.ttf',700,'normal'],
        'italic'=>['segoeuii.ttf',400,'italic'],'bold_italic'=>['segoeuiz.ttf',700,'italic']];
    $hasFaces = static function(array $cached) use ($faces): bool {
        foreach ($faces as $variant=>$_face) {
            $path = $cached[$variant] ?? '';
            if (!$path || !is_readable($path . '.ttf') || !is_readable($path . '.ufm')) return false;
        }
        return true;
    };
    $lock = null;
    try {
        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(false);
        $fontCache = $options->getFontDir();
        $renderer = new \Dompdf\Dompdf($options);
        $metrics = $renderer->getFontMetrics();
        if ($hasFaces($metrics->getFontFamilies()['segoe ui'] ?? [])) return $family = 'Segoe UI';
        if (PHP_OS_FAMILY !== 'Windows') return $family;
        $fontRoot = realpath((getenv('WINDIR') ?: 'C:/Windows') . '/Fonts');
        if (!$fontRoot) return $family;
        foreach ($faces as [$file]) if (!is_readable($fontRoot . '/' . $file)) return $family;
        if (!is_writable($fontCache)) return $family;
        $lock = @fopen($fontCache . '/spp-segoe-ui.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) return $family;
        $options->setChroot([$fontRoot]);
        $renderer = new \Dompdf\Dompdf($options);
        $metrics = $renderer->getFontMetrics();
        if (!$hasFaces($metrics->getFontFamilies()['segoe ui'] ?? [])) {
            // Repair an incomplete cache instead of retaining paths to missing files.
            $metrics->setFontFamily('Segoe UI', []);
            foreach ($faces as [$file,$weight,$style]) {
                $uri = 'file://' . str_replace('\\','/',$fontRoot . '/' . $file);
                if (!$metrics->registerFont(['family'=>'Segoe UI','weight'=>$weight,'style'=>$style],$uri)) {
                    $metrics->setFontFamily('Segoe UI', []);
                    return $family;
                }
            }
        }
        $family = 'Segoe UI';
    } catch (\Throwable $error) {
        error_log('PDF UI font unavailable; using the bundled sans-serif fallback.');
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
    return $family;
}

function spp_pdf_font_family(): string
{
    require_pdf_library();
    return spp_pdf_prepare_system_font();
}
