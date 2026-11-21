<?php
require_once __DIR__.'/reports.php';
require_once __DIR__.'/parent_letter_messages.php';

function report_letter_logo(): string {
    $path=__DIR__.'/../assets/img/school-logo.png';
    return is_file($path)?'data:image/png;base64,'.base64_encode((string)file_get_contents($path)):'';
}

function report_letter_css(): string {
    return '@page{size:A4 portrait;margin:18mm 17mm 16mm}*{box-sizing:border-box}body{font-family:"Segoe UI","DejaVu Sans",Arial,sans-serif;font-size:11pt;line-height:1.35;color:#17231d;margin:0}p{margin:8px 0}.letter{page-break-after:always}.letter:last-child{page-break-after:auto}.kop{width:100%;border-bottom:3px double #1d5740;padding-bottom:8px;margin-bottom:14px}.kop td{border:0;vertical-align:middle}.kop img{width:58px;height:58px}.kop h1{font-size:15px;margin:0;text-align:center;line-height:1.3}.kop p{font-size:9pt;text-align:center;margin:3px 0}.date{text-align:right;margin:6px 0 12px}.recipient{margin-bottom:10px}.subject{text-align:center;font-size:13px;font-weight:bold;text-transform:uppercase;margin:4px 0 12px}.identity{width:100%;margin:8px 0 10px}.identity td{border:0;padding:1px 0;vertical-align:top}.identity td:first-child{width:95px}.identity td:nth-child(2){width:12px}.body-copy{margin:8px 0 10px}.debt{width:100%;border-collapse:collapse;margin:10px 0}.debt th,.debt td{border:1px solid #819a8c;padding:4px 7px;vertical-align:top}.debt th{background:#eaf2ed;font-weight:bold;text-align:left}.debt th.money,.debt td.money{text-align:right;white-space:nowrap}.debt tfoot td{font-weight:bold;background:#f2f7f4}.debt thead{display:table-header-group}.debt tr{page-break-inside:avoid}.closing{margin-top:12px}.signature{width:45%;margin:16px 0 0 auto;text-align:center;page-break-inside:avoid}.signature .space{height:42px}.muted{color:#566b60}.footer-note{font-size:9pt;color:#65766c;margin-top:14px}';
}

function report_letter_unit_id(): int {
    return (int)($GLOBALS['app_unit_id'] ?? 1);
}

function report_letter_header(string $logo, ?int $unitId=null, ?string $schoolOverride=null): string {
    $image=$logo!==''?'<img src="'.report_e($logo).'" alt="Logo sekolah">':'';
    $schoolName=$schoolOverride??(function_exists('unit_school_name')?unit_school_name($unitId??report_letter_unit_id()):"SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH");
    return '<table class="kop"><tr><td style="width:68px">'.$image.'</td><td><h1>'.report_e($schoolName).'</h1><p>Perum Bekasi Griya Asri II, Tambun Selatan · Telp. 021-88363466</p></td><td style="width:68px"></td></tr></table>';
}

function report_parent_letters_html(array $students,string $today,array $messages=[],array $signatories=[],int $layoutVersion=1): string {
    if(in_array($layoutVersion,[2,3,4],true))return report_parent_reminders_html($students,$today,$messages,$signatories,$layoutVersion);
    $logo=report_letter_logo();
    $html='<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Surat Tunggakan Orang Tua</title><style>'.report_letter_css().'.parent-custom-message{overflow-wrap:anywhere;word-wrap:break-word}.parent-custom-message p{margin:10px 0}.parent-custom-message ul,.parent-custom-message ol{padding-left:20px;margin:10px 0}.parent-custom-message li{margin:3px 0}.parent-custom-message{page-break-inside:auto}</style></head><body>';
    foreach($students as $student){
        $studentUnit=(int)($student['unit_id']??report_letter_unit_id());
        $signatory=$signatories[$studentUnit]??null;
        $html.='<section class="letter">'.report_letter_header($logo,$studentUnit,$signatory['school_name']??null);
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
        $html.='<div style="page-break-inside:avoid"><p>Demikian pemberitahuan ini kami sampaikan. Terima kasih atas perhatian dan kerja sama Bapak/Ibu.</p>';
        $html.='<p>Wassalamu’alaikum warahmatullahi wabarakatuh.</p>';
        $signature=$signatory?'<div>Kepala Sekolah</div><div class="space"></div><strong style="display:block;overflow-wrap:anywhere;word-wrap:break-word">'.report_e($signatory['principal_name']).'</strong>':'<div class="space"></div>(________________________)';
        $html.='<div class="signature">Hormat kami,'.$signature.'</div></div></section>';
    }
    return $html.'</body></html>';
}

