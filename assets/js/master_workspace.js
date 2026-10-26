/* Saved totals are server values. Input changes only mark the form as unsaved. */
document.addEventListener('DOMContentLoaded',function(){
  const form=document.getElementById('du-rate-form');if(!form)return;
  const inputs=Array.from(form.querySelectorAll('.rupiah-input'));
  form.addEventListener('input',function(){
    const dirty=inputs.some(el=>el.value.replace(/[^0-9,]/g,'')!==(el.dataset.savedValue||'').replace(/[^0-9,]/g,''));
    const message=form.querySelector('[data-du-unsaved]');if(message)message.hidden=!dirty;
  });
});
