<?php /** Included by the authenticated, unit-scoped master page. */ ?>
<section class="main-card master-modern-card fee-panel fee-optional-guide" style="margin-top:0">
  <div class="card-title-row fee-panel-heading"><span class="fee-icon"><?= master_workspace_icon('document') ?></span><div><h2>Terbitkan Biaya Tidak Berulang</h2></div></div>
  <p>Berulang tersedia langsung di Input Pembayaran. Tidak Berulang diterbitkan sekali per siswa dan dapat dicicil; penerbitan ulang melewati siswa yang sudah memiliki tagihan.</p>
  <form method="post" class="fee-publish-form" id="fee-publish-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>"><input type="hidden" name="aksi" value="terbitkan_tagihan"><input type="hidden" name="expected_master" id="fee-publish-version">
    <label class="field-label" for="fee-publish-master">Master Tidak Berulang</label><select class="field-input field-select" name="master_id" id="fee-publish-master" required><option value="">Pilih master</option><?php foreach($activeMasters as $master): ?><option value="<?= (int)$master['id'] ?>" data-nominal="<?= htmlspecialchars($master['nominal']) ?>" data-version="<?= other_fee_master_version($master) ?>"><?= htmlspecialchars($master['nama']) ?> · Rp <?= number_format((float)$master['nominal'],0,',','.') ?></option><?php endforeach; ?></select>
    <label class="field-label" for="fee-publish-target">Sasaran</label><select class="field-input field-select" name="target" id="fee-publish-target"><option value="all">Seluruh siswa aktif</option><option value="rombel">Pilih rombel</option><option value="siswa">Pilih siswa</option></select>
    <div id="fee-publish-picker" hidden>
      <label class="field-label" for="fee-publish-search">Cari sasaran</label><input type="search" class="field-input" id="fee-publish-search" placeholder="Ketik nama, NIS, atau rombel">
      <div class="fee-picker-toolbar"><span id="fee-publish-count" aria-live="polite">0 terpilih</span><div><button type="button" id="fee-publish-all">Pilih Semua Hasil</button><button type="button" id="fee-publish-clear">Kosongkan Pilihan</button></div></div>
      <div id="fee-publish-ids" class="fee-picker-list" role="group" aria-label="Pilihan sasaran penerbitan"></div><p id="fee-publish-error" role="alert" hidden></p>
    </div>
    <button class="btn btn-primary" type="submit" <?= !$activeMasters?'disabled':'' ?>><?= master_workspace_icon('plus') ?>Tinjau Penerbitan</button>
  </form>
  <script type="application/json" id="fee-publish-roster"><?= json_encode(['students'=>array_map(static fn($s)=>['id'=>(int)$s['id'],'label'=>$s['NAMA'].' · '.$s['NO_INDUK'],'name'=>$s['NAMA'],'nis'=>$s['NO_INDUK'],'class_id'=>(int)$s['master_kelas_id']],$activeStudents),'classes'=>array_values(array_map(static fn($c)=>['id'=>(int)$c['id'],'label'=>$c['label']],$activeClasses))],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
</section>
