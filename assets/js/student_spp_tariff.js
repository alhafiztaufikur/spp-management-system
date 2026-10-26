'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const data=document.getElementById('student-tariff-data'),dialog=document.getElementById('student-tariff-dialog');
  if(!data||!dialog)return;
  const contexts=JSON.parse(data.textContent),form=document.getElementById('student-tariff-form');
  const amount=form.elements.nominal_akhir,start=form.elements.start_period,feedback=document.getElementById('sct-feedback');
  const reset=document.getElementById('sct-reset'),save=document.getElementById('sct-save');
  const money=n=>'Rp '+Number(n).toLocaleString('id-ID',{maximumFractionDigits:2});
  const typed=n=>Number(n).toLocaleString('id-ID',{maximumFractionDigits:2});
  let current=null,opener=null,busy=false,resetMode=false,customDraft='';
  function cents(raw){
    raw=raw.trim();
    if(/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/.test(raw))raw=raw.replace(/\./g,'').replace(',','.');
    else if(!/^[0-9]+\.[0-9]{1,2}$/.test(raw))return null;
    const [whole,fraction='']=raw.split('.');
    if(whole.replace(/^0+/,'').length>13)return null;
    const value=BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));
    return value<=999999999999999n?value:null;
  }
  function rowFor(id){return document.querySelector('.spp-publish-row[data-student-id="'+id+'"]');}
  function paintRow(context){
    const row=rowFor(context.student_id);if(!row)return;
    const month=row.querySelector('[name^="start_month["]')?.value;
    const period=context.periods.find(p=>p.month===month)||context.periods[0];
    const value=p=>p.locked&&p.bill_id?p.before:p.rate;
    row.querySelector('[data-student-rate]').textContent=money(value(period));
    const badge=row.querySelector('[data-student-rate-badge]');
    const varied=new Set(context.periods.map(p=>String(value(p)))).size>1;
    badge.textContent=period.locked&&period.bill_id&&period.custom?'Tagihan dipertahankan':period.custom?'Tarif Khusus':varied?'Tarif Bervariasi':'';
    badge.hidden=!badge.textContent;badge.title=period.label+(varied?' · tarif antarbulan berbeda':'');
  }
  function paintPublication(context,summary){
    const status=rowFor(context.student_id)?.querySelector('[data-student-bill-status]');
    const bills=context.periods.filter(p=>p.bill_id),covered=bills.some(p=>p.status==='covered_psb'),waived=bills.some(p=>p.status==='waived');
    if(status){status.textContent=bills.length?bills.length+' bulan'+(covered?' · Tercakup PSB':waived?' · Potongan Penuh':bills.every(p=>p.status==='cancelled')?' · Dibatalkan':' · Lihat rincian tagihan'):'Belum terbit';status.classList.toggle('is-active',!!bills.length);status.classList.toggle('is-inactive',!bills.length);}
    if(!summary)return;
    document.querySelectorAll('[data-sct-summary]').forEach(node=>node.textContent=Number(summary[node.dataset.sctSummary]).toLocaleString('en-US'));
    document.querySelectorAll('[data-sct-progress]').forEach(node=>node.style.width=summary[node.dataset.sctProgress+'_percent']+'%');
    document.querySelectorAll('[data-sct-percent]').forEach(node=>node.textContent=summary[node.dataset.sctPercent+'_percent']+'%');
    const unpaid=document.querySelector('[data-sct-unpaid]');if(unpaid)unpaid.textContent=Number(summary.unpaid_bills).toLocaleString('en-US')+' tagihan belum bayar.';
    const badge=document.querySelector('[data-sct-year-status]');if(badge){badge.textContent=summary.status.toUpperCase();badge.classList.toggle('is-paid',summary.status==='published');badge.classList.toggle('is-partial',summary.status==='draft');}
    if(summary.status==='published'){
      const publication=document.getElementById('spp-publish-form');if(publication)publication.dataset.firstPublication='false';
      document.querySelector('[data-sct-close-year]')?.removeAttribute('hidden');
      const rate=document.getElementById('spp-rate-form');
      if(rate?.dataset.status==='draft'){
        rate.dataset.status='published';rate.dataset.editing='false';rate.elements.aksi.value='ubah_tarif_terbit';
        rate.querySelectorAll('[data-level]').forEach(input=>{input.value=Number(input.dataset.original).toLocaleString('id-ID');input.disabled=true;});
        rate.querySelector('[data-level]')?.dispatchEvent(new Event('input',{bubbles:true}));
        const save=rate.querySelector('#spp-rate-save span');if(save)save.textContent='Simpan Perubahan Tarif';
      }
      const title=document.querySelector('.spp-year-explanation strong');if(title)title.textContent='Tahun SPP sudah diterbitkan';
      const note=document.querySelector('.spp-status-note strong'),hint=document.querySelector('.spp-status-note small');
      if(note)note.textContent='Tahun ajaran ini sedang terbuka';if(hint)hint.textContent='Gunakan Edit Tarif untuk mengoreksi tagihan yang belum dibayar.';
    }
  }
  function mode(value){
    resetMode=value;amount.readOnly=value;
    form.elements.aksi.value=value?'reset_tarif_siswa':'simpan_tarif_siswa';
    save.textContent=value?'Terapkan Tarif Bawaan':'Terapkan Tarif';
    reset.textContent=value?'Kembali ke Tarif Khusus':'Gunakan Tarif Bawaan';
    if(value){customDraft=amount.value;amount.value=typed(current.periods.find(p=>p.period===start.value).default.net);}
    else if(customDraft!=='')amount.value=customDraft;
    render();
  }
  function render(){
    if(!current)return;
    const offset=current.periods.findIndex(p=>p.period===start.value),body=document.getElementById('sct-preview-body');
    const parsed=cents(amount.value);amount.setCustomValidity(parsed===null&&!resetMode?'Isi nominal positif atau nol, maksimal dua desimal.':'');
    const next=parsed===null?null:Number(parsed)/100;
    body.replaceChildren();let changed=0,locked=0,future=0,ineligible=0;
    current.periods.forEach((p,index)=>{
      const selected=index>=offset;
      const before=p.bill_id?p.before:p.rate;
      const after=!selected||p.locked?before:resetMode?p.default.net:next;
      if(selected){if(p.locked)locked++;else if(!p.bill_id){if(p.can_publish)future++;else ineligible++;}else if(after!==null&&Math.abs(after-before)>.001)changed++;}
      const tr=document.createElement('tr');if(selected)tr.classList.add('is-target');if(p.locked)tr.classList.add('is-locked');
      const note=!selected?'Tetap':p.locked?p.note:!p.bill_id?(p.can_publish?'Akan diterbitkan':p.note):after!==null&&Math.abs(after-before)>.001?'Diperbarui':'Tidak berubah';
      for(const value of [p.label,money(before),after===null?'—':money(after),note]){const td=document.createElement('td');td.textContent=value;tr.append(td);}
      body.append(tr);
    });
    document.getElementById('sct-preview-summary').textContent=changed+' diperbarui · '+locked+' dipertahankan · '+future+' diterbitkan'+(ineligible?' · '+ineligible+' tidak memenuhi syarat':'');
    reset.disabled=busy||(!resetMode&&!current.periods.slice(offset).some(p=>p.custom));
  }
  document.querySelectorAll('[data-edit-student-tariff]').forEach(button=>button.addEventListener('click',()=>{
    current=contexts[button.dataset.editStudentTariff];if(!current)return;
    opener=button;resetMode=false;customDraft='';form.reset();amount.readOnly=false;
    save.textContent='Terapkan Tarif';reset.textContent='Gunakan Tarif Bawaan';feedback.hidden=true;
    form.elements.student_id.value=current.student_id;form.elements.expected_student_version.value=current.version;
    document.getElementById('sct-student-name').textContent=current.name;
    document.getElementById('sct-student-detail').textContent='NIS '+current.nis+' · '+current.class+' · TA '+current.year;
    const month=rowFor(current.student_id).querySelector('[name^="start_month["]').value;
    const period=current.periods.find(p=>p.month===month)||current.periods[0];start.value=period.period;amount.value=typed(period.rate);
    render();dialog.showModal();amount.focus();amount.select();
  }));
  document.querySelectorAll('[data-sct-close]').forEach(button=>button.addEventListener('click',()=>{if(!busy)dialog.close();}));
  dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
  dialog.addEventListener('close',()=>opener?.focus({preventScroll:true}));
  amount.addEventListener('input',()=>{feedback.hidden=true;render();});
  amount.addEventListener('blur',()=>{const value=cents(amount.value);if(value!==null)amount.value=typed(Number(value)/100);});
  start.addEventListener('change',()=>{if(resetMode)amount.value=typed(current.periods.find(p=>p.period===start.value).default.net);render();});
  reset.addEventListener('click',()=>mode(!resetMode));
  Object.values(contexts).forEach(context=>{
    paintRow(context);rowFor(context.student_id)?.querySelector('[name^="start_month["]')?.addEventListener('change',()=>paintRow(contexts[context.student_id]));
  });
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy||!form.reportValidity())return;
    busy=true;feedback.hidden=true;const values=new FormData(form);
    form.setAttribute('aria-busy','true');form.querySelectorAll('button').forEach(button=>button.disabled=true);
    try{
      const response=await fetch(form.action,{method:'POST',body:values,headers:{Accept:'application/json'},credentials:'same-origin'});
      const result=await response.json().catch(()=>null);
      if(!response.ok||!result?.ok)throw new Error(result?.message||'Tarif belum dapat diterapkan. Periksa sesi dan muat ulang bila diperlukan.');
      contexts[current.student_id]=result.context;paintRow(result.context);paintPublication(result.context,result.year_summary);
      document.querySelectorAll('[name="expected_rate_version"]').forEach(input=>{input.value=result.rate_version;input.defaultValue=result.rate_version;});
      let toast=document.getElementById('sct-toast');if(!toast){toast=document.createElement('div');toast.id='sct-toast';toast.className='sct-toast';toast.setAttribute('role','status');document.body.append(toast);}
      toast.textContent='Tarif diterapkan: '+result.result.updated+' diperbarui; '+result.result.created+' diterbitkan; '+result.result.locked+' dipertahankan.'+(result.result.ineligible?' '+result.result.ineligible+' periode tidak memenuhi syarat.':'');toast.hidden=false;
      window.setTimeout(()=>toast.hidden=true,6500);dialog.close();
    }catch(error){feedback.textContent=error.message;feedback.hidden=false;}
    finally{busy=false;form.removeAttribute('aria-busy');form.querySelectorAll('button').forEach(button=>button.disabled=false);render();}
  });
});
