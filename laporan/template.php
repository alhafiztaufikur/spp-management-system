<?php
session_start();
require_once '../koneksi.php'; require_once '../includes/auth.php'; require_once '../includes/reports.php'; require_once '../includes/pagination.php';
requireRole(['admin','bendahara','kasir']);
$reportUnitId=unit_report_scope($koneksi,(string)($_GET['unit']??''));
$registry=report_registry(); $template=(string)($_GET['template']??'');
if(!isset($registry[$template])){ header('Location: global.php'); exit; }
$isPrincipalLetter=$template==='tunggakan-siswa';
$filters=report_filters($koneksi,$_GET);
if($template==='riwayat-tagihan'&&!isset($_GET['siswa_status'])) $filters['siswa_status']='all';
if(!isset($_GET['kategori'])&&$template==='penerimaan') $filters['kategori']='semua';
$perItemUsesMonthly=$template==='per-item'&&report_item_is_monthly_category($filters['kategori']);
$perItemUsesAnnual=$template==='per-item'&&report_item_is_annual_category($filters['kategori']);
$isCashRecap=in_array($template,['setoran','kas-tabungan'],true);
$isSavingsCashRecap=$template==='kas-tabungan';

$inlineStudentSearch=in_array($template,['status','saldo-tabungan','spp-tahunan','tabungan-siswa','per-item'],true);
$templateFilterClass='report-filter-'.preg_replace('/[^a-z0-9_-]+/i','-',$template);
$classes=report_classes($koneksi);$classLevels=array_map('strval',$reportUnitId===0?range(1,12):range(...unit_level_bounds()));$missingRombel=false;
try { $report=report_build($koneksi,$template,$filters); }
catch(Throwable $e){ $report=['title'=>$registry[$template]['label'],'subtitle'=>'Gagal memuat laporan','columns'=>[],'rows'=>[],'error'=>$e->getMessage()]; }
if($missingRombel){$report['rows']=[];$report['error']='Belum ada rombel nyata. Admin perlu membuat Master Kelas/Rombel lalu memindahkan siswa dari placeholder Belum Ditentukan.';}
$billingGroupedView=$template==='riwayat-tagihan';
$billingDetailCount=$template==='riwayat-tagihan'?count($report['rows']):0;
$displayRows=$billingGroupedView?report_billing_history_group_students($report['rows']):$report['rows'];
$billingColumns=$billingGroupedView?report_billing_history_component_columns($displayRows):[];
$billingMatrixWidth=700+count($billingColumns)*154;
$pagination=report_paginate($displayRows,$filters,false);
$years=report_years($koneksi); $operatorOptions=report_operator_options($koneksi); $operatorFilterLabel=report_operator_filter_label(); $categories=report_categories($koneksi); $billingCategories=$template==='riwayat-tagihan'?report_billing_categories($koneksi):[]; $query=array_merge($_GET,$filters,['template'=>$template,'unit'=>$reportUnitId===0?'all':'active']); if($template==='riwayat-tagihan')unset($query['tahun_tagihan']);
$moneyTotals=report_money_totals($report,$template);if($billingGroupedView)$moneyTotals=array_values(array_filter($moneyTotals,static fn($total)=>($total['key']??'')==='tagihan'));$useGlobalIdentitySticky=in_array($template,['penerimaan','spp-tahunan','per-item','riwayat-tagihan'],true);
$studentOptionWhere=in_array($template,['saldo-tabungan','riwayat-tagihan','tunggakan-siswa'],true)&&$filters['siswa_status']!=='active'?'':' WHERE s.is_active=1';
$studentOptions=$koneksi->query("SELECT s.id AS student_id,s.NO_INDUK,s.unit_id,s.NO_induk_diknas,s.NAMA,s.KELAS,s.master_kelas_id,mk.tingkat AS master_tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id$studentOptionWhere ORDER BY s.NAMA ASC")->fetch_all(MYSQLI_ASSOC);
$studentSearchDisplay=$filters['q'];foreach($studentOptions as $studentOption){if($filters['q']!==''&&($filters['q']===$studentOption['NO_INDUK']||$filters['q']===(string)($studentOption['NO_induk_diknas']??''))){$studentSearchDisplay=$studentOption['NAMA'];break;}}
$summaryCards=report_summaries($report['rows']);$visibleRows=count($pagination['rows']);$totalRows=count($displayRows);$paginationUnit=$billingGroupedView?'siswa':'baris';$exportQuery=$query;$exportQuery['page']=1;unset($exportQuery['format'],$exportQuery['download'],$exportQuery['preview_action']);$firstShown=$totalRows>0?(($pagination['page']-1)*$pagination['per_page'])+1:0;$lastShown=$totalRows>0?min($totalRows,$pagination['page']*$pagination['per_page']):0;$resultRangeLabel=$totalRows<=0?'0 '.$paginationUnit:($visibleRows===$totalRows?'Semua '.number_format($totalRows).' '.$paginationUnit:number_format($firstShown).' s.d. '.number_format($lastShown).' dari '.number_format($totalRows).' '.$paginationUnit);if($billingGroupedView)$resultRangeLabel.=' · '.number_format($billingDetailCount).' rincian tagihan';
if($isCashRecap){
    $componentSummary=$report['component_summary']??[];
    $componentRows=$report['component_rows']??$componentSummary;
    $methodSummary=$report['method_summary']??[];
    $cashSummaryItems=$isSavingsCashRecap
        ? array_map(static fn($item)=>['label'=>$item['label'],'value'=>$item['value'],'type'=>'count'],$report['transaction_summary']??[])
        : array_map(static fn($item)=>['label'=>$item['metode'],'value'=>$item['nominal'],'type'=>'money'],$methodSummary);
    $componentTitle=$isSavingsCashRecap?'Arus Tabungan':'Komponen Pembayaran';
    $componentTotalLabel=$isSavingsCashRecap?'Mutasi Bersih':'Total Pembayaran';
    $summaryTitle=$isSavingsCashRecap?'Jumlah Transaksi':'Metode Pembayaran';
    $cashTotalLabel=$isSavingsCashRecap?'MUTASI BERSIH':'TOTAL SETORAN';
    $cashTotalValue=$isSavingsCashRecap?(float)($report['mutasi_bersih']??0):(float)($report['total_setoran']??$report['component_total']??0);
    $summaryCards=[];
    $resultRangeLabel=$isSavingsCashRecap
        ? number_format((int)($report['settlement']['transaction_count']??0)).' transaksi tabungan'
        : number_format((int)($report['settlement']['payment_count']??0)).' transaksi pembayaran';
}
function template_url(array $changes=[]):string { global $query; return 'template.php?'.http_build_query(array_merge($query,$changes)); }
function report_table_column_class(array $column): string {
    $key=(string)($column[0]??'');
    $type=(string)($column[2]??'text');
    if(in_array($type,['money','money_optional'],true))return 'report-col-money';
    if($type==='html')return 'report-col-month';
    if($type==='status'||in_array($key,['kelas','jenis','periode'],true))return 'report-col-center';
    if($key==='tanggal')return 'report-col-date';
    if($key==='nama')return 'report-col-name';
    if(in_array($key,['nis','nis_diknas'],true))return 'report-col-id';
    return 'report-col-text';
}
if($isPrincipalLetter){ require __DIR__.'/../includes/principal_letter_page.php'; exit; }
?>
<!DOCTYPE html><html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= report_e($report['title']) ?> | SistemSPP</title><link rel="icon" href="../assets/img/favicon.png?v=2"><link rel="stylesheet" href="../assets/css/style.css?v=unitpalette8&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>"><script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')})();</script></head><body>
<!DOCTYPE html><html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= report_e($report['title']) ?> | SistemSPP</title><link rel="icon" href="../assets/img/favicon.png?v=2"><link rel="stylesheet" href="../assets/css/date_controls.css?v=unitpalette8&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>"><script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')})();</script></head><body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div><div class="layout"><?php include '../includes/sidebar.php'; ?><main class="main-content">
<div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button><div class="topbar-title"><h2><?= report_e($report['title']) ?></h2><span class="breadcrumb"><?= $isPrincipalLetter ? '<a href="surat_laporan.php">Surat Laporan</a>' : '<a href="global.php?unit='.($reportUnitId===0?'all':'active').'">Laporan Global</a>' ?> / <?= report_e($registry[$template]['label']) ?></span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
<?php if($isPrincipalLetter): ?><a class="letter-back-link letter-principal-back" href="surat_laporan.php">&larr; Kembali ke pilihan surat</a><?php endif; ?>
<section class="main-card class-recap-card recap-report-shell report-template-shell report-table-<?= report_e($templateFilterClass) ?>">
<div class="recap-report-header"><div class="recap-report-copy"><span class="recap-class-overline"><?= $isPrincipalLetter ? 'Surat Laporan' : 'Laporan Global' ?></span><h1><?= report_e($report['title']) ?></h1><p><?= report_e($report['subtitle']) ?> · <?= number_format($totalRows) ?> <?= report_e($paginationUnit) ?> hasil filter.</p></div>
<form method="get" class="recap-header-controls report-filter-card report-template-filters report-global-filter-form <?= report_e($templateFilterClass) ?><?= $template==='per-item'&&!$perItemUsesMonthly?' report-per-item-compact':'' ?>"><input type="hidden" name="template" value="<?= report_e($template) ?>"><input type="hidden" name="unit" value="<?= $reportUnitId===0?'all':'active' ?>"><div class="report-filter-heading"><span class="recap-filter-label">Filter Laporan</span></div>
<?php if(in_array($template,['penerimaan','setoran','kas-tabungan','tabungan-siswa','riwayat-tagihan'],true)||$template==='per-item'): $rangeLabel=$template==='riwayat-tagihan'&&$filters['tanggal_awal']===''?'Semua tanggal':($template==='per-item'?report_item_date_period_label($filters):report_date_range_label($filters['tanggal_awal'],$filters['tanggal_akhir'])); $datePeriodStyle=$template==='per-item'&&($perItemUsesMonthly||$perItemUsesAnnual)?' style="display:none"':''; ?><div class="field-row report-date-range-field" data-per-item-period="date"<?= $datePeriodStyle ?>><label class="field-label"><?= $template==='riwayat-tagihan'?'Tanggal Tagihan Dibuat':'Tanggal Transaksi' ?></label><div class="report-date-range-control report-date-range-picker" data-range-picker data-empty-label="<?= $template==='riwayat-tagihan'?'Semua tanggal':report_e($rangeLabel) ?>"><input type="hidden" name="tanggal_awal" value="<?= report_e($filters['tanggal_awal']) ?>"><input type="hidden" name="tanggal_akhir" value="<?= report_e($filters['tanggal_akhir']) ?>"><button type="button" class="report-date-range-button" aria-expanded="false"><span class="report-date-range-icon">📅</span><span class="report-date-range-value"><?= report_e($rangeLabel) ?></span></button><div class="report-date-range-popover" hidden><label><span>Mulai</span><input type="date" value="<?= report_e($filters['tanggal_awal']) ?>" data-range-start></label><label><span>Sampai</span><input type="date" value="<?= report_e($filters['tanggal_akhir']) ?>" data-range-end></label><div class="report-date-range-popover-actions"><button type="button" class="btn btn-primary btn-sm" data-range-apply>Terapkan</button></div></div></div></div><?php endif; ?>
<?php if(in_array($template,['status','spp-tahunan'],true)||$template==='per-item'): ?><div class="field-row"<?= $template==='per-item'?' data-per-item-period="academic-year"'.($perItemUsesAnnual?'':' style="display:none"'):'' ?>><label class="field-label">Tahun Ajaran</label><select class="field-input field-select" name="tahun_ajaran"><?php foreach($years as $year): ?><option value="<?= report_e($year['label']) ?>" <?= $filters['tahun_ajaran']===$year['label']?'selected':'' ?>><?= report_e($year['label'].' · '.$year['status']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if(in_array($template,['status','per-item','penerimaan'],true)): ?><div class="field-row"><label class="field-label">Kategori</label><select class="field-input field-select" name="kategori"<?= $template==='per-item'?' data-report-item-category':'' ?>><?php if($template==='penerimaan'): ?><option value="semua">Semua kategori</option><?php endif; ?><?php foreach($categories as $key=>$label): ?><option value="<?= report_e($key) ?>" <?= $filters['kategori']===$key?'selected':'' ?>><?= report_e($label) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if($template==='status'): ?><div class="field-row"><label class="field-label">Bulan Tagihan</label><select class="field-input field-select" name="bulan_awal"><?php foreach(report_months() as $code=>$month): ?><option value="<?= $code ?>" <?= $filters['bulan_awal']===$code?'selected':'' ?>><?= $month ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if($template==='per-item'): $monthPeriodStyle=$perItemUsesMonthly?'':' style="display:none"'; $monthNames=report_months(); $monthStartLabel=$monthNames[$filters['bulan_awal']]??'Bulan'; $monthEndLabel=$monthNames[$filters['bulan_akhir']]??'Bulan'; ?><div class="field-row report-month-range-field" data-per-item-period="month"<?= $monthPeriodStyle ?>><label class="field-label">Periode Tagihan</label><div class="report-month-range-control report-month-range-picker" data-month-range-picker data-empty-label="Pilih bulan tagihan"><input type="hidden" name="bulan_awal" value="<?= report_e($filters['bulan_awal']) ?>"><input type="hidden" name="bulan_akhir" value="<?= report_e($filters['bulan_akhir']) ?>"><button type="button" class="report-month-range-button" aria-expanded="false"><span class="report-month-range-icon" aria-hidden="true">&#128197;</span><span class="report-month-range-value"><?= report_e($monthStartLabel.' - '.$monthEndLabel) ?></span></button><div class="report-month-range-popover" hidden><label><span>Bulan Awal</span><select data-month-range-start><?php foreach($monthNames as $code=>$month): ?><option value="<?= $code ?>" <?= $filters['bulan_awal']===$code?'selected':'' ?>><?= report_e($month) ?></option><?php endforeach; ?></select></label><label><span>Bulan Akhir</span><select data-month-range-end><?php foreach($monthNames as $code=>$month): ?><option value="<?= $code ?>" <?= $filters['bulan_akhir']===$code?'selected':'' ?>><?= report_e($month) ?></option><?php endforeach; ?></select></label><div class="report-month-range-popover-actions"><button type="button" class="btn btn-primary btn-sm" data-month-range-apply>Terapkan</button></div></div></div></div><div class="field-row report-year-range-field" data-per-item-period="month"<?= $monthPeriodStyle ?>><label class="field-label">Rentang Tahun Tagihan</label><div class="report-year-range-control report-year-range-picker" data-year-range-picker data-empty-label="Pilih tahun tagihan"><input type="hidden" name="tahun_awal" value="<?= (int)$filters['tahun_awal'] ?>"><input type="hidden" name="tahun_akhir" value="<?= (int)$filters['tahun_akhir'] ?>"><button type="button" class="report-year-range-button" aria-expanded="false"><span class="report-year-range-icon" aria-hidden="true">&#128197;</span><span class="report-year-range-value"><?= report_e((int)$filters['tahun_awal'].' - '.(int)$filters['tahun_akhir']) ?></span></button><div class="report-year-range-popover" hidden><label><span>Tahun Awal</span><input type="number" min="2000" max="2100" value="<?= (int)$filters['tahun_awal'] ?>" data-year-range-start></label><label><span>Tahun Akhir</span><input type="number" min="2000" max="2100" value="<?= (int)$filters['tahun_akhir'] ?>" data-year-range-end></label><div class="report-year-range-popover-actions"><button type="button" class="btn btn-primary btn-sm" data-year-range-apply>Terapkan</button></div></div></div></div><?php endif; ?>
<?php if(!$isCashRecap): ?><div class="field-row report-field-kelas"><label class="field-label">Kelas/Rombel</label><select class="field-input field-select" name="kelas"><option value="" <?= $filters['kelas']===''?'selected':'' ?>>Semua Kelas</option><?php foreach($classLevels as $level): ?><option value="tingkat:<?= $level ?>" <?= $filters['kelas']==='tingkat:'.$level?'selected':'' ?>>Semua Kelas <?= $level ?></option><?php endforeach; ?><?php foreach($classes as $class): ?><option value="rombel:<?= (int)$class['id'] ?>" <?= $filters['kelas']==='rombel:'.((int)$class['id'])?'selected':'' ?>><?= report_e($class['label']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if($template==='riwayat-tagihan'): ?><div class="field-row report-field-komponen-tagihan"><label class="field-label">Komponen</label><select class="field-input field-select" name="komponen_tagihan"><option value="">Semua Komponen</option><?php foreach($billingCategories as $key=>$label): ?><option value="<?= report_e($key) ?>" <?= $filters['komponen_tagihan']===$key?'selected':'' ?>><?= report_e($label) ?></option><?php endforeach; ?></select></div><div class="field-row report-field-status"><label class="field-label">Status</label><select class="field-input field-select" name="status"><option value="">Semua Status</option><?php foreach(['tidak_ditagihkan'=>'Tidak Ditagihkan','belum_bayar'=>'Belum Bayar','cicilan'=>'Cicilan','lunas'=>'Lunas','rekonsiliasi'=>'Perlu Rekonsiliasi','dibatalkan'=>'Dibatalkan'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['status']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if(in_array($template,['saldo-tabungan','riwayat-tagihan','tunggakan-siswa'],true)): ?><div class="field-row report-field-siswa-status"><label class="field-label">Status Siswa</label><select class="field-input field-select" name="siswa_status"><?php foreach(['active'=>'Aktif','archived'=>'Arsip/Lulus','all'=>'Semua'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['siswa_status']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if($template==='saldo-tabungan'): ?><div class="field-row report-field-saldo-status"><label class="field-label">Status Saldo</label><select class="field-input field-select" name="saldo_status"><option value="" <?= $filters['saldo_status']===''?'selected':'' ?>>Semua Saldo</option><option value="ada_saldo" <?= $filters['saldo_status']==='ada_saldo'?'selected':'' ?>>Ada Saldo</option><option value="saldo_nol" <?= $filters['saldo_status']==='saldo_nol'?'selected':'' ?>>Saldo Nol</option></select></div><?php endif; ?>
<?php if(in_array($template,['penerimaan','tabungan-siswa','setoran','kas-tabungan'],true)): ?><div class="field-row report-field-operator"><label class="field-label">Operator</label><select class="field-input field-select" name="operator"><option value=""><?= report_e($operatorFilterLabel) ?></option><?php foreach($operatorOptions as $operator): ?><option value="<?= (int)$operator['id'] ?>" <?= $filters['operator']==(string)$operator['id']?'selected':'' ?>><?= report_e($operator['nama']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if($template==='tabungan-siswa'): ?><div class="field-row"><label class="field-label">Jenis Mutasi</label><select class="field-input field-select" name="mutasi"><option value="" <?= $filters['mutasi']===''?'selected':'' ?>>Semua Mutasi</option><option value="masuk" <?= $filters['mutasi']==='masuk'?'selected':'' ?>>Masuk</option><option value="keluar" <?= $filters['mutasi']==='keluar'?'selected':'' ?>>Keluar</option></select></div><?php endif; ?>
<?php if(in_array($template,['penerimaan','setoran'],true)): ?><div class="field-row report-field-metode"><label class="field-label">Metode</label><select class="field-input field-select" name="metode"><option value="">Semua metode</option><?php foreach(['Tunai','VA','Qris'] as $method): ?><option <?= $filters['metode']===$method?'selected':'' ?>><?= $method ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if(in_array($template,['status','spp-tahunan','per-item'],true)): ?><div class="field-row"><label class="field-label">Status</label><select class="field-input field-select" name="status"><option value="">Semua status</option><?php foreach(['tidak_ditagihkan'=>'Tidak Ditagihkan','belum_bayar'=>'Belum Bayar','cicilan'=>'Cicilan','lunas'=>'Lunas','dibatalkan'=>'Dibatalkan','rekonsiliasi'=>'Perlu Rekonsiliasi','ada_pembayaran'=>'Ada Pembayaran','tunggakan'=>'Memiliki Tunggakan'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['status']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if(in_array($template,['status','spp-tahunan','per-item'],true)): ?><div class="field-row"><label class="field-label">Status Siswa</label><select class="field-input field-select" name="siswa_status"><?php foreach(['active'=>'Aktif','archived'=>'Arsip/Lulus','all'=>'Semua'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['siswa_status']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if(!$isCashRecap): ?><?php if($inlineStudentSearch): ?><div class="report-inline-search-row"><?php endif; ?><div class="field-row report-per-page-field"><label class="field-label"><?= $billingGroupedView?'Siswa/Halaman':'Baris/Halaman' ?></label><select class="field-input field-select" name="per_page"><?php foreach([25,50,100] as $size): ?><option <?= $filters['per_page']===$size?'selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></div><div class="field-row full-span report-student-field"><label class="field-label" for="global-siswa-search">Cari Siswa (Nama / NIS / NIS Diknas)</label><div class="search-box"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="global-siswa-search" data-student-search data-student-list="global-siswa-list" data-student-select-callback="selectReportStudentSearchOption" data-student-query-target="global-student-query" data-student-class-filter="[name=kelas]" value="<?= report_e($studentSearchDisplay) ?>" placeholder="Ketik nama, NIS, atau NIS Diknas..." autocomplete="off"><input type="hidden" id="global-student-query" name="q" value="<?= report_e($filters['q']) ?>"></div><datalist id="global-siswa-list"><?php foreach($studentOptions as $studentOption): $studentLevel=(string)($studentOption['master_tingkat']?:$studentOption['KELAS']); $studentClassLabel=class_label(['tingkat'=>$studentLevel,'kode_rombel'=>$studentOption['kode_rombel']??'BELUM','is_placeholder'=>$studentOption['is_placeholder']??1]); ?><option value="<?= report_e($studentOption['NAMA']) ?>" data-student-id="<?= (int)($studentOption['student_id']??0) ?>" data-unit-id="<?= (int)($studentOption['unit_id']??0) ?>" data-nis="<?= report_e($studentOption['NO_INDUK']) ?>" data-diknas="<?= report_e((string)($studentOption['NO_induk_diknas']??'')) ?>" data-nama="<?= report_e($studentOption['NAMA']) ?>" data-kelas="<?= report_e((unit_all_readonly()?unit_label((int)$studentOption['unit_id']).' · ':'').$studentClassLabel) ?>" data-kelas-id="<?= (int)($studentOption['master_kelas_id']??0) ?>" data-tingkat="<?= report_e($studentLevel) ?>"><?= report_e($studentOption['NAMA']) ?> (<?= report_e((unit_all_readonly()?unit_label((int)$studentOption['unit_id']).' · ':'').$studentClassLabel) ?>)</option><?php endforeach; ?></datalist></div><?php if($inlineStudentSearch): ?></div><?php endif; ?><?php endif; ?><div class="report-filter-actions"><button class="btn btn-primary" type="submit">Tampilkan Rekap</button><a class="btn btn-ghost" href="template.php?template=<?= urlencode($template) ?>&amp;unit=<?= $reportUnitId===0?'all':'active' ?>">Reset</a></div></form></div>
<?php if(isset($report['error'])): ?><div class="alert alert-error report-template-alert"><?= report_e($report['error']) ?></div><?php endif; ?>
<div class="recap-period-strip report-template-strip"><div class="report-strip-copy"><strong><?= report_e($registry[$template]['label']) ?></strong><span><?= report_e($report['subtitle']) ?></span></div><div class="report-strip-tools"><span class="report-result-pill"><?= report_e($resultRangeLabel) ?></span><div class="report-export-actions" aria-label="Ekspor laporan"><a class="btn btn-ghost report-export-btn" target="_blank" rel="noopener" href="<?= report_e('export_global.php?'.http_build_query(array_merge($exportQuery,['format'=>'preview']))) ?>">Cetak</a><a class="btn btn-ghost report-export-btn" target="_blank" rel="noopener" href="<?= report_e('export_global.php?'.http_build_query(array_merge($exportQuery,['format'=>'preview']))) ?>">PDF</a><a class="btn btn-primary report-export-btn" target="_blank" rel="noopener" href="<?= report_e('export_global.php?'.http_build_query(array_merge($exportQuery,['format'=>'excel']))) ?>">Excel</a></div></div></div>
<?php if($isCashRecap): ?><section class="report-settlement-component-card"><div class="report-settlement-section-head"><h3><?= report_e($componentTitle) ?></h3><div class="report-settlement-component-total"><span><?= report_e($componentTotalLabel) ?></span><strong class="<?= (float)($report['component_total']??0)<0?'report-negative':'' ?>"><?= report_money($report['component_total']??0) ?></strong></div></div><div class="table-container report-settlement-component-table"><table class="payment-table"><thead><tr><th>No</th><th><?= report_e($componentTitle) ?></th><th>Nominal</th></tr></thead><tbody><?php if(!$componentRows): ?><tr><td colspan="3"><div class="empty-state"><p>Belum ada transaksi</p><span>Tidak ada data pada periode dan filter yang dipilih.</span></div></td></tr><?php else: foreach($componentRows as $index=>$component): ?><tr><td><?= $index+1 ?></td><td><?= report_e($component['komponen']) ?></td><td class="<?= (float)$component['nominal']<0?'report-negative':'' ?>"><?= report_money($component['nominal']) ?></td></tr><?php endforeach; endif; ?></tbody><tfoot><tr><th colspan="2"><?= report_e($componentTotalLabel) ?></th><th class="<?= (float)($report['component_total']??0)<0?'report-negative':'' ?>"><?= report_money($report['component_total']??0) ?></th></tr></tfoot></table></div></section><section class="report-settlement-method-summary"><h3><?= report_e($summaryTitle) ?></h3><div class="report-settlement-method-grid"><?php foreach($cashSummaryItems as $item): ?><div class="report-settlement-method-card"><span><?= report_e($item['label']) ?></span><strong><?= $item['type']==='money'?report_money($item['value']):number_format((int)$item['value']) ?></strong></div><?php endforeach; ?></div></section><section class="report-settlement-total-card <?= $cashTotalValue<0?'is-negative':'' ?>"><div><span><?= report_e($cashTotalLabel) ?></span></div><strong><?= report_money($cashTotalValue) ?></strong></section><?php endif; ?>
<?php if(!$isCashRecap): ?>
<?php if($billingGroupedView): ?>
<p class="report-billing-date-note">Tanggal Uang PSB mengikuti tanggal data siswa dibuat.</p>
<p class="report-billing-matrix-hint">Geser tabel ke samping untuk melihat seluruh komponen tagihan.</p>
<div class="table-container report-billing-matrix-wrap" role="region" aria-label="Matriks riwayat tagihan siswa" tabindex="0">
  <table class="payment-table report-billing-matrix-table" style="--billing-matrix-width: <?= $billingMatrixWidth ?>px">
    <thead><tr>
      <th class="billing-matrix-sticky billing-matrix-no" scope="col">No</th>
      <th class="billing-matrix-sticky billing-matrix-nis" scope="col">NIS</th>
      <th class="billing-matrix-sticky billing-matrix-name" scope="col">Nama Siswa</th>
      <th class="billing-matrix-class" scope="col">Kelas</th>
      <?php foreach($billingColumns as $column): ?><th class="billing-matrix-component" scope="col"><?= report_e($column['komponen']) ?></th><?php endforeach; ?>
      <th class="billing-matrix-total" scope="col">Total Tagihan</th>
    </tr></thead>
    <tbody>
    <?php if(!$pagination['rows']): ?>
      <tr><td colspan="<?= 5+count($billingColumns) ?>"><div class="empty-state"><p>Tidak ada data</p><span>Ubah filter atau lengkapi data tagihan yang dibutuhkan.</span></div></td></tr>
    <?php else: foreach($pagination['rows'] as $index=>$student): $componentMap=array_column($student['components'],null,'komponen_key'); ?>
      <tr>
        <td class="billing-matrix-sticky billing-matrix-no"><?= (($pagination['page']-1)*$pagination['per_page'])+$index+1 ?></td>
        <td class="billing-matrix-sticky billing-matrix-nis"><span class="badge-nis"><?= report_e($student['nis']) ?></span><?php if($student['nis_diknas']!==''): ?><small>Diknas <?= report_e($student['nis_diknas']) ?></small><?php endif; ?></td>
        <td class="billing-matrix-sticky billing-matrix-name"><strong><?= report_e($student['nama']) ?></strong><small><?= number_format((int)$student['item_count']) ?> tagihan / <?= count($student['components']) ?> komponen</small></td>
        <td class="billing-matrix-class"><span class="kelas-badge"><?= report_e($student['kelas']) ?></span></td>
        <?php foreach($billingColumns as $column): $component=$componentMap[$column['komponen_key']]??null; ?>
          <td class="billing-matrix-component">
            <?php if($component): ?>
              <strong class="billing-matrix-amount"><?= report_money($component['tagihan']) ?></strong>
            <?php else: ?><span class="billing-matrix-empty" aria-label="Tidak ada tagihan">&mdash;</span><?php endif; ?>
          </td>
        <?php endforeach; ?>
        <td class="billing-matrix-total">
          <strong class="billing-matrix-amount"><?= report_money($student['total_tagihan']) ?></strong>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="table-container class-recap-scroll report-wide-table report-template-table-wrap">
  <table class="payment-table class-recap-table report-template-table<?= $useGlobalIdentitySticky ? '' : ' report-standard-scroll' ?>">
    <thead><tr>
      <th scope="col" class="report-col-no <?= $useGlobalIdentitySticky ? 'report-identity-sticky report-identity-no' : '' ?>">No</th>
      <?php foreach($report['columns'] as $columnIndex=>$column):
        $identityClass=$useGlobalIdentitySticky&&$columnIndex===0?'report-identity-sticky report-identity-nis':($useGlobalIdentitySticky&&$columnIndex===1?'report-identity-sticky report-identity-name':'');
      ?><th scope="col" class="<?= report_table_column_class($column).' '.$identityClass ?>"><?= report_e($column[1]) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php if(!$pagination['rows']): ?>
      <tr><td colspan="<?= count($report['columns'])+1 ?>"><div class="empty-state"><p>Tidak ada data</p><span>Ubah filter atau lengkapi data master yang dibutuhkan.</span></div></td></tr>
    <?php else: foreach($pagination['rows'] as $index=>$row): ?>
      <tr>
        <td class="report-col-no <?= $useGlobalIdentitySticky ? 'report-identity-sticky report-identity-no' : '' ?>"><?= (($pagination['page']-1)*$pagination['per_page'])+$index+1 ?></td>
        <?php foreach($report['columns'] as $columnIndex=>$column):
          $key=$column[0];$type=$column[2]??'text';$value=$row[$key]??'';
          $cellClasses=[report_table_column_class($column)];
          if($type==='money'&&(float)$value<0)$cellClasses[]='report-negative';
          if($useGlobalIdentitySticky&&$columnIndex===0)$cellClasses[]='report-identity-sticky report-identity-nis';
          if($useGlobalIdentitySticky&&$columnIndex===1)$cellClasses[]='report-identity-sticky report-identity-name';
        ?><td class="<?= implode(' ',$cellClasses) ?>"><?php if($type==='money'): ?><?= report_money($value) ?><?php elseif($type==='money_optional'): ?><?= $value===null||$value===''?'-':report_money($value) ?><?php elseif($type==='status'): ?><span class="report-status status-<?= report_e(report_status_key((string)$value)) ?>"><?= report_e(in_array($type,['date','datetime'],true)?spp_date_label($value,$type==='datetime'):$value) ?></span><?php elseif($type==='html'&&is_array($value)): ?><span class="matrix-main status-<?= report_e($value['status']??'') ?>"><?= report_e($value['text']??'') ?></span><small><?= report_e($value['sub']??'') ?></small><?php elseif($type==='nis'||$key==='nis'): ?><span class="badge-nis"><?= report_e(in_array($type,['date','datetime'],true)?spp_date_label($value,$type==='datetime'):$value) ?></span><?php if(!empty($row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']??'')): ?><small class="report-secondary-id">Diknas <?= report_e($row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']) ?></small><?php endif; ?><?php elseif($type==='kelas'||$key==='kelas'): ?><div class="du-class-year-cell"><span class="kelas-badge"><?= report_e(in_array($type,['date','datetime'],true)?spp_date_label($value,$type==='datetime'):$value) ?></span></div><?php else: ?><?= report_e(in_array($type,['date','datetime'],true)?spp_date_label($value,$type==='datetime'):$value) ?><?php endif; ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php if($moneyTotals): $primaryTotalKeys=['total_penerimaan','total_bersih'];$secondaryTotalCount=count(array_filter($moneyTotals,static fn($total)=>!in_array($total['key']??'', $primaryTotalKeys,true))); ?><div class="report-total-section" style="margin-top:16px"><div class="card-title">Total Rupiah</div><div class="report-summary-grid report-total-grid" style="--report-total-columns:<?= max(1,min(4,$secondaryTotalCount)) ?>"><?php foreach($moneyTotals as $total): $isPrimaryTotal=in_array($total['key']??'', $primaryTotalKeys,true); ?><div class="report-summary-card<?= $isPrimaryTotal?' is-primary':'' ?>"><span><?= report_e($total['label']) ?></span><strong><?= report_money($total['value']) ?></strong></div><?php endforeach; ?></div></div><?php endif; ?>
<?php render_pagination('template.php', $query, $pagination['page'], $pagination['pages'], $pagination['total'], $pagination['per_page'], $paginationUnit); endif; ?>
</section>
</main></div><script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script><?php if($template==='per-item'): ?><script>
document.addEventListener('DOMContentLoaded',function(){
  const category=document.querySelector('[data-report-item-category]');
  const form=category?.closest('form');
  const monthFields=Array.from(document.querySelectorAll('[data-per-item-period="month"]'));
  const dateFields=Array.from(document.querySelectorAll('[data-per-item-period="date"]'));
  const academicYearFields=Array.from(document.querySelectorAll('[data-per-item-period="academic-year"]'));
  const monthlyCategories=new Set(['spp','komite']);
  const annualCategories=new Set(<?= json_encode(array_merge(array_keys(annual_fee_components()), ['daftar_ulang'])) ?>);
  function syncPerItemPeriodFields(){
    const value=category?.value||'';
    const isMonthly=monthlyCategories.has(value);
    const isAnnual=annualCategories.has(value);
    form?.classList.toggle('report-per-item-compact',!isMonthly);
    monthFields.forEach(field=>{field.style.display=isMonthly?'':'none';});
    dateFields.forEach(field=>{field.style.display=(!isMonthly&&!isAnnual)?'':'none';});
    academicYearFields.forEach(field=>{field.style.display=isAnnual?'':'none';});
  }
  category?.addEventListener('change',syncPerItemPeriodFields);
  syncPerItemPeriodFields();
});
</script><?php endif; ?></body></html>
