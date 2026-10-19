(() => {
 'use strict';
 const root=document.querySelector('[data-book-print]');if(!root)return;
 const form=root.querySelector('[data-book-form]'),fields=[...root.querySelectorAll('[data-layout-key]')],status=root.querySelector('[data-book-status]'),config=JSON.parse(root.querySelector('[data-book-config]').textContent);
 const inform=message=>{status.textContent=message;};
 const maxRow=()=>{const side=form.elements.start_side.value;form.elements.start_row.max=form.elements['layout[rows_'+side+']'].value;};
 form.elements.start_side.addEventListener('change',maxRow);fields.forEach(input=>input.addEventListener('input',maxRow));
 try{if(!config.explicit_layout){const saved=JSON.parse(localStorage.getItem(root.dataset.storageKey)||'null');if(saved&&typeof saved==='object'){
  fields.forEach(input=>{const value=saved[input.dataset.layoutKey];if(typeof value==='number'&&Number.isFinite(value))input.value=String(value);});maxRow();inform('Kalibrasi tersimpan dimuat. Pilih Terapkan Preview untuk memperbarui panduan.');
 }}}catch(e){inform('Penyimpanan browser tidak tersedia; pengaturan tetap dapat digunakan.');}
 root.querySelector('[data-book-save]').addEventListener('click',()=>{if(!form.reportValidity())return;try{localStorage.setItem(root.dataset.storageKey,JSON.stringify(Object.fromEntries(fields.map(input=>[input.dataset.layoutKey,Number(input.value)]))));inform('Kalibrasi disimpan di browser ini.');}catch(e){inform('Kalibrasi tidak dapat disimpan pada browser ini.');}});
 root.querySelector('[data-book-reset]').addEventListener('click',()=>{fields.forEach(input=>{input.value=input.dataset.default;});try{localStorage.removeItem(root.dataset.storageKey);}catch(e){}maxRow();inform('Kalibrasi dikembalikan ke preset. Pilih Terapkan Preview.');});
 form.addEventListener('submit',event=>{
  if(Number(form.elements.entry_from.value)>Number(form.elements.entry_to.value)){event.preventDefault();inform('Transaksi pertama harus mendahului transaksi terakhir.');return;}
  if(event.submitter&&event.submitter.hasAttribute('data-book-test')){
   event.preventDefault();if(!form.reportValidity())return;const params=new URLSearchParams(new FormData(form));params.set('output','pdf');params.set('calibration','1');window.open(form.action+'?'+params.toString(),'_blank','noopener');
  }
 });
})();
