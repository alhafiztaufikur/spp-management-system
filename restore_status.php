<?php
// This progress page remains available while application database access is paused.
session_start();require_once __DIR__.'/includes/database_restore.php';
require_once __DIR__.'/includes/database_restore_ui.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$cap=$_SESSION['database_restore_job']??null;
if(empty($_SESSION['admin_id'])||($_SESSION['admin_role']??'')!=='super_admin'||!is_array($cap)){header('Location: backup_restore.php');exit;}
try{$row=db_restore_authorize(db_restore_store(),(string)$cap['id'],(int)$_SESSION['admin_id'],(string)$cap['token']);}
catch(Throwable $e){http_response_code(403);exit('Progres pemulihan tidak tersedia untuk sesi ini.');}
$config=['csrf'=>$_SESSION['csrf_backup'],'job'=>$cap];session_write_close();
?><!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Progres Pemulihan | SistemSPP</title>
<style>body{font:15px/1.6 system-ui,sans-serif;background:#edf7f2;color:#173c30;margin:0;padding:32px 16px}main{max-width:720px;margin:auto;padding:28px;background:white;border:1px solid #c8e4d6;border-radius:18px}h1{font-size:26px}dt{font-weight:600}dd{margin:0 0 14px;overflow-wrap:anywhere}button,a{display:inline-block;font:inherit;padding:10px 16px;border:1px solid #b9dbca;border-radius:8px;background:#168748;color:white;text-decoration:none;cursor:pointer}button:disabled{opacity:.5;cursor:not-allowed}label,input{display:block;margin:12px 0}input[type=text]{box-sizing:border-box;width:100%;padding:12px;font:inherit}input[type=checkbox]{display:inline}#restore-unavailable{background:#edf7f2;padding:16px;border-radius:8px}[hidden]{display:none!important}@media(prefers-color-scheme:dark){body{background:#14241f;color:#def0e7}main{background:#20362d;border-color:#406052}#restore-unavailable{background:#29483a}input{background:#14241f;color:#def0e7}}</style><link rel="stylesheet" href="assets/css/database_restore.css?v=<?= filemtime(__DIR__.'/assets/css/database_restore.css') ?>"></head>
<body class="restore-status-page"><main id="restore-dialog"><h1>Progres Pemulihan Database</h1><p>Pemulihan mencakup SD, SMP, dan SMA. Proses tetap berjalan ketika halaman ditutup.</p>
<dl><dt>File</dt><dd id="restore-dialog-file"></dd><dt>Tahap</dt><dd id="restore-validation-state"></dd></dl>
<?php database_restore_progress_ui(); database_restore_confirmation_ui(); ?>
<button id="restore-apply" disabled>Pulihkan Database</button><button id="restore-resume" hidden>Lanjutkan Pemulihan Pengaman</button><a id="restore-login" href="login.php" hidden>Masuk Kembali</a>
<p><a href="backup_restore.php">Kembali ke Backup &amp; Restore</a></p>
<script type="application/json" id="database-restore-config"><?= json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="assets/js/database_restore.js?v=<?= filemtime(__DIR__.'/assets/js/database_restore.js') ?>"></script>
</main></body></html>
