(() => {
  const form=document.getElementById('account-filter-form');
  form.querySelectorAll('select').forEach(select=>select.addEventListener('change',()=>form.requestSubmit()));
  document.addEventListener('click',event=>{
    const button=event.target.closest('[data-account-shortcut]');
    if(button){const row=button.closest('tr');row.querySelector('.account-more').open=false;
      const target=button.dataset.accountShortcut==='password'?row.querySelector('.btn-reset-password'):button.dataset.accountShortcut==='delete'?row.querySelector('.btn-purge-account'):row.querySelector('.btn-delete-account,.account-activate-form button');target?.click();}
    document.querySelectorAll('.account-more[open]').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;});
  });
  const modals=[...document.querySelectorAll('.account-workspace .modal-overlay')];let opener;
  document.addEventListener('click',event=>{if(event.target.closest('.btn-reset-password,.btn-delete-account,.btn-purge-account'))opener=event.target.closest('button');});
  const purge=document.getElementById('purge-account-modal'),purgeForm=document.getElementById('form-purge-account');
  const closePurge=()=>{purge.classList.remove('show');purgeForm.reset();};
  document.querySelectorAll('.btn-purge-account').forEach(button=>button.addEventListener('click',()=>{
    if(button.disabled)return;
    purgeForm.reset();document.getElementById('purge-account-id').value=button.dataset.accountId;
    document.getElementById('purge-account-label').textContent=button.dataset.accountName+' (@'+button.dataset.accountUsername+')';
    purge.classList.add('show');
  }));
  document.getElementById('btn-cancel-purge').addEventListener('click',closePurge);
  purge.addEventListener('click',event=>{if(event.target===purge)closePurge();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&purge.classList.contains('show'))closePurge();});
  modals.forEach(modal=>new MutationObserver(()=>{if(!modal.classList.contains('show'))opener?.focus({preventScroll:true});else modal.querySelector('input:not([type=hidden]),button')?.focus();}).observe(modal,{attributes:true,attributeFilter:['class']}));
  document.addEventListener('keydown',event=>{
    const modal=modals.find(m=>m.classList.contains('show'));if(!modal||event.key!=='Tab')return;
    const items=[...modal.querySelectorAll('button,input:not([type=hidden])')].filter(e=>!e.disabled),first=items[0],last=items.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
})();
