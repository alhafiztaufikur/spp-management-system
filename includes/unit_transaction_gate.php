<?php
// Included only after authentication, before any transaction form is loaded.
$root = str_contains((string)$_SERVER['SCRIPT_NAME'], '/pembayaran/') || str_contains((string)$_SERVER['SCRIPT_NAME'], '/tabungan/') ? '../' : '';
?>
<!doctype html><html lang="id" data-palette="super"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pilih unit untuk transaksi | SistemSPP</title><link rel="stylesheet" href="<?= $root ?>assets/css/style.css?mtime=<?= filemtime(__DIR__.'/../assets/css/style.css') ?>"><link rel="stylesheet" href="<?= $root ?>assets/css/date_controls.css?mtime=<?= filemtime(__DIR__.'/../assets/css/date_controls.css') ?>"></head><body>
<div class="layout"><?php include __DIR__.'/sidebar.php'; ?><main class="main-content">
<div class="topbar"><button class="sidebar-toggle" id="btn-sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka menu">☰</button><div class="topbar-title"><h2>Pilih unit untuk transaksi</h2><span class="breadcrumb">SistemSPP / Unit transaksi</span></div></div>
<div class="page-content"><section class="main-card"><h1>Pilih unit untuk transaksi</h1><p>Pilih SD, SMP, atau SMA melalui dropdown Unit operasional di sidebar untuk melanjutkan.</p><p>Transaksi hanya dapat dilakukan pada satu unit yang dipilih secara eksplisit.</p><a class="btn btn-ghost" href="<?= $root ?>dashboard.php">Kembali ke Dashboard</a></section></div>
</main></div><script src="<?= $root ?>assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="<?= $root ?>assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script></body></html>
