<?php
/** Primary next action is visible beside the simulation summary on every review section. */
$finalState=$draft['job_info']['state'];$finalUrl='legacy_operational_review.php?'.http_build_query(['id'=>$id,'section'=>'confirm']);
?>
<section class="dbt-card lop-finalization" id="migration-finalization" aria-labelledby="migration-finalization-title">
<?php if($batch&&$batch['state']==='completed'&&(int)$batch['version']===(int)$draft['version']): ?>
<h2 id="migration-finalization-title"><?= (int)$plan['summary']['pending']>0?'Siap Digunakan — Masih Ada Data Perlu Diperiksa':'Migrasi Selesai — Data Sudah Masuk' ?></h2>
<p>Data siap telah diterapkan dan verifikasi akhir lulus.<?= (int)$plan['summary']['pending']>0?' Catatan tertahan tetap tersedia untuk penyelesaian dalam pekerjaan ini.':' Data hasil migrasi dapat digunakan di sistem ini.' ?></p>
<div class="legacy-actions"><a class="dbt-button dbt-button-primary" href="siswa/daftar.php">Buka Data Siswa</a><a class="dbt-button" href="pembayaran/lihat.php">Riwayat Pembayaran</a><a class="dbt-button" href="legacy_accounts.php?batch=<?= loe($id) ?>">Rekening &amp; Transaksi</a></div>
<?php elseif(in_array($finalState,['apply_queued','applying'],true)): ?>
<h2 id="migration-finalization-title">Finalisasi Migrasi Sedang Berjalan</h2>
<p>Worker sedang menyiapkan backup, menerapkan data, dan memverifikasi hasil. Halaman ini dapat dibuka kembali; tidak perlu mengirim ulang.</p>
<a class="dbt-button" href="<?= loe($finalUrl) ?>">Muat Ulang Progres</a>
<?php elseif($batch&&in_array($batch['state'],['blocked','verifying'],true)): ?>
<h2 id="migration-finalization-title">Data Menunggu Verifikasi Akhir</h2><p>Jangan ulangi impor. Lanjutkan verifikasi hasil dan backup dari pekerjaan yang sama.</p>
<?php if($activeUnit!==0&&$finalState==='failed'): ?><form method="post" action="<?= loe($finalUrl) ?>"><?php lop_form_hidden('retry'); ?><button class="dbt-button dbt-button-primary">Verifikasi Ulang</button></form><?php endif; ?>
<?php else: ?>
<h2 id="migration-finalization-title">Selesaikan Migrasi</h2>
<p>Hasil simulasi belum menjadi data operasional. Finalisasi memasukkan siswa, pembayaran, dan tabungan ke database tujuan setelah konfirmasi.</p>
<?php if($activeUnit===0): ?><p class="dbt-info">Pilih <?= loe(unit_label($unit)) ?> di sidebar untuk finalisasi.</p>
<?php elseif($simulationCurrent&&!empty($draft['simulation']['ready'])&&!empty($draft['simulation']['confirmation_context'])&&$finalState==='ready'): ?>
<p><strong><?= (int)$plan['summary']['payments'] ?> pembayaran siap</strong> &middot; <?= (int)$plan['summary']['pending'] ?> catatan tertahan. Draf dan saldo yang telah disahkan digunakan pada penerapan.</p>
<form method="post" action="<?= loe($finalUrl) ?>"><?php lop_form_hidden('confirm'); ?><input type="hidden" name="digest" value="<?= loe($draft['simulation']['digest']) ?>"><button class="dbt-button dbt-button-primary">Finalisasi Migrasi</button></form>
<?php elseif(in_array($finalState,['simulate_queued','simulating','processing','queued'],true)): ?><p class="dbt-info">Pemeriksaan sedang berjalan. Tombol finalisasi tersedia setelah hasil siap.</p>
<?php else: ?><form method="post" action="<?= loe($finalUrl) ?>"><?php lop_form_hidden('simulate'); ?><button class="dbt-button dbt-button-primary">Perbarui Pemeriksaan untuk Finalisasi</button></form><p class="legacy-note">Pemeriksaan berjalan di latar belakang; pengaturan dan saldo FIX tetap tersimpan.</p>
<?php endif; ?>
<?php endif; ?>
</section>
