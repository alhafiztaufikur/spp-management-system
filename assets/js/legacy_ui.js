/* Optional enhancement. Navigation, review and confirmation remain server forms. */
(() => {
  const forms = Array.from(document.querySelectorAll('.legacy-ui form[method="post"]'));
  let dirty = false, submitting = false;
  forms.forEach(form => {
    form.addEventListener('input', () => { dirty = true; document.querySelectorAll('[data-save-status]').forEach(el => { el.textContent = 'Perubahan belum disimpan'; }); });
    form.addEventListener('submit', event => {
      if (form.dataset.busy) { event.preventDefault(); return; }
      form.dataset.busy = '1'; submitting = true;
      // Submit values are required by server actions. Disable only after serialization.
      setTimeout(() => form.querySelectorAll('button[type=submit],button:not([type])').forEach(button => { button.disabled = true; }), 0);
    });
  });
  window.addEventListener('beforeunload', event => { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });
  document.querySelectorAll('[data-legacy-group-context] select').forEach(select => select.addEventListener('change', () => {
    // GET replaces group membership; temporary selections never cross groups.
    if (!dirty || window.confirm('Ada perubahan yang belum disimpan. Ganti kelompok?')) { submitting = true; select.form.requestSubmit(); }
  }));
  const job = document.querySelector('[data-import-progress]');
  if (job && ['queued','processing','simulate_queued','simulating','apply_queued','applying'].includes(job.dataset.state)) {
    const poll = async () => { try { const r = await fetch('legacy_import_api.php?action=status&id=' + encodeURIComponent(job.dataset.importProgress), {cache:'no-store',credentials:'same-origin'}); if (!r.ok) throw new Error(); const data = await r.json(); if (data.job.state !== job.dataset.state) { location.reload(); return; } } catch (_) { const message = document.querySelector('[data-network-status]'); if (message) message.textContent = 'Koneksi terputus. Pemeriksaan akan dicoba lagi; Muat Ulang tetap tersedia.'; } setTimeout(poll,5000); }; setTimeout(poll,5000);
  }
})();
