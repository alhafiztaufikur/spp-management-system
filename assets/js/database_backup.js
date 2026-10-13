/* Local backup queue; file contents and private configuration never enter this page. */
(() => {
  'use strict';
  const configNode = document.getElementById('database-backup-config');
  const form = document.getElementById('database-backup-form');
  if (!configNode || !form) return;
  let config;
  try { config = JSON.parse(configNode.textContent); } catch (_) { return; }
  const byId = id => document.getElementById(id);
  const states = {queued: 'Menunggu', dumping: 'Mencadangkan', verifying: 'Memverifikasi', verified: 'Terverifikasi', failed: 'Gagal'};
  const types = {manual: 'Manual', scheduled: 'Terjadwal', catchup: 'Susulan'};
  const number = new Intl.NumberFormat('id-ID');
  const date = value => {
    if (!value) return 'Belum ada';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.valueOf())) return '—';
    const parts = new Intl.DateTimeFormat('id-ID', {timeZone: 'Asia/Jakarta', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).formatToParts(parsed);
    const part = type => parts.find(item => item.type === type)?.value || '';
    return `${part('day')}/${part('month')}/${part('year')} ${part('hour')}:${part('minute')} WIB`;
  };
  const bytes = value => {
    if (value === null || value === undefined) return '—';
    let size = Math.max(0, Number(value) || 0), index = 0;
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    while (size >= 1024 && index < units.length - 1) { size /= 1024; index++; }
    return new Intl.NumberFormat('id-ID', {minimumFractionDigits: index ? 1 : 0, maximumFractionDigits: index ? 1 : 0}).format(size) + ' ' + units[index];
  };
  const element = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  const request = async (url, options = {}) => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try { return await fetch(url, {...options, signal: controller.signal}); }
    finally { clearTimeout(timeout); }
  };
  let summary = config.summary || {}, submitting = false, polling = false, timer;
  let previousActive = summary.active?.id || null;
  const feedback = (message, type = 'error') => {
    const node = byId('backup-feedback');
    node.textContent = message;
    node.className = 'dbt-feedback is-' + (type === 'success' ? 'success' : 'error');
    node.hidden = !message;
  };
  const renderHistory = jobs => {
    const search = String(config.search || '').toLocaleLowerCase('id-ID');
    const filtered = jobs.filter(job => (!config.type || job.type === config.type) && (!search || [job.name, job.actor_name, job.message, types[job.type]].join(' ').toLocaleLowerCase('id-ID').includes(search)));
    const body = byId('backup-history-body');
    const rows = document.createDocumentFragment();
    filtered.forEach(job => {
      const row = element('tr');
      const name = element('td');
      name.append(element('strong', job.name || 'Cadangan'));
      if (job.message) name.append(element('span', job.message, 'dbt-row-message'));
      if (job.type === 'catchup' && job.scheduled_for) name.append(element('span', 'Jadwal terlewat: ' + date(job.scheduled_for), 'dbt-row-message'));
      row.append(name, element('td', types[job.type] || '—'), element('td', date(job.created_at)), element('td', bytes(job.bytes || 0)), element('td', job.actor_name || 'Otomatis'));
      const status = element('td');
      const safeState = Object.hasOwn(states, job.state) ? job.state : 'queued';
      status.append(element('span', states[safeState], 'dbt-job-status is-' + safeState));
      const action = element('td');
      if (job.state === 'verified' && job.download_url && job.id) {
        const link = element('a', 'Unduh', 'dbt-button dbt-button-outline dbt-download');
        link.href = 'backup_api.php?action=download&id=' + encodeURIComponent(job.id);
        link.setAttribute('aria-label', 'Unduh ' + (job.name || 'cadangan'));
        action.append(link);
      } else action.append(element('span', '—', 'dbt-hint'));
      row.append(status, action); rows.append(row);
    });
    if (!filtered.length) {
      const row = element('tr'), cell = element('td', undefined, 'dbt-empty'), content = element('div', undefined, 'dbt-empty-content');
      cell.colSpan = 7;
      content.append(element('strong', search || config.type ? 'Tidak ada cadangan yang cocok' : 'Belum ada cadangan'), element('p', search || config.type ? 'Ubah pencarian atau filter tipe.' : 'Cadangan akan tampil di sini setelah pencadangan dimulai.'));
      cell.append(content); row.append(cell); rows.append(row);
    }
    body.replaceChildren(rows);
    byId('backup-history-count').textContent = number.format(filtered.length) + ' cadangan ditampilkan';
  };
  const render = next => {
    summary = next;
    const active = summary.active;
    byId('backup-next').textContent = date(summary.next_schedule);
    byId('backup-last').textContent = date(summary.last_success?.finished_at);
    byId('backup-count').textContent = number.format(Number(summary.count) || 0);
    byId('backup-total-size').textContent = bytes(summary.total_bytes || 0) + ' ukuran terverifikasi';
    byId('backup-free-space').textContent = bytes(summary.free_bytes);
    byId('backup-create').disabled = submitting || !summary.ready || !!active;
    byId('backup-create-hint').textContent = active ? 'Satu pencadangan sedang berlangsung. Progres tampil di bawah.' : summary.ready ? 'Cadangan baru akan diproses oleh layanan backup.' : 'Layanan backup belum siap. Periksa informasi di atas.';
    const alerts = document.createDocumentFragment();
    (Array.isArray(summary.alerts) ? summary.alerts : []).forEach(alert => alerts.append(element('div', alert, 'dbt-warning')));
    byId('backup-alerts').replaceChildren(alerts);
    byId('backup-progress').hidden = !active;
    if (active) {
      byId('backup-progress-state').textContent = states[active.state] || 'Menunggu';
      byId('backup-progress-message').textContent = active.message || '';
      document.querySelectorAll('[data-backup-stage]').forEach(node => {
        if (node.dataset.backupStage === active.state) node.setAttribute('aria-current', 'step');
        else node.removeAttribute('aria-current');
      });
    } else if (previousActive) {
      const completed = (summary.jobs || []).find(job => job.id === previousActive);
      if (completed?.state === 'verified') feedback('Backup selesai dan sudah terverifikasi.', 'success');
      else if (completed?.state === 'failed') feedback(completed.message || 'Backup gagal. Periksa riwayat untuk mencoba kembali.');
    }
    previousActive = active?.id || null;
    renderHistory(Array.isArray(summary.jobs) ? summary.jobs : []);
  };
  const schedule = () => {
    clearTimeout(timer);
    timer = setTimeout(refresh, summary.active ? 2000 : 30000);
  };
  async function refresh() {
    if (polling) return;
    polling = true;
    try {
      const response = await request('backup_api.php?action=status', {headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok) throw new Error('status');
      const next = await response.json();
      if (!Array.isArray(next.jobs) || typeof next.ready !== 'boolean') throw new Error('status');
      render(next);
    } catch (_) {
      feedback('Status belum dapat diperbarui. Data terakhir tetap ditampilkan; mencoba kembali otomatis.');
    } finally { polling = false; schedule(); }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting || !summary.ready || summary.active) return;
    submitting = true;
    byId('backup-create').disabled = true;
    byId('backup-create').setAttribute('aria-busy', 'true');
    feedback('Mengirim permintaan backup…', 'success');
    try {
      const payload = new FormData(form);
      payload.delete('return'); // Native submission keeps PRG; fetch requires the JSON acknowledgement.
      const response = await request(form.getAttribute('action'), {method: 'POST', body: payload, headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
      const result = await response.json();
      if (!response.ok) throw new Error(typeof result.error === 'string' ? result.error : 'Permintaan backup belum berhasil. Coba kembali.');
      if (typeof result.request_key === 'string' && /^[a-f0-9]{32}$/.test(result.request_key)) form.elements.request_key.value = result.request_key;
      if (result.job) summary.active = result.job;
      feedback('Permintaan backup diterima. Progres diperbarui otomatis.', 'success');
      render(summary);
    } catch (error) {
      feedback(error instanceof SyntaxError || error instanceof TypeError || error.name === 'AbortError' ? 'Koneksi terputus. Periksa status sebelum mencoba kembali; permintaan yang sama tidak digandakan.' : error.message);
    } finally {
      submitting = false;
      byId('backup-create').removeAttribute('aria-busy');
      byId('backup-create').disabled = !summary.ready || !!summary.active;
      refresh();
    }
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  render(summary);
  schedule();
})();
