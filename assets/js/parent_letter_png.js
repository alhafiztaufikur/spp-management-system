/* Rasterize the application's PDF itself, preserving its page boundaries and custom messages. */
(() => {
  'use strict';
  const button=document.getElementById('parent-png'),status=document.getElementById('parent-png-status');
  if(!button)return;
  const assetRoot=new URL('../vendor/pdfjs/',document.currentScript.src).href;
  const blobOf=canvas=>new Promise((resolve,reject)=>canvas.toBlob(blob=>blob?resolve(blob):reject(new Error('Halaman PNG gagal dibentuk.')),'image/png'));
  button.addEventListener('click',async()=>{
    if(button.disabled)return;button.disabled=true;status.setAttribute('aria-busy','true');
    let task,pdf,canvas,downloadUrl;
    try{
      status.textContent='Menyiapkan surat…';
      const response=await fetch(button.dataset.pdfUrl,{credentials:'same-origin',cache:'no-store'});
      if(!response.ok||!(response.headers.get('Content-Type')||'').includes('application/pdf'))throw new Error('Surat tidak tersedia. Periksa sesi dan unit, lalu buka kembali pratinjau.');
      const bytes=new Uint8Array(await response.arrayBuffer());
      const pdfjs=await import('../vendor/pdfjs/pdf.min.mjs');
      pdfjs.GlobalWorkerOptions.workerSrc=assetRoot+'pdf.worker.min.mjs';
      task=pdfjs.getDocument({data:bytes,isEvalSupported:false,standardFontDataUrl:assetRoot+'standard_fonts/'});pdf=await task.promise;
      if(!pdf.numPages)throw new Error('Surat tidak memiliki halaman.');
      if(pdf.numPages>1&&!window.JSZip)throw new Error('Pustaka ZIP belum tersedia. Muat ulang halaman.');
      const zip=pdf.numPages>1?new window.JSZip():null;
      const base=button.dataset.filename;let output;
      for(let number=1;number<=pdf.numPages;number++){
        status.textContent='Membentuk PNG '+number+' dari '+pdf.numPages+' halaman…';
        const page=await pdf.getPage(number),viewport=page.getViewport({scale:300/72});
        canvas=document.createElement('canvas');canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
        await page.render({canvasContext:canvas.getContext('2d'),viewport,background:'#ffffff'}).promise;
        const blob=await blobOf(canvas);
        if(zip)zip.file(base+'-halaman-'+String(number).padStart(3,'0')+'.png',blob);else output=blob;
        canvas.width=canvas.height=0;canvas=null;page.cleanup();
      }
      if(zip){status.textContent='Mengemas seluruh halaman…';output=await zip.generateAsync({type:'blob',compression:'STORE'});}
      downloadUrl=URL.createObjectURL(output);const a=document.createElement('a');a.href=downloadUrl;a.download=base+(zip?'.zip':'.png');document.body.append(a);a.click();a.remove();
      const url=downloadUrl;setTimeout(()=>URL.revokeObjectURL(url),60000);downloadUrl=null;
      status.textContent=pdf.numPages+' halaman siap diunduh.';
    }catch(error){status.textContent=error instanceof Error?error.message:'PNG gagal disiapkan. Coba kembali.';}
    finally{
      if(canvas)canvas.width=canvas.height=0;
      if(task)await task.destroy().catch(()=>{});
      if(downloadUrl)URL.revokeObjectURL(downloadUrl);
      button.disabled=false;status.removeAttribute('aria-busy');
    }
  });
})();
