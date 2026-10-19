(() => {
  'use strict';
  const form=document.getElementById('fee-publish-form');if(!form)return;
  const roster=JSON.parse(document.getElementById('fee-publish-roster').textContent),target=document.getElementById('fee-publish-target'),master=document.getElementById('fee-publish-master'),picker=document.getElementById('fee-publish-picker'),list=document.getElementById('fee-publish-ids'),search=document.getElementById('fee-publish-search'),count=document.getElementById('fee-publish-count'),error=document.getElementById('fee-publish-error');
  const selectedIds=new Set(),classes=new Map(roster.classes.map(c=>[c.id,c.label]));let options=[],visible=[];
  const clearError=()=>{error.hidden=true;error.textContent='';};
  const updateCount=()=>{count.textContent=selectedIds.size+' terpilih \u00b7 '+visible.length+' hasil';clearError();};
  function render(){
    const query=search.value.toLocaleLowerCase('id-ID').trim();visible=options.filter(row=>[row.label,row.name,row.nis,classes.get(row.class_id)].join(' ').toLocaleLowerCase('id-ID').includes(query));list.replaceChildren();
    for(const row of visible){
      const label=document.createElement('label');label.className='fee-picker-row';label.classList.toggle('is-selected',selectedIds.has(row.id));
      const input=document.createElement('input');input.type='checkbox';input.name='target_ids[]';input.value=row.id;input.checked=selectedIds.has(row.id);input.addEventListener('change',()=>{if(input.checked)selectedIds.add(row.id);else selectedIds.delete(row.id);label.classList.toggle('is-selected',input.checked);syncHidden();updateCount();});
      const text=document.createElement('span'),name=document.createElement('strong'),meta=document.createElement('small');name.textContent=target.value==='siswa'?row.name:row.label;meta.textContent=target.value==='siswa'?'NIS '+row.nis+' \u00b7 '+(classes.get(row.class_id)||'Rombel belum tercatat'):roster.students.filter(s=>s.class_id===row.id).length+' siswa sasaran';text.append(name,meta);label.append(input,text);list.append(label);
    }
    if(!visible.length){const empty=document.createElement('p');empty.className='fee-picker-empty';empty.textContent=options.length?'Tidak ada sasaran yang cocok. Coba kata pencarian lain.':'Belum ada sasaran tersedia.';list.append(empty);}syncHidden();updateCount();
  }
  // Search keeps selected hidden rows in the submitted target_ids[] contract.
  function syncHidden(){
    form.querySelectorAll('[data-hidden-target]').forEach(input=>input.remove());const shown=new Set(visible.map(r=>r.id));
    for(const id of selectedIds)if(!shown.has(id)){const input=document.createElement('input');input.type='hidden';input.name='target_ids[]';input.value=id;input.dataset.hiddenTarget='1';form.append(input);}
  }
  function refresh(){selectedIds.clear();search.value='';picker.hidden=target.value==='all';options=target.value==='rombel'?roster.classes:target.value==='siswa'?roster.students:[];render();}
  target.addEventListener('change',refresh);refresh();search.addEventListener('input',render);
  document.getElementById('fee-publish-all').addEventListener('click',()=>{visible.forEach(r=>selectedIds.add(r.id));render();});document.getElementById('fee-publish-clear').addEventListener('click',()=>{selectedIds.clear();render();});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(form.dataset.submitting==='1'||!form.reportValidity())return;
    const selected=master.selectedOptions[0],selection=options.filter(r=>selectedIds.has(r.id)),students=roster.students.filter(s=>target.value==='all'||selectedIds.has(target.value==='rombel'?s.class_id:s.id));
    if(!students.length){error.hidden=false;error.textContent='Pilih sasaran yang memiliki siswa aktif.';list.querySelector('input')?.focus();return;}
    clearError();form.dataset.submitting='1';
    try {
      const ok=await window.workflowConfirm({title:'Konfirmasi Penerbitan',message:'Siswa yang sudah memiliki tagihan master ini akan dilewati. Pembayaran lama tidak diubah.',rows:[selected.textContent,students.length+' siswa sasaran','Maksimal nilai tagihan Rp '+(students.length*Number(selected.dataset.nominal)).toLocaleString('id-ID'),...selection.map(o=>o.label)],action:'Terbitkan Tagihan'},event.submitter);if(!ok)return;
      form.querySelectorAll('[name="expected_student_ids[]"]').forEach(input=>input.remove());for(const student of students){const input=document.createElement('input');input.type='hidden';input.name='expected_student_ids[]';input.value=student.id;form.append(input);}
      document.getElementById('fee-publish-version').value=selected.dataset.version;syncHidden();form.querySelector('button[type=submit]').disabled=true;HTMLFormElement.prototype.submit.call(form);
    } finally {delete form.dataset.submitting;}
  });
})();
