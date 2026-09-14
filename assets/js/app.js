// ============================================
// SistemSPP - app.js
function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
// ============================================

/* ── Theme Init (run ASAP to avoid flash) ─── */
(function () {
  const saved = localStorage.getItem('spp_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', saved);
})();

/* ── Theme Toggle ────────────────────────── */
function toggleTheme() {
  const html    = document.documentElement;
  const current = html.getAttribute('data-theme') || 'dark';
  const next    = current === 'dark' ? 'light' : 'dark';

  html.setAttribute('data-theme', next);
  localStorage.setItem('spp_theme', next);
  updateThemeUI(next);
}

function updateThemeUI(theme) {
  const isDark = (theme !== 'light');

  // Sidebar toggle button label
  const lblEl  = document.getElementById('theme-label');
  const iconEl = document.getElementById('theme-icon');
  if (lblEl)  lblEl.textContent  = isDark ? 'Mode Gelap'  : 'Mode Terang';
  if (iconEl) iconEl.textContent = isDark ? '🌙'           : '☀️';

  // Login page floating button
  const loginBtn   = document.getElementById('login-theme-btn');
  const loginLabel = document.getElementById('login-theme-label');
  const loginIcon  = document.getElementById('login-theme-icon');
  if (loginBtn)   loginBtn.setAttribute('title', isDark ? 'Aktifkan Mode Terang' : 'Aktifkan Mode Gelap');
  if (loginLabel) loginLabel.textContent = isDark ? 'Mode Terang' : 'Mode Gelap';
  if (loginIcon)  loginIcon.textContent  = isDark ? '☀️' : '🌙';
}

// Apply UI labels once DOM is ready
document.addEventListener('DOMContentLoaded', function () {
  const saved = localStorage.getItem('spp_theme') || 'dark';
  updateThemeUI(saved);
});

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.clickable-payment-row[data-edit-url]').forEach(row => {
    const openEdit = () => {
      const url = row.dataset.editUrl;
      if (url) window.location.href = url;
    };

    row.addEventListener('click', function (event) {
      if (event.target.closest('a, button, input, select, textarea, form')) return;
      openEdit();
    });

    row.addEventListener('keydown', function (event) {
      if (!['Enter', ' '].includes(event.key)) return;
      if (event.target.closest('a, button, input, select, textarea, form')) return;
      event.preventDefault();
      openEdit();
    });
  });

  const classHistoryToggles = Array.from(document.querySelectorAll('.student-class-history-toggle[aria-controls]'));
  const classHistoryMobile = window.matchMedia('(max-width: 900px)');
  const syncClassHistoryHeight = detail => {
    if (!detail) return;
    detail.style.removeProperty('height');
    if (detail.hidden || !classHistoryMobile.matches) return;
    const panel = detail.querySelector('.student-class-history-panel');
    if (panel) detail.style.height = Math.ceil(panel.getBoundingClientRect().height) + 'px';
  };
  classHistoryToggles.forEach(button => {
    const detail = document.getElementById(button.getAttribute('aria-controls'));
    const panel = detail?.querySelector('.student-class-history-panel');
    if (panel && 'ResizeObserver' in window) {
      new ResizeObserver(() => syncClassHistoryHeight(detail)).observe(panel);
    }
    button.addEventListener('click', event => {
      event.stopPropagation();
      if (!detail) return;
      const opening = detail.hidden;
      if (opening) {
        classHistoryToggles.forEach(otherButton => {
          if (otherButton === button) return;
          const otherDetail = document.getElementById(otherButton.getAttribute('aria-controls'));
          if (otherDetail) {
            otherDetail.hidden = true;
            syncClassHistoryHeight(otherDetail);
          }
          otherButton.setAttribute('aria-expanded', 'false');
          otherButton.setAttribute('aria-label', 'Buka Riwayat Kelas ' + (otherButton.dataset.studentName || 'siswa'));
        });
      }
      detail.hidden = !opening;
      syncClassHistoryHeight(detail);
      button.setAttribute('aria-expanded', String(opening));
      button.setAttribute('aria-label', (opening ? 'Tutup' : 'Buka') + ' Riwayat Kelas ' + (button.dataset.studentName || 'siswa'));
    });
  });
  window.addEventListener('resize', () => {
    classHistoryToggles.forEach(button => syncClassHistoryHeight(document.getElementById(button.getAttribute('aria-controls'))));
  });
});

/* ── Live Clock ──────────────────────────── */
function updateClock() {
  const el = document.getElementById('liveClock');
  if (!el) return;
  const now = new Date();
  el.textContent = now.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
setInterval(updateClock, 1000);
updateClock();

/* ── Sidebar Toggle ──────────────────────── */
function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  const mc = document.querySelector('.main-content');
  if (!sb) return;
  if (window.innerWidth <= 768) {
    sb.classList.toggle('open');
  } else {
    sb.classList.toggle('collapsed');
    mc && mc.classList.toggle('expanded');
  }
}

/* ── Toggle Password Visibility ───────────── */
function togglePw() {
  const pw = document.getElementById('password');
  if (!pw) return;
  pw.type = pw.type === 'password' ? 'text' : 'password';
}

/* ── Tab Switching ───────────────────────── */
function switchTab(name) {
  document.querySelectorAll('.tab').forEach(t => {
    t.classList.remove('active');
    t.setAttribute('aria-selected', 'false');
  });
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));

  const tabEl = document.getElementById('tab-' + name);
  const panelEl = document.getElementById('panel-' + name);
  if (tabEl)   { tabEl.classList.add('active'); tabEl.setAttribute('aria-selected', 'true'); }
  if (panelEl) panelEl.classList.add('active');

  if (name === 'lihat') renderLihatTable();
}

/* ── Pilih Siswa (auto-fill NIS & Kelas) ─── */
function pilihSiswa(sel) {
  const opt = sel.options[sel.selectedIndex];
  const nisEl   = document.getElementById('disp-nis');
  const kelasEl = document.getElementById('disp-kelas');
  if (nisEl)   nisEl.value   = opt.dataset.nis   || '';
  if (kelasEl) kelasEl.value = opt.dataset.kelas  || '';
}

/* ── Pilih Siswa Datalist (Search & Auto-fill) ── */
function pilihSiswaDatalist(input) {
  const val = input.value.trim();
  const list = document.getElementById('siswa-list');
  if (!list) return;
  const options = list.options;
  
  if (val === '') {
    document.getElementById('disp-nis').value = '';
    document.getElementById('disp-nama').value = '';
    document.getElementById('disp-kelas').value = '';
    clearPaymentDetails();
    refreshPaymentHistory('');
    resetSppStatusState();
    return;
  }
  
  let found = false;
  for (let i = 0; i < options.length; i++) {
    const opt = options[i];
    const nis = opt.dataset.nis;
    const nama = opt.dataset.nama;
    
    // Match if exact match or if user is backspacing but NIS is still at the beginning
    if (val === opt.value || val === nis || val === nama || val.startsWith(nis)) {
      document.getElementById('disp-nis').value = nis || '';
      document.getElementById('disp-nama').value = nama || '';
      document.getElementById('disp-kelas').value = opt.dataset.kelas || '';
      applyDefaultDaftarUlangClass(opt);
      refreshBiayaLainOptions();
      refreshBiayaLainBillsFromServer(nis || '');
      applyStudentPaymentDetails(opt);
      refreshPaymentHistory(nis || '');
      scheduleSppStatusCheck(sppStatusUiReady);
      found = true;
      break;
    }
  }
  
  if (!found) {
    document.getElementById('disp-nis').value = '';
    document.getElementById('disp-nama').value = '';
    document.getElementById('disp-kelas').value = '';
    clearPaymentDetails();
    refreshPaymentHistory('');
    resetSppStatusState();
  }
}

function refreshPaymentHistory(noInduk) {
  const body = document.getElementById('payment-history-body');
  const period = document.getElementById('payment-history-period');
  const url = window.sppPaymentHistoryUrl || '';
  if (!body || !url) return;
  if (!noInduk) {
    body.innerHTML = '<tr><td colspan="6">Belum ada siswa dipilih.</td></tr>';
    if (period) period.textContent = 'Pilih siswa untuk melihat transaksi.';
    return;
  }
  body.innerHTML = '<tr><td colspan="6">Memuat history transaksi.</td></tr>';
  fetch(url + '?no_induk=' + encodeURIComponent(noInduk), { headers: { 'Accept': 'application/json' } })
    .then(response => response.json())
    .then(payload => {
      if (!payload.ok) throw new Error(payload.message || 'Gagal memuat history.');
      if (period) period.textContent = payload.period || '';
      const rows = Array.isArray(payload.rows) ? payload.rows : [];
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="6">Belum ada transaksi pembayaran untuk siswa ini.</td></tr>';
        return;
      }
      body.innerHTML = rows.map(row => '<tr>' +
        '<td>' + escapeHtml(row.tanggal || '') + '</td>' +
        '<td>' + escapeHtml(row.periode || '') + '</td>' +
        '<td>' + escapeHtml(row.komponen || '') + '</td>' +
        '<td>' + escapeHtml(row.metode || '') + '</td>' +
        '<td>' + escapeHtml(row.operator || '') + '</td>' +
        '<td><strong>' + escapeHtml(row.total || 'Rp 0') + '</strong></td>' +
      '</tr>').join('');
    })
    .catch(error => {
      body.innerHTML = '<tr><td colspan="6">' + escapeHtml(error.message || 'Gagal memuat history.') + '</td></tr>';
    });
}

document.addEventListener('DOMContentLoaded', function () {
  const noInduk = document.getElementById('disp-nis')?.value || '';
  if (noInduk) refreshPaymentHistory(noInduk);
});

function studentSearchText(opt) {
  return [
    opt.value || '',
    opt.dataset.nis || '',
    opt.dataset.diknas || '',
    opt.dataset.nama || '',
    opt.dataset.kelas || ''
  ].join(' ').toLowerCase();
}

function studentSearchOptionLabel(opt) {
  const nis = opt.dataset.nis || '';
  const nama = opt.dataset.nama || opt.textContent.trim() || opt.value || '';
  return nama || opt.value || nis;
}

function studentSearchClassFilter(input) {
  const selector = input?.dataset.studentClassFilter || '';
  if (!selector) return { value: '', label: '', type: 'all' };
  const select = document.querySelector(selector);
  if (!select) return { value: '', label: '', type: 'all' };
  const value = String(select.value || '');
  const selected = select.options?.[select.selectedIndex];
  let type = 'rombel';
  let id = value;
  let level = '';
  if (!value || value === '0') {
    type = 'all';
    id = '';
  } else if (value.startsWith('tingkat:')) {
    type = 'tingkat';
    level = value.slice(8);
    id = level;
  } else if (value.startsWith('rombel:')) {
    id = value.slice(7);
  }
  return {
    value: type === 'all' ? '' : id,
    type,
    level,
    label: selected ? selected.textContent.trim() : ''
  };
}

function studentSearchOptionsForClass(input, options) {
  const filter = studentSearchClassFilter(input);
  if (!filter.value) return options;
  if (filter.type === 'tingkat') {
    return options.filter(opt => {
      const level = String(opt.dataset.tingkat || '').match(/[1-6]/)?.[0]
        || String(opt.dataset.kelas || '').match(/[1-6]/)?.[0]
        || '';
      return level === filter.level;
    });
  }
  return options.filter(opt => String(opt.dataset.kelasId || '') === filter.value);
}

function selectStudentSearchOption(input, opt) {
  input.value = opt.value || studentSearchOptionLabel(opt);
  input.dataset.studentSelected = '1';
  input.dataset.studentSuppressPanel = '1';
  try {
    const callbackName = input.dataset.studentSelectCallback || '';
    if (callbackName && typeof window[callbackName] === 'function') {
      window[callbackName](input, opt);
    } else if (typeof window.pilihSiswaDatalist === 'function') {
      window.pilihSiswaDatalist(input);
    }
  } finally {
    closeStudentSearchPanel(input);
  }
}

function closeStudentSearchPanel(input) {
  const box = input?.closest('.student-combobox');
  const panel = box?.querySelector('.student-search-panel');
  if (!panel) return;
  panel.hidden = true;
  panel.innerHTML = '';
  input.setAttribute('aria-expanded', 'false');
}

function renderStudentSearchPanel(input, forceAll) {
  const listId = input.dataset.studentList || input.getAttribute('list') || 'siswa-list';
  const list = document.getElementById(listId);
  const box = input.closest('.student-combobox');
  const panel = box?.querySelector('.student-search-panel');
  if (!list || !box || !panel) return;
  if (input.dataset.studentSuppressPanel === '1') return;

  const query = input.value.trim().toLowerCase();
  const allOptions = Array.from(list.options);
  const classFilter = studentSearchClassFilter(input);
  const scopedOptions = studentSearchOptionsForClass(input, allOptions);
  const showAll = !!forceAll || !query || input.dataset.studentSelected === '1';

  const matches = (!showAll
    ? scopedOptions.filter(opt => studentSearchText(opt).includes(query))
    : scopedOptions
  ).slice(0, showAll ? 12 : 8);

  input.setAttribute('aria-expanded', 'true');
  panel.hidden = false;
  panel.innerHTML = '';

  if (matches.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'student-search-empty';
    empty.textContent = query ? 'Siswa tidak ditemukan' : 'Belum ada data siswa aktif';
    panel.appendChild(empty);
    return;
  }

  if (showAll) {
    const hint = document.createElement('div');
    hint.className = 'student-search-hint';
    if (classFilter.value) {
      hint.textContent = scopedOptions.length > matches.length
        ? 'Menampilkan ' + matches.length + ' siswa pertama dari ' + classFilter.label + '. Ketik nama atau NIS untuk mencari lebih spesifik.'
        : 'Menampilkan siswa dari ' + classFilter.label + '.';
    } else {
      hint.textContent = allOptions.length > matches.length
        ? 'Menampilkan ' + matches.length + ' siswa pertama. Ketik nama atau NIS untuk mencari lebih spesifik.'
        : 'Pilih siswa dari daftar.';
    }
    panel.appendChild(hint);
  }

  matches.forEach((opt, index) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'student-search-option';
    button.dataset.index = String(index);
    const main = document.createElement('span');
    main.className = 'student-search-main';
    const name = document.createElement('strong');
    name.textContent = opt.dataset.nama || opt.textContent.trim() || opt.value || '-';
    const nis = document.createElement('small');
    nis.textContent = 'NIS ' + (opt.dataset.nis || '-')
      + (opt.dataset.diknas ? ' · NIS Diknas ' + opt.dataset.diknas : '');
    const classBadge = document.createElement('span');
    classBadge.className = 'student-search-class';
    const classText = opt.dataset.kelas || '-';
    classBadge.textContent = /^kelas\s/i.test(classText) ? classText : 'Kelas ' + classText;
    main.appendChild(name);
    main.appendChild(nis);
    button.appendChild(main);
    button.appendChild(classBadge);
    button.addEventListener('mousedown', function (event) {
      event.preventDefault();
      selectStudentSearchOption(input, opt);
    });
    panel.appendChild(button);
  });
}

