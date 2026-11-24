document.addEventListener('DOMContentLoaded', () => {
  const mode = document.getElementById('payment-period-mode');
  if (!mode) return;
  const form = mode.form;
  const editId = Number(mode.dataset.editId);
  const nis = form.elements.no_induk;
  const date = form.elements.tanggal_bayar;
  const choice = document.getElementById('payment-period-choice');
  const preview = form.querySelector('[data-payment-period-preview]');
  const controls = form.querySelector('[data-payment-period-controls]');
  const status = preview.querySelector('[data-payment-period-status]');
  const count = preview.querySelector('[data-payment-period-count]');
  const table = preview.querySelector('[data-payment-period-table]');
  const version = form.querySelector('[data-payment-period-version]');
  const tbody = table.querySelector('tbody');
  const total = document.getElementById('totalJumlah');
  const submit = form.querySelector('[type=submit]');
  const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 });
  const tableAmount = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
  const month = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  const billStates = {settled: 'Sudah lunas', exempt: 'Tidak ditagihkan', missing: 'Belum terbit'};
  let request;
  let currentStudent;
  let initialChoice = mode.dataset.choice;
  let pending;
  let lastMode;
  let arrearsSummaryStudent;
  let periodsToPay = [];

  function periodName(period) {
    return period ? month.format(new Date(period + '-01T00:00:00Z')) : '—';
  }

  function switchToMonthly(period, refreshSummary = false) {
    if (editId || !period || mode.value === 'monthly') return;
    const monthly = form.elements.bulan_bayar;
    const year = form.elements.tahun_bayar;
    mode.value = 'monthly';
    synchronize();
    monthly.value = period.bulan;
    year.value = period.tahun;
    form.dataset.arrearsPeriod = period.tahun+'-'+period.bulan;
    monthly.dispatchEvent(new Event('change', {bubbles:true}));
    window.syncSppDropdowns?.();
    const shouldRefresh = refreshSummary && arrearsSummaryStudent !== nis.value;
    arrearsSummaryStudent = nis.value;
    if (shouldRefresh) requestSppStatus({summary:true,showSummary:true,sourceElement:monthly});
  }

  function resetResult(message) {
    version.value = '';
    periodsToPay = [];
    if (mode.value !== 'monthly') {
      submit.disabled = true;
      total.textContent = '—';
    }
    status.textContent = message;
    status.removeAttribute('data-blocked');
    count.hidden = true;
    table.hidden = true;
    tbody.replaceChildren();
  }

  function show(result) {
    tbody.replaceChildren();
    for (const row of result.rows) {
      const label = document.createElement('th');
      label.scope = 'row';
      label.textContent = row.label;
      const line = tbody.insertRow();
      line.append(label);
      for (const cell of [row.spp, row.komite]) {
        const column = line.insertCell();
        const state = billStates[cell.state];
        if (state) {
          const badge = document.createElement('span');
          badge.className = 'payment-range-badge';
          badge.dataset.billState = cell.state;
          badge.textContent = state;
          column.append(badge);
        } else {
          column.textContent = tableAmount.format(cell.remaining / 100);
        }
      }
      const paid = line.insertCell();
      const rowAmount = row.spp.remaining === null || row.komite.remaining === null
        ? null : row.spp.remaining + row.komite.remaining;
      paid.className = rowAmount > 0 ? 'payment-range-amount-due' : 'payment-range-amount-muted';
      paid.textContent = rowAmount === null ? '—' : tableAmount.format(rowAmount / 100);
    }
    table.hidden = !result.rows.length;
    periodsToPay = result.rows.filter(row => row.spp.remaining > 0 || row.komite.remaining > 0)
      .map(row => row.period);
    count.hidden = Boolean(result.blocking) || !periodsToPay.length;
    count.textContent = periodsToPay.length + ' BULAN AKAN DIBAYAR';
    version.value = result.version;
    submit.disabled = !result.version;
    total.textContent = result.blocking ? '—' : money.format(result.total / 100);
    const range = result.range;
    const description = range ? periodName(range.mulai) + (range.mulai === range.akhir ? '' : ' – ' + periodName(range.akhir)) : '';
    status.textContent = result.blocking || description;
    status.toggleAttribute('data-blocked', Boolean(result.blocking));
  }

  async function load(selected = '') {
    request?.abort();
    resetResult(nis.value ? 'Memuat rincian tagihan…' : 'Pilih siswa untuk melihat periode tagihan.');
    if (mode.value === 'monthly' || !nis.value) return;
    const controller = new AbortController();
    request = controller;
    const params = new URLSearchParams({
      unit_id: mode.dataset.unit, no_induk: nis.value, mode: mode.value, choice: selected, edit_id: editId
    });
    try {
      const response = await fetch('pratinjau_periode.php?' + params, {signal: controller.signal, cache: 'no-store'});
      const result = await response.json();
      if (controller.signal.aborted) return;
      if (!response.ok) throw new Error(result.error || 'Rincian gagal dimuat.');
      if (result.monthly_period && !editId) {
        switchToMonthly(result.monthly_period,true);
        return;
      }
      if (selected) {
        show(result);
        return;
      }
      const options = [new Option(mode.value === 'range' ? 'Pilih bulan akhir' : 'Pilih tahun ajaran', '')];
      controls.querySelector('[data-payment-period-oldest]').textContent = periodName(result.oldest);
      if (mode.value === 'academic') {
        for (const year of result.years) options.push(new Option(year, year));
      } else if (result.oldest && result.last) {
        const first = new Date(result.oldest + '-01T00:00:00Z');
        for (; first.toISOString().slice(0, 7) <= result.last; first.setUTCMonth(first.getUTCMonth() + 1)) {
          const period = first.toISOString().slice(0, 7);
          options.push(new Option(periodName(period), period));
        }
      }
      choice.replaceChildren(...options);
      choice.disabled = options.length === 1;
      status.textContent = choice.disabled ? 'Belum ada tagihan SPP atau Komite yang bisa dibayar pada pilihan ini.' : options[0].text;
      if (initialChoice && options.some(option => option.value === initialChoice)) {
        choice.value = initialChoice;
        initialChoice = '';
        load(choice.value);
      } else {
        initialChoice = '';
      }
    } catch (error) {
      if (controller.signal.aborted) return;
      resetResult(error.message);
      status.setAttribute('data-blocked', '');
    }
  }

  function synchronize() {
    const active = mode.value !== 'monthly';
    const changedMode = lastMode !== mode.value;
    if (!changedMode && nis.value === currentStudent) return;
    delete form.dataset.arrearsPeriod;
    if (nis.value !== currentStudent) arrearsSummaryStudent = null;
    lastMode = mode.value;
    currentStudent = nis.value;
    form.classList.toggle('is-period-payment', active);
    controls.hidden = preview.hidden = !active;
    controls.querySelector('[data-payment-period-start]').hidden = mode.value !== 'range';
    controls.querySelector('[data-payment-period-choice-label]').textContent = mode.value === 'range' ? 'Bulan Akhir' : 'Tahun Ajaran';
    form.querySelectorAll('[data-payment-period-monthly]').forEach(section => { section.hidden = section.disabled = active; });
    if (!active) {
      request?.abort();
      version.value = '';
      choice.disabled = true;
      submit.disabled = false;
      if (typeof updateTotal === 'function') updateTotal();
      return;
    }
    choice.replaceChildren(new Option(nis.value ? 'Memuat…' : 'Pilih siswa dahulu', ''));
    choice.disabled = true;
    controls.querySelector('[data-payment-period-oldest]').textContent = '—';
    load();
  }

  mode.addEventListener('change', synchronize);
  choice.addEventListener('change', () => {
    if (choice.value) load(choice.value);
    else {
      request?.abort();
      resetResult('Pilih periode tagihan.');
    }
  });
  form.addEventListener('payment:student-changed', synchronize);
  form.addEventListener('payment:monthly-required', event => switchToMonthly(event.detail));
  nis.addEventListener('change', synchronize);
  form.addEventListener('reset', () => setTimeout(synchronize));
  form.addEventListener('submit', event => {
    if (mode.value === 'monthly') return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (pending || !version.value) return;
    if (!editId && !validatePaymentMethod(form)) return;
    if (!form.reportValidity()) return;
    const expected = version.value;
    const cashDate = date.value;
    const save = () => {
      if (pending || version.value !== expected || date.value !== cashDate) return;
      pending = true;
      submit.disabled = true;
      HTMLFormElement.prototype.submit.call(form);
    };
    const latest = periodsToPay.at(-1);
    if (!editId && latest && latest > cashDate.slice(0,7)) {
      showSppWarning({code:'future_period',severity:'warning',title:'Periksa bulan tagihan',
        message:'Pembayaran ini mencakup tagihan mendatang sampai '+periodName(latest)+'. Pastikan ini memang pembayaran lebih awal.',
        amount_label:'Total bayar '+total.textContent,action_label:'Tetap Simpan',target:choice.id},choice,true,save);
      return;
    }
    save();
  }, true);
  synchronize();
});
