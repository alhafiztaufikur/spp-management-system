(() => {
  'use strict';
  const panel = document.getElementById('daftar-master-biaya');
  if (!panel) return;

  const feedback = panel.querySelector('.fee-usage-feedback');
  const status = document.getElementById('fee-usage-status');
  const retry = document.getElementById('fee-usage-retry');
  const deleteCount = document.getElementById('fee-delete-count');

  async function loadUsage() {
    if (panel.getAttribute('aria-busy') === 'true') return;
    panel.setAttribute('aria-busy', 'true');
    feedback.hidden = false;
    retry.hidden = true;
    status.textContent = 'Memuat rincian pemakaian…';
    try {
      const response = await fetch(panel.dataset.usageUrl, {cache: 'no-store'});
      if (!response.ok || response.redirected) throw new Error('Usage unavailable');
      const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
      const result = documentFragment.querySelector('#fee-master-body[data-usage-ready]');
      if (!result) throw new Error('Usage unavailable');
      const body = document.getElementById('fee-master-body');
      const currentRows = Array.from(body.querySelectorAll('[data-master-id]'));
      const incomingRows = Array.from(result.querySelectorAll('[data-master-id]'));
      if (deleteCount || currentRows.length !== incomingRows.length
        || currentRows.some((row, index) => row.dataset.masterId !== incomingRows[index].dataset.masterId)) {
        body.replaceWith(result);
      } else {
        currentRows.forEach((row, index) => {
          row.querySelectorAll('[data-usage-cell]').forEach(cell => {
            const update = incomingRows[index].querySelector('[data-usage-cell="' + cell.dataset.usageCell + '"]');
            if (update) cell.replaceWith(update);
          });
        });
        body.dataset.usageReady = '';
      }
      if (deleteCount) {
        deleteCount.textContent = Number(result.dataset.total).toLocaleString('id-ID');
        deleteCount.hidden = false;
      }
      feedback.hidden = true;
    } catch {
      status.textContent = 'Rincian pemakaian belum dapat dimuat.';
      retry.hidden = false;
    } finally {
      panel.removeAttribute('aria-busy');
    }
  }

  retry.addEventListener('click', loadUsage);
  if (panel.dataset.loadUsage === '1') requestAnimationFrame(() => requestAnimationFrame(loadUsage));
})();
