(() => {
  document.querySelectorAll('form[data-savings-submit]').forEach(form => {
    const reset = () => {
      delete form.dataset.saving;
      form.removeAttribute('aria-busy');
      form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => {
        button.disabled = false;
        if (button.dataset.originalLabel) button.textContent = button.dataset.originalLabel;
      });
    };
    form.addEventListener('submit', event => {
      if (form.dataset.saving) { event.preventDefault(); return; }
      if (!form.checkValidity()) return;
      form.dataset.saving = '1';
      form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => {
        button.dataset.originalLabel = button.textContent;
        button.textContent = 'Menyimpan…';
        button.disabled = true;
      });
    });
    window.addEventListener('pageshow', reset);
  });
})();
