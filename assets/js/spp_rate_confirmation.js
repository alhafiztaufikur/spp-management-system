document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  const node=document.getElementById('spp-rate-confirmation-data');if(!node)return;
  const data=JSON.parse(node.textContent),rate=document.getElementById('spp-rate-form'),publish=document.getElementById('spp-publish-form');
  const inputs=[...rate.querySelectorAll('[data-level]')],edit=document.getElementById('spp-rate-edit'),save=document.getElementById('spp-rate-save'),cancel=document.getElementById('spp-rate-cancel');
  const accepted=new WeakMap();let pending=false;
  document.getElementById('spp-warning-secondary').addEventListener('click',()=>{pending=false;});
  document.addEventListener('keydown',event=>{if(event.key==='Escape')pending=false;});
  const number=v=>Number(String(v).replace(/\./g,'')),money=v=>'Rp '+Number(v).toLocaleString('id-ID');
  function refresh(){
    if(rate.dataset.status!=='published')return;
    const editing=rate.dataset.editing==='true',dirty=inputs.some(input=>number(input.value)!==Number(input.dataset.original));
    inputs.forEach(input=>input.disabled=!editing);
    edit.hidden=editing&&dirty;edit.setAttribute('aria-disabled',String(editing));edit.tabIndex=editing?-1:0;
    save.hidden=!editing||!dirty;save.disabled=!dirty;cancel.hidden=!editing;
  }
  edit?.addEventListener('click',event=>{event.preventDefault();if(rate.dataset.editing==='true')return;rate.dataset.editing='true';refresh();inputs[0]?.focus();});
  cancel?.addEventListener('click',event=>{event.preventDefault();inputs.forEach(input=>{input.value=Number(input.dataset.original)>0?Number(input.dataset.original).toLocaleString('id-ID'):'';input.setCustomValidity('');});rate.dataset.editing='false';accepted.delete(rate);rate.elements.confirm_rate_change.value='0';refresh();edit.focus();});
  inputs.forEach(input=>input.addEventListener('input',()=>{input.setCustomValidity('');accepted.delete(rate);rate.elements.confirm_rate_change.value='0';refresh();}));
  function signature(form){
    return JSON.stringify([...new FormData(form)].filter(([name])=>!name.startsWith('confirm_')&&name!=='prior_debt_csrf')
      .map(([name,value])=>[name,name.startsWith('jumlah[')?String(number(value)):value]));
  }
  function check(form,event){
    if(form!==rate&&form!==publish)return true;
    if(form.dataset.sppSubmitting==='1'){event.preventDefault();event.stopImmediatePropagation();return false;}
    if(form===publish&&form.dataset.firstPublication!=='true')return true;
    const snapshot=signature(form),field=form===rate?'confirm_rate_change':'confirm_spp_publish';
    if(accepted.get(form)===snapshot&&form.elements[field].value==='1')return true;
    event.preventDefault();event.stopImmediatePropagation();if(pending)return false;
    if(form===rate){
      for(const input of inputs){if(!Number.isFinite(number(input.value))||number(input.value)<=0){input.setCustomValidity('Isi tarif lebih dari Rp0.');input.reportValidity();return false;}}
    }else if(!form.querySelector('[name="selected_students[]"]:checked'))return false;
    form.elements[field].value='0';if(form.elements.confirm_previous_debt)form.elements.confirm_previous_debt.value='0';
    const action=form===rate?form.elements.aksi.value:'terbitkan',copy=data.copy[action];
    const lines=[data.unit+' · '+data.year];
    if(form===rate){inputs.forEach(input=>{const old=Number(input.dataset.original),next=number(input.value);if(action==='ubah_tarif_terbit'&&old===next)return;lines.push('Kelas '+input.dataset.level+': '+(action==='ubah_tarif_terbit'?money(old)+' → ':'')+money(next));});}
    else{
      Object.entries(data.rates).forEach(([level,value])=>lines.push('Kelas '+level+': '+money(value)));
      const selected=[...form.querySelectorAll('[name="selected_students[]"]:checked')];lines.push(selected.length+' siswa dipilih');
      const months=new Set(selected.map(input=>input.closest('.spp-publish-row').querySelector('[name^="start_month["]')?.selectedOptions[0]?.textContent?.trim()).filter(Boolean));
      if(months.size)lines.push('Mulai tagihan: '+[...months].join(', '));
    }
    pending=true;
    const release=()=>{pending=false;};
    showSppWarning({code:'master_spp_confirmation',severity:'warning',title:copy[0],message:copy[1],amount_label:lines.join('\n'),action_label:copy[2]},event.submitter||save,true,()=>{
      release();accepted.set(form,snapshot);form.elements[field].value='1';form.requestSubmit();
    });
    return false;
  }
  window.sppConfirmBeforePriorDebt=check;
  rate.addEventListener('submit',event=>check(rate,event),true);
  rate.addEventListener('submit',()=>{rate.dataset.sppSubmitting='1';save.disabled=true;});
  publish.addEventListener('submit',()=>{publish.dataset.sppSubmitting='1';const button=document.getElementById('spp-submit-button');if(button)button.disabled=true;});
  ['input','change'].forEach(name=>publish.addEventListener(name,()=>{accepted.delete(publish);publish.elements.confirm_spp_publish.value='0';if(publish.elements.confirm_previous_debt)publish.elements.confirm_previous_debt.value='0';}));
  refresh();
});
