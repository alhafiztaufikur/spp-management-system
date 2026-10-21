(() => {
 'use strict';
 const root=document.querySelector('[data-native-pdf]');if(!root)return;
 const frame=root.querySelector('[data-pdf-frame]'),message=root.querySelector('[data-pdf-message]'),retry=root.querySelector('[data-pdf-retry]'),print=root.querySelector('[data-pdf-print]'),download=root.querySelector('[data-pdf-download]');
 let blobUrl=null,busy=false,fingerprint=null,controller=null;
 const setBusy=value=>{busy=value;print.disabled=value;download.setAttribute('aria-disabled',String(value));retry.hidden=true;};
 async function load(action,popup){
  if(busy){if(popup)popup.close();return;}setBusy(true);message.textContent='Memeriksa data terbaru dan menyiapkan PDF...';
  try{
   controller=new AbortController();
   const response=await fetch(root.dataset.pdfUrl,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
   if(!response.ok||!(response.headers.get('content-type')||'').includes('application/pdf'))throw new Error('PDF unavailable');
   const blob=await response.blob(),signature=(response.headers.get('X-Report-Fingerprint')||'')+'|'+(response.headers.get('X-Report-Generated-At')||'');
   const unchanged=signature!=='|'&&signature===fingerprint&&blobUrl;
   const next=unchanged?blobUrl:URL.createObjectURL(blob),old=unchanged?null:blobUrl;blobUrl=next;fingerprint=signature;if(!unchanged)frame.src=next;frame.hidden=false;
   // Opened print viewers may still reference the old blob; release it on leaving this page.
   if(old)retired.push(old);if(retired.length>8)URL.revokeObjectURL(retired.shift());
   message.textContent='PDF siap dengan data terbaru. Gunakan Cetak atau Download PDF.';
   const generated=response.headers.get('X-Report-Generated-At'),stamp=root.querySelector('[data-pdf-generated]');
   const rowCount=response.headers.get('X-Report-Row-Count'),count=root.querySelector('[data-pdf-count]');
   if(rowCount!==null&&/^\d+$/.test(rowCount)&&count)count.textContent=new Intl.NumberFormat('id-ID').format(Number(rowCount))+' data';
   if(generated&&stamp)stamp.textContent='Dibuat '+new Intl.DateTimeFormat('id-ID',{dateStyle:'short',timeStyle:'medium',timeZone:'Asia/Jakarta'}).format(new Date(generated))+' WIB';
   if(action==='print'&&popup)popup.location.replace(next);
   if(action==='download'){
    const link=document.createElement('a');link.href=next;link.download=root.dataset.filename;document.body.append(link);link.click();link.remove();
   }
  }catch(error){if(popup)popup.close();message.textContent='PDF belum dapat dimuat. Coba lagi atau buka tautan PDF.';retry.hidden=false;}
  finally{busy=false;print.disabled=!blobUrl;download.setAttribute('aria-disabled','false');}
 }
 const retired=[];
 print.addEventListener('click',()=>{if(busy)return;const popup=window.open('about:blank','_blank');if(popup){popup.opener=null;popup.document.title='Menyiapkan PDF';popup.document.body.textContent='Memeriksa data terbaru dan menyiapkan PDF...';load('print',popup);}else{message.textContent='Izinkan tab cetak atau gunakan tautan Buka PDF.';}});
 download.addEventListener('click',event=>{event.preventDefault();if(!busy)load('download');});retry.addEventListener('click',()=>load());
 root.querySelector('[data-pdf-back]')?.addEventListener('click',event=>{
  if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||root.dataset.closeOnBack!=='1')return;
  event.preventDefault();const fallback=event.currentTarget.href;controller?.abort();window.close();
  // Directly opened preview tabs may not be script-closable; their normal back link still works.
  setTimeout(()=>{location.href=fallback;},100);
 });
 window.addEventListener('pagehide',event=>{if(event.persisted)return;controller?.abort();if(blobUrl)URL.revokeObjectURL(blobUrl);retired.forEach(url=>URL.revokeObjectURL(url));});load();
})();
