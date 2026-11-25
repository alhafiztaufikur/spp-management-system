<?php
session_start();
unset($_GET['undated'], $_GET['include_undated']);
require_once __DIR__.'/../includes/date_format.php';
require_once '../koneksi.php'; require_once '../includes/auth.php'; require_once '../includes/reports.php'; require_once '../includes/pagination.php';
requireRole(['admin','bendahara','kasir']);
require_once __DIR__.'/../includes/global_report_matrix.php';
$globalScope=in_array($_GET['template']??'',global_report_templates(),true);
$unitChoice=global_report_validate_choice($_GET['unit']??'');
$reportUnitId=$globalScope?global_report_scope($koneksi,$_GET,false):unit_report_scope($koneksi,$unitChoice);
$applied = !$globalScope || ($_GET['rekap'] ?? '') === '1';
$reportScopeQuery=$globalScope?global_report_unit_value($reportUnitId):($reportUnitId===0?'all':'active');
require_once '../includes/read_session.php';read_session_release();
$registry=report_registry(); $template=(string)($_GET['template']??'');
if(!isset($registry[$template])){ header('Location: global.php'); exit; }
$isPrincipalLetter=$template==='tunggakan-siswa';
require_once __DIR__.'/../includes/global_report_filter_options.php';
if ($globalScope && isset($_GET['filter_group'])) {
    global_report_filter_options_response($koneksi,$template,$_GET,$reportUnitId);
}
$deferFilterOptions = $globalScope && !$applied && ($_GET['filter_options'] ?? '') !== '1';
$filterGroups = $deferFilterOptions ? global_report_filter_groups($template) : [];
$optionsUrl = 'template.php?'.http_build_query(['template'=>$template,'unit'=>$reportScopeQuery]);
$completeFiltersUrl = 'template.php?'.http_build_query(array_replace($_GET,['filter_options'=>'1']));
$report = ['title'=>$registry[$template]['label']];
$filters = [];
$billingGroupedView = $template === 'riwayat-tagihan';
$isCashRecap = in_array($template,['setoran','kas-tabungan'],true);
$isSavingsCashRecap = $template === 'kas-tabungan';
$inlineStudentSearch = in_array($template,['psb','status','saldo-tabungan','spp-tahunan','tabungan-siswa','riwayat-tagihan'],true);
$templateFilterClass = 'report-filter-'.preg_replace('/[^a-z0-9_-]+/i','-',$template);
$catalog = $globalScope ? global_report_filter_catalog($koneksi,$template,$_GET,$deferFilterOptions) : null;
$filters = report_filters($koneksi,$_GET,true,$catalog);
filter_output_start();
if ($billingGroupedView && !isset($_GET['siswa_status'])) $filters['siswa_status'] = 'all';
if (!isset($_GET['kategori']) && $template === 'penerimaan') $filters['kategori'] = 'semua';
if (!$isPrincipalLetter) {
    $perItemUsesMonthly = $template === 'per-item' && report_item_is_monthly_category($filters['kategori']);
    $perItemUsesAnnual = $template === 'per-item' && report_item_is_annual_category($filters['kategori']);
    $perItemUsesOneTime = $template === 'per-item' && report_item_is_one_time_category($filters['kategori']);
    $classes = $catalog['classes'] ?? report_classes($koneksi);
    $classLevels = array_map('strval',$reportUnitId===0?range(1,12):range(...unit_level_bounds($reportUnitId)));
    $years = $catalog['years'] ?? report_years($koneksi);
    $operatorOptions = $catalog['operators'] ?? [];
    $operatorFilterLabel = report_operator_filter_label();
    $categories = array_map(static fn($label)=>global_report_present_label($label,$template),$catalog['categories']??[]);
    $billingCategories = $catalog['billingCategories'] ?? [];
    $studentSearchDisplay = $isCashRecap ? '' : global_report_filter_student_display($koneksi,$filters);
}
if ($globalScope && !$applied && !$deferFilterOptions && ($_SERVER['HTTP_X_REPORT_FRAGMENT']??'') === 'filters') {
    header('Cache-Control: private, no-store');
    require __DIR__.'/../includes/global_report_filter_form.php';
    exit;
}
if ($applied) {
    require_once '../includes/report_history_page.php';
    $historyPage = null;
    try {
        if ($billingGroupedView) $historyPage = report_history_page_data($koneksi,$filters);
        $report = $historyPage['report'] ?? report_build($koneksi,$template,$filters);
        $report = global_report_present($report,$template,'screen');
    } catch (Throwable $e) {
        $report = ['title'=>$registry[$template]['label'],'subtitle'=>'Gagal memuat laporan','columns'=>[],'rows'=>[],'error'=>$e->getMessage()];
    }
    $billingView = $billingGroupedView ? billing_history_view($koneksi,$report,$filters) : null;
    $billingDetailCount = $billingView['detail_count'] ?? 0;
    $periodCaption = $globalScope ? global_report_period_caption($koneksi,$filters,$template) : '';
    $matrix = global_report_matrix($report,$template,$reportUnitId);
    $displayRows = $matrix ? $matrix['rows'] : ($billingGroupedView ? $billingView['rows'] : $report['rows']);
    $pagination = report_paginate($displayRows,$filters,false);
    $query = array_merge($_GET,report_multiple_query($filters),['template'=>$template,'unit'=>$reportScopeQuery]);
    unset($query['filter_options']);
    if ($billingGroupedView) {
        $query['billing_year'] = $filters['_billing_years'];
        unset($query['tahun_tagihan']);
        if (empty($filters['filter_tanggal_tagihan'])) {
            $query['periode_awal'] = $filters['periode_awal'];
            $query['periode_akhir'] = $filters['periode_akhir'];
            unset($query['tanggal_awal'],$query['tanggal_akhir']);
        }
    }
    $moneyTotals = $billingGroupedView ? [] : report_money_totals($report,$template);
    $useGlobalIdentitySticky = in_array($template,['penerimaan','spp-tahunan','per-item','riwayat-tagihan'],true);
    $screenTotals = $billingGroupedView ? billing_history_screen_totals($billingView) : $moneyTotals;
    $screenTotalsHtml = $globalScope ? global_report_total_cards_html($screenTotals,$billingGroupedView?'-':'—') : '';
    $visibleRows = count($pagination['rows']);
    $totalRows = count($displayRows);
    $paginationUnit = $template==='penerimaan' ? 'transaksi' : ($billingGroupedView?'baris siswa':'baris');
    $exportQuery = $query;
    $exportQuery['page'] = 1;
    unset($exportQuery['format'],$exportQuery['download'],$exportQuery['preview_action']);
    $firstShown = $totalRows>0 ? (($pagination['page']-1)*$pagination['per_page'])+1 : 0;
    $lastShown = $totalRows>0 ? min($totalRows,$pagination['page']*$pagination['per_page']) : 0;
    $resultRangeLabel = $totalRows<=0 ? '0 '.$paginationUnit : ($visibleRows===$totalRows
        ? 'Semua '.number_format($totalRows).' '.$paginationUnit
        : number_format($firstShown).' s.d. '.number_format($lastShown).' dari '.number_format($totalRows).' '.$paginationUnit);
    if ($billingGroupedView) $resultRangeLabel .= ' · '.number_format($billingDetailCount).' rincian tagihan';
    $summaryCards = $billingGroupedView ? [] : ($historyPage['summary'] ?? report_summaries($report['rows']));
    if (isset($report['student_count'])) $summaryCards = ['Siswa'=>$report['student_count']]+$summaryCards;
    $billingColumns = $billingGroupedView ? report_billing_history_component_columns($displayRows) : [];
    $billingMatrixWidth = 700+count($billingColumns)*154;
}
if($applied && $isCashRecap){
    $componentSummary=$report['component_summary']??[];
    $componentRows=$report['component_rows']??$componentSummary;
    $methodSummary=$report['method_summary']??[];
    $cashSummaryItems=$isSavingsCashRecap
        ? array_map(static fn($item)=>['label'=>$item['label'],'value'=>$item['value'],'type'=>'count'],$report['transaction_summary']??[])
        : array_map(static fn($item)=>['label'=>$item['metode'],'value'=>$item['nominal'],'type'=>'money'],$methodSummary);
    $componentTitle=$isSavingsCashRecap?'Tabungan':'Komponen Pembayaran';
    $componentTotalLabel=$isSavingsCashRecap?'Mutasi Bersih':'Total Pembayaran';
    $summaryTitle=$isSavingsCashRecap?'Jumlah Transaksi':'Metode Pembayaran';
    $cashTotalLabel=$isSavingsCashRecap?'MUTASI BERSIH':'TOTAL SETORAN';
    $cashTotalValue=$isSavingsCashRecap?(float)($report['mutasi_bersih']??0):(float)($report['total_setoran']??$report['component_total']??0);
    $summaryCards=[];
    $resultRangeLabel=$isSavingsCashRecap
        ? number_format((int)($report['settlement']['transaction_count']??0)).' transaksi tabungan'
        : number_format((int)($report['settlement']['payment_count']??0)).' transaksi pembayaran';
}
function template_url(array $changes=[]):string { global $query; return 'template.php?'.filter_build_query(array_merge($query,$changes)); }
function report_table_column_class(array $column): string {
    return global_report_column_class($column).($column[0]==='kelas'?' report-col-class':'');
}
if($isPrincipalLetter){ require __DIR__.'/../includes/principal_letter_page.php'; exit; }
?>
<!DOCTYPE html><html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= report_e($report['title']) ?> | SistemSPP</title><link rel="icon" href="../assets/img/favicon.png?v=2"><link rel="stylesheet" href="../assets/css/style.css?v=unitpalette8&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>"><link rel="stylesheet" href="../assets/css/date_controls.css?v=unitpalette8&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>"><script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')})();</script><link rel="stylesheet" href="../assets/css/global_report_scope.css?v=<?= filemtime(__DIR__.'/../assets/css/global_report_scope.css') ?>"><link rel="stylesheet" href="../assets/css/global_report_matrix.css?v=<?= filemtime(__DIR__.'/../assets/css/global_report_matrix.css') ?>"><link rel="stylesheet" href="../assets/css/menu_refinements.css?v=<?= filemtime(__DIR__.'/../assets/css/menu_refinements.css') ?>"><link rel="stylesheet" href="../assets/css/desktop_transaction_refinements.css?v=<?= filemtime(__DIR__.'/../assets/css/desktop_transaction_refinements.css') ?>"></head><body id="<?= $globalScope?'global-report-workspace':'' ?>" data-report-template="<?= report_e($template) ?>" class="global-report-matrix-workspace<?= in_array($template,['setoran','kas-tabungan','tabungan-siswa','penerimaan'],true)?' recap-filter-refined':'' ?>">
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div><div class="layout"><?php include '../includes/sidebar.php'; ?><main class="main-content">
<div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button><div class="topbar-title"><h2><?= report_e($report['title']) ?></h2><span class="breadcrumb"><?= $isPrincipalLetter ? '<a href="surat_laporan.php">Surat Laporan</a>' : '<a href="global.php?unit='.($reportScopeQuery).'">Laporan Global</a>' ?> / <?= report_e($registry[$template]['label']) ?></span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
<?php if($isPrincipalLetter): ?><a class="letter-back-link letter-principal-back" href="surat_laporan.php">&larr; Kembali ke pilihan surat</a><?php endif; ?>
<section class="main-card class-recap-card recap-report-shell report-template-shell report-table-<?= report_e($templateFilterClass) ?>">
<div class="recap-report-header"><div class="recap-report-copy"><span class="recap-class-overline"><?= $isPrincipalLetter ? 'Surat Laporan' : 'Laporan Global' ?></span><h1><?= report_e($report['title']) ?></h1><?php if($globalScope && $applied && !isset($report['error'])): ?><p class="report-period-caption"><?= report_e($periodCaption) ?></p><?php elseif($isPrincipalLetter): ?><p><?= report_e($report['subtitle']) ?> · <?= number_format($totalRows) ?> <?= report_e($paginationUnit) ?> hasil filter.</p><?php endif; ?><?php if($globalScope): ?><?= global_report_selector($koneksi,$reportUnitId,$_GET,'template.php',false) ?><?php endif; ?></div>
<?php require __DIR__.'/../includes/global_report_filter_form.php'; ?>
</div>
<?php if($globalScope): ?>
<div class="report-preview-placeholder" data-report-placeholder<?= $applied ? ' hidden' : '' ?>>
  <h2 data-report-placeholder-label>Pilih filter, lalu klik Tampilkan Rekap.</h2>
  <div class="report-skeleton" aria-hidden="true">
    <?php for($row=0;$row<4;$row++): ?>
    <div class="report-skeleton-row"><span></span><span></span><span></span><span></span></div>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>
