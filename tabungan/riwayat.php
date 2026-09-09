<?php
// ============================================
// tabungan/riwayat.php — Riwayat Transaksi Tabungan
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/pagination.php';
requireRole(['admin', 'kasir', 'bendahara']);

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

$selectedFilterNis = '';
if ($filter_nis !== '') {
    $stmtIdentity = $koneksi->prepare('SELECT NO_INDUK FROM siswa WHERE NO_INDUK=? OR NO_induk_diknas=? OR NAMA=? LIMIT 1');
    $stmtIdentity->bind_param('sss', $filter_nis, $filter_nis, $filter_nis);
    $stmtIdentity->execute();
    $identity = $stmtIdentity->get_result()->fetch_assoc();
    $stmtIdentity->close();
    if ($identity) $selectedFilterNis = (string)$identity['NO_INDUK'];
}

// Query gabungan masuk + keluar. Filter NIS selalu diparameterkan agar
// input URL tidak pernah menjadi bagian dari SQL.
$where_nis_masuk = $filter_nis !== '' ? ' AND (tm.NO_INDUK = ? OR s.NO_induk_diknas = ? OR s.NAMA LIKE ?)' : '';
$where_nis_keluar = $filter_nis !== '' ? ' AND (tk.NO_INDUK = ? OR s.NO_induk_diknas = ? OR s.NAMA LIKE ?)' : '';
$periodStart = $filter_tanggal_awal . ' 00:00:00';
$periodEnd = date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'));

$sql_masuk = "
    SELECT tm.id, tm.NO_INDUK, s.NAMA, s.KELAS, tm.TANGGAL,
           tm.MASUK as nominal, 0 as keluar, 'masuk' as jenis, tm.user_id
    FROM transaksi_m tm
    JOIN siswa s ON s.NO_INDUK = tm.NO_INDUK
    WHERE tm.TANGGAL >= ? AND tm.TANGGAL < ?$where_nis_masuk
";
$sql_keluar = "
    SELECT tk.id, tk.NO_INDUK, s.NAMA, s.KELAS, tk.TANGGAL,
           0 as nominal, tk.KELUAR as keluar, 'keluar' as jenis, tk.user_id
    FROM transaksi_k tk
    JOIN siswa s ON s.NO_INDUK = tk.NO_INDUK
    WHERE tk.TANGGAL >= ? AND tk.TANGGAL < ?$where_nis_keluar
";

if ($filter_nis !== '') {
    $studentLike = '%' . $filter_nis . '%';
    $historyTypes = 'ssssssssss';
    $historyParams = [$periodStart, $periodEnd, $filter_nis, $filter_nis, $studentLike, $periodStart, $periodEnd, $filter_nis, $filter_nis, $studentLike];
} else {
    $historyTypes = 'ssss';
    $historyParams = [$periodStart, $periodEnd, $periodStart, $periodEnd];
}

