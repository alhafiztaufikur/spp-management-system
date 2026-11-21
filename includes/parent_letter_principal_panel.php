<?php $signerUnit=unit_active_id(); ?>
<div class="letter-signers">
<?php foreach($signerPanels as $role=>$states):
    [,,,$title]=plp_config($role);
    $label=$role==='principal'?'kepala sekolah':'Super Admin';
    $prefix='letter-'.$role;
    $ready=!empty($states);
?>
<section class="letter-principal-panel" id="<?= $prefix ?>-panel" aria-labelledby="<?= $prefix ?>-heading"
         data-letter-signer="<?= $role ?>" data-ready="<?= $ready?'true':'false' ?>">
  <div class="letter-principal-heading">
    <span class="letter-principal-icon"><?= account_letter_icon($role==='principal'?'user_check':'user') ?></span>
    <div><h2 id="<?= $prefix ?>-heading"><?= $title ?></h2><?php if($role==='finance'): ?><p>Penanda tangan Bagian Keuangan</p><?php endif; ?></div>
  </div>
  <?php if(!$ready): ?>
    <p class="letter-principal-feedback is-error" role="alert"><?= $role==='principal'
        ?'Pengaturan Kepala Sekolah belum tersedia. Hubungi administrator sebelum membuat surat baru.'
        :'Pengaturan Super Admin belum tersedia. Surat memakai tanda tangan Bagian Keuangan.' ?></p>
  <?php elseif($signerUnit===0): ?>
    <div class="letter-principal-summary">
      <?php foreach($states as $state): $chosen=array_column($state['names'],'name','id')[$state['selected_id']]??''; ?>
        <div><span><?= report_e($state['school_name']) ?></span><strong><?= report_e($chosen) ?></strong></div>
      <?php endforeach; ?>
    </div>
    <p class="letter-principal-note">Pilih SD, SMP, atau SMA untuk mengelola penanda tangan unit.</p>
  <?php else: $state=$states[0]; ?>
    <div class="letter-principal-controls">
      <div class="letter-principal-choice">
        <label for="<?= $prefix ?>-select"><?= ucfirst($label) ?> terpilih</label>
        <select class="field-input field-select" id="<?= $prefix ?>-select">
          <?php foreach($state['names'] as $name): ?>
            <option value="<?= $name['id'] ?>" <?= $name['id']===$state['selected_id']?'selected':'' ?>><?= report_e($name['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="letter-principal-buttons">
        <button type="button" class="btn btn-ghost" id="<?= $prefix ?>-add"><?= account_letter_icon('add') ?>Tambah</button>
        <button type="button" class="btn btn-ghost" id="<?= $prefix ?>-edit"><?= account_letter_icon('edit') ?>Edit</button>
        <button type="button" class="btn btn-ghost letter-principal-delete" id="<?= $prefix ?>-delete"><?= account_letter_icon('trash') ?>Hapus</button>
      </div>
    </div>
    <div class="letter-principal-footer" hidden>
      <p id="<?= $prefix ?>-status" class="letter-principal-feedback" role="status" aria-live="polite"></p>
      <button type="button" class="letter-principal-reload" id="<?= $prefix ?>-reload" hidden>Muat ulang daftar</button>
    </div>
  <?php endif; ?>
</section>
<?php if($ready&&$signerUnit!==0): ?>
<dialog class="letter-principal-dialog" id="<?= $prefix ?>-dialog" aria-labelledby="<?= $prefix ?>-dialog-title" aria-describedby="<?= $prefix ?>-dialog-description">
  <form id="<?= $prefix ?>-form">
    <header>
      <h2 id="<?= $prefix ?>-dialog-title">Tambah <?= $label ?></h2>
      <button type="button" class="letter-principal-close" aria-label="Tutup dialog"><?= account_letter_icon('close') ?></button>
    </header>
    <div class="letter-principal-dialog-body">
      <p id="<?= $prefix ?>-dialog-description"></p>
      <div id="<?= $prefix ?>-name-field">
        <label for="<?= $prefix ?>-name">Nama <?= $label ?></label>
        <input id="<?= $prefix ?>-name" class="field-input" maxlength="150" autocomplete="off" required>
        <small>Maksimal 150 karakter, termasuk gelar bila ada.</small>
      </div>
      <div id="<?= $prefix ?>-replacement-field" hidden>
        <label for="<?= $prefix ?>-replacement">Nama pengganti</label>
        <select id="<?= $prefix ?>-replacement" class="field-input field-select"></select>
      </div>
      <p id="<?= $prefix ?>-dialog-error" class="letter-signer-dialog-error" role="alert" hidden></p>
    </div>
    <footer>
      <button type="button" class="btn btn-ghost letter-principal-cancel">Batal</button>
      <button type="submit" class="btn btn-primary" id="<?= $prefix ?>-save">Simpan</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>
<script type="application/json" id="<?= $prefix ?>-data"><?= json_encode([
    'unit_id'=>$signerUnit,'states'=>$states,'label'=>$label,'csrf'=>$_SESSION['csrf_parent_principal']??'',
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<?php endforeach; ?>
</div>
