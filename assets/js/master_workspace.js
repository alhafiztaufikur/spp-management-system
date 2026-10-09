/* Estimates are previews only. The existing POST handler remains authoritative. */
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('du-rate-form');
  if (!form) return;
  const cards = Array.from(form.querySelectorAll('[data-du-rate-card]'));
  const money = amount => 'Rp ' + amount.toLocaleString('id-ID');
  const update = () => {
    let total = 0;
    cards.forEach(card => {
      const input = card.querySelector('.rupiah-input');
      const rate = Number((input.value || '').replace(/\D/g, '')) || 0;
      const estimate = rate * Number(card.dataset.students || 0);
      card.querySelector('[data-du-estimate]').textContent = money(estimate);
      total += estimate;
    });
    const output = form.querySelector('[data-du-total-estimate]');
    if (output) output.textContent = money(total);
  };
  form.addEventListener('input', update);
  update();
});
