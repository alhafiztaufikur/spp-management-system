/* Bounded suggestions. The form's ordinary GET search remains the fallback. */
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-history-search]').forEach(input=>{
    const form=input.form,box=input.closest('.search-box')||input.parentElement;
    // Keep the visible no-JS field from overriding a selected hidden identity.
    if(input.dataset.historyQuery)input.removeAttribute('name');
    const panel=document.createElement('div');panel.className='student-search-panel history-search-panel';panel.hidden=true;panel.setAttribute('role','listbox');box.append(panel);
    input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');input.autocomplete='off';
    panel.id=input.id+'-suggestions';input.setAttribute('aria-controls',panel.id);
    let timer,controller,generation=0,index=-1;
    const close=()=>{panel.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');index=-1;};
    const cancel=()=>{clearTimeout(timer);controller?.abort();generation++;close();};
    input.addEventListener('input',()=>{
      clearTimeout(timer);controller?.abort();const request=++generation;close();
      const queryField=input.dataset.historyQuery?form.querySelector(input.dataset.historyQuery):null;
      if(queryField)queryField.value=input.value;
      const identity=form.querySelector('[data-student-identity]');if(identity)identity.value='';
      if(input.value.trim().length<2)return;
      timer=setTimeout(async()=>{
        controller=new AbortController();const params=new URLSearchParams({q:input.value.trim(),context:input.dataset.historySearch,unit:input.dataset.historyUnit,source:form.elements.source?.value||'all'});
        try{
          const response=await fetch('history_search.php?'+params,{signal:controller.signal,cache:'no-store',headers:{Accept:'application/json'}});
          const data=await response.json();if(request!==generation||!response.ok||!data.ok)return;
          panel.replaceChildren();
          data.rows.forEach((row,i)=>{
            const button=document.createElement('button');button.type='button';button.className='student-search-option';button.setAttribute('role','option');button.id=panel.id+'-'+i;
            const title=document.createElement('strong');title.textContent=row.name;
            const detail=document.createElement('small');detail.textContent=row.identity+' · '+row.kelas+' · '+({1:'SD',2:'SMP',3:'SMA'}[row.unit_id]||'');button.append(title,detail);
            button.addEventListener('click',()=>{controller?.abort();generation++;input.value=row.name;if(queryField)queryField.value=row.identity;else input.value=row.identity;if(identity)identity.value=String(row.student_id||'');close();});panel.append(button);
          });
          if(!data.rows.length){const empty=document.createElement('div');empty.className='student-search-empty';empty.textContent='Tidak ada identitas yang cocok.';panel.append(empty);}
          panel.hidden=false;input.setAttribute('aria-expanded','true');
        }catch(error){if(error.name!=='AbortError'&&request===generation)close();}
      },250);
    });
    input.addEventListener('keydown',event=>{
      const options=[...panel.querySelectorAll('button')];
      if(event.key==='Escape'){cancel();return;}
      if(panel.hidden||!options.length)return;
      if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();index=index<0?(event.key==='ArrowDown'?0:options.length-1):(index+(event.key==='ArrowDown'?1:-1)+options.length)%options.length;options.forEach((o,i)=>o.setAttribute('aria-selected',String(i===index)));input.setAttribute('aria-activedescendant',options[index].id);options[index].scrollIntoView({block:'nearest'});}
      if(event.key==='Enter'&&index>=0){event.preventDefault();options[index].click();}
    });
    document.addEventListener('click',e=>{if(!box.contains(e.target))cancel();});
  });
});
