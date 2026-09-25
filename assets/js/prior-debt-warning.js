(function () {
  'use strict';

  function rupiah(value) {
    return 'Rp ' + Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
  }

  function setup() {
    const modal = document.getElementById('prior-debt-modal');
    if (!modal) return;
    (window.priorDebtForms || []).forEach(function (config) {
      const form = document.getElementById(config.id);
      if (!form) return;
      form.dataset.priorDebtForm = '';
      form.dataset.debtScope = config.scope || 'selected';
      form.dataset.debtEndpoint = config.endpoint || 'tagihan_tunggakan_check.php';
      if (!form.querySelector('[name="prior_debt_csrf"]')) {
        const csrf = document.createElement('input');
        csrf.type = 'hidden'; csrf.name = 'prior_debt_csrf'; csrf.value = config.csrf || '';
        form.appendChild(csrf);
      }
      if (!form.querySelector('[name="confirm_previous_debt"]')) {
        const confirmation = document.createElement('input');
        confirmation.type = 'hidden'; confirmation.name = 'confirm_previous_debt'; confirmation.value = '0';
        form.appendChild(confirmation);
      }
    });
    const list = modal.querySelector('[data-prior-debt-list]');
    const count = modal.querySelector('[data-prior-debt-count]');
    const total = modal.querySelector('[data-prior-debt-total]');
    const continueButton = modal.querySelector('[data-prior-debt-continue]');
    let pendingForm = null;

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('prior-debt-modal-open');
      pendingForm = null;
    }

    function showModal(data, form) {
      pendingForm = form;
      count.textContent = Number(data.affected_students || 0).toLocaleString('id-ID') + ' siswa';
      total.textContent = rupiah(data.total_outstanding);
      list.replaceChildren();
      (data.students || []).forEach(function (student) {
        const item = document.createElement('article');
        item.className = 'prior-debt-student';
        const identity = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = student.nama || 'Nama belum tersedia';
        const meta = document.createElement('span');
        meta.textContent = 'NIS ' + (student.nis || '-') + ' | ' + (student.kelas || 'Kelas belum diatur');
        identity.append(name, meta);
        const amount = document.createElement('strong');
        amount.className = 'prior-debt-student__amount';
        amount.textContent = rupiah(student.tunggakan);
        item.append(identity, amount);
        list.appendChild(item);
      });
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('prior-debt-modal-open');
      continueButton.focus();
    }

    modal.querySelectorAll('[data-prior-debt-cancel]').forEach(function (button) {
      button.addEventListener('click', closeModal);
    });
    continueButton.addEventListener('click', function () {
      if (!pendingForm) return;
      const form = pendingForm;
      form.querySelector('[name="confirm_previous_debt"]').value = '1';
      closeModal();
      form.requestSubmit();
    });

    document.querySelectorAll('[data-prior-debt-form]').forEach(function (form) {
      form.addEventListener('submit', async function (event) {
        const confirmation = form.querySelector('[name="confirm_previous_debt"]');
        if (confirmation && confirmation.value === '1') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitButton = event.submitter || form.querySelector('[type="submit"]');
        if (submitButton) submitButton.disabled = true;
        try {
          const payload = new FormData(form);
          payload.set('scope', form.dataset.debtScope || 'selected');
          payload.set('csrf_token', form.querySelector('[name="prior_debt_csrf"]').value);
          const response = await fetch(form.dataset.debtEndpoint || 'tagihan_tunggakan_check.php', {
            method: 'POST', body: payload, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.message || 'Pemeriksaan tunggakan gagal.');
          if (!data.has_debt) {
            confirmation.value = '1';
            form.requestSubmit();
            return;
          }
          showModal(data, form);
        } catch (error) {
          window.alert(error.message || 'Pemeriksaan tunggakan tidak dapat dilakukan.');
        } finally {
          if (submitButton) submitButton.disabled = false;
        }
      }, true);
    });
  }

  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', setup) : setup();
})();
