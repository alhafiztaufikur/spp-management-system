<?php /** Read-only history inside the payment form. */ ?>
<div class="student-history" id="student-history">
  <div class="sh-toolbar">
    <label class="sh-year" for="payment-history-scope"><span>Tahun Ajaran / Penempatan</span><select id="payment-history-scope" class="field-input field-select"><option value="active">Penempatan Aktif</option><option value="all">Semua Tahun</option></select></label>
    <div class="sh-tools">
      <button type="button" class="sh-filter-button" id="sh-filter-toggle" aria-expanded="false" aria-controls="sh-filter"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M7 11h10M10 17h4"/></svg>Filter <span aria-hidden="true">⌄</span></button>
    </div>
    <div class="sh-filter" id="sh-filter" hidden>
      <label>Metode pembayaran<select id="sh-method" class="field-input"><option value="">Semua metode</option><option>Tunai</option><option>Qris</option><option>VA</option></select></label>
      <label>Komponen<select id="sh-component" class="field-input"><option value="">Semua komponen</option></select></label>
      <button type="button" class="btn btn-primary" id="sh-apply">Terapkan</button><button type="button" class="btn btn-ghost" id="sh-reset">Reset</button>
    </div>
  </div>
  <section class="payment-history-panel" id="payment-history-panel" aria-label="Riwayat Pembayaran Siswa">
    <div class="payment-history-head"><span class="sh-heading-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M7 7h10M7 11h3M14 11h3M7 16h10"/></svg></span><div><h3>Riwayat Pembayaran Siswa</h3><span id="payment-history-period">Pilih siswa untuk melihat transaksi.</span></div></div>
    <div class="payment-history-table-wrap"><table class="payment-history-table"><thead><tr>
      <?php foreach(['tanggal'=>'Tanggal','periode'=>'Periode','komponen'=>'Komponen','metode'=>'Metode','operator'=>'Operator','total'=>'Total'] as $key=>$label): ?><th scope="col" data-sort-column="<?= $key ?>" aria-sort="<?= $key==='tanggal'?'descending':'none' ?>"><button type="button" data-history-sort="<?= $key ?>"><?= $label ?><span aria-hidden="true">↕</span></button></th><?php endforeach; ?>
      <th scope="col"><span class="sh-sr-only">Tindakan</span></th>
    </tr></thead><tbody id="payment-history-body"><tr><td colspan="7">Belum ada siswa dipilih.</td></tr></tbody></table></div>
    <footer class="sh-footer" id="sh-count" aria-live="polite">Pilih siswa untuk melihat transaksi.</footer>
  </section>
  <dialog id="sh-detail" class="sh-detail-dialog" aria-labelledby="sh-detail-title"><header><h2 id="sh-detail-title">Detail Transaksi</h2><button type="button" id="sh-detail-close" aria-label="Tutup detail">&times;</button></header><div id="sh-detail-content"></div></dialog>
</div>
