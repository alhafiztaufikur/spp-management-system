(() => {
  const data=JSON.parse(document.getElementById('parent-draft-data').textContent);
  const buttons=[...document.querySelectorAll('.parent-recipient')];
  const editor=document.getElementById('parent-message'),status=document.getElementById('parent-save-status');
  const messages=data.messages && !Array.isArray(data.messages)?data.messages:{};
  let current='',dirty=false,timer,chain=Promise.resolve();
  function capture(){if(!current)return;messages[current]=editor.value;const button=buttons.find(b=>b.dataset.key===current);button.querySelector('[data-message-status]').textContent=editor.value.trim()?'Pesan custom terisi':'Tanpa pesan tambahan';document.getElementById('parent-message-count').textContent=Array.from(editor.value).length+' / 2.000 karakter';}
  function choose(button){capture();current=button.dataset.key;buttons.forEach(b=>b.setAttribute('aria-pressed',String(b===button)));editor.value=messages[current]||'';document.getElementById('recipient-name').textContent=button.querySelector('strong').textContent;document.getElementById('recipient-identity').textContent=button.querySelector('small').textContent;capture();}
  function save(){capture();clearTimeout(timer);const payload=JSON.stringify({draft:data.token,messages});status.textContent='Menyimpan draf…';
    const task=chain.catch(()=>{}).then(async()=>{const response=await fetch('surat_orang_tua_draf.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':data.csrf},body:payload});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'Draf belum tersimpan.');if(payload===JSON.stringify({draft:data.token,messages}))dirty=false;status.textContent=dirty?'Ada perubahan yang belum tersimpan.':'Draf tersimpan sementara.';});chain=task;return task;}
  const report=error=>{status.textContent=error.message+' Tekan Simpan Draf untuk mencoba lagi.';};
  buttons.forEach(button=>button.addEventListener('click',()=>choose(button)));
  editor.addEventListener('input',()=>{dirty=true;capture();status.textContent='Ada perubahan yang belum tersimpan.';clearTimeout(timer);timer=setTimeout(()=>save().catch(report),600);});
  document.getElementById('recipient-search').addEventListener('input',event=>{const q=event.target.value.toLocaleLowerCase('id');buttons.forEach(b=>b.hidden=!b.textContent.toLocaleLowerCase('id').includes(q));});
  document.getElementById('parent-save').addEventListener('click',()=>save().catch(report));
  document.getElementById('parent-preview').addEventListener('click',async event=>{event.target.disabled=true;editor.disabled=true;buttons.forEach(button=>button.disabled=true);try{await save();location.href='surat_orang_tua_susun.php?draft='+encodeURIComponent(data.token)+'&preview=1';}catch(error){report(error);event.target.disabled=false;editor.disabled=false;buttons.forEach(button=>button.disabled=false);}});
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
  if(buttons.length)choose(buttons[0]);
})();
