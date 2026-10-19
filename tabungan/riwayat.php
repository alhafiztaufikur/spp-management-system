<?php
// ============================================
// tabungan/riwayat.php — Riwayat Transaksi Tabungan
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/pagination.php';
require_once '../includes/savings_workspace.php';
require_once '../includes/legacy_finance.php';
require_once '../includes/legacy_full_read.php';
require_once '../includes/migrated_history.php';
requireRole(['admin', 'kasir', 'bendahara']);
if(is_file(__DIR__.'/../includes/savings_authorization.php'))require_once '../includes/savings_authorization.php';
require_once '../includes/savings_operator.php';
$operatorOptions=savings_operator_options($koneksi);
try{$operators=history_operator_choices($_GET['operator']??null,$operatorOptions);}
catch(InvalidArgumentException $e){http_response_code(400);exit(savings_e($e->getMessage()));}
require_once '../includes/history_class_filter.php';
$rombelFilter=history_class_filter($koneksi,$_GET);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Filter
$filter_nis = trim($_GET['nis'] ?? '');
$dateParam = static function (string $key): string {
    $value = trim((string)($_GET[$key] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
};
$filter_tanggal_awal = $dateParam('tanggal_awal') ?: date('Y-m-d', strtotime('-1 month'));
$filter_tanggal_akhir = $dateParam('tanggal_akhir') ?: date('Y-m-d');
if ($filter_tanggal_awal > $filter_tanggal_akhir) {
    [$filter_tanggal_awal, $filter_tanggal_akhir] = [$filter_tanggal_akhir, $filter_tanggal_awal];
}
$allowedPageSizes = [10, 25, 50];
$perPage = page_size_param('per_page', $allowedPageSizes, 10);
$page = page_int_param('page');

$selectedFilterNis = '';$selectedFilterUnit=0;
if ($filter_nis !== '') {
    $stmtIdentity=$koneksi->prepare('SELECT NO_INDUK,unit_id FROM siswa s WHERE (NO_INDUK=? OR NO_induk_diknas=? OR NAMA=?)'.unit_student_selection_where());
    $stmtIdentity->bind_param('sss',$filter_nis,$filter_nis,$filter_nis);$stmtIdentity->execute();$identities=$stmtIdentity->get_result()->fetch_all(MYSQLI_ASSOC);$stmtIdentity->close();
    if(count($identities)===1){$selectedFilterNis=$identities[0]['NO_INDUK'];$selectedFilterUnit=(int)$identities[0]['unit_id'];}
}

// One owner-aware projection feeds the summary, page and detail endpoints.
$savingsSource=mh_savings_source($koneksi);
$periodStart=$filter_tanggal_awal.' 00:00:00';$periodEnd=date('Y-m-d H:i:s',strtotime($filter_tanggal_akhir.' +1 day'));
require_once '../includes/migrated_savings.php';
$historyRead=mh_savings_read_query($koneksi,$_GET,$rombelFilter,$operators,$periodStart,$periodEnd);
$historyUnionSql=$historyRead['sql'];$historyParams=$historyRead['args'];
$historyTypes=str_repeat('s',count($historyParams));
$stmtSummary = $koneksi->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(nominal), 0) AS total_masuk, COALESCE(SUM(keluar), 0) AS total_keluar FROM ($historyUnionSql) tabungan_tx");
if($historyParams)$stmtSummary->bind_param($historyTypes, ...$historyParams);
$stmtSummary->execute();
$historySummary = $stmtSummary->get_result()->fetch_assoc() ?: ['total'=>0, 'total_masuk'=>0, 'total_keluar'=>0];
$stmtSummary->close();
$totalHistoryRows = (int)$historySummary['total'];
$totalPages = total_pages($totalHistoryRows, $perPage);
$page = min($page, $totalPages);
$wantedId=(int)($_GET['id']??0);$wantedKind=$_GET['jenis']??'';
if($wantedId&&in_array($wantedKind,['masuk','keluar'],true)){
    $target=lf_one($koneksi,"SELECT * FROM ($historyUnionSql) z WHERE z.id=? AND z.jenis=?",array_merge($historyParams,[$wantedId,$wantedKind]));
    if($target){$rank=lf_one($koneksi,"SELECT COUNT(*) n FROM ($historyUnionSql) z WHERE z.TANGGAL>? OR (z.TANGGAL<=>? AND (z.jenis<? OR (z.jenis=? AND z.id>?))) OR (? IS NULL AND z.TANGGAL IS NOT NULL)",array_merge($historyParams,[$target['TANGGAL'],$target['TANGGAL'],$target['jenis'],$target['jenis'],$target['id'],$target['TANGGAL']]));$page=intdiv((int)$rank['n'],$perPage)+1;}
}
$_GET['page']=$page;
$offset = ($page - 1) * $perPage;

$sql = "$historyUnionSql ORDER BY TANGGAL DESC, jenis, id DESC LIMIT ? OFFSET ?";
$stmt = $koneksi->prepare($sql);
$pageTypes = $historyTypes . 'ii';
$pageParams = array_merge($historyParams, [$perPage, $offset]);
$stmt->bind_param($pageTypes, ...$pageParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
require_once '../includes/kelas.php';
foreach($rows as &$savingRow)$savingRow['kelas_label']=class_label($savingRow);unset($savingRow);

// Only the displayed recap page is loaded; the search accepts arbitrary owner text.
require_once '../includes/migrated_savings.php';
$saldo_list=[];$selected_saldo=null;
$historyPaginationQuery = pagination_query([
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
    'nis' => $filter_nis,
    'per_page' => $perPage,
    'operator' => $operators,
    'rombel' => $rombelFilter['values'],
    'all_periods'=>($_GET['all_periods']??'')==='1'?'1':null,
    'owner_id'=>max(0,(int)($_GET['owner_id']??0)),
]);

if(is_file(__DIR__.'/../includes/savings_authorization.php'))require_once '../includes/savings_authorization.php';
$archiveView=($_GET['view']??'active')==='archive';
if($archiveView&&function_exists('savings_authorization_ready')&&savings_authorization_ready($koneksi)){
    $archiveOwner=savings_operator_owner_sql('a.unit_id','a.jenis','a.transaction_id',"JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.transaction.user_id'))");
    $archiveOperatorWhere=savings_operator_where($operators,$archiveOwner).history_class_archive_where($rombelFilter,'a.unit_id',"JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.transaction.NO_INDUK'))");
    $s=$koneksi->prepare("SELECT a.jenis,a.transaction_id id,a.before_snapshot,a.unit_id FROM tabungan_aktivitas a WHERE a.action='deleted'
        AND JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.transaction.TANGGAL'))>=? AND JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.transaction.TANGGAL'))<?
        AND (?='' OR JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.transaction.NO_INDUK'))=? OR JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.transaction.NAMA')) LIKE ?)
        $archiveOperatorWhere ORDER BY a.occurred_at DESC,a.id DESC");
    $like='%'.$filter_nis.'%';$s->bind_param('sssss',$periodStart,$periodEnd,$filter_nis,$filter_nis,$like);$s->execute();$archived=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $historySummary=['total_masuk'=>0,'total_keluar'=>0];foreach($archived as $a){$tx=json_decode($a['before_snapshot'],true)['transaction'];$historySummary['total_masuk']+=(float)$tx['MASUK'];$historySummary['total_keluar']+=(float)$tx['KELUAR'];}
    $totalHistoryRows=count($archived);$totalPages=total_pages($totalHistoryRows,$perPage);$page=min($page,$totalPages);$offset=($page-1)*$perPage;$rows=[];
    foreach(array_slice($archived,$offset,$perPage) as $a){$row=json_decode($a['before_snapshot'],true)['transaction'];$row['nominal']=$row['MASUK'];$row['keluar']=$row['KELUAR'];$row['deleted']=true;$rows[]=$row;}
    $historyPaginationQuery['view']='archive';
}
$total_masuk=(float)$historySummary['total_masuk'];$total_keluar=(float)$historySummary['total_keluar'];
$rows=savings_enrich_rows($koneksi,$rows);
$requestedSelection=isset($_GET['id'])||isset($_GET['jenis']);
$selectedTransaction=null;
if($requestedSelection){try{[$selectionKind,$selectionId]=savings_http_identity();$selectedTransaction=savings_transaction($koneksi,$selectionKind,$selectionId);}catch(InvalidArgumentException $e){http_response_code(400);}}else{$selectedTransaction=$rows[0]??null;}
if($selectedTransaction&&!array_filter($rows,static fn($r)=>(int)$r['id']===(int)$selectedTransaction['id']&&$r['jenis']===$selectedTransaction['jenis']))$selectedTransaction=null;
$originBack=$selectedTransaction&&!empty($selectedTransaction['student_id'])?mh_return_url($_GET['back_context']??null,(int)$selectedTransaction['unit_id'],'student',(int)$selectedTransaction['student_id']):null;
$printTransaction=null;$prompt=$_SESSION['savings_print_prompt']??null;unset($_SESSION['savings_print_prompt']);
if(is_array($prompt) && (unit_all_readonly() || (int)$prompt['unit_id']===unit_active_id())){$candidate=savings_transaction($koneksi,(string)$prompt['jenis'],(int)$prompt['id']);if($candidate && $candidate['can_print'])$printTransaction=$candidate;}
unset($historyPaginationQuery['id'],$historyPaginationQuery['jenis']);
$bln_names = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
               '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$firstShown = $totalHistoryRows > 0 ? $offset + 1 : 0;
