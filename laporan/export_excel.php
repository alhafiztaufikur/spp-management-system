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

require_once '../includes/general_multiple.php';
$generalChoices=general_choices($_GET);
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
$studentWhere = '';
$studentParams = [];
if ($filter_q !== '') {
    $studentWhere = ' AND (s.NO_INDUK LIKE ? OR s.NAMA LIKE ? OR s.NO_induk_diknas LIKE ?)';
    $studentLike = '%' . $filter_q . '%';
    $studentParams = [$studentLike, $studentLike, $studentLike];
}
$studentWhere .= unit_student_selection_where();
$bind = static function (mysqli_stmt $stmt, string $baseTypes, array $baseParams) use ($studentParams): void {
    $types = $baseTypes . str_repeat('s', count($studentParams));
    $params = array_merge($baseParams, $studentParams);
    $stmt->bind_param($types, ...$params);
};
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
if(!empty($sppGeneralPdf)&&!in_array($_GET['output']??'preview',['pdf','inline'],true)){
    require_once __DIR__.'/../includes/report_preview.php';
    $downloadQuery=$_GET;$downloadQuery['output']='pdf';$inlineQuery=$_GET;$inlineQuery['output']='inline';$backQuery=$_GET;unset($backQuery['output'],$backQuery['return_to']);
    render_report_pdf_preview('',[
        'title'=>'Rekap Laporan Transaksi','subtitle'=>'Periode '.$period_label.($filter_q!==''?' | Pencarian: '.$filter_q:''),
        'orientation'=>'landscape','unit'=>$reportUnitId,'row_count'=>null,
        'pdf_url'=>'export_pdf.php?'.filter_build_query($inlineQuery),'download_url'=>'export_pdf.php?'.filter_build_query($downloadQuery),
        'back_url'=>'index.php?'.filter_build_query($backQuery),'close_on_back'=>($_GET['return_to']??'')==='report','fit_viewport'=>true,
    ]);
}
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);

// Keep the existing three-sheet layout; read all matching historical and current rows.
$selectedSections=is_array($_GET['jenis_laporan']??null)||($_GET['jenis_laporan']??'semua')!=='semua';
$rows=$selectedSections?[]:general_payment_rows($koneksi,$period_start,$period_end,$filter_q,(string)($_GET['urut']??'terbaru'));
$komponen_rows=general_payment_components($koneksi,$period_start,$period_end,$filter_q);
$tab_rows=general_savings_read($koneksi,$period_start,$period_end,$filter_q,true);
foreach($tab_rows as &$tab){$tab['nominal']=$tab['amount'];if($tab['origin']==='legacy')$tab['keterangan']='Legacy · '.$tab['keterangan'];}unset($tab);

$preview_total_pembayaran=(float)general_payment_summary($koneksi,$period_start,$period_end,$filter_q)['total'];
$preview_total_tab_masuk = 0.0;
$preview_total_tab_keluar = 0.0;
foreach ($tab_rows as $tab) {
    if ($tab['jenis'] === 'masuk') {
        $preview_total_tab_masuk += (float)$tab['nominal'];
    } else {
        $preview_total_tab_keluar += (float)$tab['nominal'];
    }
}

require_once __DIR__.'/../includes/excel.php';
$paymentColumns=[spp_excel_column('no','No','number')];$savingColumns=$paymentColumns;
if($reportUnitId===0){$paymentColumns[]=spp_excel_column('export_unit','Unit');$savingColumns[]=spp_excel_column('export_unit','Unit');}
$identities=[spp_excel_column('NO_INDUK','NIS'),spp_excel_column('NO_induk_diknas','NIS Diknas'),spp_excel_column('NAMA','Nama Siswa'),spp_excel_column('KELAS','Kelas')];
$paymentColumns=array_merge($paymentColumns,$identities,[spp_excel_column('export_period','Bulan Tagihan'),spp_excel_column('sistem_pembayaran','Sistem Pembayaran'),spp_excel_column('total_jumlah','Total Bayar','money'),spp_excel_column('TGL_BYR','Tanggal Bayar','datetime')]);
$savingColumns=array_merge($savingColumns,$identities,[spp_excel_column('TANGGAL','Tanggal','datetime'),spp_excel_column('jenis','Jenis'),spp_excel_column('nominal','Nominal','money'),spp_excel_column('keterangan','Keterangan')]);
foreach($rows as $i=>&$row){$row['no']=$i+1;$row['export_unit']=unit_label((int)$row['unit_id']);$row['export_period']=$row['BULAN'].' '.$row['TAHUN'];}unset($row);
foreach($tab_rows as $i=>&$row){$row['no']=$i+1;$row['export_unit']=unit_label((int)$row['unit_id']);$row['jenis']=$row['jenis']==='masuk'?'Masuk':'Keluar';}unset($row);
$totals=[['label'=>'Total Pembayaran','value'=>$preview_total_pembayaran]];
$tabTotals=[['label'=>'Total Masuk','value'=>$preview_total_tab_masuk],['label'=>'Total Keluar','value'=>$preview_total_tab_keluar]];
$summary=[['label'=>'Pembayaran','value'=>$preview_total_pembayaran],['label'=>'Tabungan Masuk','value'=>$preview_total_tab_masuk],['label'=>'Tabungan Keluar','value'=>$preview_total_tab_keluar]];
$sheets=[['name'=>'Ringkasan','sections'=>[spp_excel_section('Ringkasan Keuangan',[spp_excel_column('label','Ringkasan'),spp_excel_column('value','Nominal','money')],$summary,[],false),spp_excel_section('Rincian Komponen Pembayaran',[spp_excel_column('nama','Komponen'),spp_excel_column('total','Nominal','money')],$komponen_rows,$totals,false)]],['name'=>'Pembayaran','sections'=>[spp_excel_section('Transaksi Pembayaran',$paymentColumns,$rows,$totals)]],['name'=>'Tabungan','sections'=>[spp_excel_section('Transaksi Tabungan',$savingColumns,$tab_rows,$tabTotals)]]];
if($selectedSections){
    $generalSections=general_sections($koneksi,$generalChoices,$period_start,$period_end,$filter_q,(string)($_GET['urut']??'terbaru'));
    $sheets[1]['sections']=general_excel_sections($generalSections);
    foreach($generalSections as $section)foreach($section['totals'] as $total)$summary[]=['label'=>$section['title'].' - '.$total['label'],'value'=>$total['value']];
    $sheets[0]['sections'][0]['rows']=$summary;unset($generalSections,$section);
}
$koneksi->commit();report_perf_mark('query_model');
$doc=spp_excel_document('Rekap Laporan Transaksi','Periode '.$period_label.($filter_q!==''?' | Pencarian: '.$filter_q:''),$reportUnitId,$sheets);
$downloadQuery=$_GET;$downloadQuery['download']='1';$backQuery=$_GET;unset($backQuery['download']);
if(!empty($sppGeneralPdf))return;
$detailCount=0;
foreach(array_slice($sheets,1) as $sheet)foreach($sheet['sections'] as $section)$detailCount+=count($section['rows']);
spp_excel_respond($doc,$download,'laporan-keuangan-'.date('Ymd-His'),'export_excel.php?'.filter_build_query($downloadQuery),'index.php?'.filter_build_query($backQuery),$detailCount);
