/* Create/remove one deposit through existing services, only after proving the HTTP audit target. */
const fs=require('fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'C:/laragon/backups/spp-management-system/import_audit_20261020/browser-tools/node_modules/playwright-core');
const root=process.argv[2];assert(root?.includes('desktop_refinements_'));const config=JSON.parse(fs.readFileSync(root+'/config.json')),fixture=JSON.parse(fs.readFileSync(root+'/fixture.json'));assert(config.database.startsWith('db_spp_audit_'));const base='http://127.0.0.1:'+config.port;
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});let historyUrl='',removed=false;
 const context=await browser.newContext({viewport:{width:1440,height:1000}});await context.addCookies([{name:'PHPSESSID',value:fixture.sessions.admin[1],url:base}]);await context.route('https://**/*',route=>route.abort());const page=await context.newPage();
 const fields=selector=>page.locator(selector).evaluate(form=>Object.fromEntries(new FormData(form)));
 try{
  const identity=await context.request.get(base+'/tests/browser_clone_identity.php');assert.equal(identity.status(),200);assert.equal((await identity.json()).database,config.database);
  await page.goto(base+'/tabungan/masuk.php?owner_type=student');await Promise.all([page.waitForURL(url=>url.searchParams.has('student_id')),page.locator('.sw-student-option').first().click()]);
  const before=await page.locator('[data-savings-entry]').getAttribute('data-balance'),input=await fields('[data-savings-entry]');input.nominal='100.000,13';input.keterangan='QA desktop date widget; disposable clone';
  const created=await context.request.post(base+'/tabungan/proses.php',{form:input,maxRedirects:0});assert.equal(created.status(),302);const location=new URL(created.headers().location,base+'/tabungan/');assert(Number(location.searchParams.get('id'))>0);location.searchParams.delete('after_save');location.searchParams.set('all_periods','1');historyUrl=location.href;
  await page.goto(historyUrl);const later=page.locator('#sw-print-later');if(await later.isVisible())await later.click();
  await page.locator('[data-savings-open=edit]').click();const dialog=page.locator('[data-savings-editor=edit][open]');await dialog.locator('[data-correction-day]').waitFor();const canonical=await dialog.locator('[name=tanggal]').inputValue();assert(await dialog.locator('[data-correction-clock]').inputValue());
  await dialog.getByRole('button',{name:'Pilih tanggal',exact:true}).click();assert(await dialog.getByRole('dialog',{name:'Kalender tanggal transaksi'}).isVisible());await page.keyboard.press('Escape');assert(await dialog.isVisible(),'Closing calendar must retain correction dialog');
  await dialog.locator('[name=reason]').fill('Pemeriksaan widget tanggal koreksi native pada salinan.');await dialog.getByRole('button',{name:'Tinjau Perubahan'}).click();await dialog.locator('.sw-review-stage:not([hidden])').waitFor();assert.equal(await dialog.locator('[name=tanggal]').inputValue(),canonical);await page.screenshot({path:root+'/artifacts/native-correction-desktop.png'});await dialog.locator('[data-sw-cancel]').click();
  // Replace the detail through its existing AJAX route; the date widget must initialize again.
  const row=page.locator('.sw-transaction[data-id="'+location.searchParams.get('id')+'"] .sw-transaction-select');await row.click();await page.locator('#sw-history-detail[aria-busy]').waitFor({state:'detached'});await page.locator('[data-savings-open=edit]').click();await page.locator('[data-savings-editor=edit][open] [data-correction-day]').waitFor();
  const cleanup=await fields('[data-savings-editor=edit] form');cleanup.action='direct_hapus';cleanup.reason='Hapus fixture pengujian tampilan kalender native.';const deleted=await context.request.post(base+'/tabungan/koreksi.php',{form:cleanup,maxRedirects:0});assert.equal(deleted.status(),303);removed=true;
  await page.goto(base+'/tabungan/masuk.php?student_id='+input.student_id);assert.equal(await page.locator('[data-savings-entry]').getAttribute('data-balance'),before,'Original balance restored');fs.writeFileSync(root+'/native-browser-results.json',JSON.stringify({ok:true,balanceRestored:true,checks:['native date/time widget','calendar in modal top layer','Escape retains editor','unchanged canonical preview','widget after AJAX detail replacement']},null,2));console.log('PASS native correction calendar, preview, AJAX replacement and restored clone balance');
 }finally{
  if(historyUrl&&!removed){await page.goto(historyUrl);const cleanup=await fields('[data-savings-editor=edit] form');cleanup.action='direct_hapus';cleanup.reason='Pemulihan fixture kalender setelah pengujian.';const response=await context.request.post(base+'/tabungan/koreksi.php',{form:cleanup,maxRedirects:0});assert.equal(response.status(),303,'Fixture cleanup');}
  await browser.close();
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
