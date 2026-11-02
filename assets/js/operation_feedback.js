/* Results of explicit actions only. Business validation and confirmations stay with each form. */
(() => {
  'use strict';
  const titles={success:'Berhasil',error:'Gagal diproses',warning:'Periksa hasil',info:'Informasi'};
  const queue=[];
  let dialog,heading,body,details,close,current;
  function build(){
    dialog=document.createElement('dialog');dialog.id='operation-feedback-dialog';dialog.className='operation-feedback-dialog';
    dialog.setAttribute('aria-labelledby','operation-feedback-title');dialog.setAttribute('aria-describedby','operation-feedback-message');
    const header=document.createElement('header');heading=document.createElement('h2');heading.id='operation-feedback-title';header.append(heading);
    body=document.createElement('p');body.id='operation-feedback-message';
    details=document.createElement('dl');details.className='operation-feedback-details';details.hidden=true;
    const footer=document.createElement('footer');close=document.createElement('button');close.type='button';close.className='btn btn-primary';close.textContent='Mengerti';
    footer.append(close);dialog.append(header,body,details,footer);document.body.append(dialog);
    close.addEventListener('click',()=>dialog.close());
    dialog.addEventListener('keydown',event=>{
      if(event.key==='Tab'){event.preventDefault();close.focus({preventScroll:true});}
    });
    dialog.addEventListener('close',()=>{
      const source=current?.source;current=null;
      if(source?.isConnected&&!source.disabled)source.focus({preventScroll:true});
      if(queue.length)queueMicrotask(next);
    });
  }
  function next(){
    if(current||!queue.length)return;
    if(!dialog)build();
    current=queue.shift();dialog.dataset.type=current.type;
    dialog.setAttribute('role',current.type==='error'?'alertdialog':'dialog');
    heading.textContent=current.title;body.textContent=current.message;
    details.replaceChildren();details.hidden=!current.details.length;
    current.details.forEach(({label,value})=>{
      const row=document.createElement('div'),term=document.createElement('dt'),count=document.createElement('dd');
      term.textContent=label;count.textContent=value;row.append(term,count);details.append(row);
    });
    dialog.showModal();close.focus({preventScroll:true});
  }
  window.showOperationResult=settings=>{
    const message=String(settings?.message??'').trim();
    if(!message||typeof HTMLDialogElement==='undefined'||!HTMLDialogElement.prototype.showModal)return false;
    const type=Object.hasOwn(titles,settings.type)?settings.type:'info';
    const rows=Array.isArray(settings.details)?settings.details.filter(row=>row&&typeof row.label==='string'&&['string','number'].includes(typeof row.value)).map(row=>({label:row.label,value:String(row.value)})):[];
    const item={type,title:String(settings.title||titles[type]),message,details:rows,source:settings.source||document.activeElement};
    if([current,...queue].some(result=>result&&result.type===item.type&&result.title===item.title&&result.message===message&&JSON.stringify(result.details)===JSON.stringify(rows)))return true;
    queue.push(item);next();return true;
  };
  function readResults(){
    document.querySelectorAll('#flash-msg,[data-operation-result]').forEach(node=>{
      if(node.dataset.operationConsumed||node.hasAttribute('data-operation-result-skip'))return;
      // Payment failures can contain actions such as choosing an earlier month.
      if(node.id==='flash-msg'&&window.sppFlashWarning)return;
      const type=node.dataset.operationResult||['success','error','warning','info'].find(value=>node.classList.contains('alert-'+value))||'info';
      const source=document.querySelector(node.dataset.operationFocus||'.main-content [aria-invalid="true"],.main-content button[type="submit"],.main-content input:not([type="hidden"])');
      let detailRows=[];
      try{detailRows=JSON.parse(node.dataset.operationDetails||'[]');}catch(_){return;/* Keep the complete inline result when metadata is unreadable. */}
      if(window.showOperationResult({type,title:node.dataset.operationTitle,message:node.dataset.operationMessage||node.textContent,details:detailRows,source})){
        node.dataset.operationConsumed='1';node.hidden=true;
      }
    });
    document.querySelectorAll('[data-import-progress][data-operation-job-message]').forEach(node=>{
      const state=node.dataset.state,key='operation-job:'+node.dataset.importProgress;
      try{
        const previous=sessionStorage.getItem(key);
        sessionStorage.setItem(key,state);
        const active=['queued','processing','simulate_queued','simulating','apply_queued','applying'];
        const terminal={imported:'success',completed:'success',failed:'error',cancelled:'warning',ready:'info'};
        if(previous&&active.includes(previous)&&terminal[state])window.showOperationResult({type:terminal[state],message:node.dataset.operationJobMessage});
      }catch(_){/* Existing job status remains visible when browser storage is unavailable. */}
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',readResults,{once:true});else readResults();
})();
