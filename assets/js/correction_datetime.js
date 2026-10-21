/* Presentation adapter: existing ISO date payloads and server validation stay authoritative. */
document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  const months=['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  let serial=0;
  const widgets=new Map();
  const make=(tag,props={})=>Object.assign(document.createElement(tag),props);
  const pad=value=>String(value).padStart(2,'0');
  const iso=(year,month,day)=>String(year).padStart(4,'0')+'-'+pad(month+1)+'-'+pad(day);
  const parse=text=>{
    const match=/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text.trim());if(!match)return null;
    const day=Number(match[1]),month=Number(match[2])-1,year=Number(match[3]);
    return year>=1000&&month>=0&&month<12&&day>0&&day<=new Date(year,month+1,0).getDate()?{year,month,day}:null;
  };
  const init=root=>root.querySelectorAll('input[data-correction-datetime]').forEach(source=>{
    if(source.dataset.correctionDatetimeReady)return;source.dataset.correctionDatetimeReady='1';
    const form=source.form;if(!form)return;
    const lifetime=new AbortController();
    const required=source.required,clockRequired=source.name==='tanggal',precision=form.elements.namedItem('time_precision');
    const original=source.value,parts=/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}:\d{2}(?::\d{2})?))?$/.exec(original);
    const id='correction-date-'+(++serial),label=source.closest('label');
    const widget=make('div',{className:'correction-datetime'}),dateBox=make('div',{className:'correction-day-control'}),clockBox=make('div',{className:'correction-clock-control'});
    const day=make('input',{id,type:'text',inputMode:'numeric',placeholder:'DD/MM/YYYY',required,autocomplete:'off'});
    day.dataset.correctionDay='';day.setAttribute('aria-label','Tanggal (DD/MM/YYYY)');
    const clock=make('input',{type:'text',inputMode:'numeric',placeholder:'HH:MM:SS',required:clockRequired,autocomplete:'off'});
    clock.dataset.correctionClock='';clock.setAttribute('aria-label','Jam WIB, format 24 jam');
    const toggle=make('button',{type:'button',className:'correction-calendar-toggle',title:'Pilih tanggal',innerHTML:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18"/></svg>'});
    toggle.setAttribute('aria-label','Pilih tanggal');toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-controls',id+'-calendar');
    dateBox.append(day,toggle);clockBox.append(clock,make('span',{textContent:'WIB',className:'correction-clock-zone'}));widget.append(dateBox,clockBox);source.after(widget);
    if(label)label.htmlFor=id;
    if(parts){day.value=parts[3]+'/'+parts[2]+'/'+parts[1];if(!precision||precision.value==='datetime')clock.value=parts[4]||'';}
    source.type='hidden';source.required=false;
    let view=parse(day.value)||{year:new Date().getFullYear(),month:new Date().getMonth(),day:new Date().getDate()};
    const calendar=make('div',{id:id+'-calendar',className:'correction-calendar',hidden:true});calendar.setAttribute('role','dialog');calendar.setAttribute('aria-label','Kalender tanggal transaksi');
    const header=make('div',{className:'correction-calendar-header'}),previous=make('button',{type:'button',textContent:'‹'}),next=make('button',{type:'button',textContent:'›'}),month=make('select'),year=make('select');
    previous.setAttribute('aria-label','Bulan sebelumnya');next.setAttribute('aria-label','Bulan berikutnya');month.setAttribute('aria-label','Bulan');year.setAttribute('aria-label','Tahun');
    month.setAttribute('data-native-select','');year.setAttribute('data-native-select','');
    months.forEach((name,index)=>month.append(make('option',{value:String(index),textContent:name})));
    for(let value=Math.min(1900,view.year);value<=Math.max(new Date().getFullYear()+1,view.year);value++)year.append(make('option',{value:String(value),textContent:String(value)}));
    header.append(previous,month,year,next);
    const weekdays=make('div',{className:'correction-calendar-weekdays'});['Sen','Sel','Rab','Kam','Jum','Sab','Min'].forEach(name=>weekdays.append(make('span',{textContent:name})));
    const grid=make('div',{className:'correction-calendar-days'}),footer=make('div',{className:'correction-calendar-footer'}),close=make('button',{type:'button',textContent:'Selesai'});
    footer.append(make('span',{textContent:'Tanggal / bulan / tahun'}),close);calendar.append(header,weekdays,grid,footer);
    // A dialog's top layer must own its calendar; ordinary forms use the document overlay.
    (source.closest('dialog')||document.body).append(calendar);
    const sync=(finish=false)=>{
      const selected=parse(day.value),text=clock.value.trim(),time=/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(text);
      const validTime=!text||!!time&&Number(time[1])<24&&Number(time[2])<60&&Number(time[3]||0)<60;
      day.setCustomValidity(day.value&&!selected?'Gunakan tanggal yang valid, contoh 22/10/2026.':'');
      clock.setCustomValidity(validTime?'':'Gunakan jam 24 jam, contoh 14:30:00.');
      if(!selected||!validTime||(!text&&clockRequired))source.value='';
      else{
        const stamp=time?pad(Number(time[1]))+':'+time[2]+':'+(time[3]||'00'):'';
        source.value=iso(selected.year,selected.month,selected.day)+'T'+(stamp||'00:00:00');
        if(precision)precision.value=stamp?'datetime':'date';
        if(finish){day.value=pad(selected.day)+'/'+pad(selected.month+1)+'/'+selected.year;if(stamp)clock.value=stamp;}
      }
      if(!day.value&&!clock.value&&precision)precision.value='none';
      source.dispatchEvent(new Event('change',{bubbles:true}));
    };
    const hide=(focus=false)=>{calendar.hidden=true;toggle.setAttribute('aria-expanded','false');if(focus)toggle.focus();};
    const place=()=>{
      const rect=widget.getBoundingClientRect(),width=Math.min(340,innerWidth-24);calendar.style.width=width+'px';
      calendar.style.left=Math.max(12,Math.min(rect.left,innerWidth-width-12))+'px';
      const height=calendar.getBoundingClientRect().height;calendar.style.top=Math.max(12,rect.bottom+height+10>innerHeight?rect.top-height-8:rect.bottom+8)+'px';
    };
    const render=()=>{
      month.value=String(view.month);year.value=String(view.year);grid.replaceChildren();
      const first=(new Date(view.year,view.month,1).getDay()+6)%7,total=new Date(view.year,view.month+1,0).getDate(),selected=parse(day.value);
      for(let index=0;index<first;index++)grid.append(make('span',{className:'correction-calendar-spacer'}));
      for(let value=1;value<=total;value++){
        const button=make('button',{type:'button',textContent:String(value)});button.dataset.calendarDay=String(value);
        const current=!!selected&&selected.day===value&&selected.month===view.month&&selected.year===view.year;
        button.classList.toggle('is-selected',current);button.setAttribute('aria-pressed',String(current));button.setAttribute('aria-label',value+' '+months[view.month]+' '+view.year);
        button.addEventListener('click',()=>{day.value=pad(value)+'/'+pad(view.month+1)+'/'+view.year;sync(true);hide();day.focus();});
        button.addEventListener('keydown',event=>{const step={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7}[event.key];if(step!==undefined){event.preventDefault();grid.querySelector('[data-calendar-day="'+Math.max(1,Math.min(total,value+step))+'"]')?.focus();}});
        grid.append(button);
      }
      if(!calendar.hidden)place();
    };
    toggle.addEventListener('click',()=>{if(!calendar.hidden){hide();return;}view=parse(day.value)||view;render();calendar.hidden=false;toggle.setAttribute('aria-expanded','true');place();(grid.querySelector('.is-selected')||grid.querySelector('button'))?.focus();});
    month.addEventListener('change',()=>{view.month=Number(month.value);render();});year.addEventListener('change',()=>{view.year=Number(year.value);render();});
    const move=amount=>{const date=new Date(view.year,view.month+amount,1),target=String(date.getFullYear());if(![...year.options].some(option=>option.value===target))return;view={year:date.getFullYear(),month:date.getMonth(),day:1};render();};
    previous.addEventListener('click',()=>move(-1));next.addEventListener('click',()=>move(1));close.addEventListener('click',()=>hide(true));
    calendar.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();hide(true);}});
    document.addEventListener('pointerdown',event=>{if(!calendar.contains(event.target)&&!widget.contains(event.target))hide();},{signal:lifetime.signal});
    addEventListener('resize',()=>hide(),{signal:lifetime.signal});addEventListener('scroll',event=>{if(!calendar.contains(event.target))hide();},{capture:true,signal:lifetime.signal});source.closest('dialog')?.addEventListener('close',()=>hide(),{signal:lifetime.signal});
    for(const field of [day,clock]){field.addEventListener('input',()=>sync());field.addEventListener('blur',()=>sync(true));}
    form.addEventListener('submit',event=>{sync(true);if(!day.checkValidity()||!clock.checkValidity()){event.preventDefault();event.stopImmediatePropagation();(!day.checkValidity()?day:clock).reportValidity();}},true);
    form.addEventListener('reset',()=>setTimeout(()=>{source.value=original;if(parts){day.value=parts[3]+'/'+parts[2]+'/'+parts[1];clock.value=(!precision||precision.value==='datetime')?(parts[4]||''):'';}else{day.value='';clock.value='';}hide();}));
    widgets.set(source,()=>{lifetime.abort();calendar.remove();});
  });
  init(document);
  const detail=document.getElementById('sw-history-detail');if(detail)new MutationObserver(()=>{for(const [source,dispose] of widgets)if(!source.isConnected){dispose();widgets.delete(source);}init(detail);}).observe(detail,{childList:true,subtree:true});
});
