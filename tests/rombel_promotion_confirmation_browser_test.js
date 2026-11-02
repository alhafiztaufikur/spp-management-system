const assert=require('node:assert/strict'),fs=require('node:fs');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core'),base=process.env.SPP_TEST_BASE_URL;
assert.match(base||'',/^http:\/\/(127\.0\.0\.1|localhost):\d+$/);
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),page=await browser.newPage({viewport:{width:1440,height:1080}}),errors=[],results=[];let sends=0;
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>{errors.push('Unexpected native confirmation');d.dismiss();});
 try{
  assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  await page.goto(base+'/master_kelas.php');if(await page.locator('#sidebar-unit-select').inputValue()!=='1')await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('1')]);
  await page.route('**/master_kelas.php*',async route=>{if(route.request().method()!=='POST')return route.continue();sends++;await route.fulfill({status:200,contentType:'text/html',body:'<p>Confirmation captured; no promotion was applied.</p>'});});
  for(const level of [1,6]){
   await page.goto(base+'/master_kelas.php?source_level='+level+'#naik-kelas');const form=page.locator('#promotion-batch-form'),row=form.locator('[data-promotion-student]').first();assert.ok(await row.count(),`Level ${level} must have a real roster`);
   await row.locator('[name="selected_students[]"]').check();
   const target=row.locator('select');if(await target.count()){const value=await target.locator('option').evaluateAll(rows=>rows.find(r=>r.value)?.value);assert.ok(value);await target.selectOption(value);}
   const prior=sends,dialog=page.locator('.workflow-dialog-operation');await page.locator('#promotion-submit-button').click();await dialog.waitFor();assert.match(await dialog.locator('h2').innerText(),level===6?/Kelulusan/:/Kenaikan Kelas/);assert.equal(await dialog.locator('li').count(),1);
   await dialog.getByRole('button',{name:'Batal',exact:true}).click();assert.equal(sends,prior);assert.equal(await row.locator('[name="selected_students[]"]').isChecked(),true);
   await page.locator('#promotion-submit-button').click();await dialog.waitFor();await page.evaluate(()=>document.querySelector('#promotion-batch-form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));assert.equal(await page.locator('.workflow-dialog[open]').count(),1);
   await Promise.all([page.waitForNavigation(),dialog.getByRole('button',{name:'Proses 1 Siswa',exact:true}).click()]);assert.equal(sends,prior+1);results.push({level,cancel:true,applicationDialog:true,onePost:true});
  }
  assert.deepEqual(errors,[]);if(process.env.SPP_UI_OUTPUT)fs.writeFileSync(process.env.SPP_UI_OUTPUT+'/rombel-promotion-confirmation.json',JSON.stringify(results,null,2));console.log('PASS: real promotion/graduation roster dialogs, cancellation, retained selection and one POST per confirmation (submission intercepted).');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
