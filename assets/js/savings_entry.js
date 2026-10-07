(() => {
  const search=document.getElementById('siswa-search'),options=Array.from(document.getElementById('siswa-list').options);
  const cards=Array.from(document.querySelectorAll('.sw-student-option'));
  function filter() {
    const q=search.value.toLocaleLowerCase('id-ID').trim();let count=0;
    cards.forEach(card=>{card.hidden=!card.dataset.search.includes(q);if(!card.hidden)count++;});
    document.getElementById('sw-no-students').hidden=count!==0;
  }
  window.pilihSiswaDatalist=function(input){
    const value=input.value.trim();let matches=options.filter(o=>o.dataset.nis===value);
    if (!matches.length)matches=options.filter(o=>o.value===value||o.dataset.diknas===value);
    const chosen=matches.length===1?matches[0]:null;
    ['nis','nama','kelas'].forEach(key=>document.getElementById('disp-'+key).value=chosen?.dataset[key]||'');
    document.getElementById('sw-selected-name').textContent=chosen?.dataset.nama||'Pilih siswa dulu';
    document.getElementById('sw-selected-nis').textContent=chosen?.dataset.nis||'—';
    document.getElementById('sw-selected-diknas').textContent=chosen?.dataset.diknas||'—';
    document.getElementById('sw-entry-info').lastChild.textContent=chosen?'Siswa terpilih. Periksa saldo dan isi nominal transaksi.':'Pilih siswa terlebih dahulu di panel sebelah kiri.';
    cards.forEach(card=>{const active=!!chosen&&card.dataset.nis===chosen.dataset.nis;card.classList.toggle('is-active',active);card.setAttribute('aria-pressed',String(active));card.querySelector('.sw-selected-mark').hidden=!active;});
    filter();
  };
  cards.forEach(card=>card.addEventListener('click',()=>{search.value=card.dataset.nis;window.pilihSiswaDatalist(search);document.getElementById('nominal-'+document.getElementById('form-tabungan').elements.aksi.value).focus({preventScroll:true});}));
  document.getElementById('sw-change-student').addEventListener('click',()=>{search.value='';window.pilihSiswaDatalist(search);search.focus();});
  search.addEventListener('keydown',event=>{if(event.key==='ArrowDown'){event.preventDefault();cards.find(card=>!card.hidden)?.focus();}});
  cards.forEach(card=>card.addEventListener('keydown',event=>{const visible=cards.filter(item=>!item.hidden),index=visible.indexOf(card);if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();visible[Math.max(0,Math.min(visible.length-1,index+(event.key==='ArrowDown'?1:-1)))]?.focus();}if(event.key==='Escape')search.focus();}));
  new MutationObserver(()=>{document.getElementById('sw-selected-balance').textContent=document.getElementById('saldo-preview').textContent;}).observe(document.getElementById('saldo-preview'),{childList:true,subtree:true});
})();
