<?php
require_once __DIR__.'/date_format.php';
function savings_book_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function savings_book_page(array $page, array $student, string $school, string $logo, string $position): string
{
    $out='<div class="leaf '.$position.($page['type']==='back'?' back-leaf':'').'">';
    if($page['type']==='front') {
        $out.='<div class="cover"><div class="brand">'.($logo?'<img src="'.$logo.'" alt="">':'').'<div><strong>SIMPEL</strong><span>SIMPANAN PELAJAR</span></div></div><h1>BUKU TABUNGAN</h1><div class="school-name">'.savings_book_escape($school).'</div><div class="cover-rule"></div><div class="identity"><div><span>Nama Siswa</span><b>: '.savings_book_escape($student['NAMA']).'</b></div><div><span>No. Induk</span><b>: '.savings_book_escape($student['NO_INDUK']).'</b></div><div><span>Kelas</span><b>: '.savings_book_escape($student['KELAS']).'</b></div></div><p class="motivation">Menabung hari ini, mempersiapkan masa depan yang lebih baik.</p><div class="cover-ornament"></div></div>';
    } elseif($page['type']==='back') {
        $out.='<div class="cover back-cover"><div class="back-content">'.($logo?'<img src="'.$logo.'" alt="">':'').'<h2>SIMPEL</h2><p>Simpanan Pelajar</p><strong>'.savings_book_escape($school).'</strong><p>Simpan buku ini dengan baik.<br>Periksa catatan tabungan bersama petugas.</p></div><div class="cover-ornament"></div></div>';
    } else {
        $out.='<div class="ledger-heading"><strong>BUKU TABUNGAN</strong><span>'.savings_book_escape($student['NAMA']).' &middot; NIS '.savings_book_escape($student['NO_INDUK']).'</span></div><table class="ledger"><colgroup><col style="width:6mm"><col style="width:20mm"><col style="width:31mm"><col style="width:31mm"><col style="width:31mm"><col style="width:13mm"></colgroup><thead><tr><th rowspan="2" style="width:6mm">No</th><th rowspan="2" style="width:20mm">Tanggal</th><th colspan="2" style="width:62mm">Tabungan</th><th rowspan="2" style="width:31mm">Jumlah</th><th rowspan="2" style="width:13mm">Paraf</th></tr><tr><th>Masuk</th><th>Keluar</th></tr></thead><tbody>';
        foreach($page['rows'] as $entry) {
            $out.='<tr><td>'.($entry?(int)$entry['number']:'').'</td><td>'.($entry?savings_book_escape(spp_date_label($entry['tanggal'])):'').'</td><td class="amount">'.($entry&&$entry['masuk']?savings_book_money($entry['masuk']):'').'</td><td class="amount">'.($entry&&$entry['keluar']?savings_book_money($entry['keluar']):'').'</td><td class="amount">'.($entry?savings_book_money($entry['saldo']):'').'</td><td></td></tr>';
        }
        $out.='</tbody></table><div class="ledger-footer">Jumlah = saldo berjalan (Rp)<span>Hal. '.$page['number'].'</span></div>';
    }
    return $out.'</div>';
}
function savings_book_html(array $book,array $student,string $school,string $logo): string {
$html='<!doctype html><html lang="id"><head><meta charset="utf-8"><style>
@page{size:A5 portrait;margin:0}*{box-sizing:border-box}body{margin:0;color:#262626;font-family:DejaVu Sans,Arial,sans-serif}
.sheet{width:148mm;height:210mm;position:relative;page-break-after:always}.sheet.last{page-break-after:auto}
.leaf{position:absolute;left:0;width:138mm;height:95mm;padding:5mm}.leaf.top{top:0}.leaf.bottom{top:105mm}
.back-cover .back-content{transform:rotate(180deg);transform-origin:center center}.back-cover .cover-ornament{top:0;bottom:auto}
.cover{height:86mm;position:relative;background:#fffefa;border:0.7pt solid #dec7c7;color:#873a3a;padding:4mm;overflow:hidden}
.brand{height:16mm}.brand img{width:15mm;height:15mm;float:left;margin-right:3mm}.brand strong{display:block;font-size:19pt;font-weight:normal;line-height:1.1}.brand span{font-size:8pt}.cover h1{text-align:center;margin:4mm 0 0;font-size:19pt;letter-spacing:.6pt;line-height:1.15}.school-name{text-align:center;font-weight:bold;font-size:8pt;margin-top:1mm;line-height:1.3}.cover-rule{width:90mm;margin:3mm auto;border-bottom:1pt dashed #75444d}.identity{width:112mm;margin:2mm auto;font-size:8pt}.identity div{min-height:7mm}.identity span{vertical-align:top;display:inline-block;width:23mm;font-weight:bold}.identity b{display:inline-block;width:86mm;border-bottom:.5pt solid #b28a8a;vertical-align:top;word-wrap:break-word;font-weight:normal}.motivation{position:absolute;bottom:7mm;left:10mm;right:10mm;text-align:center;font-size:7pt;font-weight:bold;line-height:1.4;z-index:2}.cover-ornament{position:absolute;bottom:0;left:0;right:0;height:6mm;background:#703c4e;border-top:2pt solid #c29375}.back-content{text-align:center;margin:9mm auto;width:115mm;font-size:8pt}.back-content img{width:17mm;height:17mm}.back-content h2{font-size:19pt;margin:2mm 0}.back-content p{font-size:8pt;line-height:1.5}
.ledger-heading{height:10mm}.ledger-heading strong{font-size:9pt;display:block}.ledger-heading span{font-size:6.2pt;display:block;margin-top:1mm}.ledger{width:138mm;table-layout:auto;border-collapse:collapse;font-size:6.5pt}.ledger td,.ledger th{border:.5pt solid #555;height:3.3mm;padding:.15mm .4mm;vertical-align:middle}.ledger th{text-align:center;height:3.5mm;font-weight:bold}.ledger td:first-child{text-align:center}.ledger td.amount{text-align:right;white-space:nowrap}.ledger-footer{font-size:6pt;margin-top:2mm}.ledger-footer span{float:right}
</style></head><body>';
foreach($book['sides'] as $index=>$side) {
    $html.='<div class="sheet'.($index===count($book['sides'])-1?' last':'').'">'.savings_book_page($book['pages'][$side['left']],$student,$school,$logo,'top').savings_book_page($book['pages'][$side['right']],$student,$school,$logo,'bottom').'</div>';
}
$html.='</body></html>';

return $html;
}
