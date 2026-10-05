<?php
/** Build the editable workflow guide as a printable PDF. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

$source = __DIR__ . '/alur_kerja_penggunaan_sistemspp.html';
$target = __DIR__ . '/alur_kerja_penggunaan_sistemspp.pdf';
$html = file_get_contents($source);
if ($html === false || !str_contains($html, 'Alur Kerja Penggunaan SistemSPP')) {
    throw new RuntimeException('Sumber panduan alur kerja tidak tersedia.');
}
$detail = file_get_contents(__DIR__ . '/alur_kerja_detail_sistemspp.html');
if ($detail === false || !str_contains($html, '<!-- DETAIL_CONTENT -->')) {
    throw new RuntimeException('Sumber langkah detail atau penanda sisipannya tidak tersedia.');
}
$html = str_replace('<!-- DETAIL_CONTENT -->', $detail, $html);

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->setDefaultMediaType('print');
$options->set('defaultFont', 'DejaVu Sans');
$pdf = new \Dompdf\Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'portrait');
$pdf->render();
$bytes = $pdf->output();
if (!str_starts_with($bytes, '%PDF-') || strlen($bytes) < 10000) {
    throw new RuntimeException('Hasil PDF tidak valid.');
}
if (file_put_contents($target, $bytes) !== strlen($bytes)) {
    throw new RuntimeException('Gagal menyimpan panduan PDF.');
}
echo $target . ' (' . strlen($bytes) . " bytes)\n";
