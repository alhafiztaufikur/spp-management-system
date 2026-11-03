/* Reuse the payment student's combobox while keeping the existing GET q filter. */
(() => {
  const input=document.getElementById('registration-history-search');
  if(!input)return;
  window.selectRegistrationHistoryStudent=(field,option)=>{
    field.value=option.dataset.nis||option.dataset.nama||option.value;
  };
  const desktop=window.matchMedia('(min-width:1024px)');
  function enhance(){
    if(!desktop.matches)return;
    input.dataset.studentSearch='';
    input.dataset.studentList='registration-history-students';
    input.dataset.studentFitViewport='1';
    input.dataset.studentSelectCallback='selectRegistrationHistoryStudent';
    if(typeof window.initStudentSearchInput==='function')window.initStudentSearchInput(input);
  }
  enhance();
  desktop.addEventListener('change',enhance);
})();
