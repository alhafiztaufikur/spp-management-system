// ============================================
// SistemSPP - app.js
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
      applyStudentPaymentDetails(opt);
      found = true;
      break;
    }
  }
  
  if (!found) {
    document.getElementById('disp-nis').value = '';
    document.getElementById('disp-nama').value = '';
    document.getElementById('disp-kelas').value = '';
    clearPaymentDetails();
  }
}

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
  target.value = exact ? (exact.dataset.nis || exact.dataset.diknas || typed) : typed;
}

function selectReportStudentSearchOption(input, opt) {
  input.value = studentSearchOptionLabel(opt);
  syncStudentSearchQueryTarget(input, opt.dataset.nis || opt.dataset.diknas || input.value);
}

function applyDefaultDaftarUlangClass(opt) {
  const studentClass = String(opt.dataset.kelas || '').match(/[1-6]/)?.[0] || '';
  setDaftarUlangContext(studentClass, academicYearFromPaymentPeriod());
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

function firstUnpaidPriorSppPeriod(opt, monthlyBill) {
  if (!opt || monthlyBill <= 0) return null;
  let periods = {};
  try {
    periods = JSON.parse(opt.dataset.paidSppPeriods || '{}');
  } catch (_) {
    periods = {};
  }

  for (const period of priorSppPeriodsInAcademicYear()) {
    const paid = parseNumber(periods[period] || 0);
    if (paid + 0.001 < monthlyBill) {
      return {
        period,
        paid,
        remaining: Math.max(0, monthlyBill - paid),
        label: paymentPeriodDisplayLabel(period)
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

  for (const period of followingSppPeriodsInAcademicYear()) {
    const paid = parseNumber(periods[period] || 0);
    if (paid > 0.001) {
      return { period, paid, label: paymentPeriodDisplayLabel(period) };
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
  const bill = selectedDaftarUlangBill(opt);
  setDaftarUlangContext(bill?.kelas || '', academicYearFromPaymentPeriod());
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

function selectedDaftarUlangKey() {
  return academicYearFromPaymentPeriod();
}

function selectedDaftarUlangRecord(opt) {
  if (!opt) return null;
  const year = selectedDaftarUlangKey();
  if (!year) return null;
  try {
    const bills = JSON.parse(opt.dataset.duBills || '{}');
    return bills[year] || null;
  } catch (_) {
    return null;
  }
}

function selectedDaftarUlangBill(opt) {
  const bill = selectedDaftarUlangRecord(opt);
  return bill && bill.status === 'open' ? bill : null;
}

function totalDaftarUlangForContext(opt) {
  return parseNumber(selectedDaftarUlangBill(opt)?.total || 0);
}

function refreshDaftarUlangMasterWarning(opt) {
  const warning = document.getElementById('du-master-warning');
  const input = document.getElementById('du-input');
  if (!warning && !input) return;

  const periodKey = selectedDaftarUlangKey();
  const record = selectedDaftarUlangRecord(opt);
  const bill = selectedDaftarUlangBill(opt);
  const hasContext = !!opt && !!periodKey;
  const total = parseNumber(bill?.total || 0);
  const paid = parseNumber(bill?.paid || 0);
  const remaining = Math.max(0, total - paid);
  const isSettled = !!bill && total > 0 && remaining <= 0.001;
  const isUnavailable = hasContext && !bill;
  const contextLabel = document.getElementById('du-context-label');
  if (contextLabel) {
    let status = 'Belum Bayar';
    if (isSettled) status = 'Lunas';
    else if (paid > 0) status = 'Cicilan';
    if (!opt || !periodKey) {
      contextLabel.textContent = 'Pilih siswa, bulan, dan tahun pembayaran.';
    } else if (bill) {
      contextLabel.textContent = 'TA ' + periodKey + ' · Kelas ' + bill.kelas + ' · ' + status;
    } else if (record?.status === 'cancelled') {
      contextLabel.textContent = 'TA ' + periodKey + ' · Tagihan dibatalkan';
    } else {
      contextLabel.textContent = 'TA ' + periodKey + ' · Tagihan belum tersedia';
    }
  }

  if (input) {
    const locked = !bill || isSettled;
    const lockedMessage = isSettled
      ? 'Tagihan Daftar Ulang sudah lunas.'
      : 'Tagihan Daftar Ulang siswa untuk tahun ajaran ini belum diterbitkan.';
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
    return;
  }

  warning.hidden = false;
  warning.textContent = record?.status === 'cancelled'
    ? 'Tagihan siswa ini telah dibatalkan dan tidak dapat dibayar.'
    : 'Tagihan belum diterbitkan. Buka Master Daftar Ulang lalu gunakan Simpan & Terbitkan Tagihan.';
}

function paidDaftarUlangForContext(opt) {
  return parseNumber(selectedDaftarUlangBill(opt)?.paid || 0);
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

  const annualKeys = ['pangkal','bangunan','seragam','kegiatan','komite','makan','sorga','infaq'];
  let total = annualKeys.reduce((sum, key) => sum + totalAnnualFeeForContext(opt, key), 0);
  let paid = annualKeys.reduce((sum, key) => sum + paidAnnualFeeForContext(opt, key), 0);

  total += datasetNumber(opt, 'total', 'spp') * 12;
  paid += paidForAcademicYear(opt, 'spp');

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
  ['pangkal','bangunan','seragam','kegiatan','spp','komite','makan','sorga','infaq','du'].forEach(key => {
    const total = key === 'du'
      ? totalDaftarUlangForContext(opt)
      : (key === 'spp'
          ? datasetNumber(opt, 'total', key) * (isAnnualPaymentPlan() ? 12 : 1)
          : totalAnnualFeeForContext(opt, key));
    const paid = key === 'du'
      ? paidDaftarUlangForContext(opt)
      : (key === 'spp' ? paidForPeriod(opt, key) : paidAnnualFeeForContext(opt, key));
    setPaymentComponent(key, total, paid);
  });
  refreshAnnualPaymentState(opt);
  refreshDaftarUlangMasterWarning(opt);
  refreshBiayaLainOptions();
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  updateTotal();
}

function clearPaymentDetails() {
  ['pangkal','bangunan','seragam','kegiatan','spp','komite','makan','sorga','infaq','du'].forEach(key => {
    ['total','bayar','sisa'].forEach(part => {
      const el = document.getElementById(key + '-' + part);
      if (el) el.value = '0';
    });
  });
  refreshDaftarUlangMasterWarning(null);
  refreshAnnualPaymentState(null);
  refreshBiayaLainOptions();
  document.querySelectorAll('.biaya-lain-row').forEach(row => refreshBiayaLainRow(row, true));
  updateTotal();
}

const paymentComponentLabels = {
  pangkal: 'Uang Pangkal',
  bangunan: 'Uang Bangunan',
  seragam: 'Uang Seragam',
  kegiatan: 'Uang Kegiatan',
  spp: 'Uang SPP',
  komite: 'Uang Komite',
  makan: 'Uang Makan',
  sorga: 'Uang Sorga',
  infaq: 'Uang Infaq',
  du: 'Uang Daftar Ulang'
};

function refreshOptionalOneTimeFeeAvailability() {
  const hasStudent = !!document.getElementById('disp-nis')?.value;
  ['pangkal', 'bangunan', 'seragam', 'kegiatan', 'komite', 'makan', 'sorga', 'infaq'].forEach(key => {
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
      contextEl.textContent = locked ? message : 'Tagihan tahunan · dapat dicicil';
    }
    hitungSisa(key);
  });
}

function refreshSppInstallmentAvailability() {
  const input = document.getElementById('spp-input');
  if (!input) return;
  const opt = selectedStudentOption();
  const total = parseNumber(document.getElementById('spp-total')?.value || 0);
  const paid = parseNumber(document.getElementById('spp-bayar')?.value || 0);
  const remaining = Math.max(0, total - paid);
  const context = document.getElementById('spp-context-label');
  const monthLabel = selectedPaymentMonthLabel();
  const year = document.getElementById('tahun-bayar')?.value || '';

  let lockedMessage = '';
  if (!opt) lockedMessage = 'Pilih siswa terlebih dahulu';
  else if (total <= 0) lockedMessage = 'Tarif SPP belum diatur';
  else if (remaining <= 0.001) lockedMessage = 'Lunas untuk ' + monthLabel + ' ' + year;
  else {
    const unpaidPrior = firstUnpaidPriorSppPeriod(opt, total);
    if (unpaidPrior) {
      lockedMessage = 'SPP ' + monthLabel + ' ' + year + ' belum bisa dibayar karena ' + unpaidPrior.label + ' belum lunas';
      if (unpaidPrior.remaining > 0) lockedMessage += ' (sisa Rp ' + formatRupiah(unpaidPrior.remaining) + ')';
    }
  }

  const paidFollowing = firstPaidFollowingSppPeriod(opt);
  const mustSettleCurrent = !lockedMessage && !!paidFollowing && remaining > 0.001;
  const inputValue = parseNumber(input.value || 0);
  const locked = lockedMessage !== '';
  input.readOnly = locked;
  input.classList.toggle('tbl-readonly', locked);
  if (locked) {
    input.value = '0';
    input.title = lockedMessage;
    input.setCustomValidity('');
  } else {
    input.removeAttribute('title');
    if (mustSettleCurrent && inputValue > 0.001 && inputValue + 0.001 < remaining) {
      input.setCustomValidity('SPP ' + monthLabel + ' ' + year + ' harus dilunasi karena ' + paidFollowing.label + ' sudah memiliki pembayaran.');
    } else {
      input.setCustomValidity('');
    }
  }

  if (context) {
    if (lockedMessage) context.textContent = lockedMessage;
    else if (paid > 0) context.textContent = 'Cicilan ' + monthLabel + ' ' + year + ' · sisa Rp ' + formatRupiah(remaining);
    else context.textContent = 'Tagihan bulanan · dapat dicicil';
  }
  if (context && !lockedMessage && mustSettleCurrent) {
    context.textContent = 'Harus dilunasi sebelum ' + paidFollowing.label + ' tetap valid · sisa Rp ' + formatRupiah(remaining);
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

    row?.classList.toggle('row-input-overlimit', isTooMuch);
    inputEl.classList.toggle('is-input-overlimit', isTooMuch);

    if (isTooMuch) {
      const message = paymentComponentLabels[key] + ' melebihi sisa tagihan. Sisa Rp ' + formatRupiah(remainingBeforeInput) + ', input Rp ' + formatRupiah(input) + '.';
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

// Auto-fill on page load (edit page) & bind number formatting
document.addEventListener('DOMContentLoaded', function () {
  initStudentSearchCombobox();

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
    el.addEventListener('change', refreshSelectedStudentPaymentDetails);
    if (el.id === 'tahun-bayar') {
      el.addEventListener('input', refreshSelectedStudentPaymentDetails);
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

  // Clean all formatted numeric inputs right before form submission so the backend gets raw numbers
  const form = document.getElementById('form-bayar');
  if (form) {
    form.addEventListener('submit', function () {
      document.querySelectorAll('.tbl-input, #potongan-spp, #kewajiban-spp, .biaya-lain-nominal').forEach(input => {
        input.value = input.value.replace(/\./g, '');
      });
    });
    form.addEventListener('reset', function () {
      window.setTimeout(resetForm, 0);
    });
  }

  updateTotal();
  autoHideFlash();
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
    placeholder.textContent = legacyPlaceholder?.textContent || '-- Pilih Tagihan --';
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

document.addEventListener('DOMContentLoaded', function () {
  initReportDateRangePickers();
  document.addEventListener('mousedown', function (event) {
    document.querySelectorAll('[data-range-picker]').forEach(picker => {
      if (!picker.contains(event.target)) closeReportDateRangePicker(picker);
    });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('[data-range-picker]').forEach(closeReportDateRangePicker);
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
  const ids = ['pangkal-input','bangunan-input','seragam-input','kegiatan-input','spp-input','komite-input','makan-input','sorga-input','infaq-input','du-input'];
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
  clearOverpaidUiState();
  const totalEl = document.getElementById('totalJumlah');
  if (totalEl) totalEl.textContent = 'Rp 0';
  const hiddenEl = document.getElementById('hidden-total');
  if (hiddenEl) hiddenEl.value = 0;

  ['pangkal','bangunan','seragam','kegiatan','spp','komite','makan','sorga','infaq','du'].forEach(k => {
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
