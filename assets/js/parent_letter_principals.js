(() => {
  'use strict';
  const card = document.querySelector('.letter-list-card');
  if (!card) return;
  const panels = [...card.querySelectorAll('[data-letter-signer]')];
  const printLinks = [...card.querySelectorAll('.letter-actions a,.letter-action-cell a')];
  const originalLinks = new Map(printLinks.map(link => [link, link.getAttribute('href')]));
  const printBlocks = new Set();
  function blockPrint(role, blocked) {
    if (blocked) printBlocks.add(role);
    else printBlocks.delete(role);
    card.dataset.principalBusy = String(printBlocks.size > 0);
    printLinks.forEach(link => {
      if (printBlocks.size) {
        link.removeAttribute('href');
        link.setAttribute('aria-disabled', 'true');
      } else if (!link.classList.contains('is-disabled')) {
        link.setAttribute('href', originalLinks.get(link));
        link.removeAttribute('aria-disabled');
      }
    });
  }
  document.addEventListener('click', event => {
    if (printBlocks.size && event.target instanceof Element && event.target.closest('#print-selected,.letter-actions a,.letter-action-cell a')) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  panels.forEach(panel => {
    const role = panel.dataset.letterSigner;
    const element = name => document.getElementById(`letter-${role}-${name}`);
    const config = JSON.parse(element('data').textContent);
    if (panel.dataset.ready !== 'true') {
      if (role === 'principal') blockPrint(role, true);
      return;
    }
    if (config.unit_id === 0) return;

    let state = config.states[0], busy = false, stale = false, action = 'add';
    const select = element('select'), status = element('status'), reload = element('reload');
    const dialog = element('dialog'), form = element('form'), name = element('name');
    const replacement = element('replacement'), save = element('save'), error = element('dialog-error');
    const add = element('add'), edit = element('edit'), remove = element('delete');
    const endpoint = 'surat_orang_tua_kepala.php';
    function feedback(message, failed = false) {
      status.textContent = message;
      status.classList.toggle('is-error', failed);
      status.parentElement.hidden = !message;
    }
    function lock(value) {
      busy = value;
      panel.setAttribute('aria-busy', String(value));
      blockPrint(role, value || stale);
      [select, add, edit, remove, reload, ...form.querySelectorAll('button,input,select')].forEach(control => {
        control.disabled = value;
      });
    }
    function render() {
      select.replaceChildren(...state.names.map(item => new Option(item.name, String(item.id), false, item.id === state.selected_id)));
    }
    function requireReload() {
      stale = true;
      reload.hidden = false;
    }
    async function request(payload) {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 20000);
      try {
        const response = await fetch(endpoint, {
          method: 'POST',
          headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': config.csrf},
          body: JSON.stringify({role, unit_id: config.unit_id, version: state.version, ...payload}),
          signal: controller.signal,
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          if (response.status === 409) requireReload();
          throw new Error(result.message || 'Perubahan belum tersimpan. Silakan coba kembali.');
        }
        state = result.state;
        stale = false;
        reload.hidden = true;
        render();
      } catch (failure) {
        if (failure.name === 'AbortError' || failure instanceof TypeError || failure instanceof SyntaxError) {
          requireReload();
          throw new Error('Status penyimpanan belum dapat dipastikan. Muat ulang daftar sebelum melanjutkan.');
        }
        throw failure;
      } finally {
        clearTimeout(timer);
      }
    }
    select.addEventListener('change', async () => {
      const id = Number(select.value);
      lock(true);
      feedback(`Menyimpan pilihan ${config.label}…`);
      try {
        await request({action: 'select', id});
        feedback('Pilihan tersimpan. Nama ini akan digunakan pada surat baru.');
      } catch (failure) {
        render();
        feedback(failure.message, true);
      } finally {
        lock(false);
      }
    });
    function open(kind) {
      action = kind;
      error.hidden = true;
      const chosen = state.names.find(item => item.id === state.selected_id);
      if (!chosen) return;
      const deleting = kind === 'delete';
      const verbs = {add: 'Tambah', edit: 'Edit', delete: 'Hapus'};
      element('dialog-title').textContent = `${verbs[kind]} ${config.label}`;
      element('dialog-description').textContent = kind === 'add'
        ? 'Nama baru akan langsung dipilih untuk surat unit ini.'
        : deleting
          ? `Hapus “${chosen.name}” dari daftar? Pilih pengganti untuk surat baru.`
          : 'Nama baru berlaku pada surat yang dibuat setelah perubahan ini.';
      element('name-field').hidden = deleting;
      name.required = !deleting;
      name.value = kind === 'edit' ? chosen.name : '';
      element('replacement-field').hidden = !deleting;
      replacement.replaceChildren(new Option('Pilih nama pengganti', ''), ...state.names
        .filter(item => item.id !== state.selected_id).map(item => new Option(item.name, String(item.id))));
      replacement.required = deleting;
      save.textContent = deleting ? 'Hapus & Pilih Pengganti' : 'Simpan';
      save.disabled = deleting && state.names.length < 2;
      if (save.disabled) {
        error.textContent = 'Tambahkan pengganti sebelum menghapus nama terakhir.';
        error.hidden = false;
      }
      dialog.showModal();
      (deleting ? replacement : name).focus();
    }
    add.addEventListener('click', () => open('add'));
    edit.addEventListener('click', () => open('edit'));
    remove.addEventListener('click', () => open('delete'));
    form.querySelectorAll('.letter-principal-close,.letter-principal-cancel').forEach(button => {
      button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('cancel', event => {
      if (busy) event.preventDefault();
    });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (busy || save.disabled || !form.reportValidity()) return;
      error.hidden = true;
      lock(true);
      save.textContent = 'Menyimpan…';
      try {
        await request({action, id: state.selected_id, name: name.value, replacement_id: Number(replacement.value)});
        dialog.close();
        feedback({
          add: 'Nama ditambahkan dan dipilih untuk surat baru.',
          edit: 'Nama penanda tangan diperbarui.',
          delete: 'Nama dihapus dan pengganti tersimpan.',
        }[action]);
      } catch (failure) {
        error.textContent = failure.message;
        error.hidden = false;
        feedback(failure.message, true);
      } finally {
        lock(false);
        save.textContent = action === 'delete' ? 'Hapus & Pilih Pengganti' : 'Simpan';
      }
    });
    reload.addEventListener('click', async () => {
      lock(true);
      feedback('Memuat daftar penanda tangan…');
      try {
        const response = await fetch(`${endpoint}?role=${role}`, {headers: {Accept: 'application/json'}, cache: 'no-store'});
        const result = await response.json();
        const fresh = result.states?.find(item => item.unit_id === config.unit_id);
        if (!response.ok || !result.ok || !fresh) throw new Error('Unit aktif berubah atau sesi berakhir. Pilih kembali unit asal dan muat ulang halaman.');
        state = fresh;
        stale = false;
        reload.hidden = true;
        render();
        feedback('Daftar diperbarui. Silakan ulangi perubahan bila diperlukan.');
        if (dialog.open) dialog.close();
      } catch (failure) {
        feedback(failure.message, true);
      } finally {
        lock(false);
      }
    });
  });
})();
