/* Native selects remain the submitted/validated source; this only improves presentation. */
(() => {
  let sequence = 0;
  function enhance(select) {
    if (select.dataset.pickerReady) return;
    select.dataset.pickerReady = '1';
    const field = select.closest('.ll-field');
    field.classList.add('ll-field-kind');
    const picker = document.createElement('div'); picker.className = 'other-fee-picker';
    const button = document.createElement('button'); button.type = 'button'; button.className = 'field-input other-fee-trigger';
    button.setAttribute('aria-haspopup', 'listbox'); button.setAttribute('aria-expanded', 'false');
    const menu = document.createElement('div'); menu.className = 'other-fee-menu'; menu.hidden = true;
    menu.id = 'other-fee-menu-' + (++sequence); menu.setAttribute('role','listbox'); menu.setAttribute('aria-label','Pilih jenis biaya lain');
    button.setAttribute('aria-controls',menu.id);
    const hint = document.createElement('small'); hint.className = 'other-fee-hint'; hint.id = menu.id + '-hint';
    button.setAttribute('aria-describedby',hint.id);
    picker.append(button,menu); select.before(picker); select.after(hint);
    select.classList.add('other-fee-native'); select.tabIndex = -1;
    function close(restore = false) { menu.hidden=true; button.setAttribute('aria-expanded','false'); if(restore)button.focus(); }
    function render() {
      const chosen = select.selectedOptions[0];
      const name = chosen?.dataset.baseLabel || chosen?.textContent || 'Pilih tagihan';
      button.replaceChildren(); const caption = document.createElement('span'); caption.textContent = name.replace(/\s+\((Lunas|Sudah dipilih)\)$/u,'');
      const chevron = document.createElement('span'); chevron.textContent = '⌄'; chevron.className='other-fee-chevron'; chevron.setAttribute('aria-hidden','true');
      button.append(caption,chevron); button.disabled = select.disabled;
      button.setAttribute('aria-label','Jenis biaya lain: ' + caption.textContent);
      button.setAttribute('aria-invalid',select.getAttribute('aria-invalid') || 'false');
      const empty = caption.textContent === 'Belum ada tagihan';
      hint.textContent = empty ? 'Terbitkan tagihan melalui Master Biaya Lain.' : '';
      hint.hidden = !empty;
      menu.replaceChildren();
      Array.from(select.options).forEach(option => {
        const item=document.createElement('button'); item.type='button'; item.className='other-fee-option'; item.setAttribute('role','option');
        item.setAttribute('aria-selected',String(option.selected)); item.disabled=option.disabled;
        const title=document.createElement('span'); title.textContent=option.dataset.baseLabel || option.textContent.replace(/\s+\((Lunas|Sudah dipilih)\)$/u,''); item.append(title);
        const status=option.textContent.match(/\((Lunas|Sudah dipilih)\)$/u)?.[1];
        if(status){const badge=document.createElement('small');badge.className='other-fee-status';badge.textContent=status;item.append(badge);}
        item.addEventListener('click',e=>{e.preventDefault();select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));close(true);});
        menu.append(item);
      });
      if(select.disabled)close();
    }
    button.addEventListener('click',e=>{e.preventDefault();const opening=menu.hidden;document.querySelectorAll('.other-fee-menu').forEach(m=>m.hidden=true);
      document.querySelectorAll('.other-fee-trigger').forEach(b=>b.setAttribute('aria-expanded','false'));
      menu.hidden=!opening;button.setAttribute('aria-expanded',String(opening));});
    button.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close(true);return;}if(e.key==='ArrowDown'){e.preventDefault();menu.hidden=false;button.setAttribute('aria-expanded','true');menu.querySelector('button:not(:disabled)')?.focus();}});
    menu.addEventListener('keydown',e=>{
      if(e.key==='Escape'){e.preventDefault();close(true);return;}
      const options=Array.from(menu.querySelectorAll('button:not(:disabled)'));const index=options.indexOf(document.activeElement);
      if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)){e.preventDefault();const next=e.key==='Home'?0:e.key==='End'?options.length-1:(index+(e.key==='ArrowDown'?1:-1)+options.length)%options.length;options[next]?.focus();}
    });
    select.addEventListener('focus',()=>button.focus()); select.addEventListener('change',render);
    new MutationObserver(render).observe(select,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','aria-invalid','data-base-label']});
    picker.addEventListener('focusout',()=>setTimeout(()=>{if(!picker.contains(document.activeElement))close();},0));
    select._syncOtherFeePicker=render; render();
  }
  window.syncOtherFeePickers=()=>document.querySelectorAll('.biaya-lain-select').forEach(select=>{enhance(select);select._syncOtherFeePicker();});
  document.addEventListener('DOMContentLoaded',()=>{
    window.syncOtherFeePickers();
    const list=document.getElementById('biaya-lain-list');if(list)new MutationObserver(()=>window.syncOtherFeePickers()).observe(list,{childList:true});
    document.addEventListener('click',e=>{if(!e.target.closest('.other-fee-picker')){document.querySelectorAll('.other-fee-menu').forEach(m=>m.hidden=true);document.querySelectorAll('.other-fee-trigger').forEach(b=>b.setAttribute('aria-expanded','false'));}});
  });
})();
