// Close the student suggestion panel when focus leaves the search control.
function initGlobalReportStudentExit() {
  document.querySelectorAll('[data-student-search]').forEach(input => {
    if (input.dataset.reportExitReady === '1') return;
    input.dataset.reportExitReady = '1';
    input.addEventListener('blur', () => setTimeout(() => {
      const box = input.closest('.student-combobox');
      if (box && !(window.sppFloatingPanels?.contains(box,document.activeElement) ?? box.contains(document.activeElement))) window.closeStudentSearchPanel?.(input);
    }, 0));
  });
}
initGlobalReportStudentExit();

function globalReportOptionsUrl(form, group) {
  const url = new URL(form.dataset.reportOptionsUrl, location.href);
  url.searchParams.set('filter_group', group);
  return url;
}

function initGlobalReportOptions(form) {
  form.querySelectorAll('[data-report-options]').forEach(select => {
    let pending = false;
    select.addEventListener('spp:options-open', async ({detail: {status, refresh}}) => {
      if (pending || select.dataset.reportOptionsLoaded === '1') return;
      pending = true;
      status.hidden = false;
      status.textContent = 'Memuat pilihan historis…';
      const url = globalReportOptionsUrl(form, select.dataset.reportOptions);
      try {
        const response = await fetch(url, {cache:'no-store'});
        if (!response.ok || response.redirected) throw new Error();
        const options = await response.json();
        const existing = new Map(Array.from(select.options, option => [option.value, option]));
        const selected = new Set(Array.from(select.selectedOptions, option => option.value));
        const ordered = [];
        if (existing.has('*')) ordered.push(existing.get('*'));
        existing.delete('*');
        options.forEach(({value,label}) => {
          const option = existing.get(value) || new Option(label,value);
          option.textContent = label;
          ordered.push(option);
          existing.delete(value);
        });
        ordered.push(...existing.values());
        select.replaceChildren(...ordered);
        for (const option of select.options) option.selected = selected.has(option.value);
        select.dataset.reportOptionsLoaded = '1';
        status.hidden = options.length > 0;
        if (!status.hidden) status.textContent = 'Tidak ada pilihan historis.';
        refresh();
        select.dispatchEvent(new Event('spp:options-ready'));
      } catch {
        status.textContent = 'Pilihan historis gagal dimuat. Buka kembali untuk mencoba lagi.';
        refresh();
      } finally {
        pending = false;
      }
    });
  });
}

function initGlobalReportStudentSearch(form) {
  const input = form.querySelector('[data-student-remote]');
  if (!input) return;
  const query = form.querySelector('#global-student-query');
  const list = document.getElementById(input.dataset.studentList);
  const filters = Array.from(form.querySelectorAll('select')).filter(select =>
    ['kelas','siswa_status'].includes(select.name.replace(/\[\]$/,''))
  );
  input.removeAttribute('name');
  input.value = input.dataset.studentDisplay;
  query.disabled = false;
  form.querySelectorAll('[data-student-identity]').forEach(field => field.disabled = false);
  let timer;
  let controller;

  function cancelSearch() {
    clearTimeout(timer);
    controller?.abort();
  }

  function search() {
    cancelSearch();
    list.replaceChildren();
    delete input.dataset.studentResults;
    closeStudentSearchPanel(input);
    const term = input.value.trim();
    if (term.length < 2) return;
    const url = globalReportOptionsUrl(form, 'student');
    url.searchParams.set('q',term);
    filters.forEach(select => {
      const name = select.name.replace(/\[\]$/,'')+'[]';
      Array.from(select.selectedOptions, option => url.searchParams.append(name,option.value));
    });
    const request = new AbortController();
    controller = request;
    timer = setTimeout(async () => {
      try {
        const response = await fetch(url,{signal:request.signal,cache:'no-store'});
        if (!response.ok || response.redirected) throw new Error();
        const students = await response.json();
        if (request.signal.aborted || input.value.trim() !== term) return;
        list.replaceChildren(...students.map(student => {
          const option = new Option(student.name,student.name);
          Object.assign(option.dataset,{
            studentId:student.id,unitId:student.unit,nis:student.nis,
            diknas:student.diknas,nama:student.name,kelas:student.class,
          });
          return option;
        }));
        input.dataset.studentResults = term.toLowerCase();
        if (document.activeElement === input) renderStudentSearchPanel(input);
      } catch {
        if (request.signal.aborted || document.activeElement !== input) return;
        const message = document.createElement('div');
        message.className = 'student-search-empty';
        message.setAttribute('role','status');
        message.textContent = 'Saran siswa gagal dimuat. Kamu tetap bisa mencari lewat Tampilkan Rekap.';
        input._studentSearchPanel.replaceChildren(message);
        input._studentSearchPanel.hidden = false;
        input.setAttribute('aria-expanded','true');
        fitStudentSearchPanel(input);
      }
    }, 180);
  }
  input.addEventListener('input',search);
  input.addEventListener('keydown',event => {
    if (event.key === 'Escape') cancelSearch();
    if (event.key === 'ArrowDown' && !input._studentSearchPanel.hidden) {
      const first = input._studentSearchPanel.querySelector('button');
      if (first) {
        event.preventDefault();
        first.focus();
      }
    }
  });
  input._studentSearchPanel.addEventListener('keydown',event => {
    const choices = Array.from(input._studentSearchPanel.querySelectorAll('button'));
    const index = choices.indexOf(event.target);
    if (index < 0) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const next = index + (event.key === 'ArrowDown' ? 1 : -1);
      (choices[next] || input).focus();
    } else if (event.key === 'Escape') {
      event.preventDefault();
      input.dataset.studentSuppressPanel = '1';
      closeStudentSearchPanel(input);
      input.focus();
    } else if (event.key === 'Enter') {
      event.preventDefault();
      event.target.click();
      input.focus();
    }
  });
  filters.forEach(select => select.addEventListener('change',() => {
    if (form.querySelector('[name="student_id"]')?.value) {
      input.value = query.value;
      syncStudentSearchQueryTarget(input,query.value);
      delete input.dataset.studentSuppressPanel;
    }
    cancelSearch();
    list.replaceChildren();
    delete input.dataset.studentResults;
    closeStudentSearchPanel(input);
  }));
  input.addEventListener('focus',() => {
    if (!input.dataset.studentResults && !input.dataset.studentSuppressPanel) search();
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const workspace = document.getElementById('global-report-workspace');
  if (!workspace) return;
  const placeholder = workspace.querySelector('[data-report-placeholder]');
  const results = workspace.querySelector('[data-report-results]');
  const label = placeholder.querySelector('[data-report-placeholder-label]');
  let submitting = false;

  workspace.addEventListener('submit', event => {
    if (!event.target.matches('.report-global-filter-form') || event.defaultPrevented) return;
    if (submitting) {
      event.preventDefault();
      return;
    }
    submitting = true;
    if (results) results.hidden = true;
    placeholder.hidden = false;
    placeholder.setAttribute('aria-busy', 'true');
    label.textContent = 'Memuat rekap…';
  });

  window.addEventListener('pageshow', event => {
    if (!event.persisted || !submitting) return;
    submitting = false;
    if (results) results.hidden = false;
    placeholder.hidden = Boolean(results);
    placeholder.removeAttribute('aria-busy');
    label.textContent = 'Pilih filter, lalu klik Tampilkan Rekap.';
  });

  const form = workspace.querySelector('.report-global-filter-form');
  if (!form) return;
  initGlobalReportOptions(form);
  initGlobalReportStudentSearch(form);
});

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
