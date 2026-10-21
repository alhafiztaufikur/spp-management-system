(() => {
  'use strict';
  const form=document.querySelector('[data-savings-entry]');
  const dialog=document.getElementById('savings-entry-dialog');
  const amount=form?.elements.nominal;
  if(!dialog)return;
  let source=amount;
  const group=digits=>digits.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
  function formatted(raw,canonical=false){
    raw=String(raw).trim();
    if(canonical&&/^\d+\.\d{1,2}$/.test(raw))raw=raw.replace('.',',');
    const parts=raw.replace(/[^\d,]/g,'').split(',');
    const integer=(parts[0]||'').replace(/^0+(?=\d)/,'');
    return group(integer)+(parts.length>1?','+(parts[1]||''):'');
  }
  const validRaw=raw=>/^[1-9][0-9]{0,2}(?:\.[0-9]{3})+(?:,[0-9]{1,2})?$/.test(raw)||/^[0-9]+(?:[.,][0-9]{1,2})?$/.test(raw);
  function cents(raw){
    const value=String(raw).replace(/\./g,'');
    if(!/^\d+(?:,\d{1,2})?$/.test(value))return null;
    const [whole,fraction='']=value.split(',');
    return BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));
  }
  const money=raw=>'Rp '+formatted(raw,true).replace(/,00$/,'');
  function finishTypedAmount(){
    if(!amount?.dataset.pendingDot)return;
    delete amount.dataset.pendingDot;
    if(!/^[\d.]+$/.test(amount.value))return;
    const last=amount.value.lastIndexOf('.'),fraction=amount.value.slice(last+1);
    if(last>=0&&fraction.length<=2)amount.value=formatted(amount.value.slice(0,last).replace(/\./g,'')+(fraction?','+fraction:''));
  }
  function warn(title,message,withAmounts=false){
    document.getElementById('savings-entry-dialog-title').textContent=title;
    document.getElementById('savings-entry-dialog-message').textContent=message;
    document.getElementById('savings-entry-dialog-summary').hidden=!withAmounts;
    if(withAmounts){
      document.getElementById('savings-entry-dialog-balance').textContent=money(form.dataset.balance);
      document.getElementById('savings-entry-dialog-amount').textContent='Rp '+amount.value;
    }
    if(!dialog.open)dialog.showModal();
    document.getElementById('savings-entry-dialog-close').focus();
  }
  document.getElementById('savings-entry-dialog-close').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('close',()=>source?.focus());
  if(amount){
    amount.value=amount.value.trim();
    if(!amount.value||validRaw(amount.value))amount.value=formatted(amount.value,true);
    else{amount.dataset.invalidFormat='1';amount.setAttribute('aria-invalid','true');}
    amount.addEventListener('input',event=>{
      const trimmed=amount.value.trim();if(trimmed!==amount.value)amount.value=trimmed;
      if(amount.dataset.invalidFormat==='1'){
        if(amount.value&&!validRaw(amount.value))return;
        delete amount.dataset.invalidFormat;amount.value=formatted(amount.value,true);
      }
      if(/[^\d.,]/.test(amount.value))return;
      if(event.inputType==='insertText'&&event.data===amount.value&&event.data.length>1&&!validRaw(amount.value)){
        amount.dataset.invalidFormat='1';amount.setAttribute('aria-invalid','true');return;
      }
      if(event.inputType==='insertText'&&event.data==='.'){
        if(!amount.value.includes(',')){amount.dataset.pendingDot='1';amount.removeAttribute('aria-invalid');return;}
      }
      if(amount.dataset.pendingDot){
        const last=amount.value.lastIndexOf('.'),fraction=amount.value.slice(last+1);
        if(last>=0&&!amount.value.includes(',')&&fraction.length<3){amount.removeAttribute('aria-invalid');return;}
        delete amount.dataset.pendingDot;
      }else if(event.data===amount.value&&/^\d+\.\d{1,2}$/.test(amount.value))amount.value=amount.value.replace('.',',');
      const pos=amount.selectionStart??amount.value.length;
      const tail=amount.value.length-pos;
      amount.value=formatted(amount.value);
      const next=Math.max(0,amount.value.length-tail);amount.setSelectionRange(next,next);
      amount.removeAttribute('aria-invalid');
    });
    amount.addEventListener('paste',event=>{
      delete amount.dataset.pendingDot;
      const text=event.clipboardData?.getData('text')?.trim();
      if(text&&!validRaw(text)){
        event.preventDefault();amount.value=text;amount.dataset.invalidFormat='1';amount.setAttribute('aria-invalid','true');return;
      }
      if(text&&/^\d+\.\d{1,2}$/.test(text)){
        event.preventDefault();amount.setRangeText(text.replace('.',','),amount.selectionStart,amount.selectionEnd,'end');amount.dispatchEvent(new Event('input',{bubbles:true}));
      }
    });
    amount.addEventListener('blur',finishTypedAmount);
    form.addEventListener('submit',event=>{
      finishTypedAmount();
      const value=amount.dataset.invalidFormat==='1'?null:cents(amount.value),balance=cents(formatted(form.dataset.balance,true));
      let problem;
      if(value===null||value<=0n||value>999999999999999n)problem=['Periksa nominal','Isi nominal positif dengan maksimal dua desimal.'];
      else if(form.dataset.kind==='keluar'&&balance!==null&&value>balance)problem=['Saldo tidak mencukupi','Nominal penarikan melebihi saldo tersedia. Silakan kurangi nominal penarikan.',true];
      if(problem){event.preventDefault();event.stopImmediatePropagation();amount.setAttribute('aria-invalid','true');source=amount;warn(...problem);}
    },true);
    window.addEventListener('pageshow',async event=>{
      if(!event.persisted)return;
      const query=new URLSearchParams();
      for(const key of ['student_id','owner_id'])if(form.elements[key]?.value)query.set(key,form.elements[key].value);
      try{const response=await fetch('get_saldo.php?'+query,{cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json();if(response.ok&&Number.isFinite(Number(data.raw_saldo)))form.dataset.balance=Math.max(0,Number(data.raw_saldo)).toFixed(2);}catch(_){/* The server checks the authoritative balance on submit. */}
    });
  }
  const feedback=document.querySelector('.savings-entry-feedback[data-error="1"]');
  if(feedback){
    const message=feedback.textContent.trim();
    warn(/saldo.*(cukup|kurang|kosong)|melebihi saldo|saldo.*minus/i.test(message)?'Saldo tidak mencukupi':'Tabungan belum tersimpan',message,!!amount&&/saldo/i.test(message));
    feedback.hidden=true;
  }
})();
