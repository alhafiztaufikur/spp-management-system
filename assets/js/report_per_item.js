// Period controls and an explicit boundary between selected and applied filters.
document.addEventListener('DOMContentLoaded', () => {
  const category = document.querySelector('[data-report-item-category]');
  const form = category?.closest('form');
  if (!form) return;
  const notice = form.querySelector('[data-report-filter-pending]');
  const exports = document.querySelectorAll('.report-export-btn');
  const monthly = new Set(['spp', 'komite']);
  const annual = new Set(window.sppAnnualReportCategories || []);
  let pending = false;
  function markPending() {
    pending = true;
    if (notice) notice.hidden = false;
    exports.forEach(link => link.setAttribute('aria-disabled', 'true'));
  }
  function syncPeriods() {
    const values = window.sppSelectedValues?.(category) || [category.value];
    const all = values.includes('*');
    const show = {
      month: all || values.some(value => monthly.has(value)),
      'academic-year': all || values.some(value => annual.has(value)),
      date: all || values.some(value => !monthly.has(value) && !annual.has(value)),
    };
    form.classList.toggle('report-per-item-compact', !show.month);
    form.querySelectorAll('[data-per-item-period]').forEach(field => {
      field.style.display = show[field.dataset.perItemPeriod] ? '' : 'none';
    });
    const applied = [...(window.sppAppliedReportCategories || [])].sort();
    if (JSON.stringify([...values].sort()) !== JSON.stringify(applied)) markPending();
  }
  category.addEventListener('change', syncPeriods);
  form.addEventListener('change', event => {
    if (event.target.matches('select,input[name]')) markPending();
  });
  form.addEventListener('input', event => {
    if (event.target.matches('#global-siswa-search,input[name]')) markPending();
  });
  form.addEventListener('click', event => {
    if (event.target.closest('[data-range-apply],[data-month-range-apply],[data-year-range-apply]')) markPending();
  });
  exports.forEach(link => link.addEventListener('click', event => {
    if (!pending) return;
    event.preventDefault();
    notice?.scrollIntoView({block: 'nearest'});
    form.querySelector('[type="submit"]')?.focus({preventScroll: true});
  }));
  syncPeriods();
});
