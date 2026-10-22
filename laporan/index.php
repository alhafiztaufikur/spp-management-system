<?php
// ============================================
// laporan/index.php - Rekap Laporan Transaksi
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/payment_permissions.php';
require_once '../includes/finance_ui.php';
require_once '../includes/pagination.php';
require_once '../includes/daftar_ulang.php';
require_once '../includes/kelas.php';
require_once '../includes/tagihan_tahunan.php';
requireRole(['admin', 'bendahara', 'kasir']);
$reportUnitId=unit_report_scope($koneksi,(string)($_GET['unit']??''));

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function general_page_money($value): string {
    $v=round((float)$value,2);return 'Rp ' . number_format($v,$v===floor($v)?0:2,',','.');
}

function general_page_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function general_page_month_code($value): string {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12',
    ];
    if (isset($map[$value])) return $map[$value];
    $number = (int)$value;
    return $number >= 1 && $number <= 12 ? str_pad((string)$number, 2, '0', STR_PAD_LEFT) : '01';
}

function report_date_param(string $key): string {
    $value = trim((string)($_GET[$key] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function report_date_label_id(int $timestamp): string { return spp_date_label($timestamp); }

function report_month_name_id(int $month): string {
    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    return $months[$month] ?? '';
}

function report_transaction_date_label(string $startDate, string $endDate, string $fallback): string {
    if ($startDate === '' || $endDate === '') return $fallback;
    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if (!$startTs || !$endTs) return $fallback;
    if ($startDate === $endDate) return report_date_label_id($startTs);

    return report_date_label_id($startTs) . ' - ' . report_date_label_id($endTs);
}

$bln_names = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
];

$filter_q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$filter_tanggal = report_date_param('tanggal');
$filter_tanggal_awal = report_date_param('tanggal_awal') ?: ($filter_tanggal ?: date('Y-m-01'));
$filter_tanggal_akhir = report_date_param('tanggal_akhir') ?: ($filter_tanggal ?: date('Y-m-d'));
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir === '') {
    $filter_tanggal_akhir = $filter_tanggal_awal;
}
if ($filter_tanggal_akhir !== '' && $filter_tanggal_awal === '') {
    $filter_tanggal_awal = $filter_tanggal_akhir;
}
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '' && strtotime($filter_tanggal_awal) > strtotime($filter_tanggal_akhir)) {
    [$filter_tanggal_awal, $filter_tanggal_akhir] = [$filter_tanggal_akhir, $filter_tanggal_awal];
}
$periodReferenceTs = strtotime($filter_tanggal_akhir ?: date('Y-m-d'));
$filter_bulan = general_page_month_code($_GET['bulan'] ?? date('m', $periodReferenceTs));
$filter_tahun = preg_match('/^\d{4}$/', (string)($_GET['tahun'] ?? '')) ? (string)$_GET['tahun'] : date('Y', $periodReferenceTs);

require_once '../includes/transaction_report.php';
$sort=transaction_report_normalize_request();
$financePanel=$_GET['panel'];
$sortOptions=transaction_report_sort_options();
if(!isset($_GET['fragment']))filter_output_start();

$allowedPageSizes = [10, 25, 50];
$perPage = page_size_param('per_page', $allowedPageSizes, 10);

$periodStart = $filter_tanggal_awal !== '' ? $filter_tanggal_awal . ' 00:00:00' : $filter_tahun . '-' . $filter_bulan . '-01 00:00:00';
$periodEnd = $filter_tanggal_akhir !== ''
    ? date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'))
    : date('Y-m-d H:i:s', strtotime($periodStart . ' +1 month'));
$bulan_label = $bln_names[$filter_bulan];
$periodLabel = report_transaction_date_label($filter_tanggal_awal, $filter_tanggal_akhir, $bulan_label . ' ' . $filter_tahun);