function initStudentSearchCombobox() {
  const inputs = Array.from(document.querySelectorAll('input[data-student-search], #siswa-search'));
  inputs.forEach(initStudentSearchInput);
}

function initStudentSearchInput(input) {
  const listId = input.dataset.studentList || input.getAttribute('list') || 'siswa-list';
  const list = document.getElementById(listId);
  if (!input || !list || input.dataset.studentComboboxReady === '1') return;

  const box = input.closest('.search-box');
  if (!box) return;

  input.dataset.studentComboboxReady = '1';
  input.dataset.studentList = list.id;
  input.removeAttribute('list');
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');

  box.classList.add('student-combobox');
  const panel = document.createElement('div');
  panel.className = 'student-search-panel';
  panel.hidden = true;
  box.appendChild(panel);

  input.addEventListener('input', function () {
    delete input.dataset.studentSelected;
    delete input.dataset.studentSuppressPanel;
    syncStudentSearchQueryTarget(input, input.value);
    renderStudentSearchPanel(input);
  });
  input.addEventListener('focus', function () {
    renderStudentSearchPanel(input, input.value.trim() === '');
  });
  input.addEventListener('click', function () {
    delete input.dataset.studentSuppressPanel;
    renderStudentSearchPanel(input, true);
  });
  const classFilterSelector = input.dataset.studentClassFilter || '';
  const classFilter = classFilterSelector ? document.querySelector(classFilterSelector) : null;
  if (classFilter) {
    classFilter.addEventListener('change', function () {
      const current = input.value.trim();
      const options = Array.from(list.options);
      const selected = options.find(opt => current === opt.value || current === studentSearchOptionLabel(opt));
      const selectedStillValid = !selected || studentSearchOptionsForClass(input, [selected]).length > 0;
      if (!selectedStillValid) {
        input.value = '';
        delete input.dataset.studentSelected;
        delete input.dataset.studentSuppressPanel;
        syncStudentSearchQueryTarget(input, '');
      }
      if (document.activeElement === input) {
        delete input.dataset.studentSuppressPanel;
        renderStudentSearchPanel(input, true);
      }
    });
  }
  input.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeStudentSearchPanel(input);
      return;
    }
    if (event.key !== 'Enter') return;
    const first = panel.querySelector('.student-search-option');
    if (!first || panel.hidden) return;
    event.preventDefault();
    first.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
  });
  document.addEventListener('mousedown', function (event) {
    if (!box.contains(event.target)) closeStudentSearchPanel(input);
  });
}

function syncStudentSearchQueryTarget(input, value) {
  const targetId = input.dataset.studentQueryTarget || '';
  if (!targetId) return;
  const target = document.getElementById(targetId);
  if (!target) return;
  const listId = input.dataset.studentList || input.getAttribute('list') || '';
  const list = listId ? document.getElementById(listId) : null;
  const typed = value || '';
  const exact = list
    ? Array.from(list.options).find(opt => typed === opt.value || typed === studentSearchOptionLabel(opt))
    : null;
  target.value = exact
    ? (exact.dataset.nis || exact.dataset.diknas || typed)
    : (input.dataset.studentQueryExact === '1' ? '' : typed);
}

function selectReportStudentSearchOption(input, opt) {
  input.value = studentSearchOptionLabel(opt);
  syncStudentSearchQueryTarget(input, opt.dataset.nis || opt.dataset.diknas || input.value);
}

function applyDefaultDaftarUlangClass(opt) {
  const hidden = document.getElementById('tagihan-daftar-ulang-id');
  const editingLinkedId = parseInt(window.sppEditingDuBillId || 0, 10) || 0;
  if (hidden && editingLinkedId && daftarUlangRecords(opt).some(bill => parseInt(bill.id, 10) === editingLinkedId)) {
    hidden.value = String(editingLinkedId);
    window.sppEditingDuBillId = 0;
  } else if (hidden) {
    hidden.value = '';
  }
  ensureDefaultDaftarUlangSelection(opt);
}

function selectedPaymentPeriod() {
  const bulan = document.getElementById('bulan-bayar')?.value || '';
  const tahun = document.getElementById('tahun-bayar')?.value || '';
  return bulan && tahun ? bulan + '-' + tahun : '';
}

function paymentMonthLabelByCode(month) {
  const labels = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  const index = parseInt(month, 10) - 1;
  return labels[index] || '';
}

function paymentPeriodDisplayLabel(period) {
  if (!period || !period.includes('-')) return '';
  const [month, year] = period.split('-');
  const monthLabel = paymentMonthLabelByCode(month);
  return monthLabel && year ? monthLabel + ' ' + year : period;
}

function priorSppPeriodsInAcademicYear() {
  const month = parseInt(document.getElementById('bulan-bayar')?.value || '0', 10);
  const year = parseInt(document.getElementById('tahun-bayar')?.value || '0', 10);
  if (!month || !year) return [];

  const periods = [];
  if (month >= 7) {
    for (let m = 7; m < month; m++) {
      periods.push(String(m).padStart(2, '0') + '-' + year);
    }
    return periods;
  }

  for (let m = 7; m <= 12; m++) {
    periods.push(String(m).padStart(2, '0') + '-' + (year - 1));
  }
  for (let m = 1; m < month; m++) {
    periods.push(String(m).padStart(2, '0') + '-' + year);
  }
  return periods;
}

function followingSppPeriodsInAcademicYear() {
  const month = parseInt(document.getElementById('bulan-bayar')?.value || '0', 10);
  const year = parseInt(document.getElementById('tahun-bayar')?.value || '0', 10);
  if (!month || !year) return [];

  const periods = [];
  if (month >= 7) {
    for (let m = month + 1; m <= 12; m++) {
      periods.push(String(m).padStart(2, '0') + '-' + year);
    }
    for (let m = 1; m <= 6; m++) {
      periods.push(String(m).padStart(2, '0') + '-' + (year + 1));
    }
    return periods;
  }

  for (let m = month + 1; m <= 6; m++) {
    periods.push(String(m).padStart(2, '0') + '-' + year);
  }
  return periods;
}

function activeSppPlacementPeriods(opt) {
  if (!opt) return [];
  let placements = [];
  try {
    placements = JSON.parse(opt.dataset.sppPlacements || '[]');
  } catch (_) {
    placements = [];
  }

  const periods = [];
  placements.forEach(placement => {
    const match = String(placement?.tahun_ajaran || '').match(/^(\d{4})\/(\d{4})$/);
    if (!match || Number(match[2]) !== Number(match[1]) + 1) return;
    const startYear = Number(match[1]);
    const tariff = parseNumber(placement?.tarif || 0);
    [[startYear, 7, 12], [startYear + 1, 1, 6]].forEach(([year, firstMonth, lastMonth]) => {
      for (let month = firstMonth; month <= lastMonth; month++) {
        const code = String(month).padStart(2, '0');
        periods.push({
          period: code + '-' + year,
          year,
          month,
          tariff,
          order: (year * 12) + month,
          label: paymentMonthLabelByCode(code) + ' ' + year
        });
      }
    });
  });

  return periods.sort((left, right) => left.order - right.order);
}

function selectedSppTariff(opt) {
  const selected = selectedPaymentPeriod();
  const record = activeSppPlacementPeriods(opt).find(period => period.period === selected);
  return record ? record.tariff : datasetNumber(opt, 'total', 'spp');
}

function firstUnpaidPriorSppPeriod(opt, monthlyBill) {
  if (!opt || monthlyBill <= 0) return null;
  let periods = {};
  try {
    periods = JSON.parse(opt.dataset.paidSppPeriods || '{}');
  } catch (_) {
    periods = {};
  }

  const selectedOrder = (() => {
    const [month, year] = selectedPaymentPeriod().split('-').map(Number);
    return month && year ? (year * 12) + month : 0;
  })();
  for (const period of activeSppPlacementPeriods(opt)) {
    if (period.order >= selectedOrder || period.tariff <= 0.001) continue;
    const paid = parseNumber(periods[period.period] || 0);
    if (paid + 0.001 < period.tariff) {
      return {
        period: period.period,
        paid,
        remaining: Math.max(0, period.tariff - paid),
        label: period.label
      };
    }
  }
  return null;
}

function firstPaidFollowingSppPeriod(opt) {
  if (!opt) return null;
  let periods = {};
  try {
    periods = JSON.parse(opt.dataset.paidSppPeriods || '{}');
  } catch (_) {
    periods = {};
  }

  const [month, year] = selectedPaymentPeriod().split('-').map(Number);
  const selectedOrder = month && year ? (year * 12) + month : 0;
  for (const period of activeSppPlacementPeriods(opt)) {
    if (period.order <= selectedOrder) continue;
    const paid = parseNumber(periods[period.period] || 0);
    if (paid > 0.001) {
      return { period: period.period, paid, label: period.label };
    }
  }
  return null;
}

function isAnnualPaymentPlan() {
  return document.getElementById('payment-plan')?.value === 'annual';
}

function annualPaidForYear(opt, key) {
  if (!opt) return 0;
  const year = document.getElementById('tahun-bayar')?.value || '';
  try {
    const datasetKey = key === 'komite' ? 'paidKomitePeriods' : 'paidSppPeriods';
    const periods = JSON.parse(opt.dataset[datasetKey] || '{}');
    return Object.entries(periods).reduce((sum, [period, amount]) => {
      return period.endsWith('-' + year) ? sum + parseNumber(amount) : sum;
    }, 0);
  } catch (_) {
    return 0;
  }
}

function annualSppConflictLabels(opt) {
  if (!opt) return [];
  const year = document.getElementById('tahun-bayar')?.value || '';
  const monthNames = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  try {
    const periods = JSON.parse(opt.dataset.paidSppPeriods || '{}');
    return Object.entries(periods)
      .filter(([period, amount]) => period.endsWith('-' + year) && parseNumber(amount) > 0)
      .map(([period]) => monthNames[Math.max(0, parseInt(period.slice(0, 2), 10) - 1)] + ' ' + year);
  } catch (_) {
    return [];
  }
}

function academicYearFromPaymentPeriod() {
  const month = parseNumber(document.getElementById('bulan-bayar')?.value || 0);
  const year = parseNumber(document.getElementById('tahun-bayar')?.value || 0);
  if (!month || !year) return '';
  const start = month >= 7 ? year : year - 1;
  return start + '/' + (start + 1);
}

function academicYearPeriodKeys() {
  const academicYear = academicYearFromPaymentPeriod();
  const [startYear, endYear] = academicYear.split('/').map(Number);
  if (!startYear || !endYear) return [];
  const periods = [];
  for (let month = 7; month <= 12; month++) {
    periods.push(String(month).padStart(2, '0') + '-' + startYear);
  }
  for (let month = 1; month <= 6; month++) {
    periods.push(String(month).padStart(2, '0') + '-' + endYear);
  }
  return periods;
}

function paidForAcademicYear(opt, key) {
  if (!opt) return 0;
  try {
    const periods = JSON.parse(opt.dataset.paidSppPeriods || '{}');
    return academicYearPeriodKeys().reduce((sum, period) => sum + parseNumber(periods[period] || 0), 0);
  } catch (_) {
    return 0;
  }
}

function selectedAnnualFeeRecord(opt, key) {
  if (!opt) return null;
  const year = academicYearFromPaymentPeriod();
  if (!year) return null;
  try {
    const fees = JSON.parse(opt.dataset.annualFees || '{}');
    return fees?.[key]?.[year] || null;
  } catch (_) {
    return null;
  }
}

function hasAnnualFeeDataset(opt) {
  return !!opt?.dataset?.annualFees && opt.dataset.annualFees !== '{}';
}

function totalAnnualFeeForContext(opt, key) {
  const record = selectedAnnualFeeRecord(opt, key);
  return record ? parseNumber(record.total || 0) : datasetNumber(opt, 'total', key);
}

function paidAnnualFeeForContext(opt, key) {
  const record = selectedAnnualFeeRecord(opt, key);
  if (!record && hasAnnualFeeDataset(opt)) return 0;
  return record ? parseNumber(record.paid || 0) : datasetNumber(opt, 'paid', key);
}

function selectedPaymentMonthLabel() {
  const select = document.getElementById('bulan-bayar');
  const option = select?.options[select.selectedIndex];
  if (option?.dataset.label) return option.dataset.label;
  const month = parseNumber(select?.value || 0);
  return paymentMonthLabelByCode(month);
}

function refreshSppPeriodLabel() {
  const label = document.getElementById('spp-component-label');
  if (!label) return;
  if (window.sppPublishedBilling) { label.textContent = '🎓 Uang SPP Diterima'; return; }
  const monthLabel = selectedPaymentMonthLabel();
  label.textContent = '🎓 Uang SPP' + (monthLabel ? ' (' + monthLabel + ')' : '');
}

function setDaftarUlangContext(kelas, tahunAjaran) {
  const kelasInput = document.getElementById('kelas-du') || document.querySelector('[name="kelas_du"]');
  const tahunInput = document.getElementById('tahun-ajaran-du') || document.querySelector('[name="tahun_ajaran_du"]');
  if (kelasInput) kelasInput.value = kelas || '';
  if (tahunInput) tahunInput.value = tahunAjaran || '';
}

function syncDaftarUlangContextFromCurrentState() {
  const opt = selectedStudentOption();
  ensureDefaultDaftarUlangSelection(opt);
  const bill = selectedDaftarUlangBill(opt);
  setDaftarUlangContext(bill?.kelas || '', bill?.tahun_ajaran || '');
  return opt;
}

function datasetNumber(opt, prefix, key) {
  if (!opt) return 0;
  const name = prefix + key.charAt(0).toUpperCase() + key.slice(1);
  return parseNumber(opt.dataset[name] || 0);
}

