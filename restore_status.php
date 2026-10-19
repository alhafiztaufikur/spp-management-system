<?php
// This progress page remains available while application database access is paused.
session_start();require_once __DIR__.'/includes/database_restore.php';
require_once __DIR__.'/includes/database_restore_ui.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$cap=$_SESSION['database_restore_job']??null;
if(empty($_SESSION['admin_id'])||!operational_role()||!is_array($cap)){header('Location: backup_restore.php');exit;}
try{$row=db_restore_authorize(db_restore_store(),(string)$cap['id'],(int)$_SESSION['admin_id'],(string)$cap['token']);}
catch(Throwable $e){http_response_code(403);exit('Progres pemulihan tidak tersedia untuk sesi ini.');}
$palette = [0=>'super',1=>'sd',2=>'smp',3=>'sma'][(int)($_SESSION['active_unit_id']??0)] ?? 'super';
$config=['csrf'=>$_SESSION['csrf_backup'],'job'=>$cap];session_write_close();
?><!doctype html>
<html lang="id" data-palette="<?= $palette ?>" data-theme="light">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Progres Pemulihan | SistemSPP</title>
  <script>try{document.documentElement.dataset.theme=localStorage.getItem('spp_theme')==='dark'?'dark':'light'}catch(e){}</script>
  <link rel="stylesheet" href="assets/css/database_restore.css?v=<?= filemtime(__DIR__.'/assets/css/database_restore.css') ?>">
</head>
<body class="restore-status-page">
  <header class="restore-status-topbar"><a class="restore-brand" href="backup_restore.php"><img src="assets/img/school-logo.png" alt="">SistemSPP</a><button type="button" id="restore-theme" class="restore-action secondary" aria-label="Ganti tema">Ganti Tema</button></header>
  <main id="restore-dialog" class="restore-status-card">
    <header class="restore-status-heading"><span class="restore-status-symbol" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11a9 9 0 1 1 2 7M3 4v7h7"/></svg></span><div><h1>Progres Pemulihan Database</h1><p>SD, SMP, dan SMA &middot; Proses tetap berjalan ketika halaman ditutup.</p></div></header>
    <dl class="restore-file-details"><div><dt>File cadangan</dt><dd id="restore-dialog-file"></dd></div><div><dt>Status</dt><dd id="restore-validation-state" role="status" aria-live="polite"></dd></div></dl>
    <?php database_restore_progress_ui(); database_restore_confirmation_ui(); ?>
    <footer class="restore-status-actions">
      <a class="restore-action secondary" href="backup_restore.php">Kembali ke Backup &amp; Restore</a>
      <button class="restore-action" id="restore-apply" disabled hidden>Pulihkan Database</button>
      <button class="restore-action" id="restore-resume" hidden>Lanjutkan Pemulihan Pengaman</button>
      <a class="restore-action" id="restore-login" href="login.php" hidden>Masuk Kembali</a>
    </footer>
    <p id="restore-connection" class="restore-connection" role="status" aria-live="polite" hidden></p>
  </main>
  <script>document.getElementById('restore-theme').addEventListener('click',function(){var next=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=next;try{localStorage.setItem('spp_theme',next)}catch(e){}});</script>
  <script type="application/json" id="database-restore-config"><?= json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <script src="assets/js/database_restore.js?v=<?= filemtime(__DIR__.'/assets/js/database_restore.js') ?>"></script>
</body></html>
