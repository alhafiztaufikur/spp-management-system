<?php
/** PSB is a once-at-entry obligation. Reuse the verified position, never infer a bill from cash. */
function report_psb_data(mysqli $db, array $filters): array {
    $filters['kategori']='psb';
    unset($filters['_multi']['kategori']);
    $report=report_annual_evidence($db,$filters);
    $report['title']='Pembayaran PSB';
    $report['subtitle']='Kewajiban PSB sekali saat masuk · Pembayaran kumulatif';
    $report['columns']=[['kelas','Kelas','kelas'],['nis','No. Induk','nis'],['nama','Nama Siswa'],
        ['tagihan','JUMLAH','money_optional'],['terbayar','U_PSB','money'],['sisa','SISA','money_optional']];
    return $report;
}

/** A flat PSB presentation; unit and student identities remain separate. */
function report_psb_matrix(array $report,int $unit): array {
    $identity=[['no','No','number','width'=>40]];
    if($unit===0)$identity[]=['unit','Unit','width'=>64];
    $identity=array_merge($identity,[['kelas','Kelas','kelas','width'=>72],['nis','No. Induk','nis','width'=>138],['nama','Nama Siswa','width'=>195]]);
    $money=[['tagihan','JUMLAH','money_optional','width'=>150],['terbayar','U_PSB','money','width'=>150],['sisa','SISA','money_optional','width'=>150]];
    $rows=$report['rows'];
    foreach($rows as $index=>&$row){$row['no']=$index+1;$row['kelas']=$row['kelas_asli']??$row['kelas'];}unset($row);
    $groups=array_map(static fn($column)=>['label'=>$column[1],'subtitle'=>'','columns'=>[$column]],$money);
    $totals=report_money_totals($report,'psb');
    return ['columns'=>array_merge($identity,$money),'identity_count'=>count($identity),'rows'=>$rows,
        'groups'=>$groups,'subtotals'=>array_column($totals,null,'key'),'totals'=>$totals,'flat_header'=>true,'orientation'=>'portrait'];
}

function report_psb_amount($value): string {
    return $value===null?'—':substr(report_money($value),3);
}

/** One source document for the PSB preview and PDF, isolated from other report styles. */
function report_psb_print_document(array $report,int $unit,string $subtitle,string $generated,string $operator,bool $pdf=false): string {
    $rows=$report['rows'];
    usort($rows,static function($a,$b) use($unit){
        return ((int)($a['unit_id']??$unit)<=>(int)($b['unit_id']??$unit))
            ?:strnatcasecmp($a['kelas_asli']??$a['kelas'],$b['kelas_asli']??$b['kelas'])
            ?:strcasecmp($a['nama'],$b['nama'])?:strcmp((string)$a['nis'],(string)$b['nis']);
    });
    $totals=array_column(report_money_totals($report,'psb'),null,'key');
    ob_start(); ?>
    <!doctype html><html lang="id"><head><meta charset="utf-8"><title>Pembayaran PSB</title><style>
    <?php if(!$pdf): ?>
    @font-face{font-family:"DejaVu Serif";src:url("../vendor/dompdf/dompdf/lib/fonts/DejaVuSerif.ttf") format("truetype");font-weight:400}
    @font-face{font-family:"DejaVu Serif";src:url("../vendor/dompdf/dompdf/lib/fonts/DejaVuSerif-Bold.ttf") format("truetype");font-weight:700}
    <?php endif; ?>
    @page{size:A4 portrait;margin:12mm}*{box-sizing:border-box}
    body{margin:0;background:#fff;color:#000;font:9pt/1.3 "DejaVu Serif","Times New Roman",serif}
    .psb-heading{text-align:center;margin-bottom:5mm}.psb-heading h1{font-size:12pt;margin:0 0 2mm}.psb-heading h2{font-size:11pt;margin:0 0 2mm}.psb-heading p{font-size:8pt;margin:1mm 0}
    .psb-table{width:100%;border-collapse:collapse;table-layout:fixed}.psb-table thead{display:table-header-group}
    .psb-table th,.psb-table td{padding:1mm .7mm;vertical-align:top;border:0;overflow-wrap:break-word}
    .psb-table thead th{border-top:1.5pt solid #000;border-bottom:1pt solid #000;text-align:left;font-size:8pt}
    .psb-table tr{page-break-inside:avoid}.psb-table .psb-group{page-break-after:avoid}
    .psb-group th{text-align:left;padding-top:3mm;padding-bottom:1.5mm;text-decoration:underline}
    .psb-table .psb-number{text-align:center;white-space:nowrap;overflow-wrap:normal;word-break:normal;font-variant-numeric:tabular-nums}.psb-table .psb-money{text-align:right;white-space:nowrap}
    .psb-table thead th.psb-money{white-space:normal}.psb-table .psb-money-wide{font-size:6.5pt}.psb-total th,.psb-total td{border-top:1pt solid #000;border-bottom:1pt solid #000;font-weight:bold}
    .psb-footer{position:fixed;bottom:-7mm;left:0;right:0;font-size:7pt;border-top:.5pt solid #000;padding-top:1mm}.psb-footer:after{content:" · Halaman " counter(page)}
    @media screen{body{max-width:186mm;margin:24px auto}.psb-footer{position:static;margin-top:5mm}}
    </style></head><body>
    <header class="psb-heading"><h1><?= report_e(unit_school_name($unit)) ?></h1><h2>PEMBAYARAN PSB</h2></header>
    <table class="psb-table"><colgroup><col style="width:5%"><col style="width:8%"><col style="width:14%"><col style="width:28%"><col style="width:15%"><col style="width:15%"><col style="width:15%"></colgroup>
    <thead><tr><th style="width:5%" class="psb-number">NO</th><th style="width:8%">KELAS</th><th style="width:14%">NO. INDUK</th><th style="width:28%">NAMA SISWA</th><th style="width:15%" class="psb-money">JUMLAH</th><th style="width:15%" class="psb-money">U_PSB</th><th style="width:15%" class="psb-money">SISA</th></tr></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="7">Tidak ada data sesuai filter.</td></tr><?php endif; ?>
    <?php $priorGroup=null;foreach($rows as $index=>$row):$rowUnit=(int)($row['unit_id']??$unit);$class=$row['kelas_asli']??$row['kelas'];$group=$rowUnit.'|'.$class; ?>
    <?php if($group!==$priorGroup):$priorGroup=$group; ?><tr class="psb-group"><th colspan="7"><?= report_e(($unit===0?unit_label($rowUnit).' · ':'').$class) ?></th></tr><?php endif; ?>
    <tr><td class="psb-number"><?= $index+1 ?></td><td><?= report_e($class) ?></td><td><?= report_e($row['nis']) ?></td><td><?= report_e($row['nama']) ?></td>
    <?php foreach(['tagihan','terbayar','sisa'] as $key):$amount=report_psb_amount($row[$key]); ?><td class="psb-money<?= strlen($amount)>13?' psb-money-wide':'' ?>"><?= report_e($amount) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <tr class="psb-total"><th colspan="4">TOTAL KESELURUHAN</th><?php foreach(['tagihan','terbayar','sisa'] as $key):$amount=report_psb_amount($totals[$key]['value']??null); ?><td class="psb-money<?= strlen($amount)>13?' psb-money-wide':'' ?>"><?= report_e($amount) ?></td><?php endforeach; ?></tr>
    </tbody></table>
    <footer class="psb-footer">SistemSPP · Pembayaran PSB</footer></body></html>
    <?php return ob_get_clean();
}
