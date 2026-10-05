<?php
session_start();
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
requireRole(['admin','bendahara','kasir']);
?>
<!doctype html>
<html lang="id" data-palette="<?= unit_palette_for_view() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Surat Laporan | SistemSPP</title>
  <link rel="icon" href="../assets/img/favicon.png?v=2">
  <link rel="stylesheet" href="../assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
  <link rel="stylesheet" href="../assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>">
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')})();</script>
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout">
  <?php include __DIR__.'/../includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <div class="topbar-title"><h2>Surat Laporan</h2><span class="breadcrumb">SistemSPP / Surat Laporan</span></div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>
    <div class="report-catalog-v2 letter-catalog">
      <section class="report-catalog-intro">
        <div class="report-catalog-intro-copy"><span class="report-eyebrow">SURAT LAPORAN</span><h1>Pilih surat yang dibutuhkan</h1><p>Buat surat tunggakan untuk orang tua atau rekap resmi untuk kepala sekolah. Data dihitung kembali saat surat dibuka.</p></div>
        <div class="report-catalog-overview" aria-label="Jumlah pilihan surat"><strong>2</strong><span>jenis surat tersedia</span></div>
      </section>
      <section class="report-catalog-section letter-catalog-section" aria-label="Pilihan surat laporan">
        <div class="report-catalog-grid">
          <article class="report-template-card">
            <div class="report-template-card-head"><div class="report-template-icon" aria-hidden="true">OT</div><span class="report-template-type">SURAT</span></div>
            <div class="report-template-copy"><h3>Cetak Surat ke Orang Tua</h3><p>Pilih siswa dengan tunggakan, lalu cetak surat per siswa, pilihan, atau rombel dalam satu PDF.</p></div>
            <a class="report-template-link" href="surat_orang_tua.php"><span>Buka surat</span><span aria-hidden="true">&rarr;</span></a>
          </article>
          <article class="report-template-card">
            <div class="report-template-card-head"><div class="report-template-icon" aria-hidden="true">KS</div><span class="report-template-type">SURAT</span></div>
            <div class="report-template-copy"><h3>Cetak Surat ke Kepala Sekolah</h3><p>Pilih rombel, seluruh rombel pada satu kelas, atau seluruh kelas untuk melihat total tunggakan dan membuka surat PDF atau Excel.</p></div>
            <a class="report-template-link" href="template.php?template=tunggakan-siswa"><span>Buka surat</span><span aria-hidden="true">&rarr;</span></a>
          </article>
        </div>
      </section>
    </div>
  </main>
</div>
<script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
