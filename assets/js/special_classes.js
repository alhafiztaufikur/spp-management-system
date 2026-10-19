/* Progressive enhancement: server-rendered confirmation works without JavaScript. */
(() => {
  const confirmation=document.getElementById('special-delete-confirm');
  if(!confirmation||typeof HTMLDialogElement==='undefined'||!HTMLDialogElement.prototype.showModal)return;
  const dialog=document.createElement('dialog');dialog.className='sc-dialog';dialog.setAttribute('aria-labelledby','special-delete-title');
  const back=confirmation.querySelector('[data-sc-back]');confirmation.before(dialog);dialog.append(confirmation);dialog.showModal();
  back?.focus();dialog.addEventListener('cancel',event=>{event.preventDefault();if(back)location.href=back.href;else dialog.close();});
})();
