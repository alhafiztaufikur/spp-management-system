/* Indonesian presentation; native inputs keep ISO request values. */
function sppIsoDate(value) {
  const match=/^(\d{2})\/(\d{2})\/(\d{4})$/.exec(String(value||''));
  if (!match) return '';
  const d=Number(match[1]),m=Number(match[2]),y=Number(match[3]);
  if (y<1||m<1||m>12||d<1||d>new Date(Date.UTC(y,m,0)).getUTCDate()) return '';
  return match[3]+'-'+match[2]+'-'+match[1];
}
function sppDateLabel(value, withTime=false) {
  const text=String(value||'');const match=/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?)?$/.exec(text);
  if(match) {
    const label=match[3]+'/'+match[2]+'/'+match[1];
    if (!sppIsoDate(label)) return '—';
    if (match[4] && (Number(match[4])>23||Number(match[5])>59||Number(match[6])>59))return '—';
    return label+(withTime&&match[4]?' '+match[4]+':'+match[5]+':'+match[6]+' WIB':'');
  }
  if(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(text)){
    if(!sppIsoDate(text.slice(8,10)+'/'+text.slice(5,7)+'/'+text.slice(0,4)))return '\u2014';
    const d=new Date(text);if(Number.isNaN(d.getTime()))return '—';
    const parts=new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Jakarta',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(d);
    const p=Object.fromEntries(parts.map(v=>[v.type,v.value]));
    return p.day+'/'+p.month+'/'+p.year+(withTime?' '+p.hour+':'+p.minute+':'+p.second+' WIB':'');
  }return '—';
}
function initIndonesianDateInputs() {
  document.querySelectorAll('input[type="date"]').forEach(native=>{
    if(native.dataset.indonesianReady)return;
    native.dataset.indonesianReady='1';
    const wrapper=document.createElement('div');wrapper.className='id-date-control';
    native.before(wrapper);wrapper.append(native);
    const visible=document.createElement('input');visible.type='text';visible.inputMode='numeric';visible.placeholder='DD/MM/YYYY';visible.className=native.className||'field-input';
    visible.dataset.dateDisplay=native.id||native.name||'date';
    visible.readOnly=native.readOnly;visible.disabled=native.disabled;visible.required=native.required;
    const label=native.id?document.querySelector('label[for="'+native.id+'"]'):null;
    if(native.id){visible.id=native.id+'-display';if(label)label.htmlFor=visible.id;}
    visible.setAttribute('aria-label',(label?.textContent||native.getAttribute('aria-label')||'Tanggal')+' (DD/MM/YYYY)');
    native.classList.add('id-date-native');native.tabIndex=-1;native.required=false;
    wrapper.prepend(visible);
    const sync=()=>{visible.value=native.value?sppDateLabel(native.value):'';visible.setCustomValidity('');};
    const push=()=>{
      if(visible.readOnly)return;
      const iso=sppIsoDate(visible.value);const valid=visible.value===''||iso!=='';
      visible.setCustomValidity(valid?'':'Gunakan tanggal yang valid dengan format DD/MM/YYYY.');
      native.value=iso;
      native.dispatchEvent(new Event('change',{bubbles:true}));
      if(!valid){visible.value=visible.dataset.typedDate;visible.setCustomValidity('Gunakan tanggal yang valid dengan format DD/MM/YYYY.');}
    };
    visible.addEventListener('change',()=>{visible.dataset.typedDate=visible.value;push();});
    native.addEventListener('change',sync);
    if(!native.readOnly){
      const button=document.createElement('button');button.type='button';button.className='id-date-picker';button.textContent='▦';button.setAttribute('aria-label','Buka kalender');
      wrapper.append(button);button.disabled=native.disabled;
      button.addEventListener('click',()=>{try{native.showPicker();}catch(_){native.focus();}});
    }
    sync();
  });
}
document.addEventListener('DOMContentLoaded',initIndonesianDateInputs);

