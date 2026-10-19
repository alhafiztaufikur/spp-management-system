<?php $preflight=lful_preflight($koneksi); ?>
<section class="dbt-card"><h2>Kesiapan migrasi</h2><p class="legacy-note">Lengkapi bagian yang belum siap, lalu muat ulang halaman ini.</p><div class="lful-readiness">
<?php foreach($preflight as $check): ?><div><strong><?= $check['ready']?'✓':'○' ?> <?= lful_e($check['label']) ?></strong><span><?= lful_e($check['help']) ?></span><?php if(!$check['ready']&&$check['href']): ?><a href="<?= lful_e($check['href']) ?>">Lengkapi</a><?php endif; ?></div><?php endforeach; ?>
</div></section>