function selectedStudentOption() {
  const input = document.getElementById('siswa-search');
  const list = document.getElementById('siswa-list');
  const nis = document.getElementById('disp-nis')?.value || '';
  if (!input || !list || !nis) return null;
  return Array.from(list.options).find(opt => opt.dataset.nis === nis || opt.value === input.value) || null;
}

function paidForPeriod(opt, key) {
  if (!opt) return 0;
  if (key === 'spp' && isAnnualPaymentPlan()) return annualPaidForYear(opt, key);
  try {
    const periods = JSON.parse(opt.dataset.paidSppPeriods || '{}');
    return parseNumber(periods[selectedPaymentPeriod()] || 0);
  } catch (_) {
    return 0;
  }
}

function daftarUlangRecords(opt) {
  if (!opt) return [];
  try {
    const raw = JSON.parse(opt.dataset.duBills || '[]');
    if (Array.isArray(raw)) return raw;
    return Object.entries(raw).map(([year, record]) => ({ tahun_ajaran: year, ...record }));
  } catch (_) {
    return [];
  }
}

function publishedSppData(opt = selectedStudentOption()) {
  if (!window.sppPublishedBilling || !opt) return null;
  try {
    const parsed = JSON.parse(opt.dataset.sppBilling || '{"saldo":0,"tagihan":[]}');
    return { saldo: parseNumber(parsed.saldo || 0), tagihan: Array.isArray(parsed.tagihan) ? parsed.tagihan : [] };
  } catch (_) {
    return { saldo: 0, tagihan: [] };
  }
}

function publishedSppPlan(useDeposit = false) {
  const data = publishedSppData();
  const newMoney = parseNumber(document.getElementById('spp-input')?.value || 0);
  if (!data) return { lines: [], depositUsed: 0, depositCreated: 0, balanceAfter: 0, newMoney };
  let deposit = useDeposit ? data.saldo : 0;
  let cash = newMoney;
  let depositUsed = 0;
  const lines = [];
  for (const bill of data.tagihan) {
    let need = parseNumber(bill.remaining || 0);
    if (cash + deposit + 0.001 < need) break;
    const fromDeposit = Math.min(deposit, need); deposit -= fromDeposit; need -= fromDeposit;
    const fromCash = Math.min(cash, need); cash -= fromCash; need -= fromCash;
    if (need > 0.001) break;
    depositUsed += fromDeposit;
    lines.push({ ...bill, fromDeposit, fromCash });
  }
  return { lines, depositUsed, depositCreated: cash, balanceAfter: data.saldo - depositUsed + cash, newMoney };
}

function refreshPublishedSppUi(opt = selectedStudentOption()) {
  if (!window.sppPublishedBilling) return false;
  const data = publishedSppData(opt) || { saldo: 0, tagihan: [] };
  const total = data.tagihan.reduce((sum, bill) => sum + parseNumber(bill.total || 0), 0);
  const paid = data.tagihan.reduce((sum, bill) => sum + parseNumber(bill.paid || 0), 0);
  setPaymentComponent('spp', total, paid);
  const context = document.getElementById('spp-context-label');
  if (context) context.textContent = data.tagihan.length
    ? data.tagihan.length + ' tagihan terbuka · alokasi dimulai dari ' + paymentPeriodLabel(data.tagihan[0].bulan + '-' + data.tagihan[0].tahun)
    : 'Belum ada tagihan terbuka; seluruh uang SPP baru akan menjadi titipan.';
  const banner = document.getElementById('spp-deposit-banner');
  const balance = document.getElementById('spp-deposit-balance');
  const capacity = document.getElementById('spp-deposit-capacity');
  const button = document.getElementById('spp-use-deposit-button');
  const hidden = document.getElementById('gunakan-titipan-spp');
  const nis = opt?.dataset.nis || '';
  if (hidden && hidden.dataset.nis !== nis) { hidden.value = '0'; hidden.dataset.nis = nis; }
  if (banner) banner.hidden = !opt;
  if (balance) balance.textContent = 'Rp ' + formatRupiah(data.saldo);
  let pool = data.saldo, payable = 0;
  for (const bill of data.tagihan) { const need=parseNumber(bill.remaining||0); if(pool+0.001<need) break; pool-=need; payable++; }
  if (capacity) capacity.textContent = data.saldo > 0 ? 'Dapat melunasi ' + payable + ' tagihan penuh tanpa menambah kas hari ini.' : 'Belum ada saldo titipan SPP.';
  if (button) { button.disabled = data.saldo <= 0 || data.tagihan.length === 0; button.textContent = hidden?.value === '1' ? 'Titipan Dipilih' : 'Gunakan Titipan'; }
  return true;
}

function selectedDaftarUlangKey() {
  return document.getElementById('tagihan-daftar-ulang-id')?.value || '';
}

function selectedDaftarUlangRecord(opt) {
  const selectedId = parseInt(selectedDaftarUlangKey(), 10) || 0;
  return daftarUlangRecords(opt).find(record => parseInt(record.id, 10) === selectedId) || null;
}

function ensureDefaultDaftarUlangSelection(opt) {
  const hidden = document.getElementById('tagihan-daftar-ulang-id');
  if (!hidden || !opt) return null;
  const records = daftarUlangRecords(opt);
  let selected = records.find(record => String(record.id) === hidden.value);
  if (!selected) {
    selected = records.find(record => record.is_current)
      || records.filter(record => record.is_arrear && parseNumber(record.sisa) > .001)
        .sort((a, b) => String(a.tahun_ajaran).localeCompare(String(b.tahun_ajaran)))[0]
      || null;
    hidden.value = selected ? String(selected.id) : '';
  }
  return selected;
}

function selectedDaftarUlangBill(opt) {
  const bill = selectedDaftarUlangRecord(opt);
  return bill && bill.status === 'open' ? bill : null;
}

function totalDaftarUlangForContext(opt) {
  return parseNumber(selectedDaftarUlangBill(opt)?.total || 0);
}

function refreshDaftarUlangSelector(opt) {
  const trigger = document.getElementById('du-selector-trigger');
  const staticLabel = document.getElementById('du-static-label');
  const menu = document.getElementById('du-selector-menu');
  const warningIcon = document.getElementById('du-arrear-warning');
  if (!trigger || !staticLabel || !menu) return;

  const records = daftarUlangRecords(opt);
  const arrears = records.filter(record => record.is_arrear && parseNumber(record.sisa) > .001);
  const canChoose = arrears.length > 0;
  trigger.hidden = !canChoose;
  staticLabel.hidden = canChoose;
  menu.hidden = true;
  trigger.setAttribute('aria-expanded', 'false');
  if (warningIcon) {
    const message = arrears.length + ' tahun ajaran masih memiliki tunggakan Daftar Ulang';
    warningIcon.textContent = arrears.length ? '!' : '';
    warningIcon.setAttribute('aria-label', message);
    warningIcon.title = message;
  }

  menu.replaceChildren();
  const menuHeader = document.createElement('div');
  menuHeader.className = 'du-selector-menu-header';
  const menuHeading = document.createElement('strong');
  menuHeading.textContent = 'Pilih tagihan Daftar Ulang';
  const menuHint = document.createElement('span');
  menuHint.textContent = arrears.length + ' tunggakan perlu diselesaikan';
  menuHeader.append(menuHeading, menuHint);
  menu.appendChild(menuHeader);

  records.forEach(record => {
    const option = document.createElement('button');
    option.type = 'button';
    option.className = 'du-selector-option';
    option.setAttribute('role', 'option');
    option.setAttribute('aria-selected', String(String(record.id) === selectedDaftarUlangKey()));
    option.dataset.billId = String(record.id);

    const heading = document.createElement('span');
    heading.className = 'du-option-heading';
    const titleWrap = document.createElement('span');
    titleWrap.className = 'du-option-title';
    const title = document.createElement('strong');
    title.textContent = 'TA ' + record.tahun_ajaran;
    const className = document.createElement('span');
    className.textContent = 'Kelas ' + record.kelas;
    titleWrap.append(title, className);
    const badge = document.createElement('span');
    badge.className = 'du-option-badge ' + (record.is_current ? 'is-current' : 'is-arrear');
    badge.textContent = record.is_current ? 'Tahun Berjalan' : 'Tunggakan';
    heading.append(titleWrap, badge);

    const values = document.createElement('span');
    values.className = 'du-option-values';
    [
      ['Tagihan', record.total],
      ['Terbayar', record.terbayar ?? record.paid ?? 0],
      ['Sisa', record.sisa ?? 0]
    ].forEach(([label, value]) => {
      const stat = document.createElement('span');
      const statLabel = document.createElement('small');
      const statValue = document.createElement('strong');
      statLabel.textContent = label;
      statValue.textContent = 'Rp ' + formatRupiah(value);
      stat.append(statLabel, statValue);
      values.appendChild(stat);
    });
    const selectedMark = document.createElement('span');
    selectedMark.className = 'du-option-selected-mark';
    selectedMark.setAttribute('aria-hidden', 'true');
    selectedMark.textContent = '✓';
    option.append(heading, values, selectedMark);
    option.addEventListener('click', () => {
      const hidden = document.getElementById('tagihan-daftar-ulang-id');
      if (hidden) hidden.value = String(record.id);
      const input = document.getElementById('du-input');
      if (input) input.value = '0';
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      setDaftarUlangContext(record.kelas, record.tahun_ajaran);
      applyStudentPaymentDetails(opt);
      trigger.focus();
    });
    menu.appendChild(option);
  });

  if (trigger.dataset.bound !== '1') {
    trigger.dataset.bound = '1';
    trigger.addEventListener('click', () => {
      const opening = menu.hidden;
      menu.hidden = !opening;
      trigger.setAttribute('aria-expanded', String(opening));
      if (opening) menu.querySelector('[aria-selected="true"]')?.focus();
    });
    trigger.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        menu.querySelector('[aria-selected="true"], .du-selector-option')?.focus();
      }
    });
    document.addEventListener('click', event => {
      if (!event.target.closest('.du-bill-selector')) {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
      }
    });
    menu.addEventListener('keydown', event => {
      const options = Array.from(menu.querySelectorAll('.du-selector-option'));
      const index = options.indexOf(document.activeElement);
      if (event.key === 'Escape') {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
      } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        options[(index + step + options.length) % options.length]?.focus();
      }
    });
  }
}

function refreshDaftarUlangMasterWarning(opt) {
  const warning = document.getElementById('du-master-warning');
  const input = document.getElementById('du-input');
  if (!warning && !input) return;

  ensureDefaultDaftarUlangSelection(opt);
  const bill = selectedDaftarUlangBill(opt);
  const periodKey = bill?.tahun_ajaran || '';
  const total = parseNumber(bill?.total || 0);
  const paid = parseNumber(bill?.terbayar ?? bill?.paid ?? 0);
  const remaining = parseNumber(bill?.sisa ?? Math.max(0, total - paid));
  const isSettled = !!bill && total > 0 && remaining <= 0.001;
  const isUnavailable = !!opt && !bill;
  const contextLabel = document.getElementById('du-context-label');
  if (contextLabel) {
    let status = 'Belum Bayar';
    if (isSettled) status = 'Lunas';
    else if (paid > 0) status = 'Cicilan';
    if (!opt || !periodKey) {
      contextLabel.textContent = opt ? 'Belum ada tagihan Daftar Ulang yang dapat dipilih.' : 'Pilih siswa untuk melihat tagihan.';
    } else if (bill) {
      contextLabel.textContent = 'TA ' + periodKey + ' · Kelas ' + bill.kelas + ' · ' + status;
    }
  }

  if (input) {
    const locked = !bill || isSettled;
    const lockedMessage = isSettled
      ? 'Tagihan Daftar Ulang sudah lunas.'
      : 'Tidak ada tagihan Daftar Ulang yang dapat dibayar.';
    input.readOnly = locked;
    input.classList.toggle('tbl-readonly', locked);
    if (locked) {
      input.value = '0';
      input.title = lockedMessage;
      input.setCustomValidity('');
    } else {
      input.setCustomValidity('');
      input.removeAttribute('title');
    }
  }

  if (!warning) return;
  if (!isUnavailable) {
    warning.hidden = true;
    warning.textContent = '';
  } else {
    warning.hidden = false;
    warning.textContent = 'Tagihan Daftar Ulang belum diterbitkan atau tidak memiliki sisa.';
  }
  refreshDaftarUlangSelector(opt);
}

function paidDaftarUlangForContext(opt) {
  const bill = selectedDaftarUlangBill(opt);
  return parseNumber(bill?.terbayar ?? bill?.paid ?? 0);
}

function applyGraduatePaymentLock(opt) {
  const graduateOnly = opt?.dataset.isGraduate === '1';
  ['pangkal', 'psb', 'spp', 'komite'].forEach(key => {
    const input = document.getElementById(key + '-input');
    if (!input) return;
    if (graduateOnly && !(window.sppPublishedBilling && key === 'spp')) {
      input.value = '0';
      input.readOnly = true;
      input.classList.add('tbl-readonly');
      input.dataset.graduateLocked = '1';
      input.title = 'Lulusan hanya dapat melunasi tunggakan Daftar Ulang.';
    } else if (input.dataset.graduateLocked === '1') {
      input.readOnly = false;
      input.classList.remove('tbl-readonly');
      delete input.dataset.graduateLocked;
      input.removeAttribute('title');
    }
  });
  const discount = document.getElementById('potongan-spp');
  if (discount) {
    if (graduateOnly) {
      discount.value = '0';
      discount.readOnly = true;
      discount.dataset.graduateLocked = '1';
    } else if (discount.dataset.graduateLocked === '1') {
      discount.readOnly = false;
      delete discount.dataset.graduateLocked;
    }
  }
  document.querySelectorAll('.biaya-lain-select').forEach(select => {
    if (graduateOnly) {
      select.value = '';
      select.disabled = true;
      select.dataset.graduateLocked = '1';
    } else if (select.dataset.graduateLocked === '1') {
      select.disabled = false;
      delete select.dataset.graduateLocked;
    }
  });
  document.querySelectorAll('.biaya-lain-nominal, .biaya-lain-keterangan').forEach(input => {
    if (graduateOnly) input.value = input.classList.contains('biaya-lain-nominal') ? '0' : '';
    if (graduateOnly) {
      input.readOnly = true;
      input.dataset.graduateLocked = '1';
    } else if (input.dataset.graduateLocked === '1') {
      input.readOnly = false;
      delete input.dataset.graduateLocked;
    }
  });
  document.querySelectorAll('.btn-remove-biaya-lain').forEach(button => {
    if (graduateOnly) { button.disabled = true; button.dataset.graduateLocked = '1'; }
    else if (button.dataset.graduateLocked === '1') { button.disabled = false; delete button.dataset.graduateLocked; }
  });
  const addButton = document.getElementById('btn-add-biaya-lain');
  if (addButton) {
    if (graduateOnly) { addButton.disabled = true; addButton.dataset.graduateLocked = '1'; }
    else if (addButton.dataset.graduateLocked === '1') { delete addButton.dataset.graduateLocked; }
  }
}

