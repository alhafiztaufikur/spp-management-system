<?php
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/reports.php';
requireRole(['admin','bendahara','kasir']);
$registry=report_registry();
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Laporan Global | SistemSPP</title><link rel="icon" href="../assets/img/favicon.png?v=2"><link rel="stylesheet" href="../assets/css/style.css?v=9.6"><script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'dark')})();</script></head><body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div><div class="layout"><?php include '../includes/sidebar.php'; ?><main class="main-content"><div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button><div class="topbar-title"><h2>Laporan Global</h2><span class="breadcrumb">SistemSPP / Pusat Template Laporan</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
<div class="main-card report-catalog-hero"><div><span class="report-eyebrow">PUSAT LAPORAN</span><h1>Pilih laporan yang dibutuhkan</h1><p>Data baru dimuat setelah template dibuka. Semua laporan memakai filter yang sama untuk web, cetak, PDF, dan Excel.</p></div></div>
<div class="report-catalog-grid"><?php foreach($registry as $id=>$report): ?><article class="report-template-card"><div class="report-template-icon"><?= report_e($report['icon']) ?></div><div><h3><?= report_e($report['label']) ?></h3><p><?= report_e($report['description']) ?></p></div><a class="btn btn-primary" href="template.php?template=<?= urlencode($id) ?>">Buka Laporan <span aria-hidden="true">→</span></a></article><?php endforeach; ?></div>
<?php
$catalogGroups=[
  'pembayaran'=>['label'=>'Pembayaran Siswa','description'=>'Pantau tagihan, pembayaran yang diterima, dan tunggakan siswa.','items'=>['status','penerimaan','spp-tahunan','per-item','riwayat-tagihan']],
  'tabungan'=>['label'=>'Tabungan Siswa','description'=>'Lihat mutasi dan saldo tabungan siswa secara terpisah.','items'=>['tabungan-siswa','saldo-tabungan']],
  'kas'=>['label'=>'Rekap Kas','description'=>'Ringkasan penerimaan harian untuk kebutuhan penutupan kas.','items'=>['setoran']],
];
?>
<div class="report-catalog-v2">
  <section class="report-catalog-intro">
    <div class="report-catalog-intro-copy"><span class="report-eyebrow">PUSAT LAPORAN</span><h1>Laporan yang mudah dicari</h1><p>Pilih area kerja terlebih dahulu, lalu buka laporan yang sesuai. Setiap laporan dapat difilter dan diekspor ke cetak, PDF, atau Excel.</p></div>
    <div class="report-catalog-overview" aria-label="Ringkasan katalog laporan"><strong><?= count($registry) ?></strong><span>jenis laporan siap digunakan</span></div>
  </section>
  <div class="report-catalog-sections"><?php foreach($catalogGroups as $group): $items=array_filter($group['items'],static fn($id)=>isset($registry[$id])); if(!$items)continue; ?>
    <section class="report-catalog-section"><header class="report-catalog-section-head"><div><span class="report-catalog-section-label"><?= report_e($group['label']) ?></span><p><?= report_e($group['description']) ?></p></div><span class="report-catalog-count"><?= count($items) ?> laporan</span></header>
      <div class="report-catalog-grid"><?php foreach($items as $id): $report=$registry[$id]; ?><article class="report-template-card"><div class="report-template-card-head"><div class="report-template-icon" aria-hidden="true"><?= report_e($report['icon']) ?></div><span class="report-template-type">Laporan</span></div><div class="report-template-copy"><h3><?= report_e($report['label']) ?></h3><p><?= report_e($report['description']) ?></p></div><a class="report-template-link" href="template.php?template=<?= urlencode($id) ?>"><span>Buka laporan</span><span aria-hidden="true">&rarr;</span></a></article><?php endforeach; ?></div>
    </section><?php endforeach; ?>
  </div>
</div>
</main></div><script src="../assets/js/app.js?v=8.2"></script></body></html>
