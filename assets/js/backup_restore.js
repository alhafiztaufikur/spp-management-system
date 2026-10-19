/* Shared file selection and Legacy tabs. SQL restore is handled by database_restore.js. */
(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const tabs = ['backup', 'legacy'].map(mode => byId('tab-' + mode));
  const selectTab = selected => {
    tabs.forEach(tab => {
      const active = tab === selected;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      byId(tab.getAttribute('aria-controls')).hidden = !active;
    });
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', event => { event.preventDefault(); selectTab(tab); history.replaceState(null, '', '?tab=' + (tab.id === 'tab-legacy' ? 'legacy' : 'backup')); });
    tab.addEventListener('keydown', event => {
      let target;
      if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') target = tabs[1 - index];
      if (event.key === 'Home') target = tabs[0];
      if (event.key === 'End') target = tabs[1];
      if (target) { event.preventDefault(); selectTab(target); target.focus(); }
    });
  });
  if (location.hash === '#panel-legacy') selectTab(byId('tab-legacy'));
  const selectedFiles = {restore: null, legacy: null};
  const legacyReadiness = () => document.dispatchEvent(new CustomEvent('legacy-selection-changed'));
  const updateSelection = (prefix, files) => {
    if (prefix === 'restore' && byId('restore-review').dataset.busy === 'true') return;
    selectedFiles[prefix] = null;
    byId(prefix + '-summary').hidden = true;
    const status = byId(prefix + '-status');
    status.classList.remove('is-error');
    let error = '';
    if (files.length > 1) error = 'Pilih satu file saja; pilihan ganda tidak diterima.';
    else if (files.length === 1) {
      const file = files[0];
      if (!(prefix === 'legacy' ? (!file.name.includes('.') || /\.(dat|bak)$/i.test(file.name)) : /\.sql$/i.test(file.name))) error = prefix === 'legacy' ? 'Pilih backup SQL Server .dat, .bak, atau tanpa ekstensi.' : 'Pilih satu file SQL (.sql).';
      else if (file.size === 0) error = 'File kosong tidak dapat digunakan.';
      else if (file.size > 100 * 1024 * 1024) error = 'Ukuran file melebihi batas 100 MB.';
      else {
        selectedFiles[prefix] = file;
        byId(prefix + '-summary').hidden = false;
        byId(prefix + '-name').textContent = file.name;
        byId(prefix + '-size').textContent = (file.size / 1048576).toLocaleString('id-ID', {maximumFractionDigits: 2}) + ' MB';
      }
    }
    status.textContent = error || (selectedFiles[prefix] ? (prefix==='legacy'?'Belum diperiksa. Nama, ukuran, dan jumlah file memenuhi pemeriksaan awal; klik Periksa Backup untuk memeriksa sumber.':'Belum divalidasi. Pemeriksaan awal nama, ukuran, dan jumlah file terpenuhi; isi SQL belum diperiksa.') : 'Belum ada file dipilih.');
    if (error) status.classList.add('is-error');
    if (prefix === 'restore') byId('restore-review').disabled = !selectedFiles.restore;
    if (prefix === 'restore') document.dispatchEvent(new CustomEvent('restore-file-selected', {detail: selectedFiles.restore}));
    legacyReadiness();
    if(prefix === 'legacy') document.dispatchEvent(new CustomEvent('legacy-file-selected', {detail: selectedFiles.legacy}));
  };
  ['restore', 'legacy'].filter(prefix => byId(prefix + '-file')).forEach(prefix => {
    const input = byId(prefix + '-file'), zone = byId(prefix + '-dropzone');
    const choose = () => { input.value = ''; input.click(); };
    byId(prefix + '-choose').addEventListener('click', choose);
    byId(prefix + '-change').addEventListener('click', choose);
    input.addEventListener('change', () => updateSelection(prefix, Array.from(input.files)));
    byId(prefix + '-remove').addEventListener('click', () => { input.value = ''; updateSelection(prefix, []); byId(prefix + '-choose').focus(); });
    zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('is-dragging'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('is-dragging'));
    zone.addEventListener('drop', event => {
      event.preventDefault(); zone.classList.remove('is-dragging'); input.value = '';
      updateSelection(prefix, Array.from(event.dataTransfer.files));
    });
  });
  const conditions = {
    students: ['Identitas siswa sebagai Legacy', 'NIS dipertahankan per unit. Duplikasi dalam unit dan identitas yang bermasalah ditahan. Aktivasi dan penempatan dilakukan manual melalui Data Siswa.'],
    finance: ['Arsip histori keuangan', 'Histori disimpan sebagai arsip. Saldo dan tunggakan harus ditinjau per siswa sebelum menjadi data aktif.'],
    classes: ['Histori kelas dan tarif perlu dilengkapi', 'Kelas, rombel, periode, dan tarif harus dipetakan dari bukti sumber. Kelas sebelum penempatan pertama tidak ditebak.'],
    payments: ['Relasi pembayaran perlu dibuktikan', 'Identitas transaksi, periode, siswa, tagihan, potongan, dan nominal perlu dicocokkan. Tidak ada penerimaan fiktif atau pemecahan transaksi otomatis.'],
    savings: ['Saldo dan jurnal perlu direkonsiliasi', 'Saldo Tabungan harus cocok dengan jurnal masuk/keluar. Saldo bukan penerimaan pembayaran sekolah.']
  };
  document.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('[data-category]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    const [title, description] = conditions[button.dataset.category];
    document.dispatchEvent(new CustomEvent('legacy-category-selected', {detail: button.dataset.category}));
    byId('legacy-condition-title').textContent = title;
    byId('legacy-condition').textContent = description;
    document.querySelector('.dbt-audit-condition .dbt-badge').textContent = button.dataset.category === 'finance' ? 'Arsip & peninjauan' : 'Identitas siswa saja';
  }));
  if (byId('legacy-unit')) byId('legacy-unit').addEventListener('change', legacyReadiness);
  const dialog = byId('restore-dialog');
  byId('restore-cancel').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => byId('restore-review').focus());
  dialog.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const controls = [...dialog.querySelectorAll('button:not(:disabled),input:not(:disabled),a[href]')].filter(node => node.getClientRects().length && !node.closest('[hidden]'));
    if (!controls.length) return;
    const first = controls[0], last = controls[controls.length - 1];
    if (event.shiftKey && (document.activeElement === first || !controls.includes(document.activeElement))) {event.preventDefault();last.focus();}
    else if (!event.shiftKey && (document.activeElement === last || !controls.includes(document.activeElement))) {event.preventDefault();first.focus();}
  });
  // The native modal supplies Escape cancellation; Tab stays within visible enabled controls.
})();
