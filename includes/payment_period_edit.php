<?php
$draft = $returnEntry['draft'] ?? [];
$periodEditId = $id;
$periodMode = in_array($draft['payment_plan'] ?? '', ['range','academic'],true) ? $draft['payment_plan'] : $periodMeta['mode'];
$periodChoice = $draft['period_choice'] ?? ($periodMeta['mode'] === 'academic' ? lb_payment_year($periodMeta['mulai']) : $periodMeta['akhir']);
$escape = static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$ask = transaction_authorization_requires_request();
?>
<!doctype html>
<html lang="id" data-palette="<?= $escape(unit_palette_for_view()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Koreksi Pembayaran | SistemSPP</title>
  <script>document.documentElement.dataset.theme = localStorage.getItem('spp_theme') || 'light';</script>
  <?php foreach (['style','date_controls','payment_ui_polish','payment_workspace_refinement','payment_period','payment_edit'] as $stylesheet): ?>
    <link rel="stylesheet" href="../assets/css/<?= $stylesheet ?>.css?v=<?= filemtime(__DIR__.'/../assets/css/'.$stylesheet.'.css') ?>">
  <?php endforeach; ?>
</head>
<body class="payment-content-plain">
<div class="layout">
  <?php require __DIR__.'/sidebar.php'; ?>
  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" type="button" id="btn-sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka atau tutup menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
      </button>
      <div class="topbar-title"><h2>Koreksi Pembayaran</h2><span class="breadcrumb">SistemSPP / Pembayaran / Edit</span></div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>
    <div class="main-card payment-edit">
      <div class="card-title-row">
        <h1 class="card-title">Koreksi Pembayaran SPP &amp; Komite</h1>
        <span class="badge-id">TRX-<?= str_pad((string)$periodEditId,6,'0',STR_PAD_LEFT) ?></span>
      </div>
      <?php if ($flash): ?><p class="alert alert-<?= $escape($flash['type']) ?>" id="flash-msg" role="alert" data-operation-focus="#tgl-bayar-display"><?= $escape($flash['msg']) ?></p><?php endif; ?>
      <form method="post" action="proses.php" class="payment-period-enabled">
        <input type="hidden" name="aksi" value="update">
        <input type="hidden" name="id" value="<?= (int)$periodEditId ?>">
        <input type="hidden" name="_operational_unit" value="<?= unit_active_id() ?>">
        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_payment']) ?>">
        <input type="hidden" name="return_context" value="<?= $escape($returnContext) ?>">
        <div class="payment-edit-settings">
          <section class="payment-edit-panel" aria-labelledby="edit-payment-settings">
            <h3 id="edit-payment-settings">Pengaturan Transaksi</h3>
            <div class="payment-edit-fields">
              <div class="field-row">
                <label class="field-label" for="tgl-bayar">Tanggal Bayar</label>
                <input class="field-input" type="date" id="tgl-bayar" name="tanggal_bayar" value="<?= $escape(substr($d['TGL_BYR'],0,10)) ?>" required>
              </div>
              <div class="field-row"><div class="payment-period-layout"><?php require __DIR__.'/payment_period_fields.php'; ?></div></div>
              <label class="field-row"><span class="field-label">Sistem Pembayaran</span>
                <select class="field-input field-select" name="sistem_pembayaran" required>
                  <?php foreach (['Tunai','VA','Qris'] as $method): ?><option<?= $method === $d['sistem_pembayaran'] ? ' selected' : '' ?>><?= $method ?></option><?php endforeach; ?>
                </select>
              </label>
            </div>
          </section>
          <div class="payment-edit-total"><span>Total Bayar</span><strong id="totalJumlah" aria-live="polite">—</strong></div>
        </div>
        <section class="payment-edit-panel payment-edit-student" aria-labelledby="edit-payment-student">
          <h3 id="edit-payment-student">Data Siswa</h3>
          <div class="payment-edit-student-fields">
            <label class="field-row"><span class="field-label">Nama Siswa</span><input class="field-input" value="<?= $escape($d['NAMA']) ?>" readonly></label>
            <label class="field-row"><span class="field-label">NIS</span><input class="field-input" name="no_induk" id="period-edit-student" value="<?= $escape($d['NO_INDUK']) ?>" readonly></label>
            <label class="field-row"><span class="field-label">Kelas</span><input class="field-input" value="<?= $escape($d['kelas_rombel_snapshot'] ?: ('Kelas '.$d['KELAS'])) ?>" readonly></label>
          </div>
        </section>
        <?php require __DIR__.'/payment_period_preview.php'; ?>
        <div class="field-row">
          <label class="field-label" for="period-edit-note">Catatan</label>
          <input type="text" class="field-input" name="catatan" id="period-edit-note" maxlength="255" value="<?= $escape($d['KETERANGAN']) ?>">
        </div>
        <?php if ($ask): ?>
        <div class="field-row">
          <label class="field-label" for="period-edit-reason">Alasan Pengajuan</label>
          <textarea class="field-input" name="authorization_reason" id="period-edit-reason" rows="3" minlength="5" maxlength="500" required></textarea>
        </div>
        <?php endif; ?>
        <div class="action-bar">
          <button type="submit" class="btn btn-primary" disabled><?= $ask ? 'Ajukan Koreksi' : 'Simpan Koreksi' ?></button>
          <a class="btn btn-ghost" href="<?= $escape($returnUrl) ?>">Kembali</a>
        </div>
      </form>
    </div>
  </main>
</div>
<script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__.'/../assets/js/date_format.js') ?>"></script>
<script src="../assets/js/app.js?v=<?= filemtime(__DIR__.'/../assets/js/app.js') ?>"></script>
<script src="../assets/js/payment_period.js?v=<?= filemtime(__DIR__.'/../assets/js/payment_period.js') ?>"></script>
</body>
</html>
