/* Real job checkpoints, upload bytes, and explicit apply confirmation. */
(() => {
  const $ = id => document.getElementById(id);
  const config = JSON.parse($('legacy-config').textContent);
  const storageKey = 'spp-legacy-job:' + config.key;
  let file = null, category = 'students', ready = false, financeReady = false, id = localStorage.getItem(storageKey), timer, state = '', jobUnit=0, restoreInProgress=false;
  const start = $('legacy-start'), dialog = $('legacy-dialog');
  const units = {SD: 1, SMP: 2, SMA: 3};
  const refresh = () => {
    start.disabled = restoreInProgress || !(ready && file && (category === 'students' || (category === 'finance' && financeReady)) && units[$('legacy-unit').value] === config.unit);
    if (category === 'finance' && !financeReady) $('legacy-readiness').textContent = 'Keuangan Legacy belum siap. Administrator perlu memasang skema melalui CLI.';
    else if (!config.unit) $('legacy-readiness').textContent = 'Pilih SD, SMP, atau SMA di sidebar. Semua Unit hanya untuk baca.';
    else if (units[$('legacy-unit').value] !== config.unit) $('legacy-readiness').textContent = 'Unit sumber harus sama dengan pilihan sidebar.';
    else if (ready) $('legacy-readiness').textContent = 'Worker siap. Pilih satu backup SQL Server (.dat, .bak, atau tanpa ekstensi) untuk diperiksa.';
  };
  const api = async (action, fields = {}) => {
    const body = new URLSearchParams({action, csrf_token: config.csrf, ...fields});
    body.set('_operational_unit', String(config.unit));
    const response = await fetch('legacy_import_api.php', {method: 'POST', body});
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Permintaan gagal.'); return result;
  };
  const statusText = text => { $('legacy-progress-status').textContent = text; };
  const progress = (done, total) => { if (total > 0) $('legacy-progress').value = Math.min(100, done / total * 100); else $('legacy-progress').removeAttribute('value'); };
  const render = result => {
    const job = result.job; state = job.state;jobUnit=Number(job.unit);
    const finance = job.category === 'finance';
    $('legacy-confirm-section').querySelector('p').textContent = finance ? 'Konfirmasi hanya menyimpan arsip histori. Saldo, tunggakan, dan penerimaan aktif belum berubah.' : 'Siswa diterima sebagai Legacy, tanpa penempatan, tagihan, atau rekening Tabungan.';
    $('legacy-confirm').textContent = finance ? 'Simpan Arsip Keuangan' : 'Impor Identitas Legacy';
    $('legacy-job-name').textContent = `${job.name} · ${Object.keys(units).find(key => units[key] === Number(job.unit))}`;
    statusText(`${job.stage}: ${job.message || (job.total > 0 ? `${job.done}/${job.total} baris` : 'Sedang diproses')}`);
    progress(job.done, job.total);
    $('legacy-cancel-job').disabled = ['applying', 'imported', 'failed', 'cancelled'].includes(state);
    $('legacy-confirm-section').hidden = state !== 'ready';
    $('legacy-confirm').disabled = restoreInProgress || state !== 'ready' || $('legacy-confirmation').value !== 'IMPOR LEGACY' || Number(job.unit) !== config.unit;
    const preview = result.preview;
    $('legacy-issues').hidden = !preview;
    $('legacy-issues').href = 'legacy_import_api.php?action=issues&id=' + encodeURIComponent(id);
    if (preview) {
      $('legacy-counts').textContent = Object.entries(preview.counts).map(([key, value]) => `${key}: ${value}`).join(' · ') + `. Pratinjau maksimal ${preview.limit} baris; laporan memuat seluruh baris.`;
      const table = document.createElement('table'); table.className = 'dbt-table';
      const head = document.createElement('tr'); ['Baris', 'NIS', 'Nama', finance ? 'Tabel sumber' : 'Kelas sumber', 'Hasil', 'Alasan'].forEach(label => { const cell = document.createElement('th'); cell.textContent = label; head.append(cell); });table.append(head);
      preview.rows.forEach(row => { const tr = document.createElement('tr'); ['ordinal', 'nis', 'name', 'class', 'result', 'reason'].forEach(key => { const td = document.createElement('td');td.textContent = row[key];tr.append(td); });table.append(tr); });
      $('legacy-preview').replaceChildren(table);
    }
  };
  const poll = async () => {
    clearTimeout(timer); if (!id || restoreInProgress) return;
    try {
      const response = await fetch('legacy_import_api.php?action=status&id=' + encodeURIComponent(id), {cache: 'no-store'});
      const result = await response.json();if (!response.ok) throw new Error(result.error || 'Status tidak tersedia.');render(result);
      if (['queued', 'processing', 'apply_queued', 'applying'].includes(state)) timer = setTimeout(poll, 2000);
    } catch (e) { statusText(e.message); }
  };
  document.addEventListener('legacy-file-selected', event => { file = event.detail;refresh(); });
  document.addEventListener('legacy-category-selected', event => { category = event.detail;refresh(); });
  $('legacy-unit').addEventListener('change', refresh);
  document.addEventListener('legacy-selection-changed',refresh);
  document.addEventListener('database-restore-active',event=>{
    const wasActive=restoreInProgress;restoreInProgress=!!event.detail;
    if(restoreInProgress)clearTimeout(timer);else if(wasActive && id)poll();
    $('legacy-confirm').disabled=restoreInProgress || state!=='ready' || $('legacy-confirmation').value!=='IMPOR LEGACY' || jobUnit!==config.unit;
    refresh();
  });
  start.addEventListener('click', () => {
    if (start.disabled) return;
    start.disabled = true; dialog.showModal();$('legacy-preview').replaceChildren();$('legacy-counts').textContent = '';statusText('Unggah'); progress(0, 1);
    const body = new FormData();body.append('_operational_unit', String(config.unit));body.append('action', 'upload');body.append('csrf_token', config.csrf);body.append('unit', config.unit);body.append('category', category);body.append('backup', file);
    const xhr = new XMLHttpRequest();xhr.open('POST', 'legacy_import_api.php');xhr.responseType = 'json';
    xhr.upload.addEventListener('progress', event => { if (event.lengthComputable) { progress(event.loaded, event.total);statusText(`Unggah: ${Math.round(event.loaded / event.total * 100)}%`); } });
    xhr.addEventListener('load', () => { refresh();if (xhr.status >= 400 || !xhr.response?.id) { statusText(xhr.response?.error || 'Unggahan gagal.');return; }id = xhr.response.id;localStorage.setItem(storageKey, id);$('legacy-reopen').hidden = false;poll(); });
    xhr.addEventListener('error', () => { refresh();statusText('Koneksi terputus. Periksa status pekerjaan sebelum mengunggah ulang.'); });xhr.send(body);
  });
  $('legacy-confirmation').addEventListener('input', () => { $('legacy-confirm').disabled = restoreInProgress || state !== 'ready' || jobUnit!==config.unit || $('legacy-confirmation').value !== 'IMPOR LEGACY'; });
  $('legacy-confirm').addEventListener('click', async () => { $('legacy-confirm').disabled = true;try { await api('confirm', {id, confirmation: $('legacy-confirmation').value});poll(); } catch (e) { statusText(e.message); } });
  $('legacy-cancel-job').addEventListener('click', async () => { try { await api('cancel', {id});poll(); } catch (e) { statusText(e.message); } });
  $('legacy-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => $('legacy-reopen').focus());
  $('legacy-reopen').hidden = !id;
  $('legacy-reopen').addEventListener('click', () => { dialog.showModal();poll(); });
  fetch('legacy_import_api.php?action=health', {cache: 'no-store'}).then(r => r.json()).then(result => { ready = result.ready === true;financeReady = result.finance_ready === true;if (!ready) $('legacy-readiness').textContent = result.reasons?.join(' ') || 'Layanan belum tersedia.';refresh(); }).catch(() => { $('legacy-readiness').textContent = 'Kesiapan worker tidak dapat diperiksa.'; });
})();
