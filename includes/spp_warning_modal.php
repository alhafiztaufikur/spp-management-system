<div class="spp-warning-overlay" id="spp-warning-overlay" role="alertdialog" aria-modal="true"
     aria-labelledby="spp-warning-title" aria-describedby="spp-warning-message" aria-hidden="true">
  <div class="spp-warning-dialog" role="document">
    <div class="spp-warning-head">
      <?php if (!empty($showPaymentNoticeIcons)): ?>
      <span class="payment-notice-symbol" aria-hidden="true">
        <svg class="payment-notice-obligations-icon" viewBox="0 0 24 24">
          <path d="M8 3h8l4 4v14H4V3h4Zm8 0v5h4M8 12h8m-8 4h8"/>
        </svg>
        <svg class="payment-notice-method-icon" viewBox="0 0 24 24">
          <rect x="3" y="5" width="18" height="14" rx="3"/>
          <path d="M3 10h18M7 15h4"/>
        </svg>
      </span>
      <?php endif; ?>
      <h3 class="spp-warning-title" id="spp-warning-title">Pembayaran perlu diperiksa</h3>
    </div>
    <div class="spp-warning-content">
      <p class="spp-warning-message" id="spp-warning-message"></p>
      <div class="spp-warning-amount" id="spp-warning-amount" hidden></div>
      <div class="spp-warning-actions">
        <button type="button" class="btn btn-ghost" id="spp-warning-secondary" hidden>Kembali</button>
        <button type="button" class="btn btn-primary" id="spp-warning-close">Mengerti</button>
      </div>
    </div>
  </div>
</div>
