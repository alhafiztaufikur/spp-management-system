<div class="prior-debt-modal" id="prior-debt-modal" hidden aria-hidden="true">
  <div class="prior-debt-modal__backdrop" data-prior-debt-cancel></div>
  <section class="prior-debt-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="prior-debt-title">
    <header class="prior-debt-modal__header">
      <div>
        <span class="prior-debt-modal__eyebrow">Peringatan Tagihan Lama</span>
        <h2 id="prior-debt-title">Masih ada tunggakan tahun sebelumnya</h2>
        <p>Penerbitan tetap dapat dilanjutkan. Tagihan lama tidak akan diubah atau dihapus.</p>
      </div>
      <button type="button" class="prior-debt-modal__close" data-prior-debt-cancel aria-label="Tutup">&times;</button>
    </header>
    <div class="prior-debt-modal__summary">
      <div><span>Siswa Menunggak</span><strong data-prior-debt-count>0 siswa</strong></div>
      <div><span>Total Tunggakan</span><strong data-prior-debt-total>Rp 0</strong></div>
    </div>
    <div class="prior-debt-modal__list" data-prior-debt-list></div>
    <footer class="prior-debt-modal__actions">
      <button type="button" class="btn btn-ghost" data-prior-debt-cancel>Batal</button>
      <button type="button" class="btn btn-warning" data-prior-debt-continue>Tetap Terbitkan</button>
    </footer>
  </section>
</div>
