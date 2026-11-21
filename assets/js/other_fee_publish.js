(() => {
  'use strict';
  const form = document.getElementById('fee-publish-form');
  if (!form) return;
  const field = (id) => document.getElementById('fee-publish-' + id);
  const target = field('target');
  const master = field('master');
  const picker = field('picker');
  const list = field('ids');
  const search = field('search');
  const count = field('count');
  const error = field('error');
  const retry = field('retry');
  const prev = field('prev');
  const next = field('next');
  const selectedIds = new Set();
  const pageSize = 40;
  let rosterRequest, roster, classesById, studentsPerClass;
  let matches = [], page = 1;

  function setError(message = '', retryable = false) {
    error.hidden = message === '';
    error.textContent = message;
    retry.hidden = !retryable;
  }

  function loadRoster() {
    rosterRequest ??= fetch(form.dataset.rosterUrl, {cache: 'no-store'})
      .then(async response => {
        if (!response.ok || response.redirected) throw new Error('Roster unavailable');
        roster = await response.json();
        classesById = new Map(roster.classes.map(row => [row.id, row.label]));
        studentsPerClass = new Map();
        roster.students.forEach(row => {
          row.searchLabel = [row.name, row.nis, classesById.get(row.class_id)].join(' ').toLocaleLowerCase('id-ID');
          studentsPerClass.set(row.class_id, (studentsPerClass.get(row.class_id) ?? 0) + 1);
        });
        roster.classes.forEach(row => { row.searchLabel = row.label.toLocaleLowerCase('id-ID'); });
        return roster;
      })
      .catch(error => { rosterRequest = undefined; roster = undefined; throw error; });
    return rosterRequest;
  }

  function render() {
    if (!roster || target.value === 'all') return;
    const isStudent = target.value === 'siswa';
    const options = isStudent ? roster.students : roster.classes;
    const query = search.value.toLocaleLowerCase('id-ID').trim();
    matches = options.filter(row => row.searchLabel.includes(query));
    const pages = Math.max(1, Math.ceil(matches.length / pageSize));
    page = Math.min(page, pages);
    field('pages').hidden = pages === 1;
    field('page').textContent = page + ' / ' + pages;
    prev.disabled = page === 1;
    next.disabled = page === pages;
    list.replaceChildren();
    for (const row of matches.slice((page - 1) * pageSize, page * pageSize)) {
      const label = document.createElement('label');
      label.className = 'fee-picker-row';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.value = row.id;
      input.checked = selectedIds.has(row.id);
      label.classList.toggle('is-selected', input.checked);
      input.addEventListener('change', () => {
        if (input.checked) selectedIds.add(row.id);
        else selectedIds.delete(row.id);
        label.classList.toggle('is-selected', input.checked);
        updateCount();
      });
      const text = document.createElement('span');
      const name = document.createElement('strong');
      const meta = document.createElement('small');
      name.textContent = isStudent ? row.name : row.label;
      meta.textContent = isStudent
        ? 'NIS ' + row.nis + ' · ' + (classesById.get(row.class_id) || 'Rombel belum tercatat')
        : (studentsPerClass.get(row.id) || 0) + ' siswa sasaran';
      text.append(name, meta);
      label.append(input, text);
      list.append(label);
    }
    if (!matches.length) {
      const empty = document.createElement('p');
      empty.className = 'fee-picker-empty';
      empty.textContent = options.length ? 'Tidak ada sasaran yang cocok.' : 'Belum ada sasaran tersedia.';
      list.append(empty);
    }
    list.scrollTop = 0;
    updateCount();
  }

  function updateCount() {
    count.textContent = selectedIds.size + ' terpilih · ' + matches.length + ' hasil';
    field('all').disabled = matches.length === 0;
    field('clear').disabled = selectedIds.size === 0;
    setError();
  }

  async function showRoster() {
    const mode = target.value;
    picker.hidden = mode === 'all';
    setError();
    if (mode === 'all') return;
    list.replaceChildren();
    field('pages').hidden = true;
    search.disabled = true;
    field('all').disabled = true;
    field('clear').disabled = true;
    count.textContent = 'Memuat sasaran…';
    try {
      await loadRoster();
      if (target.value !== mode) return;
      search.disabled = false;
      render();
    } catch {
      if (target.value !== mode) return;
      count.textContent = '';
      setError('Daftar sasaran belum dapat dimuat.', true);
    }
  }

  target.addEventListener('change', () => {
    selectedIds.clear();
    matches = [];
    search.value = '';
    page = 1;
    showRoster();
  });
  search.addEventListener('input', () => { page = 1; render(); });
  prev.addEventListener('click', () => { page--; render(); if (prev.disabled) next.focus(); });
  next.addEventListener('click', () => { page++; render(); if (next.disabled) prev.focus(); });
  field('all').addEventListener('click', () => { matches.forEach(row => selectedIds.add(row.id)); render(); });
  field('clear').addEventListener('click', () => { selectedIds.clear(); render(); search.focus(); });
  retry.addEventListener('click', () => target.value === 'all' ? form.requestSubmit() : showRoster());

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (form.dataset.submitting === '1' || !form.reportValidity()) return;
    const mode = target.value;
    const selected = master.selectedOptions[0];
    if (!selected?.dataset.version) return;
    const ids = new Set(selectedIds);
    form.dataset.submitting = '1';
    try {
      await loadRoster();
      if (target.value !== mode || master.selectedOptions[0] !== selected) return;
      const students = roster.students.filter(row => mode === 'all' || ids.has(mode === 'rombel' ? row.class_id : row.id));
      if (!students.length) {
        setError('Pilih sasaran yang memiliki siswa aktif.');
        list.querySelector('input')?.focus();
        return;
      }
      const choices = mode === 'rombel' ? roster.classes : roster.students;
      const selection = choices.filter(row => ids.has(row.id)).map(row => row.label ?? row.name);
      const rows = [
        selected.textContent,
        students.length + ' siswa sasaran',
        'Maksimal nilai tagihan Rp ' + (students.length * Number(selected.dataset.nominal)).toLocaleString('id-ID'),
        ...selection.slice(0, 8),
      ];
      if (selection.length > 8) rows.push((selection.length - 8) + ' sasaran lainnya');
      setError();
      const confirmed = await window.workflowConfirm({
        title: 'Konfirmasi Penerbitan',
        message: 'Siswa yang sudah memiliki tagihan master ini akan dilewati.',
        rows,
        action: 'Terbitkan Tagihan',
      }, event.submitter);
      if (!confirmed) return;
      field('version').value = selected.dataset.version;
      field('target-ids').value = JSON.stringify(Array.from(ids));
      field('expected-ids').value = JSON.stringify(students.map(row => row.id));
      form.querySelector('button[type="submit"]').disabled = true;
      HTMLFormElement.prototype.submit.call(form);
    } catch {
      setError('Pratinjau sasaran belum dapat dimuat.', true);
    } finally {
      delete form.dataset.submitting;
    }
  });
  if (!target.disabled && target.value !== 'all') showRoster();
})();