<?php if($applied): ?>
<div data-report-results>
<?php if(isset($report['error'])): ?><div class="alert alert-error report-template-alert"><?= report_e($report['error']) ?></div><?php else: ?>
<?php if(!empty($report['notes'])): ?><p class="report-evidence-note" role="note"><?= report_e(implode(' ? ',$report['notes'])) ?></p><?php endif; ?>
<div class="recap-period-strip report-template-strip"><div class="report-strip-copy"><strong><?= report_e($registry[$template]['label']) ?></strong></div><div class="report-strip-tools"><?php if($template!=='psb'): ?><span class="report-result-pill"><?= report_e($resultRangeLabel) ?></span><?php endif; ?><div class="report-export-actions" aria-label="Ekspor laporan"><a class="btn btn-ghost report-export-btn" target="_blank" rel="noopener" href="<?= report_e('export_global.php?'.filter_build_query(array_merge($exportQuery,['format'=>$globalScope?'print':'preview']))) ?>">Cetak</a><a class="btn btn-ghost report-export-btn" target="_blank" rel="noopener" href="<?= report_e('export_global.php?'.filter_build_query(array_merge($exportQuery,['format'=>'preview']))) ?>">PDF</a><a class="btn btn-primary report-export-btn" target="_blank" rel="noopener" href="<?= report_e('export_global.php?'.filter_build_query(array_merge($exportQuery,['format'=>'excel']))) ?>">Excel</a></div></div></div>
<?php if($isCashRecap): ?><section class="report-settlement-component-card"><div class="report-settlement-section-head"><h3><?= report_e($componentTitle) ?></h3><div class="report-settlement-component-total"><span><?= report_e($componentTotalLabel) ?></span><strong class="<?= (float)($report['component_total']??0)<0?'report-negative':'' ?>"><?= report_money($report['component_total']??0) ?></strong></div></div><div class="table-container report-settlement-component-table"><table class="payment-table"><thead><tr><th class="report-col-no">No</th><th><?= report_e($componentTitle) ?></th><th>Nominal</th></tr></thead><tbody><?php if(!$componentRows): ?><tr><td colspan="3"><div class="empty-state"><p>Belum ada transaksi</p><span>Tidak ada data pada periode dan filter yang dipilih.</span></div></td></tr><?php else: foreach($componentRows as $index=>$component): ?><tr><td class="report-col-no"><?= $index+1 ?></td><td><?= report_e($component['komponen']) ?></td><td class="<?= (float)$component['nominal']<0?'report-negative':'' ?>"><?= report_money($component['nominal']) ?></td></tr><?php endforeach; endif; ?></tbody><tfoot><tr><th colspan="2"><?= report_e($componentTotalLabel) ?></th><th class="<?= (float)($report['component_total']??0)<0?'report-negative':'' ?>"><?= report_money($report['component_total']??0) ?></th></tr></tfoot></table></div></section><section class="report-settlement-method-summary"><h3><?= report_e($summaryTitle) ?></h3><div class="report-settlement-method-grid"><?php foreach($cashSummaryItems as $item): ?><div class="report-settlement-method-card"><span><?= report_e($item['label']) ?></span><strong><?= $item['type']==='money'?report_money($item['value']):number_format((int)$item['value']) ?></strong></div><?php endforeach; ?></div></section><section class="report-settlement-total-card<?= $globalScope?' global-report-legacy-totals':'' ?> <?= $cashTotalValue<0?'is-negative':'' ?>"><div><span><?= report_e($cashTotalLabel) ?></span></div><strong><?= report_money($cashTotalValue) ?></strong></section><?= $screenTotalsHtml ?><?php endif; ?>
<?php if(!$isCashRecap): ?>
<?php if($matrix): echo global_report_matrix_html($matrix,$pagination['rows'],($pagination['page']-1)*$pagination['per_page']); ?>
<?php elseif(isset($report['sections'])): ?>
<?php $sectionStart=($pagination['page']-1)*$pagination['per_page'];foreach($report['sections'] as $sectionIndex=>$section): $selectedRows=array_values(array_filter($pagination['rows'],static fn($row)=>$row['_section']===$sectionIndex));if(!$selectedRows&&$section['rows'])continue;$section['section_totals']=report_money_totals($section,$template);$section['rows']=$selectedRows;echo report_section_html($section,$sectionStart);$sectionStart+=count($selectedRows);endforeach; ?>
<?php elseif($billingGroupedView): echo billing_history_table($billingView,$pagination['rows'],false,false,false); ?>
<?= billing_history_empty_years_html($billingView) ?>
<?php else: ?>
<div class="table-container class-recap-scroll report-wide-table report-template-table-wrap">
  <?php $flatWidths=array_map(static fn($column)=>global_report_column_width($column),array_merge([['no','No','number']],$report['columns'])); ?>
  <table class="payment-table class-recap-table report-template-table<?= $useGlobalIdentitySticky ? '' : ' report-standard-scroll' ?>" style="width:100%;min-width:<?= array_sum($flatWidths) ?>px">
    <colgroup><?php foreach($flatWidths as $columnIndex=>$width): ?><col<?= $columnIndex>0&&$report['columns'][$columnIndex-1][0]==='kelas'?' class="report-col-class"':'' ?> style="width:<?= $width ?>px"><?php endforeach; ?></colgroup>
    <thead><tr>
      <th scope="col" class="report-col-no <?= $useGlobalIdentitySticky ? 'report-identity-sticky report-identity-no' : '' ?>">No</th>
      <?php foreach($report['columns'] as $columnIndex=>$column):
        $identityClass=$useGlobalIdentitySticky&&$columnIndex===0?'report-identity-sticky report-identity-nis':($useGlobalIdentitySticky&&$columnIndex===1?'report-identity-sticky report-identity-name':'');
      ?><th scope="col" class="<?= report_table_column_class($column).' '.$identityClass.(global_report_nowrap_header($column[1])?' report-header-nowrap':'') ?>"><?= report_e($column[1]) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php if(!$pagination['rows']): ?>
      <tr><td colspan="<?= count($report['columns'])+1 ?>"><div class="empty-state"><p>Tidak ada data</p><span>Ubah filter atau lengkapi data master yang dibutuhkan.</span></div></td></tr>
    <?php else: foreach($pagination['rows'] as $index=>$row): ?>
      <tr>
        <td class="report-col-no <?= $useGlobalIdentitySticky ? 'report-identity-sticky report-identity-no' : '' ?>"><?= (($pagination['page']-1)*$pagination['per_page'])+$index+1 ?></td>
        <?php foreach($report['columns'] as $columnIndex=>$column):
          $key=$column[0];$type=$column[2]??'text';$value=$row[$key]??null;
          $cellClasses=[report_table_column_class($column)];
          if($type==='money'&&(float)$value<0)$cellClasses[]='report-negative';
          if($useGlobalIdentitySticky&&$columnIndex===0)$cellClasses[]='report-identity-sticky report-identity-nis';
          if($useGlobalIdentitySticky&&$columnIndex===1)$cellClasses[]='report-identity-sticky report-identity-name';
        ?><td class="<?= implode(' ',$cellClasses) ?>"><?php if($type==='datetime'): ?><?= global_report_cell($value,$type,$row,$key) ?><?php elseif($type==='money'): ?><?= report_money($value) ?><?php elseif($type==='money_optional'): ?><?= $value===null||$value===''?'-':report_money($value) ?><?php elseif($type==='status'): ?><span class="report-status status-<?= report_e(report_status_key((string)$value)) ?>"><?= report_e(in_array($type,['date','datetime'],true)?spp_date_label($value,$type==='datetime'):$value) ?></span><?php elseif($type==='html'&&is_array($value)): ?><span class="matrix-main status-<?= report_e($value['status']??'') ?>"><?= report_e($value['text']??'') ?></span><small><?= report_e($value['sub']??'') ?></small><?php elseif($type==='nis'||$key==='nis'): ?><span class="badge-nis"><?= report_e(in_array($type,['date','datetime'],true)?spp_date_label($value,$type==='datetime'):$value) ?></span><?php if(!empty($row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']??'')): ?><small class="report-secondary-id">Diknas <?= report_e($row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']) ?></small><?php endif; ?><?php elseif($type==='kelas'||$key==='kelas'): ?><div class="du-class-year-cell"><span class="kelas-badge"><?= report_e(in_array($type,['date','datetime'],true)?spp_date_label($value,$type==='datetime'):$value) ?></span></div><?php else: ?><?= report_e(in_array($type,['date','datetime'],true)?spp_date_label($value,$type==='datetime'):$value) ?><?php endif; ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?= $screenTotalsHtml ?>
