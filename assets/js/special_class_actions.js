/* Native details remains usable without JavaScript. */
(() => {
  const menus=[...document.querySelectorAll('.sc-row-menu')];
  const close=menu=>{menu.open=false;};
  menus.forEach(menu=>{
    menu.addEventListener('toggle',()=>{if(menu.open)menus.forEach(other=>{if(other!==menu)close(other);});});
    menu.addEventListener('keydown',event=>{
      const summary=menu.querySelector('summary');
      if(event.key==='Escape'){close(menu);summary.focus();event.preventDefault();return;}
      if(!['ArrowDown','ArrowUp','Home','End'].includes(event.key))return;
      event.preventDefault();menu.open=true;
      const items=[...menu.querySelectorAll('a,button')];if(!items.length)return;
      const current=items.indexOf(document.activeElement);
      const next=event.key==='Home'?0:event.key==='End'?items.length-1:event.key==='ArrowDown'?(current+1)%items.length:(current<0?items.length-1:(current+items.length-1)%items.length);
      items[next].focus();
    });
    menu.addEventListener('focusout',()=>setTimeout(()=>{if(!menu.contains(document.activeElement))close(menu);},0));
  });
  document.addEventListener('click',event=>menus.forEach(menu=>{if(!menu.contains(event.target))close(menu);}));
})();
