(() => {
  const detail=document.getElementById('sw-history-detail');if(!detail)return;
  let controller,generation=0;
  document.querySelectorAll('.sw-transaction-select').forEach(link=>link.addEventListener('click',async event=>{
    if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
    event.preventDefault();const card=link.closest('.sw-transaction'),request=++generation;
    controller?.abort();controller=new AbortController();
    document.querySelectorAll('.sw-transaction').forEach(row=>{const active=row===card;row.classList.toggle('is-active',active);const a=row.querySelector('a');if(active)a.setAttribute('aria-current','true');else a.removeAttribute('aria-current');});
    detail.setAttribute('aria-busy','true');detail.innerHTML='<p class="sw-empty">Memuat detail transaksi…</p>';
    try {
      const response=await fetch('detail.php?'+new URLSearchParams({jenis:card.dataset.kind,id:card.dataset.id}),{signal:controller.signal,headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Detail tidak tersedia.');
      if(request!==generation)return;detail.innerHTML=data.html;
      history.replaceState(null,'',link.href);
    }catch(error){if(request!==generation||error.name==='AbortError')return;detail.textContent='Detail belum dapat dimuat. ';const retry=document.createElement('button');retry.className='btn btn-ghost';retry.textContent='Coba lagi';retry.addEventListener('click',()=>link.click());detail.append(retry);}
    finally{if(request===generation)detail.removeAttribute('aria-busy');}
  }));
  const modal=document.getElementById('sw-print-modal');if(!modal)return;
  const later=document.getElementById('sw-print-later'),now=document.getElementById('sw-print-now');const previous=document.activeElement;
  const close=()=>{modal.remove();document.body.classList.remove('sw-modal-open');(previous?.matches?.('a,button,input,select,textarea,[tabindex]')?previous:detail).focus({preventScroll:true});};
  document.body.classList.add('sw-modal-open');now.focus();later.addEventListener('click',close);now.addEventListener('click',close);
  modal.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();}if(e.key==='Tab'){e.preventDefault();(document.activeElement===now?later:now).focus();}});
})();
