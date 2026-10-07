(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const dialog=document.getElementById('payment-activity-dialog');if(!dialog)return;
    const body=dialog.querySelector('.payment-activity-content');const subtitle=dialog.querySelector('[data-activity-subtitle]');
    let source=null;let controller=null;
    const text=(tag,content,cls='')=>{const e=document.createElement(tag);e.textContent=content;if(cls)e.className=cls;return e;};
    const roles={super_admin:'Super Admin',admin:'Admin',kasir:'Kasir',bendahara:'Bendahara'};
    dialog.querySelector('[data-close-activity]').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
    dialog.addEventListener('close',()=>{controller?.abort();source?.focus();});
    document.addEventListener('click',async e=>{
      const button=e.target.closest('.open-payment-activity');if(!button)return;
      e.stopPropagation();source=button;controller?.abort();controller=new AbortController();
      subtitle.textContent='Memuat riwayat…';body.replaceChildren(text('p','Memuat aktivitas pembayaran…'));dialog.showModal();
      try{
        const response=await fetch((dialog.dataset.endpoint || 'aktivitas.php')+'?id='+encodeURIComponent(button.dataset.id)+(button.dataset.unit?'&unit_id='+encodeURIComponent(button.dataset.unit):''),{signal:controller.signal,headers:{Accept:'application/json'}});
        const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Riwayat tidak dapat dimuat.');
        subtitle.textContent=[data.reference,data.student,data.unit].filter(Boolean).join(' · ');
        const list=document.createElement('ol');list.className='payment-activity-list';
        data.events.forEach(event=>{
          const item=document.createElement('li');item.className='payment-activity-event';
          const heading=document.createElement('div');heading.className='payment-activity-event-heading';heading.append(text('strong',event.label),text('time',event.time));item.append(heading);
          item.append(text('p',[event.name,event.username?'@'+event.username:'',roles[event.role]||event.role].filter(Boolean).join(' · ')));
          if(event.authorization_id)item.append(text('p','Pengajuan #'+event.authorization_id));
          if(event.note)item.append(text('p',event.note));
          if(event.reconstructed)item.append(text('p','Direkonstruksi dari bukti data lama.','payment-activity-legacy'));
          if(event.proposed)item.append(text('p','Usulan pemohon; belum berarti perubahan diterapkan.','payment-activity-legacy'));
          if(event.changes?.length){
            const changes=document.createElement('div');changes.className='payment-activity-changes';
            event.changes.forEach(change=>{const row=document.createElement('div');row.className='payment-activity-change';const values=document.createElement('div');
              values.append(text('del','Sebelum: '+(change.before||'—')),text('ins',(event.proposed?'Usulan: ':'Sesudah: ')+(change.after||'—')));row.append(text('strong',change.label),values);changes.append(row);});item.append(changes);
          }
          list.append(item);
        });body.replaceChildren(list);
      }catch(error){if(error.name!=='AbortError'){subtitle.textContent='Riwayat aktivitas';body.replaceChildren(text('p',error.message));}}
    });
  });
})();
