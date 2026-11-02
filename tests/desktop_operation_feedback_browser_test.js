const assert=require('node:assert/strict'),fs=require('node:fs');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core'),base=process.env.SPP_TEST_BASE_URL;
assert.ok(/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),page=await browser.newPage({viewport:{width:1440,height:1080}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 const dialog=page.locator('#operation-feedback-dialog');
 async function result(type,pattern){await dialog.waitFor({state:'visible'});assert.equal(await dialog.getAttribute('data-type'),type);if(pattern)assert.match(await page.locator('#operation-feedback-message').innerText(),pattern);assert.equal(await page.locator('#flash-msg:visible').count(),0);await dialog.getByRole('button',{name:'Mengerti',exact:true}).click();}
 async function nativePost(form,values){const fields=await form.evaluate(e=>Object.fromEntries(new FormData(e))),action=await form.getAttribute('action');const response=await page.request.post(new URL(action||page.url(),page.url()).href,{form:{...fields,...values},maxRedirects:0});assert.ok([302,303].includes(response.status()));const destination=new URL(response.headers().location,page.url()),current=new URL(page.url());if(destination.pathname===current.pathname&&destination.search===current.search)await page.reload();else await page.goto(destination.href);}
 try{
  assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  const name='QA Popup '+Date.now();
  await page.goto(base+'/master_biaya_lain.php');
  if(await page.locator('#sidebar-unit-select').inputValue()!=='1')await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('1')]);
  assert.equal(await page.locator('#operation-feedback-dialog[open]').count(),0);
  const fee=page.locator('#form-master-biaya');await fee.locator('[name=nama]').fill(name);await fee.locator('[name=nominal]').fill('125000');
  await Promise.all([page.waitForNavigation(),fee.getByRole('button',{name:'Tambah Biaya',exact:true}).click()]);await result('success',/berhasil ditambahkan/);
  await fee.locator('[name=nama]').fill(name);await fee.locator('[name=nominal]').fill('125000');await Promise.all([page.waitForNavigation(),fee.getByRole('button',{name:'Tambah Biaya',exact:true}).click()]);await result('error',/sudah tersedia/);
  let row=page.locator('.payment-table tbody tr').filter({hasText:name});
  await nativePost(row.locator('form:has(input[name="aksi"][value="toggle"])'),{target_active:'1'});await result('warning',/Tidak ada perubahan/);
  await row.locator('a[href*="edit="]').click();await fee.locator('[name=nominal]').fill('150000');
  await Promise.all([page.waitForNavigation(),fee.getByRole('button',{name:'Simpan Perubahan',exact:true}).click()]);await result('success',/berhasil diperbarui/);
  row=page.locator('.payment-table tbody tr').filter({hasText:name});await nativePost(row.locator('form:has(input[name="aksi"][value="toggle"])'),{target_active:'0'});await result('success',/Status master/);
  await page.goto(base+'/master_biaya_lain.php?master_tab=hapus');row=page.locator('.payment-table tbody tr').filter({hasText:name});await nativePost(row.locator('form:has(input[name="aksi"][value="hapus"])'),{});await result('success',/berhasil dihapus/);assert.equal(await page.locator('.payment-table tbody tr').filter({hasText:name}).count(),0);
  // Account creation and rejection use real controller outcomes, not synthetic success messages.
  await page.goto(base+'/role_management.php');if(await page.locator('#sidebar-unit-select').inputValue()!=='0')await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('0')]);
  const account=page.locator('#form-tambah-akun'),username='qa.popup.'+Date.now();
  const accountValues={nama:'QA Popup Account',username,role:'admin',password:'QAPopupPassword123!',password_confirmation:'QAPopupPassword123!'};
  await nativePost(account,accountValues);await result('success',/berhasil dibuat/);
  await nativePost(account,accountValues);await result('error',/Username sudah digunakan/);
  // Operation messages remain readable without JavaScript.
  const fallback=await browser.newContext({javaScriptEnabled:false});await fallback.addCookies(await page.context().cookies());const plain=await fallback.newPage();
  const pending=await page.request.post(base+'/role_management.php',{form:{...(await account.evaluate(e=>Object.fromEntries(new FormData(e)))),...accountValues,password_confirmation:'DifferentPassword123!'},maxRedirects:0});
  await plain.goto(new URL(pending.headers().location,base+'/role_management.php').href);assert.ok(await plain.locator('#flash-msg').isVisible());assert.match(await plain.locator('#flash-msg').innerText(),/Konfirmasi password/);await fallback.close();
  await page.goto(base+'/role_management.php');
  // Dedicated results never replace a field correction or confirmation action.
  for(const width of [1280,1440,1920])for(const theme of ['light','dark'])for(const type of ['success','error','warning']){
   await page.setViewportSize({width,height:1080});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
   const message='Hasil '+type+': <img src=x onerror=alert(1)> '+('Rincian hasil tindakan. '.repeat(45));
   await account.locator('[name=nama]').focus();
   await page.evaluate(({type,message})=>{const source=document.querySelector('#form-tambah-akun [name=nama]');window.showOperationResult({type,message,source});window.showOperationResult({type,message,source});},{type,message});
   await dialog.waitFor({state:'visible'});assert.equal(await page.locator('#operation-feedback-message img').count(),0);assert.equal(await page.locator('#operation-feedback-message').innerText(),message.trim());
   assert.equal(await dialog.evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight&&e.scrollWidth<=e.clientWidth+2;}),true);
   await page.keyboard.press('Shift+Tab');assert.equal(await dialog.evaluate(e=>e.contains(document.activeElement)),true);
   if(process.env.SPP_UI_OUTPUT&&width===1440)await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/result-'+type+'-'+theme+'.png',animations:'disabled'});
   await page.keyboard.press('Escape');assert.equal(await account.locator('[name=nama]').evaluate(e=>e===document.activeElement),true);await page.waitForTimeout(25);assert.equal(await page.locator('#operation-feedback-dialog[open]').count(),0);
  }
  // Existing action-specific warnings must not also produce a generic result popup.
  const specific=await browser.newPage();
  await specific.route('**/__feedback_component',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><script>window.sppFlashWarning={title:"Validasi khusus"};</script><div id="flash-msg" class="alert alert-error">Validasi khusus</div><script defer src="/assets/js/operation_feedback.js"></script>'}));
  await specific.goto(base+'/__feedback_component');assert.equal(await specific.locator('#operation-feedback-dialog[open]').count(),0);assert.equal(await specific.locator('#flash-msg').isVisible(),true);
  // Import jobs notify once after an observed active -> terminal transition, never on every poll/reload.
  let jobState='processing';await specific.route('**/__feedback_job',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><section data-import-progress="test-desktop-job" data-state="'+jobState+'" data-operation-job-message="Pemeriksaan selesai."></section><script defer src="/assets/js/operation_feedback.js"></script>'}));
  await specific.goto(base+'/__feedback_job');assert.equal(await specific.locator('#operation-feedback-dialog[open]').count(),0);
  jobState='imported';await specific.reload();await specific.locator('#operation-feedback-dialog[open]').waitFor();assert.equal(await specific.locator('#operation-feedback-dialog').getAttribute('data-type'),'success');await specific.getByRole('button',{name:'Mengerti'}).click();await specific.reload();assert.equal(await specific.locator('#operation-feedback-dialog[open]').count(),0);await specific.close();
  assert.deepEqual(errors,[]);console.log('PASS: real fee CRUD/status/no-op, account create/rejection, no-JS fallback, desktop result themes, safe text, deduplication and keyboard focus.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
