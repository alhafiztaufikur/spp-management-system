<?php
// ============================================
// dashboard.php - Daily Closing Dashboard
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'koneksi.php';
require_once 'includes/auth.php';
requireRole(['admin', 'bendahara']);
require_once 'includes/reports.php';

// Data Rekap Penerimaan Hari Ini
$todayDate = date('Y-m-d');
$setoranFilters = [
    'tanggal_awal' => $todayDate,
    'tanggal_akhir' => $todayDate,
    'kategori' => 'semua',
    'kelas' => 0,
    'operator' => '',
    'metode' => '',
    'q' => '',
    'mode' => 'harian',
    'tahun' => (int)date('Y'),
    'bulan_awal' => date('m'),
    'bulan_akhir' => date('m'),
    'status' => '',
    'siswa_status' => 'active',
    'tahun_ajaran' => du_current_academic_year()
];

$paymentComponents = report_payment_components($koneksi, $setoranFilters);
$paymentComponents = array_values(array_filter($paymentComponents, static fn($row) => ($row['kategori_key'] ?? '') !== 'potongan'));
$totalPembayaran = array_sum(array_map(static fn($row) => (float)($row['nominal'] ?? 0), $paymentComponents));
$jumlahTransaksi = count(array_unique(array_column($paymentComponents, 'id')));

$startToday = $todayDate . ' 00:00:00';
$endToday = date('Y-m-d H:i:s', strtotime($todayDate . ' +1 day'));
$savingTransactions = report_savings_transactions($koneksi, $startToday, $endToday, $setoranFilters);
$tabunganMasuk = array_sum(array_map(static fn($row) => (float)($row['masuk'] ?? 0), $savingTransactions));
$tabunganKeluar = array_sum(array_map(static fn($row) => (float)($row['keluar'] ?? 0), $savingTransactions));
$totalPenerimaan = $totalPembayaran + $tabunganMasuk - $tabunganKeluar;