$lastShown = $totalHistoryRows > 0 ? min($offset + count($rows), $totalHistoryRows) : 0;
$periodLabel = $filter_tanggal_awal === $filter_tanggal_akhir
    ? spp_date_label($filter_tanggal_awal)
    : spp_date_label($filter_tanggal_awal) . ' - ' . spp_date_label($filter_tanggal_akhir);
if(($_GET['all_periods']??'')==='1')$periodLabel='Semua periode';
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Tabungan | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" />
  <link rel="stylesheet" href="../assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>" />
<link rel="stylesheet" href="../assets/css/migrated_history.css?v=<?= filemtime(__DIR__.'/../assets/css/migrated_history.css') ?>">
<link rel="stylesheet" href="../assets/css/savings_workspace.css?v=<?= filemtime(__DIR__.'/../assets/css/savings_workspace.css') ?>">
<link rel="stylesheet" href="../assets/css/history_rombel_refinement.css?v=<?= filemtime(__DIR__.'/../assets/css/history_rombel_refinement.css') ?>">
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout">
  <?php include '../includes/sidebar.php'; ?>

  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title">
        <h2>Riwayat Tabungan</h2>
        <span class="breadcrumb">SistemSPP / Tabungan / Riwayat</span>
      </div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>

    <?php if ($flash): ?>
    <div id="flash-msg" class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="margin:16px 20px 0;">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <div class="page-content sw-page">

