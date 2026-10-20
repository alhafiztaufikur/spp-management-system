/* Delegation keeps correction controls alive when the detail panel is replaced. */
(() => {
  'use strict';
  const detail=document.getElementById('sw-history-detail');if(!detail)return;
  const money=value=>'Rp '+Number(value||0).toLocaleString('id-ID');
  const digitsOf=value=>value.replace(/\./g,'').replace(',','.');
  function validateAmount(input){
    const raw=input.value;
    const valid=/^(?:[0-9]{1,12}|[1-9][0-9]{0,2}(?:\.[0-9]{3}){1,3})(?:,[0-9]{1,2})?$/.test(raw)&&digitsOf(raw).split('.')[0].length<=12&&Number(digitsOf(raw))>0;
    input.setCustomValidity(valid?'':'Isi nominal rupiah positif, maksimal 12 digit dan dua desimal, misalnya 90.000,50.');
    return valid;
  }
  detail.addEventListener('input',event=>{
    const input=event.target.closest('[data-savings-amount]');if(!input)return;
    const value=input.value,position=input.selectionStart??value.length;
    if(value.includes(',')){validateAmount(input);return;}
    const count=value.slice(0,position).replace(/\D/g,'').length;
    // Do not silently reinterpret decimal, signed, or pasted malformed amounts.
    if(!/^[0-9.]*$/.test(value)){validateAmount(input);return;}
    const digits=digitsOf(value);
    if(digits.length>12){validateAmount(input);return;}
    const formatted=digits.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    input.value=formatted;
    let caret=0,seen=0;
    while(caret<formatted.length&&seen<count){if(/\d/.test(formatted[caret]))seen++;caret++;}
    input.setSelectionRange(caret,caret);validateAmount(input);
  });
  detail.addEventListener('paste',event=>{
    const input=event.target.closest('[data-savings-amount]');if(!input)return;
    const text=event.clipboardData?.getData('text');if(text===undefined)return;
    if(!/^(?:[0-9]+|[1-9][0-9]{0,2}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/.test(text)){
      event.preventDefault();input.setCustomValidity('Format tempelan tidak valid. Gunakan angka atau format seperti 90.000.');input.reportValidity();
    }
  });
  let active=null,opener=null,activityController=null,activityGeneration=0;
  const activity=document.createElement('dialog');activity.className='sw-confirm-dialog sw-activity-dialog';
  activity.setAttribute('aria-label','Riwayat Aktivitas Tabungan');
  activity.innerHTML='<header class="sw-confirm-heading"><h3>Riwayat Aktivitas</h3><button type="button" class="btn btn-ghost" data-activity-close>Tutup</button></header><div class="sw-confirm-body" data-activity-body aria-live="polite"></div>';
  document.body.append(activity);
  document.addEventListener('keydown',event=>{
    if(event.key!=='Tab')return;
    const dialog=event.target.closest('.sw-editor-dialog[open],.sw-activity-dialog[open]');if(!dialog)return;
    const buttons=[...dialog.querySelectorAll('button,input,textarea,select,a[href],[tabindex]')].filter(el=>!el.disabled&&el.tabIndex>=0&&el.getClientRects().length);
    const first=buttons[0],last=buttons[buttons.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
  });
  const restore=()=>{if(opener?.isConnected)opener.focus({preventScroll:true});};
  activity.addEventListener('close',()=>{activityController?.abort();activityGeneration++;restore();});
  activity.querySelector('[data-activity-close]').addEventListener('click',()=>activity.close());
  new MutationObserver(()=>{
    if(active?.open&&!active.isConnected){active.close();active=null;}
    if(activity.open&&!opener?.isConnected)activity.close();
  }).observe(detail,{childList:true,subtree:true});
  detail.addEventListener('click',async event=>{
    const open=event.target.closest('[data-savings-open]');
    if(open){
      opener=open;const dialog=detail.querySelector('[data-savings-editor="'+open.dataset.savingsOpen+'"]');if(!dialog)return;
      active=dialog;dialog.querySelector('.sw-editor-stage').hidden=false;dialog.querySelector('.sw-review-stage').hidden=true;
      dialog.onclose=restore;dialog.showModal();dialog.querySelector('input:not([type="hidden"]),textarea,button')?.focus();return;
    }
    if(event.target.closest('[data-savings-close]')){active?.close();return;}
    const button=event.target.closest('[data-savings-activity]');if(!button)return;
    opener=button;activityController?.abort();activityController=new AbortController();const generation=++activityGeneration;
    const body=activity.querySelector('[data-activity-body]');body.textContent='Memuat riwayat aktivitas...';activity.showModal();
    const params=new URLSearchParams({jenis:button.dataset.kind,id:button.dataset.id});
    try{
      const response=await fetch('aktivitas.php?'+params,{signal:activityController.signal,headers:{Accept:'application/json'},cache:'no-store'});
      const payload=await response.json();if(!response.ok||!payload.ok)throw Error('Riwayat aktivitas belum dapat dimuat. Silakan coba lagi.');
      if(generation===activityGeneration&&button.isConnected){body.innerHTML=payload.html;}
    }catch(error){if(error.name!=='AbortError'&&generation===activityGeneration)body.textContent='Riwayat aktivitas belum dapat dimuat. Tutup dialog lalu coba lagi.';}
  });
  detail.addEventListener('submit',event=>{
    const form=event.target.closest('[data-savings-confirm]');if(!form)return;
    if(form.dataset.savingsSending){event.preventDefault();return;}
    const amount=form.querySelector('[data-savings-amount]');
    if(amount&&!validateAmount(amount)){event.preventDefault();amount.reportValidity();return;}
    if(form.dataset.savingsConfirmed==='1'){
      delete form.dataset.savingsConfirmed;form.dataset.savingsSending='1';
      setTimeout(()=>active?.querySelectorAll('button').forEach(b=>b.disabled=true),0);return;
    }
    event.preventDefault();if(!form.reportValidity())return;
    const dialog=form.closest('dialog');if(!dialog?.open)return;
    const review=dialog.querySelector('.sw-review-stage');review.innerHTML='<header class="sw-confirm-heading"><span data-sw-icon aria-hidden="true"></span><div><small>TRANSAKSI TABUNGAN</small><h3 id="sw-confirm-title"></h3></div></header><div class="sw-confirm-body"><div class="sw-confirm-identity"><strong data-sw-student></strong><small data-sw-reference></small></div><p id="sw-confirm-copy"></p><dl class="sw-confirm-summary" data-sw-summary></dl><div class="sw-confirm-reason"><small>Alasan</small><p data-sw-reason></p></div><footer class="sw-confirm-actions"><button class="btn btn-ghost" type="button" data-sw-back>Kembali</button><button class="btn btn-ghost" type="button" data-sw-cancel>Batal</button><button class="btn btn-primary" type="button" data-sw-accept>Simpan Koreksi</button></footer></div>';
    const accept=review.querySelector('[data-sw-accept]');
    const action=form.elements.action.value,deletion=action.endsWith('hapus'),proposal=action.startsWith('request_');
    dialog.dataset.danger=String(deletion);
    review.querySelector('#sw-confirm-title').textContent=proposal?(deletion?'Ajukan penghapusan transaksi?':'Ajukan koreksi transaksi?'):(deletion?'Hapus transaksi ini?':'Simpan koreksi transaksi?');
    review.querySelector('[data-sw-student]').textContent=form.dataset.student;
    review.querySelector('[data-sw-reference]').textContent=form.dataset.reference;
    review.querySelector('#sw-confirm-copy').textContent=proposal?'Pengajuan akan diperiksa Super Admin. Transaksi dan saldo berubah setelah pengajuan disetujui.':deletion?'Transaksi akan dikeluarkan dari saldo aktif. Riwayat dan alasan penghapusan tetap tersimpan. Saldo akan diperiksa ulang.':'Perubahan akan disimpan dan saldo diperiksa ulang. Pembuat awal dan riwayat aktivitas tetap tercatat.';
    review.querySelector('[data-sw-reason]').textContent=form.elements.reason.value.trim();
    const summary=review.querySelector('[data-sw-summary]');summary.replaceChildren();
    const line=(label,before,after)=>{
      const row=document.createElement('div'),dt=document.createElement('dt'),dd=document.createElement('dd');
      dt.textContent=label;dd.textContent=after===undefined?before:before+' → '+after;row.append(dt,dd);summary.append(row);
    };
    if(deletion)line('Nominal transaksi',money(form.dataset.originalAmount));
    else{
      line('Nominal',money(form.dataset.originalAmount),money(digitsOf(amount.value)));
      let date=form.elements.tanggal.value.replace('T',' ');if(date.length===16)date+=':00';
      const dateLabel=value=>window.sppDateLabel?window.sppDateLabel(value,true):value+' WIB';
      line('Tanggal / waktu WIB',dateLabel(form.dataset.originalDate),dateLabel(date));
      line('Keterangan',form.dataset.originalNote||'—',form.elements.keterangan.value.trim()||'—');
    }
    review.querySelector('[data-sw-icon]').innerHTML='<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8"><path d="'+(deletion?'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7':'m16 3 5 5-12 12H4v-5L16 3ZM14 5l5 5')+'"/></svg>';
    accept.className='btn '+(deletion?'btn-danger':'btn-primary');
    accept.textContent=proposal?(deletion?'Ajukan Hapus':'Ajukan Koreksi'):(deletion?'Hapus Transaksi':'Simpan Koreksi');

    dialog.querySelector('.sw-editor-stage').hidden=true;review.hidden=false;
    review.querySelector('[data-sw-cancel]').addEventListener('click',()=>dialog.close());
    review.querySelector('[data-sw-back]').addEventListener('click',()=>{review.hidden=true;dialog.querySelector('.sw-editor-stage').hidden=false;form.querySelector('textarea')?.focus();});
    accept.addEventListener('click',()=>{
      if(!form.isConnected||form.dataset.savingsSending)return;
      form.dataset.savingsConfirmed='1';form.requestSubmit(form.querySelector('[type="submit"]'));
    });
    review.querySelector('[data-sw-back]').focus();
  });
})();
