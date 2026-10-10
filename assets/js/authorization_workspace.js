(() => {
  const root=document.querySelector('[data-auth-workspace]');if(!root)return;
  const cards=[...root.querySelectorAll('[data-auth-record]')];const detail=root.querySelector('[data-auth-detail]');
  const prev=root.querySelector('[data-auth-prev]');const next=root.querySelector('[data-auth-next]');
  const exportForm=root.querySelector('[data-export-form]');
  if(exportForm){
    const boxes=[...root.querySelectorAll('[data-export-choice]')],all=exportForm.querySelector('[data-export-all-page]');
    const count=exportForm.querySelector('[data-export-count]'),clear=exportForm.querySelector('[data-export-clear]'),print=exportForm.querySelector('[data-export-selected]');
    const filter=JSON.parse(root.dataset.exportFilter);filter.kind.sort();filter.status.sort();
    const context=JSON.stringify([root.dataset.exportAccount,root.dataset.exportScope,filter]);
    const storageKey='spp.authorization.pdf.selection';let choices=new Set();
    function restore(){choices=new Set();try{const saved=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(saved?.context===context&&Array.isArray(saved.choices))choices=new Set(saved.choices.filter(key=>/^[1-3]\|[1-9]\d*$/.test(key)));}catch{}}
    restore();
    const key=box=>box.dataset.unit+'|'+box.dataset.id;
    function sync(){
      boxes.forEach(box=>{box.checked=choices.has(key(box));box.closest('.auth-record-row').classList.toggle('is-print-selected',box.checked);});
      const selectedOnPage=boxes.filter(box=>box.checked).length;
      all.checked=boxes.length>0&&selectedOnPage===boxes.length;all.indeterminate=selectedOnPage>0&&!all.checked;
      count.textContent=choices.size+' transaksi dipilih';clear.disabled=print.disabled=choices.size===0;
      exportForm.elements.transactions.value=JSON.stringify([...choices].map(value=>{const [unit,payment]=value.split('|');return {unit_id:Number(unit),payment_id:Number(payment)};}));
      try{sessionStorage.setItem(storageKey,JSON.stringify({context,choices:[...choices]}));}catch{}
    }
    boxes.forEach(box=>box.addEventListener('change',()=>{box.checked?choices.add(key(box)):choices.delete(key(box));sync();}));
    all.addEventListener('change',()=>{boxes.forEach(box=>all.checked?choices.add(key(box)):choices.delete(key(box)));sync();});
    clear.addEventListener('click',()=>{choices.clear();sync();});
    exportForm.addEventListener('submit',event=>{if(!choices.size)event.preventDefault();});
    addEventListener('pageshow',()=>{restore();sync();});sync();
  }
  let selected=cards.findIndex(e=>e.classList.contains('is-selected'));let controller=null;let generation=0;
  const nav=()=>{prev.disabled=selected<=0;next.disabled=selected<0||selected>=cards.length-1;};
  async function select(index,push=true){
    if(index<0||index>=cards.length)return;
    selected=index;const card=cards[index];const version=++generation;controller?.abort();controller=new AbortController();
    cards.forEach((e,i)=>{e.classList.toggle('is-selected',i===index);if(i===index)e.setAttribute('aria-current','true');else e.removeAttribute('aria-current');});nav();
    if(push)history.pushState(null,'',card.href);
    detail.setAttribute('aria-busy','true');detail.replaceChildren(Object.assign(document.createElement('p'),{textContent:'Memuat detail transaksi…',className:'auth-muted'}));
    try{
      const params=new URLSearchParams({id:card.dataset.id,unit_id:card.dataset.unit,view:root.dataset.view});
      const response=await fetch('otorisasi_detail.php?'+params,{signal:controller.signal,headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Detail belum dapat dimuat.');
      if(version===generation)detail.innerHTML=data.html;
    }catch(error){if(error.name!=='AbortError'&&version===generation){const p=document.createElement('p');p.textContent=error.message;const retry=document.createElement('button');retry.type='button';retry.className='btn btn-ghost';retry.textContent='Coba kembali';retry.addEventListener('click',()=>select(index,false));detail.replaceChildren(p,retry);}}
    finally{if(version===generation)detail.removeAttribute('aria-busy');}
  }
  cards.forEach((card,i)=>card.addEventListener('click',e=>{if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;e.preventDefault();select(i);}));
  prev.addEventListener('click',()=>select(selected-1));next.addEventListener('click',()=>select(selected+1));
  addEventListener('popstate',()=>{const q=new URL(location.href).searchParams;const i=cards.findIndex(c=>c.dataset.id===q.get('selected')&&c.dataset.unit===q.get('selected_unit'));if(i>=0)select(i,false);else if(cards.length)select(0,false);});
  const dialog=document.createElement('dialog');dialog.className='auth-confirm-dialog';
  dialog.setAttribute('aria-labelledby','auth-confirm-title');dialog.setAttribute('aria-describedby','auth-confirm-copy');
  dialog.innerHTML='<div class="auth-confirm-heading"><span data-confirm-icon aria-hidden="true"></span><h3 id="auth-confirm-title"></h3></div><div class="auth-confirm-body"><strong data-confirm-reference></strong><p id="auth-confirm-copy"></p><div class="auth-confirm-actions"><button type="button" class="btn btn-ghost" data-confirm-cancel>Batal</button><button type="button" class="btn btn-primary" data-confirm-accept></button></div></div>';
  document.body.append(dialog);
  const accept=dialog.querySelector('[data-confirm-accept]');let pendingForm=null,submitter=null,opener=null;
  dialog.querySelector('[data-confirm-cancel]').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('keydown',e=>{
    if(e.key!=='Tab')return;
    const buttons=[...dialog.querySelectorAll('button:not(:disabled)')],first=buttons[0],last=buttons.at(-1);
    if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
    else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
  });
  dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
  dialog.addEventListener('close',()=>{pendingForm=null;opener?.focus();});
  accept.addEventListener('click',()=>{const form=pendingForm,button=submitter;if(!form||!form.reportValidity())return;form.dataset.confirmed='1';dialog.close();if(button)form.requestSubmit(button);else form.requestSubmit();});
  document.addEventListener('submit',e=>{
    const form=e.target.closest('[data-auth-confirm]');if(!form)return;
    if(form.dataset.sending){e.preventDefault();return;}
    if(form.dataset.confirmed==='1'){delete form.dataset.confirmed;form.dataset.sending='1';setTimeout(()=>form.querySelectorAll('button[type="submit"]').forEach(b=>b.disabled=true),0);return;}
    e.preventDefault();if(!form.reportValidity())return;
    pendingForm=form;submitter=e.submitter;opener=e.submitter||document.activeElement;
    const action=form.querySelector('[name="action"]')?.value,approve=!!form.querySelector('[name="aksi"][value="otorisasi_setujui"]'),deletion=form.dataset.authKind==='hapus';
    const title=approve?(deletion?'Setujui penghapusan transaksi?':'Setujui perubahan transaksi?'):action==='reject'?'Tolak pengajuan?':'Batalkan pengajuan?';
    const copy=approve?(deletion?'Pembayaran akan dihapus setelah persetujuan. Seluruh jurnal aktivitas tetap tersimpan.':'Usulan perubahan akan diterapkan pada pembayaran. Pembuat awal transaksi tetap tercatat.'):action==='reject'?'Pengajuan akan ditolak. Pembayaran tetap utuh dan keputusan tersimpan dalam riwayat.':'Pengajuan akan dibatalkan. Pembayaran tetap utuh dan riwayat pengajuan tersimpan.';
    dialog.dataset.danger=String(deletion||action==='reject');dialog.querySelector('#auth-confirm-title').textContent=title;
    dialog.querySelector('#auth-confirm-copy').textContent=copy;dialog.querySelector('[data-confirm-reference]').textContent=[form.dataset.authReference,form.dataset.authStudent].filter(Boolean).join(' / ');
    dialog.querySelector('[data-confirm-icon]').innerHTML='<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="'+(deletion?'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 10v7M14 10v7':'m5 12 4 4L19 6')+'"/></svg>';
    accept.textContent=approve?(deletion?'Setujui dan Hapus':'Setujui dan Terapkan'):action==='reject'?'Tolak Pengajuan':'Batalkan Pengajuan';
    accept.className='btn '+(deletion||action==='reject'?'btn-danger':'btn-primary');dialog.showModal();dialog.querySelector('[data-confirm-cancel]').focus();
  });nav();
})();
