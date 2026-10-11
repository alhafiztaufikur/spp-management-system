// Close the student suggestion panel when focus leaves the search control.
(() => {
  document.querySelectorAll('[data-student-search]').forEach(input => {
    input.addEventListener('blur', () => setTimeout(() => {
      const box = input.closest('.student-combobox');
      if (box && !box.contains(document.activeElement)) window.closeStudentSearchPanel?.(input);
    }, 0));
  });
})();

// Keep sticky offsets tied to rendered column widths, including font/viewport changes.
(() => {
  function prepareTable(table) {
    const matrix = table.classList.contains('report-horizontal-matrix');
    const billing = table.classList.contains('report-billing-matrix-table');
    const headers = Array.from(table.tHead?.rows[0]?.cells || []);
    if (!matrix && !billing && !table.closest('.report-template-table-wrap')) return;
    if (!matrix) {
      table.classList.add('global-layout-table');
      if (!table.querySelector('colgroup')) {
        const cols = document.createElement('colgroup');
        const sizes = headers.map((h, i) => billing ? [58,172,210,90][i] || (i===headers.length-1?170:154) : Math.max(h.classList.contains('report-col-date')?184:0, Math.ceil(h.getBoundingClientRect().width)));
        sizes.forEach(width => {const col=document.createElement('col');col.style.width=width+'px';cols.append(col);});
        table.prepend(cols);table.style.setProperty('width','100%','important');
        table.style.setProperty('min-width',sizes.reduce((a,b)=>a+b,0)+'px','important');
      }
      headers.forEach((h,i)=>{
        const lastIdentity=headers.findIndex(cell=>/^Kelas$/i.test(cell.textContent.trim()));
        const pin=billing?i<3:(i<=Math.max(0,lastIdentity));
        if (!pin) return;
        [h,...Array.from(table.tBodies[0]?.rows || []).map(r=>r.cells.length===headers.length?r.cells[i]:null)].filter(Boolean).forEach(cell=>{
          cell.classList.add('global-layout-identity');if (/^NIS$/i.test(h.textContent.trim()))cell.classList.add('global-layout-nis');
        });
      });
    }
    const cols=Array.from(table.querySelectorAll(':scope > colgroup > col'));
    const bodyRows=Array.from(table.tBodies[0]?.rows || []),footerRows=Array.from(table.tFoot?.rows || []);
    const identityCells=cols.map((_,i)=>[headers[i],...bodyRows.map(r=>r.cells[i]),...footerRows.map(r=>r.cells[i])].filter(c=>c?.matches('.matrix-identity,.global-layout-identity')));
    let previousLayout='';
    const update = () => {
      // Read all geometry before changing styles; alternating reads/writes causes
      // a full layout of wide preview tables once for every column.
      const widths=cols.map(col=>col.getBoundingClientRect().width);
      const parentWidth=table.parentElement.clientWidth;
      const signature=parentWidth+':'+widths.join(',');
      if(signature===previousLayout)return;
      previousLayout=signature;
      let left=0;
      const pinned=Array.from(table.tHead.querySelectorAll('.matrix-identity,.global-layout-identity'));
      const pinWidth=pinned.reduce((sum,c)=>sum+c.getBoundingClientRect().width,0);
      table.parentElement.classList.toggle('global-layout-narrow',parentWidth<pinWidth+155);
      widths.forEach((width,i)=>{
        identityCells[i].forEach(c=>{c.style.setProperty('--matrix-left',left+'px');c.style.setProperty('--matrix-width',width+'px');});
        left+=width;
      });
    };
    update();const observer=new ResizeObserver(update);observer.observe(table);observer.observe(table.parentElement);
  }
  document.querySelectorAll('#global-report-workspace table').forEach(prepareTable);
})();
