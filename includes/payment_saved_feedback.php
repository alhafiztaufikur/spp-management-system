<?php // Only a committed input result may render this dialog. ?>
<dialog id="payment-saved-dialog" class="operation-feedback-dialog payment-saved-dialog" data-type="success" aria-labelledby="payment-saved-title" aria-describedby="payment-saved-message">
  <header><h2 id="payment-saved-title">Pembayaran berhasil disimpan</h2></header>
  <p id="payment-saved-message"><?= $savedPaymentReceiptUrl ? 'Cetak struk pembayaran ini?' : 'Silakan lanjutkan input pembayaran berikutnya.' ?></p>
  <footer>
    <button type="button" class="btn btn-ghost" id="payment-saved-continue" autofocus>Lanjut input</button>
    <?php if ($savedPaymentReceiptUrl): ?>
    <a class="btn btn-primary" id="payment-saved-print" href="<?= htmlspecialchars($savedPaymentReceiptUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Cetak struk</a>
    <?php endif; ?>
  </footer>
</dialog>
