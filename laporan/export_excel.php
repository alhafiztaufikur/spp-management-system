<?php
// ============================================
// laporan/export_excel.php — Export ke Excel
// ============================================
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara', 'kasir']);
$reportUnitId=unit_report_scope($koneksi,(string)($_GET['unit']??''));
require_once __DIR__.'/../includes/report_artifact.php';
if(PHP_SAPI!=='cli'&&session_status()===PHP_SESSION_ACTIVE)session_write_close();
report_perf_mark('validate');

require_once '../includes/transaction_report.php';
$sort=transaction_report_normalize_request();
$filter_bulan = (int)($_GET['bulan'] ?? date('m'));
$filter_tahun = (int)($_GET['tahun'] ?? date('Y'));
$filter_q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$dateParam = static function (string $key): string {
    $value = trim((string)($_GET[$key] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
};
$filter_tanggal = $dateParam('tanggal');
$filter_tanggal_awal = $dateParam('tanggal_awal') ?: $filter_tanggal;
$filter_tanggal_akhir = $dateParam('tanggal_akhir') ?: $filter_tanggal;
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir === '') $filter_tanggal_akhir = $filter_tanggal_awal;
if ($filter_tanggal_akhir !== '' && $filter_tanggal_awal === '') $filter_tanggal_awal = $filter_tanggal_akhir;
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '' && strtotime($filter_tanggal_awal) > strtotime($filter_tanggal_akhir)) {
    [$filter_tanggal_awal, $filter_tanggal_akhir] = [$filter_tanggal_akhir, $filter_tanggal_awal];
}
$download = isset($_GET['download']) && $_GET['download'] === '1';

$bln_names = ['1'=>'Januari','2'=>'Februari','3'=>'Maret','4'=>'April','5'=>'Mei','6'=>'Juni',
               '7'=>'Juli','8'=>'Agustus','9'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$bulan_label = $bln_names[$filter_bulan] ?? 'Unknown';
$period_start = $filter_tanggal_awal !== '' ? $filter_tanggal_awal . ' 00:00:00' : sprintf('%04d-%02d-01 00:00:00', $filter_tahun, $filter_bulan);
$period_end = $filter_tanggal_akhir !== ''
    ? date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'))
    : date('Y-m-d H:i:s', strtotime($period_start . ' +1 month'));
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '') {
    $startTs = strtotime($filter_tanggal_awal);
    $endTs = strtotime($filter_tanggal_akhir);
    if ($filter_tanggal_awal === $filter_tanggal_akhir) {
        $period_label = spp_date_label($startTs);
    } elseif (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        $period_label = spp_date_label($startTs) . ' - ' . spp_date_label($endTs);
    } else {
        $period_label = spp_date_label($startTs) . ' - ' . spp_date_label($endTs);
    }
} else {
    $period_label = $bulan_label . ' ' . $filter_tahun;
}

if(($_GET['all_periods']??'')==='1')$period_label='Semua periode';
if(($_GET['include_undated']??'')==='1')$period_label.=' (termasuk transaksi tanpa tanggal sesuai periode kewajiban)';

// The PDF shell needs only filter labels; the PDF request builds the fresh report once.
if(!empty($sppGeneralPdf)&&!in_array($_GET['output']??'preview',['pdf','inline','html'],true)){
    require_once __DIR__.'/../includes/report_preview.php';
    $downloadQuery=$_GET;$downloadQuery['output']='pdf';$inlineQuery=$_GET;$inlineQuery['output']='inline';$backQuery=$_GET;unset($backQuery['output'],$backQuery['return_to']);
    $htmlQuery=$_GET;$htmlQuery['output']='html';
    render_report_pdf_preview('',[
        'title'=>'Rekap Laporan Transaksi','subtitle'=>'Periode '.$period_label.($filter_q!==''?' | Pencarian: '.$filter_q:''),
        'orientation'=>'landscape','unit'=>$reportUnitId,'row_count'=>null,
        'pdf_url'=>'export_pdf.php?'.filter_build_query($inlineQuery),'download_url'=>'export_pdf.php?'.filter_build_query($downloadQuery),
        'back_url'=>'index.php?'.filter_build_query($backQuery),'close_on_back'=>($_GET['return_to']??'')==='report','fit_viewport'=>true,
        'desktop_html_url'=>'export_pdf.php?'.filter_build_query($htmlQuery),
    ]);
}
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);

$section=transaction_report_section($koneksi,$period_start,$period_end,$filter_q,$sort);
$sheets=[['name'=>'Transaksi Pembayaran','sections'=>[transaction_report_export_section($koneksi,$section)]]];
$koneksi->commit();report_perf_mark('query_model');
$doc=spp_excel_document('Rekap Laporan Transaksi','Periode '.$period_label.($filter_q!==''?' | Pencarian: '.$filter_q:''),$reportUnitId,$sheets);
$downloadQuery=$_GET;$downloadQuery['download']='1';$backQuery=$_GET;unset($backQuery['download']);
if(!empty($sppGeneralPdf))return;
unset($_GET['panel']); // Presentation state must not split identical XLSX artifacts.
$detailCount=0;
foreach($sheets as $sheet)foreach($sheet['sections'] as $section)$detailCount+=count($section['rows']);
spp_excel_respond($doc,$download,'transaksi-pembayaran-'.date('Ymd-His'),'export_excel.php?'.filter_build_query($downloadQuery),'index.php?'.filter_build_query($backQuery),$detailCount);
