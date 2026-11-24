(() => {
  'use strict';
  let current=null;
  window.workflowConfirm=(settings,source=document.activeElement)=>new Promise(resolve=>{
    if (typeof HTMLDialogElement==='undefined' || !HTMLDialogElement.prototype.showModal) { resolve(false); return; }
    current?.close();
    const dialog=document.createElement('dialog');dialog.className='workflow-dialog';dialog.dataset.danger=settings.danger?'true':'false';
    if(settings.appearance==='operation')dialog.classList.add('workflow-dialog-operation');
    const header=document.createElement('header');
    const heading=document.createElement('h2');heading.id='workflow-dialog-title';heading.textContent=settings.title;dialog.setAttribute('aria-labelledby',heading.id);
    header.append(heading);
    const body=document.createElement('div');body.className='workflow-dialog-body';
    if(settings.message){
      const text=document.createElement('p');text.id='workflow-dialog-message';text.textContent=settings.message;body.append(text);
      dialog.setAttribute('aria-describedby',text.id);
    }
    const list=document.createElement('ul');(settings.rows||[]).forEach(value=>{const item=document.createElement('li');item.textContent=value;list.append(item);});
    if(list.childElementCount)body.append(list);
    const actions=document.createElement('footer');actions.className='workflow-dialog-actions';
    const cancel=document.createElement('button');cancel.type='button';cancel.className='btn btn-ghost';cancel.textContent='Batal';
    const submit=document.createElement('button');submit.type='button';submit.className='btn '+(settings.danger?'btn-danger':'btn-primary');submit.textContent=settings.action||'Konfirmasi';
    let accepted=false;cancel.onclick=()=>dialog.close();submit.onclick=()=>{accepted=true;dialog.close();};actions.append(cancel,submit);dialog.append(header,body,actions);document.body.append(dialog);current=dialog;
    dialog.addEventListener('keydown',event=>{if(event.key!=='Tab')return;const controls=[cancel,submit];if(event.shiftKey&&document.activeElement===controls[0]){event.preventDefault();controls.at(-1).focus();}else if(!event.shiftKey&&document.activeElement===controls.at(-1)){event.preventDefault();controls[0].focus();}});
    dialog.addEventListener('close',()=>{
      dialog.remove();
      if(current===dialog){
        current=null;
        if(source?.isConnected)source.focus({preventScroll:true});
      }
      resolve(accepted);
    },{once:true});
    dialog.showModal();cancel.focus();
  });

  const pendingForms=new WeakSet();
  const confirmedForms=new WeakSet();
  document.addEventListener('submit',async event=>{
    const form=event.target;
    if (!form.hasAttribute('data-dialog-confirm') || confirmedForms.delete(form)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if(pendingForms.has(form))return;
    pendingForms.add(form);
    const submitter=event.submitter;
    const action=form.dataset.dialogAction || submitter?.textContent.trim();
    try {
      const accepted=await window.workflowConfirm({
        title:form.dataset.dialogTitle || action || 'Konfirmasi tindakan',
        message:form.dataset.dialogConfirm,
        action,
        danger:form.dataset.dialogTone==='danger',
      },submitter || document.activeElement);
      if(accepted && form.isConnected && (!submitter || (submitter.form===form && !submitter.disabled))){
        confirmedForms.add(form);
        form.requestSubmit(submitter || undefined);
      }
    } finally {
      pendingForms.delete(form);
      confirmedForms.delete(form);
    }
  },true);
})();
