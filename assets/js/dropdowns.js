/* Shared viewport geometry for selects, legacy pickers and search/range panels. */
(() => {
  const mounted = new Map(); let formSequence = 0;
  const supportsPopover = typeof HTMLElement.prototype.showPopover === 'function'
    && typeof HTMLElement.prototype.hidePopover === 'function'
    && typeof CSS !== 'undefined' && CSS.supports('selector(:popover-open)');
  function viewport() {
    const v = window.visualViewport;
    const left = v ? v.offsetLeft : 0, top = v ? v.offsetTop : 0;
    return {left, top, right:left + (v ? v.width : document.documentElement.clientWidth),
      bottom:top + (v ? v.height : window.innerHeight)};
  }
  function contains(owner, target, seen = new Set()) {
    if (!owner || !target || seen.has(target)) return false;
    if (owner.contains(target)) return true;
    seen.add(target);
    for (const record of mounted.values()) {
      if (record.panel.contains(target) && contains(owner, record.button, seen)) return true;
    }
    return false;
  }
  function isPopoverOpen(panel) {
    return supportsPopover && panel.hasAttribute('popover') && panel.matches(':popover-open');
  }
  function position(panel, button, options = {}) {
    if (panel.hidden || !button.isConnected) return;
    const r = button.getBoundingClientRect(), v = viewport(), margin = 8, gap = 6;
    const width = Math.max(0, Math.min(Math.max(r.width, options.minWidth || 260), v.right-v.left-margin*2));
    const set = (key, value) => panel.style.setProperty(key, value, 'important');
    set('position', 'fixed'); set('inset', 'auto'); set('margin', '0'); set('box-sizing', 'border-box');
    set('min-width', '0'); set('max-width', width+'px'); set('width', width+'px');
    set('right', 'auto'); set('bottom', 'auto'); set('transform', 'none');
    set('max-height', Math.max(0, v.bottom-v.top-margin*2)+'px');
    const natural = Math.min(options.maxHeight || 440, panel.scrollHeight + 2);
    const below = Math.max(0, v.bottom-margin-r.bottom-gap), above = Math.max(0, r.top-v.top-margin-gap);
    const up = below < natural && above > below;
    const height = Math.min(options.maxHeight || 440, up ? above : below);
    set('max-height', height+'px');
    const left = Math.max(v.left+margin, Math.min(r.left, v.right-margin-width));
    const measuredHeight = panel.getBoundingClientRect().height;
    const top = Math.max(v.top+margin, Math.min(up ? r.top-gap-measuredHeight : r.bottom+gap, v.bottom-margin-measuredHeight));
    set('left', left+'px'); set('top', top+'px');
    // A modal itself can establish a fixed containing block. Compensate using
    // its actual rendered coordinates rather than assuming viewport origin.
    const actual = panel.getBoundingClientRect();
    if (Math.abs(actual.left-left) > .25) set('left', (left+left-actual.left)+'px');
    if (Math.abs(actual.top-top) > .25) set('top', (top+top-actual.top)+'px');
    panel.dataset.sppPlacement = up ? 'top' : 'bottom';
  }
  function hide(panel) {
    const record = mounted.get(panel);
    if (!record) return;
    for (const child of [...mounted.values()]) {
      if (child !== record && contains(panel, child.button)) dismiss(child);
    }
    mounted.delete(panel);
    if (isPopoverOpen(panel)) panel.hidePopover();
    panel.removeAttribute('data-spp-floating-open');
    if (record.marker) {
      if (record.marker.parentNode) record.marker.replaceWith(panel);
      record.host.remove();
      for (const [key, value, priority] of record.inherited) {
        value ? panel.style.setProperty(key, value, priority) : panel.style.removeProperty(key);
      }
      for (const [control, form] of record.forms) {
        form === null ? control.removeAttribute('form') : control.setAttribute('form', form);
      }
    }
  }
  function dismiss(record) {
    record.options.onClose?.();
    if (mounted.has(record.panel)) { record.panel.hidden = true; hide(record.panel); }
  }
  function show(panel, button, options = {}) {
    let record = mounted.get(panel);
    if (!record) {
      record = {panel, button, options, inherited:[], forms:[]};
      mounted.set(panel, record);
      panel.classList.add('spp-floating-panel');
      if (supportsPopover) {
        panel.setAttribute('popover', 'manual');
      } else {
        panel.removeAttribute('popover');
        record.marker = document.createComment('dropdown origin');
        panel.before(record.marker);
        record.host = document.createElement('div'); record.host.className = 'spp-floating-root';
        (button.closest('dialog[open]') || document.body).append(record.host);
        // Preserve scoped unit/theme tokens when escaping the original card.
        const style = getComputedStyle(button);
        for (const key of style) if (key.startsWith('--')) {
          record.inherited.push([key, panel.style.getPropertyValue(key), panel.style.getPropertyPriority(key)]);
          panel.style.setProperty(key, style.getPropertyValue(key));
        }
        for (const control of panel.querySelectorAll('input,select,textarea,button')) {
          if (!control.form) continue;
          const form = control.form;
          if (!form.id) { do { form.id = 'spp-floating-form-'+(++formSequence); } while (document.getElementById(form.id) !== form); }
          record.forms.push([control, control.getAttribute('form')]);
          control.setAttribute('form', form.id);
        }
        record.host.append(panel);
      }
      let previous = '';
      function frame() {
        if (mounted.get(panel) !== record) return;
        const r = button.getBoundingClientRect(), v = viewport();
        if (!button.isConnected || !button.getClientRects().length || r.bottom <= v.top || r.top >= v.bottom || r.right <= v.left || r.left >= v.right || button.closest('dialog:not([open])')) { dismiss(record); return; }
        if (panel.hidden) { hide(panel); return; }
        const signature = [r.left,r.top,r.width,r.height,v.left,v.top,v.right,v.bottom,panel.scrollHeight].join('|');
        if (signature !== previous) { position(panel, button, record.options); previous = signature; }
        requestAnimationFrame(frame);
      }
      requestAnimationFrame(frame);
    }
    record.options = options;
    panel.hidden = false;
    panel.dataset.sppFloatingOpen = '';
    if (supportsPopover && !isPopoverOpen(panel)) panel.showPopover();
    position(panel, button, options);
  }
  window.sppFloatingPanels = {show, hide, position, contains, viewport};
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || event.defaultPrevented) return;
    const record = [...mounted.values()].reverse().find(r=>contains(r.panel,event.target)||r.button.contains(event.target));
    if (!record) return;
    dismiss(record); record.button.focus({preventScroll:true}); event.preventDefault();
  });
})();

