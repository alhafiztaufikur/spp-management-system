/* Paginate at the real paper width. Chromium may omit tall repeating table heads. */
(() => {
  'use strict';
  const clean = () => {
    document.querySelectorAll('.receipt-print-page').forEach(page => page.remove());
    document.querySelectorAll('.receipt-original').forEach(page => page.classList.remove('receipt-original'));
  };
  window.addEventListener('beforeprint', () => {
    clean();
    const measure = document.createElement('div');
    measure.style.cssText = 'position:absolute;left:-10000px;top:0;visibility:hidden;width:195.9mm;';
    const ruler = document.createElement('div');ruler.style.height = '129.7mm';measure.append(ruler);document.body.append(measure);
    const available = ruler.getBoundingClientRect().height - 2;ruler.remove();
    document.querySelectorAll('.receipt-sheet').forEach(original => {
      const source = original.querySelector('.receipt-document');
      const pages = [];
      let page, table, body;
      const next = () => {
        page = document.createElement('article');page.className = 'receipt-print-page';
        table = source.cloneNode(false);
        table.append(source.querySelector('colgroup').cloneNode(true), source.querySelector('thead').cloneNode(true));
        body = document.createElement('tbody');table.append(body);page.append(table);measure.append(page);pages.push(page);
      };
      next();
      source.querySelectorAll('.receipt-line,.receipt-closing').forEach(row => {
        const copy = row.cloneNode(true);body.append(copy);
        if (table.getBoundingClientRect().height > available && body.children.length > 1) {
          copy.remove();next();body.append(copy);
        }
      });
      original.classList.add('receipt-original');
      if (original.classList.contains('receipt-last')) pages[pages.length - 1].classList.add('receipt-last');
      let previous = original;
      pages.forEach(part => {previous.after(part);previous = part;});
    });
    measure.remove();
  });
  window.addEventListener('afterprint', clean);
})();
