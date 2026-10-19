/* Progressive enhancement: every action remains an ordinary server form. */
(() => {
  const groupForm = document.getElementById('group-review-form');
  if (groupForm) {
    const type = groupForm.querySelector('[name=group_type]');
    const display = () => groupForm.querySelectorAll('[data-group-types]').forEach(el => { el.hidden = !el.dataset.groupTypes.split(' ').includes(type.value); });
    type.addEventListener('change', display); display();
  }
  const progress = document.querySelector('[data-full-job]');
  if (progress && ['queued','processing','simulate_queued','simulating','apply_queued','applying'].includes(progress.dataset.fullState)) {
    const poll = async () => {
      try {
        const response = await fetch('legacy_import_api.php?action=' + (progress.dataset.upload ? 'status' : 'full_status') + '&id=' + encodeURIComponent(progress.dataset.fullJob), {credentials:'same-origin',cache:'no-store'});
        if (!response.ok) throw new Error('retry');
        const result = await response.json();
        const state = result.job || (result.draft || {}).job_info || {};
        if (state.state && !['queued','processing','simulate_queued','simulating','apply_queued','applying'].includes(state.state)) { location.reload(); return; }
      } catch (_) { /* Reload remains available when the network is interrupted. */ }
      setTimeout(poll, 5000);
    };
    setTimeout(poll, 5000);
  }
  document.querySelectorAll('.lful-component-target').forEach(select => {
    select.addEventListener('change', () => {
      if (!select.value.startsWith('biaya_baru:')) return;
      const proposal = document.getElementById('proposal-' + select.value.slice(11));
      if (proposal) { proposal.open = true; }
    });
  });
  const confirmation = document.getElementById('full-confirm');
  if (confirmation && typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal === 'function') {
    const dialog = document.createElement('dialog'); dialog.className = 'lful-modal';
    dialog.setAttribute('aria-label', confirmation.dataset.confirmLabel || 'Konfirmasi migrasi penuh');
    const back = confirmation.querySelector('[data-confirm-back],button[value=back],a');
    if (!confirmation.classList.contains('lful-structured')) {
      const header = document.createElement('header'); header.className = 'lful-modal-header';
      const heading = confirmation.querySelector('h2,h3'); if (heading) header.append(heading);
      const close = document.createElement('button'); close.type = 'button'; close.className = 'lful-modal-close';
      close.setAttribute('aria-label', 'Tutup konfirmasi'); close.textContent = '×';
      close.addEventListener('click', () => { if (back) back.click(); else dialog.close(); }); header.append(close);
      const body = document.createElement('div'); body.className = 'lful-modal-body';
      const footer = document.createElement('footer'); footer.className = 'lful-modal-footer';
      const actions = confirmation.querySelector('.legacy-actions,.dbt-dialog-actions');
      if (actions) {
        const form = actions.closest('form');
        if (form) {
          form.id = form.id || 'legacy-confirm-submit';
          actions.querySelectorAll('button,input,select,textarea').forEach(control => control.setAttribute('form',form.id));
        }
        footer.append(actions);
      }
      while (confirmation.firstChild) body.append(confirmation.firstChild);
      confirmation.append(header,body,footer); confirmation.classList.add('lful-structured');
    }
    confirmation.before(dialog); dialog.append(confirmation);
    const heading = confirmation.querySelector('h2,h3');
    if (heading) { heading.id = heading.id || 'legacy-confirm-title'; dialog.setAttribute('aria-labelledby',heading.id); }
    dialog.showModal();
    const close = confirmation.querySelector('.lful-modal-close'); if (close) close.focus({preventScroll:true});
    dialog.addEventListener('cancel', event => {
      event.preventDefault();
      if (back) back.click(); else dialog.close();
    });
  }
})();
