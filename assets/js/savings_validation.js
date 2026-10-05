(() => {
  const form = document.getElementById('form-tabungan');
  if (!form) return;
  form.noValidate = true;
  const withdrawal = form.elements.aksi.value === 'keluar';
  const amount = document.getElementById(withdrawal ? 'nominal-keluar' : 'nominal-masuk');
  const search = document.getElementById('siswa-search');
  const nis = document.getElementById('disp-nis');
  const balanceField = document.getElementById('disp-saldo');
  const preview = document.getElementById('saldo-preview');
  const hint = document.createElement('p');
  hint.id = 'savings-amount-hint'; hint.className = 'savings-validation-hint'; hint.setAttribute('aria-live','polite');
  amount.after(hint); amount.setAttribute('aria-describedby',hint.id);
  let state = 'empty', balance = 0, selected = '', generation = 0, controller;
  const rupiah = value => 'Rp ' + Number(value).toLocaleString('id-ID');
  const value = () => Number(amount.value.replace(/\D/g,''));
  function validate() {
    const exceeds = withdrawal && state === 'ready' && value() > balance;
    amount.classList.toggle('savings-invalid',exceeds);
    amount.setAttribute('aria-invalid',String(exceeds));
    hint.textContent = exceeds ? `Penarikan ${rupiah(value())} melebihi saldo ${rupiah(balance)}. Kelebihan ${rupiah(value()-balance)}.` :
      state === 'loading' ? 'Sedang memeriksa saldo…' : state === 'error' ? 'Saldo belum dapat diperiksa. Pilih ulang siswa untuk mencoba lagi.' : '';
    hint.classList.toggle('is-error',exceeds || state === 'error');
  }
  async function loadBalance() {
    const current = nis.value;
    if (current === selected && (state === 'ready' || state === 'loading')) return;
    selected = current; const request = ++generation; controller?.abort(); controller = new AbortController();
    balance = 0; state = current ? 'loading' : 'empty';
    balanceField.value = current ? 'Memeriksa saldo…' : '';
    preview.textContent = current ? 'Memeriksa saldo…' : 'Pilih siswa dulu';
    const raw = document.getElementById('raw-saldo'); if (raw) raw.value = '0';
    validate(); if (!current) return;
    try {
      const response = await fetch('get_saldo.php?nis='+encodeURIComponent(current),{signal:controller.signal,headers:{Accept:'application/json'}});
      const data = await response.json();
      if (!response.ok || data.error || !Number.isFinite(Number(data.saldo))) throw new Error('Saldo tidak tersedia');
      if (request !== generation || nis.value !== current) return;
      balance = Math.max(0,Number(data.saldo)); state = 'ready';
      balanceField.value = preview.textContent = rupiah(balance); if (raw) raw.value = String(balance);
    } catch (error) {
      if (request !== generation || error.name === 'AbortError') return;
      state = 'error'; balanceField.value = preview.textContent = 'Saldo belum tersedia';
    }
    validate();
  }
  const original = window.pilihSiswaDatalist;
  window.pilihSiswaDatalist = function(input) { original(input); loadBalance(); };
  amount.addEventListener('input',() => {
    const digits = amount.value.replace(/\D/g,'');
    amount.value = digits ? Number(digits).toLocaleString('id-ID') : ''; validate();
  });
  const warn = (title,message,target) => showSppWarning({code:'savings_validation',title,message,target:target.id},target,true);
  form.addEventListener('submit',event => {
    let problem;
    if (!nis.value) problem = ['Pilih siswa','Pilih siswa dari hasil pencarian sebelum menyimpan.',search];
    else if (state !== 'ready') problem = ['Saldo belum tersedia',state === 'loading' ? 'Tunggu sampai pemeriksaan saldo selesai.' : 'Saldo belum dapat diperiksa. Pilih ulang siswa untuk mencoba lagi.',search];
    else if (!Number.isSafeInteger(value()) || value() <= 0) problem = ['Periksa nominal','Isi nominal tabungan lebih dari nol.',amount];
    else if (withdrawal && value() > balance) problem = ['Saldo tidak mencukupi',`Penarikan ${rupiah(value())} melebihi saldo ${rupiah(balance)}. Kurangi nominal sebesar ${rupiah(value()-balance)}.`,amount];
    else if (form.elements.keterangan.value.length > 255) problem = ['Periksa keterangan','Keterangan maksimal 255 karakter.',form.elements.keterangan];
    if (problem) { event.preventDefault(); validate(); warn(...problem); return; }
    amount.value = String(value());
    // Keep a local draft for a server-side balance rejection; it is cleared after success.
    try { sessionStorage.setItem('savings-form-draft',JSON.stringify({path:location.pathname,unit:form.dataset.unit,nis:nis.value,amount:amount.value,note:form.elements.keterangan.value})); } catch (_) { /* Storage is optional. */ }
  });
  document.addEventListener('DOMContentLoaded',() => {
    const flash = document.getElementById('flash-msg');
    if (flash?.classList.contains('alert-error')) {
      try {
        const draft = JSON.parse(sessionStorage.getItem('savings-form-draft') || 'null');
        if (draft?.path === location.pathname && draft.unit === form.dataset.unit) { search.value = draft.nis; amount.value = Number(draft.amount).toLocaleString('id-ID'); form.elements.keterangan.value = draft.note; }
      } catch (_) { /* A draft is optional. */ }
      warn('Tabungan belum tersimpan',flash.textContent.trim(),amount);
    } else { try { sessionStorage.removeItem('savings-form-draft'); } catch (_) { /* Storage is optional. */ } }
    if (search.value.trim()) window.pilihSiswaDatalist(search);
  });
})();
