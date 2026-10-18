(() => {
  'use strict';
  const root=document.getElementById('student-history');if(!root)return;
  const $=id=>document.getElementById(id),body=$('payment-history-body'),dialog=$('sh-detail');
  let rows=[],sort='tanggal',direction=-1,method='',component='',detailController=null,detailGeneration=0,menuTrigger=null;
  const icons={detail:'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/>',print:'<path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6zM17 12h1"/>'};
  const icon=key=>'<svg viewBox="0 0 24 24" aria-hidden="true">'+(icons[key]||icons.detail)+'</svg>';
  const normalized=value=>String(value??'').toLocaleLowerCase('id-ID');
  const methodName=value=>normalized(value)==='qris'?'Qris':normalized(value)==='tunai'?'Tunai':normalized(value)==='va'?'VA':String(value||'Tidak tercatat');
  const menu=document.createElement('div');menu.className='sh-menu';menu.hidden=true;menu.setAttribute('role','menu');document.body.append(menu);
  function closeMenu(restore=false){menu.hidden=true;if(menuTrigger){menuTrigger.setAttribute('aria-expanded','false');if(restore&&menuTrigger.isConnected)menuTrigger.focus();}menuTrigger=null;}
  function cancelDetail(){detailGeneration++;detailController?.abort();detailController=null;if(dialog.open)dialog.close();}
  const cell=(text,className='')=>{const td=document.createElement('td');td.className=className;td.textContent=String(text??'');return td;};
  function render(){
    if(!['ready','empty'].includes(body.dataset.historyState))return;
    closeMenu();
    const filtered=rows.filter(r=>(!method||methodName(r.metode)===method)&&(!component||r.components.includes(component)));
    const value=r=>sort==='tanggal'?String(r.tanggal_raw||''):sort==='periode'?Number(r.period_key):sort==='total'?Number(r.total_raw):normalized(r[sort]);
    filtered.sort((a,b)=>{const x=value(a),y=value(b);return (typeof x==='number'?x-y:x.localeCompare(y,'id-ID'))*direction||(Number(b.id)-Number(a.id));});
    const fragment=document.createDocumentFragment();
    for(const r of filtered){
      const tr=document.createElement('tr'),date=cell(String(r.tanggal||'').replace(/ WIB$/,''),'history-tanggal');if(String(r.tanggal).endsWith(' WIB')){const zone=document.createElement('small');zone.className='sh-timezone';zone.textContent=' WIB';date.append(zone);}tr.append(date,cell(r.periode),cell(r.komponen));
      const td=cell(''),badge=document.createElement('span'),name=methodName(r.metode);badge.className='history-method sh-method-'+normalized(name);badge.textContent=name==='Qris'?'QRIS':name;td.append(badge);tr.append(td,cell(r.operator),cell(r.total,'history-total'));
      const actions=cell('','sh-row-actions'),button=document.createElement('button');button.type='button';button.className='sh-more';button.textContent='\u22ee';button.setAttribute('aria-label','Tindakan transaksi '+r.id);button.setAttribute('aria-haspopup','menu');button.setAttribute('aria-expanded','false');button.addEventListener('click',()=>openMenu(button,r));button.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault();openMenu(button,r);}});actions.append(button);tr.append(actions);fragment.append(tr);
    }
    if(!filtered.length){const tr=document.createElement('tr'),td=cell(rows.length?'Tidak ada transaksi yang sesuai filter.':'Belum ada transaksi pembayaran untuk siswa ini.','sh-empty');td.colSpan=7;tr.append(td);fragment.append(tr);}
    body.replaceChildren(fragment);$('sh-count').textContent='Menampilkan '+filtered.length+' dari '+rows.length+' transaksi';
    root.querySelectorAll('[data-sort-column]').forEach(th=>th.setAttribute('aria-sort',th.dataset.sortColumn===sort?(direction<0?'descending':'ascending'):'none'));
    $('sh-filter-toggle').classList.toggle('is-active',!!(method||component));
  }
  function openMenu(trigger,row){
    if(menuTrigger===trigger&&!menu.hidden){closeMenu();return;}closeMenu();menuTrigger=trigger;trigger.setAttribute('aria-expanded','true');menu.replaceChildren();
    const detail=document.createElement('button');detail.type='button';detail.setAttribute('role','menuitem');detail.innerHTML=icon('detail')+'Detail';detail.addEventListener('click',()=>{closeMenu(true);openDetail(row);});menu.append(detail);
    if(row.can_print&&row.print_url){const print=document.createElement('a');print.href=row.print_url;print.target='_blank';print.rel='noopener';print.setAttribute('role','menuitem');print.innerHTML=icon('print')+'Cetak';print.addEventListener('click',()=>closeMenu(true));menu.append(print);}
    menu.hidden=false;positionMenu();detail.focus({preventScroll:true});
  }
  function positionMenu(){if(menu.hidden||!menuTrigger)return;const rect=menuTrigger.getBoundingClientRect(),height=menu.offsetHeight;if(rect.bottom<0||rect.top>innerHeight){closeMenu();return;}menu.style.left=Math.max(8,Math.min(innerWidth-menu.offsetWidth-8,rect.right-menu.offsetWidth))+'px';menu.style.top=Math.max(8,rect.bottom+height+8>innerHeight?rect.top-height-4:rect.bottom+4)+'px';}
  async function openDetail(row){
    cancelDetail();const generation=++detailGeneration;detailController=new AbortController();$('sh-detail-content').textContent='Memuat detail transaksi...';dialog.showModal();
    try{const response=await fetch(row.detail_url,{signal:detailController.signal,cache:'no-store',headers:{Accept:'application/json'}}),payload=await response.json();if(generation!==detailGeneration||!dialog.open)return;if(!response.ok||!payload.ok)throw new Error(payload.message||'Detail belum dapat dimuat.');$('sh-detail-content').innerHTML=payload.html;}
    catch(error){if(generation!==detailGeneration||error.name==='AbortError')return;const message=document.createElement('p');message.textContent=error.message||'Detail belum dapat dimuat.';const retry=document.createElement('button');retry.type='button';retry.className='btn btn-ghost';retry.textContent='Coba Lagi';retry.addEventListener('click',()=>openDetail(row));$('sh-detail-content').replaceChildren(message,retry);}
  }
  menu.addEventListener('keydown',e=>{const items=[...menu.querySelectorAll('[role=menuitem]')],index=items.indexOf(document.activeElement);if(e.key==='Escape'){e.preventDefault();closeMenu(true);}if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)){e.preventDefault();items[e.key==='Home'?0:e.key==='End'?items.length-1:(index+(e.key==='ArrowDown'?1:-1)+items.length)%items.length]?.focus();}if(e.key==='Tab')closeMenu();});
  document.addEventListener('click',e=>{if(!menu.hidden&&!menu.contains(e.target)&&e.target!==menuTrigger)closeMenu();});window.addEventListener('resize',positionMenu);document.addEventListener('scroll',positionMenu,true);
  $('sh-detail-close').addEventListener('click',cancelDetail);dialog.addEventListener('cancel',cancelDetail);
  function filterOpen(open){$('sh-filter').hidden=!open;$('sh-filter-toggle').setAttribute('aria-expanded',String(open));}
  $('sh-filter-toggle').addEventListener('click',()=>filterOpen($('sh-filter').hidden));
  $('sh-apply').addEventListener('click',()=>{method=$('sh-method').value;component=$('sh-component').value;render();filterOpen(false);$('sh-filter-toggle').focus();});
  $('sh-reset').addEventListener('click',()=>{method=component='';$('sh-method').value=$('sh-component').value='';render();});
  root.addEventListener('keydown',e=>{if(e.key==='Escape'&&!$('sh-filter').hidden){filterOpen(false);$('sh-filter-toggle').focus();e.preventDefault();}});
  root.querySelectorAll('[data-history-sort]').forEach(button=>button.addEventListener('click',()=>{const key=button.dataset.historySort;direction=sort===key?-direction:(['tanggal','periode','total'].includes(key)?-1:1);sort=key;render();}));
  window.studentPaymentHistory={
    reset(){rows=[];method=component='';sort='tanggal';direction=-1;$('sh-method').value=$('sh-component').value='';filterOpen(false);cancelDetail();closeMenu();},
    setState(code){rows=[];cancelDetail();closeMenu();$('sh-count').textContent=code==='loading'?'Memuat transaksi...':code==='empty'?'0 transaksi':'Pilih siswa atau coba muat ulang riwayat.';},
    setRows(payload){
      const student=window.selectedStudentOption?.()?.dataset.studentId;if(student&&Number(student)!==Number(payload.student_id)||Number(window.sppPaymentHistoryUnit)!==Number(payload.unit_id))throw new Error('Identitas riwayat berubah.');
      rows=payload.rows.filter(r=>Number(r.unit_id)===Number(payload.unit_id)).map(r=>({...r,components:Array.isArray(r.components)?r.components:[]}));
      const components=[...new Set(rows.flatMap(r=>r.components))].sort((a,b)=>a.localeCompare(b,'id-ID'));$('sh-component').replaceChildren(new Option('Semua komponen',''),...components.map(c=>new Option(c,c)));if(components.includes(component))$('sh-component').value=component;else component='';render();
    }
  };
})();
