(()=>{
  'use strict';
  const panel=document.getElementById('letter-principal-panel');if(!panel)return;
  const config=JSON.parse(document.getElementById('letter-principal-data').textContent),card=panel.closest('.letter-list-card');
  let busy=false,unavailable=panel.dataset.ready!=='true',stale=false,state=config.states[0];
  const printLinks=[...card.querySelectorAll('.letter-actions a,.letter-action-cell a')],originalLinks=new Map(printLinks.map(link=>[link,link.getAttribute('href')]));
  function blockPrint(blocked){card.dataset.principalBusy=String(blocked);printLinks.forEach(link=>{if(blocked){link.removeAttribute('href');link.setAttribute('aria-disabled','true');}else if(!link.classList.contains('is-disabled')){link.setAttribute('href',originalLinks.get(link));link.removeAttribute('aria-disabled');}});}
  const isPrint=target=>target instanceof Element&&target.closest('#print-selected,.letter-actions a,.letter-action-cell a');
  document.addEventListener('click',event=>{if(isPrint(event.target)&&(busy||unavailable||stale)){event.preventDefault();event.stopImmediatePropagation();}},true);
  blockPrint(unavailable);
  if(config.unit_id===0||unavailable)return;
  const select=document.getElementById('letter-principal-select'),status=document.getElementById('letter-principal-status'),reload=document.getElementById('letter-principal-reload');
  const dialog=document.getElementById('letter-principal-dialog'),form=document.getElementById('letter-principal-form'),name=document.getElementById('letter-principal-name'),replacement=document.getElementById('letter-principal-replacement'),save=document.getElementById('letter-principal-save'),error=document.getElementById('letter-principal-dialog-error');
  const add=document.getElementById('letter-principal-add'),edit=document.getElementById('letter-principal-edit'),remove=document.getElementById('letter-principal-delete');let action='add';
  function feedback(message,failed=false){status.textContent=message;status.classList.toggle('is-error',failed);status.parentElement.hidden=!message;}
  function lock(value){busy=value;panel.setAttribute('aria-busy',String(value));blockPrint(value||stale);[select,add,edit,remove,reload,...form.querySelectorAll('button,input,select')].forEach(el=>el.disabled=value);}
  function render(){select.replaceChildren(...state.names.map(item=>new Option(item.name,String(item.id),false,item.id===state.selected_id)));}
  async function request(payload){
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
    try {
      const response=await fetch('surat_orang_tua_kepala.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':config.csrf},body:JSON.stringify({unit_id:config.unit_id,version:state.version,...payload}),signal:controller.signal});
      const result=await response.json();if(!response.ok||!result.ok){if(response.status===409){stale=true;reload.hidden=false;}throw new Error(result.message||'Perubahan belum tersimpan. Silakan coba kembali.');}
      state=result.state;stale=false;reload.hidden=true;render();return result;
    }catch(e){
      if(e.name==='AbortError'||e instanceof TypeError||e instanceof SyntaxError){stale=true;reload.hidden=false;throw new Error('Status penyimpanan belum dapat dipastikan. Muat ulang daftar sebelum melanjutkan.');}
      throw e;
    }finally{clearTimeout(timer);}
  }
  select.addEventListener('change',async()=>{const id=Number(select.value);lock(true);feedback('Menyimpan pilihan kepala sekolah…');try{await request({action:'select',id});feedback('Pilihan tersimpan. Nama ini akan digunakan pada surat baru.');}catch(e){render();feedback(e.message,true);}finally{lock(false);}});
  function open(kind){
    action=kind;error.hidden=true;const chosen=state.names.find(n=>n.id===state.selected_id);if(!chosen)return;
    const deleting=kind==='delete';
    document.getElementById('letter-principal-dialog-title').textContent=kind==='add'?'Tambah kepala sekolah':deleting?'Hapus kepala sekolah':'Edit kepala sekolah';
    document.getElementById('letter-principal-dialog-description').textContent=kind==='add'?'Nama baru akan langsung dipilih untuk surat unit ini.':deleting?`Hapus “${chosen.name}” dari daftar? Pilih pengganti untuk surat baru. Draf yang sudah dibuat tetap tersimpan.`:'Perubahan berlaku pada surat baru. Draf yang sudah dibuat tetap memakai nama semula.';
    document.getElementById('letter-principal-name-field').hidden=deleting;name.required=!deleting;name.value=kind==='edit'?chosen.name:'';
    document.getElementById('letter-principal-replacement-field').hidden=!deleting;
    replacement.replaceChildren(new Option('Pilih kepala sekolah pengganti',''),...state.names.filter(n=>n.id!==state.selected_id).map(n=>new Option(n.name,String(n.id))));replacement.required=deleting;
    save.textContent=deleting?'Hapus & Pilih Pengganti':'Simpan';save.disabled=false;
    if(deleting&&state.names.length<2){error.textContent='Tambahkan kepala sekolah pengganti sebelum menghapus nama terakhir.';error.hidden=false;save.disabled=true;}
    dialog.showModal();(deleting?replacement:name).focus();
  }
  add.addEventListener('click',()=>open('add'));edit.addEventListener('click',()=>open('edit'));remove.addEventListener('click',()=>open('delete'));
  form.querySelectorAll('.letter-principal-close,.letter-principal-cancel').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy||!form.reportValidity())return;error.hidden=true;lock(true);save.textContent='Menyimpan…';
    try{await request({action,id:state.selected_id,name:name.value,replacement_id:Number(replacement.value)});dialog.close();feedback(action==='delete'?'Nama dihapus dan pengganti tersimpan.':action==='add'?'Nama ditambahkan dan dipilih untuk surat baru.':'Nama kepala sekolah diperbarui.');}
    catch(e){error.textContent=e.message;error.hidden=false;feedback(e.message,true);}
    finally{lock(false);save.textContent=action==='delete'?'Hapus & Pilih Pengganti':'Simpan';}
  });
  reload.addEventListener('click',async()=>{
    lock(true);feedback('Memuat daftar kepala sekolah…');
    try{const response=await fetch('surat_orang_tua_kepala.php',{headers:{'Accept':'application/json'},cache:'no-store'}),result=await response.json();const fresh=result.states?.find(s=>s.unit_id===config.unit_id);if(!response.ok||!result.ok||!fresh)throw new Error('Unit aktif berubah atau sesi berakhir. Pilih kembali unit asal dan muat ulang halaman.');state=fresh;stale=false;reload.hidden=true;render();feedback('Daftar diperbarui. Silakan ulangi perubahan bila diperlukan.');if(dialog.open)dialog.close();}catch(e){feedback(e.message,true);}finally{lock(false);}
  });
})();
