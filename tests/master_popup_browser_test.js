const assert=require('node:assert/strict'),fs=require('node:fs');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core'),base=process.env.SPP_TEST_BASE_URL;
assert.match(base||'',/^http:\/\/(127\.0\.0\.1|localhost):\d+$/);
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),context=await browser.newContext({viewport:{width:1440,height:1080}}),page=await context.newPage(),errors=[],checks=[];
 page.setDefaultNavigationTimeout(120000);
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>{errors.push('Unexpected native confirmation');d.dismiss();});
 const result=page.locator('#operation-feedback-dialog'),workflow=page.locator('.workflow-dialog-operation');
 async function switchUnit(unit){if(await page.locator('#sidebar-unit-select').inputValue()!==String(unit))await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);}
 async function postDU(values={}){const form=page.locator('#du-rate-form'),payload=await form.evaluate(e=>Object.fromEntries(new FormData(e)));const response=await page.request.post(page.url(),{form:{...payload,...values},maxRedirects:0,timeout:120000});assert.ok([302,303].includes(response.status()));await page.goto(new URL(response.headers().location,page.url()).href);await result.waitFor({state:'visible'});}
 async function fits(locator){assert.equal(await locator.evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight&&e.scrollWidth<=e.clientWidth+2;}),true);}
 try{
  assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  for(const unit of [1,2,3]){
   await page.goto(base+'/master_daftar_ulang.php');await switchUnit(unit);await postDU();
   assert.ok(['success','warning'].includes(await result.getAttribute('data-type')));assert.equal(await result.locator('dl>div').count(),7);assert.equal(await result.locator('dd').allTextContents().then(rows=>rows.every(v=>/^\d+$/.test(v))),true);await result.getByRole('button',{name:'Mengerti',exact:true}).click();
   await postDU();assert.equal(await result.locator('dl>div').count(),7);assert.equal(await result.locator('dd').first().textContent(),'0');assert.equal(await result.locator('dd').nth(1).textContent(),'0');if(process.env.SPP_UI_OUTPUT&&unit===1)await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/du-popup-server-result.png'});await page.keyboard.press('Escape');assert.equal(await page.locator('#du-rate-submit').evaluate(e=>e===document.activeElement),true);
   const input=page.locator('#du-rate-form input.rupiah-input').first(),name=await input.getAttribute('name');await postDU({csrf_token:'expired',[name]:'123456'});assert.equal(await result.getAttribute('data-type'),'error');assert.equal(await result.locator('dl').isVisible(),false);await result.getByRole('button',{name:'Mengerti',exact:true}).click();assert.equal((await input.inputValue()).replace(/\D/g,''),'123456');checks.push({unit,serverResults:true,noOpZeros:true,errorDraft:true});
  }
  // Rich results are safe text, retain zeroes and fit every requested desktop size/theme.
  for(const width of [1280,1440,1920])for(const theme of ['light','dark'])for(const type of ['success','warning','error']){
   await page.setViewportSize({width,height:900});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
   await page.evaluate(type=>window.showOperationResult({type,message:'Hasil pengujian tampilan. '+('Pesan panjang tetap terbaca. '.repeat(12)),details:type==='error'?[]:Array.from({length:7},(_,i)=>({label:i===0?'<img src=x onerror=alert(1)>':'Rincian '+i,value:i===0?0:i*100})),source:document.querySelector('#du-rate-submit')}),type);
   await result.waitFor({state:'visible'});assert.equal(await result.locator('img').count(),0);await fits(result);await page.keyboard.press('Shift+Tab');assert.equal(await result.evaluate(e=>e.contains(document.activeElement)),true);
   if(process.env.SPP_UI_OUTPUT)await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/du-popup-'+width+'-'+theme+'-'+type+'.png'});await page.keyboard.press('Escape');
  }
  await page.goto(base+'/master_kelas.php?promotion_mode=regular&source_level=psb&show_participants=1#naik-kelas');await switchUnit(1);
  const selected=page.locator('#psb-placement-form input[name="selected_students[]"]');assert.ok(await selected.count()>1);await selected.nth(0).check();await selected.nth(1).check();
  const target=page.locator('[data-psb-search]').filter({has:page.locator('input[name="selected_students[]"]:checked')}).first().locator('[data-psb-target]');const choice=await target.locator('option').evaluateAll(rows=>rows.find(r=>r.value)?.value);await target.selectOption(choice);
  let sends=0,payload;
  await page.route('**/master_kelas.php*',async route=>{if(route.request().method()==='POST'){sends++;payload=new URLSearchParams(route.request().postData());await route.fulfill({status:200,contentType:'text/html',body:'<p>Confirmation payload captured; no placement was changed.</p>'});}else await route.continue();});
  await page.locator('#psb-submit').click();await workflow.waitFor();assert.equal(await workflow.locator('li').count(),2);assert.match(await workflow.locator('p').innerText(),/tanpa rombel tujuan akan dilewati/);await workflow.getByRole('button',{name:'Batal',exact:true}).click();assert.equal(sends,0);assert.equal(await selected.nth(0).isChecked(),true);
  await page.locator('#psb-submit').click();await workflow.waitFor();await page.keyboard.press('Escape');assert.equal(sends,0);
  for(const width of [1280,1440,1920])for(const theme of ['light','dark']){
   await page.setViewportSize({width,height:900});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);await page.locator('#psb-submit').click();await workflow.waitFor();await fits(workflow);await page.keyboard.press('Shift+Tab');assert.equal(await workflow.evaluate(e=>e.contains(document.activeElement)),true);if(process.env.SPP_UI_OUTPUT)await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/rombel-popup-'+width+'-'+theme+'.png'});await page.keyboard.press('Escape');
   // Exercise the same long-list confirmation configuration used by regular promotion/graduation.
   await page.evaluate(()=>{window.__confirmation=window.workflowConfirm({appearance:'operation',title:'Konfirmasi Kelulusan',message:'TA 2026/2027 → Alumni2027',rows:Array.from({length:150},(_,i)=>'Siswa '+i+' → Alumni2027'),action:'Proses 150 Siswa'},document.querySelector('#psb-submit'));});await workflow.waitFor();await fits(workflow);assert.equal(await workflow.locator('ul').evaluate(e=>e.scrollHeight>e.clientHeight),true);await page.keyboard.press('Escape');
  }
  // Double submit while confirmation is open must not create another dialog or POST.
  await page.locator('#psb-submit').click();await workflow.waitFor();await page.evaluate(()=>document.querySelector('#psb-placement-form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));assert.equal(await page.locator('.workflow-dialog[open]').count(),1);
  await Promise.all([page.waitForNavigation(),workflow.getByRole('button',{name:'Tempatkan Siswa',exact:true}).click()]);assert.equal(sends,1);assert.equal(payload.getAll('selected_students[]').length,2);checks.push({confirmation:true,cancel:true,escape:true,doubleClick:true,missingTargetPreserved:true,onePost:true});
  assert.deepEqual(errors,[]);if(process.env.SPP_UI_OUTPUT)fs.writeFileSync(process.env.SPP_UI_OUTPUT+'/master-popup-browser.json',JSON.stringify(checks,null,2));console.log('PASS: real DU outcomes/drafts, structured safe results, desktop light/dark layouts and PSB application confirmations with one POST.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