<div class="sw-stats"><?php foreach([['up',$total_masuk,$archiveView?'Total Masuk (Arsip)':'Total Masuk (Filter Aktif)','is-in'],['down',$total_keluar,$archiveView?'Total Keluar (Arsip)':'Total Keluar (Filter Aktif)','is-out'],['document',$totalHistoryRows,'Total Transaksi','is-count']] as $stat): ?><div class="sw-card sw-stat <?= $stat[3] ?>"><span class="sw-head-icon"><?= savings_icon($stat[0]) ?></span><div><strong><?= $stat[0]==='document'?number_format($stat[1]):savings_money($stat[1]) ?></strong><small><?= $stat[2] ?></small></div></div><?php endforeach; ?></div>
<?php if($originBack): ?><p><a class="btn btn-ghost" href="../<?= savings_e($originBack) ?>">Kembali ke Data Siswa</a></p><?php endif; ?><section class="sw-card sw-filter-card">        <form method="GET" class="sw-filter-form"><input type="hidden" name="view" value="<?= $archiveView?'archive':'active' ?>">

          <div class="field-row report-date-range-field"><label class="field-label">Tanggal Transaksi</label><div class="report-date-range-control report-date-range-picker" data-range-picker data-empty-label="<?= htmlspecialchars($periodLabel) ?>"><input type="hidden" name="tanggal_awal" value="<?= htmlspecialchars($filter_tanggal_awal) ?>"><input type="hidden" name="tanggal_akhir" value="<?= htmlspecialchars($filter_tanggal_akhir) ?>"><button type="button" class="report-date-range-button" aria-expanded="false"><span class="report-date-range-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><span class="report-date-range-value"><?= htmlspecialchars($periodLabel) ?></span></button><div class="report-date-range-popover" hidden><label><span>Mulai</span><input type="date" value="<?= htmlspecialchars($filter_tanggal_awal) ?>" data-range-start></label><label><span>Sampai</span><input type="date" value="<?= htmlspecialchars($filter_tanggal_akhir) ?>" data-range-end></label><div class="report-date-range-popover-actions"><button type="button" class="btn btn-primary btn-sm" data-range-apply>Terapkan</button></div></div></div></div><div class="field-row sw-filter-student"><label class="field-label" for="savings-history-student">Cari Nama / NIS / Identitas Sumber</label><div class="search-box"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="savings-history-student" data-student-fit-viewport="1" data-student-search data-student-list="savings-history-student-list" data-student-select-callback="selectReportStudentSearchOption" data-student-query-target="savings-history-nis" placeholder="Ketik nama, NIS, atau NIS Diknas..." value="<?= htmlspecialchars($selected_saldo['NAMA'] ?? $filter_nis) ?>" autocomplete="off"><input type="hidden" name="owner_id" value="<?= max(0,(int)($_GET['owner_id']??0)) ?>"><input type="hidden" name="student_id" data-student-identity="1" value="<?= (int)($_GET['student_id']??0) ?>"><input type="hidden" id="savings-history-nis" name="nis" value="<?= htmlspecialchars($filter_nis) ?>"></div><datalist id="savings-history-student-list"><?php foreach ($saldo_list as $studentOption): ?><option value="<?= htmlspecialchars($studentOption['NAMA']) ?>" data-student-id="<?= (int)($studentOption['student_id']??0) ?>" data-unit-id="<?= (int)($studentOption['unit_id']??0) ?>" data-nis="<?= htmlspecialchars($studentOption['NO_INDUK']) ?>" data-diknas="<?= htmlspecialchars((string)($studentOption['NO_induk_diknas'] ?? '')) ?>" data-nama="<?= htmlspecialchars($studentOption['NAMA']) ?>" data-kelas="<?= htmlspecialchars((unit_all_readonly()?unit_label((int)$studentOption['unit_id']).' · ':'').$studentOption['KELAS']) ?>"></option><?php endforeach; ?></datalist></div><?php history_operator_field($operatorOptions,'savings-history-operator'); history_class_field($rombelFilter,'savings-history-rombel'); ?><div class="field-row tabungan-filter-page">
            <label class="field-label">Per Halaman</label>
            <select class="field-input field-select" name="per_page" aria-label="Jumlah transaksi tabungan per halaman">
              <?php foreach ($allowedPageSizes as $pageSize): ?>
              <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if(($_GET['all_periods']??'')==='1'): ?><input type="hidden" name="all_periods" value="1"><?php endif; ?>
          <div class="sw-filter-actions"><a class="btn btn-ghost" href="riwayat.php?<?= savings_e(http_build_query(array_replace($_GET,['all_periods'=>'1','page'=>1,'id'=>null,'jenis'=>null]))) ?>">Semua Periode</a>
            <button type="submit" name="all_periods" value="0" class="btn btn-primary"><?= savings_icon('check') ?>Tampilkan Rekap</button>
            <a href="riwayat.php" class="btn btn-ghost"><?= savings_icon('reload') ?>Reset</a>
            <?php if (hasRole(['admin','kasir'])): ?>
            <a href="masuk.php" class="btn btn-primary"><?= savings_icon('up') ?>Tabungan Masuk</a>
            <a href="keluar.php" class="btn btn-warning"><?= savings_icon('down') ?>Tabungan Keluar</a>
