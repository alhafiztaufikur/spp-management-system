(() => {
  'use strict';
  const data=JSON.parse(document.getElementById('parent-draft-data').textContent);
  const recipients=data.recipients,buttons=[...document.querySelectorAll('.parent-recipient')];
  const editor=document.getElementById('parent-message'),status=document.getElementById('parent-save-status');
  const messages=data.messages&&!Array.isArray(data.messages)?data.messages:{};
  const changes=new Map(),versions=new Map(),dialog=document.getElementById('parent-copy-dialog');
  const list=document.getElementById('copy-recipient-list'),all=document.getElementById('copy-select-all');
  const apply=document.getElementById('copy-apply'),targets=new Set();
  let current='',timer,chain=Promise.resolve(),busy=false,copyBusy=false,range=null,chooseVersion=0;
  const escape=text=>text.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function plain(message){if(typeof message==='string')return message;return htmlText(message?.html||'');}
  function htmlText(html){const root=document.createElement('div');root.innerHTML=html.replace(/<br\s*\/?\s*>/gi,'\n').replace(/<\/(p|li)>/gi,'\n');return root.textContent.trim();}
  function clean(source){
    const root=document.createElement('div');root.innerHTML=source;
    root.querySelectorAll('script,style,iframe,object,embed,svg,math,form,input,button,textarea,select,template,head').forEach(n=>n.remove());
    const tags=new Set(['P','BR','STRONG','EM','U','UL','OL','LI']);
    [...root.querySelectorAll('*')].reverse().forEach(node=>{
      const alias={B:'strong',I:'em',DIV:'p'}[node.tagName];
      if(alias){const replacement=document.createElement(alias);replacement.append(...node.childNodes);node.replaceWith(replacement);node=replacement;}
      if(!tags.has(node.tagName)){node.replaceWith(...node.childNodes);return;}
      [...node.attributes].forEach(a=>node.removeAttribute(a.name));
    });
    return root.innerHTML;
  }
  function html(message){if(!plain(message))return '';return typeof message==='string'?message.split(/\n\s*\n/u).map(p=>'<p>'+escape(p).replace(/\n/g,'<br>')+'</p>').join(''):clean(message?.html||'');}
  function value(){const result=clean(editor.innerHTML);return htmlText(result)?{format:'rich_text',html:result}:'';}
  function markRecipient(key){const button=buttons.find(b=>b.dataset.key===key);button.querySelector('[data-message-status]').textContent=plain(messages[key])?'Pesan tambahan terisi':'Tanpa pesan tambahan';}
  function count(){
    const length=Array.from(plain(value())).length,valid=length<=2000;
    editor.dataset.empty=String(length===0);
    document.getElementById('parent-message-count').textContent=length.toLocaleString('id-ID')+' / 2.000 karakter';
    editor.setAttribute('aria-invalid',String(!valid));
    document.getElementById('parent-copy').disabled=busy||!valid||!plain(value())||recipients.length<2;
    return valid;
  }
  function capture(){
    if(!current)return;
    const message=value();
    if(JSON.stringify(message)!==JSON.stringify(messages[current]||'')){
      messages[current]=message;changes.set(current,message);versions.set(current,(versions.get(current)||0)+1);markRecipient(current);
    }
    count();
  }
  const report=error=>{status.textContent=error.message+' Tekan Simpan Draf untuk mencoba lagi.';};
  function enqueue(task){const work=chain.catch(()=>{}).then(task);chain=work;return work;}
  async function request(payload){
    const response=await fetch('surat_orang_tua_draf.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':data.csrf},body:JSON.stringify({draft:data.token,...payload})});
    let result;try{result=await response.json();}catch(error){throw Error('Penyimpanan draf belum tersedia.');}
    if(!response.ok||!result.ok)throw Error(result.message||'Draf belum tersimpan.');return result;
  }
  function save(){
    capture();clearTimeout(timer);
    if(!count())return Promise.reject(Error('Pesan setiap siswa maksimal 2.000 karakter.'));
    return enqueue(async()=>{
      if(!changes.size){status.textContent='Draf tersimpan sementara.';return;}
      const patch=Object.fromEntries(changes),expected=new Map(versions);
      if(Object.values(patch).some(m=>Array.from(plain(m)).length>2000))throw Error('Pesan setiap siswa maksimal 2.000 karakter.');
      status.textContent='Menyimpan draf…';
      const result=await request({action:'save',messages:patch});
      Object.entries(result.messages).forEach(([key,message])=>{
        if(versions.get(key)===expected.get(key)){changes.delete(key);messages[key]=message;markRecipient(key);}
      });
      status.textContent=changes.size?'Ada perubahan yang belum tersimpan.':'Draf tersimpan sementara.';
    });
  }
  function showRecipient(button){
    current=button.dataset.key;buttons.forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
    editor.innerHTML=html(messages[current]||'');range=null;
    const recipient=recipients.find(r=>r.key===current);
    document.getElementById('recipient-name').textContent=recipient.name;
    document.getElementById('recipient-identity').textContent=recipient.unit+' · '+recipient.nis+' · '+recipient.class;
    for(const [id,field] of [['summary-nis','nis'],['summary-diknas','diknas'],['summary-class','class'],['summary-unit','unit']])document.getElementById(id).textContent=recipient[field]||'Tidak tercatat';
    count();
  }
  buttons.forEach(button=>button.addEventListener('click',async()=>{
    if(busy||button.dataset.key===current)return;
    lock(true);
    const generation=++chooseVersion;
    try{await save();if(generation===chooseVersion)showRecipient(button);}catch(error){report(error);}finally{lock(false);}
  }));
  function changed(){capture();status.textContent='Ada perubahan yang belum tersimpan.';clearTimeout(timer);timer=setTimeout(()=>save().catch(report),600);}
  editor.addEventListener('input',changed);
  editor.addEventListener('paste',event=>{event.preventDefault();document.execCommand('insertText',false,event.clipboardData.getData('text/plain'));changed();});
  // Prevent an HTML drag payload from inserting images, attributes, or active nodes.
  editor.addEventListener('drop',event=>{event.preventDefault();document.execCommand('insertText',false,event.dataTransfer.getData('text/plain'));changed();});
  document.addEventListener('selectionchange',()=>{
    const selection=getSelection();if(selection.rangeCount&&editor.contains(selection.anchorNode)&&editor.contains(selection.focusNode)){
      range=selection.getRangeAt(0).cloneRange();
      document.querySelectorAll('[data-format][aria-pressed]').forEach(b=>b.setAttribute('aria-pressed',String(document.queryCommandState(b.dataset.format))));
    }
  });
  document.querySelectorAll('[data-format]').forEach(button=>{
    button.addEventListener('mousedown',event=>{const selection=getSelection();if(selection.rangeCount&&editor.contains(selection.anchorNode)&&editor.contains(selection.focusNode))range=selection.getRangeAt(0).cloneRange();event.preventDefault();});
    button.addEventListener('click',()=>{editor.focus();if(range){const selection=getSelection();selection.removeAllRanges();selection.addRange(range);}document.execCommand(button.dataset.format,false,button.dataset.value||null);changed();});
  });
  document.getElementById('recipient-search').addEventListener('input',event=>{
    const q=event.target.value.toLocaleLowerCase('id');let visible=0;
    buttons.forEach(b=>{b.hidden=!b.textContent.toLocaleLowerCase('id').includes(q);if(!b.hidden)visible++;});
    document.getElementById('parent-recipient-count').textContent='Menampilkan '+visible+' dari '+buttons.length+' penerima';
  });
  function lock(locked){busy=locked;editor.contentEditable=String(!locked);buttons.forEach(b=>b.disabled=locked);document.querySelectorAll('[data-format],#parent-save,#parent-preview').forEach(b=>b.disabled=locked);count();}
  document.getElementById('parent-save').addEventListener('click',async()=>{lock(true);try{await save();}catch(error){report(error);}finally{lock(false);}});
  document.getElementById('parent-preview').addEventListener('click',async()=>{if(busy)return;lock(true);try{await save();location.href='surat_orang_tua_susun.php?draft='+encodeURIComponent(data.token)+'&preview=1';}catch(error){report(error);lock(false);}});
  function copyState(){
    const others=recipients.filter(r=>r.key!==current);
    all.checked=others.length>0&&others.every(r=>targets.has(r.key));all.indeterminate=targets.size>0&&!all.checked;
    document.getElementById('copy-selected-count').textContent=targets.size+' penerima dipilih';
    document.getElementById('copy-total').textContent='('+others.length+')';
    apply.disabled=copyBusy||!targets.size||!plain(value());
    list.querySelectorAll('input').forEach(check=>check.checked=targets.has(check.value));
  }
  function closeCopy(){if(copyBusy)return;dialog.close();document.getElementById('parent-copy').focus();}
  document.getElementById('parent-copy').addEventListener('click',()=>{
    capture();if(!count()||!plain(value()))return;
    targets.clear();
    document.getElementById('copy-result').textContent='';document.getElementById('copy-recipient-search').value='';
    document.getElementById('copy-source-name').textContent=recipients.find(r=>r.key===current).name;
    list.replaceChildren();
    recipients.filter(r=>r.key!==current).forEach(recipient=>{
      const row=document.createElement('label');row.className='parent-copy-target';
      const check=document.createElement('input');check.type='checkbox';check.value=recipient.key;
      const text=document.createElement('span'),name=document.createElement('strong'),identity=document.createElement('small');
      name.textContent=recipient.name;identity.textContent=recipient.unit+' · '+recipient.nis+' · '+recipient.class+(plain(messages[recipient.key])?' · Pesan sudah terisi':' · Tanpa pesan');
      text.append(name,identity);row.append(check,text);list.append(row);check.addEventListener('change',()=>{check.checked?targets.add(check.value):targets.delete(check.value);copyState();});
    });
    copyState();dialog.showModal();document.getElementById('copy-recipient-search').focus();
  });
  all.addEventListener('change',()=>{targets.clear();if(all.checked)recipients.filter(r=>r.key!==current).forEach(r=>targets.add(r.key));copyState();});
  document.getElementById('copy-recipient-search').addEventListener('input',event=>{const query=event.target.value.toLocaleLowerCase('id');list.querySelectorAll('label').forEach(row=>row.hidden=!row.textContent.toLocaleLowerCase('id').includes(query));});
  dialog.querySelectorAll('[data-copy-close]').forEach(button=>button.addEventListener('click',closeCopy));
  dialog.addEventListener('cancel',event=>{event.preventDefault();closeCopy();});
  dialog.addEventListener('click',event=>{const r=dialog.getBoundingClientRect();if(event.target===dialog&&(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom))closeCopy();});
  apply.addEventListener('click',async()=>{
    if(copyBusy||apply.disabled)return;
    const source=current,selected=[...targets];
    copyBusy=true;lock(true);copyState();
    dialog.querySelectorAll('input,[data-copy-close]').forEach(e=>e.disabled=true);
    try{
      await save();
      const payload={action:'apply_message',source_key:source,message:messages[source],targets:selected,overwrite:true};
      const result=await enqueue(()=>request(payload));
      Object.entries(result.messages).forEach(([key,message])=>{messages[key]=message;changes.delete(key);versions.set(key,(versions.get(key)||0)+1);markRecipient(key);});
      editor.innerHTML=html(messages[current]||'');range=null;
      status.textContent='Pesan diterapkan ke '+result.updated+' penerima.';
      copyBusy=false;dialog.close();document.getElementById('parent-copy').focus();
    }catch(error){document.getElementById('copy-result').textContent=error.message+' Coba Terapkan Pesan kembali.';}
    finally{copyBusy=false;lock(false);dialog.querySelectorAll('input,[data-copy-close]').forEach(e=>e.disabled=false);copyState();}
  });
  window.addEventListener('beforeunload',event=>{if(changes.size){event.preventDefault();event.returnValue='';}});
  if(buttons.length)showRecipient(buttons[0]);
})();
