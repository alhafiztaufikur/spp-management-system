(() => {
  'use strict';
  const style=document.createElement('link');style.rel='stylesheet';style.href=new URL('../css/workflow_dialogs.css',document.currentScript.src);document.head.append(style);
  let current=null;
  window.workflowConfirm=(settings,source=document.activeElement)=>new Promise(resolve=>{
    current?.close();
    const dialog=document.createElement('dialog');dialog.className='workflow-dialog';dialog.dataset.danger=settings.danger?'true':'false';
    const heading=document.createElement('h2');heading.id='workflow-dialog-title';heading.textContent=settings.title;dialog.setAttribute('aria-labelledby',heading.id);
    const text=document.createElement('p');text.textContent=settings.message||'';
    const list=document.createElement('ul');(settings.rows||[]).forEach(value=>{const item=document.createElement('li');item.textContent=value;list.append(item);});
    const actions=document.createElement('div');actions.className='workflow-dialog-actions';
    const cancel=document.createElement('button');cancel.type='button';cancel.className='btn btn-ghost';cancel.textContent='Batal';
    const submit=document.createElement('button');submit.type='button';submit.className='btn '+(settings.danger?'btn-danger':'btn-primary');submit.textContent=settings.action||'Konfirmasi';
    let accepted=false;cancel.onclick=()=>dialog.close();submit.onclick=()=>{accepted=true;dialog.close();};actions.append(cancel,submit);dialog.append(heading,text,list,actions);document.body.append(dialog);current=dialog;
    dialog.addEventListener('keydown',event=>{if(event.key!=='Tab')return;const controls=[cancel,submit];if(event.shiftKey&&document.activeElement===controls[0]){event.preventDefault();controls.at(-1).focus();}else if(!event.shiftKey&&document.activeElement===controls.at(-1)){event.preventDefault();controls[0].focus();}});
    dialog.addEventListener('close',()=>{if(current===dialog)current=null;dialog.remove();if(source?.isConnected)source.focus();resolve(accepted);},{once:true});
    dialog.showModal();cancel.focus();
  });
})();
