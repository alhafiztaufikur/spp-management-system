const assert=require('node:assert/strict'),fs=require('node:fs');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core'),base=process.env.SPP_TEST_BASE_URL;
assert.match(base,/^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true}),context=await browser.newContext(),page=await context.newPage(),errors=[];
page.on('pageerror',e=>errors.push(e.message));
try{
 assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
 await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
 if(!process.argv.includes('--native-only')){
 for(const unit of [1,2,3]){
  await page.goto(base+'/dashboard.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
  await page.goto(base+'/siswa/daftar.php');assert.equal(await page.locator('.student-panel').count(),5);assert.equal(await page.locator('#advanced-enabled,.student-panel-advanced,.student-locked-note').count(),0);
  for(const selector of ['#nis-diknas','#student-psb','#student-pomg','#student-potongan-spp','#student-potong-du'])assert.equal(await page.locator(selector).isEditable(),true);
  await page.locator('#student-daftar_ulang').fill('1000000');await page.locator('#student-potong-du').fill('10000');assert.equal(await page.locator('#student-total-du').inputValue(),'990.000');
  await page.locator('#student-reset-form').click();assert.equal(await page.locator('#student-daftar_ulang').inputValue(),'');assert.equal(await page.locator('#form-master-siswa input[name=aksi]').inputValue(),'tambah');
  for(const width of [1440,390])for(const theme of ['light','dark']){
   await page.setViewportSize({width,height:1000});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+2);
   if(process.env.SPP_UI_OUTPUT)await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/students-'+unit+'-'+width+'-'+theme+'.png',fullPage:true,animations:'disabled'});
  }
  await page.setViewportSize({width:1440,height:1000});await page.goto(base+'/master_spp.php?tahun=2026%2F2027#spp-rate-panel');
  const field=page.locator('#spp-rate-form [data-level]').first(),original=Number(await field.getAttribute('data-original'));
  assert.equal(await field.isDisabled(),true);await page.locator('#spp-rate-edit').click();assert.equal(await field.isEditable(),true);assert.equal(await page.locator('#spp-rate-edit').getAttribute('aria-disabled'),'true');
  await field.fill(String(original+1));assert.equal(await page.locator('#spp-rate-save').isVisible(),true);await field.fill(String(original));assert.equal(await page.locator('#spp-rate-save').isVisible(),false);
  await field.fill(String(original+1));await page.locator('#spp-rate-cancel').click();assert.equal(await field.isDisabled(),true);assert.equal(Number((await field.inputValue()).replace(/\./g,'')),original);
  await page.locator('#spp-rate-edit').click();await field.fill(String(original+1));await page.locator('#spp-rate-save').click();
  await page.locator('#spp-warning-overlay.show').waitFor();assert.equal(await page.locator('#spp-warning-title').innerText(),'Simpan perubahan tarif SPP?');
  await page.locator('#spp-warning-secondary').click();assert.equal(await field.isEditable(),true);assert.equal(Number((await field.inputValue()).replace(/\./g,'')),original+1);
  await page.locator('#spp-rate-save').click();for(const theme of ['light','dark']){await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);if(process.env.SPP_UI_OUTPUT)await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/rate-modal-'+unit+'-'+theme+'.png',animations:'disabled'});}
  await Promise.all([page.waitForNavigation(),page.locator('#spp-warning-close').click()]);assert.equal(await field.isDisabled(),true);assert.equal(Number((await field.inputValue()).replace(/\./g,'')),original+1);
  console.log('PASS: unit '+unit+' five editable student panels, reset, responsive themes, edit/cancel/dirty modal and confirmed correction');
 }
 // Local SD future year is a draft only on this disposable clone.
 await page.goto(base+'/dashboard.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('1')]);
 await page.goto(base+'/master_spp.php?tahun=2027%2F2028#spp-rate-panel');
 for(const field of await page.locator('#spp-rate-form [data-level]').all())await field.fill('330000');
 await page.locator('#spp-rate-save').click();await page.locator('#spp-warning-overlay.show').waitFor();assert.equal(await page.locator('#spp-warning-title').innerText(),'Periksa tarif SPP');
 await Promise.all([page.waitForNavigation(),page.locator('#spp-warning-close').click()]);
 await page.locator('.spp-workspace-tabs [data-spp-tab=students]').click();assert.ok(await page.locator('.spp-publish-row').count());
 await page.locator('[name="selected_students[]"]').first().check();await page.locator('#spp-submit-button').click();await page.locator('#spp-warning-overlay.show').waitFor();
 assert.equal(await page.locator('#spp-warning-title').innerText(),'Terbitkan tagihan SPP?');await page.locator('#spp-warning-close').click();
 await page.waitForFunction(()=>!document.getElementById('prior-debt-modal').hidden||document.getElementById('spp-publish-form').dataset.firstPublication==='false');
 if(await page.locator('#prior-debt-modal').isVisible()){assert.equal(await page.locator('#spp-warning-overlay.show').count(),0);await Promise.all([page.waitForNavigation(),page.locator('[data-prior-debt-continue]').click()]);}
 assert.equal(await page.locator('#spp-publish-form').getAttribute('data-first-publication'),'false');console.log('PASS: draft save confirmation -> first publication confirmation -> existing debt confirmation -> publish');
 }
 // The native form receives the same confirmation when JavaScript is disabled.
 const native=await browser.newContext({javaScriptEnabled:false});await native.addCookies(await context.cookies());const np=await native.newPage();
 await np.goto(base+'/master_spp.php?tahun=2168%2F2169');for(const field of await np.locator('#spp-rate-form [data-level]').all())await field.fill('250000');
 await Promise.all([np.waitForNavigation(),np.locator('#spp-rate-save').press('Enter')]);assert.equal(await np.locator('#rate-confirm-title').innerText(),'Periksa tarif SPP');
 await Promise.all([np.waitForNavigation(),np.getByRole('button',{name:'Simpan Tarif',exact:true}).press('Enter')]);assert.ok((await np.locator('#flash-msg').innerText()).includes('tersimpan'));
 await np.goto(base+'/master_spp.php?tahun=2026%2F2027&edit_tarif=1');assert.equal(await np.locator('#spp-rate-form [data-level]').first().isEditable(),true);
 await Promise.all([np.waitForNavigation(),np.locator('#spp-rate-cancel').press('Enter')]);assert.equal(await np.locator('#spp-rate-form [data-level]').first().isDisabled(),true);
 await np.goto(base+'/siswa/daftar.php');assert.equal(await np.locator('#nis-diknas').isEditable(),true);assert.equal(await np.locator('#student-psb').isEditable(),true);
 const nis=String(9700000000+Math.floor(Math.random()*90000000));await np.locator('#nis-baru').fill(nis);await np.locator('#nama-baru').fill('TEST NATIVE STUDENT');
 const nativeClass=np.locator('select[name=master_kelas_id]');const classId=await nativeClass.locator('option').evaluateAll(options=>options.find(o=>o.textContent.trim()==='1A')?.value);assert.ok(classId);await nativeClass.selectOption(classId);
 await np.locator('#student-komite-start').selectOption('07');await np.locator('#nis-diknas').fill('00'+nis.slice(-8));await np.locator('#student-daftar_ulang').fill('250000');await np.locator('#student-potong-du').fill('30000');
 await Promise.all([np.waitForNavigation(),np.locator('#student-submit-form').press('Enter')]);assert.match(await np.locator('#flash-msg').innerText(),/berhasil ditambahkan/);
 await np.goto(base+'/siswa/daftar.php?q='+nis);await np.locator('a[href*="edit="]').first().press('Enter');assert.equal(await np.locator('#nis-baru').isEditable(),false);assert.equal(await np.locator('#student-daftar_ulang').inputValue(),'250.000');await native.close();
 await page.goto(base+'/dashboard.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('0')]);await page.goto(base+'/siswa/daftar.php');assert.equal(await page.locator('#student-psb').isDisabled(),true);
 console.log('PASS: native confirmation, native student fields, cancelled native edit and combined read-only form');assert.deepEqual(errors,[]);
}finally{await context.close();await browser.close();}})().catch(e=>{console.error(e.stack);process.exitCode=1;});
