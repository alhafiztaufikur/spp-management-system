<?php
require_once __DIR__.'/reports.php';
require_once __DIR__.'/parent_letter_messages.php';

function report_letter_logo(): string {
    $path=__DIR__.'/../assets/img/school-logo.png';
    return is_file($path)?'data:image/png;base64,'.base64_encode((string)file_get_contents($path)):'';
}

function report_letter_css(): string {
    return '@page{size:A4 portrait;margin:18mm 17mm 16mm}*{box-sizing:border-box}body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;line-height:1.55;color:#17231d;margin:0}.letter{page-break-after:always}.letter:last-child{page-break-after:auto}.kop{width:100%;border-bottom:3px double #1d5740;padding-bottom:8px;margin-bottom:14px}.kop td{border:0;vertical-align:middle}.kop img{width:58px;height:58px}.kop h1{font-size:15px;margin:0;text-align:center;line-height:1.3}.kop p{font-size:9px;text-align:center;margin:3px 0}.date{text-align:right;margin:6px 0 18px}.recipient{margin-bottom:16px}.subject{text-align:center;font-size:13px;font-weight:bold;text-transform:uppercase;margin:4px 0 18px}.identity{width:100%;margin:10px 0 16px}.identity td{border:0;padding:2px 0;vertical-align:top}.identity td:first-child{width:95px}.identity td:nth-child(2){width:12px}.body-copy{margin:10px 0 14px}.debt{width:100%;border-collapse:collapse;margin:10px 0}.debt th,.debt td{border:1px solid #819a8c;padding:6px 7px;vertical-align:top}.debt th{background:#eaf2ed;font-weight:bold;text-align:left}.debt th.money,.debt td.money{text-align:right;white-space:nowrap}.debt tfoot td{font-weight:bold;background:#f2f7f4}.debt thead{display:table-header-group}.debt tr{page-break-inside:avoid}.closing{margin-top:16px}.signature{width:45%;margin:27px 0 0 auto;text-align:center;page-break-inside:avoid}.signature .space{height:65px}.muted{color:#566b60}.footer-note{font-size:8px;color:#65766c;margin-top:14px}';
}

function report_letter_unit_id(): int {
    return (int)($GLOBALS['app_unit_id'] ?? 1);
}

function report_letter_header(string $logo, ?int $unitId=null): string {
    $image=$logo!==''?'<img src="'.report_e($logo).'" alt="Logo sekolah">':'';
    $schoolName=function_exists('unit_school_name')?unit_school_name($unitId??report_letter_unit_id()):"SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH";
    return '<table class="kop"><tr><td style="width:68px">'.$image.'</td><td><h1>'.report_e($schoolName).'</h1><p>Perum Bekasi Griya Asri II, Tambun Selatan · Telp. 021-88363466</p></td><td style="width:68px"></td></tr></table>';
}

function report_parent_letters_html(array $students,string $today,array $messages=[]): string {
    $logo=report_letter_logo();
    $html='<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Surat Tunggakan Orang Tua</title><style>'.report_letter_css().'.parent-custom-message{overflow-wrap:anywhere;word-wrap:break-word}.parent-custom-message p{margin:10px 0}.parent-custom-message ul,.parent-custom-message ol{padding-left:20px;margin:10px 0}.parent-custom-message li{margin:3px 0}.parent-custom-message{page-break-inside:auto}</style></head><body>';
    foreach($students as $student){
        $html.='<section class="letter">'.report_letter_header($logo,(int)($student['unit_id']??report_letter_unit_id()));
        $html.='<div class="date">Tambun Selatan, '.report_e(report_date_label($today)).'</div>';
        $html.='<div class="recipient">Yth. Bapak/Ibu Orang Tua/Wali<br><strong>'.report_e($student['nama']).'</strong><br>di tempat</div>';
        $html.='<div class="subject">Pemberitahuan Tunggakan Biaya Pendidikan</div>';
        $html.='<p>Assalamu’alaikum warahmatullahi wabarakatuh.</p>';
        $html.='<p class="body-copy">Dengan hormat, berdasarkan catatan pembayaran sekolah sampai tanggal surat ini, masih terdapat kewajiban atas siswa berikut:</p>';
        $html.='<table class="identity"><tr><td>Nama siswa</td><td>:</td><td>'.report_e($student['nama']).'</td></tr><tr><td>NIS</td><td>:</td><td>'.report_e($student['nis']).'</td></tr>';
        if(($student['nis_diknas']??'')!=='')$html.='<tr><td>NIS Diknas</td><td>:</td><td>'.report_e($student['nis_diknas']).'</td></tr>';
        $html.='<tr><td>Kelas/Rombel</td><td>:</td><td>'.report_e($student['kelas']).'</td></tr></table>';
        $html.='<table class="debt"><thead><tr><th style="width:35px">No.</th><th>Komponen</th><th>Periode</th><th class="money">Sisa Tagihan</th></tr></thead><tbody>';
        foreach($student['items'] as $index=>$item){
            $html.='<tr><td>'.($index+1).'</td><td>'.report_e($item['komponen']).'</td><td>'.report_e($item['periode']).'</td><td class="money">'.report_e(report_money($item['sisa'])).'</td></tr>';
        }
        $html.='</tbody><tfoot><tr><td colspan="3">Jumlah tunggakan</td><td class="money">'.report_e(report_money($student['total_tunggakan'])).'</td></tr></tfoot></table>';
        $html.=parent_letter_message_html($messages[unit_student_key($student)]??'');
        $html.='<p class="closing">Mohon Bapak/Ibu dapat menyelesaikan kewajiban tersebut melalui bagian pembayaran sekolah. Jika sudah membayar, mohon hubungi petugas agar catatan kami dapat diperiksa kembali.</p>';
        $html.='<p>Demikian pemberitahuan ini kami sampaikan. Terima kasih atas perhatian dan kerja sama Bapak/Ibu.</p>';
        $html.='<p>Wassalamu’alaikum warahmatullahi wabarakatuh.</p>';
        $html.='<div class="signature">Hormat kami,<div class="space"></div>(________________________)</div></section>';
    }
    return $html.'</body></html>';
}