$rekapHarianRows = [
    ['label' => 'Transaksi Pembayaran', 'desc' => 'Semua pembayaran siswa hari ini', 'nominal' => $totalPembayaran, 'kind' => 'payment', 'icon' => 'card'],
    ['label' => 'Tabungan Masuk', 'desc' => 'Setoran tabungan yang diterima', 'nominal' => $tabunganMasuk, 'kind' => 'saving-in', 'icon' => 'up'],
    ['label' => 'Tabungan Keluar', 'desc' => 'Penarikan tabungan siswa', 'nominal' => $tabunganKeluar, 'kind' => 'saving-out', 'icon' => 'down'],
    ['label' => 'Total Penerimaan', 'desc' => 'Pembayaran + masuk - keluar', 'nominal' => $totalPenerimaan, 'kind' => 'total', 'icon' => 'total'],
];
$bulanIndo = [
    '01' => 'Januari',
    '02' => 'Februari',
    '03' => 'Maret',
    '04' => 'April',
    '05' => 'Mei',
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Agustus',
    '09' => 'September',
    '10' => 'Oktober',
    '11' => 'November',
    '12' => 'Desember',
];
$todayLabel = date('d') . ' ' . ($bulanIndo[date('m')] ?? date('F')) . ' ' . date('Y');
$exportSetoranPdfUrl = 'laporan/export_global.php?template=setoran&format=pdf&tanggal_awal=' . $todayDate . '&tanggal_akhir=' . $todayDate;
$exportSetoranExcelUrl = 'laporan/export_global.php?template=setoran&format=excel&tanggal_awal=' . $todayDate . '&tanggal_akhir=' . $todayDate;

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard Closing Harian | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png" />
  <meta name="description" content="Dashboard admin sistem pembayaran SPP sekolah. Fokus rekap harian." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css?v=7.6" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

  <div class="bg-orbs">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
  </div>

  <!-- Sidebar -->
  <div class="layout">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
      <!-- Topbar -->
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" title="Toggle Sidebar">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Dashboard Closing</h2>
          <span class="breadcrumb">SistemSPP / Ringkasan Harian</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <section class="dashboard-closing-shell">
        <div class="dashboard-closing-hero">
          <div class="dashboard-total-card">
            <span class="dashboard-eyebrow">Dashboard Closing</span>
            <h1>Ringkasan Hari Ini</h1>
            <p><?= $todayLabel ?></p>
            <div class="dashboard-total-value"><?= report_money($totalPenerimaan) ?></div>
            <div class="dashboard-total-meta">
              <span><?= number_format($jumlahTransaksi) ?> transaksi</span>
              <span>Total bersih</span>
            </div>
          </div>

          <div class="dashboard-closing-side">
            <div class="dashboard-mini-stat is-payment">
              <span class="dashboard-mini-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              </span>
              <div>
                <strong><?= report_money($totalPembayaran) ?></strong>
                <small>Transaksi Pembayaran</small>
              </div>
            </div>
            <div class="dashboard-mini-stat is-in">
              <span class="dashboard-mini-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
              </span>
              <div>
                <strong><?= report_money($tabunganMasuk) ?></strong>
                <small>Tabungan Masuk</small>
              </div>
            </div>
            <div class="dashboard-mini-stat is-out">
              <span class="dashboard-mini-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
              </span>
              <div>
                <strong><?= report_money($tabunganKeluar) ?></strong>
                <small>Tabungan Keluar</small>
              </div>
            </div>
          </div>
        </div>

        <div class="dashboard-action-strip">
          <a href="pembayaran/form.php" class="quick-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Pembayaran
          </a>
          <a href="tabungan/riwayat.php" class="quick-btn quick-btn-ghost">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            Tabungan
          </a>
          <a href="laporan/template.php?template=setoran" class="quick-btn quick-btn-ghost">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Laporan
          </a>
          <a href="<?= htmlspecialchars($exportSetoranPdfUrl) ?>" class="quick-btn quick-btn-soft">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export PDF
          </a>
          <a href="<?= htmlspecialchars($exportSetoranExcelUrl) ?>" class="quick-btn quick-btn-soft">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export Excel
          </a>
        </div>

        <div class="dashboard-breakdown-card">
          <div class="dashboard-breakdown-head">
            <div>
              <span class="dashboard-eyebrow">Rekap Penerimaan</span>
              <h2>Rincian Hari Ini</h2>
              <p>Pembayaran + tabungan masuk - tabungan keluar.</p>
            </div>
            <span class="dashboard-date-pill"><?= $todayLabel ?></span>
          </div>

          <div class="dashboard-breakdown-list">
            <?php foreach ($rekapHarianRows as $row): ?>
              <?php
                $nominal = (float)($row['nominal'] ?? 0);
                $isDeduct = $row['kind'] === 'saving-out';
                $displayNominal = ($isDeduct && $nominal > 0 ? '- ' : '') . report_money($nominal);
              ?>
              <div class="dashboard-breakdown-row is-<?= htmlspecialchars($row['kind']) ?>">
                <span class="dashboard-breakdown-icon">
                  <?php if ($row['icon'] === 'up'): ?>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                  <?php elseif ($row['icon'] === 'down'): ?>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                  <?php elseif ($row['icon'] === 'total'): ?>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19h16"/><path d="M7 15l3-3 3 2 4-6"/><path d="M17 8h-4V4"/></svg>
                  <?php else: ?>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                  <?php endif; ?>
                </span>
                <div class="dashboard-breakdown-copy">
                  <strong><?= htmlspecialchars($row['label']) ?></strong>
                  <small><?= htmlspecialchars($row['desc']) ?></small>
                </div>
                <div class="dashboard-breakdown-amount"><?= $displayNominal ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

    </main>
  </div><!-- /layout -->

  <script src="assets/js/app.js?v=6.2"></script>
</body>
</html>
