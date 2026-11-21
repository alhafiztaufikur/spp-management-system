/* One post-commit result; printing never submits the input form again. */
(() => {
  'use strict';
  function showSavedPayment() {
    const dialog = document.getElementById('payment-saved-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const close = () => dialog.close();
    dialog.querySelector('#payment-saved-continue').addEventListener('click', close);
    dialog.querySelector('#payment-saved-print')?.addEventListener('click', close);
    dialog.addEventListener('close', () => document.getElementById('siswa-search')?.focus());
    // A cached page must not repeat a result when returning from another menu.
    window.addEventListener('pagehide', () => { if (dialog.open) close(); });
    dialog.showModal();
    const fallback = document.getElementById('flash-msg');
    if (fallback) fallback.hidden = true;
    document.getElementById('payment-saved-continue').focus();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showSavedPayment, { once: true });
  else showSavedPayment();
})();
