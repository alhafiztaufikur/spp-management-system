/* Monitoring selections are intentionally independent of the promotion form. */
document.addEventListener('DOMContentLoaded',()=>{
  const root=document.querySelector('[data-participants]');if(!root)return;
  const model=JSON.parse(root.querySelector('[data-participants-data]').textContent),rows=model.rows;
  const byId=new Map(rows.map(r=>[String(r.id),r])),nodes=new Map([...root.querySelectorAll('[data-participant]')].map(el=>[el.dataset.participant,el]));
  const selected=new Set(),search=root.querySelector('[data-participants-search]'),size=root.querySelector('[data-participants-size]'),pageCheck=root.querySelector('[data-participants-page-check]');
  const disclosure=document.getElementById('participants-disclosure'),toggle=disclosure.querySelector(':scope>summary'),form=document.getElementById('participants-export-form');
  let page=1,filtered=rows,visible=[],menu=null,opener=null;const text=(tag,value)=>Object.assign(document.createElement(tag),{textContent:String(value)});
  function closeMenu(focus=false){if(!menu)return;const old=menu;menu=null;old.panel.hidden=true;window.sppFloatingPanels?.hide(old.panel);old.button.setAttribute('aria-expanded','false');if(focus)old.button.focus();}
  function selectionSummary(){
    root.querySelector('[data-participants-selected]').textContent=selected.size+' siswa dipilih';root.querySelector('[data-participants-export-scope]').textContent=selected.size?'Ekspor '+selected.size+' peserta terpilih':'Ekspor seluruh '+filtered.length+' hasil pencarian';
    visible.forEach(r=>{const el=nodes.get(String(r.id));el.classList.toggle('is-selected',selected.has(String(r.id)));el.querySelector('[data-participant-check]').checked=selected.has(String(r.id));});
    const count=visible.filter(r=>selected.has(String(r.id))).length;pageCheck.checked=visible.length>0&&count===visible.length;pageCheck.indeterminate=count>0&&count<visible.length;pageCheck.disabled=visible.length===0;
  }
  function render(){
    closeMenu();const term=search.value.trim().toLocaleLowerCase('id-ID');filtered=rows.filter(r=>(r.nama+' '+r.nis+' '+r.diknas+' '+r.rombel).toLocaleLowerCase('id-ID').includes(term));
    const per=Number(size.value),pages=Math.max(1,Math.ceil(filtered.length/per));page=Math.max(1,Math.min(page,pages));visible=filtered.slice((page-1)*per,page*per);
    nodes.forEach(el=>el.hidden=true);visible.forEach((r,i)=>{const el=nodes.get(String(r.id));el.hidden=false;el.querySelector('[data-participant-number]').textContent=(page-1)*per+i+1;});
    const empty=root.querySelector('[data-participants-empty]');empty.hidden=filtered.length>0;empty.querySelector('td').textContent=rows.length?'Tidak ada peserta yang cocok dengan pencarian.':'Tidak ada peserta pada tahun dan tingkat ini.';
    root.querySelector('[data-participants-count]').textContent='Menampilkan '+(filtered.length?(page-1)*per+1:0)+'–'+Math.min(page*per,filtered.length)+' dari '+filtered.length+' siswa';
    const nav=root.querySelector('[data-participants-pages]');nav.replaceChildren();
    function button(label,target,disabled=false,current=false){const el=text('button',label);el.type='button';el.disabled=disabled;el.setAttribute('aria-label',current?'Halaman '+target+', saat ini':target===page-1&&label==='‹'?'Halaman sebelumnya':label==='›'?'Halaman berikutnya':'Halaman '+target);if(current)el.setAttribute('aria-current','page');el.addEventListener('click',()=>{page=target;render();nav.querySelector('[aria-current=page]')?.focus();});nav.append(el);}
    button('‹',page-1,page===1);let start=Math.max(1,page-2),end=Math.min(pages,start+4);start=Math.max(1,end-4);for(let n=start;n<=end;n++)button(n,n,false,n===page);button('›',page+1,page===pages);
    selectionSummary();
  }
  root.querySelector('.participants-pagination').hidden=false;
  search.addEventListener('input',()=>{page=1;render();});size.addEventListener('change',()=>{page=1;render();});
  root.addEventListener('change',e=>{if(e.target.matches('[data-participant-check]')){e.target.checked?selected.add(e.target.value):selected.delete(e.target.value);selectionSummary();}});
  pageCheck.addEventListener('change',()=>{visible.forEach(r=>pageCheck.checked?selected.add(String(r.id)):selected.delete(String(r.id)));selectionSummary();});
  document.addEventListener('click',e=>{
    const button=e.target.closest('[data-participants-menu]');if(button&&root.contains(button)){
      const panel=document.getElementById(button.dataset.participantsMenu),same=menu?.panel===panel;closeMenu();if(same)return;
      menu={panel,button};panel.hidden=false;button.setAttribute('aria-expanded','true');window.sppFloatingPanels?.show(panel,button,{minWidth:220,maxHeight:280,onClose:()=>closeMenu()});panel.querySelector('[role=menuitem]')?.focus();return;
    }
    if(e.target.closest('[data-participants-select-all]')){filtered.forEach(r=>selected.add(String(r.id)));selectionSummary();closeMenu(true);return;}
    if(e.target.closest('[data-participants-clear]')){selected.clear();selectionSummary();closeMenu(true);return;}
    const detail=e.target.closest('[data-participants-detail]');if(detail){const r=byId.get(detail.dataset.participantsDetail);if(!r)return;opener=menu?.button;closeMenu();showDetail(r);return;}
    if(menu&&!menu.panel.contains(e.target)&&e.target!==menu.button)closeMenu();
  });
  document.addEventListener('keydown',e=>{
    const trigger=e.target.closest('[data-participants-menu]');
    if(!menu&&trigger&&root.contains(trigger)&&['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();trigger.click();if(e.key==='ArrowUp')menu?.panel.querySelector('[role=menuitem]:last-child')?.focus();return;}
    if(!menu)return;const items=[...menu.panel.querySelectorAll('[role=menuitem]')],i=items.indexOf(document.activeElement);let next;
    if(e.key==='Escape'){e.preventDefault();closeMenu(true);return;}if(e.key==='ArrowDown')next=(i+1)%items.length;if(e.key==='ArrowUp')next=(i+items.length-1)%items.length;if(e.key==='Home')next=0;if(e.key==='End')next=items.length-1;
    if(next!==undefined){e.preventDefault();items[next].focus();}if(e.key==='Tab')closeMenu(true);
  });
  form.addEventListener('submit',()=>{form.elements.roster_q.value=search.value.trim();const box=form.querySelector('[data-participants-export-ids]');box.replaceChildren();for(const id of selected){const input=document.createElement('input');input.type='hidden';input.name='selected_placements[]';input.value=id;box.append(input);}closeMenu();});
  const dialog=root.querySelector('.participants-detail');function showDetail(r){
    root.querySelector('[data-participants-detail-name]').textContent=r.nama+' · NIS '+r.nis;
    const dl=root.querySelector('[data-participants-detail-values]');dl.replaceChildren();
    for(const [label,value] of [['Tahun Penempatan',model.source_year],['Tingkat Asal','Kelas '+model.level],['Rombel Penempatan',r.rombel],['Tingkat Saat Ini',r.current_level],['Rombel Aktif',r.current_rombel],['Status Penempatan',r.status],['Kenaikan',r.process],['Rombel Tujuan',r.target+(r.target_note?' · '+r.target_note:'')]]){const div=document.createElement('div');div.append(text('dt',label),text('dd',value));dl.append(div);}dialog.showModal();
  }
  root.querySelector('[data-participants-detail-close]').addEventListener('click',()=>dialog.close());dialog.addEventListener('close',()=>opener?.focus());dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
  disclosure.addEventListener('toggle',()=>{toggle.setAttribute('aria-expanded',String(disclosure.open));if(!disclosure.open)closeMenu();const url=new URL(location.href);disclosure.open?url.searchParams.set('show_participants','1'):url.searchParams.delete('show_participants');history.replaceState(null,'',url);});
  render();
});