function selectedBiayaLainSummary() {
  const selected = new Set();
  let total = 0;
  let paid = 0;
  document.querySelectorAll('.biaya-lain-select').forEach(select => {
    const option = select.options[select.selectedIndex];
    const billId = option?.value || '';
    if (!billId || selected.has(billId)) return;
    selected.add(billId);
    total += parseNumber(option.dataset.nominal || 0);
    paid += paidBiayaLainForSelectedStudent(billId);
  });
  return { total, paid };
}

function refreshAcademicYearSummary() {
  const totalLabel = document.getElementById('academic-total-label');
  const totalValue = document.getElementById('academic-total-value');
  const paidValue = document.getElementById('academic-paid-value');
  const remainingValue = document.getElementById('academic-remaining-value');
  if (!totalLabel || !totalValue || !paidValue || !remainingValue) return;

  const academicYear = academicYearFromPaymentPeriod();
  totalLabel.textContent = 'Total Tagihan TA ' + (academicYear || '-');
  const opt = selectedStudentOption();
  if (!opt) {
    totalValue.textContent = 'Rp 0';
    paidValue.textContent = 'Rp 0';
    remainingValue.textContent = 'Rp 0';
    return;
  }

  const annualKeys = ['komite'];
  let total = annualKeys.reduce((sum, key) => sum + totalAnnualFeeForContext(opt, key), 0);
  let paid = annualKeys.reduce((sum, key) => sum + paidAnnualFeeForContext(opt, key), 0);
  ['pangkal', 'psb'].forEach(key => {
    total += datasetNumber(opt, 'total', key);
    paid += datasetNumber(opt, 'paid', key);
  });

  if (window.sppPublishedBilling) {
    const sppData=publishedSppData(opt);total+=(sppData?.tagihan||[]).reduce((sum,bill)=>sum+parseNumber(bill.total||0),0);paid+=(sppData?.tagihan||[]).reduce((sum,bill)=>sum+parseNumber(bill.paid||0),0);
  } else {
    total += datasetNumber(opt, 'total', 'spp') * 12;
    paid += paidForAcademicYear(opt, 'spp');
  }

  const daftarUlang = selectedDaftarUlangBill(opt);
  total += parseNumber(daftarUlang?.total || 0);
  paid += parseNumber(daftarUlang?.paid || 0);

  const biayaLain = selectedBiayaLainSummary();
  total += biayaLain.total;
  paid += biayaLain.paid;

  totalValue.textContent = 'Rp ' + formatRupiah(total);
  paidValue.textContent = 'Rp ' + formatRupiah(paid);
  remainingValue.textContent = 'Rp ' + formatRupiah(Math.max(0, total - paid));
}

function setPaymentComponent(key, total, paid) {
  const totalEl = document.getElementById(key + '-total');
  const paidEl = document.getElementById(key + '-bayar');
  if (totalEl) totalEl.value = formatRupiahString(total || 0);
  if (paidEl) paidEl.value = formatRupiahString(paid || 0);
  hitungSisa(key);
}

function applyStudentPaymentDetails(opt) {
  if (!opt) return;
  ['pangkal','psb','spp','komite','du'].forEach(key => {
    if (key === 'spp' && window.sppPublishedBilling) return;
    const total = key === 'du'
      ? totalDaftarUlangForContext(opt)
      : (key === 'spp'
          ? selectedSppTariff(opt) * (isAnnualPaymentPlan() ? 12 : 1)
          : (key === 'komite' ? totalAnnualFeeForContext(opt, key) : datasetNumber(opt, 'total', key)));
    const paid = key === 'du'
      ? paidDaftarUlangForContext(opt)
      : (key === 'spp'
          ? paidForPeriod(opt, key)
          : (key === 'komite' ? paidAnnualFeeForContext(opt, key) : datasetNumber(opt, 'paid', key)));
    setPaymentComponent(key, total, paid);
  });
  refreshPublishedSppUi(opt);
  refreshAnnualPaymentState(opt);
  refreshDaftarUlangMasterWarning(opt);
  refreshBiayaLainOptions();
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  updateTotal();
}

function clearPaymentDetails() {
  const duBillId = document.getElementById('tagihan-daftar-ulang-id');
  if (duBillId) duBillId.value = '';
  ['pangkal','psb','spp','komite','du'].forEach(key => {
    ['total','bayar','sisa'].forEach(part => {
      const el = document.getElementById(key + '-' + part);
      if (el) el.value = '0';
    });
  });
  refreshDaftarUlangMasterWarning(null);
  refreshPublishedSppUi(null);
  applyGraduatePaymentLock(null);
  refreshAnnualPaymentState(null);
  refreshBiayaLainOptions();
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  updateTotal();
}

const paymentComponentLabels = {
  pangkal: 'Uang Pangkal',
  psb: 'Uang PSB',
  spp: 'Uang SPP',
  komite: 'Uang Komite',
  du: 'Uang Daftar Ulang'
};

function refreshOptionalOneTimeFeeAvailability() {
  const hasStudent = !!document.getElementById('disp-nis')?.value;
  ['pangkal', 'psb', 'komite'].forEach(key => {
    const total = parseNumber(document.getElementById(key + '-total')?.value || 0);
    const paid = parseNumber(document.getElementById(key + '-bayar')?.value || 0);
    const inputEl = document.getElementById(key + '-input');
    const contextEl = document.getElementById(key + '-context-label');
    if (!inputEl) return;

    let message = '';
    if (!hasStudent) message = 'Pilih siswa terlebih dahulu';
    else if (total <= 0) message = 'Tarif belum diatur di Data Siswa';
    else if (paid + 0.001 >= total) message = 'Lunas';

    const locked = message !== '';
    inputEl.readOnly = locked;
    inputEl.classList.toggle('tbl-readonly', locked);
    if (locked) {
      inputEl.value = '0';
      inputEl.title = message;
      inputEl.setCustomValidity('');
    } else {
      inputEl.removeAttribute('title');
    }
    if (contextEl) {
      contextEl.textContent = locked ? message : (key === 'komite' ? 'Tagihan tahunan bisa dicicil' : 'Tagihan satu kali, dapat dicicil');
    }
    hitungSisa(key);
  });
}

let sppStatusUiReady = false;
let sppStatusTimer = null;
let sppStatusRequestId = 0;
let sppStatusAbortController = null;
let sppWarningRestoreFocus = null;
const sppStatusState = {
  contextKey: '',
  pending: false,
  payload: null,
  lastShownKey: ''
};

function currentSppStatusContext() {
  const noInduk = document.getElementById('disp-nis')?.value.trim() || '';
  const bulan = document.getElementById('bulan-bayar')?.value || '';
  const tahun = document.getElementById('tahun-bayar')?.value.trim() || '';
  if (!noInduk || !/^\d{2}$/.test(bulan) || !/^\d{4}$/.test(tahun)) return null;
  const editId = parseInt(window.sppEditPaymentId || 0, 10) || 0;
  return {
    noInduk,
    bulan,
    tahun,
    editId,
    key: [noInduk, bulan, tahun, editId].join('|')
  };
}

function resetSppStatusState() {
  if (sppStatusTimer) window.clearTimeout(sppStatusTimer);
  sppStatusTimer = null;
  if (sppStatusAbortController) sppStatusAbortController.abort();
  sppStatusAbortController = null;
  sppStatusRequestId += 1;
  sppStatusState.contextKey = '';
  sppStatusState.pending = false;
  sppStatusState.payload = null;
  sppStatusState.lastShownKey = '';
}

function unavailableSppStatus() {
  return {
    ok: false,
    status: 'status_unavailable',
    code: 'status_unavailable',
    lock_spp: true,
    title: 'Status belum tersedia',
    message: 'Status SPP belum dapat diperiksa. Coba lagi.',
    amount_label: ''
  };
}

function closeSppWarning() {
  const overlay = document.getElementById('spp-warning-overlay');
  if (!overlay) return;
  overlay.classList.remove('show');
  overlay.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('spp-warning-open');
  const focusTarget = sppWarningRestoreFocus;
  sppWarningRestoreFocus = null;
  if (focusTarget && typeof focusTarget.focus === 'function') focusTarget.focus();
}

function showSppWarning(status, sourceElement = null, force = false) {
  const overlay = document.getElementById('spp-warning-overlay');
  const title = document.getElementById('spp-warning-title');
  const message = document.getElementById('spp-warning-message');
  const amount = document.getElementById('spp-warning-amount');
  const retry = document.getElementById('spp-warning-retry');
  const close = document.getElementById('spp-warning-close');
  if (!overlay || !title || !message || !amount || !retry || !close || !status) return;

  const context = currentSppStatusContext();
  const warningKey = (context?.key || 'flash') + '|' + (status.code || 'blocked');
  if (!force && sppStatusState.lastShownKey === warningKey) return;
  sppStatusState.lastShownKey = warningKey;
  sppWarningRestoreFocus = sourceElement || document.activeElement;

  title.textContent = status.title || 'SPP belum bisa dibayar';
  message.textContent = status.message || 'Pembayaran SPP belum dapat diproses.';
  amount.textContent = status.amount_label || '';
  amount.hidden = !status.amount_label;
  retry.hidden = status.code !== 'status_unavailable';
  overlay.dataset.statusCode = status.code || 'blocked';
  overlay.classList.add('show');
  overlay.setAttribute('aria-hidden', 'false');
  document.body.classList.add('spp-warning-open');
  window.setTimeout(() => (retry.hidden ? close : retry).focus(), 0);
}

function sppEditDependencyBlocks(dependency, proposedAmount = null) {
  if (!dependency?.original) return false;
  const context = currentSppStatusContext();
  if (!context) return false;
  const original = dependency.original;
  const contextMoved = context.noInduk !== String(original.no_induk || '')
    || context.bulan !== String(original.bulan || '')
    || context.tahun !== String(original.tahun || '');
  if (contextMoved) return true;
  if (proposedAmount === null) return false;
  return proposedAmount + 0.001 < parseNumber(original.tariff || 0);
}

function applySppStatusPayload(payload) {
  const selected = payload?.selected;
  if (selected && Number.isFinite(Number(selected.tariff)) && Number.isFinite(Number(selected.paid))) {
    setPaymentComponent('spp', Number(selected.tariff), Number(selected.paid));
  }
  updateTotal();
}

async function requestSppStatus(options = {}) {
  const context = currentSppStatusContext();
  const interactive = options.interactive === true;
  const force = options.force === true;
  const sourceElement = options.sourceElement || document.activeElement;
  const urlValue = window.sppPaymentStatusUrl || '';
  if (!context || !urlValue) {
    resetSppStatusState();
    refreshSppInstallmentAvailability();
    return null;
  }

  if (!force && sppStatusState.contextKey === context.key && sppStatusState.payload && !sppStatusState.pending) {
    const cached = sppStatusState.payload;
    if (interactive && sppEditDependencyBlocks(cached.edit_dependency)) {
      showSppWarning(cached.edit_dependency, sourceElement);
    } else if (interactive && cached.status !== 'payable') {
      showSppWarning(cached, sourceElement);
    }
    return cached;
  }

  if (sppStatusAbortController) sppStatusAbortController.abort();
  sppStatusAbortController = new AbortController();
  const requestId = ++sppStatusRequestId;
  if (sppStatusState.contextKey !== context.key) sppStatusState.lastShownKey = '';
  sppStatusState.contextKey = context.key;
  sppStatusState.pending = true;
  sppStatusState.payload = null;
  refreshSppInstallmentAvailability();

  try {
    const url = new URL(urlValue, window.location.href);
    url.searchParams.set('no_induk', context.noInduk);
    url.searchParams.set('bulan', context.bulan);
    url.searchParams.set('tahun', context.tahun);
    if (context.editId > 0) url.searchParams.set('edit_id', String(context.editId));
    const response = await fetch(url.toString(), {
      headers: { 'Accept': 'application/json' },
      cache: 'no-store',
      signal: sppStatusAbortController.signal
    });
    const payload = await response.json().catch(() => null);
    if (requestId !== sppStatusRequestId) return null;
    if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'Status SPP belum dapat diperiksa.');

    sppStatusState.pending = false;
    sppStatusState.payload = payload;
    applySppStatusPayload(payload);
    if (interactive && sppEditDependencyBlocks(payload.edit_dependency)) {
      showSppWarning(payload.edit_dependency, sourceElement, force);
    } else if (interactive && payload.status !== 'payable') {
      showSppWarning(payload, sourceElement, force);
    }
    return payload;
  } catch (error) {
    if (error?.name === 'AbortError' || requestId !== sppStatusRequestId) return null;
    const payload = unavailableSppStatus();
    sppStatusState.pending = false;
    sppStatusState.payload = payload;
    refreshSppInstallmentAvailability();
    if (interactive) showSppWarning(payload, sourceElement, force);
    return payload;
  }
}

function scheduleSppStatusCheck(interactive = true, sourceElement = null) {
  if (window.sppPublishedBilling) { refreshSppInstallmentAvailability(); return; }
  if (sppStatusTimer) window.clearTimeout(sppStatusTimer);
  const context = currentSppStatusContext();
  if (!context) {
    resetSppStatusState();
    refreshSppInstallmentAvailability();
    return;
  }
  sppStatusTimer = window.setTimeout(() => {
    sppStatusTimer = null;
    requestSppStatus({ interactive, sourceElement });
  }, 220);
}

