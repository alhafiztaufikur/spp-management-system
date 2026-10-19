/* Print and PNG share a paginator; all dependencies are local. */
(() => {
  'use strict';
  const button=document.querySelector('[data-receipt-png]'),status=document.querySelector('[data-receipt-export-status]');if(!button)return;
  const blobOf=canvas=>new Promise((resolve,reject)=>canvas.toBlob(blob=>blob?resolve(blob):reject(new Error('Gambar gagal dibentuk.')),'image/png'));
  function download(blob,name){const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=name;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);}
  button.addEventListener('click',async()=>{
    if(button.disabled)return;button.disabled=true;document.body.classList.add('receipt-exporting');let holder;
    try{
      if(!window.html2canvas||!window.JSZip||!window.receiptPagination)throw new Error('Pustaka gambar belum tersedia. Muat ulang halaman.');
      await Promise.resolve(document.fonts&&document.fonts.ready);window.receiptPagination.prepare();
      const pages=[...document.querySelectorAll('.receipt-print-page')];if(!pages.length)throw new Error('Tidak ada halaman transaksi.');
      const zip=pages.length>1?new JSZip():null;holder=document.createElement('div');holder.className='receipt-image-holder';document.body.append(holder);
      const title=(document.title||'Bukti-Transaksi').replace(/[^a-zA-Z0-9_-]+/g,'-').slice(0,100);
      for(let i=0;i<pages.length;i++){
        status.textContent='Membentuk gambar '+(i+1)+' dari '+pages.length+'…';holder.replaceChildren();const paper=document.createElement('div');paper.className='receipt-image-paper';paper.append(pages[i].querySelector('.receipt-document').cloneNode(true));holder.append(paper);
        await new Promise(resolve=>requestAnimationFrame(resolve));let canvas;
        try{canvas=await html2canvas(paper,{scale:2550/paper.getBoundingClientRect().width,backgroundColor:'#ffffff',logging:false,useCORS:false});
          const output=document.createElement('canvas');output.width=2550;output.height=1650;output.getContext('2d').drawImage(canvas,0,0,2550,1650);const blob=await blobOf(output);output.width=output.height=0;
          if(zip)zip.file(title+'-'+String(i+1).padStart(3,'0')+'.png',blob);else download(blob,title+'.png');
        }finally{if(canvas)canvas.width=canvas.height=0;}
      }
      if(zip){status.textContent='Menyiapkan ZIP…';download(await zip.generateAsync({type:'blob',compression:'STORE'}),title+'.zip');}
      status.textContent='Gambar selesai dibuat. Periksa unduhan browser.';
    }catch(error){status.textContent='Unduhan gagal: '+error.message+' Silakan coba kembali.';}
    finally{holder?.remove();window.receiptPagination?.clean();document.body.classList.remove('receipt-exporting');button.disabled=false;}
  });
})();
