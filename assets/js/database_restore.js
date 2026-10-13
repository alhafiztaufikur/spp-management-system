/* Validation, explicit confirmation and resumable progress for a full SQL restore. */
(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const node = byId('database-restore-config');
  if (!node) return;
  const config = JSON.parse(node.textContent);
  let capability = config.job, job = null, file = null, busy = false, timer, previousState = null, notifiedTerminal = null;
  const originalTitle = document.title;
  const dialog = byId('restore-dialog');
  const states = {validating:'Memeriksa SQL', ready:'Terverifikasi', queued:'Menunggu', safety_backup:'Backup Pengaman', restoring:'Memulihkan Database', auditing:'Memeriksa Hasil', rolling_back:'Mengembalikan Backup Pengaman', completed:'Selesai', rolled_back:'Backup Pengaman Dipulihkan', failed:'Gagal', cancelled:'Dibatalkan', recovery_required:'Pemulihan Pengaman Tertunda'};
  const active = () => job && (['validating','queued','safety_backup','restoring','auditing','rolling_back'].includes(job.state) || job.finalized === false);
  const setText = (id,text) => {const target=byId(id);if(target && target.textContent !== text) target.textContent=text;};
  const feedback = message => {
    setText('restore-unavailable',message);setText('restore-job-status',message);
  };
  const renderProgress = (state, finalized = true) => {
    const stages={uploading:[0,'Mengunggah SQL'],validating:[0,'Memeriksa SQL'],ready:[0,'SQL terverifikasi'],queued:[1,'Menunggu backup pengaman'],safety_backup:[1,'Membuat backup pengaman'],restoring:[2,'Memulihkan data'],auditing:[3,'Memverifikasi hasil'],completed:[3,'Pemulihan selesai'],rolling_back:[3,'Mengembalikan backup pengaman'],rolled_back:[3,'Backup pengaman dikembalikan'],recovery_required:[3,'Pemulihan pengaman tertunda'],failed:[0,'Pemeriksaan belum berhasil'],cancelled:[0,'Pemulihan dibatalkan']};
    const [step,label]=stages[state] || [0,'Menyiapkan pemulihan'];
    const terminal=['completed','rolled_back'].includes(state) && finalized;
    byId('restore-progress-panel').hidden=false;
    setText('restore-stage-label',finalized ? label : 'Menyelesaikan pemulihan');
    setText('restore-stage-count',terminal ? '4 tahap selesai' : `Tahap ${step+1} dari 4`);
    const bar=byId('restore-progress-bar');
    if(['uploading','validating'].includes(state))bar.removeAttribute('value');
    else bar.value=terminal ? 4 : state==='ready' ? 1 : step;
    bar.setAttribute('aria-valuetext',finalized ? label : 'Menyelesaikan pemulihan');
    byId('restore-progress-panel').setAttribute('aria-busy',String(['uploading','validating','queued','safety_backup','restoring','auditing','rolling_back'].includes(state) || !finalized));
    document.querySelectorAll('[data-restore-step]').forEach(item=>{
      const index=Number(item.dataset.restoreStep),complete=terminal || index<step || (state==='ready' && index===0);
      item.classList.toggle('is-complete',complete);
      if(index===step && !terminal && state!=='ready')item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');
    });
  };
  const renderResult = () => {
    const terminal=job.finalized !== false && ['completed','rolled_back','failed','cancelled'].includes(job.state);
    byId('restore-result').hidden=!terminal;
    if(!terminal)return;
    const success=job.state==='completed';
    byId('restore-result').dataset.kind=success ? 'success' : job.state==='failed' ? 'error' : 'warning';
    setText('restore-result-title',success ? 'Pemulihan berhasil selesai' : job.state==='rolled_back' ? 'Backup pengaman berhasil dikembalikan' : job.state==='cancelled' ? 'Pemulihan dibatalkan' : 'Database tidak diganti');
    setText('restore-result-message',success ? 'Seluruh data sudah cocok dengan cadangan. Silakan masuk kembali.' : job.message);
    const finished=job.finished_at ? new Date(job.finished_at) : null;
    setText('restore-result-time',finished && !Number.isNaN(finished.valueOf()) ? 'Selesai '+new Intl.DateTimeFormat('id-ID',{timeZone:'Asia/Jakarta',dateStyle:'medium',timeStyle:'short'}).format(finished)+' WIB' : '');
    if(success || job.state==='rolled_back'){
      document.title=(success ? 'Pemulihan selesai' : 'Backup pengaman dipulihkan')+' | SistemSPP';
      if(notifiedTerminal!==job.id && previousState && typeof dialog.showModal==='function' && !dialog.open)dialog.showModal();
      notifiedTerminal=job.id;
    }
  };
  const render = () => {
    if (!job) return;
    byId('restore-dialog-file').textContent = job.name;
    byId('restore-validation-state').textContent = states[job.state] || job.state;
    feedback(job.message);
    renderProgress(job.state,job.finalized !== false);renderResult();
    const ready = job.state === 'ready' && job.expires_at * 1000 > Date.now();
    byId('restore-confirm-fields').hidden=!ready;
    byId('restore-understood').disabled = !ready || busy;
    byId('restore-confirmation').disabled = !ready || busy;
    byId('restore-apply').disabled = !ready || busy || !byId('restore-understood').checked || byId('restore-confirmation').value !== 'PULIHKAN DATABASE';
    byId('restore-apply').hidden=!ready;
    byId('restore-resume').hidden = job.state !== 'recovery_required';
    byId('restore-resume').disabled = busy;
    byId('restore-login').hidden = job.finalized===false || !['completed','rolled_back'].includes(job.state);
    if (byId('restore-progress-link')) byId('restore-progress-link').hidden = false;
    const locked = busy || !!active() || job.state === 'recovery_required';
    document.dispatchEvent(new CustomEvent('database-restore-active',{detail:['queued','safety_backup','restoring','auditing','rolling_back','recovery_required'].includes(job.state) || job.finalized === false || ['completed','rolled_back'].includes(job.state)}));
    if (byId('restore-review')) {byId('restore-review').disabled = locked || !file;byId('restore-review').dataset.busy = String(locked);}
    ['restore-choose','restore-change','restore-remove','restore-file'].forEach(id => {if (byId(id)) byId(id).disabled = locked;});
    previousState=job.state;
  };
  const request = async (action, data = {}) => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), action === 'upload' ? 120000 : 30000);
    try {
      let response;
      if (action === 'status') response = await fetch(`restore_api.php?action=status&id=${encodeURIComponent(capability.id)}`, {credentials:'same-origin',headers:{'X-Restore-Token':capability.token},signal:controller.signal});
      else {
        const body = new FormData();body.set('action',action);body.set('csrf_token',config.csrf);
        if (capability) {body.set('id',capability.id);body.set('token',capability.token);}
        Object.entries(data).forEach(([key,value]) => body.set(key,value));
        response = await fetch('restore_api.php',{method:'POST',credentials:'same-origin',body,signal:controller.signal});
      }
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Pemulihan belum dapat diproses.');
      return result;
    } finally {clearTimeout(timeout);}
  };
  const poll = async () => {
    if (!capability) return;
    clearTimeout(timer);
    try {
      const result = await request('status');job = result.job;render();
      if (active()) timer = setTimeout(poll,2000);
    } catch (_) {feedback('Progres belum dapat dihubungi. Akan dicoba kembali; jangan mengunggah ulang.');timer = setTimeout(poll,5000);}
  };
  document.addEventListener('restore-file-selected', event => {
    if (active() || (job && job.state === 'recovery_required')) {feedback('Selesaikan pemulihan yang berjalan sebelum memilih file lain.');return;}
    file = event.detail;job = null;capability = null;previousState=null;notifiedTerminal=null;clearTimeout(timer);document.title=originalTitle;
  });
  byId('restore-review')?.addEventListener('click', async () => {
    if (!file || busy || active()) return;
    if (job?.state === 'ready') {dialog.showModal();return;}
    busy = true;byId('restore-review').disabled = true;
    byId('restore-review').dataset.busy = 'true';byId('restore-progress-link').hidden = false;
    byId('restore-dialog-file').textContent = file.name;
    byId('restore-understood').checked = false;byId('restore-understood').disabled = true;
    byId('restore-confirmation').value = '';byId('restore-confirmation').disabled = true;
    byId('restore-validation-state').textContent = 'Mengunggah SQL';
    byId('restore-confirm-fields').hidden=true;byId('restore-result').hidden=true;
    byId('restore-apply').disabled = true;byId('restore-apply').hidden=true;byId('restore-login').hidden=true;byId('restore-resume').hidden=true;
    renderProgress('uploading');feedback('Mengunggah SQL untuk pemeriksaan pada database terpisah.');dialog.showModal();
    try {
      const result = await request('upload',{sql:file});job = result.job;capability = {id:job.id,token:result.token};
      poll();
    } catch (error) {renderProgress('failed');feedback(error.name === 'AbortError' ? 'Unggahan belum merespons. Buka Lihat Progres Pemulihan untuk memeriksa pekerjaan yang sudah diterima.' : error.message);}
    finally {busy = false;render();if (!job) {byId('restore-review').disabled = !file;byId('restore-review').dataset.busy = 'false';}}
  });
  ['restore-understood','restore-confirmation'].forEach(id => byId(id).addEventListener('input',render));
  byId('restore-apply').addEventListener('click',async () => {
    if (busy || byId('restore-apply').disabled) return;
    busy = true;render();document.dispatchEvent(new CustomEvent('database-restore-active',{detail:true}));
    try {const result = await request('apply',{understood:'1',confirmation:byId('restore-confirmation').value});job = result.job;}
    catch (error) {feedback(error.message);}
    finally {busy = false;render();poll();}
  });
  byId('restore-resume').addEventListener('click',async () => {
    if (busy) return;busy = true;render();
    try {await request('resume');poll();} catch (error) {feedback(error.message);}finally {busy = false;render();}
  });
  if (capability) {poll();if (!dialog?.showModal) byId('restore-dialog')?.removeAttribute('hidden');}
})();