require_once '../includes/general_report_sections.php';
$sectionPages=[];$requestedPages=is_array($_GET['section_page']??null)?$_GET['section_page']:[];
foreach(['semua'] as $type)$sectionPages[$type]=is_scalar($requestedPages[$type]??null)?max(1,min(1000000,(int)$requestedPages[$type])):1;
$sectionQuery=['unit'=>$reportUnitId===0?'all':'active','tanggal_awal'=>$filter_tanggal_awal,'tanggal_akhir'=>$filter_tanggal_akhir,'bulan'=>$filter_bulan,'tahun'=>$filter_tahun,'q'=>$filter_q,'urut'=>$sort,'per_page'=>$perPage,'student_id'=>max(0,(int)($_GET['student_id']??0)),'all_periods'=>($_GET['all_periods']??'')==='1'?'1':'0','include_undated'=>($_GET['include_undated']??'')==='1'?'1':'0','section_page'=>$sectionPages];
$sectionQuery['panel']=$financePanel;
if($sectionQuery['all_periods']==='1')$periodLabel='Semua periode';
if($sectionQuery['include_undated']==='1')$periodLabel.=' (termasuk tanpa tanggal sesuai periode kewajiban)';
if(isset($_GET['fragment'])){
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
    try{
        $type=$_GET['section']??null;
        if($_GET['fragment']!=='section'||!is_string($type)||$type!=='semua')throw new InvalidArgumentException('Bagian laporan tidak valid.');
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $section=transaction_report_section($koneksi,$periodStart,$periodEnd,$filter_q,$sort,$perPage,$sectionPages['semua']);
        $sectionQuery['section_page'][$type]=$section['page'];
        echo json_encode(['ok'=>true,'section'=>$type,'html'=>general_section_page_html($section,$sectionQuery),'page'=>$section['page'],'per_page'=>$section['per_page'],'total_rows'=>$section['row_count'],'total_pages'=>$section['total_pages'],'filter_key'=>general_filter_key($sectionQuery)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $error){http_response_code($error instanceof InvalidArgumentException?400:500);echo json_encode(['ok'=>false,'message'=>$error instanceof InvalidArgumentException?$error->getMessage():'Bagian laporan belum dapat dimuat. Silakan coba kembali.']);error_log('Report section: '.$error->getMessage());}
    exit;
}

$studentSearchSql = '';
$studentSearchParams = [];
if ($filter_q !== '') {
    $studentSearchSql = ' AND (s.NO_INDUK LIKE ? OR s.NAMA LIKE ? OR s.NO_induk_diknas LIKE ?)';
    $studentLike = '%' . $filter_q . '%';
    $studentSearchParams = [$studentLike, $studentLike, $studentLike];
}

$studentSearchSql .= unit_student_selection_where();
$studentOptions = $koneksi->query("
    SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS,
           mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
    WHERE s.is_active = 1
    ORDER BY s.NAMA ASC
")->fetch_all(MYSQLI_ASSOC);
$studentSearchDisplay = $filter_q;
$displayMatches=array_values(array_filter($studentOptions,static fn($o)=>(int)($_GET['student_id']??0)>0 ? (int)$o['student_id']===(int)$_GET['student_id'] : ($filter_q!==''&&($filter_q===$o['NO_INDUK']||$filter_q===(string)($o['NO_induk_diknas']??'')))));
if(count($displayMatches)===1)$studentSearchDisplay=$displayMatches[0]['NAMA'];

// The page and both export formats share the same scoped transaction readers.
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
$savings=general_savings_read($koneksi,$periodStart,$periodEnd,$filter_q);
$tab_masuk=(float)$savings['total_masuk'];$tab_keluar=(float)$savings['total_keluar'];
$savingsIncoming=['jml_tx'=>(int)$savings['count_masuk']];$savingsOutgoing=['jml_tx'=>(int)$savings['count_keluar']];
$section=transaction_report_section($koneksi,$periodStart,$periodEnd,$filter_q,$sort,$perPage,$sectionPages['semua']);
$bayar_recap=['total'=>$section['totals'][0]['value'],'jml_tx'=>$section['row_count']];
$generalSections=[$section];
$totalDetailRows=$section['row_count'];
$bayar_detail=$section['rows'];
$componentTotals=transaction_report_component_totals($koneksi,$periodStart,$periodEnd,$filter_q);
$koneksi->commit();
$sectionQuery['section_page']=['semua'=>$section['page']];

$financeTrend = finance_payment_trend($koneksi, $filter_tanggal_akhir, $studentSearchSql, $studentSearchParams);
$financeTrendMax = max(1, ...array_values($financeTrend));
$exportQuery = filter_build_query([
    'panel'=>$financePanel,
    'all_periods'=>$sectionQuery['all_periods'],'include_undated'=>$sectionQuery['include_undated'],
    'urut' => $sort,
    'unit' => $reportUnitId===0?'all':'active',
    'bulan' => $filter_bulan,
    'tahun' => $filter_tahun,
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
    'q' => $filter_q,
    'student_id' => max(0,(int)($_GET['student_id']??0)),
]);
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Laporan Transaksi | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
<script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" />
  <link rel="stylesheet" href="../assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>" />
  <link rel="stylesheet" href="../assets/css/finance_workspace.css?v=<?= filemtime(__DIR__.'/../assets/css/finance_workspace.css') ?>">
  <link rel="stylesheet" href="../assets/css/payment_details.css?v=<?= filemtime(__DIR__.'/../assets/css/payment_details.css') ?>">
  <link rel="stylesheet" href="../assets/css/workspace_readability.css?v=<?= filemtime(__DIR__.'/../assets/css/workspace_readability.css') ?>">
<link rel="stylesheet" href="../assets/css/menu_refinements.css?v=<?= filemtime(__DIR__.'/../assets/css/menu_refinements.css') ?>"><link rel="stylesheet" href="../assets/css/desktop_transaction_refinements.css?v=<?= filemtime(__DIR__.'/../assets/css/desktop_transaction_refinements.css') ?>"></head>
<body class="report-general-page" data-readable-workspace="finance">
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>

<div class="layout">
  <?php include '../includes/sidebar.php'; ?>

  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title">
        <h2>Laporan Transaksi</h2>
        <span class="breadcrumb">SistemSPP / Laporan</span>
      </div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>

    <div class="page-content finance-workspace">
      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" id="flash-msg" style="margin-bottom:16px;">
        <?= general_page_e($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <section class="main-card class-recap-card recap-report-shell report-general-shell" style="margin-bottom:16px;">
        <div class="recap-report-header">
          <div class="report-general-heading finance-hero">
            <div class="recap-report-copy">
              <span class="finance-breadcrumb">SistemSPP <span aria-hidden="true">&rsaquo;</span> Laporan Transaksi</span>
              <h1>Rekap Laporan Transaksi</h1>
              <p>Pembayaran sekolah untuk periode <?= general_page_e($periodLabel) ?>.</p>
            </div>
            <svg class="finance-hero-art" viewBox="0 0 540 110" fill="none" aria-hidden="true"><path d="M0 110Q80 45 150 72T280 35T400 65T540 15V110Z" fill="currentColor" opacity=".07"/><path d="M30 85Q100 45 155 62T280 28T410 60T520 20" stroke="currentColor" opacity=".35"/><g fill="currentColor" opacity=".12"><path d="M60 110V82h16v28M110 110V60h16v50M160 110V75h16v35M210 110V46h16v64M260 110V20h16v90M310 110V52h16v58M360 110V66h16v44M410 110V42h16v68M460 110V27h16v83M510 110V9h16v101"/></g></svg>
            <a class="report-general-catalog-link finance-catalog" href="global.php?unit=<?= $reportUnitId===0?'all':'active' ?>">
              <strong><?= finance_icon('chart') ?>Laporan Global <span aria-hidden="true">&rarr;</span></strong>
              <span class="finance-trend-copy">Pembayaran 7 hari hingga <?= general_page_e(spp_date_label($filter_tanggal_akhir)) ?></span>
              <span class="finance-spark" role="img" aria-label="<?= general_page_e(implode('; ',array_map(static fn($day,$n)=>spp_date_label($day).': '.$n.' transaksi',array_keys($financeTrend),array_values($financeTrend)))) ?>">
                <?php foreach($financeTrend as $day=>$n): ?><span style="--bar:<?= $n>0?max(8,round($n/$financeTrendMax*100)):2 ?>%" title="<?= general_page_e(spp_date_label($day).': '.$n.' transaksi') ?>"></span><?php endforeach; ?>
              </span>
            </a>
          </div>
        <form method="GET" class="recap-header-controls report-filter-card report-general-filter report-filter-grid<?= operational_role() ? ' has-scope' : '' ?>">
          <input type="hidden" name="panel" value="<?= $financePanel ?>" data-finance-panel-input>
          <input type="hidden" name="student_id" data-student-identity="1" value="<?= max(0,(int)($_GET['student_id']??0)) ?>">
          <div class="field-row finance-scope-field"><span class="field-label">Cakupan rekap</span>
            <?php if(operational_role() && unit_active_id()!==0): ?>
            <select name="unit" class="field-input field-select" onchange="unitSwitchReportScope(this)" aria-label="Cakupan rekap"><option value="active" <?= $reportUnitId!==0?'selected':'' ?>>Unit aktif: <?= general_page_e(unit_label(unit_active_id())) ?></option><option value="all" <?= $reportUnitId===0?'selected':'' ?>>Semua Unit</option></select>
            <?php else: ?><div class="finance-scope-value"><?= finance_icon('unit') ?><strong><?= general_page_e(unit_label($reportUnitId)) ?></strong></div><?php if($reportUnitId===0): ?><input type="hidden" name="unit" value="all"><?php endif; ?><?php endif; ?>
          </div>
          <div class="field-row report-date-range-field">
            <label class="field-label">Tanggal transaksi</label>
            <div class="report-date-range-control report-date-range-picker" data-range-picker data-empty-label="<?= general_page_e($periodLabel) ?>">
              <input type="hidden" name="tanggal_awal" value="<?= general_page_e($filter_tanggal_awal) ?>">
              <input type="hidden" name="tanggal_akhir" value="<?= general_page_e($filter_tanggal_akhir) ?>">
              <button type="button" class="report-date-range-button" aria-expanded="false">
                <span class="report-date-range-icon"><?= finance_icon('calendar') ?></span>
                <span class="report-date-range-value"><?= general_page_e($periodLabel) ?></span>
              </button>
              <div class="report-date-range-popover" hidden>
                <label>
                  <span>Mulai</span>
                  <input type="date" value="<?= general_page_e($filter_tanggal_awal) ?>" data-range-start aria-label="Tanggal transaksi mulai">
                </label>
                <label>
                  <span>Sampai</span>
                  <input type="date" value="<?= general_page_e($filter_tanggal_akhir) ?>" data-range-end aria-label="Tanggal transaksi sampai">
                </label>
                <div class="report-date-range-popover-actions">
                  <button type="button" class="btn btn-primary btn-sm" data-range-apply>Terapkan</button>
                </div>
              </div>
            </div>
          </div>
          <div class="field-row">
            <label class="field-label">Urutkan</label>
            <select class="field-input field-select" name="urut">
              <?php foreach ($sortOptions as $key => $label): ?>
              <option value="<?= general_page_e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= general_page_e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-row">
            <label class="field-label">Per Halaman</label>
            <select class="field-input field-select" name="per_page">
              <?php foreach ($allowedPageSizes as $pageSize): ?>
              <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-row full-span">
            <label class="field-label" for="report-siswa-search">Cari Siswa (Nama / NIS / NIS Diknas)</label>
            <div class="search-box">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="report-siswa-search" data-student-fit-viewport="1" data-student-search data-student-list="report-siswa-list" data-student-select-callback="selectReportStudentSearchOption" data-student-query-target="report-student-query" value="<?= general_page_e($studentSearchDisplay) ?>" placeholder="Ketik nama, NIS, atau NIS Diknas..." autocomplete="off">
              <input type="hidden" id="report-student-query" name="q" value="<?= general_page_e($filter_q) ?>">
            </div>
            <datalist id="report-siswa-list">
              <?php foreach ($studentOptions as $studentOption): ?>
              <?php $studentClassLabel = class_label(['tingkat' => $studentOption['master_tingkat'] ?: $studentOption['KELAS'], 'kode_rombel' => $studentOption['kode_rombel'] ?? 'BELUM', 'is_placeholder' => $studentOption['is_placeholder'] ?? 1]); ?>
              <option value="<?= general_page_e($studentOption['NAMA']) ?>"
                data-student-id="<?= (int)($studentOption['student_id']??0) ?>" data-unit-id="<?= (int)($studentOption['unit_id']??0) ?>" data-nis="<?= general_page_e($studentOption['NO_INDUK']) ?>"
                data-diknas="<?= general_page_e((string)($studentOption['NO_induk_diknas'] ?? '')) ?>"
                data-nama="<?= general_page_e($studentOption['NAMA']) ?>"
                data-kelas="<?= general_page_e($studentClassLabel) ?>">
                <?= general_page_e($studentOption['NAMA']) ?> (<?= general_page_e($studentClassLabel) ?>)
              </option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="report-period-options"><label><input type="checkbox" name="all_periods" value="1" <?= $sectionQuery['all_periods']==='1'?'checked':'' ?>> Semua periode</label><label><input type="checkbox" name="include_undated" value="1" <?= $sectionQuery['include_undated']==='1'?'checked':'' ?>> Sertakan transaksi tanpa tanggal sesuai periode kewajiban</label></div>
          <div class="report-general-actions">
            <div class="report-filter-actions">
              <button type="submit" class="btn btn-primary"><?= finance_icon('search') ?>Tampilkan Rekap</button>
              <a href="index.php?unit=<?= $reportUnitId===0?'all':'active' ?>&amp;panel=<?= $financePanel ?>" data-finance-reset class="btn btn-ghost"><?= finance_icon('reload') ?>Reset</a>
            </div>
            <div class="report-export-actions">
              <a href="export_excel.php?<?= general_page_e($exportQuery) ?>" class="btn btn-success" target="_blank" rel="noopener"><?= finance_icon('document') ?>Export Excel</a>
              <a href="export_pdf.php?<?= general_page_e($exportQuery) ?>&amp;output=preview&amp;return_to=report" class="btn btn-warning" target="_blank" rel="noopener"><?= finance_icon('document') ?>Export PDF</a>
            </div>
          </div>
        </form>
        </div>
      </section>

      <div class="finance-stats">
        <article class="finance-stat is-payment"><span class="finance-stat-icon"><?= finance_icon('card') ?></span><div><strong><?= general_page_money($bayar_recap['total']??0) ?></strong><span>Total Pembayaran</span><small><?= number_format((int)($bayar_recap['jml_tx']??0)) ?> transaksi</small></div></article>
        <article class="finance-stat is-in"><span class="finance-stat-icon"><?= finance_icon('in') ?></span><div><strong><?= general_page_money($tab_masuk) ?></strong><span>Tabungan Masuk</span><small><?= number_format((int)$savingsIncoming['jml_tx']) ?> transaksi</small></div></article>
        <article class="finance-stat is-out"><span class="finance-stat-icon"><?= finance_icon('out') ?></span><div><strong><?= general_page_money($tab_keluar) ?></strong><span>Tabungan Keluar</span><small><?= number_format((int)$savingsOutgoing['jml_tx']) ?> transaksi</small></div></article>
        <article class="finance-stat is-count"><span class="finance-stat-icon"><?= finance_icon('clock') ?></span><div><strong><?= number_format((int)($bayar_recap['jml_tx']??0)) ?></strong><span>Jumlah Transaksi Pembayaran</span><small><?= general_page_e($periodLabel) ?></small></div></article>
      </div>
      <p class="finance-summary-note">Ringkasan seluruh pembayaran dan mutasi tabungan pada periode, siswa, dan cakupan unit yang dipilih.</p>
      <nav class="finance-tabs" aria-label="Tampilan laporan transaksi">
        <?php foreach(['ringkasan'=>['chart','Ringkasan'],'komponen'=>['list','Komponen Pembayaran'],'transaksi'=>['file','Daftar Transaksi']] as $panel=>[$icon,$label]): $tabQuery=$sectionQuery;$tabQuery['panel']=$panel; ?>
        <a id="finance-tab-<?= $panel ?>" class="<?= $financePanel===$panel?'is-active':'' ?>" href="index.php?<?= general_page_e(filter_build_query($tabQuery)) ?>" data-finance-tab="<?= $panel ?>" aria-controls="finance-results" <?= $financePanel===$panel?'aria-current="page"':'' ?>><?= finance_icon($icon) ?><?= $label ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="finance-results finance-payment-results" id="finance-results" data-finance-panel="<?= $financePanel ?>">
      <section class="main-card finance-components" aria-labelledby="finance-components-title">
        <header class="card-header"><h3 class="card-title" id="finance-components-title">Rekap Komponen Pembayaran</h3><small class="finance-table-note"><?= general_page_e($periodLabel) ?></small></header>
        <div class="table-container"><table class="payment-table finance-components-table">
          <thead><tr><th scope="col">Komponen</th><th scope="col" class="nominal">Total (Rp)</th></tr></thead>
          <tbody><?php if(!$componentTotals): ?><tr><td colspan="2">Tidak ada pembayaran sesuai filter.</td></tr><?php endif; ?>
          <?php foreach($componentTotals as $component): ?><tr data-component-key="<?= general_page_e($component['component_key']) ?>" data-cents="<?= $component['cents'] ?>"><td><?= general_page_e($component['label']) ?></td><td class="nominal"><?= general_page_e(general_page_money($component['cents']/100)) ?></td></tr><?php endforeach; ?></tbody>
          <tfoot><tr><th scope="row">Total</th><td class="nominal"><?= general_page_e(general_page_money(array_sum(array_column($componentTotals,'cents'))/100)) ?></td></tr></tfoot>
        </table></div>
      </section>
      <div class="main-card finance-transactions" aria-labelledby="finance-transactions-title">
        <div class="card-header laporan-detail-header">
          <div class="laporan-detail-heading">
            <h3 class="card-title" id="finance-transactions-title">Hasil Laporan: Semua Transaksi</h3>
            <span class="badge-count"><?= number_format($totalDetailRows) ?> transaksi</span>
          </div>
          <small class="finance-table-note"><?= general_page_e($periodLabel) ?></small>
          <?php if ($bayar_detail): ?>
          <button type="submit" form="print-selected-form" class="btn btn-warning btn-print-selected" id="btn-print-selected" disabled><?= finance_icon('print') ?><span>Cetak Dipilih</span></button>
          <?php endif; ?>
        </div>

        <form method="GET" action="export_pdf.php" id="print-selected-form" target="_blank" rel="noopener"><input type="hidden" name="unit" value="<?= $reportUnitId===0?'all':'active' ?>">
          <input type="hidden" name="all_periods" value="<?= $sectionQuery['all_periods'] ?>"><input type="hidden" name="include_undated" value="<?= $sectionQuery['include_undated'] ?>"><input type="hidden" name="output" value="preview">
          <input type="hidden" name="bulan" value="<?= general_page_e($filter_bulan) ?>">
          <input type="hidden" name="tahun" value="<?= general_page_e($filter_tahun) ?>">
          <input type="hidden" name="tanggal_awal" value="<?= general_page_e($filter_tanggal_awal) ?>">
          <input type="hidden" name="tanggal_akhir" value="<?= general_page_e($filter_tanggal_akhir) ?>">
          <input type="hidden" name="q" value="<?= general_page_e($filter_q) ?>">
          <input type="hidden" name="mode" value="selected"><input type="hidden" name="urut" value="<?= general_page_e($sort) ?>">
          <input type="hidden" name="student_id" value="<?= max(0,(int)($_GET['student_id']??0)) ?>">
<?php foreach($generalSections as $section)echo general_section_page_html($section,$sectionQuery); ?></form>
      </div>
      </div>
    </div>
  </main>
</div>

<div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
<dialog class="payment-activity-dialog" id="payment-activity-dialog" data-endpoint="../pembayaran/aktivitas.php" aria-labelledby="payment-activity-title"><header class="payment-activity-dialog-header"><div><h3 id="payment-activity-title">Riwayat Aktivitas</h3><p data-activity-subtitle></p></div><button type="button" class="btn btn-ghost btn-sm" data-close-activity>Tutup</button></header><div class="payment-activity-content" aria-live="polite"></div></dialog>
<script src="../assets/js/payment_activity.js?v=<?= filemtime(__DIR__.'/../assets/js/payment_activity.js') ?>"></script>
<script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  autoHideFlash();
  document.querySelectorAll('.finance-row-menu .open-payment-activity').forEach(button => button.hidden = false);

});
</script>
<script src="../assets/js/report_sections.js?v=<?= filemtime(__DIR__.'/../assets/js/report_sections.js') ?>"></script>
</body>
</html>
