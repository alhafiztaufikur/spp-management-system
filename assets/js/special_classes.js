/* Progressive enhancement: server-rendered confirmation works without JavaScript. */
(() => {
  const confirmation=document.getElementById('special-delete-confirm');
  if(!confirmation||typeof HTMLDialogElement==='undefined'||!HTMLDialogElement.prototype.showModal)return;
  const dialog=document.createElement('dialog');dialog.className='sc-dialog';dialog.setAttribute('aria-labelledby','special-delete-title');
  const back=confirmation.querySelector('[data-sc-back]');confirmation.before(dialog);dialog.append(confirmation);dialog.showModal();
  back?.focus();dialog.addEventListener('cancel',event=>{event.preventDefault();if(back)location.href=back.href;else dialog.close();});
})();

/* Show the existing, scoped member list beside its trigger without leaving the directory. */
(() => {
  'use strict';
  if(typeof HTMLDialogElement==='undefined'||!HTMLDialogElement.prototype.showModal)return;
  const workspace=document.getElementById('special-classes');if(!workspace)return;
  const dialog=document.createElement('dialog');dialog.className='sc-members-dialog';dialog.setAttribute('aria-label','Daftar anggota kelas');document.body.append(dialog);
  let controller=null,trigger=null,request=0;
  const close=()=>dialog.close();
  dialog.addEventListener('close',()=>{request++;controller?.abort();document.documentElement.classList.remove('sc-members-open');trigger?.focus({preventScroll:true});});
  dialog.addEventListener('click',event=>{
    if(event.target.closest('[data-sc-members-close]')){event.preventDefault();close();}
    else if(event.target===dialog){const box=dialog.getBoundingClientRect();if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)close();}
  });
  const show=()=>{if(!dialog.open){dialog.showModal();document.documentElement.classList.add('sc-members-open');}};
  const install=section=>{
    dialog.replaceChildren(section);dialog.setAttribute('aria-labelledby','sc-member-title');show();
    section.querySelector('.sc-member-body')?.scrollTo(0,0);section.querySelector('[data-sc-members-close]')?.focus({preventScroll:true});
  };
  async function load(link){
    const url=new URL(link.href,location.href);if(url.origin!==location.origin)return;
    if(!dialog.contains(link))trigger=link;
    controller?.abort();controller=new AbortController();const current=++request;
    if(!dialog.open){
      const loading=document.createElement('div');loading.className='sc-members-loading';
      const title=document.createElement('h3');title.textContent=link.dataset.classLabel?'Anggota '+link.dataset.classLabel:'Daftar Anggota';
      const status=document.createElement('p');status.setAttribute('role','status');status.textContent='Memuat daftar anggota...';
      const button=document.createElement('button');button.type='button';button.className='btn btn-ghost';button.dataset.scMembersClose='';button.textContent='Tutup';
      loading.append(title,status,button);dialog.removeAttribute('aria-labelledby');dialog.replaceChildren(loading);show();
    }
    dialog.setAttribute('aria-busy','true');
    try{
      const response=await fetch(url,{credentials:'same-origin',signal:controller.signal});
      if(!response.ok)throw new Error('Members unavailable');
      const documentHtml=new DOMParser().parseFromString(await response.text(),'text/html'),section=documentHtml.querySelector('.sc-member-section');
      if(!section)throw new Error('Members unavailable');
      if(current===request)install(section);
    }catch(error){
      if(error.name==='AbortError'||current!==request)return;
      const panel=document.createElement('div');panel.className='sc-members-loading';
      const message=document.createElement('p');message.setAttribute('role','alert');message.textContent='Daftar anggota belum dapat dimuat. Silakan coba kembali.';
      const retry=document.createElement('a');retry.href=url.href;retry.className='btn btn-primary';retry.dataset.scMembers='';retry.textContent='Coba Lagi';
      const cancel=document.createElement('button');cancel.type='button';cancel.className='btn btn-ghost';cancel.dataset.scMembersClose='';cancel.textContent='Tutup';
      dialog.removeAttribute('aria-labelledby');panel.append(message,retry,cancel);dialog.replaceChildren(panel);
    }finally{if(current===request)dialog.removeAttribute('aria-busy');}
  }
  document.addEventListener('click',event=>{
    const link=event.target.closest('a[data-sc-members]');
    if(!link||!workspace.contains(link)&&!dialog.contains(link)||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
    event.preventDefault();load(link);
  });
  const initial=workspace.querySelector('.sc-member-section');if(initial){
    trigger=workspace.querySelector('[data-sc-members]');install(initial);
    dialog.addEventListener('close',()=>{const url=new URL(location.href);url.searchParams.delete('special_members');url.searchParams.delete('special_member_page');history.replaceState(history.state,'',url);},{once:true});
  }
})();
