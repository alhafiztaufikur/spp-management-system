(() => {
  const form=document.getElementById('account-filter-form');
  form.querySelectorAll('select').forEach(select=>select.addEventListener('change',()=>form.requestSubmit()));
  document.addEventListener('click',event=>{
    const button=event.target.closest('[data-account-shortcut]');
    if(button){const row=button.closest('tr');row.querySelector('.account-more').open=false;
      const target=button.dataset.accountShortcut==='password'?row.querySelector('.btn-reset-password'):row.querySelector('.btn-delete-account,.account-activate-form button');target?.click();}
    document.querySelectorAll('.account-more[open]').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;});
  });
  const modals=[...document.querySelectorAll('.account-workspace .modal-overlay')];let opener;
  document.addEventListener('click',event=>{if(event.target.closest('.btn-reset-password,.btn-delete-account'))opener=event.target.closest('button');});
  modals.forEach(modal=>new MutationObserver(()=>{if(!modal.classList.contains('show'))opener?.focus({preventScroll:true});else modal.querySelector('input:not([type=hidden]),button')?.focus();}).observe(modal,{attributes:true,attributeFilter:['class']}));
  document.addEventListener('keydown',event=>{
    const modal=modals.find(m=>m.classList.contains('show'));if(!modal||event.key!=='Tab')return;
    const items=[...modal.querySelectorAll('button,input:not([type=hidden])')].filter(e=>!e.disabled),first=items[0],last=items.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
})();