function report_principal_letter_html(array $rombels,string $today,string $scopeLabel='Seluruh Kelas/Rombel'): string {
    $logo=report_letter_logo();
    $total=array_sum(array_column($rombels,'total_tunggakan'));
    $studentCount=array_sum(array_column($rombels,'jumlah_siswa'));
    $principalCss='.principal-letter{font-size:11px;line-height:1.45}.principal-letter .kop{margin-bottom:14px}.principal-letter .date{margin:6px 0 16px}.principal-letter .recipient{margin-bottom:14px;line-height:1.5}.principal-letter .subject{margin:10px 0 15px;font-size:13px}.principal-letter p{margin:0 0 9px}.principal-letter .body-copy{margin-bottom:12px}.principal-letter .closing{margin-top:12px}.principal-letter .signature{margin-top:18px}.principal-letter .signature .space{height:42px}.principal-debt{width:100%!important;table-layout:fixed;border-collapse:collapse;margin:10px 0 12px;font-size:10px;line-height:1.3}.principal-debt th,.principal-debt td{padding:5px 7px;overflow-wrap:anywhere}.principal-debt th:last-child,.principal-debt td:last-child{white-space:nowrap;text-align:right}.principal-debt thead th,.principal-debt thead th.money{text-align:center}.principal-debt tbody td:first-child,.principal-debt tbody td:nth-child(2){text-align:center}@media screen{body{width:100%;max-width:794px;min-height:1122px;margin:0 auto;padding:18mm 17mm 16mm;background:#fff}}@media screen and (max-width:620px){body{min-height:0;padding:24px 18px}.principal-letter{font-size:11px}.principal-debt{font-size:10px}.principal-debt th,.principal-debt td{padding:6px 5px}}';
    $html='<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Surat Tunggakan Kepala Sekolah</title><style>'.report_letter_css().$principalCss.'</style></head><body><section class="principal-letter">';
    $html.=report_letter_header($logo).'<div class="date">Tambun Selatan, '.report_e(report_date_label($today)).'</div>';
    $schoolName=function_exists('unit_school_name')?unit_school_name(report_letter_unit_id()):"SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH";
    $html.='<div class="recipient">Yth. Kepala Sekolah<br>'.report_e($schoolName).'<br>di tempat</div>';
    $html.='<div class="subject">Laporan Tunggakan Siswa</div>';
    $html.='<p>Assalamu’alaikum warahmatullahi wabarakatuh.</p><p class="body-copy">Bersama ini kami sampaikan total tunggakan untuk '.report_e($scopeLabel).' berdasarkan catatan pembayaran sekolah sampai '.report_e(report_date_label($today)).'.</p>';
    $html.='<table class="debt principal-debt"><colgroup><col style="width:9%"><col style="width:35%"><col style="width:22%"><col style="width:34%"></colgroup><thead><tr><th>No.</th><th>Kelas/Rombel</th><th>Siswa Menunggak</th><th class="money">Total Tunggakan</th></tr></thead><tbody>';
    foreach($rombels as $index=>$rombel){
        $html.='<tr><td>'.($index+1).'</td><td>'.report_e($rombel['kelas']).'</td><td>'.number_format((int)$rombel['jumlah_siswa']).'</td><td class="money">'.report_e(report_money($rombel['total_tunggakan'])).'</td></tr>';
    }
    if(!$rombels)$html.='<tr><td colspan="4">Tidak ada tunggakan pada pilihan ini.</td></tr>';
    $html.='</tbody><tfoot><tr><td colspan="3">Total '.report_e($scopeLabel).' ('.number_format($studentCount).' siswa menunggak)</td><td class="money">'.report_e(report_money($total)).'</td></tr></tfoot></table>';
    $html.='<p class="closing">Laporan ini kami sampaikan sebagai bahan pemantauan dan tindak lanjut.</p>';
    $html.='<p>Demikian laporan ini kami sampaikan. Terima kasih atas perhatian Bapak/Ibu.</p><p>Wassalamu’alaikum warahmatullahi wabarakatuh.</p>';
    $html.='<div class="signature">Hormat kami,<div class="space"></div>(________________________)</div></section></body></html>';
    return $html;
}
