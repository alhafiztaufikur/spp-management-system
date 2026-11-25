(() => {
  const dialog = document.getElementById('master-year-limit');
  const select = document.querySelector('[data-master-year-choice]');
  const maxYear = dialog.dataset.maxYear;
  let trigger = null;

  if (dialog.hasAttribute('data-open-direct')) dialog.showModal();

  select?.addEventListener('change', () => {
    if (select.value <= maxYear) {
      select.form.requestSubmit();
      return;
    }
    select.value = select.dataset.masterYearChoice;
    trigger = select.parentElement.querySelector('.spp-select-trigger') || select;
    dialog.showModal();
  });

  document.querySelectorAll('[data-master-year-link]').forEach(link => {
    link.addEventListener('click', event => {
      if (link.dataset.masterYearLink <= maxYear || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      trigger = link;
      dialog.showModal();
    });
  });

  dialog.addEventListener('cancel', event => {
    if (dialog.hasAttribute('data-open-direct')) event.preventDefault();
  });
  dialog.addEventListener('close', () => trigger?.focus());
})();
