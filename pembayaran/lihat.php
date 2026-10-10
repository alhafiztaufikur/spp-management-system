<?php
// ============================================
// pembayaran/lihat.php - View All Payments
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/pagination.php';
require_once '../includes/transaction_authorization.php';
require_once '../includes/payment_archive.php';
require_once '../includes/payment_history.php';
require_once '../includes/history_operator_filter.php';
requireRole(['admin', 'kasir', 'bendahara']);
if (empty($_SESSION['csrf_payment'])) $_SESSION['csrf_payment'] = bin2hex(random_bytes(32));

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$printPayment = is_array($flash['print_payment'] ?? null) ? $flash['print_payment'] : null;
$printPaymentId = max(0, (int)($printPayment['id'] ?? 0));
$printPaymentMonth = str_pad((string)(int)($printPayment['bulan'] ?? 0), 2, '0', STR_PAD_LEFT);
$printPaymentYear = (string)(int)($printPayment['tahun'] ?? 0);
$isUpdatedPayment = ($printPayment['source'] ?? '') === 'update';
$printBatchToken = strtolower(trim((string)($printPayment['batch'] ?? '')));
$printReceiptCount = max(1, (int)($printPayment['count'] ?? 1));
$isAnnualPayment = $printReceiptCount === 12 && preg_match('/^[a-f0-9]{32}$/', $printBatchToken);
$showPrintPrompt = $printPaymentId > 0
    && preg_match('/^(0[1-9]|1[0-2])$/', $printPaymentMonth)
    && preg_match('/^\d{4}$/', $printPaymentYear);
$printPaymentUrl = $isAnnualPayment
    ? '../laporan/cetak_struk_tahunan.php?' . http_build_query(['batch' => $printBatchToken])
    : '../laporan/cetak_struk.php?' . http_build_query(['id' => $printPaymentId]);