/* Keep native controls as the submitted, validated source of truth. */
(() => {
  let sequence=0, active=null;const instances=new Set(),enhanced=new WeakMap();
  const icon='<svg class="spp-select-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>';
  function position(panel,button){
    window.sppFloatingPanels.position(panel,button,panel.classList.contains('du-selector-menu')&&matchMedia('(min-width:769px)').matches?{minWidth:520}:{});
  }
  function controlLabel(select){
    const copy=(select.labels?.[0]||select.closest('.field-row')?.querySelector('label'))?.cloneNode(true);
    copy?.querySelectorAll('select,.spp-select,button,input').forEach(n=>n.remove());
    const labels={tahun_ajaran:'Tahun ajaran',kelas:'Kelas',tingkat_kelas:'Tingkat',status_kelas:'Status',saldo_kelas:'Kelas',saldo_status:'Saldo',status:'Status',siswa_status:'Status siswa',kategori:'Kategori',source_rombel_filter:'Rombel asal'};
    return select.dataset.dropdownLabel||copy?.textContent.trim().replace(/\s+/g,' ')||labels[select.name.replace('[]','')]||select.getAttribute('aria-label')?.replace(/^Filter\s+/i,'')||select.name.replace(/\[\]/g,'').replace(/_/g,' ')||'pilihan';
  }
  function modeNote(multiple){const note=document.createElement('div');note.className='spp-dropdown-note';note.textContent=multiple?'Bisa pilih beberapa opsi':'Pilih satu opsi';return note;}
  function preservePointerFocus(button){button.addEventListener('mousedown',event=>{if(event.button===0){event.preventDefault();button.focus({preventScroll:true});}});}
  function setCaption(caption,value){if(caption.textContent!==value)caption.textContent=value;}
  function identify(wrap,select){wrap.dataset.control=select.name.replace('[]','');}
  function watchAnchor(record){
    window.sppFloatingPanels.show(record.panel,record.button,{...(record.panel.classList.contains('du-selector-menu')&&matchMedia('(min-width:769px)').matches?{minWidth:520}:{}),onClose:()=>{if(active===record)close();}});
  }
  function close(restore=false){if(!active)return;const old=active;active=null;old.close();if(restore){old.button.focus({preventScroll:true});old.close();}}
  function focusOption(item,panel){
    if(!item||item.disabled){panel?.focus({preventScroll:true});return;}item.focus({preventScroll:true});
    const r=item.getBoundingClientRect(),p=panel.getBoundingClientRect(),footer=panel.querySelector('.spp-multi-footer')?.getBoundingClientRect().height||0;
    if(r.top<p.top+8)panel.scrollTop-=p.top+8-r.top;
    else if(r.bottom>p.bottom-footer-8)panel.scrollTop+=r.bottom-p.bottom+footer+8;
  }
  function keyboard(event,items,onEscape){const enabled=items.filter(i=>!i.disabled&&!i.hidden);const index=enabled.indexOf(document.activeElement);if(event.key==='Escape'){event.preventDefault();event.stopPropagation();onEscape();return;}if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)){event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?enabled.length-1:(index+(event.key==='ArrowUp'?-1:1)+enabled.length)%enabled.length;focusOption(enabled[next],enabled[next]?.closest('.spp-dropdown-panel'));}}
  function enhanceMulti(select){
    const wrap=document.createElement('span');wrap.className='spp-select';select.before(wrap);wrap.append(select);identify(wrap,select);select.classList.add('spp-select-native');select.tabIndex=-1;select.setAttribute('aria-hidden','true');
    const label=controlLabel(select);
    const button=document.createElement('button');button.type='button';button.className='field-input spp-select-trigger';preservePointerFocus(button);const caption=document.createElement('span');caption.className='spp-select-caption';button.append(caption);button.insertAdjacentHTML('beforeend',icon);wrap.append(button);
    const panel=document.createElement('div');panel.id='spp-multi-menu-'+(++sequence);panel.className='spp-dropdown-panel spp-multi-panel';panel.hidden=true;panel.tabIndex=-1;panel.setAttribute('role','dialog');panel.setAttribute('aria-label',label);wrap.append(panel);
    button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls',panel.id);button.setAttribute('aria-expanded','false');
    const allRow=document.createElement('label'),all=document.createElement('input'),allText=document.createElement('span'),list=document.createElement('div'),footer=document.createElement('div'),count=document.createElement('span'),cancel=document.createElement('button'),apply=document.createElement('button');
    all.type='checkbox';allRow.className='spp-multi-all';allRow.append(all,allText);footer.className='spp-multi-footer';count.setAttribute('aria-live','polite');cancel.type=apply.type='button';cancel.textContent='Batal';apply.textContent='Terapkan';apply.className='spp-multi-apply';footer.append(count,cancel,apply);panel.append(modeNote(true),allRow,list,footer);let draft=new Set(),checks=[];
    const search=select.dataset.selectSearch==='true'?document.createElement('input'):null;
    if(search){search.type='search';search.className='spp-multi-search';search.placeholder='Cari operator...';search.setAttribute('aria-label','Cari operator');allRow.before(search);search.addEventListener('input',()=>{const term=search.value.trim().toLocaleLowerCase('id');list.querySelectorAll('.spp-multi-option').forEach(row=>row.hidden=!row.textContent.toLocaleLowerCase('id').includes(term));let empty=list.querySelector('[data-search-empty]');if(!empty){empty=document.createElement('p');empty.className='spp-dropdown-empty';empty.dataset.searchEmpty='';empty.textContent='Tidak ada operator yang cocok.';list.append(empty);}empty.hidden=[...list.querySelectorAll('.spp-multi-option')].some(row=>!row.hidden);position(panel,button);});}
    const available=()=>Array.from(select.options).filter(o=>o.value!=='*'&&!o.hidden&&!o.parentElement.hidden);
    const enabled=()=>available().filter(o=>!o.disabled&&!(o.parentElement.tagName==='OPTGROUP'&&o.parentElement.disabled));
    const committed=()=>select.selectedOptions.length&&Array.from(select.selectedOptions).some(o=>o.value==='*')?new Set(enabled().map(o=>o.value)):new Set(Array.from(select.selectedOptions).filter(o=>enabled().includes(o)).map(o=>o.value));
    function summary(){const choices=available().filter(o=>committed().has(o.value)),isAll=enabled().length>0&&enabled().every(o=>committed().has(o.value));const allCaption=select.dataset.allLabel||Array.from(select.options).find(o=>o.value==='*'&&o.textContent.trim()!=='Semua pilihan')?.textContent.trim()||'Semua '+label.toLowerCase();setCaption(caption,isAll?allCaption:(choices.slice(0,2).map(o=>o.textContent.trim()).join(', ')+(choices.length>2?' +'+(choices.length-2):''))||'Pilih '+label.toLowerCase());button.title=caption.textContent;button.disabled=select.disabled;button.setAttribute('aria-label',label+': '+caption.textContent);}
    function state(){const values=enabled();all.checked=values.length>0&&values.every(o=>draft.has(o.value));all.indeterminate=!all.checked&&values.some(o=>draft.has(o.value));all.disabled=!values.length;allText.textContent='Pilih Semua ('+values.length+')';count.textContent=draft.size?draft.size+' pilihan':'Pilih minimal satu opsi';apply.disabled=!draft.size;checks.forEach(c=>c.checked=draft.has(c.value));}
    function render(){list.replaceChildren();checks=[];let group='';available().forEach(o=>{const section=o.parentElement.tagName==='OPTGROUP'?o.parentElement.label:(select.name.replace('[]','')==='kategori'?(o.value.startsWith('biaya_lain')?'Biaya Lain':'Pembayaran Inti'):'');if(section&&section!==group){const h=document.createElement('div');h.className='spp-dropdown-group';h.textContent=section;list.append(h);group=section;}const row=document.createElement('label'),check=document.createElement('input'),text=document.createElement('span');row.className='spp-multi-option';check.type='checkbox';check.value=o.value;check.disabled=o.disabled||(o.parentElement.tagName==='OPTGROUP'&&o.parentElement.disabled);text.textContent=o.textContent;row.append(check,text);check.addEventListener('change',()=>{check.checked?draft.add(o.value):draft.delete(o.value);state();});list.append(row);checks.push(check);});if(!checks.length){const p=document.createElement('p');p.className='spp-dropdown-empty';p.textContent='Tidak ada pilihan yang cocok.';list.append(p);}state();position(panel,button);}
    let lastSource='';const sourceSignature=()=>select.outerHTML+'|'+Array.from(select.selectedOptions).map(o=>o.value).join('|');
    const instance={select,button,panel,close(){panel.hidden=true;window.sppFloatingPanels.hide(panel);button.setAttribute('aria-expanded','false');draft=committed();},sync(){summary();const signature=sourceSignature();if(active===instance&&signature!==lastSource){draft=committed();render();}lastSource=signature;if(select.disabled&&active===instance)close();}};
    button.addEventListener('click',()=>{if(active===instance){close(true);return;}close();active=instance;watchAnchor(instance);lastSource=sourceSignature();draft=committed();if(search)search.value='';panel.hidden=false;render();position(panel,button);button.setAttribute('aria-expanded','true');focusOption(checks.find(c=>c.checked&&!c.disabled)||checks.find(c=>!c.disabled)||all,panel);});
    button.addEventListener('keydown',e=>{if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();button.click();}});
    all.addEventListener('change',()=>{enabled().forEach(o=>all.checked?draft.add(o.value):draft.delete(o.value));state();});cancel.addEventListener('click',()=>close(true));
    apply.addEventListener('click',()=>{if(!draft.size)return;const isAll=enabled().length>0&&enabled().every(o=>draft.has(o.value));if(isAll&&!Array.from(select.options).some(o=>o.value==='*'))select.prepend(new Option('Semua pilihan','*'));Array.from(select.options).forEach(o=>{o.selected=isAll?o.value==='*':draft.has(o.value);});close(true);summary();select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));});
    panel.addEventListener('keydown',e=>{if(e.target===search&&['Enter','Home','End'].includes(e.key)){if(e.key==='Enter')e.preventDefault();return;}if(e.key==='Enter'&&e.target.type==='checkbox'){e.preventDefault();e.target.click();}keyboard(e,[all,...checks.filter(c=>!c.closest('label').hidden)],()=>close(true));});
    select.addEventListener('change',()=>{if(select.hasAttribute('data-filter-persist')){const url=new URL(location.href),key=select.name.replace('[]','');for(const parameter of [...url.searchParams.keys()])if(parameter===key||parameter.startsWith(key+'['))url.searchParams.delete(parameter);for(const value of Array.from(select.selectedOptions).map(o=>o.value))url.searchParams.append(key+'[]',value);url.searchParams.delete('page');url.searchParams.delete('class_page');history.replaceState(null,'',url);}});
    select.addEventListener('change',()=>instance.sync());select.addEventListener('input',()=>instance.sync());select.addEventListener('focus',()=>button.focus({preventScroll:true}));select.addEventListener('invalid',e=>{e.preventDefault();button.setAttribute('aria-invalid','true');button.focus();});select.form?.addEventListener('reset',()=>setTimeout(()=>instance.sync(),0));new MutationObserver(()=>instance.sync()).observe(select,{childList:true,subtree:true,attributes:true,characterData:true});enhanced.set(select,instance);instances.add(instance);['value','selectedIndex'].forEach(key=>{const descriptor=Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,key);if(descriptor?.get&&descriptor?.set)Object.defineProperty(select,key,{configurable:true,get(){return descriptor.get.call(this);},set(value){descriptor.set.call(this,value);instance.sync();}});});summary();
  }
  window.sppSelectedValues=select=>!select?[]:Array.from(select.selectedOptions).some(o=>o.value==='*')?Array.from(select.options).filter(o=>o.value!=='*'&&!o.disabled&&!(o.parentElement.tagName==='OPTGROUP'&&o.parentElement.disabled)).map(o=>o.value):Array.from(select.selectedOptions).map(o=>o.value);
  function enhance(select){if(select.hasAttribute('data-native-select')||enhanced.has(select))return;if(select.hasAttribute('data-filter-multiple')&&select.multiple){enhanceMulti(select);return;}if(select.multiple||select.size>1)return;const wrap=document.createElement('span');wrap.className='spp-select';select.before(wrap);wrap.append(select);identify(wrap,select);select.classList.add('spp-select-native');select.tabIndex=-1;
    const button=document.createElement('button');button.type='button';button.className='field-input spp-select-trigger';preservePointerFocus(button);const caption=document.createElement('span');caption.className='spp-select-caption';button.append(caption);button.insertAdjacentHTML('beforeend',icon);wrap.append(button);
    const label=controlLabel(select);
    const panel=document.createElement('div');panel.id='spp-select-menu-'+(++sequence);panel.className='spp-dropdown-panel';panel.hidden=true;panel.tabIndex=-1;panel.setAttribute('role','dialog');panel.setAttribute('aria-label',label);wrap.append(panel);let menuSignature='',validationShown=false;const signature=()=>select.outerHTML+'|'+select.value;
    button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls',panel.id);button.setAttribute('aria-expanded','false');const list=document.createElement('div');list.setAttribute('role','listbox');list.setAttribute('aria-label',label);panel.append(modeNote(false),list);let items=[];
    const hint=select.classList.contains('biaya-lain-select')?document.createElement('small'):null;if(hint){hint.className='other-fee-hint';hint.id=panel.id+'-hint';wrap.after(hint);button.setAttribute('aria-describedby',hint.id);}
    const instance={select,button,panel,close(){panel.hidden=true;window.sppFloatingPanels.hide(panel);button.setAttribute('aria-expanded','false');},sync(){const selected=select.selectedOptions[0];setCaption(caption,(selected?.dataset.baseLabel||selected?.textContent||'Pilih '+label).replace(/\s*\((Lunas|Sudah dipilih)\)$/u,''));button.title=caption.textContent;button.disabled=select.disabled;button.setAttribute('aria-label',label+': '+caption.textContent);button.setAttribute('aria-invalid',select.getAttribute('aria-invalid')==='true'||(validationShown&&!select.validity.valid)?'true':'false');if(hint){if(hint.textContent!=='Aktifkan atau tambahkan item melalui Master Biaya Lain.')hint.textContent='Aktifkan atau tambahkan item melalui Master Biaya Lain.';hint.hidden=caption.textContent!=='Belum ada biaya aktif atau tagihan lama';}if(select.disabled&&active===instance)close();if(active===instance&&signature()!==menuSignature)render();}};
    function render(){menuSignature=signature();list.replaceChildren();items=[];let group=null;Array.from(select.options).forEach(option=>{let title=option.dataset.baseLabel||option.textContent;const status=option.textContent.match(/\s*\((Lunas|Sudah dipilih)\)$/u)?.[1];title=title.replace(/\s*\((Lunas|Sudah dipilih)\)$/u,'');const parent=option.parentElement;if(option.hidden||parent.hidden)return;const section=parent.tagName==='OPTGROUP'?parent.label:(select.name==='kategori'?(option.value.startsWith('biaya_lain')?'Biaya Lain':'Pembayaran Inti'):'');if(section&&section!==group){const heading=document.createElement('div');heading.className='spp-dropdown-group';heading.textContent=section;list.append(heading);group=section;}
      const item=document.createElement('button');item.type='button';item.className='spp-dropdown-option';item.setAttribute('role','option');item.setAttribute('aria-selected',String(option.selected));item.disabled=option.disabled||(parent.tagName==='OPTGROUP'&&parent.disabled);const name=document.createElement('span');name.textContent=title;item.append(name);if(status){const badge=document.createElement('small');badge.className='spp-dropdown-status';badge.textContent=status;item.append(badge);}if(option.selected){const mark=document.createElement('span');mark.className='spp-dropdown-mark';mark.textContent='✓';mark.setAttribute('aria-hidden','true');item.append(mark);}item.addEventListener('click',()=>{select.value=option.value;instance.sync();close(true);select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));});list.append(item);items.push(item);});if(!items.length){const empty=document.createElement('div');empty.className='spp-dropdown-empty';empty.textContent='Tidak ada pilihan yang cocok.';list.append(empty);}position(panel,button);}
    function open(focus=false){if(button.disabled)return;if(active===instance){close(true);return;}close();active=instance;watchAnchor(instance);panel.hidden=false;render();position(panel,button);button.setAttribute('aria-expanded','true');focusOption(items.find(i=>i.getAttribute('aria-selected')==='true'&&!i.disabled)||items.find(i=>!i.disabled),panel);}
    button.addEventListener('click',()=>open());button.addEventListener('keydown',e=>{if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();open(true);}if(e.key==='Escape')close(true);});panel.addEventListener('keydown',e=>keyboard(e,items,()=>close(true)));select.addEventListener('change',()=>instance.sync());select.addEventListener('input',()=>instance.sync());select.addEventListener('focus',()=>button.focus({preventScroll:true}));select.addEventListener('invalid',e=>{e.preventDefault();validationShown=true;button.setAttribute('aria-invalid','true');button.focus();});select.form?.addEventListener('reset',()=>setTimeout(()=>instance.sync(),0));new MutationObserver(()=>instance.sync()).observe(select,{childList:true,subtree:true,attributes:true,characterData:true});select._syncOtherFeePicker=()=>instance.sync();enhanced.set(select,instance);instances.add(instance);['value','selectedIndex'].forEach(key=>{const descriptor=Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,key);if(descriptor?.get&&descriptor?.set)Object.defineProperty(select,key,{configurable:true,get(){return descriptor.get.call(this);},set(value){descriptor.set.call(this,value);instance.sync();}});});instance.sync();
  }
  function enhanceLegacy(owner,button,menu,isOpen,options){
    if(menu.dataset.sppLegacyReady)return;menu.dataset.sppLegacyReady='1';menu.classList.add('spp-dropdown-panel');menu.tabIndex=-1;
    const note=modeNote(false);
    const record={owner,button,panel:menu,close(){owner.classList.remove('is-open');if(!owner.classList.contains('payment-year-picker'))menu.hidden=true;button.setAttribute('aria-expanded','false');window.sppFloatingPanels.hide(menu);}};
    function decorate(){if(!menu.classList.contains('du-selector-menu')&&!menu.contains(note))menu.prepend(note);menu.querySelectorAll(options).forEach(item=>item.classList.add('spp-dropdown-option'));button.title=button.textContent.trim();}
    function sync(){decorate();if(isOpen()){
      const opening=active!==record;button.setAttribute('aria-expanded','true');if(opening){close();active=record;watchAnchor(record);}
      watchAnchor(record);position(menu,button);
      if(opening){const items=Array.from(menu.querySelectorAll(options)).filter(i=>!i.disabled&&!i.hidden);focusOption(items.find(i=>i.getAttribute('aria-selected')==='true'||i.classList.contains('is-active'))||items[0],menu);}
    }else{button.setAttribute('aria-expanded','false');window.sppFloatingPanels.hide(menu);if(active===record)active=null;}}
    menu.addEventListener('keydown',e=>{if(['ArrowDown','ArrowUp','Home','End','Escape'].includes(e.key)){e.stopImmediatePropagation();keyboard(e,Array.from(menu.querySelectorAll(options)),()=>close(true));}},true);
    menu.addEventListener('click',e=>{if(e.target.closest(options))queueMicrotask(()=>{if(active===record)close(true);});});
    new MutationObserver(sync).observe(menu,{attributes:true,attributeFilter:['hidden'],childList:true});
    new MutationObserver(sync).observe(owner,{attributes:true,attributeFilter:['class']});owner.addEventListener('click',()=>queueMicrotask(sync));decorate();
  }
  function scan(){document.querySelectorAll('select').forEach(enhance);document.querySelectorAll('.du-bill-selector').forEach(o=>{const b=o.querySelector('.du-selector-trigger'),m=o.querySelector('.du-selector-menu');if(b&&m)enhanceLegacy(o,b,m,()=>!m.hidden,'.du-selector-option');});document.querySelectorAll('[data-class-picker]').forEach(o=>{const b=o.querySelector('[data-class-picker-button]'),m=o.querySelector('[data-class-picker-panel]');if(b&&m)enhanceLegacy(o,b,m,()=>!m.hidden,'[data-class-picker-option]');});document.querySelectorAll('.payment-year-picker').forEach(o=>{const b=o.querySelector('.payment-year-select'),m=o.querySelector('.payment-year-options');if(b&&m)enhanceLegacy(o,b,m,()=>o.classList.contains('is-open'),'.payment-year-option');});}
  window.syncSppDropdowns=()=>{scan();instances.forEach(i=>{if(i.select.isConnected)i.sync();else{if(active===i)close();i.panel.remove();instances.delete(i);}});};window.syncOtherFeePickers=window.syncSppDropdowns;
  document.addEventListener('DOMContentLoaded',()=>{scan();let scheduled=false;new MutationObserver(mutations=>{if(mutations.every(m=>m.target.closest?.('.spp-dropdown-panel,.spp-select')))return;if(!scheduled){scheduled=true;queueMicrotask(()=>{scheduled=false;window.syncSppDropdowns();});}}).observe(document.body,{childList:true,subtree:true});document.addEventListener('pointerdown',e=>{if(active&&!window.sppFloatingPanels.contains(active.panel,e.target)&&!active.button.contains(e.target))close();});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&active){e.preventDefault();close(true);}});document.addEventListener('focusin',e=>{if(active&&!window.sppFloatingPanels.contains(active.panel,e.target)&&!active.button.contains(e.target)&&!active.owner?.contains(e.target))close();});window.addEventListener('resize',()=>{if(active)position(active.panel,active.button);});window.addEventListener('scroll',e=>{if(active&&!active.panel.contains(e.target))position(active.panel,active.button);},true);
    document.addEventListener('pointerdown',()=>instances.forEach(i=>i.sync()),true);document.addEventListener('focusin',()=>instances.forEach(i=>i.sync()),true);
  });
})();