function refreshSppInstallmentAvailability() {
  const input = document.getElementById('spp-input');
  if (!input) return;
  if (window.sppPublishedBilling) {
    const opt = selectedStudentOption();
    input.readOnly = !opt;
    input.classList.toggle('tbl-readonly', !opt);
    input.setCustomValidity('');
    if (!opt) { input.value = '0'; input.title = 'Pilih siswa terlebih dahulu'; }
    else input.removeAttribute('title');
    refreshPublishedSppUi(opt);
    return;
  }
  const opt = selectedStudentOption();
  const total = parseNumber(document.getElementById('spp-total')?.value || 0);
  const paid = parseNumber(document.getElementById('spp-bayar')?.value || 0);
  const remaining = Math.max(0, total - paid);
  const context = document.getElementById('spp-context-label');
  const monthLabel = selectedPaymentMonthLabel();
  const year = document.getElementById('tahun-bayar')?.value || '';

  const contextKey = currentSppStatusContext()?.key || '';
  const hasLiveContext = contextKey && sppStatusState.contextKey === contextKey;
  const liveStatus = hasLiveContext ? sppStatusState.payload : null;
  let lockedMessage = '';
  let locked = false;

  if (hasLiveContext && sppStatusState.pending) {
    locked = true;
    lockedMessage = 'Memeriksa status SPP…';
  } else if (liveStatus) {
    locked = liveStatus.lock_spp === true;
    lockedMessage = locked ? (liveStatus.message || 'Pembayaran SPP belum dapat diproses.') : '';
    if (lockedMessage && liveStatus.amount_label) lockedMessage += ' ' + liveStatus.amount_label + '.';
  } else if (!opt) {
    locked = true;
    lockedMessage = 'Pilih siswa terlebih dahulu';
  } else if (!contextKey) {
    locked = true;
    lockedMessage = 'Lengkapi bulan dan tahun pembayaran';
  } else if (total <= 0) {
    locked = true;
    lockedMessage = 'Tarif SPP belum diatur';
  } else if (remaining <= 0.001) {
    locked = true;
    lockedMessage = 'Lunas untuk ' + monthLabel + ' ' + year;
  } else {
    const unpaidPrior = firstUnpaidPriorSppPeriod(opt, total);
    if (unpaidPrior) {
      locked = true;
      lockedMessage = 'SPP ' + monthLabel + ' ' + year + ' belum bisa dibayar karena ' + unpaidPrior.label + ' belum lunas';
      if (unpaidPrior.remaining > 0) lockedMessage += ' (sisa Rp ' + formatRupiah(unpaidPrior.remaining) + ')';
    }
  }

  const inputValue = parseNumber(input.value || 0);
  input.readOnly = locked;
  input.classList.toggle('tbl-readonly', locked);
  if (locked) {
    if (liveStatus?.lock_spp && !window.sppEditPaymentId) input.value = '0';
    input.title = lockedMessage;
    input.setCustomValidity('');
  } else {
    input.removeAttribute('title');
    if (inputValue > 0.001 && Math.abs(inputValue - total) > 0.001) {
      input.setCustomValidity('SPP ' + monthLabel + ' ' + year + ' wajib dibayar penuh sebesar Rp ' + formatRupiah(total) + '.');
    } else {
      input.setCustomValidity('');
    }
  }

  if (context) {
    if (lockedMessage) context.textContent = lockedMessage;
    else context.textContent = 'SPP ' + monthLabel + ' ' + year + ' wajib dibayar penuh Rp ' + formatRupiah(total);
  }
  hitungSisa('spp');
}

function refreshOverpaidWarnings() {
  const alertEl = document.getElementById('payment-overpaid-alert');
  const overpaid = [];

  Object.keys(paymentComponentLabels).forEach(key => {
    const totalEl = document.getElementById(key + '-total');
    const paidEl = document.getElementById(key + '-bayar');
    const inputEl = document.getElementById(key + '-input');
    const row = totalEl?.closest('tr');
    if (!totalEl || !paidEl || !inputEl) return;

    const total = parseNumber(totalEl.value || 0);
    const paid = parseNumber(paidEl.value || 0);
    const isOverpaid = total > 0 && paid > total;

    row?.classList.toggle('row-overpaid', isOverpaid);
    if (isOverpaid) {
      inputEl.value = '0';
      inputEl.disabled = true;
      inputEl.title = 'Input dikunci karena pembayaran sebelumnya sudah melebihi total tagihan.';
      overpaid.push(paymentComponentLabels[key] + ' (total Rp ' + formatRupiah(total) + ', sudah terbayar Rp ' + formatRupiah(paid) + ')');
    } else {
      inputEl.disabled = false;
      if (!inputEl.readOnly) inputEl.removeAttribute('title');
    }
  });

  if (!alertEl) return overpaid;
  if (overpaid.length === 0) {
    alertEl.hidden = true;
    alertEl.textContent = '';
    return overpaid;
  }

  alertEl.hidden = false;
  alertEl.textContent = 'Perhatian: ada pembayaran yang sudah melebihi total tagihan, yaitu ' + overpaid.join('; ') + '. Sisa pembayaran dianggap Rp 0 dan input komponen tersebut dikunci. Silakan cek ulang data pembayaran sebelumnya.';
  return overpaid;
}

function refreshPaymentInputOverlimitWarnings() {
  const alertEl = document.getElementById('payment-input-overlimit-alert');
  const warnings = [];

  Object.keys(paymentComponentLabels).forEach(key => {
    if (window.sppPublishedBilling && key === 'spp') return;
    const totalEl = document.getElementById(key + '-total');
    const paidEl = document.getElementById(key + '-bayar');
    const inputEl = document.getElementById(key + '-input');
    const row = inputEl?.closest('tr');
    if (!totalEl || !paidEl || !inputEl) return;

    if (inputEl.disabled) {
      row?.classList.remove('row-input-overlimit');
      inputEl.classList.remove('is-input-overlimit');
      inputEl.setCustomValidity('');
      return;
    }

    const total = parseNumber(totalEl.value || 0);
    const paid = parseNumber(paidEl.value || 0);
    const input = parseNumber(inputEl.value || 0);
    const remainingBeforeInput = Math.max(0, total - paid);
    const isTooMuch = input > remainingBeforeInput + 0.001;
    const isInvalidFullSpp = key === 'spp' && input > 0.001 && Math.abs(input - total) > 0.001;

    row?.classList.toggle('row-input-overlimit', isTooMuch || isInvalidFullSpp);
    inputEl.classList.toggle('is-input-overlimit', isTooMuch || isInvalidFullSpp);

    if (isTooMuch || isInvalidFullSpp) {
      const message = isInvalidFullSpp
        ? 'Uang SPP wajib dibayar penuh sebesar Rp ' + formatRupiah(total) + '.'
        : paymentComponentLabels[key] + ' melebihi sisa tagihan. Sisa Rp ' + formatRupiah(remainingBeforeInput) + ', input Rp ' + formatRupiah(input) + '.';
      inputEl.setCustomValidity(message);
      inputEl.title = message;
      warnings.push(message);
    } else {
      inputEl.setCustomValidity('');
      if (!inputEl.readOnly) inputEl.removeAttribute('title');
    }
  });

  if (!alertEl) return warnings;
  if (warnings.length === 0) {
    alertEl.hidden = true;
    alertEl.textContent = '';
    return warnings;
  }

  alertEl.hidden = false;
  alertEl.textContent = 'Perhatian: input pembayaran melebihi sisa tagihan. ' + warnings.join(' ');
  return warnings;
}

function clearOverpaidUiState() {
  ['payment-overpaid-alert', 'payment-input-overlimit-alert', 'biaya-lain-overpaid-alert'].forEach(id => {
    const alertEl = document.getElementById(id);
    if (!alertEl) return;
    alertEl.hidden = true;
    alertEl.textContent = '';
  });

  document.querySelectorAll('.row-overpaid').forEach(row => row.classList.remove('row-overpaid'));
  document.querySelectorAll('.row-input-overlimit').forEach(row => row.classList.remove('row-input-overlimit'));
  Object.keys(paymentComponentLabels).forEach(key => {
    const inputEl = document.getElementById(key + '-input');
    if (!inputEl) return;
    inputEl.disabled = false;
    inputEl.removeAttribute('title');
    inputEl.setCustomValidity('');
    inputEl.classList.remove('is-input-overlimit');
  });
  document.querySelectorAll('.biaya-lain-nominal').forEach(input => {
    input.removeAttribute('title');
    input.setCustomValidity('');
  });
}

function refreshSelectedStudentPaymentDetails() {
  const opt = syncDaftarUlangContextFromCurrentState();
  if (opt) applyStudentPaymentDetails(opt);
  else {
    refreshAnnualPaymentState(null);
    refreshSppPeriodLabel();
    refreshAcademicYearSummary();
  }
}

function showMonthLabels(select) {
  if (!select) return;
  Array.from(select.options).forEach(opt => {
    if (opt.value && opt.dataset.label) opt.textContent = opt.dataset.label;
  });
}

function showSelectedMonthCode(select) {
  if (!select) return;
  showMonthLabels(select);
  const opt = select.options[select.selectedIndex];
  if (opt && opt.value) opt.textContent = opt.value;
}

// Helper to parse formatted rupiah string back to raw number
function parseNumber(val) {
  if (typeof val === 'number') return val;
  if (!val) return 0;
  const clean = val.toString().replace(/\./g, '');
  return parseFloat(clean) || 0;
}

// Helper to format number to rupiah string with dots
function formatRupiahString(val) {
  if (val === null || val === undefined || val === '') return '0';
  const clean = val.toString().replace(/\D/g, '');
  if (!clean) return '0';
  return parseInt(clean, 10).toLocaleString('id-ID');
}

function formatNumericInput(input) {
  let cursorPosition = input.selectionStart;
  const originalLength = input.value.length;
  const cleanVal = input.value.replace(/\D/g, '');

  input.value = cleanVal === '' ? '0' : parseInt(cleanVal, 10).toLocaleString('id-ID');

  const newLength = input.value.length;
  cursorPosition = cursorPosition + (newLength - originalLength);
  if (input.setSelectionRange) {
    input.setSelectionRange(cursorPosition, cursorPosition);
  }
}

function bindNumericInput(input) {
  if (!input || input.dataset.numericBound === '1') return;
  input.dataset.numericBound = '1';
  if (input.value && input.value !== '0') {
    input.value = formatRupiahString(input.value);
  }
  input.addEventListener('input', function () {
    formatNumericInput(this);

    if (this.id.endsWith('-total') || this.id.endsWith('-bayar') || this.id.endsWith('-input')) {
      const key = this.id.split('-')[0];
      hitungSisa(key);
    }
    if (this.classList.contains('biaya-lain-nominal')) {
      refreshBiayaLainRow(this.closest('.biaya-lain-row'), true);
    }
    updateTotal();
  });
}

function initPromotionBatchSelector() {
  const form = document.getElementById('promotion-batch-form');
  if (!form || form.dataset.promotionReady === '1') return;

  const search = document.getElementById('promotion-batch-search');
  const sourceFilter = document.getElementById('promotion-source-filter');
  const rows = Array.from(form.querySelectorAll('[data-promotion-student]'));
  const emptyState = document.getElementById('promotion-empty-filter');
  const visibleCount = document.getElementById('promotion-visible-count');
  const selectedCount = document.getElementById('promotion-selected-count');
  const submitSummary = document.getElementById('promotion-submit-summary');
  const submitButton = document.getElementById('promotion-submit-button');
  const selectVisible = document.getElementById('promotion-select-visible');
  const selectAll = document.getElementById('promotion-select-all');
  const clearSelection = document.getElementById('promotion-clear-selection');
  const actionLabel = form.dataset.promotionAction || 'Proses';
  form.dataset.promotionReady = '1';

  const normalize = value => (value || '').toLocaleLowerCase('id-ID').trim();
  const checkboxFor = row => row.querySelector('input[type="checkbox"][name="selected_students[]"]');
  const selectedRows = () => rows.filter(row => checkboxFor(row)?.checked);

  const updateSelection = () => {
    const selected = selectedRows();
    rows.forEach(row => {
      const isSelected = !!checkboxFor(row)?.checked;
      const target = row.querySelector('select[name^="target_master_kelas_id"]');
      row.classList.toggle('is-selected', isSelected);
      if (target) target.disabled = !isSelected;
    });
    if (selectedCount) selectedCount.textContent = selected.length + ' siswa dipilih';
    if (submitSummary) {
      submitSummary.textContent = selected.length > 0
        ? selected.length + ' siswa siap diproses'
        : 'Belum ada siswa dipilih';
    }
    if (submitButton) {
      submitButton.disabled = selected.length === 0;
      submitButton.textContent = actionLabel + ' ' + selected.length + ' Siswa';
    }
  };

  const filterRows = () => {
    const query = normalize(search?.value);
    const source = sourceFilter?.value || '';
    let shown = 0;
    rows.forEach(row => {
      const matchesSearch = !query || normalize(row.dataset.search).includes(query);
      const matchesSource = !source || row.dataset.sourceRombel === source;
      const visible = matchesSearch && matchesSource;
      row.hidden = !visible;
      if (visible) shown++;
    });
    if (visibleCount) visibleCount.textContent = shown + ' siswa ditampilkan';
    if (emptyState) emptyState.hidden = shown !== 0;
  };

  search?.addEventListener('input', filterRows);
  sourceFilter?.addEventListener('change', filterRows);
  rows.forEach(row => checkboxFor(row)?.addEventListener('change', updateSelection));
  selectVisible?.addEventListener('click', function () {
    rows.filter(row => !row.hidden).forEach(row => {
      const checkbox = checkboxFor(row);
      if (checkbox) checkbox.checked = true;
    });
    updateSelection();
  });
  selectAll?.addEventListener('click', function () {
    rows.forEach(row => {
      const checkbox = checkboxFor(row);
      if (checkbox) checkbox.checked = true;
    });
    updateSelection();
  });
  clearSelection?.addEventListener('click', function () {
    rows.forEach(row => {
      const checkbox = checkboxFor(row);
      if (checkbox) checkbox.checked = false;
    });
    updateSelection();
  });

  form.addEventListener('submit', function (event) {
    const selected = selectedRows();
    if (selected.length === 0) {
      event.preventDefault();
      return;
    }
    const missingTargets = selected.filter(row => {
      const target = row.querySelector('select[name^="target_master_kelas_id"]');
      return target && !target.value;
    }).length;
    let message = actionLabel + ' ' + selected.length + ' siswa untuk tahun ajaran yang dituju?';
    if (missingTargets > 0) {
      message += '\n\n' + missingTargets + ' siswa belum memiliki rombel tujuan dan akan dilewati.';
    }
    if (!window.confirm(message)) {
      event.preventDefault();
      return;
    }
    if (submitButton) submitButton.disabled = true;
  });

  filterRows();
  updateSelection();
}