$historyUnionSql = "($sql_masuk) UNION ALL ($sql_keluar)";
$stmtSummary = $koneksi->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(nominal), 0) AS total_masuk, COALESCE(SUM(keluar), 0) AS total_keluar FROM ($historyUnionSql) tabungan_tx");
$stmtSummary->bind_param($historyTypes, ...$historyParams);
$stmtSummary->execute();
$historySummary = $stmtSummary->get_result()->fetch_assoc() ?: ['total'=>0, 'total_masuk'=>0, 'total_keluar'=>0];
$stmtSummary->close();
$totalHistoryRows = (int)$historySummary['total'];
$totalPages = total_pages($totalHistoryRows, $perPage);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "$historyUnionSql ORDER BY TANGGAL DESC LIMIT ? OFFSET ?";
$stmt = $koneksi->prepare($sql);
$pageTypes = $historyTypes . 'ii';
$pageParams = array_merge($historyParams, [$perPage, $offset]);
$stmt->bind_param($pageTypes, ...$pageParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Rekap saldo semua siswa aktif. Siswa yang belum punya tabungan tetap tampil
// agar admin bisa langsung menemukan nama dan mulai setoran pertama.
$saldo_list = $koneksi->query("
    SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, COALESCE(t.SALDO, 0) AS SALDO,
           COALESCE(m.total_masuk, 0) AS total_masuk,
           COALESCE(k.total_keluar, 0) AS total_keluar,
           CASE
             WHEN m.last_masuk IS NULL THEN k.last_keluar
             WHEN k.last_keluar IS NULL THEN m.last_masuk
             WHEN m.last_masuk >= k.last_keluar THEN m.last_masuk
             ELSE k.last_keluar
           END AS last_activity
    FROM siswa s
    LEFT JOIN tabungan t ON t.NO_INDUK = s.NO_INDUK
    LEFT JOIN (
        SELECT NO_INDUK, SUM(MASUK) AS total_masuk, MAX(TANGGAL) AS last_masuk
        FROM transaksi_m
        GROUP BY NO_INDUK
    ) m ON m.NO_INDUK = s.NO_INDUK
    LEFT JOIN (
        SELECT NO_INDUK, SUM(KELUAR) AS total_keluar, MAX(TANGGAL) AS last_keluar
        FROM transaksi_k
        GROUP BY NO_INDUK
    ) k ON k.NO_INDUK = s.NO_INDUK
    WHERE s.is_active = 1
    ORDER BY CAST(s.KELAS AS UNSIGNED), s.NAMA ASC
")->fetch_all(MYSQLI_ASSOC);

$total_saldo_semua = array_sum(array_map(static fn($row) => (float)$row['SALDO'], $saldo_list));
$siswa_punya_saldo = count(array_filter($saldo_list, static fn($row) => (float)$row['SALDO'] > 0));
$saldo_tertinggi = empty($saldo_list) ? 0 : max(array_map(static fn($row) => (float)$row['SALDO'], $saldo_list));
$kelas_rekap = array_values(array_unique(array_map(static fn($row) => (string)$row['KELAS'], $saldo_list)));
sort($kelas_rekap, SORT_NATURAL);

$selected_saldo = null;
if ($selectedFilterNis !== '') {
    foreach ($saldo_list as $saldo_row) {
        if ((string)$saldo_row['NO_INDUK'] === (string)$selectedFilterNis) {
            $selected_saldo = $saldo_row;
            break;
        }
    }
}

$total_masuk  = (float)$historySummary['total_masuk'];
$total_keluar = (float)$historySummary['total_keluar'];
$historyPaginationQuery = pagination_query([
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
    'nis' => $filter_nis,
    'per_page' => $perPage,
]);

$bln_names = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
               '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$firstShown = $totalHistoryRows > 0 ? $offset + 1 : 0;
$lastShown = $totalHistoryRows > 0 ? min($offset + count($rows), $totalHistoryRows) : 0;
$periodLabel = $filter_tanggal_awal === $filter_tanggal_akhir
    ? date('d/m/Y', strtotime($filter_tanggal_awal))
    : date('d/m/Y', strtotime($filter_tanggal_awal)) . ' - ' . date('d/m/Y', strtotime($filter_tanggal_akhir));
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Tabungan | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=8.7" />
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

    <div class="page-content">

      <!-- Saldo Cards -->
      <div class="stats-grid" style="margin-bottom:8px;">
        <div class="stat-card stat-green">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($total_masuk, 0, ',', '.') ?></span>
            <span class="stat-label">Total Masuk (Filter Aktif)</span>
          </div>
        </div>
        <div class="stat-card stat-red" style="--c:#ef4444;">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 17H18M12 22V2M7 7l5-5 5 5"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($total_keluar, 0, ',', '.') ?></span>
            <span class="stat-label">Total Keluar (Filter Aktif)</span>
          </div>
        </div>
        <?php if ($selected_saldo): ?>
        <div class="stat-card stat-purple">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h10"/><path d="M7 14h6"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format((float)$selected_saldo['SALDO'], 0, ',', '.') ?></span>
            <span class="stat-label">Saldo <?= htmlspecialchars($selected_saldo['NAMA']) ?> saat ini</span>
          </div>
        </div>
        <?php endif; ?>
        <div class="stat-card stat-blue">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
          <div class="stat-info">
            <span class="stat-value"><?= number_format($totalHistoryRows) ?></span>
            <span class="stat-label">Total Transaksi</span>
          </div>
        </div>
      </div>

      <!-- Filter dan Tabel Transaksi -->
      <section class="main-card class-recap-card recap-report-shell history-recap-shell savings-history-shell">
        <div class="recap-report-header">
          <div class="recap-report-copy">
            <span class="recap-class-overline">Tabungan</span>
            <h1>Riwayat Tabungan</h1>
            <p><?= number_format($totalHistoryRows) ?> transaksi tabungan cocok dengan filter saat ini.</p>
          </div>

        <form method="GET" class="recap-header-controls history-recap-filter tabungan-filter-form">
          <span class="recap-filter-label">Filter Riwayat</span>
          <div class="field-row report-date-range-field"><label class="field-label">Tanggal Transaksi</label><div class="report-date-range-control report-date-range-picker" data-range-picker data-empty-label="<?= htmlspecialchars($periodLabel) ?>"><input type="hidden" name="tanggal_awal" value="<?= htmlspecialchars($filter_tanggal_awal) ?>"><input type="hidden" name="tanggal_akhir" value="<?= htmlspecialchars($filter_tanggal_akhir) ?>"><button type="button" class="report-date-range-button" aria-expanded="false"><span class="report-date-range-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><span class="report-date-range-value"><?= htmlspecialchars($periodLabel) ?></span></button><div class="report-date-range-popover" hidden><label><span>Mulai</span><input type="date" value="<?= htmlspecialchars($filter_tanggal_awal) ?>" data-range-start></label><label><span>Sampai</span><input type="date" value="<?= htmlspecialchars($filter_tanggal_akhir) ?>" data-range-end></label><div class="report-date-range-popover-actions"><button type="button" class="btn btn-primary btn-sm" data-range-apply>Terapkan</button></div></div></div></div><div class="field-row tabungan-filter-nis full-span"><label class="field-label">Cari Siswa (Nama / NIS / NIS Diknas)</label><div class="search-box"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="savings-history-student" data-student-search data-student-list="savings-history-student-list" data-student-select-callback="selectReportStudentSearchOption" data-student-query-target="savings-history-nis" placeholder="Ketik nama, NIS, atau NIS Diknas..." value="<?= htmlspecialchars($selected_saldo['NAMA'] ?? $filter_nis) ?>" autocomplete="off"><input type="hidden" id="savings-history-nis" name="nis" value="<?= htmlspecialchars($filter_nis) ?>"></div><datalist id="savings-history-student-list"><?php foreach ($saldo_list as $studentOption): ?><option value="<?= htmlspecialchars($studentOption['NAMA']) ?>" data-nis="<?= htmlspecialchars($studentOption['NO_INDUK']) ?>" data-diknas="<?= htmlspecialchars((string)($studentOption['NO_induk_diknas'] ?? '')) ?>" data-nama="<?= htmlspecialchars($studentOption['NAMA']) ?>" data-kelas="<?= htmlspecialchars($studentOption['KELAS']) ?>"></option><?php endforeach; ?></datalist></div><div class="field-row tabungan-filter-page">
            <label class="field-label">Per Halaman</label>
            <select class="field-input field-select" name="per_page" aria-label="Jumlah transaksi tabungan per halaman">
              <?php foreach ($allowedPageSizes as $pageSize): ?>
              <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="history-recap-actions tabungan-filter-actions">
            <button type="submit" class="btn btn-primary">Tampilkan Rekap</button>
            <a href="riwayat.php" class="btn btn-ghost">Reset</a>
            <?php if (hasRole(['admin','kasir'])): ?>
            <a href="masuk.php" class="btn btn-primary">Tabungan Masuk</a>
            <a href="keluar.php" class="btn btn-warning">Tabungan Keluar</a>
            <a href="../laporan/template.php?template=tabungan-siswa&tanggal_awal=<?= urlencode($filter_tanggal_awal) ?>&tanggal_akhir=<?= urlencode($filter_tanggal_akhir) ?>&q=<?= urlencode($filter_nis) ?>" class="btn btn-ghost">Cetak Laporan</a>
            <?php endif; ?>
          </div>
        </form>
        </div>

      <!-- Tabel Transaksi -->
        <div class="recap-period-strip history-recap-strip">
          <div><strong>Daftar Transaksi <?= htmlspecialchars($periodLabel) ?></strong><span>Menampilkan <?= number_format($firstShown) ?>–<?= number_format($lastShown) ?> dari <?= number_format($totalHistoryRows) ?> transaksi.</span></div>
          <span><?= number_format($totalHistoryRows) ?> transaksi</span>
        </div>
      <div class="savings-transaction-card">
        <div class="card-header tabungan-card-header">
          <h3 class="card-title">Daftar Transaksi <?= htmlspecialchars($periodLabel) ?></h3>
          <?php if (hasRole(['admin','kasir'])): ?>
          <div class="tabungan-card-actions">
            <a href="masuk.php" class="btn btn-primary">+ Tabungan Masuk</a>
            <a href="keluar.php" class="btn btn-warning">- Tabungan Keluar</a>
          </div>
          <?php endif; ?>
        </div>

        <div class="table-container">
          <table class="payment-table responsive-table savings-transaction-table" id="tbl-riwayat">
            <thead>
              <tr>
                <th>No</th><th>No. Induk</th><th>Nama</th><th class="savings-class-col">Kelas</th>
                <th>Tanggal</th><th>Jenis</th><th>Masuk (Rp)</th><th>Keluar (Rp)</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
              <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">Belum ada transaksi tabungan pada periode ini.</td></tr>
              <?php else: ?>
              <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'row-highlight' : '' ?>">
                <td data-label="No"><?= $offset + $i + 1 ?></td>
                <td data-label="No. Induk"><span class="badge-nis"><?= htmlspecialchars($r['NO_INDUK']) ?></span></td>
                <td data-label="Nama"><?= htmlspecialchars($r['NAMA']) ?></td>
                <td data-label="Kelas" class="savings-class-col"><span class="savings-class-badge">Kelas <?= htmlspecialchars($r['KELAS']) ?></span></td>
                <td data-label="Tanggal"><?= date('d M Y H:i', strtotime($r['TANGGAL'])) ?></td>
                <td data-label="Jenis">
                  <?php if ($r['jenis'] === 'masuk'): ?>
                  <span style="background:rgba(34,197,94,0.15);color:#16a34a;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">↑ Masuk</span>
                  <?php else: ?>
                  <span style="background:rgba(239,68,68,0.15);color:#dc2626;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">↓ Keluar</span>
                  <?php endif; ?>
                </td>
                <td class="nominal"><?= $r['nominal'] > 0 ? 'Rp ' . number_format($r['nominal'],0,',','.') : '—' ?></td>
                <td class="nominal" style="color:#dc2626;"><?= $r['keluar'] > 0 ? 'Rp ' . number_format($r['keluar'],0,',','.') : '—' ?></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php render_pagination('riwayat.php', $historyPaginationQuery, $page, $totalPages, $totalHistoryRows, $perPage, 'transaksi'); ?>
      </div>
      </section>

      <!-- Rekap Saldo Per Siswa -->
      <div class="main-card savings-recap-card" style="margin-top:16px;">
        <div class="savings-recap-head">
          <div>
            <span class="section-kicker">Rekap Tabungan Siswa</span>
            <h3 class="card-title">Cari Saldo Tabungan Per Siswa</h3>
            <p>Gunakan nama, NIS, kelas, atau status saldo untuk menemukan siswa lebih cepat.</p>
          </div>
          <div class="savings-recap-total">
            <span>Total Saldo</span>
            <strong>Rp <?= number_format($total_saldo_semua, 0, ',', '.') ?></strong>
          </div>
        </div>

        <div class="savings-recap-stats">
          <div><span>Siswa aktif</span><strong><?= number_format(count($saldo_list)) ?></strong></div>
          <div><span>Punya saldo</span><strong><?= number_format($siswa_punya_saldo) ?></strong></div>
          <div><span>Saldo tertinggi</span><strong>Rp <?= number_format($saldo_tertinggi, 0, ',', '.') ?></strong></div>
        </div>

        <div class="savings-search-panel">
          <div class="search-box savings-search-main">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" id="savings-student-search" placeholder="Cari nama, NIS, atau NIS Diknas..." autocomplete="off" />
          </div>
          <select class="field-input field-select" id="savings-class-filter" aria-label="Filter kelas">
            <option value="">Semua kelas</option>
            <?php foreach ($kelas_rekap as $kelas): ?>
            <option value="<?= htmlspecialchars($kelas) ?>">Kelas <?= htmlspecialchars($kelas) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="field-input field-select" id="savings-status-filter" aria-label="Filter saldo">
            <option value="">Semua saldo</option>
            <option value="positive">Ada saldo</option>
            <option value="zero">Saldo kosong</option>
          </select>
          <select class="field-input field-select" id="savings-per-page-filter" aria-label="Jumlah siswa per halaman">
            <option value="10">10 / halaman</option>
            <option value="25">25 / halaman</option>
            <option value="50">50 / halaman</option>
          </select>
          <button type="button" class="btn btn-ghost" id="savings-reset-filter">Reset</button>
        </div>

        <div class="savings-result-info">
          <span id="savings-result-count">Ditemukan <?= number_format(count($saldo_list)) ?> siswa</span>
          <span>Klik baris aksi untuk lihat riwayat, setor, atau tarik.</span>
        </div>

        <div class="table-container">
          <table class="payment-table responsive-table savings-recap-table" id="savings-recap-table">
            <thead>
              <tr>
                <th>No</th>
                <th>Siswa</th>
                <th class="savings-class-col">Kelas</th>
                <th>Saldo</th>
                <th>Terakhir</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($saldo_list)): ?>
              <tr class="savings-empty-row"><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">Belum ada data siswa aktif.</td></tr>
              <?php else: ?>
              <?php foreach ($saldo_list as $i => $sl): ?>
              <?php
                $saldo = (float)$sl['SALDO'];
                $lastActivity = $sl['last_activity'] ? date('d/m/Y', strtotime($sl['last_activity'])) : 'Belum ada';
                $searchText = strtolower($sl['NO_INDUK'] . ' ' . ($sl['NO_induk_diknas'] ?? '') . ' ' . $sl['NAMA'] . ' kelas ' . $sl['KELAS']);
              ?>
              <tr class="<?= $i%2===0?'row-highlight':'' ?>"
                  data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>"
                  data-class="<?= htmlspecialchars((string)$sl['KELAS'], ENT_QUOTES, 'UTF-8') ?>"
                  data-saldo="<?= htmlspecialchars((string)$saldo, ENT_QUOTES, 'UTF-8') ?>">
                <td data-label="No" class="savings-row-number"><?= $i+1 ?></td>
                <td data-label="Siswa">
                  <div class="savings-student-cell">
                    <strong><?= htmlspecialchars($sl['NAMA']) ?></strong>
                    <span>NIS <?= htmlspecialchars($sl['NO_INDUK']) ?><?= !empty($sl['NO_induk_diknas']) ? ' · Diknas ' . htmlspecialchars($sl['NO_induk_diknas']) : '' ?></span>
                  </div>
                </td>
                <td data-label="Kelas" class="savings-class-col"><span class="savings-class-badge">Kelas <?= htmlspecialchars($sl['KELAS']) ?></span></td>
                <td data-label="Saldo" class="nominal savings-balance <?= $saldo > 0 ? 'is-positive' : 'is-empty' ?>">Rp <?= number_format($saldo,0,',','.') ?></td>
                <td data-label="Terakhir"><?= htmlspecialchars($lastActivity) ?></td>
                <td data-label="Aksi">
                  <div class="savings-row-actions">
                    <a href="riwayat.php?nis=<?= urlencode($sl['NO_INDUK']) ?>&tanggal_awal=<?= urlencode($filter_tanggal_awal) ?>&tanggal_akhir=<?= urlencode($filter_tanggal_akhir) ?>" class="btn-tbl btn-tbl-view" title="Lihat riwayat">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                    <?php if (hasRole(['admin','kasir'])): ?>
                    <a href="masuk.php?nis=<?= urlencode($sl['NO_INDUK']) ?>" class="btn-tbl btn-tbl-edit" title="Tabungan masuk">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                    </a>
                    <a href="keluar.php?nis=<?= urlencode($sl['NO_INDUK']) ?>" class="btn-tbl btn-tbl-del" title="Tabungan keluar">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>
                    </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <tr class="savings-no-match" hidden><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">Siswa tidak ditemukan. Coba kata kunci atau filter lain.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div id="savings-recap-pagination"></div>
      </div>

    </div>
  </main>
</div>
<div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
<script src="../assets/js/app.js?v=2.8"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  autoHideFlash();

  const searchInput = document.getElementById('savings-student-search');
  const classFilter = document.getElementById('savings-class-filter');
  const statusFilter = document.getElementById('savings-status-filter');
  const perPageFilter = document.getElementById('savings-per-page-filter');
  const resetButton = document.getElementById('savings-reset-filter');
  const resultCount = document.getElementById('savings-result-count');
  const table = document.getElementById('savings-recap-table');
  const paginationContainer = document.getElementById('savings-recap-pagination');
  if (!searchInput || !classFilter || !statusFilter || !table) return;

  const rows = Array.from(table.querySelectorAll('tbody tr[data-search]'));
  const noMatch = table.querySelector('.savings-no-match');
  let savingsCurrentPage = 1;

  function savingsPerPage() {
    const value = parseInt(perPageFilter?.value || '10', 10);
    return [10, 25, 50].includes(value) ? value : 10;
  }

  function renderSavingsPagination(totalFiltered, currentPage, totalPages) {
    if (!paginationContainer) return;
    if (totalFiltered === 0) {
      paginationContainer.innerHTML = '';
      return;
    }

    const perPage = savingsPerPage();
    const startShown = (currentPage - 1) * perPage + 1;
    const endShown = Math.min(totalFiltered, currentPage * perPage);

    const windowWidth = 5;
    let windowStart = Math.max(1, currentPage - Math.floor(windowWidth / 2));
    let windowEnd = Math.min(totalPages, windowStart + windowWidth - 1);
    windowStart = Math.max(1, windowEnd - windowWidth + 1);

    let numberButtonsHtml = '';
    for (let p = windowStart; p <= windowEnd; p++) {
      if (p === currentPage) {
        numberButtonsHtml += `<span class="du-page-link is-active" aria-current="page">${p}</span>`;
      } else {
        numberButtonsHtml += `<button type="button" class="du-page-link" data-page="${p}">${p}</button>`;
      }
    }

    let navInner = '';
    if (currentPage > 1) {
      navInner += `<button type="button" class="du-page-link du-page-desktop-only" data-page="1">Awal</button>`;
      navInner += `<button type="button" class="du-page-link du-page-prev" data-page="${currentPage - 1}">Sebelumnya</button>`;
    } else {
      navInner += `<span class="du-page-link du-page-desktop-only is-disabled">Awal</span>`;
      navInner += `<span class="du-page-link du-page-prev is-disabled">Sebelumnya</span>`;
    }

    navInner += `<span class="du-page-mobile-label">Halaman ${currentPage} dari ${totalPages}</span>`;
    navInner += `<span class="du-page-numbers">${numberButtonsHtml}</span>`;

    if (currentPage < totalPages) {
      navInner += `<button type="button" class="du-page-link du-page-next" data-page="${currentPage + 1}">Berikutnya</button>`;
      navInner += `<button type="button" class="du-page-link du-page-desktop-only" data-page="${totalPages}">Akhir</button>`;
    } else {
      navInner += `<span class="du-page-link du-page-next is-disabled">Berikutnya</span>`;
      navInner += `<span class="du-page-link du-page-desktop-only is-disabled">Akhir</span>`;
    }

    paginationContainer.innerHTML = `
      <div class="du-pagination-footer">
        <p class="du-pagination-info">Menampilkan <strong>${startShown}&ndash;${endShown}</strong> dari <strong>${totalFiltered}</strong> siswa</p>
        <nav class="du-pagination" aria-label="Navigasi halaman">
          ${navInner}
        </nav>
      </div>
    `;
  }

  function applySavingsFilter(resetPage = false) {
    if (resetPage) savingsCurrentPage = 1;

    const query = searchInput.value.trim().toLowerCase();
    const selectedClass = classFilter.value;
    const selectedStatus = statusFilter.value;

    const visibleRows = rows.filter(function(row) {
      const saldo = Number(row.dataset.saldo || 0);
      const matchesText = !query || (row.dataset.search || '').includes(query);
      const matchesClass = !selectedClass || row.dataset.class === selectedClass;
      const matchesStatus = !selectedStatus ||
        (selectedStatus === 'positive' && saldo > 0) ||
        (selectedStatus === 'zero' && saldo <= 0);
      return matchesText && matchesClass && matchesStatus;
    });

    const totalFiltered = visibleRows.length;
    const perPage = savingsPerPage();
    const totalPages = Math.max(1, Math.ceil(totalFiltered / perPage));
    if (savingsCurrentPage > totalPages) savingsCurrentPage = totalPages;
    if (savingsCurrentPage < 1) savingsCurrentPage = 1;

    const startShownIndex = (savingsCurrentPage - 1) * perPage;
    const endShownIndex = startShownIndex + perPage;

    rows.forEach(function(row) { row.hidden = true; });

    visibleRows.forEach(function(row, idx) {
      if (idx >= startShownIndex && idx < endShownIndex) {
        row.hidden = false;
        const numberCell = row.querySelector('.savings-row-number');
        if (numberCell) numberCell.textContent = idx + 1;
      }
    });

    if (noMatch) noMatch.hidden = totalFiltered !== 0;
    if (resultCount) {
      resultCount.textContent = 'Ditemukan ' + totalFiltered.toLocaleString('id-ID') + ' siswa';
    }

    renderSavingsPagination(totalFiltered, savingsCurrentPage, totalPages);
  }

  if (paginationContainer) {
    paginationContainer.addEventListener('click', function(e) {
      const btn = e.target.closest('button.du-page-link');
      if (btn && !btn.classList.contains('is-disabled') && btn.dataset.page) {
        savingsCurrentPage = parseInt(btn.dataset.page, 10);
        applySavingsFilter(false);
      }
    });
  }

  searchInput.addEventListener('input', function () { applySavingsFilter(true); });
  classFilter.addEventListener('change', function () { applySavingsFilter(true); });
  statusFilter.addEventListener('change', function () { applySavingsFilter(true); });
  if (perPageFilter) perPageFilter.addEventListener('change', function () { applySavingsFilter(true); });
  if (resetButton) {
    resetButton.addEventListener('click', function () {
      searchInput.value = '';
      classFilter.value = '';
      statusFilter.value = '';
      if (perPageFilter) perPageFilter.value = '10';
      applySavingsFilter(true);
      searchInput.focus();
    });
  }

  applySavingsFilter(true);
});
</script>
</body>
</html>
