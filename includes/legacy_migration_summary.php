<?php /* Migration review only; no operational page consumes this view. */ ?>
<section class="dbt-card">
<h2>Hasil pencocokan otomatis</h2>
<p>Data sejak 2020 dan siswa aktif diprioritaskan. Catatan lama yang belum lengkap tetap disimpan tanpa menghalangi data lain yang siap.</p>
<p><strong><?= (int)($plan['summary']['fee_unresolved_priority']??0) ?> rincian biaya pada data prioritas belum diketahui jenisnya.</strong> <?= (int)($plan['summary']['fee_unresolved_historical']??0) ?> rincian lainnya disimpan sebagai histori lama tanpa memerlukan pemetaan satu per satu.</p>
<div class="lful-status-grid">
<?php if(!empty($plan['summary']['fee_closed'])): ?><div><strong><?= (int)$plan['summary']['fee_closed'] ?></strong><p>Histori biaya ditutup sesuai keputusan klien</p></div><?php endif; ?>
<?php foreach(['fee_direct'=>'Rincian biaya cocok langsung','fee_inferred'=>'Rincian biaya berdasarkan pola pendukung','fee_unresolved'=>'Jenis biaya belum teridentifikasi','du_reconciled'=>'Rekap DU tercakup pembayaran asal','du_unresolved'=>'Rekap DU belum terkonfirmasi','primary_banks_retained'=>'Rekening utama tetap digunakan','additional_bank_exceptions'=>'Catatan tambahan dengan pengecualian','source_balance_fixes'=>'Saldo akhir sumber dengan penyesuaian'] as $key=>$label): ?>
<div><strong><?= (int)($plan['summary'][$key]??0) ?></strong><p><?= loe($label) ?></p></div>
<?php endforeach; ?>
</div>
<p>Rekap DU tidak dihitung sebagai penerimaan tambahan. Penyesuaian saldo mengikuti saldo akhir sumber dan tidak membuktikan adanya setoran atau penarikan yang hilang.</p>
<div class="legacy-actions"><a class="dbt-button" href="legacy_history.php?id=<?= loe($id) ?>&amp;kind=du_reconciled">Lihat rekap DU yang cocok</a><a class="dbt-button" href="legacy_history.php?id=<?= loe($id) ?>&amp;kind=du_unresolved">Lihat rekap DU belum terkonfirmasi</a></div>
<?php if(!empty($plan['migration_evidence']['banks'])): ?>
<details><summary>Perbedaan sumber tabungan</summary><ul class="lop-confirm-list">
<?php foreach($plan['migration_evidence']['banks'] as $key=>$decision): ?>
<li><strong><?= loe($catalog['additional_savings'][$key]['identity']??$key) ?></strong><span>Rekening utama: <?= loe(legacy_ui_money($decision['source_balance']??null)) ?> · Catatan tambahan: <?= loe(legacy_ui_money($decision['additional_balance']??null)) ?>. Rekening utama tetap digunakan.</span><?php if(!empty($decision['additional_issues'])): ?><p>Pengecualian catatan tambahan: <?= loe(implode(' ', $decision['additional_issues'])) ?> Sumber asli tetap diarsipkan.</p><?php endif; ?></li>
<?php endforeach; ?>
</ul></details>
<?php endif; ?>
</section>
