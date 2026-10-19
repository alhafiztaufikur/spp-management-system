/* Real pages with intercepted restore jobs; no restore application or physical printing. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_OPERATIONS_QA,base=process.env.SPP_TEST_BASE_URL;
assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));
assert.ok(['127.0.0.1','localhost'].includes(new URL(base).hostname));
const fixture=JSON.parse(fs.readFileSync(path.join(root,'ui-fixture.json'),'utf8'));
let checks=0;
(async()=>{
 for(const engine of (process.env.SPP_UI_BROWSERS||'chrome,msedge').split(',')){
  const launch={headless:true};if(engine==='chromium109')launch.executablePath=process.env.SPP_CHROMIUM109_EXECUTABLE;else launch.channel=engine;
  const browser=await pw.chromium.launch(launch);
  try{
   for(const role of ['admin','kasir']){
    const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.sessions[role].id,url:base}]);
    const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    let state='validating',finalized=true,offline=false;
    await page.route('**/restore_api.php*',route=>{
     assert.equal(route.request().method(),'GET','UI tests must not apply a restore');
     if(offline)return route.abort();
     return route.fulfill({json:{job:{id:'a'.repeat(32),name:'Cadangan Uji '+('Nama Panjang '.repeat(12))+'.sql',state,finalized,message:'Status uji '+state,expires_at:Math.floor(Date.now()/1000)+3600,finished_at:'2026-10-16T13:00:00+07:00'}}});
    });
    for(const theme of ['light','dark'])for(const width of [1440,768,390]){
     await page.setViewportSize({width,height:900});await page.goto(base+'/restore_status.php');await page.evaluate(theme=>{localStorage.setItem('spp_theme',theme);document.documentElement.dataset.theme=theme},theme);
     await page.locator('#restore-progress-panel').waitFor({state:'visible'});
     for(const next of ['validating','ready','restoring','auditing','completed','rolled_back','failed','recovery_required']){
      state=next;finalized=next!=='completed';await page.reload();await page.waitForFunction(s=>document.querySelector('#restore-validation-state').textContent===s,({validating:'Memeriksa SQL',ready:'Terverifikasi',restoring:'Memulihkan Database',auditing:'Memeriksa Hasil',completed:'Selesai',rolled_back:'Backup Pengaman Dipulihkan',failed:'Gagal',recovery_required:'Pemulihan Pengaman Tertunda'})[next]);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Horizontal overflow');
      assert.equal(await page.locator('#restore-login').isVisible(),next==='rolled_back');
      if(next==='ready'){assert.equal(await page.locator('#restore-apply').isDisabled(),true);await page.locator('#restore-understood').check();await page.locator('#restore-confirmation').fill('PULIHKAN DATABASE');assert.equal(await page.locator('#restore-apply').isEnabled(),true);}
      if(next==='completed')assert.equal(await page.locator('#restore-result').isVisible(),false,'Premature completion');
      checks++;
     }
     state='completed';finalized=true;await page.reload();await page.locator('#restore-result').waitFor({state:'visible'});assert.equal(await page.locator('#restore-login').isVisible(),true);
     if(role==='admin'&&width!==768)await page.screenshot({path:path.join(root,`progress-${engine}-${theme}-${width}.png`),fullPage:true});
    }
    for(const unit of [1,2,3,0]){
     await context.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:fixture.sessions[role].csrf,next:'restore_status.php'}});
     for(const theme of ['light','dark'])for(const width of [390,1440]){
      await page.setViewportSize({width,height:900});await page.goto(base+'/restore_status.php');await page.evaluate(theme=>{localStorage.setItem('spp_theme',theme);document.documentElement.dataset.theme=theme},theme);
      await page.locator('#restore-result').waitFor({state:'visible'});
      assert.equal(await page.locator('html').getAttribute('data-palette'),({0:'super',1:'sd',2:'smp',3:'sma'})[unit]);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      if(role==='admin')await page.screenshot({path:path.join(root,`progress-${engine}-${unit}-${theme}-${width}.png`),fullPage:true});checks++;
     }
    }
    state='validating';offline=true;await page.reload();await page.locator('#restore-connection').waitFor({state:'visible'});offline=false;await page.waitForFunction(()=>document.querySelector('#restore-connection').hidden,{},{timeout:12000});checks++;
    assert.deepEqual(errors,[]);await context.close();
   }
   const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.sessions.super_admin.id,url:base}]);const page=await context.newPage();
   await page.goto(base+'/role_management.php');
   for(const width of [1440,768,390]){
    await page.setViewportSize({width,height:900});
    await page.locator('#role').evaluate(s=>{s.value='super_admin';s.dispatchEvent(new Event('change',{bubbles:true}))});
    assert.deepEqual(await page.locator('#account-unit').evaluate(s=>({value:s.value,enabled:[...s.options].filter(o=>!o.disabled).map(o=>o.value)})),{value:'0',enabled:['0']});
    await page.locator('#account-unit + button').click();assert.equal(await page.locator('.spp-dropdown-option:visible:not(:disabled)').count(),1);await page.keyboard.press('End');await page.keyboard.press('Enter');assert.equal(await page.locator('#account-unit').inputValue(),'0');
    await page.locator('#role').evaluate(s=>{s.value='admin';s.dispatchEvent(new Event('change',{bubbles:true}))});
    assert.equal(await page.locator('#account-unit').evaluate(s=>s.querySelector('option[value="0"]').disabled),true);
    await page.locator('#account-unit').evaluate(s=>{s.value='2';s.dispatchEvent(new Event('change',{bubbles:true}))});
    await page.locator('#role').evaluate(s=>{s.value='super_admin';s.dispatchEvent(new Event('change',{bubbles:true}));s.value='kasir';s.dispatchEvent(new Event('change',{bubbles:true}))});assert.equal(await page.locator('#account-unit').inputValue(),'2');checks+=4;
   }
   await context.close();console.log('PASS '+engine+': restore states/themes/responsive/reconnect and locked account unit');
  }finally{await browser.close()}
 }
 console.log('PASS '+checks+' UI state checks');
})().catch(e=>{console.error(e);process.exit(1)});
