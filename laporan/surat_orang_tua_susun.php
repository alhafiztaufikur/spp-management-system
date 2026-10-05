<?php
session_start();require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/parent_letter_drafts.php';
requireRole(['admin','bendahara','kasir']);header('Cache-Control: no-store, private');
try{
    $token=(string)($_GET['draft']??'');
    if($token===''){$token=parent_letter_draft_create(parent_letter_collect($koneksi,$_GET));header('Location: surat_orang_tua_susun.php?draft='.$token);exit;}
    $draft=parent_letter_draft_read($token);
}catch(Throwable $e){http_response_code(400);echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Surat tidak tersedia</title><p>'.report_e($e->getMessage()).'</p><a href="surat_orang_tua.php">Kembali ke daftar surat</a></html>';exit;}
if(empty($_SESSION['csrf_parent_letter']))$_SESSION['csrf_parent_letter']=bin2hex(random_bytes(32));
$preview=($_GET['preview']??'')==='1';$pdfUrl='surat_orang_tua_pdf.php?draft='.$token;
?>
<!doctype html><html lang="id" data-palette="<?= unit_palette_for_view() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $preview?'Pratinjau':'Susun' ?> Surat Orang Tua | SistemSPP</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__.'/../assets/css/style.css') ?>"><link rel="stylesheet" href="../assets/css/transaction_workflows.css?v=<?= filemtime(__DIR__.'/../assets/css/transaction_workflows.css') ?>">
<script>document.documentElement.dataset.theme=localStorage.getItem('spp_theme')||'light';</script></head><body><div class="layout"><?php include __DIR__.'/../includes/sidebar.php'; ?><main class="main-content"><div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka menu">☰</button><div class="topbar-title"><h2><?= $preview?'Pratinjau':'Susun' ?> Surat Orang Tua</h2><span class="breadcrumb">Surat Laporan / Orang Tua</span></div><div id="liveClock" class="clock-badge"></div></div>
<div class="parent-compose"><section class="main-card"><header class="parent-compose-header"><span class="recap-class-overline">SURAT KE ORANG TUA</span><h1><?= $preview?'Periksa surat sebelum mencetak':'Sesuaikan pesan untuk setiap siswa' ?></h1><p><?= count($draft['students']) ?> penerima · Tunggakan sampai <?= report_e(report_date_label($draft['today'])) ?>. Draf berlaku dua jam sejak dibuat.</p></header>
<?php if($preview): ?>
<div class="parent-compose-actions"><a class="btn btn-ghost" href="?draft=<?= report_e($token) ?>">Kembali ke penyusunan</a><button class="btn btn-primary" type="button" id="parent-print">Cetak</button><a class="btn btn-ghost" href="<?= report_e($pdfUrl.'&download=1') ?>">Unduh PDF</a><span class="parent-compose-status">Surat memakai data saat draf dibuat. Buat penyusunan baru jika ada pembayaran setelahnya.</span></div>
<iframe title="Pratinjau PDF surat orang tua" class="parent-preview-frame" id="parent-pdf-frame" src="<?= report_e($pdfUrl) ?>"></iframe>
<script>
(() => { const frame=document.getElementById('parent-pdf-frame'),button=document.getElementById('parent-print');button.disabled=true;
frame.addEventListener('load',()=>{button.disabled=false;});
button.addEventListener('click',()=>{frame.contentWindow.focus();frame.contentWindow.print();}); })();
</script>
<?php else: ?>
<div class="parent-compose-layout"><aside class="parent-recipients"><label class="field-label" for="recipient-search">Cari penerima</label><input class="field-input" id="recipient-search" placeholder="Nama, NIS, atau kelas"><div class="parent-recipient-list">
<?php foreach($draft['students'] as $student): $key=unit_student_key($student); ?><button type="button" class="parent-recipient" data-key="<?= report_e($key) ?>" aria-pressed="false"><strong><?= report_e($student['nama']) ?></strong><small><?= report_e(unit_label((int)$student['unit_id']).' · '.$student['nis'].' · '.$student['kelas']) ?></small><small data-message-status><?= trim($draft['messages'][$key]??'')!==''?'Pesan custom terisi':'Tanpa pesan tambahan' ?></small></button><?php endforeach; ?>
</div></aside><div class="parent-editor"><h2 id="recipient-name"></h2><p id="recipient-identity" class="parent-editor-copy"></p><label class="field-label" for="parent-message">Pesan tambahan untuk orang tua</label><textarea class="field-input" id="parent-message" maxlength="2000" placeholder="Tuliskan pesan khusus untuk orang tua siswa ini…"></textarea><p class="parent-editor-copy">Pesan ditempatkan setelah tabel Jumlah Tunggakan, sebelum paragraf “Mohon Bapak/Ibu…”. Kosongkan jika tidak membutuhkan pesan tambahan.</p><span id="parent-message-count" class="parent-compose-status"></span></div></div>
<div class="parent-compose-actions"><a class="btn btn-ghost" href="surat_orang_tua.php">Kembali ke daftar</a><button type="button" class="btn btn-ghost" id="parent-save">Simpan Draf</button><button type="button" class="btn btn-primary" id="parent-preview">Pratinjau Surat</button><span id="parent-save-status" class="parent-compose-status" role="status"></span></div>
<script id="parent-draft-data" type="application/json"><?= json_encode(['token'=>$token,'csrf'=>$_SESSION['csrf_parent_letter'],'messages'=>$draft['messages']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script><script src="../assets/js/parent_letter_editor.js?v=<?= filemtime(__DIR__.'/../assets/js/parent_letter_editor.js') ?>"></script>
<?php endif; ?></section></div></main></div><script src="../assets/js/date_format.js"></script><script src="../assets/js/app.js"></script></body></html>