// Auto-fill on page load (edit page) & bind number formatting
document.addEventListener('DOMContentLoaded', function () {
  initStudentSearchCombobox();
  initPromotionBatchSelector();

  const sel = document.getElementById('siswa-select');
  if (sel && sel.value) pilihSiswa(sel);
  const siswaSearch = document.getElementById('siswa-search');
  if (siswaSearch && siswaSearch.value.trim() !== '') {
    pilihSiswaDatalist(siswaSearch);
  }

  // Set today's date if empty
  const tgl = document.getElementById('tgl-bayar');
  if (tgl && !tgl.value) {
    tgl.value = new Date().toISOString().split('T')[0];
  }

  document.querySelectorAll('#bulan-bayar, #tahun-bayar').forEach(el => {
    const handlePeriodChange = () => {
      refreshSelectedStudentPaymentDetails();
      scheduleSppStatusCheck(sppStatusUiReady, el);
    };
    el.addEventListener('change', handlePeriodChange);
    if (el.id === 'tahun-bayar') {
      el.addEventListener('input', handlePeriodChange);
    }
  });

  document.querySelectorAll('.payment-year-picker').forEach(picker => {
    const input = picker.querySelector('.payment-year-select');
    const options = Array.from(picker.querySelectorAll('.payment-year-option'));
    if (!input || !options.length) return;

    const syncActiveYear = () => {
      let activeOption = null;
      options.forEach(option => {
        const isActive = option.dataset.year === input.value.trim();
        option.classList.toggle('is-active', isActive);
        if (isActive) activeOption = option;
      });
      return activeOption;
    };
    const openPicker = () => {
      const activeOption = syncActiveYear();
      picker.classList.add('is-open');
      requestAnimationFrame(() => {
        (activeOption || options[0]).scrollIntoView({ block: 'nearest' });
      });
    };
    const closePicker = () => picker.classList.remove('is-open');

    input.addEventListener('focus', openPicker);
    input.addEventListener('click', openPicker);
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(0, 4);
      syncActiveYear();
    });
    input.addEventListener('keydown', event => {
      if (event.key === 'Escape') closePicker();
    });
    options.forEach(option => {
      option.addEventListener('click', () => {
        input.value = option.dataset.year || option.textContent.trim();
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closePicker();
        input.focus();
      });
    });
    document.addEventListener('click', event => {
      if (!picker.contains(event.target)) closePicker();
    });
    syncActiveYear();
  });

  const paymentPlan = document.getElementById('payment-plan');
  if (paymentPlan) {
    paymentPlan.addEventListener('change', refreshSelectedStudentPaymentDetails);
    refreshAnnualPaymentState(selectedStudentOption());
  }
  const paymentDate = document.getElementById('tgl-bayar');
  if (paymentDate) {
    paymentDate.addEventListener('change', function () {
      const selectedYear = this.value.slice(0, 4);
      const yearInput = document.getElementById('tahun-bayar');
      if (yearInput && selectedYear) {
        if ('options' in yearInput) {
          if (Array.from(yearInput.options).some(option => option.value === selectedYear || option.textContent.trim() === selectedYear)) {
            yearInput.value = selectedYear;
          }
        } else {
          yearInput.value = selectedYear;
        }
      }
      refreshSelectedStudentPaymentDetails();
      scheduleSppStatusCheck(sppStatusUiReady, this);
    });
  }

  document.querySelectorAll('.month-code-select').forEach(select => {
    showSelectedMonthCode(select);
    select.addEventListener('pointerdown', () => showMonthLabels(select));
    select.addEventListener('focus', () => showMonthLabels(select));
    select.addEventListener('keydown', () => showMonthLabels(select));
    select.addEventListener('change', () => {
      window.setTimeout(() => showSelectedMonthCode(select), 0);
    });
    select.addEventListener('blur', () => showSelectedMonthCode(select));
  });

  // Format all numeric fields dynamically
  const numericInputs = document.querySelectorAll(
    '.tbl-input, #potongan-spp, #kewajiban-spp, .biaya-lain-nominal'
  );
  
  numericInputs.forEach(input => {
    bindNumericInput(input);
  });

  const sppInput = document.getElementById('spp-input');
  if (sppInput) {
    sppInput.addEventListener('blur', function () {
      if (window.sppPublishedBilling) return;
      if (this.readOnly) return;
      const amount = parseNumber(this.value || 0);
      if (amount <= 0.001) return;
      const tariff = parseNumber(document.getElementById('spp-total')?.value || 0);
      if (tariff > 0.001 && Math.abs(amount - tariff) > 0.001) {
        const context = currentSppStatusContext();
        showSppWarning({
          code: 'amount_mismatch',
          title: 'Nominal SPP belum sesuai',
          message: 'SPP ' + (context ? paymentPeriodLabel(context.bulan + '-' + context.tahun) : '') + ' harus dibayar penuh Rp ' + formatRupiah(tariff) + '.',
          amount_label: 'Tagihan Rp ' + formatRupiah(tariff)
        }, this, true);
        return;
      }
      const dependency = sppStatusState.payload?.edit_dependency;
      if (sppEditDependencyBlocks(dependency, amount)) showSppWarning(dependency, this, true);
    });
  }

  const depositModal = document.getElementById('spp-deposit-modal');
  const closeDepositModal = () => { if (depositModal) depositModal.hidden = true; };
  document.getElementById('spp-use-deposit-button')?.addEventListener('click', function () {
    const hidden = document.getElementById('gunakan-titipan-spp');
    if (hidden?.value === '1') {
      hidden.value = '0';
      refreshPublishedSppUi();
      return;
    }
    const plan = publishedSppPlan(true);
    const summary = document.getElementById('spp-deposit-modal-summary');
    const lines = document.getElementById('spp-deposit-modal-lines');
    if (summary) summary.textContent = plan.lines.length
      ? 'Titipan Rp ' + formatRupiah(plan.depositUsed) + ' akan dipakai. Saldo akhir Rp ' + formatRupiah(plan.balanceAfter) + '.'
      : 'Saldo belum cukup untuk melunasi satu tagihan penuh. Tambahkan uang SPP baru agar dapat digabungkan.';
    if (lines) lines.innerHTML = plan.lines.length ? plan.lines.map(line =>
      '<article><div><strong>' + escapeHtml(paymentPeriodLabel(line.bulan + '-' + line.tahun)) + '</strong><small>TA ' + escapeHtml(line.tahun_ajaran || '-') + ' · ' + escapeHtml(line.kelas || '-') + '</small></div><span>Rp ' + formatRupiah(line.remaining) + '</span></article>'
    ).join('') : '<div class="spp-deposit-empty">Belum ada tagihan yang dapat dilunasi penuh.</div>';
    if (depositModal) depositModal.hidden = false;
  });
  document.getElementById('spp-deposit-cancel')?.addEventListener('click', closeDepositModal);
  document.getElementById('spp-deposit-confirm')?.addEventListener('click', function () {
    const plan = publishedSppPlan(true);
    if (!plan.lines.length) return;
    const hidden = document.getElementById('gunakan-titipan-spp');
    if (hidden) hidden.value = '1';
    closeDepositModal();
    refreshPublishedSppUi();
  });
  depositModal?.addEventListener('click', event => { if (event.target === depositModal) closeDepositModal(); });

  const warningClose = document.getElementById('spp-warning-close');
  const warningRetry = document.getElementById('spp-warning-retry');
  warningClose?.addEventListener('click', closeSppWarning);
  warningRetry?.addEventListener('click', function () {
    closeSppWarning();
    requestSppStatus({ interactive: true, force: true, sourceElement: document.getElementById('spp-input') });
  });
  document.addEventListener('keydown', function (event) {
    const overlay = document.getElementById('spp-warning-overlay');
    if (!overlay?.classList.contains('show')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeSppWarning();
      return;
    }
    if (event.key === 'Tab') {
      const actions = Array.from(overlay.querySelectorAll('button:not([hidden]):not([disabled])'));
      if (!actions.length) return;
      const first = actions[0];
      const last = actions[actions.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  // Clean all formatted numeric inputs right before form submission so the backend gets raw numbers
  const form = document.getElementById('form-bayar');
  if (form) {
    const normalizePaymentInputs = () => {
      document.querySelectorAll('.tbl-input, #potongan-spp, #kewajiban-spp, .biaya-lain-nominal').forEach(input => {
        input.value = input.value.replace(/\./g, '');
      });
    };
    form.addEventListener('submit', async function (event) {
      if (window.sppPublishedBilling) {
        normalizePaymentInputs();
        return;
      }
      if (form.dataset.sppSubmitting === '1') {
        normalizePaymentInputs();
        return;
      }

      const amount = parseNumber(document.getElementById('spp-input')?.value || 0);
      const editOriginal = window.sppEditOriginal || null;
      const context = currentSppStatusContext();
      const originalChanged = !!editOriginal && !!context && (
        context.noInduk !== String(editOriginal.no_induk || '')
        || context.bulan !== String(editOriginal.bulan || '')
        || context.tahun !== String(editOriginal.tahun || '')
        || Math.abs(amount - parseNumber(editOriginal.amount || 0)) > 0.001
      );
      const needsFinalSppCheck = amount > 0.001 || originalChanged;
      if (!needsFinalSppCheck) {
        normalizePaymentInputs();
        return;
      }

      event.preventDefault();
      const status = await requestSppStatus({ interactive: false, force: true, sourceElement: document.getElementById('spp-input') });
      if (!status || status.code === 'status_unavailable') {
        showSppWarning(status || unavailableSppStatus(), document.getElementById('spp-input'), true);
        return;
      }
      if (amount > 0.001 && status.status !== 'payable') {
        showSppWarning(status, document.getElementById('spp-input'), true);
        return;
      }

      const tariff = parseNumber(status.selected?.tariff || document.getElementById('spp-total')?.value || 0);
      if (amount > 0.001 && Math.abs(amount - tariff) > 0.001) {
        showSppWarning({
          code: 'amount_mismatch',
          title: 'Nominal SPP belum sesuai',
          message: 'SPP ' + (status.selected?.label || '') + ' harus dibayar penuh Rp ' + formatRupiah(tariff) + '.',
          amount_label: 'Tagihan Rp ' + formatRupiah(tariff)
        }, document.getElementById('spp-input'), true);
        return;
      }

      if (sppEditDependencyBlocks(status.edit_dependency, amount)) {
        showSppWarning(status.edit_dependency, document.getElementById('spp-input'), true);
        return;
      }

      form.dataset.sppSubmitting = '1';
      normalizePaymentInputs();
      form.submit();
    });
    form.addEventListener('reset', function () {
      window.setTimeout(resetForm, 0);
    });
  }

  updateTotal();
  autoHideFlash();
  sppStatusUiReady = true;
  if (window.sppFlashWarning) {
    window.setTimeout(() => showSppWarning(window.sppFlashWarning, document.getElementById('spp-input'), true), 80);
  }
});

function renumberBiayaLainRows() {
  document.querySelectorAll('#biaya-lain-list .biaya-lain-row').forEach((row, index) => {
    const number = row.querySelector('.ll-num');
    if (number) number.textContent = index + 1;
  });
}

function biayaLainBillsForSelectedStudent() {
  const opt = selectedStudentOption();
  if (!opt) return [];
  try {
    const bills = JSON.parse(opt.dataset.biayaLainBills || '[]');
    return Array.isArray(bills) ? bills : [];
  } catch (_) {
    return [];
  }
}

function paidBiayaLainForSelectedStudent(billId) {
  if (!billId) return 0;
  const bill = biayaLainBillsForSelectedStudent().find(item => String(item.id) === String(billId));
  if (bill) return parseNumber(bill.paid || bill.terbayar || 0);
  const selected = Array.from(document.querySelectorAll('.biaya-lain-select option'))
    .find(option => option.value === String(billId));
  return parseNumber(selected?.dataset.paid || 0);
}

function refreshBiayaLainOptions() {
  const bills = biayaLainBillsForSelectedStudent();
  document.querySelectorAll('.biaya-lain-select').forEach(select => {
    const current = select.value;
    const currentOption = select.options[select.selectedIndex];
    const legacyPlaceholder = Array.from(select.options).find(option => option.dataset.legacy === '1');
    const legacy = current && !bills.some(bill => String(bill.id) === String(current))
      ? {
          id: current,
          master_id: currentOption?.dataset.masterId || '',
          nama: currentOption?.dataset.baseLabel || currentOption?.textContent || 'Biaya Lain (histori)',
          nominal: currentOption?.dataset.nominal || 0,
          paid: currentOption?.dataset.paid || 0
        }
      : null;
    const available = legacy ? [...bills, legacy] : bills;
    select.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = legacyPlaceholder?.textContent || (selectedStudentOption()
      ? (bills.length ? 'Pilih tagihan' : 'Belum ada tagihan. Publish dari Master Biaya Lain')
      : 'Pilih siswa dulu');
    if (legacyPlaceholder) {
      placeholder.dataset.legacy = '1';
      placeholder.dataset.nominal = legacyPlaceholder.dataset.nominal || '0';
    }
    select.appendChild(placeholder);
    available.forEach(bill => {
      const option = document.createElement('option');
      option.value = String(bill.id);
      option.dataset.masterId = String(bill.master_id || bill.master_biaya_lain_id || '');
      option.dataset.nominal = String(bill.nominal || bill.nominal_tagihan || 0);
      option.dataset.paid = String(bill.paid || bill.terbayar || 0);
      option.dataset.baseLabel = String(bill.nama || bill.nama_snapshot || 'Biaya Lain');
      option.textContent = option.dataset.baseLabel;
      select.appendChild(option);
    });
    if (current && Array.from(select.options).some(option => option.value === current)) select.value = current;
  });
  refreshBiayaLainAvailability();
}

let biayaLainBillsRequestId = 0;
function refreshBiayaLainBillsFromServer(noInduk) {
  const url = window.sppOtherFeeBillsUrl || '';
  if (!url || !noInduk) return;
  const requestId = ++biayaLainBillsRequestId;
  fetch(url + '?no_induk=' + encodeURIComponent(noInduk), {
    headers: { 'Accept': 'application/json' },
    cache: 'no-store'
  })
    .then(response => response.json())
    .then(payload => {
      if (requestId !== biayaLainBillsRequestId) return;
      if (!payload.ok) throw new Error(payload.message || 'Gagal memuat tagihan biaya lain.');
      const opt = selectedStudentOption();
      if (!opt || String(opt.dataset.nis || '') !== String(noInduk)) return;
      opt.dataset.biayaLainBills = JSON.stringify(Array.isArray(payload.rows) ? payload.rows : []);
      refreshBiayaLainOptions();
      document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
      applyGraduatePaymentLock(opt);
    })
    .catch(error => {
      if (requestId !== biayaLainBillsRequestId) return;
      const alertEl = document.getElementById('biaya-lain-overpaid-alert');
      if (alertEl) {
        alertEl.hidden = false;
        alertEl.textContent = error.message || 'Gagal memuat tagihan biaya lain terbaru.';
      }
    });
}

function refreshAnnualPaymentState(opt) {
  const plan = document.getElementById('payment-plan');
  const month = document.getElementById('bulan-bayar');
  const label = document.getElementById('payment-period-label');
  const hint = document.getElementById('annual-payment-hint');
  const submitLabel = document.getElementById('payment-submit-label');
  const sppInput = document.getElementById('spp-input');
  const annual = isAnnualPaymentPlan();
  document.getElementById('form-bayar')?.classList.toggle('is-annual-payment', annual);
  if (month) month.setAttribute('aria-hidden', annual ? 'true' : 'false');
  if (label) label.textContent = annual ? 'Tahun Pembayaran (Januari–Desember)' : 'Pembayaran Bulan';
  if (hint) hint.hidden = !annual;
  if (submitLabel) submitLabel.textContent = annual ? 'Simpan & Buat 12 Struk' : 'Simpan';
  if (!sppInput) return;

  const conflicts = annual ? annualSppConflictLabels(opt) : [];
  if (annual && conflicts.length > 0) {
    const message = 'Pembayaran tahunan tidak dapat dibuat karena SPP ' + conflicts.join(', ') + ' sudah dibayar.';
    sppInput.value = '0';
    sppInput.readOnly = true;
    if (plan) {
      plan.setCustomValidity(message);
      plan.title = message;
    }
    if (hint) {
      hint.textContent = message;
      hint.classList.add('is-error');
    }
  } else {
    sppInput.readOnly = annual;
    if (plan) {
      plan.setCustomValidity('');
      plan.removeAttribute('title');
    }
    if (hint) {
      hint.textContent = 'SPP Januari–Desember dibagi otomatis menjadi 12 transaksi dan 12 halaman struk.';
      hint.classList.remove('is-error');
    }
    if (annual && opt) sppInput.value = formatRupiahString(datasetNumber(opt, 'total', 'spp') * 12);
  }
}

function refreshBiayaLainAvailability() {
  const rows = Array.from(document.querySelectorAll('#biaya-lain-list .biaya-lain-row'));
  const addButton = document.getElementById('btn-add-biaya-lain');

  rows.forEach(row => {
    const select = row.querySelector('.biaya-lain-select');
    if (!select) return;
    const selectedId = select.value;

    Array.from(select.options).forEach(option => {
      if (!option.value) return;
      const baseLabel = option.dataset.baseLabel || option.textContent.replace(/\s+\((Lunas|Sudah dipilih)\)$/u, '');
      option.dataset.baseLabel = baseLabel;
      const fullyPaid = paidBiayaLainForSelectedStudent(option.value) >= parseNumber(option.dataset.nominal || 0) - 0.001;
      const usedByOtherRow = rows.some(otherRow => otherRow !== row
        && otherRow.querySelector('.biaya-lain-select')?.value === option.value);
      option.disabled = option.value !== selectedId && (fullyPaid || usedByOtherRow);
      option.textContent = baseLabel + (fullyPaid ? ' (Lunas)' : (usedByOtherRow ? ' (Sudah dipilih)' : ''));
    });

    const selectedOption = select.options[select.selectedIndex];
    const total = parseNumber(selectedOption?.dataset.nominal || 0);
    const paid = selectedId ? paidBiayaLainForSelectedStudent(selectedId) : 0;
    const duplicated = !!selectedId && rows.some(otherRow => otherRow !== row
      && otherRow.querySelector('.biaya-lain-select')?.value === selectedId);
    let message = '';
    if (selectedId && paid >= total - 0.001) {
      message = (selectedOption?.dataset.baseLabel || 'Biaya ini') + ' sudah lunas dan tidak dapat ditambahkan lagi.';
    } else if (duplicated) {
      message = (selectedOption?.dataset.baseLabel || 'Biaya ini') + ' hanya boleh dipilih satu kali dalam satu transaksi.';
    }
    select.setCustomValidity(message);
    if (message) select.title = message;
    else select.removeAttribute('title');
    row.classList.toggle('row-unavailable', !!message);
  });

  if (!addButton) return;
  const referenceSelect = rows[0]?.querySelector('.biaya-lain-select');
  const hasAvailableMaster = referenceSelect && Array.from(referenceSelect.options).some(option => {
    if (!option.value) return false;
    const fullyPaid = paidBiayaLainForSelectedStudent(option.value) >= parseNumber(option.dataset.nominal || 0) - 0.001;
    const alreadyUsed = rows.some(row => row.querySelector('.biaya-lain-select')?.value === option.value);
    return !fullyPaid && !alreadyUsed;
  });
  addButton.disabled = !hasAvailableMaster;
  addButton.title = hasAvailableMaster ? '' : 'Semua biaya tersedia sudah lunas atau sudah dipilih.';
}

function refreshBiayaLainRow(row, preserveInput) {
  const select = row?.querySelector('.biaya-lain-select');
  const totalEl = row?.querySelector('.biaya-lain-total');
  const paidEl = row?.querySelector('.biaya-lain-paid');
  const sisaEl = row?.querySelector('.biaya-lain-sisa');
  const nominal = row?.querySelector('.biaya-lain-nominal');
  if (!select || !nominal) return;

  const option = select.options[select.selectedIndex];
  const billId = option?.value || '';
  const masterTotal = parseNumber(option?.dataset.nominal || 0);
  const alreadyPaid = billId ? paidBiayaLainForSelectedStudent(billId) : 0;
  const remainingBeforeInput = Math.max(0, masterTotal - alreadyPaid);
  const currentInput = parseNumber(nominal.value || 0);

  if (!billId) {
    const legacy = option?.dataset.legacy === '1';
    if (totalEl) totalEl.value = legacy ? formatRupiahString(masterTotal) : '0';
    if (paidEl) paidEl.value = '0';
    if (sisaEl) sisaEl.value = legacy ? formatRupiahString(Math.max(0, masterTotal - currentInput)) : '0';
    if (!preserveInput) nominal.value = '0';
    nominal.setCustomValidity('');
    nominal.removeAttribute('title');
    row?.classList.remove('row-overpaid');
    return;
  }

  if (totalEl) totalEl.value = formatRupiahString(masterTotal);
  if (paidEl) paidEl.value = formatRupiahString(alreadyPaid);
  if (!preserveInput) {
    nominal.value = '0';
  }

  const inputValue = parseNumber(nominal.value || 0);
  const isTooMuch = inputValue > remainingBeforeInput + 0.001;
  if (sisaEl) sisaEl.value = formatRupiahString(Math.max(0, remainingBeforeInput - inputValue));
  row?.classList.toggle('row-overpaid', isTooMuch);

  if (isTooMuch) {
    const message = 'Input bayar biaya lain melebihi sisa. Sisa hanya Rp ' + formatRupiah(remainingBeforeInput) + '.';
    nominal.setCustomValidity(message);
    nominal.title = message;
  } else {
    nominal.setCustomValidity('');
    nominal.removeAttribute('title');
  }
}

function refreshBiayaLainWarnings() {
  const alertEl = document.getElementById('biaya-lain-overpaid-alert');
  const warnings = [];

  document.querySelectorAll('.biaya-lain-row').forEach(row => {
    const select = row.querySelector('.biaya-lain-select');
    const input = row.querySelector('.biaya-lain-nominal');
    const total = parseNumber(row.querySelector('.biaya-lain-total')?.value || 0);
    const paid = parseNumber(row.querySelector('.biaya-lain-paid')?.value || 0);
    const inputValue = parseNumber(input?.value || 0);
    const remaining = Math.max(0, total - paid);
    if (select?.validationMessage) {
      warnings.push(select.validationMessage);
      return;
    }
    const isTooMuch = select?.value && inputValue > remaining + 0.001;
    if (!isTooMuch) return;

    const selectedText = select.options[select.selectedIndex]?.textContent?.trim() || 'Biaya lain';
    warnings.push(selectedText + ' melebihi sisa. Sisa Rp ' + formatRupiah(remaining) + ', input Rp ' + formatRupiah(inputValue) + '.');
  });

  if (!alertEl) return warnings;
  if (warnings.length === 0) {
    alertEl.hidden = true;
    alertEl.textContent = '';
    return warnings;
  }

  alertEl.hidden = false;
  alertEl.textContent = 'Perhatian: pembayaran biaya lain melebihi sisa tagihan. ' + warnings.join(' ');
  return warnings;
}

function setBiayaLainNominal(row, preserveLegacy) {
  const nominal = row?.querySelector('.biaya-lain-nominal');
  if (!nominal) return;
  if (!preserveLegacy) {
    nominal.value = '0';
  }
  refreshBiayaLainRow(row, preserveLegacy);
  updateTotal();
}

function addBiayaLainRow() {
  const list = document.getElementById('biaya-lain-list');
  const template = document.getElementById('biaya-lain-row-template');
  if (!list || !template) return;
  list.appendChild(template.content.cloneNode(true));
  const row = list.lastElementChild;
  bindNumericInput(row?.querySelector('.biaya-lain-nominal'));
  refreshBiayaLainOptions();
  refreshBiayaLainRow(row, false);
  renumberBiayaLainRows();
  refreshBiayaLainAvailability();
  updateTotal();
}

document.addEventListener('DOMContentLoaded', function () {
  const list = document.getElementById('biaya-lain-list');
  const addButton = document.getElementById('btn-add-biaya-lain');
  if (!list || !addButton) return;

  addButton.addEventListener('click', addBiayaLainRow);
  list.addEventListener('change', function (event) {
    if (event.target.classList.contains('biaya-lain-select')) {
      setBiayaLainNominal(event.target.closest('.biaya-lain-row'), false);
    }
  });
  list.addEventListener('click', function (event) {
    const removeButton = event.target.closest('.btn-remove-biaya-lain');
    if (!removeButton) return;
    const row = removeButton.closest('.biaya-lain-row');
    if (list.querySelectorAll('.biaya-lain-row').length === 1) {
      row.querySelector('.biaya-lain-select').value = '';
      row.querySelector('[name="biaya_lain_detail_id[]"]').value = '';
      row.querySelector('.biaya-lain-keterangan').value = '';
      row.querySelector('.biaya-lain-total').value = '0';
      row.querySelector('.biaya-lain-paid').value = '0';
      row.querySelector('.biaya-lain-sisa').value = '0';
      row.querySelector('.biaya-lain-nominal').value = '0';
      row.querySelector('.biaya-lain-nominal').setCustomValidity('');
      row.classList.remove('row-overpaid');
    } else {
      row.remove();
    }
    renumberBiayaLainRows();
    refreshBiayaLainAvailability();
    updateTotal();
  });
  renumberBiayaLainRows();
  refreshBiayaLainOptions();
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  refreshBiayaLainAvailability();
});

/* ── Hitung Sisa ─────────────────────────── */
function reportRangeDateLabel(value) {
  if (!value) return '';
  const date = new Date(value + 'T00:00:00');
  if (Number.isNaN(date.getTime())) return '';
  return String(date.getDate()).padStart(2, '0') + ' ' + paymentMonthLabelByCode(date.getMonth() + 1) + ' ' + date.getFullYear();
}

function reportRangeDateValue(date) {
  return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
}

function reportRangeDisplayLabel(startValue, endValue, emptyLabel) {
  const fallback = emptyLabel || 'Pilih tanggal transaksi';
  if (!startValue && !endValue) return fallback;
  if (startValue && !endValue) endValue = startValue;
  if (!startValue && endValue) startValue = endValue;

  let start = new Date(startValue + 'T00:00:00');
  let end = new Date(endValue + 'T00:00:00');
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return fallback;
  if (start > end) [start, end] = [end, start];

  const startDate = reportRangeDateValue(start);
  const endDate = reportRangeDateValue(end);
  if (startDate === endDate) return reportRangeDateLabel(startDate);

  if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()) {
    return String(start.getDate()).padStart(2, '0') + '-' + String(end.getDate()).padStart(2, '0') + ' ' +
      paymentMonthLabelByCode(end.getMonth() + 1) + ' ' + end.getFullYear();
  }
  return reportRangeDateLabel(startDate) + ' - ' + reportRangeDateLabel(endDate);
}

function closeReportDateRangePicker(picker) {
  const popover = picker?.querySelector('.report-date-range-popover');
  const button = picker?.querySelector('.report-date-range-button');
  if (!popover || !button) return;
  popover.hidden = true;
  picker.classList.remove('is-open');
  picker.closest('.report-filter-card')?.classList.remove('is-date-picker-open');
  button.setAttribute('aria-expanded', 'false');
}

function initReportDateRangePickers() {
  document.querySelectorAll('[data-range-picker]').forEach(picker => {
    if (picker.dataset.rangePickerReady === '1') return;
    picker.dataset.rangePickerReady = '1';

    const button = picker.querySelector('.report-date-range-button');
    const valueLabel = picker.querySelector('.report-date-range-value');
    const popover = picker.querySelector('.report-date-range-popover');
    const hiddenStart = picker.querySelector('input[type="hidden"][name="tanggal_awal"]');
    const hiddenEnd = picker.querySelector('input[type="hidden"][name="tanggal_akhir"]');
    const startInput = picker.querySelector('[data-range-start]');
    const endInput = picker.querySelector('[data-range-end]');
    const applyButton = picker.querySelector('[data-range-apply]');
    if (!button || !valueLabel || !popover || !hiddenStart || !hiddenEnd || !startInput || !endInput) return;

    const syncLabel = () => {
      valueLabel.textContent = reportRangeDisplayLabel(hiddenStart.value, hiddenEnd.value, picker.dataset.emptyLabel);
    };
    const syncInputsFromHidden = () => {
      startInput.value = hiddenStart.value;
      endInput.value = hiddenEnd.value || hiddenStart.value;
    };
    const applyRange = () => {
      let start = startInput.value;
      let end = endInput.value || start;
      if (!start && end) start = end;
      if (start && end && start > end) [start, end] = [end, start];
      hiddenStart.value = start;
      hiddenEnd.value = end || start;
      syncInputsFromHidden();
      syncLabel();
      closeReportDateRangePicker(picker);
    };

    syncInputsFromHidden();
    syncLabel();

    button.addEventListener('click', function () {
      const willOpen = popover.hidden;
      document.querySelectorAll('[data-range-picker]').forEach(closeReportDateRangePicker);
      popover.hidden = !willOpen;
      picker.classList.toggle('is-open', willOpen);
      picker.closest('.report-filter-card')?.classList.toggle('is-date-picker-open', willOpen);
      button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (willOpen) {
        syncInputsFromHidden();
        setTimeout(() => startInput.focus(), 0);
      }
    });

    [startInput, endInput].forEach(input => {
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          applyRange();
        }
      });
      input.addEventListener('change', function () {
        if (startInput.value && endInput.value && startInput.value > endInput.value) {
          valueLabel.textContent = reportRangeDisplayLabel(startInput.value, endInput.value, picker.dataset.emptyLabel);
        }
      });
    });
    applyButton?.addEventListener('click', applyRange);
  });
}