function report_parent_reminder_items(array $items): array {
    $labels=['psb'=>'Uang PSB','spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang'];
    $rank=array_flip(array_keys($labels));
    $months=['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun','07'=>'Jul','08'=>'Agu','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
    $rows=[];
    foreach($items as $index=>$item){
        $component=(string)($item['komponen_key']??'');
        $label=$labels[$component]??$item['komponen'];
        $monthly=in_array($component,['spp','komite'],true)
            &&preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D',(string)($item['periode_code']??''),$period);
        $key=$monthly?$component.':'.$period[1]:$index;
        $rows[$key]??=['label'=>$label,'amount_cents'=>0,'months'=>[],'year'=>$monthly?$period[1]:'',
            'rank'=>$rank[$component]??count($labels),'index'=>$index,
            'detail'=>$component==='psb'?'Sisa kewajiban PSB':(string)($item['periode']??'')];
        $rows[$key]['amount_cents']+=(int)round((float)$item['sisa']*100);
        if($monthly)$rows[$key]['months'][$period[2]]=$months[$period[2]];
    }
    uasort($rows,static fn($a,$b)=>[$a['rank'],$a['year'],$a['index']]<=>[$b['rank'],$b['year'],$b['index']]);
    foreach($rows as &$row){
        if(!$row['months'])continue;
        ksort($row['months']);
        $row['detail']=$row['label'].' '.count($row['months']).' bulan ('.implode(', ',$row['months']).') '.$row['year'];
    }
    unset($row);
    return array_values($rows);
}

function report_parent_reminders_html(array $students,string $today,array $messages,array $signatories,int $layoutVersion=2): string {
    $logo=report_letter_logo();
    $date=spp_date_value($today);
    $dateLabel=$date?$date->format('j').' '.report_months()[$date->format('m')].' '.$date->format('Y'):report_date_label($today);
    $styles=<<<'CSS'
@page{size:A4 portrait;margin:14mm 18mm 14mm}
body{font-size:10.5pt;line-height:1.14;color:#19221e}
.parent-reminder .kop{border-bottom:1.25pt solid #244d3a;margin-bottom:10pt;padding-bottom:5pt}
.parent-reminder .kop h1{font-size:12pt}
.parent-reminder .kop img{width:48px;height:48px}
.parent-reminder .subject{font-size:11pt;line-height:1.15;margin:0 0 11pt;color:#172a21}
.parent-reminder .recipient{margin:0 0 8pt}
.parent-reminder .identity{margin:5pt 0;line-height:1.08}
.parent-reminder .identity th{text-align:left;vertical-align:top;width:86pt;padding:1px 0}
.parent-reminder .identity td:first-of-type{width:10pt}
.parent-reminder p{margin:0 0 7pt;orphans:2;widows:2}
.parent-reminder .debt{margin:8pt 0 10pt;table-layout:fixed;font-size:10pt;line-height:1.1}
.parent-reminder .debt th,.parent-reminder .debt td{border-color:#668071;padding:3.5pt 5pt;overflow-wrap:break-word}
.parent-reminder .debt th{text-align:center}
.parent-reminder .debt th:nth-child(1){width:7%}
.parent-reminder .debt th:nth-child(2){width:29%}
.parent-reminder .debt th:nth-child(3){width:19%}
.parent-reminder .debt th:nth-child(4){width:45%}
.parent-reminder .debt td:first-child{text-align:center}
.parent-reminder .debt tfoot td{text-align:left}
.parent-reminder .debt tfoot td.money{text-align:right}
.parent-custom-message{word-wrap:break-word;overflow-wrap:break-word;margin:0 0 8pt}
.parent-custom-message ul,.parent-custom-message ol{padding-left:17pt;margin:0 0 8pt}
.parent-custom-message li{margin:2pt 0}
.parent-reminder-note{font-weight:bold;font-style:italic}
.parent-signatures{width:100%;table-layout:fixed;page-break-inside:avoid;margin-top:11pt;border-collapse:collapse}
.parent-signatures td{width:45%;vertical-align:top;word-wrap:break-word;overflow-wrap:break-word;padding:0 8pt}
.parent-signatures td.sign-gap{width:10%;padding:0}
.parent-signatures .space{height:30pt}
CSS;
    $useReference=$layoutVersion>=3;
    if($useReference)$styles.=<<<'CSS'

.parent-reminder{line-height:1.08}
.parent-reminder .kop{margin-bottom:8pt}
.parent-reminder .subject{margin-bottom:9pt}
.parent-reminder .recipient{margin-bottom:7pt}
.parent-reminder .identity{border-collapse:collapse}
.parent-reminder p{margin-bottom:6pt}
.parent-reminder .debt th,.parent-reminder .debt td{padding-top:3pt;padding-bottom:3pt}
.parent-signatures{margin-top:9pt}
.parent-signatures td{text-align:center}
.parent-signatures .sign-space td{height:36pt}
CSS;
    if($layoutVersion===4)$styles.=<<<'CSS'

.parent-reminder .kop h1{font-size:14pt}
.parent-reminder .kop p{font-size:10pt;text-align:center}
.parent-reminder>p,.parent-custom-message p{text-align:justify}
CSS;
    $html='<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Surat Pemberitahuan Orang Tua</title><style>'
        .report_letter_css().$styles.'</style></head><body>';
    foreach($students as $student){
        $unit=(int)($student['unit_id']??report_letter_unit_id());
        $signer=$signatories[$unit]??[];
        $html.='<section class="letter"><div class="parent-reminder">'.report_letter_header($logo,$unit,$signer['school_name']??null);
        $html.='<h2 class="subject">Surat Pemberitahuan / Pengingat<br>Kewajiban Administrasi Biaya Pendidikan</h2>';
        $html.='<div class="recipient"><strong>Kepada Yth.</strong><br>Ayah/Bunda Orang Tua/Wali Murid';
        $identity=['Nama Siswa'=>$student['nama'],'Kelas'=>$student['kelas'],'Nomor Induk'=>$student['nis']];
        if(($student['nis_diknas']??'')!=='')$identity['NIS Diknas']=$student['nis_diknas'];
        $html.='<table class="identity">';
        foreach($identity as $label=>$value)$html.='<tr><th scope="row">'.$label.'</th><td>:</td><td>'.report_e($value).'</td></tr>';
        $html.='</table>Di tempat</div>';
        $html.='<p><strong>Assalamu’alaikum warahmatullahi wabarakatuh.</strong></p>';
        if($useReference){
            $html.='<p>Segala puji bagi Allah SWT atas limpahan rahmat dan karunia-Nya. Shalawat dan salam semoga senantiasa tercurah kepada Nabi Muhammad SAW, beserta keluarga, sahabat, dan seluruh pengikutnya hingga akhir zaman.</p>';
            $html.='<p>Ayah/Bunda yang dirahmati Allah SWT, berdasarkan data administrasi per tanggal <strong>'.report_e($dateLabel)
                .'</strong>, kami sampaikan bahwa Ayah/Bunda masih memiliki kewajiban pembayaran biaya pendidikan dengan rincian sebagai berikut.</p>';
        }else{
            $html.='<p>Segala puji bagi Allah SWT atas limpahan rahmat dan karunia-Nya. Shalawat dan salam semoga tercurah kepada Nabi Muhammad SAW, keluarga, dan para sahabatnya.</p>';
            $html.='<p>Ayah/Bunda yang dirahmati Allah SWT, berdasarkan catatan administrasi per <strong>'.report_e($dateLabel)
                .'</strong>, berikut rincian kewajiban biaya pendidikan yang masih perlu diselesaikan.</p>';
        }
        $html.='<table class="debt"><thead><tr><th>No.</th><th>Jenis Biaya</th><th>Jumlah (Rp)</th><th>Keterangan</th></tr></thead><tbody>';
        foreach(report_parent_reminder_items($student['items']) as $index=>$item){
            $html.='<tr><td>'.($index+1).'</td><td>'.report_e($item['label']).'</td><td class="money">'
                .report_e(substr(report_money($item['amount_cents']/100),3)).'</td><td>'.report_e($item['detail']).'</td></tr>';
        }
        $html.='</tbody><tfoot><tr><td colspan="2">Jumlah Tagihan Biaya</td><td class="money">'
            .report_e(substr(report_money($student['total_tunggakan']),3)).'</td><td></td></tr></tfoot></table>';
        $html.=parent_letter_message_html($messages[unit_student_key($student)]??'');
        if($useReference){
            $html.='<p>Biaya SPP dibayarkan paling lambat tanggal 10 setiap bulannya. Bagi yang belum melunasi biaya PSB dan daftar ulang, diimbau agar ada angsuran setiap bulannya untuk membantu kelancaran proses Kegiatan Belajar Mengajar (KBM) di sekolah.</p>';
            $html.='<p>Demikian yang dapat kami sampaikan. Atas perhatian dan kerja samanya, kami ucapkan terima kasih.</p>';
            $html.='<p class="parent-reminder-note">Apabila Ayah/Bunda telah melakukan pembayaran, surat informasi kewajiban ini dapat diabaikan.</p>';
        }else{
            $html.='<p>Mohon Ayah/Bunda dapat menyelesaikan kewajiban tersebut melalui bagian pembayaran sekolah.</p>';
            $html.='<p>Demikian pemberitahuan ini kami sampaikan. Terima kasih atas perhatian dan kerja sama Ayah/Bunda.</p>';
            $html.='<p class="parent-reminder-note">Catatan untuk Ayah/Bunda yang sudah melakukan pembayaran. Mohon hubungi petugas agar catatan pembayaran dapat diperiksa kembali.</p>';
        }
        $html.='<p><strong>Wassalamu’alaikum warahmatullahi wabarakatuh.</strong></p>';
        $finance=$signer['finance_name']??'Bagian Keuangan';
        $principal=report_e($signer['principal_name']??'________________________');
        $financeName=$finance==='Bagian Keuangan'?'':report_e($finance);
        if($useReference){
            $position=match($unit){
                1=>"Kepala SD Al-Qur’an (SDA) Mutiara Hikmah",
                2=>'Kepala SMPIT Mutiara Hikmah',
                3=>'Kepala SMAIT Mutiara Hikmah',
                default=>'Kepala Sekolah',
            };
            $html.='<table class="parent-signatures"><tr><td>Mengetahui,</td><td class="sign-gap"></td><td>Bekasi, '.report_e($dateLabel).'</td></tr>'
                .'<tr><td>'.report_e($position).'</td><td class="sign-gap"></td><td>Bagian Keuangan</td></tr>'
                .'<tr class="sign-space" aria-hidden="true"><td></td><td class="sign-gap"></td><td></td></tr>'
                .'<tr><td><strong>'.$principal.'</strong></td><td class="sign-gap"></td><td><strong>'.$financeName.'</strong></td></tr></table>';
        }else{
            $html.='<table class="parent-signatures"><tr><td>Mengetahui,<br><strong>Kepala Sekolah</strong><div class="space"></div>'
                .$principal.'</td><td class="sign-gap"></td><td>Bekasi, '.report_e($dateLabel).'<br><strong>Bagian Keuangan</strong><div class="space"></div>'
                .$financeName.'</td></tr></table>';
        }
        $html.='</div></section>';
    }
    return $html.'</body></html>';
}

function report_principal_letter_html(array $rombels,string $today,string $scopeLabel='Seluruh Kelas/Rombel'): string {
    $logo=report_letter_logo();
    $total=array_sum(array_column($rombels,'total_tunggakan'));
    $studentCount=array_sum(array_column($rombels,'jumlah_siswa'));
    $principalCss='.principal-letter{font-size:11pt;line-height:1.3}.principal-letter .kop{margin-bottom:14px}.principal-letter .date{margin:6px 0 16px}.principal-letter .recipient{margin-bottom:12px;line-height:1.3}.principal-letter .subject{margin:10px 0 15px;font-size:13px}.principal-letter p{margin:0 0 9px}.principal-letter .body-copy{margin-bottom:12px}.principal-letter .closing{margin-top:12px}.principal-letter .signature{margin-top:18px}.principal-letter .signature .space{height:42px}.principal-debt{width:100%!important;table-layout:fixed;border-collapse:collapse;margin:10px 0 12px;font-size:11pt;line-height:1.2}.principal-debt th,.principal-debt td{padding:3px 7px;overflow-wrap:anywhere}.principal-debt th:last-child,.principal-debt td:last-child{white-space:nowrap;text-align:right}.principal-debt thead th,.principal-debt thead th.money{text-align:center}.principal-debt tbody td:first-child,.principal-debt tbody td:nth-child(2){text-align:center}@media screen{body{width:100%;max-width:794px;min-height:1122px;margin:0 auto;padding:18mm 17mm 16mm;background:#fff}}@media screen and (max-width:620px){body{min-height:0;padding:24px 18px}.principal-letter{font-size:11pt}.principal-debt{font-size:11pt}.principal-debt th,.principal-debt td{padding:6px 5px}}';
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
    $html.='<div style="page-break-inside:avoid"><p class="closing">Laporan ini kami sampaikan sebagai bahan pemantauan dan tindak lanjut.</p>';
    $html.='<p>Demikian laporan ini kami sampaikan. Terima kasih atas perhatian Bapak/Ibu.</p><p>Wassalamu’alaikum warahmatullahi wabarakatuh.</p>';
    $html.='<div class="signature">Hormat kami,<div class="space"></div>(________________________)</div></div></section></body></html>';
    return $html;
}