<?php if(!$archiveView): ?><a class="btn btn-ghost" href="export_history.php?<?= savings_e(http_build_query(array_replace($_GET,['format'=>'xlsx']))) ?>">Excel - Semua Hasil</a><a class="btn btn-ghost" href="export_history.php?<?= savings_e(http_build_query(array_replace($_GET,['format'=>'pdf']))) ?>">Cetak Halaman Ini</a>            <a href="<?= savings_e('../laporan/template.php?'.http_build_query(['template'=>'tabungan-siswa','tanggal_awal'=>$filter_tanggal_awal,'tanggal_akhir'=>$filter_tanggal_akhir,'q'=>$filter_nis,'student_id'=>max(0,(int)($_GET['student_id']??0)),'owner_id'=>max(0,(int)($_GET['owner_id']??0)),'all_periods'=>$_GET['all_periods']??'0','kelas'=>$rombelFilter['values'],'operator'=>$operators,'siswa_status'=>'all'])) ?>" class="btn btn-ghost"><?= savings_icon('print') ?>Cetak Laporan</a><?php endif; ?>
            <?php endif; ?>
          </div>
          <noscript><label>Tanggal mulai<input class="field-input" type="date" name="tanggal_awal" value="<?= savings_e($filter_tanggal_awal) ?>"></label><label>Tanggal akhir<input class="field-input" type="date" name="tanggal_akhir" value="<?= savings_e($filter_tanggal_akhir) ?>"></label><label>Nama / identitas<input class="field-input" name="nis" value="<?= savings_e($filter_nis) ?>"></label></noscript>
        </form></section>
<?php include __DIR__.'/../includes/savings_history_list.php'; ?>
      <?php mh_account_recap($koneksi,$_GET); ?>

    </div>
  </main>
</div>
<div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
<script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>

<script src="../assets/js/savings_history.js?v=<?= filemtime(__DIR__.'/../assets/js/savings_history.js') ?>"></script>
<?php if(is_file(__DIR__.'/../assets/js/savings_correction.js')): ?><script src="../assets/js/savings_correction.js?v=<?= filemtime(__DIR__.'/../assets/js/savings_correction.js') ?>"></script><?php endif; ?>
</body>
</html>
