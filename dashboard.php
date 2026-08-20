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

// Data Rekap Setoran Hari Ini
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

// Calculate cash breakdown via reports logic
$setoranData = report_settlement_data($koneksi, $setoranFilters);
$setoranRows = $setoranData['rows'] ?? [];

// Calculate Dashboard Visual Metrics
$pendapatanTunai = (float)($setoranRows[0]['nominal'] ?? 0);
$va = (float)($setoranRows[1]['nominal'] ?? 0);
$qris = (float)($setoranRows[2]['nominal'] ?? 0);
$tabunganMasuk = (float)($setoranRows[3]['nominal'] ?? 0);

$jumlahTransaksi = (int)($setoranData['settlement']['payment_count'] ?? 0);
$totalPenerimaanKotor = $pendapatanTunai + $va + $qris + $tabunganMasuk;
$tunaiDiterima = $pendapatanTunai + $tabunganMasuk;
$kasFisik = (float)($setoranData['settlement']['cash'] ?? 0);

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
  <link rel="stylesheet" href="assets/css/style.css?v=4.8" />
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

      <!-- Stats Cards (Daily Focus) -->
      <div class="stats-grid">
        <div class="stat-card stat-blue">
          <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
          </div>
          <div class="stat-info">
            <span class="stat-value"><?= number_format($jumlahTransaksi) ?></span>
            <span class="stat-label">Transaksi Hari Ini</span>
          </div>
        </div>
        <div class="stat-card stat-purple">
          <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($totalPenerimaanKotor, 0, ',', '.') ?></span>
            <span class="stat-label">Total Penerimaan</span>
          </div>
        </div>
        <div class="stat-card stat-green">
          <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($tunaiDiterima, 0, ',', '.') ?></span>
            <span class="stat-label">Tunai Diterima</span>
          </div>
        </div>
        <div class="stat-card stat-orange">
          <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          </div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($kasFisik, 0, ',', '.') ?></span>
            <span class="stat-label">Kas Disetorkan</span>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="quick-actions">
        <a href="pembayaran/form.php" class="quick-btn">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          Input Pembayaran
        </a>
        <a href="tabungan/riwayat.php" class="quick-btn quick-btn-ghost">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          Riwayat Tabungan
        </a>
        <a href="laporan/template.php?template=setoran" class="quick-btn quick-btn-ghost">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Rincian Setoran Lengkap
        </a>
      </div>

      <!-- Rekap Setoran Kas Fisik Hari Ini -->
      <div class="main-card" style="margin-top:0; margin-bottom: 24px;">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            Rekap Setoran Kas Fisik Hari Ini (<?= date('d/m/Y') ?>)
          </div>
          <a href="laporan/export_global.php?template=setoran&format=excel&tanggal_awal=<?= $todayDate ?>&tanggal_akhir=<?= $todayDate ?>&kategori=semua" class="btn btn-primary" style="padding:6px 14px;font-size:13px">
            Export Excel
          </a>
        </div>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead>
              <tr>
                <th>Komponen Setoran</th>
                <th>Klasifikasi</th>
                <th style="text-align: right;">Nominal</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($setoranRows)): ?>
              <tr>
                <td colspan="3" class="text-center" style="padding: 20px; color: var(--text-muted);">
                  Data setoran belum tersedia
                </td>
              </tr>
              <?php else: ?>
                <?php foreach ($setoranRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['bagian']) ?></td>
                  <td><?= htmlspecialchars($row['jenis']) ?></td>
                  <td style="text-align: right; <?= (float)$row['nominal'] < 0 ? 'color:#b42318;font-weight:600;' : '' ?>">
                    <?= report_money((float)$row['nominal']) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </main>
  </div><!-- /layout -->

  <script src="assets/js/app.js?v=3.8"></script>
</body>
</html>
