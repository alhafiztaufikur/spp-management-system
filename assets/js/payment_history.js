(() => {
  const root=document.querySelector('[data-payment-workspace]');if(!root)return;
  const cards=[...root.querySelectorAll('[data-payment-record]')],body=root.querySelector('[data-payment-detail]'),panel=root.querySelector('[data-payment-detail-panel]');
  const prev=root.querySelector('[data-payment-prev]'),next=root.querySelector('[data-payment-next]');
  let index=cards.findIndex(c=>c.classList.contains('is-selected')),controller,generation=0;
  function nav(){prev.disabled=index<=0;next.disabled=index<0||index>=cards.length-1;}
  async function select(i,push=true){
    if(i<0||i>=cards.length)return;
    index=i;const card=cards[i],version=++generation;controller?.abort();controller=new AbortController();
    panel.hidden=false;root.classList.remove('detail-closed');
    cards.forEach((c,j)=>{c.classList.toggle('is-selected',i===j);if(i===j)c.setAttribute('aria-current','true');else c.removeAttribute('aria-current');});nav();
    if(push)history.pushState(null,'',card.href);
    body.setAttribute('aria-busy','true');body.replaceChildren(Object.assign(document.createElement('p'),{textContent:'Memuat detail transaksi…',className:'ph-empty'}));
    try{
      const response=await fetch('detail.php?'+new URLSearchParams({id:card.dataset.id,unit_id:card.dataset.unit,view:root.dataset.view}),{signal:controller.signal,headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'Detail belum dapat dimuat.');
      if(version===generation)body.innerHTML=data.html;
    }catch(error){if(error.name!=='AbortError'&&version===generation){const p=document.createElement('p');p.className='ph-empty';p.textContent=error.message;const retry=document.createElement('button');retry.type='button';retry.className='btn btn-ghost';retry.textContent='Coba kembali';retry.addEventListener('click',()=>select(i,false));body.replaceChildren(p,retry);}}
    finally{if(version===generation)body.removeAttribute('aria-busy');}
  }
  cards.forEach((c,i)=>c.addEventListener('click',e=>{if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;e.preventDefault();select(i);}));
  prev.addEventListener('click',()=>select(index-1));next.addEventListener('click',()=>select(index+1));
  root.querySelector('[data-payment-close]').addEventListener('click',()=>{generation++;controller?.abort();panel.hidden=true;root.classList.add('detail-closed');cards[index]?.focus();});
  addEventListener('popstate',()=>{const id=new URL(location.href).searchParams.get('selected');const i=cards.findIndex(c=>c.dataset.id===id);if(i>=0)select(i,false);else if(cards.length)select(0,false);});
  nav();
})();
