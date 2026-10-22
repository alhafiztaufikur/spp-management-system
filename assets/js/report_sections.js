/* Independent PHP-backed tables; regular links remain usable without JavaScript. */
(() => {
  'use strict';
  const form=document.getElementById('print-selected-form');if(!form)return;
  function cleanLegacyParams(url){
    for(const key of [...url.searchParams.keys()])
      if(key==='jenis_laporan'||key.startsWith('jenis_laporan[')||key.startsWith('panel['))url.searchParams.delete(key);
    if(url.searchParams.has('panel'))url.searchParams.set('panel',panelValue(url.searchParams.get('panel')));
    return url;
  }
  // Scope changes also reuse location.search; canonicalize obsolete selectors first.
  const canonical=cleanLegacyParams(new URL(location.href));
  const sort=document.querySelector('.report-general-filter [name=urut]')?.value;
  if(canonical.searchParams.has('urut')&&sort)canonical.searchParams.set('urut',sort);
  if(canonical.href!==location.href)history.replaceState(history.state,'',canonical);
  const results=document.querySelector('[data-finance-panel]'),tabs=[...document.querySelectorAll('[data-finance-tab]')],desktop=matchMedia('(min-width:1100px)');
  function panelValue(value){return ['ringkasan','komponen','transaksi'].includes(value)?value:'ringkasan';}
  function syncLinks(){
    const current=cleanLegacyParams(new URL(location.href)),panel=panelValue(current.searchParams.get('panel'));
    tabs.forEach(tab=>{const url=new URL(current);url.searchParams.set('panel',tab.dataset.financeTab);tab.href=url.href;});
    document.querySelectorAll('a[data-section-page]').forEach(link=>{const url=new URL(link.href);url.searchParams.set('panel',panel);link.href=url.href;});
    document.querySelectorAll('.report-export-actions a').forEach(link=>{const url=new URL(link.href);url.searchParams.set('panel',panel);link.href=url.href;});
    const input=document.querySelector('[data-finance-panel-input]');if(input)input.value=panel;
    const reset=document.querySelector('[data-finance-reset]');if(reset){const url=new URL(reset.href);url.searchParams.set('panel',panel);reset.href=url.href;}
  }
  function applyPanel(){
    if(!results)return;const panel=panelValue(new URL(location.href).searchParams.get('panel'));
    results.dataset.financePanel=panel;
    if(desktop.matches){results.setAttribute('role','tabpanel');results.setAttribute('aria-labelledby','finance-tab-'+panel);results.tabIndex=0;}
    else{results.removeAttribute('role');results.removeAttribute('aria-labelledby');results.removeAttribute('tabindex');}
    tabs.forEach(tab=>{const active=tab.dataset.financeTab===panel;tab.classList.toggle('is-active',active);tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;tab.removeAttribute('aria-current');});syncLinks();
  }
  if(tabs.length){
    document.querySelector('.finance-tabs').setAttribute('role','tablist');desktop.addEventListener('change',applyPanel);
    tabs.forEach(tab=>{
      tab.setAttribute('role','tab');
      tab.addEventListener('click',event=>{if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;event.preventDefault();history.pushState(null,'',tab.href);applyPanel();});
      tab.addEventListener('keydown',event=>{let next=tabs.indexOf(tab);if(event.key==='ArrowRight')next=(next+1)%tabs.length;else if(event.key==='ArrowLeft')next=(next+tabs.length-1)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;event.preventDefault();tabs[next].click();tabs[next].focus();});
    });applyPanel();
  }
  const requests=new Map();
  const checks=()=>[...form.querySelectorAll('.row-print-check:not(:disabled)')];
  function refreshPrint(){
    const selected=checks().filter(check=>check.checked).length,button=document.getElementById('btn-print-selected');
    if(button){button.disabled=!selected;button.querySelector('span').textContent=selected?'Cetak Dipilih ('+selected+')':'Cetak Dipilih';}
    form.querySelectorAll('[data-print-all]').forEach(all=>{const rows=[...all.closest('table').querySelectorAll('.row-print-check:not(:disabled)')],count=rows.filter(row=>row.checked).length;all.disabled=!rows.length;all.checked=!!count&&count===rows.length;all.indeterminate=count>0&&count<rows.length;});
  }
  document.addEventListener('change',event=>{
    if(event.target.matches('[data-print-all]'))event.target.closest('table').querySelectorAll('.row-print-check:not(:disabled)').forEach(check=>check.checked=event.target.checked);
    if(event.target.matches('.row-print-check,[data-print-all]'))refreshPrint();
  });
  form.addEventListener('submit',event=>{if(!checks().some(check=>check.checked)){event.preventDefault();window.showToast?.('!','Pilih minimal satu transaksi untuk dicetak.','error');}});
  function requestUrl(section,link){
    const url=new URL(link,location.href);
    for(const key of [...url.searchParams.keys()])if(key.startsWith('section_page['))url.searchParams.delete(key);
    document.querySelectorAll('[data-report-section]').forEach(node=>url.searchParams.set('section_page['+node.dataset.reportSection+']',node.dataset.page));
    const desired=new URL(link,location.href).searchParams.get('section_page['+section.dataset.reportSection+']')||'1';
    url.searchParams.set('section_page['+section.dataset.reportSection+']',desired);
    url.searchParams.set('fragment','section');url.searchParams.set('section',section.dataset.reportSection);return url;
  }
  function updateUrl(){
    const url=cleanLegacyParams(new URL(location.href));
    document.querySelectorAll('[data-report-section]').forEach(node=>url.searchParams.set('section_page['+node.dataset.reportSection+']',node.dataset.page));
    history.pushState(null,'',url);syncLinks();
  }
  async function load(section,link,push=true){
    const type=section.dataset.reportSection,url=requestUrl(section,link),previous=requests.get(type);
    if(previous?.url===url.href)return previous.promise;
    previous?.controller.abort();const controller=new AbortController(),state={url:url.href,controller,promise:null};requests.set(type,state);
    section.setAttribute('aria-busy','true');const status=section.querySelector('.report-section-status');status.hidden=false;status.textContent='Memuat halaman tabel...';
    state.promise=(async()=>{
      try{
        const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});
        const data=await response.json();
        if(requests.get(type)!==state)return;
        if(!response.ok||!data.ok||data.section!==type||data.filter_key!==section.dataset.filterKey)throw new Error('Invalid report section');
        const template=document.createElement('template');template.innerHTML=data.html.trim();const replacement=template.content.firstElementChild;
        if(replacement?.dataset.reportSection!==type||replacement.dataset.filterKey!==section.dataset.filterKey)throw new Error('Invalid section markup');
        const top=section.getBoundingClientRect().top,scrollY=window.scrollY,left=section.querySelector('.table-container')?.scrollLeft||0;
        section.replaceWith(replacement);const table=replacement.querySelector('.table-container');if(table)table.scrollLeft=left;
        window.scrollTo({top:Math.max(0,scrollY+replacement.getBoundingClientRect().top-top),behavior:'instant'});
        if(push)updateUrl();else syncLinks();refreshPrint();document.dispatchEvent(new CustomEvent('report-section-updated',{detail:{section:type}}));
      }catch(error){
        if(error.name==='AbortError'||requests.get(type)!==state)return;
        section.removeAttribute('aria-busy');status.replaceChildren(document.createTextNode('Tabel belum dapat diperbarui. '));
        const retry=document.createElement('button');retry.type='button';retry.className='btn btn-ghost btn-sm';retry.textContent='Coba lagi';retry.addEventListener('click',()=>load(section,link,push));status.append(retry);
      }finally{if(requests.get(type)===state){requests.delete(type);section.removeAttribute('aria-busy');}}
    })();return state.promise;
  }
  document.addEventListener('click',event=>{
    const link=event.target.closest('[data-report-section] a[data-section-page]');if(!link||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
    event.preventDefault();load(link.closest('[data-report-section]'),link.href);
  });
  addEventListener('popstate',()=>{
    applyPanel();
    const url=new URL(location.href);
    document.querySelectorAll('[data-report-section]').forEach(section=>{
      const type=section.dataset.reportSection,page=url.searchParams.get('section_page['+type+']')||'1';requests.get(type)?.controller.abort();requests.delete(type);
      if(page!==section.dataset.page){const link=new URL(section.querySelector('a[data-section-page]')?.href||location.href);link.searchParams.set('section_page['+type+']',page);load(section,link,false);}
      else{section.removeAttribute('aria-busy');const status=section.querySelector('.report-section-status');status.hidden=true;}
    });
  });
  refreshPrint();
})();
