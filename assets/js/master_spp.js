/* View controls only. Existing form names and publishing checks stay authoritative. */
document.addEventListener('DOMContentLoaded', function () {
  const tabs = [...document.querySelectorAll('.spp-workspace-tabs [data-spp-tab]')];
  const panels = [...document.querySelectorAll('[data-spp-panel]')];
  const panelNames = new Set(panels.map(panel => panel.dataset.sppPanel));
  function activate(name, focus) {
    if (!panelNames.has(name)) name = 'students';
    tabs.forEach(tab => { const active = tab.dataset.sppTab === name; tab.classList.toggle('is-active', active); tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; });
    panels.forEach(panel => { panel.hidden = panel.dataset.sppPanel !== name; });
    if (focus) tabs.find(tab => tab.dataset.sppTab === name)?.focus();
  }
  document.querySelector('.spp-workspace-tabs')?.setAttribute('role', 'tablist');
  tabs.forEach(tab => { tab.id = 'spp-tab-' + tab.dataset.sppTab; tab.setAttribute('role', 'tab'); tab.setAttribute('aria-controls', tab.hash.slice(1)); });
  panels.forEach(panel => { panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', 'spp-tab-' + panel.dataset.sppPanel); });
  document.querySelectorAll('[data-spp-tab]').forEach(tab => tab.addEventListener('click', event => {
    event.preventDefault(); activate(tab.dataset.sppTab, false);
    history.replaceState(null, '', tab.hash);
  }));
  tabs.forEach((tab, index) => tab.addEventListener('keydown', event => {
    let target;
    if (event.key === 'ArrowRight') target = (index + 1) % tabs.length;
    if (event.key === 'ArrowLeft') target = (index + tabs.length - 1) % tabs.length;
    if (event.key === 'Home') target = 0;
    if (event.key === 'End') target = tabs.length - 1;
    if (target === undefined) return;
    event.preventDefault(); activate(tabs[target].dataset.sppTab, true); history.replaceState(null, '', tabs[target].hash);
  }));
  const fromHash = panels.find(panel => '#' + panel.id === location.hash);
  activate(fromHash?.dataset.sppPanel || 'students', false);
  window.addEventListener('hashchange', () => {
    const target = panels.find(panel => '#' + panel.id === location.hash);
    activate(target?.dataset.sppPanel || 'students', false);
  });
  document.querySelectorAll('.rupiah-input').forEach(input => input.addEventListener('input', () => {
    const n = input.value.replace(/\D/g, ''); input.value = n ? Number(n).toLocaleString('id-ID') : '';
  }));
  document.getElementById('spp-rate-form')?.addEventListener('submit', event => event.currentTarget.querySelectorAll('.rupiah-input').forEach(input => { input.value = input.value.replace(/\./g, ''); }));
  const rows = [...document.querySelectorAll('.spp-publish-row')];
  const search = document.getElementById('spp-student-search');
  const filter = document.getElementById('spp-rombel-filter');
  const count = document.getElementById('spp-selected-count');
  const submit = document.getElementById('spp-submit-button');
  function refresh() {
    let visible = 0, selected = 0;
    const term = search.value.toLowerCase(), rombels = window.sppSelectedValues(filter);
    rows.forEach(row => {
      const show = (!term || row.dataset.search.includes(term)) && rombels.includes(row.dataset.rombel);
      const checked = row.querySelector('input[type=checkbox]').checked;
      row.hidden = !show; row.classList.toggle('is-selected', checked);
      if (show) visible++; if (checked) selected++;
    });
    document.getElementById('spp-visible-count').textContent = visible + ' siswa ditampilkan';
    count.textContent = selected + ' siswa dipilih';
    document.getElementById('spp-submit-summary').textContent = selected ? selected + ' siswa siap diterbitkan' : 'Belum ada siswa dipilih';
    document.getElementById('spp-search-empty').hidden = visible > 0 || rows.length === 0;
    if (submit) submit.disabled = !selected || submit.closest('form').hidden;
  }
  search?.addEventListener('input', refresh); filter?.addEventListener('change', refresh);
  rows.forEach(row => row.querySelector('input[type=checkbox]').addEventListener('change', refresh));
  document.getElementById('spp-select-visible')?.addEventListener('click', () => { rows.filter(row => !row.hidden).forEach(row => { row.querySelector('input').checked = true; }); refresh(); });
  document.getElementById('spp-clear-selection')?.addEventListener('click', () => { rows.forEach(row => { row.querySelector('input').checked = false; }); refresh(); });
  refresh(); autoHideFlash();
});
