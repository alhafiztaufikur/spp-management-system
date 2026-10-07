(() => {
  const root=document.querySelector('[data-registration-workspace]');if(!root)return;
  const cards=[...root.querySelectorAll('[data-registration-record]')],body=root.querySelector('[data-registration-detail]'),panel=root.querySelector('[data-registration-detail-panel]');
  const prev=root.querySelector('[data-registration-prev]'),next=root.querySelector('[data-registration-next]');
  let index=cards.findIndex(c=>c.classList.contains('is-selected')),controller,generation=0;
  const nav=()=>{prev.disabled=index<=0;next.disabled=index<0||index>=cards.length-1;};
  async function select(i,push=true){
    if(i<0||i>=cards.length)return;
    index=i;const card=cards[i],version=++generation;controller?.abort();controller=new AbortController();panel.hidden=false;root.classList.remove('detail-closed');
    cards.forEach((c,j)=>{c.classList.toggle('is-selected',i===j);if(i===j)c.setAttribute('aria-current','true');else c.removeAttribute('aria-current');});nav();
    if(push)history.pushState(null,'',card.href);
    body.setAttribute('aria-busy','true');body.replaceChildren(Object.assign(document.createElement('p'),{textContent:'Memuat detail Daftar Ulang…',className:'ph-empty'}));
    const query=new URL(card.href).searchParams;query.set('tagihan_id',card.dataset.id);query.set('unit_id',card.dataset.unit);
    try{
      const response=await fetch('detail_daftar_ulang.php?'+query,{signal:controller.signal,headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'Detail belum dapat dimuat.');
      if(version===generation)body.innerHTML=data.html;
    }catch(error){if(error.name!=='AbortError'&&version===generation){const p=document.createElement('p');p.className='ph-empty';p.textContent=error.message;const retry=document.createElement('button');retry.type='button';retry.className='btn btn-ghost';retry.textContent='Coba Lagi';retry.addEventListener('click',()=>select(i,false));body.replaceChildren(p,retry);}}
    finally{if(version===generation)body.removeAttribute('aria-busy');}
  }
  cards.forEach((c,i)=>c.addEventListener('click',e=>{if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;e.preventDefault();select(i);}));
  prev.addEventListener('click',()=>select(index-1));next.addEventListener('click',()=>select(index+1));
  root.querySelector('[data-registration-close]').addEventListener('click',()=>{generation++;controller?.abort();panel.hidden=true;root.classList.add('detail-closed');cards[index]?.focus();});
  addEventListener('popstate',()=>{const q=new URL(location.href).searchParams;const i=cards.findIndex(c=>c.dataset.id===q.get('selected')&&(!q.get('selected_unit')||c.dataset.unit===q.get('selected_unit')));if(i>=0)select(i,false);else if(cards.length)select(0,false);});nav();
  const dialog=document.getElementById('du-delete-dialog');let source;
  document.addEventListener('click',e=>{const button=e.target.closest('.open-du-delete');if(!button)return;source=button;const form=dialog.querySelector('form');form.reset();delete form.dataset.sending;form.querySelector('[type=submit]').disabled=false;form.elements.id.value=button.dataset.id;form.elements.return_context.value=button.dataset.context;dialog.querySelector('[data-du-delete-copy]').textContent='TRX-'+button.dataset.id.padStart(6,'0')+' · '+button.dataset.student;dialog.showModal();});
  dialog.querySelector('[data-close-du-delete]').addEventListener('click',()=>dialog.close());dialog.addEventListener('close',()=>source?.focus());
  dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
  dialog.querySelector('form').addEventListener('submit',e=>{if(e.target.dataset.sending){e.preventDefault();return;}e.target.dataset.sending='1';e.target.querySelector('[type=submit]').disabled=true;});
})();
