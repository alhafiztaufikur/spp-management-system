<?php /** Included by the authenticated, unit-scoped master page. */ ?>
<section class="main-card master-modern-card fee-panel fee-optional-guide" style="margin-top:0">
  <div class="card-title-row fee-panel-heading"><div><h2>Terbitkan Biaya Tidak Berulang</h2></div></div>
  <p>Tagihan diterbitkan sekali per siswa dan dapat dicicil. Penerbitan ulang melewati siswa yang sudah memiliki tagihan.</p>
  <form method="post" class="fee-publish-form" id="fee-publish-form"
        data-roster-url="<?= htmlspecialchars('master_biaya_lain.php?section=roster&scope='.unit_active_id(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>"><input type="hidden" name="aksi" value="terbitkan_tagihan"><input type="hidden" name="expected_master" id="fee-publish-version">
    <input type="hidden" name="master_tab" value="<?= $masterTab ?>">
    <input type="hidden" name="page" value="<?= $masterPage ?>">
    <input type="hidden" name="target_ids_json" id="fee-publish-target-ids">
    <input type="hidden" name="expected_student_ids_json" id="fee-publish-expected-ids">
    <label class="field-label" for="fee-publish-master">Master Tidak Berulang</label><select class="field-input field-select" name="master_id" id="fee-publish-master" required><option value="">Pilih master</option><?php foreach($activeMasters as $master): ?><option value="<?= (int)$master['id'] ?>" data-nominal="<?= htmlspecialchars($master['nominal']) ?>" data-version="<?= other_fee_master_version($master) ?>"><?= htmlspecialchars($master['nama']) ?> · Rp <?= number_format((float)$master['nominal'],0,',','.') ?></option><?php endforeach; ?></select>
    <label class="field-label" for="fee-publish-target">Sasaran</label><select class="field-input field-select" name="target" id="fee-publish-target" <?= !$activeMasters?'disabled':'' ?>><option value="all">Seluruh siswa aktif</option><option value="rombel">Pilih rombel</option><option value="siswa">Pilih siswa</option></select>
    <div id="fee-publish-picker" hidden>
      <label class="field-label" for="fee-publish-search">Cari sasaran</label><input type="search" class="field-input" id="fee-publish-search" placeholder="Ketik nama, NIS, atau rombel">
      <div class="fee-picker-toolbar"><span id="fee-publish-count" aria-live="polite">0 terpilih</span><div><button type="button" id="fee-publish-all">Pilih Semua Hasil</button><button type="button" id="fee-publish-clear">Kosongkan Pilihan</button></div></div>
      <div id="fee-publish-ids" class="fee-picker-list" role="group" aria-label="Pilihan sasaran penerbitan"></div>
      <div class="fee-picker-pages" id="fee-publish-pages" hidden>
        <button type="button" class="btn btn-ghost btn-sm" id="fee-publish-prev">Sebelumnya</button>
        <span id="fee-publish-page"></span>
        <button type="button" class="btn btn-ghost btn-sm" id="fee-publish-next">Selanjutnya</button>
      </div>
    </div>
    <p id="fee-publish-error" role="alert" hidden></p>
    <button type="button" class="btn btn-ghost btn-sm" id="fee-publish-retry" hidden>Coba Lagi</button>
    <button class="btn btn-primary" type="submit" <?= !$activeMasters?'disabled':'' ?>><?= master_workspace_icon('plus') ?>Tinjau Penerbitan</button>
  </form>
</section>
