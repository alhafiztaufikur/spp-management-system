/* Progressive enhancement: server confirmation and all forms also work without JS. */
(() => {
  const panel = document.getElementById('lf-confirmation');
  if (panel && typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal === 'function') {
    const dialog = document.createElement('dialog');
    dialog.className = 'lf-confirm-dialog'; dialog.setAttribute('aria-labelledby', 'lf-confirm-title');
    document.body.appendChild(dialog); dialog.appendChild(panel); dialog.showModal();
    document.getElementById('lf-confirm-title').focus();
    dialog.addEventListener('cancel', event => { event.preventDefault(); panel.querySelector('a').click(); });
  }
})();