function closeReportMonthRangePicker(picker) {
  const popover = picker?.querySelector('.report-month-range-popover');
  const button = picker?.querySelector('.report-month-range-button');
  if (!popover || !button) return;
  popover.hidden = true;
  picker.classList.remove('is-open');
  button.setAttribute('aria-expanded', 'false');
}

function closeReportYearRangePicker(picker) {
  const popover = picker?.querySelector('.report-year-range-popover');
  const button = picker?.querySelector('.report-year-range-button');
  if (!popover || !button) return;
  popover.hidden = true;
  picker.classList.remove('is-open');
  button.setAttribute('aria-expanded', 'false');
}

function closeAllReportRangePickers() {
  document.querySelectorAll('[data-range-picker]').forEach(closeReportDateRangePicker);
  document.querySelectorAll('[data-month-range-picker]').forEach(closeReportMonthRangePicker);
  document.querySelectorAll('[data-year-range-picker]').forEach(closeReportYearRangePicker);
}

function initReportMonthRangePickers() {
  document.querySelectorAll('[data-month-range-picker]').forEach(picker => {
    if (picker.dataset.monthRangePickerReady === '1') return;
    picker.dataset.monthRangePickerReady = '1';
    const button = picker.querySelector('.report-month-range-button');
    const valueLabel = picker.querySelector('.report-month-range-value');
    const popover = picker.querySelector('.report-month-range-popover');
    const hiddenStart = picker.querySelector('input[type="hidden"][name="bulan_awal"]');
    const hiddenEnd = picker.querySelector('input[type="hidden"][name="bulan_akhir"]');
    const startInput = picker.querySelector('[data-month-range-start]');
    const endInput = picker.querySelector('[data-month-range-end]');
    const applyButton = picker.querySelector('[data-month-range-apply]');
    if (!button || !valueLabel || !popover || !hiddenStart || !hiddenEnd || !startInput || !endInput) return;

    const syncLabel = () => {
      const start = paymentMonthLabelByCode(parseNumber(hiddenStart.value));
      const end = paymentMonthLabelByCode(parseNumber(hiddenEnd.value));
      valueLabel.textContent = hiddenStart.value && hiddenEnd.value ? start + ' - ' + end : (picker.dataset.emptyLabel || 'Pilih bulan tagihan');
    };
    const syncInputs = () => {
      startInput.value = hiddenStart.value;
      endInput.value = hiddenEnd.value;
    };
    const applyRange = () => {
      hiddenStart.value = startInput.value;
      hiddenEnd.value = endInput.value;
      syncInputs();
      syncLabel();
      closeReportMonthRangePicker(picker);
    };

    syncInputs();
    syncLabel();
    button.addEventListener('click', () => {
      const willOpen = popover.hidden;
      closeAllReportRangePickers();
      popover.hidden = !willOpen;
      picker.classList.toggle('is-open', willOpen);
      button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (willOpen) setTimeout(() => startInput.focus(), 0);
    });
    [startInput, endInput].forEach(input => input.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        applyRange();
      }
    }));
    applyButton?.addEventListener('click', applyRange);
  });
}