<?php if($moneyTotals&&global_report_compact_totals($template)): ?><?= global_report_totals_html($moneyTotals,$template) ?><?php elseif($moneyTotals): $primaryTotalKeys=['total_penerimaan','total_bersih'];$secondaryTotalCount=count(array_filter($moneyTotals,static fn($total)=>!in_array($total['key']??'', $primaryTotalKeys,true))); ?><div class="report-total-section<?= $globalScope?' global-report-legacy-totals':'' ?>" style="margin-top:16px"><div class="card-title">Total Rupiah</div><div class="report-summary-grid report-total-grid" style="--report-total-columns:<?= max(1,min(4,$secondaryTotalCount)) ?>"><?php foreach($moneyTotals as $total): $isPrimaryTotal=in_array($total['key']??'', $primaryTotalKeys,true); ?><div class="report-summary-card<?= $isPrimaryTotal?' is-primary':'' ?>"><span><?= report_e($total['label']) ?></span><strong><?= report_money($total['value']) ?></strong></div><?php endforeach; ?></div></div><?php endif; ?>
<?php if($billingGroupedView): ?>
<p class="billing-grand-total billing-print-only-summary"><strong><?= BILLING_HISTORY_CURRENT_TOTAL_LABEL ?>: <?= report_e(billing_history_amount($billingView['grand']['total'])) ?></strong></p>
<?php endif; ?>
<?php render_pagination('template.php', $query, $pagination['page'], $pagination['pages'], $pagination['total'], $pagination['per_page'], $paginationUnit); ?>
<?php endif; ?>
<?php endif; ?></div><?php endif; ?>
</section>
</main></div><script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script><?php if($template==='per-item'): ?><script>window.sppAppliedReportCategories=<?= json_encode(report_multiple_values($filters,'kategori'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;window.sppAnnualReportCategories=<?= json_encode(array_merge(array_keys(annual_fee_components()),['daftar_ulang'])) ?>;</script><script src="../assets/js/report_per_item.js?v=<?= filemtime(__DIR__.'/../assets/js/report_per_item.js') ?>"></script><?php endif; ?><script src="../assets/js/global_report_workspace.js?v=<?= filemtime(__DIR__.'/../assets/js/global_report_workspace.js') ?>"></script></body></html>
