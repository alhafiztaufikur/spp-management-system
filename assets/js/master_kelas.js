/* Switch presentation only; the same rows and action forms serve both views. */
document.addEventListener('DOMContentLoaded', function () {
  document.documentElement.classList.add('class-workspace-enhanced');
  const tabs = [...document.querySelectorAll('[data-class-tab]')];
  const sections = [...document.querySelectorAll('[data-class-panel]')];
  const fragments = { '#kelola-rombel': 'manage', '#naik-kelas': 'promotion' };
  function activateTab(name, focus) {
    document.querySelector('.class-workspace').dataset.classMode = name;
    document.querySelector('.class-page-heading h1').textContent = name === 'manage' ? 'Kelola Rombel' : 'Master Kelas/Rombel';
    tabs.forEach(tab => {
      const active = tab.dataset.classTab === name;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      if (active && focus) tab.focus();
    });
    sections.forEach(section => { section.hidden = section.dataset.classPanel !== name; });
  }
  if (tabs.length && sections.length) {
    document.querySelector('.class-mode-tabs').setAttribute('role', 'tablist');
    tabs.forEach(tab => {
      tab.id = 'class-tab-' + tab.dataset.classTab;
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-controls', tab.hash.slice(1));
    });
    sections.forEach(section => {
      section.setAttribute('role', 'tabpanel');
      section.setAttribute('aria-labelledby', 'class-tab-' + section.dataset.classPanel);
    });
    const query = new URLSearchParams(location.search);
    const parameters = new Set([...query.keys()].map(key => key.split('[')[0]));
    const managing = ['edit', 'q_kelas', 'tingkat_kelas', 'status_kelas', 'class_per_page', 'class_page'].some(key => parameters.has(key));
    const promoting = ['source_year_id', 'source_level', 'source_rombel', 'source_rombel_filter', 'promotion_q'].some(key => parameters.has(key));
    const initial = fragments[location.hash] || (managing ? 'manage' : promoting ? 'promotion' : 'manage');
    activateTab(initial, false);
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', event => {
        event.preventDefault();
        activateTab(tab.dataset.classTab, false);
        history.replaceState(null, '', tab.hash);
      });
      tab.addEventListener('keydown', event => {
        let target;
        if (event.key === 'ArrowRight') target = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') target = (index + tabs.length - 1) % tabs.length;
        if (event.key === 'Home') target = 0;
        if (event.key === 'End') target = tabs.length - 1;
        if (target === undefined) return;
        event.preventDefault();
        activateTab(tabs[target].dataset.classTab, true);
        history.replaceState(null, '', tabs[target].hash);
      });
    });
    window.addEventListener('hashchange', () => activateTab(fragments[location.hash] || initial, false));
  }

  const panel = document.querySelector('.class-list-panel');
  const controls = panel?.querySelector('.class-view-switch');
  if (!panel || !controls) return;
  const key = 'spp_master_kelas_view';
  function show(view) {
    if (!['groups', 'cards', 'table'].includes(view)) view = 'groups';
    panel.dataset.rombelView = view;
    panel.querySelectorAll('[data-level-group]').forEach(group => {
      if (view !== 'groups') group.open = true;
    });
    controls.querySelectorAll('[data-class-view]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.classView === view));
    });
  }
  let saved = 'groups';
  try { saved = localStorage.getItem(key) || 'groups'; } catch (_) { /* Local storage may be unavailable. */ }
  show(saved);
  controls.hidden = false;
  controls.addEventListener('click', event => {
    const button = event.target.closest('[data-class-view]');
    if (!button) return;
    show(button.dataset.classView);
    try { localStorage.setItem(key, panel.dataset.rombelView); } catch (_) { /* Keep the current view for this page. */ }
  });

  const addForm = document.getElementById('class-rombel-form');
  if (addForm) {
    addForm.open = new URLSearchParams(location.search).has('edit');
    document.querySelectorAll('[data-open-class-form]').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      activateTab('manage', false);
      addForm.open = true;
      addForm.scrollIntoView({block:'start'});
      addForm.querySelector('input[name="kode_rombel"]:not(:disabled)')?.focus({preventScroll:true});
    }));
  }
  const psbSearch = document.getElementById('psb-monitor-search');
  if (psbSearch) {
    const records = [...document.querySelectorAll('[data-psb-search]')];
    const form = document.getElementById('psb-placement-form');
    const checkboxFor = row => row.querySelector('input[name="selected_students[]"]');
    const selected = () => records.filter(row => checkboxFor(row)?.checked);
    function refreshSelection() {
      if (!form) return;
      records.forEach(row => {
        const checked = !!checkboxFor(row)?.checked;
        row.querySelector('[data-psb-target]').disabled = !checked;
        row.classList.toggle('is-selected', checked);
      });
      const count = selected().length;
      document.getElementById('psb-selected-count').textContent = count + ' siswa dipilih';
      document.getElementById('psb-submit-summary').textContent = count ? count + ' siswa siap ditempatkan' : 'Pilih siswa dan rombel tujuan';
      document.getElementById('psb-submit').disabled = !count || form.dataset.submitting === '1';
    }
    psbSearch.addEventListener('input', () => {
      const term = psbSearch.value.trim().toLocaleLowerCase('id-ID');
      let shown = 0;
      records.forEach(record => { record.hidden = !record.dataset.psbSearch.includes(term); if (!record.hidden) shown++; });
      document.getElementById('psb-monitor-count').textContent = shown + ' siswa PSB aktif ditampilkan';
      document.getElementById('psb-monitor-empty').hidden = shown > 0;
    });
    if (form) {
      records.forEach(row => checkboxFor(row).addEventListener('change', refreshSelection));
      document.getElementById('psb-select-visible').addEventListener('click', () => { records.filter(row => !row.hidden).forEach(row => { checkboxFor(row).checked = true; }); refreshSelection(); });
      document.getElementById('psb-select-all').addEventListener('click', () => { records.forEach(row => { checkboxFor(row).checked = true; }); refreshSelection(); });
      document.getElementById('psb-clear').addEventListener('click', () => { records.forEach(row => { checkboxFor(row).checked = false; }); refreshSelection(); });
      form.addEventListener('submit', event => {
        const rows = selected();
        if (!rows.length || form.dataset.submitting === '1') { event.preventDefault(); return; }
        const destinations = rows.map(row => {
          const target = row.querySelector('[data-psb-target]');
          return row.querySelector('strong').textContent + ' (' + checkboxFor(row).value + '): ' + (target.value ? target.selectedOptions[0].textContent : 'Belum dipilih');
        });
        let message = 'Tempatkan ' + rows.length + ' siswa PSB pada TA ' + form.dataset.targetYear + '?\n\n' + destinations.join('\n');
        const missing = rows.filter(row => !row.querySelector('[data-psb-target]').value).length;
        if (missing) message += '\n\n' + missing + ' siswa tanpa rombel tujuan akan dilewati.';
        if (!window.confirm(message)) { event.preventDefault(); return; }
        form.dataset.submitting = '1';
        document.getElementById('psb-submit').disabled = true;
      });
      refreshSelection();
    }
  }
});
