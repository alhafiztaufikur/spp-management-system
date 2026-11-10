/* Saved totals are server values. Input changes only mark the form as unsaved. */
document.addEventListener('DOMContentLoaded',function(){
  const yearLabel=document.querySelector('label[for="du-year-select"]');
  const yearTrigger=document.querySelector('.du-year-picker .spp-select-trigger');
  if(yearLabel&&yearTrigger){
    yearTrigger.id='du-year-trigger';
    yearLabel.htmlFor=yearTrigger.id;
  }
  const form=document.getElementById('du-rate-form');if(!form)return;
  const inputs=Array.from(form.querySelectorAll('.rupiah-input'));
  form.addEventListener('input',function(){
    const dirty=inputs.some(el=>el.value.replace(/[^0-9,]/g,'')!==(el.dataset.savedValue||'').replace(/[^0-9,]/g,''));
    const message=form.querySelector('[data-du-unsaved]');if(message)message.hidden=!dirty;
  });
  const submit=form.querySelector('#du-rate-submit');
  const label=form.querySelector('[data-du-submit-label]');
  if(!submit||!label)return;
  const savedLabel=label.textContent;
  const initiallyDisabled=submit.disabled;
  form.addEventListener('submit',function(event){
    if(event.defaultPrevented)return;
    if(form.getAttribute('aria-busy')==='true'){event.preventDefault();return;}
    form.setAttribute('aria-busy','true');
    submit.disabled=true;
    label.textContent='Menyimpan tarif dan melengkapi tagihan…';
  });
  window.addEventListener('pageshow',function(){
    form.removeAttribute('aria-busy');
    submit.disabled=initiallyDisabled;
    label.textContent=savedLabel;
  });
});
