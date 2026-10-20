<?php
/** Fast post-save destination: load one journal/account, not the history dashboard. */
require_once __DIR__.'/savings_owners.php';
$savedRow=null;$savedAccount=null;$savedError=null;
try{
    [$savedKind,$savedId]=savings_http_identity();
    $savedRow=savings_transaction($koneksi,$savedKind,$savedId);
    if(!$savedRow)throw new DomainException('Transaksi tidak ditemukan pada unit ini.');
    foreach(['owner_id','student_id'] as $key){
        if(isset($_GET[$key])&&(!is_scalar($_GET[$key])||!ctype_digit((string)$_GET[$key])))throw new DomainException('Identitas rekening tidak valid.');
        $expected=$key==='owner_id'?(int)($savedRow['legacy_owner_id']??0):(int)($savedRow['student_id']??0);
        if((int)($_GET[$key]??0)>0&&(int)$_GET[$key]!==$expected)throw new DomainException('Identitas rekening tidak sesuai transaksi.');
    }
    $savedIdentity=!empty($savedRow['student_id'])?['student_id'=>(int)$savedRow['student_id']]:['owner_id'=>(int)$savedRow['legacy_owner_id']];
    $savedAccount=so_resolve($koneksi,$savedIdentity);
}catch(Throwable $error){
    $expected=$error instanceof DomainException||$error instanceof InvalidArgumentException;
    http_response_code($expected?404:500);if(!$expected)error_log('Detail tabungan setelah simpan: '.$error->getMessage());
    $savedError=$expected?$error->getMessage():'Detail belum dapat dimuat. Buka riwayat tabungan untuk mencoba kembali.';
}
$savedFlash=$_SESSION['flash']??null;unset($_SESSION['flash']);
if($savedRow&&isset($_SESSION['savings_print_prompt'])&&(int)$_SESSION['savings_print_prompt']['id']===(int)$savedRow['id'])unset($_SESSION['savings_print_prompt']);
$savedQuery=$_GET;unset($savedQuery['after_save']);$savedQuery['all_periods']='1';
$savedHistory='riwayat.php?'.http_build_query($savedQuery);
?><!doctype html><html lang="id" data-theme="light" data-palette="<?= unit_palette_for_view() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Detail Tabungan | SistemSPP</title><script>document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')</script>
<?php foreach(['style','savings_workspace','payment_history','legacy_unified_workspaces'] as $css): ?><link rel="stylesheet" href="../assets/css/<?= $css ?>.css?v=<?= filemtime(__DIR__.'/../assets/css/'.$css.'.css') ?>"><?php endforeach; ?></head><body class="legacy-unified"><div class="layout"><?php include __DIR__.'/sidebar.php'; ?><main class="main-content"><div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="topbar-title"><h2>Detail Tabungan</h2><span class="breadcrumb">SistemSPP / Tabungan / Detail</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div><div class="page-content sw-page">
<section class="sw-card"><h1><?= !$savedError&&($savedFlash['type']??'')==='success'?'Transaksi berhasil diproses':'Detail Transaksi Tabungan' ?></h1>
<?php if($savedError): ?><p class="alert alert-error" role="alert"><?= savings_e($savedError) ?></p><?php elseif($savedFlash): ?><p class="sw-info" role="status"><?= savings_e($savedFlash['msg']) ?></p><?php endif; ?>
<div class="sw-entry-actions"><a class="btn btn-ghost" href="<?= savings_e($savedHistory) ?>">Lihat Riwayat Rekening</a><a class="btn btn-ghost" href="riwayat.php">Rekap Semua Rekening</a><?php if($savedAccount&&$savedAccount['state']==='ready'&&!unit_all_readonly()&&hasRole(['admin','kasir'])): ?><a class="btn btn-primary" href="masuk.php?<?= savings_e(http_build_query($savedIdentity)) ?>">Setoran Berikutnya</a><?php endif; ?></div></section>
<?php if(!$savedError&&$savedAccount): ?><section class="sw-card"><div class="sw-balance-box"><?= savings_icon('wallet') ?><div><small>Saldo Rekening Saat Ini</small><strong><?= savings_money($savedAccount['balance']) ?></strong><small><?= savings_e($savedAccount['name']) ?></small></div></div></section><section class="sw-card"><?= savings_detail_html($savedRow) ?></section><?php endif; ?>
</div></main></div><script src="../assets/js/app.js"></script></body></html>
