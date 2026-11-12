<?php
/** $principalStates and $principalReady are loaded before releasing the session. */
$principalUnit=unit_active_id();$principalEditable=$principalUnit!==0&&$principalReady;
?>
<section class="letter-principal-panel" id="letter-principal-panel" aria-labelledby="letter-principal-heading" data-ready="<?= $principalReady?'true':'false' ?>">
  <div class="letter-principal-heading"><span class="letter-principal-icon"><?= account_letter_icon('user_check') ?></span><div><h2 id="letter-principal-heading">Kepala Sekolah</h2></div></div>
  <?php if(!$principalReady): ?>
    <p class="letter-principal-feedback is-error" role="alert">Pengaturan Kepala Sekolah belum tersedia. Hubungi administrator sebelum membuat surat baru.</p>
  <?php elseif($principalUnit===0): ?>
    <div class="letter-principal-summary"><?php foreach($principalStates as $state): $chosen=array_values(array_filter($state['names'],static fn($n)=>$n['id']===$state['selected_id']))[0]??null; ?><div><span><?= report_e($state['school_name']) ?></span><strong><?= report_e($chosen['name']??'Belum dipilih') ?></strong></div><?php endforeach; ?></div>
    <p class="letter-principal-note">Pilih SD, SMP, atau SMA pada menu unit untuk mengelola kepala sekolah. Setiap surat memakai nama sesuai unit siswa.</p>
  <?php else: $state=$principalStates[0]; ?>
    <div class="letter-principal-controls">
      <div class="letter-principal-school"><span>Sekolah</span><strong><?= report_e($state['school_name']) ?></strong></div>
      <div class="letter-principal-choice"><label for="letter-principal-select">Kepala sekolah terpilih</label><select class="field-input field-select" id="letter-principal-select"><?php foreach($state['names'] as $name): ?><option value="<?= $name['id'] ?>" <?= $name['id']===$state['selected_id']?'selected':'' ?>><?= report_e($name['name']) ?></option><?php endforeach; ?></select></div>
      <div class="letter-principal-buttons"><button type="button" class="btn btn-ghost" id="letter-principal-add"><?= account_letter_icon('add') ?>Tambah</button><button type="button" class="btn btn-ghost" id="letter-principal-edit"><?= account_letter_icon('edit') ?>Edit</button><button type="button" class="btn btn-ghost letter-principal-delete" id="letter-principal-delete"><?= account_letter_icon('trash') ?>Hapus</button></div>
    </div>
    <div class="letter-principal-footer" hidden><p id="letter-principal-status" class="letter-principal-feedback" role="status" aria-live="polite"></p><button type="button" class="letter-principal-reload" id="letter-principal-reload" hidden>Muat ulang daftar</button></div>
  <?php endif; ?>
</section>
<?php if($principalEditable): ?>
<dialog class="letter-principal-dialog" id="letter-principal-dialog" aria-labelledby="letter-principal-dialog-title" aria-describedby="letter-principal-dialog-description">
  <form id="letter-principal-form">
    <header><h2 id="letter-principal-dialog-title">Tambah kepala sekolah</h2><button type="button" class="letter-principal-close" aria-label="Tutup dialog"><?= account_letter_icon('close') ?></button></header>
    <div class="letter-principal-dialog-body"><p id="letter-principal-dialog-description"></p><div id="letter-principal-name-field"><label for="letter-principal-name">Nama kepala sekolah</label><input id="letter-principal-name" class="field-input" maxlength="150" autocomplete="off" required><small>Maksimal 150 karakter, termasuk gelar bila ada.</small></div><div id="letter-principal-replacement-field" hidden><label for="letter-principal-replacement">Kepala sekolah pengganti</label><select id="letter-principal-replacement" class="field-input field-select"></select></div><p id="letter-principal-dialog-error" role="alert" hidden></p></div>
    <footer><button type="button" class="btn btn-ghost letter-principal-cancel">Batal</button><button type="submit" class="btn btn-primary" id="letter-principal-save">Simpan</button></footer>
  </form>
</dialog>
<?php endif; ?>
<script type="application/json" id="letter-principal-data"><?= json_encode(['unit_id'=>$principalUnit,'states'=>$principalStates,'csrf'=>$_SESSION['csrf_parent_principal']??''],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