function initReportYearRangePickers() {
  document.querySelectorAll('[data-year-range-picker]').forEach(picker => {
    if (picker.dataset.yearRangePickerReady === '1') return;
    picker.dataset.yearRangePickerReady = '1';
    const button = picker.querySelector('.report-year-range-button');
    const valueLabel = picker.querySelector('.report-year-range-value');
    const popover = picker.querySelector('.report-year-range-popover');
    const hiddenStart = picker.querySelector('input[type="hidden"][name="tahun_awal"]');
    const hiddenEnd = picker.querySelector('input[type="hidden"][name="tahun_akhir"]');
    const startInput = picker.querySelector('[data-year-range-start]');
    const endInput = picker.querySelector('[data-year-range-end]');
    const applyButton = picker.querySelector('[data-year-range-apply]');
    if (!button || !valueLabel || !popover || !hiddenStart || !hiddenEnd || !startInput || !endInput) return;

    const syncLabel = () => {
      valueLabel.textContent = hiddenStart.value && hiddenEnd.value
        ? hiddenStart.value + ' - ' + hiddenEnd.value
        : (picker.dataset.emptyLabel || 'Pilih tahun tagihan');
    };
    const syncInputs = () => {
      startInput.value = hiddenStart.value;
      endInput.value = hiddenEnd.value;
    };
    const applyRange = () => {
      const start = parseNumber(startInput.value);
      const end = parseNumber(endInput.value);
      if (!start || !end) return;
      hiddenStart.value = String(Math.min(start, end));
      hiddenEnd.value = String(Math.max(start, end));
      syncInputs();
      syncLabel();
      closeReportYearRangePicker(picker);
    };

    syncInputs();
    syncLabel();
    button.addEventListener('click', () => {
      const willOpen = popover.hidden;
      closeAllReportRangePickers();
      popover.hidden = !willOpen;
      picker.classList.toggle('is-open', willOpen);
      button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (willOpen) setTimeout(() => startInput.focus(), 0);
    });
    [startInput, endInput].forEach(input => input.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        applyRange();
      }
    }));
    applyButton?.addEventListener('click', applyRange);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  initReportDateRangePickers();
  initReportMonthRangePickers();
  initReportYearRangePickers();
  document.addEventListener('mousedown', function (event) {
    document.querySelectorAll('[data-range-picker]').forEach(picker => {
      if (!picker.contains(event.target)) closeReportDateRangePicker(picker);
    });
    document.querySelectorAll('[data-month-range-picker]').forEach(picker => {
      if (!picker.contains(event.target)) closeReportMonthRangePicker(picker);
    });
    document.querySelectorAll('[data-year-range-picker]').forEach(picker => {
      if (!picker.contains(event.target)) closeReportYearRangePicker(picker);
    });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('[data-range-picker]').forEach(closeReportDateRangePicker);
    document.querySelectorAll('[data-month-range-picker]').forEach(closeReportMonthRangePicker);
    document.querySelectorAll('[data-year-range-picker]').forEach(closeReportYearRangePicker);
  });
});

function hitungSisa(key) {
  const total  = parseNumber(document.getElementById(key + '-total')?.value  || 0);
  const bayar  = parseNumber(document.getElementById(key + '-bayar')?.value  || 0);
  const input  = parseNumber(document.getElementById(key + '-input')?.value  || 0);
  const sisaEl = document.getElementById(key + '-sisa');
  if (sisaEl) sisaEl.value = formatRupiahString(Math.max(0, total - bayar - input));
}

/* ── Update Total ────────────────────────── */
function updateTotal() {
  refreshOptionalOneTimeFeeAvailability();
  refreshOverpaidWarnings();
  refreshSppInstallmentAvailability();
  refreshPaymentInputOverlimitWarnings();
  refreshBiayaLainAvailability();
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  refreshBiayaLainWarnings();
  applyGraduatePaymentLock(selectedStudentOption());
  const ids = ['pangkal-input','psb-input','spp-input','komite-input','du-input'];
  let total = 0;
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) total += parseNumber(el.value || 0);
  });

  document.querySelectorAll('.biaya-lain-nominal').forEach(input => {
    total += parseNumber(input.value || 0);
  });

  // Subtract potongan
  const pot = parseNumber(document.getElementById('potongan-spp')?.value || 0);
  total = Math.max(0, total - pot);

  const totalEl   = document.getElementById('totalJumlah');
  const hiddenEl  = document.getElementById('hidden-total');
  if (totalEl)  totalEl.textContent = 'Rp ' + formatRupiah(total);
  if (hiddenEl) hiddenEl.value = total;

  // Kewajiban SPP adalah sisa periode sebelum input transaksi saat ini.
  const sppTotal = parseNumber(document.getElementById('spp-total')?.value || 0);
  const sppPaid = parseNumber(document.getElementById('spp-bayar')?.value || 0);
  const kewEl    = document.getElementById('kewajiban-spp');
  if (kewEl) {
    kewEl.value = formatRupiahString(Math.max(0, sppTotal - sppPaid));
  }
  refreshSppPeriodLabel();
  refreshAcademicYearSummary();
}

/* ── Format Rupiah ───────────────────────── */
function formatRupiah(num) {
  return new Intl.NumberFormat('id-ID').format(num);
}

/* ── Reset Form ──────────────────────────── */
function resetForm() {
  closeSppWarning();
  resetSppStatusState();
  clearOverpaidUiState();
  const totalEl = document.getElementById('totalJumlah');
  if (totalEl) totalEl.textContent = 'Rp 0';
  const hiddenEl = document.getElementById('hidden-total');
  if (hiddenEl) hiddenEl.value = 0;

  ['pangkal','psb','spp','komite','du'].forEach(k => {
    ['total','bayar','sisa','input'].forEach(s => {
      const el = document.getElementById(k + '-' + s);
      if (el) el.value = '0';
    });
  });

  const list = document.getElementById('biaya-lain-list');
  const template = document.getElementById('biaya-lain-row-template');
  if (list && template) {
    list.innerHTML = '';
    list.appendChild(template.content.cloneNode(true));
    const row = list.lastElementChild;
    bindNumericInput(row?.querySelector('.biaya-lain-nominal'));
    refreshBiayaLainRow(row, false);
    renumberBiayaLainRows();
    refreshBiayaLainAvailability();
  }
  clearOverpaidUiState();
  refreshAnnualPaymentState(null);
  refreshPaymentHistory('');
  updateTotal();
}

/* ── Cari Siswa (standalone page only) ────── */
function cariSiswa() {
  const nis = document.getElementById('no-induk')?.value?.trim();
  if (!nis) return;
  // In the PHP version, search is via dropdown. This is fallback.
  alert('Silakan gunakan dropdown "Pilih Siswa" untuk memilih siswa.');
}

/* ── Auto-hide Flash Messages ────────────── */
function autoHideFlash() {
  const flash = document.getElementById('flash-msg');
  if (flash) {
    setTimeout(() => {
      flash.style.transition = 'opacity 0.5s ease';
      flash.style.opacity = '0';
      setTimeout(() => flash.remove(), 500);
    }, 4000);
  }
}

/* ── Table Filter (client-side) ──────────── */
function filterTable() {
  const query  = (document.getElementById('search-lihat')?.value || '').toLowerCase();
  const rows   = document.querySelectorAll('#tbl-lihat tbody tr');
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(query) ? '' : 'none';
  });
}

/* ── localStorage data store (fallback tab) */
let dataStore = JSON.parse(localStorage.getItem('spp_data') || '[]');

function renderLihatTable() {
  const tbody   = document.getElementById('tbl-lihat-body');
  const emptyEl = document.getElementById('empty-lihat');
  if (!tbody) return;

  if (dataStore.length === 0) {
    tbody.innerHTML = '';
    if (emptyEl) emptyEl.style.display = 'flex';
    return;
  }
  if (emptyEl) emptyEl.style.display = 'none';

  tbody.innerHTML = dataStore.map((row, i) => `
    <tr>
      <td>${i + 1}</td>
      <td><span class="badge-nis">${row.nis || '-'}</span></td>
      <td>${row.nama || '-'}</td>
      <td>${row.kelas || '-'}</td>
      <td>${row.bulan || '-'}</td>
      <td class="nominal">Rp ${formatRupiah(row.total)}</td>
      <td>${row.tanggal || '-'}</td>
      <td><span class="badge-count">✓</span></td>
    </tr>
  `).join('');
}

/* ── Standalone (non-PHP) functions ────────── */
function inputData() {
  const form = document.getElementById('form-bayar');
  if (!form) return;
  const siswa = document.getElementById('siswa-select');
  const nama  = siswa?.options[siswa?.selectedIndex]?.text || 'Siswa';
  const nis   = document.getElementById('disp-nis')?.value || '-';
  const kelas = document.getElementById('disp-kelas')?.value || '-';
  const bulan = document.getElementById('bulan-bayar')?.value || '-';
  const total = parseFloat(document.getElementById('hidden-total')?.value || 0);
  const tgl   = document.getElementById('tgl-bayar')?.value || new Date().toLocaleDateString('id-ID');

  const entry = { nama, nis, kelas, bulan, total, tanggal: tgl };
  dataStore.push(entry);
  localStorage.setItem('spp_data', JSON.stringify(dataStore));

  showToast('✓', 'Data pembayaran berhasil disimpan!', 'success');
  resetForm();
  form.reset();
  updateTotal();
}

function editData()  { showToast('✏', 'Mode edit — pilih data dari tab Lihat terlebih dahulu.', 'info'); }
function hapusData() { showModal('Konfirmasi Hapus', 'Yakin ingin menghapus data ini?'); }
function keluarForm() { if (confirm('Keluar dari form?')) window.location.href = '../dashboard.php'; }
function cetakLaporan() { window.print(); }

/* ── Toast ───────────────────────────────── */
function showToast(icon, msg, type = 'success') {
  const toast   = document.getElementById('toast');
  const iconEl  = document.getElementById('toast-icon');
  const msgEl   = document.getElementById('toast-msg');
  if (!toast) return;

  iconEl.textContent = icon;
  msgEl.textContent  = msg;
  toast.className    = 'toast toast-' + type + ' show';
  setTimeout(() => toast.classList.remove('show'), 3500);
}

/* ── Modal ───────────────────────────────── */
function showModal(title, body) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').textContent  = body;
  document.getElementById('modal-overlay').classList.add('show');
}
function closeModal() {
  document.getElementById('modal-overlay')?.classList.remove('show');
}
function konfirmasiHapus() {
  closeModal();
  showToast('🗑', 'Data berhasil dihapus!', 'success');
}

// Close modal on overlay click
document.addEventListener('click', function (e) {
  const overlay = document.getElementById('modal-overlay');
  if (e.target === overlay) closeModal();
});
