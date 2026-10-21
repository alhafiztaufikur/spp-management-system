/* The visible field is authoritative on the server, including without JavaScript. */
(() => {
  'use strict';
  const date=document.querySelector('.correction-form [name=date]');date?.addEventListener('input',()=>{const precision=date.form.elements.time_precision;if(precision)precision.value=date.value?'datetime':'none';});
  const grouped=/^[1-9]\d{0,2}(?:\.\d{3})+(?:,\d{1,2})?$/;
  const simple=/^\d+(?:[.,]\d{1,2})?$/;
  const format=raw=>{const [whole,fraction]=raw.split(',');return whole.replace(/^0+(?=\d)/,'').replace(/\B(?=(\d{3})+(?!\d))/g,'.')+(fraction===undefined?'':','+fraction);};
  for(const field of document.querySelectorAll('[data-money-field]')){
    let dotPending=false;
    const normalize=()=>{
      const raw=field.value.trim();
      if(!raw){field.setCustomValidity('');return;}
      if(!grouped.test(raw)&&!simple.test(raw)){field.setCustomValidity('Gunakan nominal dengan maksimal dua desimal.');return;}
      const canonical=grouped.test(raw)?raw.replaceAll('.',''):raw.replace('.',',');
      field.value=format(canonical);field.setCustomValidity('');
    };
    field.addEventListener('input',event=>{
      field.setCustomValidity('');
      if(event.inputType==='insertText'&&event.data==='.'&&!field.value.includes(',')){dotPending=true;return;}
      if(dotPending){const suffix=field.value.slice(field.value.lastIndexOf('.')+1);if(!field.value.includes(',')&&suffix.length<3)return;dotPending=false;}
      if((event.data?.length===1&&/\d/.test(event.data)||event.inputType?.startsWith('delete'))&&/^[\d.]+(?:,\d{0,2})?$/.test(field.value)){
        const tail=field.value.length-(field.selectionStart??field.value.length);field.value=format(field.value.replaceAll('.',''));const pos=Math.max(0,field.value.length-tail);field.setSelectionRange(pos,pos);return;
      }
      const raw=field.value;if(raw.endsWith(',')){
        const whole=raw.slice(0,-1).replaceAll('.','');if(/^\d+$/.test(whole))field.value=format(whole+',');return;
      }
      const tail=raw.length-(field.selectionStart??raw.length);normalize();const pos=Math.max(0,field.value.length-tail);field.setSelectionRange(pos,pos);
    });
    const finish=()=>{if(dotPending){const last=field.value.lastIndexOf('.'),tail=field.value.slice(last+1);if(last>=0&&tail.length<=2)field.value=field.value.slice(0,last).replaceAll('.','')+(tail?','+tail:'');}dotPending=false;normalize();};
    field.addEventListener('blur',finish);
    field.form?.addEventListener('submit',finish);
    // Partial decimal typing must be finished before browser constraint validation.
    field.form?.addEventListener('click',event=>{if(event.target.closest('button:not([type]),button[type="submit"]'))finish();});
  }
})();
