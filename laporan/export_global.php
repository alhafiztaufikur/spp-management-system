<?php
session_start();
require_once '../koneksi.php'; require_once '../includes/auth.php'; require_once '../includes/reports.php';
requireRole(['admin','bendahara','kasir']);
$registry=report_registry();$template=(string)($_GET['template']??'');if(!isset($registry[$template])){http_response_code(404);exit('Template tidak ditemukan.');}
$format=(string)($_GET['format']??'print');if(!in_array($format,['print','pdf','excel'],true))$format='print';
$filters=report_filters($koneksi,$_GET);if($template==='riwayat-tagihan'&&!isset($_GET['siswa_status']))$filters['siswa_status']='all';if(!isset($_GET['kategori'])&&$template==='penerimaan')$filters['kategori']='semua';
$report=report_build($koneksi,$template,$filters);$generated=date('d-m-Y H:i:s');$operator=(string)($_SESSION['admin_nama']??$_SESSION['admin_username']??'Pengguna');
$billingGroupedView=$template==='riwayat-tagihan'&&report_billing_history_uses_grouped_view($filters,$report['rows']);
$billingPdfView=$template==='riwayat-tagihan'&&$format==='pdf';
$billingGroups=($billingGroupedView||$billingPdfView)?report_billing_history_group_students($report['rows']):[];
$moneyTotals=report_money_totals($report,$template);
function export_cell($value,string $type,array $row=[],string $key=''):string{if($type==='money')return report_e(report_money($value));if($type==='money_optional')return $value===null||$value===''?'-':report_e(report_money($value));if($type==='html'&&is_array($value))return report_e(($value['text']??'').(($value['sub']??'')!==''?' · '.$value['sub']:''));if($type==='nis'||$key==='nis'){$diknas=$row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']??'';return report_e($value).($diknas!==''?'<br><small>Diknas '.report_e($diknas).'</small>':'');}return report_e($value);}
$logoPath=realpath(__DIR__.'/../assets/img/school-logo.png');
// Excel HTML (.xls) tidak stabil untuk image/base64, jadi logo gambar hanya
// dirender untuk print/PDF. Excel memakai kop teks agar tidak muncul broken logo.
$canRenderLogo=$format!=='excel'&&($format!=='pdf'||extension_loaded('gd'));
$logoData=$logoPath&&$canRenderLogo?'data:image/png;base64,'.base64_encode((string)file_get_contents($logoPath)):'';
ob_start(); ?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title><?= report_e($report['title']) ?></title><style>
@page{margin:12mm;size:<?= $registry[$template]['orientation']==='landscape'?'A4 landscape':'A4 portrait' ?>}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#17231d;font-size:9px;margin:0}.toolbar{padding:10px;background:#eef7f2;margin-bottom:12px}.toolbar button{padding:8px 14px;border:0;background:#108952;color:#fff;border-radius:6px;cursor:pointer}.kop{width:100%;border-bottom:3px double #15543c;padding-bottom:8px;margin-bottom:12px}.kop td{border:0}.kop img{width:58px;height:58px;object-fit:contain}.kop-logo-text{width:58px;height:58px;border:1px solid #15543c;color:#15543c;font-weight:bold;font-size:12px;text-align:center;line-height:58px}.kop h1{font-size:16px;margin:0;text-align:center}.kop p{text-align:center;margin:3px 0}.title{text-align:center;margin:10px 0 12px}.title h2{font-size:14px;margin:0 0 3px}.meta{width:100%;margin-bottom:8px}.meta td{border:0;padding:2px}table.data{border-collapse:collapse;width:100%}.data th,.data td{border:1px solid #9bb9aa;padding:4px;vertical-align:top}.data th{background:#12503a;color:white;text-transform:uppercase;font-size:8px}.data tr:nth-child(even){background:#f4f8f6}.money{text-align:right;white-space:nowrap}.total-table{margin-top:10px;max-width:420px;margin-left:auto}.total-table caption{text-align:left;font-weight:bold;margin-bottom:4px}.footer{position:fixed;bottom:-7mm;left:0;right:0;border-top:1px solid #aaa;padding-top:3px;color:#666;font-size:7px}.footer:after{content:" · Halaman " counter(page)}.signatures{width:100%;margin-top:24px}.signatures td{border:0;text-align:center;width:50%;height:70px;vertical-align:top}.negative{color:#b42318;font-weight:bold}.billing-group-row{page-break-inside:avoid}.billing-student strong{display:block;font-size:10px;margin-bottom:3px}.billing-student small,.billing-class{color:#52645b}.billing-summary div{margin-bottom:3px;white-space:nowrap}.billing-summary b{display:inline-block;min-width:54px}.billing-detail-item{padding:3px 0;border-bottom:1px solid #d8e5de;line-height:1.35}.billing-detail-item:last-child{border-bottom:0}.billing-detail-item b{display:inline-block;min-width:90px}.billing-detail-meta{color:#52645b}.billing-detail-money{white-space:nowrap}@media print{.toolbar{display:none}}
<?php if($billingPdfView): ?>
@page{margin:10mm 10mm 14mm}
.billing-pdf-overview{width:100%;margin:7px 0 10px;border-collapse:separate;border-spacing:5px 0}
.billing-pdf-overview td{width:33.333%;padding:7px 9px;border:1px solid #c9e0d4;background:#f1f8f4}
.billing-pdf-overview span{display:block;color:#607269;font-size:7px;font-weight:bold;text-transform:uppercase}
.billing-pdf-overview strong{display:block;margin-top:3px;color:#12503a;font-size:11px}
.billing-pdf-empty{padding:20px;border:1px solid #c9e0d4;text-align:center;color:#607269}
.billing-pdf-student{margin-top:8px}
.billing-pdf-student+.billing-pdf-student{page-break-before:always}
.billing-pdf-student-head{width:100%;margin-bottom:4px;border-collapse:collapse;page-break-after:avoid}
.billing-pdf-student-head td{padding:7px 8px;border:1px solid #b9d4c7;background:#edf7f2;vertical-align:middle}
.billing-pdf-student-head .identity{width:43%}
.billing-pdf-student-head .identity strong{display:block;color:#103c2e;font-size:11px}
.billing-pdf-student-head .identity span{display:block;margin-top:2px;color:#52645b;font-size:7.5px}
.billing-pdf-student-head .summary{width:19%;text-align:right}
.billing-pdf-student-head .summary span{display:block;color:#607269;font-size:6.8px;font-weight:bold;text-transform:uppercase}
.billing-pdf-student-head .summary strong{display:block;margin-top:2px;font-size:9px}
.billing-pdf-detail{table-layout:fixed}
.billing-pdf-detail thead{display:table-header-group}
.billing-pdf-detail tr{page-break-inside:avoid}
.billing-pdf-detail th,.billing-pdf-detail td{padding:3px 5px;font-size:7.4px;line-height:1.25}
.billing-pdf-detail .component{width:21%;font-weight:bold}
.billing-pdf-detail .period{width:19%;color:#52645b}
.billing-pdf-detail .amount{width:15%;text-align:right;white-space:nowrap}
.billing-pdf-detail .status{width:15%;text-align:center}
.billing-pdf-status{display:inline-block;padding:2px 6px;border-radius:8px;background:#fff0cf;color:#8a5b00;font-size:6.8px;font-weight:bold;white-space:nowrap}
.billing-pdf-status.is-paid{background:#dff5e8;color:#0b7441}
.billing-pdf-status.is-unpaid{background:#fde4e3;color:#b42318}
.billing-pdf-status.is-cancelled{background:#eceff1;color:#56616a}
<?php endif; ?>
</style></head><body><?php if($format==='print'): ?><div class="toolbar"><button onclick="window.print()">Cetak Laporan</button></div><?php endif; ?>
<table class="kop"><tr><td style="width:70px"><?php if($logoData): ?><img src="<?= $logoData ?>" alt="Logo sekolah"><?php elseif($format==='excel'): ?><div class="kop-logo-text">SD MH</div><?php endif; ?></td><td><h1>SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH</h1><p>Perum Bekasi Griya Asri II, Tambun Selatan · Telp. 021-88363466</p></td><td style="width:70px"></td></tr></table>
<div class="title"><h2><?= report_e(strtoupper($report['title'])) ?></h2><div><?= report_e($report['subtitle']) ?></div></div><table class="meta"><tr><td>Dibuat: <?= report_e($generated) ?></td><td style="text-align:right">Petugas: <?= report_e($operator) ?></td></tr></table>
<?php if($billingPdfView&&$moneyTotals): ?><table class="billing-pdf-overview"><tr><?php foreach($moneyTotals as $total): ?><td><span><?= report_e($total['label']) ?></span><strong><?= report_money($total['value']) ?></strong></td><?php endforeach; ?></tr></table><?php endif; ?>
<?php if($template==='setoran'): $componentRows=$report['component_rows']??($report['component_summary']??[]);$methodSummary=$report['method_summary']??[]; ?><h3>Komponen Pembayaran</h3><table class="data"><thead><tr><th>No</th><th>Komponen</th><th>Nominal</th></tr></thead><tbody><?php foreach($componentRows as $index=>$component): ?><tr><td><?= $index+1 ?></td><td><?= report_e($component['komponen']) ?></td><td class="money <?= (float)$component['nominal']<0?'negative':'' ?>"><?= report_money($component['nominal']) ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="2">Total Bersih</th><th class="money <?= (float)($report['component_total']??0)<0?'negative':'' ?>"><?= report_money($report['component_total']??0) ?></th></tr></tfoot></table><h3>Metode Pembayaran</h3><table class="data"><thead><tr><th>Metode</th><th>Nominal</th></tr></thead><tbody><?php foreach($methodSummary as $method): ?><tr><td><?= report_e($method['metode']) ?></td><td class="money <?= (float)$method['nominal']<0?'negative':'' ?>"><?= report_money($method['nominal']) ?></td></tr><?php endforeach; ?></tbody></table><table class="data total-table"><caption>TOTAL SETORAN</caption><tbody><tr><th>Total Setoran</th><td class="money"><?= report_money($report['total_setoran']??$report['component_total']??0) ?></td></tr></tbody></table><?php endif; ?>
<?php if($template!=='setoran'): ?>
<?php if($billingPdfView): ?>
<?php if(!$billingGroups): ?><div class="billing-pdf-empty">Tidak ada data pada filter terpilih.</div><?php else: foreach($billingGroups as $index=>$student): ?>
<div class="billing-pdf-student">
  <table class="billing-pdf-student-head"><tr>
    <td class="identity"><strong><?= ($index+1).'. '.report_e($student['nama']) ?></strong><span>NIS <?= report_e($student['nis']) ?><?php if($student['nis_diknas']!==''): ?> · NIS Diknas <?= report_e($student['nis_diknas']) ?><?php endif; ?> · Kelas <?= report_e($student['kelas']) ?> · <?= number_format((int)$student['item_count']) ?> rincian</span></td>
    <td class="summary"><span>Total Tagihan</span><strong><?= report_money($student['total_tagihan']) ?></strong></td>
    <td class="summary"><span>Sudah Dibayar</span><strong><?= report_money($student['total_terbayar']) ?></strong></td>
    <td class="summary"><span>Sisa</span><strong><?= report_money($student['total_sisa']) ?></strong></td>
  </tr></table>
  <table class="data billing-pdf-detail"><thead><tr><th class="component">Komponen</th><th class="period">Periode</th><th class="amount">Tagihan</th><th class="amount">Sudah Dibayar</th><th class="amount">Sisa</th><th class="status">Status</th></tr></thead><tbody>
  <?php foreach($student['items'] as $item): $statusText=(string)$item['status'];$statusLower=mb_strtolower($statusText);$statusClass=str_contains($statusLower,'lunas')?'is-paid':(str_contains($statusLower,'belum')?'is-unpaid':(str_contains($statusLower,'batal')?'is-cancelled':'')); ?>
    <tr><td class="component"><?= report_e($item['komponen']) ?></td><td class="period"><?= report_e($item['periode']) ?><?php if($item['periode']!==$item['tahun_ajaran']): ?><br><small>TA <?= report_e($item['tahun_ajaran']) ?></small><?php endif; ?></td><td class="amount"><?= report_money($item['tagihan']) ?></td><td class="amount"><?= report_money($item['terbayar']) ?></td><td class="amount"><?= report_money($item['sisa']) ?></td><td class="status"><span class="billing-pdf-status <?= $statusClass ?>"><?= report_e($statusText) ?></span></td></tr>
  <?php endforeach; ?></tbody></table>
</div>
<?php endforeach; endif; ?>
<?php elseif($billingGroupedView): ?>
<table class="data billing-group-table"><thead><tr><th>No</th><th>Siswa</th><th>Kelas</th><th>Ringkasan</th><th>Rincian Tagihan</th></tr></thead><tbody><?php if(!$billingGroups): ?><tr><td colspan="5" style="text-align:center">Tidak ada data pada filter terpilih.</td></tr><?php else: foreach($billingGroups as $index=>$student): ?><tr class="billing-group-row"><td><?= $index+1 ?></td><td class="billing-student"><strong><?= report_e($student['nama']) ?></strong><span>NIS <?= report_e($student['nis']) ?></span><?php if($student['nis_diknas']!==''): ?><br><small>Diknas <?= report_e($student['nis_diknas']) ?></small><?php endif; ?><br><small><?= number_format((int)$student['item_count']) ?> rincian</small></td><td class="billing-class"><?= report_e($student['kelas']) ?></td><td class="billing-summary"><div><b>Tagihan</b> <?= report_money($student['total_tagihan']) ?></div><div><b>Terbayar</b> <?= report_money($student['total_terbayar']) ?></div><div><b>Sisa</b> <?= report_money($student['total_sisa']) ?></div></td><td><?php foreach($student['items'] as $item): ?><div class="billing-detail-item"><b><?= report_e($item['komponen']) ?></b> <span class="billing-detail-meta"><?= report_e($item['periode']) ?><?php if($item['periode']!==$item['tahun_ajaran']): ?> · TA <?= report_e($item['tahun_ajaran']) ?><?php endif; ?></span><br><span class="billing-detail-money">Tagihan <?= report_money($item['tagihan']) ?> · Terbayar <?= report_money($item['terbayar']) ?> · Sisa <?= report_money($item['sisa']) ?></span> · <?= report_e($item['status']) ?></div><?php endforeach; ?></td></tr><?php endforeach; endif; ?></tbody></table>
<?php else: ?><table class="data"><thead><tr><th>No</th><?php foreach($report['columns'] as $column): ?><th><?= report_e($column[1]) ?></th><?php endforeach; ?></tr></thead><tbody><?php if(!$report['rows']): ?><tr><td colspan="<?= count($report['columns'])+1 ?>" style="text-align:center">Tidak ada data pada filter terpilih.</td></tr><?php else: foreach($report['rows'] as $index=>$row): ?><tr><td><?= $index+1 ?></td><?php foreach($report['columns'] as $column): $type=$column[2]??'text';$key=$column[0];$value=$row[$key]??''; ?><td class="<?= in_array($type,['money','money_optional'],true)?'money':'' ?> <?= is_numeric($value)&&(float)$value<0?'negative':'' ?>"><?= export_cell($value,$type,$row,$key) ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?></tbody></table><?php endif; ?>
<?php if($moneyTotals&&!$billingPdfView): ?><table class="data total-table"><caption>Total Rupiah</caption><tbody><?php foreach($moneyTotals as $total): ?><tr><th><?= report_e($total['label']) ?></th><td class="money <?= (float)$total['value']<0?'negative':'' ?>"><?= report_money($total['value']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?><?php endif; ?>
<?php if($template==='setoran'): ?><table class="signatures"><tr><td>Kasir/Petugas,<br><br><br><br>(________________________)</td><td>Bagian Keuangan,<br><br><br><br>(________________________)</td></tr></table><?php endif; ?><div class="footer">SistemSPP · Data laporan bersifat live dan mengikuti koreksi transaksi sampai saat laporan dibuat.</div></body></html>
<?php $html=ob_get_clean();
$safeName=preg_replace('/[^a-z0-9_-]+/i','-',strtolower($template)).'-'.date('Ymd-His');
if($format==='excel'){header('Content-Type: application/vnd.ms-excel; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$safeName.'.xls"');echo "\xEF\xBB\xBF".$html;exit;}
if($format==='pdf'){require_once '../vendor/autoload.php';$options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isHtml5ParserEnabled',true);$dompdf=new \Dompdf\Dompdf($options);$dompdf->loadHtml($html,'UTF-8');$dompdf->setPaper('A4',$registry[$template]['orientation']);$dompdf->render();$dompdf->stream($safeName.'.pdf',['Attachment'=>true]);exit;}
echo $html;
