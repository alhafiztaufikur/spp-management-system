/* HTML first; print/download always validate and use the real server PDF. */
(() => {
  'use strict';
  const root=document.querySelector('[data-transaction-preview]');if(!root)return;
  const frame=root.querySelector('[data-preview-frame]'),status=root.querySelector('[data-preview-status]'),retry=root.querySelector('[data-preview-retry]');
  const print=root.querySelector('[data-preview-print]'),download=root.querySelector('[data-preview-download]');
  let pending=null,controller=null,blobUrl=null,signature='',started=false,acting=false;
  const retired=[];
  function busy(value){print.disabled=value;download.setAttribute('aria-disabled',String(value));retry.hidden=true;}
  async function refreshPreview(model){
    if(model===root.dataset.model)return;
    const response=await fetch(root.dataset.htmlUrl,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
    if(!response.ok||response.headers.get('X-Report-Model')!==model)throw new Error('changed');
    const page=new DOMParser().parseFromString(await response.text(),'text/html'),replacement=page.querySelector('[data-preview-frame]');
    if(!replacement?.hasAttribute('srcdoc'))throw new Error('preview');
    frame.srcdoc=replacement.getAttribute('srcdoc');root.dataset.model=model;
    for(const key of ['count','generated']){
      const old=root.querySelector('[data-preview-'+key+']'),next=page.querySelector('[data-preview-'+key+']');
      if(old&&next)old.textContent=next.textContent;
    }
  }
  function prepare(){
    if(pending)return pending;
    busy(true);status.textContent='Pratinjau siap. Menyiapkan file PDF…';controller=new AbortController();
    pending=(async()=>{
      const response=await fetch(root.dataset.pdfUrl,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      if(!response.ok||!(response.headers.get('Content-Type')||'').includes('application/pdf'))throw new Error('pdf');
      const blob=await response.blob(),model=response.headers.get('X-Report-Model');
      if(!blob.size||!model)throw new Error('pdf');
      await refreshPreview(model);
      const nextSignature=(response.headers.get('X-Report-Fingerprint')||'')+'|'+(response.headers.get('X-Report-Generated-At')||'');
      if(!blobUrl||signature!==nextSignature){
        if(blobUrl)retired.push(blobUrl);blobUrl=URL.createObjectURL(blob);signature=nextSignature;
        if(retired.length>8)URL.revokeObjectURL(retired.shift());
      }
      status.textContent='PDF siap.';return blobUrl;
    })().catch(error=>{
      status.textContent=error.message==='changed'?'Data berubah saat laporan disiapkan. Coba lagi untuk memperbarui pratinjau.':'File PDF belum dapat disiapkan. Pratinjau tetap dapat dibaca; silakan coba lagi.';
      retry.hidden=false;throw error;
    }).finally(()=>{pending=null;print.disabled=false;download.setAttribute('aria-disabled','false');});
    return pending;
  }
  async function act(action){
    if(acting)return;acting=true;
    const popup=action==='print'?window.open('about:blank','_blank'):null;
    if(action==='print'&&!popup){acting=false;status.textContent='Izinkan tab cetak untuk membuka PDF.';return;}
    if(popup){popup.opener=null;popup.document.title='Menyiapkan PDF';popup.document.body.textContent='Memeriksa data terbaru dan menyiapkan PDF…';}
    try{
      // A preload may have read its snapshot before the click. Validate again on action.
      if(pending)await pending;
      const url=await prepare();
      if(popup)popup.location.replace(url);
      else{const link=document.createElement('a');link.href=url;link.download=root.dataset.filename;document.body.append(link);link.click();link.remove();}
    }catch(error){popup?.close();}finally{acting=false;}
  }
  print.addEventListener('click',()=>act('print'));
  download.addEventListener('click',event=>{event.preventDefault();act('download');});
  retry.addEventListener('click',()=>prepare().catch(()=>{}));
  function start(){if(started)return;started=true;requestAnimationFrame(()=>requestAnimationFrame(()=>prepare().catch(()=>{})));}
  document.addEventListener('report-preview-ready',start);if(frame.dataset.ready==='1')start();
  addEventListener('pagehide',event=>{if(event.persisted)return;controller?.abort();if(blobUrl)URL.revokeObjectURL(blobUrl);retired.forEach(url=>URL.revokeObjectURL(url));});
})();
