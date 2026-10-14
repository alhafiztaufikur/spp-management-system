<?php
/** Regenerate the public user manual from its editable HTML source. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

$source = __DIR__ . '/manual_penggunaan_sistemspp.html';
$target = __DIR__ . '/manual_penggunaan_sistemspp.pdf';
$html = file_get_contents($source);
if ($html === false || !str_contains($html, 'Manual Penggunaan SistemSPP')) {
    throw new RuntimeException('Sumber manual tidak dapat dibaca.');
}

// Resolve documentation-owned local assets only; no network or database access.
$document = new DOMDocument();
$oldErrors = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
libxml_clear_errors();libxml_use_internal_errors($oldErrors);
foreach ($document->getElementsByTagName('img') as $image) {
    $relative = $image->getAttribute('src');
    $resolved = realpath(__DIR__.'/'.$relative);
    if (!$resolved || !str_starts_with(str_replace('\\','/',$resolved),str_replace('\\','/',__DIR__).'/manual_assets/') || !is_file($resolved)) {
        throw new RuntimeException('Gambar manual tidak tersedia atau di luar direktori aset: '.$relative);
    }
}
function manual_render(string $html, array &$pages): \Dompdf\Dompdf {
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled',false);
    $options->set('isPhpEnabled',false);
    $options->set('isHtml5ParserEnabled',true);
    $options->setChroot(__DIR__);
    $options->setDefaultMediaType('print');
    $options->set('defaultFont','DejaVu Sans');
    $pdf = new \Dompdf\Dompdf($options);
    $pdf->setBasePath(__DIR__.'/');
    $pdf->setProtocol('file://');
    $pdf->setCallbacks([['event'=>'begin_frame','f'=>static function($frame,$canvas) use (&$pages): void {
        $node=$frame->get_node();
        if($node instanceof DOMElement && $node->hasAttribute('data-manual-heading')) {
            $pages[$node->getAttribute('data-manual-heading')]=$canvas->get_page_number();
        }
    }]]);
    // HTML image/runbook links stay useful on screen; do not embed developer
    // filesystem URLs in a portable PDF. Internal chapter links are preserved.
    $printHtml=preg_replace('/(<a\b[^>]*?)\s+href="(?!#)[^"]*"/','$1',$html);
    $pdf->loadHtml($printHtml,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();
    return $pdf;
}
// Fill the TOC from actual heading pages, re-rendering until stable.
$stable=false;
for($pass=0;$pass<4;$pass++) {
    $pages=[];$pdf=manual_render($html,$pages);
    $next=preg_replace_callback('/(<span data-toc="([a-z0-9-]+)">)[^<]*(<\/span>)/',static function(array $match) use ($pages): string {
        if(!isset($pages[$match[2]]))throw new RuntimeException('Bab daftar isi tidak ditemukan: '.$match[2]);
        return $match[1].$pages[$match[2]].$match[3];
    },$html,-1,$entries);
    if(!$entries || count($pages)!==$entries)throw new RuntimeException('Jumlah bab dan daftar isi tidak sesuai.');
    if($next===$html){$stable=true;break;}
    $html=$next;unset($pdf);gc_collect_cycles();
}
if(!$stable)throw new RuntimeException('Nomor halaman daftar isi belum stabil.');
$canvas=$pdf->getCanvas();$font=$pdf->getFontMetrics()->getFont('DejaVu Sans');
if(!preg_match('/Edisi\s+([^<\r\n]+)/u',$html,$edition))throw new RuntimeException('Edisi manual tidak tersedia.');
$canvas->page_text(48,807,'SistemSPP | Panduan Pengguna | '.$edition[1],$font,8,[0.32,0.39,0.35]);
$canvas->page_text(465,807,'{PAGE_NUM} / {PAGE_COUNT}',$font,8,[0.32,0.39,0.35]);
$bytes = $pdf->output();
if (!str_starts_with($bytes, '%PDF-') || strlen($bytes) < 10000) {
    throw new RuntimeException('PDF manual tidak valid.');
}
if (file_put_contents($target, $bytes) !== strlen($bytes)) {
    throw new RuntimeException('PDF manual gagal disimpan.');
}
if(file_put_contents($source,$html)!==strlen($html))throw new RuntimeException('Daftar isi HTML gagal disimpan.');
echo "Manual PDF diperbarui: {$target}; ".$canvas->get_page_count()." halaman, {$entries} bab, daftar isi sesuai PDF.\n";