function month_code($value) {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

function format_payment_datetime($value): array { return spp_date_parts($value); }

function payment_was_updated($createdAt, $updatedAt): bool {
    $created = strtotime((string)$createdAt);
    $updated = strtotime((string)$updatedAt);
    return $created && $updated && $updated > ($created + 1);
}

// Filter
$search     = trim($_GET['search'] ?? '');
$dateParam = static function (string $key): string {
    $value = trim((string)($_GET[$key] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
};
$filter_tanggal = $dateParam('tanggal');
$filter_tanggal_awal = $dateParam('tanggal_awal') ?: ($filter_tanggal ?: date('Y-m-01'));
$filter_tanggal_akhir = $dateParam('tanggal_akhir') ?: ($filter_tanggal ?: date('Y-m-d'));
if ($filter_tanggal_awal > $filter_tanggal_akhir) {
    [$filter_tanggal_awal, $filter_tanggal_akhir] = [$filter_tanggal_akhir, $filter_tanggal_awal];
}
$allowedPageSizes = [10, 25, 50];
$perPage = page_size_param('per_page', $allowedPageSizes, 10);
$page = page_int_param('page');
$operatorOptions = history_operator_options($koneksi);
try { $operators = history_operator_choices($_GET['operator'] ?? null, $operatorOptions); }
catch (InvalidArgumentException $error) { http_response_code(400); exit(htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8')); }

$isArchive = ($_GET['view'] ?? '') === 'deleted';
if ($isArchive) {
    $archive = payment_archive_page($koneksi, $filter_tanggal_awal, $filter_tanggal_akhir, $search,
        max(0,(int)($_GET['student_id']??0)), $page, $perPage, $operators);
    $paymentRows = $archive['rows']; $totalPayments = $archive['total'];
    $totalPages = $archive['pages']; $page = $archive['page']; $offset = $archive['offset'];
} else {
$where = "WHERE 1=1".unit_student_selection_where();
$params = [];
$types  = '';
$where .= history_operator_where($koneksi,$operators,'p.id','p.unit_id');
$where .= " AND p.TGL_BYR >= ? AND p.TGL_BYR < ?";
$params[] = $filter_tanggal_awal . ' 00:00:00';
$params[] = date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'));
$types .= 'ss';
if ($search) {
    $like = "%$search%";
    $where .= " AND (s.NAMA LIKE ? OR s.NO_INDUK LIKE ? OR s.NO_induk_diknas LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

$countSql = "SELECT COUNT(*) AS total FROM bayar p
        JOIN siswa s ON s.NO_INDUK = p.NO_INDUK AND s.unit_id=p.unit_id
        $where";
$stmtCount = $koneksi->prepare($countSql);
if ($params) { $stmtCount->bind_param($types, ...$params); }
$stmtCount->execute();
$totalPayments = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
$stmtCount->close();

$totalPages = total_pages($totalPayments, $perPage);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.*, s.NO_INDUK, s.NO_induk_diknas, s.NAMA,
        COALESCE(NULLIF(p.kelas_rombel_snapshot,''),NULLIF(p.KELAS,''),s.KELAS) AS kelas_transaksi FROM bayar p
        JOIN siswa s ON s.NO_INDUK = p.NO_INDUK AND s.unit_id=p.unit_id
        $where ORDER BY p.created_at DESC,p.id DESC
        LIMIT ? OFFSET ?";

$stmt = $koneksi->prepare($sql);
$pageParams = array_merge($params, [$perPage, $offset]);
$pageTypes = $types . 'ii';
$stmt->bind_param($pageTypes, ...$pageParams);
$stmt->execute();
$result = $stmt->get_result();


    $paymentRows = $result->fetch_all(MYSQLI_ASSOC);
}
$paymentPaginationQuery = pagination_query(['per_page' => $perPage, 'view'=>$isArchive?'deleted':'active']);
unset($paymentPaginationQuery['selected']);
$activityGroups = payment_activity_for_payments($koneksi, array_column($paymentRows, 'id'));
$tabQuery = $_GET; unset($tabQuery['page']);
$activeTabUrl = 'lihat.php?' . http_build_query(array_merge($tabQuery,['view'=>'active']));
$archiveTabUrl = 'lihat.php?' . http_build_query(array_merge($tabQuery,['view'=>'deleted']));

$selectedId=max(0,(int)($_GET['selected']??0));
$selectedRow=null;
foreach($paymentRows as $candidate) if((int)$candidate['id']===$selectedId) $selectedRow=$candidate;
$selectedRow ??= $paymentRows[0]??null;
$selectedId=(int)($selectedRow['id']??0);
$selectedModel=null;
if($selectedRow) {
    $scope=unit_active_id();
    unit_set_context($koneksi,(int)$selectedRow['unit_id']);
    try { $selectedModel=payment_history_model($koneksi,$selectedId,$isArchive); }
    catch(Throwable $error) { $detailError=$error->getMessage(); }
    unit_set_context($koneksi,$scope);
}
if($showPrintPrompt) {
    try {
        payment_assert_owner($koneksi,$printPaymentId,(int)$_SESSION['admin_id']);
        if($isAnnualPayment && !payment_can_print_batch($koneksi,$printBatchToken)) $showPrintPrompt=false;
    } catch(Throwable $error) { $showPrintPrompt=false; }
}

// Months list
$bln_list = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$firstShown = $totalPayments > 0 ? $offset + 1 : 0;
$lastShown = $totalPayments > 0 ? min($offset + $perPage, $totalPayments) : 0;
$periodLabel = $filter_tanggal_awal === $filter_tanggal_akhir
    ? spp_date_label($filter_tanggal_awal)
    : spp_date_label($filter_tanggal_awal) . ' - ' . spp_date_label($filter_tanggal_akhir);
$studentOptions = $koneksi->query("SELECT s.id AS student_id,s.NO_INDUK,s.unit_id,s.NO_induk_diknas,s.NAMA,s.KELAS FROM siswa s WHERE s.is_active=1 ORDER BY s.NAMA")->fetch_all(MYSQLI_ASSOC);
$studentSearchDisplay = $search;
$displayMatches=array_values(array_filter($studentOptions,static fn($o)=>(int)($_GET['student_id']??0)>0 ? (int)$o['student_id']===(int)$_GET['student_id'] : ($search!==''&&($search===$o['NO_INDUK']||$search===(string)($o['NO_induk_diknas']??'')))));
if(count($displayMatches)===1)$studentSearchDisplay=$displayMatches[0]['NAMA'];
filter_output_start();
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Pembayaran | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <meta name="description" content="Lihat semua data transaksi pembayaran siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" />
  <link rel="stylesheet" href="../assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>" />
  <link rel="stylesheet" href="../assets/css/payment_details.css?v=<?= filemtime(__DIR__ . "/../assets/css/payment_details.css") ?>">
  <link rel="stylesheet" href="../assets/css/payment_history.css?v=<?= filemtime(__DIR__.'/../assets/css/payment_history.css') ?>">
  <link rel="stylesheet" href="../assets/css/workspace_readability.css?v=<?= filemtime(__DIR__.'/../assets/css/workspace_readability.css') ?>">
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="payment-history-page" data-readable-workspace="payment-history">

  <div class="bg-orbs">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Riwayat Pembayaran Siswa</h2>
          <span class="breadcrumb">SistemSPP / Pembayaran / Riwayat</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>" id="flash-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($flash['type'] === 'success'): ?><polyline points="20 6 9 17 4 12"/>
          <?php else: ?><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          <?php endif; ?>
        </svg>
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <?php if ($showPrintPrompt): ?>
      <div class="modal-overlay show" id="receipt-print-modal" role="dialog" aria-modal="true" aria-labelledby="receipt-print-title">
        <div class="modal-box">
          <div class="modal-icon" aria-hidden="true">🧾</div>
          <h3 class="modal-title" id="receipt-print-title"><?= $isAnnualPayment ? 'Cetak 12 struk pembayaran?' : ($isUpdatedPayment ? 'Cetak ulang struk pembayaran?' : 'Cetak struk pembayaran?') ?></h3>
          <p class="modal-body"><?= $isAnnualPayment
              ? 'Pembayaran tahunan sudah dibagi menjadi 12 transaksi. Semua struk Januari–Desember dapat dicetak sekaligus.'
              : ($isUpdatedPayment
              ? 'Perubahan pembayaran sudah tersimpan. Cetak ulang struk agar isinya sesuai dengan data terbaru.'
              : 'Pembayaran sudah tersimpan. Kamu bisa langsung membuka struk transaksi ini tanpa memilihnya lagi dari halaman Laporan.') ?></p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" id="receipt-print-later">Tidak, nanti</button>
            <button type="button" class="btn btn-primary" id="receipt-print-now"><?= $isAnnualPayment ? 'Ya, cetak 12 struk' : ($isUpdatedPayment ? 'Ya, cetak ulang' : 'Ya, cetak struk') ?></button>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="ph-page-content">
      <header class="ph-hero"><div class="ph-hero-copy"><span class="ph-hero-icon"><?= authorization_icon('receipt') ?></span><div><h1>Riwayat Pembayaran Siswa</h1><p>Kelola dan pantau seluruh riwayat transaksi pembayaran siswa.</p></div></div><nav class="ph-tabs" aria-label="Jenis riwayat pembayaran"><a href="<?= htmlspecialchars($activeTabUrl) ?>" <?= !$isArchive?'aria-current="page"':'' ?>>Transaksi Aktif</a><a href="<?= htmlspecialchars($archiveTabUrl) ?>" <?= $isArchive?'aria-current="page"':'' ?>>Transaksi Dihapus</a></nav></header>
      <section class="ph-filter-card">        <form method="GET" action="lihat.php" class="ph-filter-form"><input type="hidden" name="student_id" data-student-identity="1" value="<?= max(0,(int)($_GET['student_id']??0)) ?>">
          <input type="hidden" name="view" value="<?= $isArchive ? "deleted" : "active" ?>">
          <div class="ph-filter-intro"><?= authorization_icon('filter') ?><div><strong>Filter Riwayat</strong><small>Pilih periode, siswa, dan operator yang ingin ditampilkan.</small></div></div>
          <div class="field-row report-date-range-field">
            <label class="field-label"><?= $isArchive ? "Tanggal Penghapusan" : "Tanggal Transaksi" ?></label>
            <div class="report-date-range-control report-date-range-picker" data-range-picker data-empty-label="<?= htmlspecialchars($periodLabel) ?>">
              <input type="hidden" name="tanggal_awal" value="<?= htmlspecialchars($filter_tanggal_awal) ?>">
              <input type="hidden" name="tanggal_akhir" value="<?= htmlspecialchars($filter_tanggal_akhir) ?>">
              <button type="button" class="report-date-range-button" aria-expanded="false">
                <span class="report-date-range-icon">📅</span>
                <span class="report-date-range-value"><?= htmlspecialchars($periodLabel) ?></span>
              </button>
              <div class="report-date-range-popover" hidden>
                <label><span>Mulai</span><input type="date" value="<?= htmlspecialchars($filter_tanggal_awal) ?>" data-range-start></label>
                <label><span>Sampai</span><input type="date" value="<?= htmlspecialchars($filter_tanggal_akhir) ?>" data-range-end></label>
                <div class="report-date-range-popover-actions"><button type="button" class="btn btn-primary btn-sm" data-range-apply>Terapkan</button></div>
              </div>
            </div>
          </div>
          <div class="field-row ph-search-field">
            <label class="field-label" for="search-lihat">Cari Siswa (Nama / NIS / NIS Diknas)</label>
            <div class="search-box">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="search-lihat" data-student-search data-student-list="payment-history-siswa-list" data-student-select-callback="selectReportStudentSearchOption" data-student-query-target="payment-history-student-query" placeholder="Ketik nama, NIS, atau NIS Diknas..." value="<?= htmlspecialchars($studentSearchDisplay) ?>" autocomplete="off">
              <input type="hidden" id="payment-history-student-query" name="search" value="<?= htmlspecialchars($search) ?>">
            </div>
            <datalist id="payment-history-siswa-list">
              <?php foreach ($studentOptions as $studentOption): ?>
              <option value="<?= htmlspecialchars($studentOption['NAMA']) ?>" data-student-id="<?= (int)($studentOption['student_id']??0) ?>" data-unit-id="<?= (int)($studentOption['unit_id']??0) ?>" data-nis="<?= htmlspecialchars($studentOption['NO_INDUK']) ?>" data-diknas="<?= htmlspecialchars((string)($studentOption['NO_induk_diknas'] ?? '')) ?>" data-nama="<?= htmlspecialchars($studentOption['NAMA']) ?>" data-kelas="<?= htmlspecialchars((unit_all_readonly()?unit_label((int)$studentOption['unit_id']).' · ':'').$studentOption['KELAS']) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <?php history_operator_field($operatorOptions,'payment-history-operator'); ?>
          <div class="field-row"><label class="field-label">Data per halaman</label><select class="field-input field-select filter-sel" name="per_page" aria-label="Jumlah pembayaran per halaman">
            <?php foreach ($allowedPageSizes as $pageSize): ?>
            <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?> / halaman</option>
            <?php endforeach; ?>
          </select></div>
          <div class="ph-filter-actions">
            <button type="submit" class="btn btn-primary" id="btn-filter">Tampilkan Rekap</button>
            <a href="lihat.php?view=<?= $isArchive ? "deleted" : "active" ?>" class="btn btn-ghost" id="btn-reset-filter">Reset</a>
            <?php if(hasRole(['admin','kasir']) && !unit_all_readonly()): ?><a href="form.php" class="btn btn-primary" id="btn-tambah">+ Tambah Baru</a><?php endif; ?>
          </div>
        </form></section>
      <div class="ph-workspace" data-payment-workspace data-view="<?= $isArchive?'deleted':'active' ?>">
      <section class="ph-list-panel"><header class="ph-panel-heading"><div><h3><?= authorization_icon('history') ?> Daftar Transaksi</h3><p>Menampilkan <?= $firstShown ?>&ndash;<?= $lastShown ?> dari <?= number_format($totalPayments) ?> transaksi<?= $isArchive?' dihapus':'' ?>.</p></div><div class="ph-list-nav"><small><?= $firstShown ?>&ndash;<?= $lastShown ?> dari <?= number_format($totalPayments) ?></small><?php foreach(['Sebelumnya'=>$page-1,'Berikutnya'=>$page+1] as $label=>$target): ?><a class="btn btn-ghost btn-sm <?= $target<1||$target>$totalPages?'is-disabled':'' ?>" aria-label="Halaman <?= strtolower($label) ?>" <?= $target<1||$target>$totalPages?'aria-disabled="true" tabindex="-1"':'' ?> href="<?= $target>=1&&$target<=$totalPages?'lihat.php?'.htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$target,'selected'=>null]))):'#' ?>"><?= authorization_icon($label==='Sebelumnya'?'left':'right') ?></a><?php endforeach; ?></div></header>
      <div class="ph-record-list">
      <?php foreach($paymentRows as $i=>$row): $id=(int)$row['id'];$href='lihat.php?'.http_build_query(array_merge($_GET,['selected'=>$id])); ?>
      <a href="<?= authorization_escape($href) ?>" class="ph-record <?= $id===$selectedId?'is-selected':'' ?> <?= $isArchive?'is-deleted':'' ?>" data-payment-record data-id="<?= $id ?>" data-unit="<?= (int)$row['unit_id'] ?>" <?= $id===$selectedId?'aria-current="true"':'' ?>><span class="ph-record-number"><?= $offset+$i+1 ?></span><span class="ph-record-icon"><?= authorization_icon('student') ?></span><div class="ph-record-identity"><strong><?= authorization_escape($row['NO_INDUK']) ?></strong><span><?= authorization_escape($row['NAMA']) ?></span><small>Diknas <?= authorization_escape($row['NO_induk_diknas']??'Tidak tercatat') ?></small><?= unit_record_badge($row) ?></div><span class="kelas-badge">Kelas <?= authorization_escape($row['kelas_transaksi']) ?></span><div class="ph-record-period"><small>Periode</small><span><?= authorization_icon('calendar') ?> <?= authorization_escape(payment_history_period($row)) ?></span></div><div class="ph-record-total"><small>Total Bayar</small><strong><?= payment_history_money($row['total_jumlah']) ?></strong></div><span class="ph-status <?= $isArchive?'is-deleted':'' ?>"><?= $isArchive?'Dihapus':'Aktif' ?></span><?= authorization_icon('right') ?></a>
      <?php endforeach; ?>
      <?php if(!$paymentRows): ?><div class="ph-empty"><?= authorization_icon('history') ?><h3>Tidak ada transaksi sesuai filter</h3><p><?= $isArchive?'Arsip menggunakan tanggal penghapusan.':'Ubah periode atau pencarian siswa untuk melihat transaksi lain.' ?></p></div><?php endif; ?>
      </div>
      <?php render_pagination('lihat.php',$paymentPaginationQuery,$page,$totalPages,$totalPayments,$perPage,'transaksi'); ?>
      </section>
      <section class="ph-detail-panel" data-payment-detail-panel><header class="ph-panel-heading"><div><h3><?= authorization_icon('history') ?> Detail Transaksi</h3><p>Informasi lengkap transaksi pembayaran siswa.</p></div><div class="ph-detail-nav"><button type="button" class="btn btn-ghost btn-sm" data-payment-prev aria-label="Transaksi sebelumnya"><?= authorization_icon('left') ?></button><button type="button" class="btn btn-ghost btn-sm" data-payment-next aria-label="Transaksi berikutnya"><?= authorization_icon('right') ?></button><button type="button" class="btn btn-ghost btn-sm" data-payment-close aria-label="Tutup detail"><?= authorization_icon('close') ?></button></div></header><div class="ph-detail-body" data-payment-detail aria-live="polite"><?php if($selectedModel): payment_history_render($selectedModel,array_merge($_GET,['selected'=>$selectedId])); else: ?><p class="ph-empty"><?= authorization_escape($detailError??'Pilih transaksi pada daftar untuk melihat detail.') ?></p><?php endif; ?></div></section>
      </div></div>
    </main>
  </div>

  <div class="authorization-modal" id="payment-delete-request-modal" hidden role="dialog" aria-modal="true" aria-labelledby="payment-delete-request-title">
    <form method="POST" action="proses.php" class="authorization-modal-card">
      <input type="hidden" name="aksi" value="hapus" />
      <input type="hidden" name="return_context" value="" />
      <input type="hidden" name="id" id="payment-delete-request-id" value="" />
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_payment']) ?>" />
      <h3 id="payment-delete-request-title"><?= transaction_authorization_requires_request() ? 'Ajukan penghapusan transaksi' : 'Hapus transaksi' ?></h3>
      <p id="payment-delete-request-copy"><?= transaction_authorization_requires_request() ? 'Transaksi tidak akan dihapus sebelum disetujui Super Admin.' : 'Transaksi akan langsung dihapus setelah Anda menyimpan tindakan ini.' ?></p>
      <?php if (transaction_authorization_requires_request()): ?>
      <label class="field-row">
        <span class="field-label">Alasan penghapusan</span>
        <textarea class="field-input" name="authorization_reason" rows="4" minlength="5" maxlength="500" required placeholder="Jelaskan alasan transaksi perlu dihapus."></textarea>
      </label>
      <?php endif; ?>
      <div class="authorization-modal-actions">
        <button type="button" class="btn btn-ghost" id="payment-delete-request-cancel">Batal</button>
        <button type="submit" class="btn btn-danger"><?= transaction_authorization_requires_request() ? 'Ajukan Penghapusan' : 'Hapus Transaksi' ?></button>
      </div>
    </form>
  </div>

  <dialog class="payment-activity-dialog" id="payment-activity-dialog" aria-labelledby="payment-activity-title">
    <header class="payment-activity-dialog-header"><div><h3 id="payment-activity-title">Riwayat Aktivitas</h3><p data-activity-subtitle></p></div>
      <button type="button" class="btn btn-ghost btn-sm" data-close-activity aria-label="Tutup riwayat aktivitas">Tutup</button>
    </header><div class="payment-activity-content" aria-live="polite"></div>
  </dialog>
  <script src="../assets/js/payment_history.js?v=<?= filemtime(__DIR__.'/../assets/js/payment_history.js') ?>"></script>
  <script src="../assets/js/payment_activity.js?v=<?= filemtime(__DIR__ . "/../assets/js/payment_activity.js") ?>"></script>
  <script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
  <script>
  (() => {
    const modal = document.getElementById('payment-delete-request-modal');
    const idInput = document.getElementById('payment-delete-request-id');
    const copy = document.getElementById('payment-delete-request-copy');
    const close = () => { modal.hidden = true; document.body.classList.remove('modal-open'); opener?.focus(); };
    let opener=null;
    document.addEventListener('click', event => {
      const button=event.target.closest('.open-payment-delete-request');if(!button)return;opener=button;
      event.stopPropagation();
      idInput.value = button.dataset.id || '';
      modal.querySelector('[name=return_context]').value=button.dataset.context||'';
      copy.textContent = <?= json_encode(transaction_authorization_requires_request() ? 'Penghapusan transaksi %s menunggu persetujuan Super Admin.' : 'Transaksi %s akan langsung dihapus setelah tindakan ini disimpan.') ?>.replace('%s', button.dataset.student || 'siswa');
      modal.hidden = false;
      document.body.classList.add('modal-open');
      (modal.querySelector('textarea') || modal.querySelector('button[type="submit"]')).focus();
    });
    modal.addEventListener('keydown',event=>{if(event.key==='Escape')close();if(event.key==='Tab'){const items=[...modal.querySelectorAll('textarea,button')];const first=items[0],last=items.at(-1);if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}});
    modal.querySelector('form').addEventListener('submit',event=>{if(event.target.dataset.sending){event.preventDefault();return;}event.target.dataset.sending='1';event.target.querySelector('[type=submit]').disabled=true;});
    document.getElementById('payment-delete-request-cancel')?.addEventListener('click', close);
    modal?.addEventListener('click', event => { if (event.target === modal) close(); });
  })();
  </script>
  <?php if ($showPrintPrompt): ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const modal = document.getElementById('receipt-print-modal');
      const printNow = document.getElementById('receipt-print-now');
      const printLater = document.getElementById('receipt-print-later');
      const closePrompt = function () {
        modal.classList.remove('show');
        window.setTimeout(function () { modal.remove(); }, 250);
      };

      printLater.addEventListener('click', closePrompt);
      printNow.addEventListener('click', function () {
        window.open(<?= json_encode($printPaymentUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, '_blank', 'noopener');
        closePrompt();
      });
      printNow.focus();
    });
  </script>
  <?php endif; ?>
</body>
</html>

