/* Actual settings POST/persistence/conflict tests, restricted to an owned isolated MySQL server. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = new URL(process.env.SPP_TEST_BASE_URL);
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
assert.ok(['127.0.0.1','localhost'].includes(base.hostname));
const manifest = JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE, 'utf8'));
assert.equal(manifest.isolated_test, true);
const artifacts = process.env.SPP_UI_ARTIFACTS;
assert.ok(path.isAbsolute(artifacts) && !path.resolve(artifacts).toLowerCase().startsWith(path.resolve('.').toLowerCase()+path.sep));
fs.mkdirSync(artifacts,{recursive:true});
(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try{
    const context=await browser.newContext({viewport:{width:1440,height:1000}});
    const page=await context.newPage(),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    const identity=await(await page.request.get(new URL('/tests/browser_clone_identity.php',base).href)).json();
    assert.equal(identity.isolated_test,true);assert.equal(identity.server_uuid,manifest.server_uuid);assert.equal(identity.port,manifest.port);
    await page.goto(new URL('/login.php',base).href);
    await page.locator('#username').fill('superadmin');
    await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());
    await page.locator('#btn-login').click();await page.waitForURL('**/dashboard.php');
    await page.goto(new URL('/backup_restore.php',base).href);
    const form=page.locator('#database-backup-settings-form');
    assert.equal(await form.getAttribute('method'),'post');
    const clock=page.locator('#backup-daily-time'),days=page.locator('#backup-retention-days'),button=page.locator('#backup-settings-save');
    let posts=0;page.on('request',request=>{if(request.method()==='POST'&&request.url().endsWith('/backup_api.php'))posts++;});
    await clock.fill('22:10');await days.fill('365');
    await form.evaluate(node=>{node.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));node.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));});
    try{await page.waitForFunction(()=>document.querySelector('#backup-settings-feedback').textContent.includes('berhasil disimpan'));}
    catch(error){console.error({posts,errors,details:await form.evaluate(node=>({hidden:node.hidden,feedback:document.querySelector('#backup-settings-feedback').textContent,controls:[...node.querySelectorAll('input:not([type=hidden]),button')].map(n=>({name:n.name,disabled:n.disabled,value:n.value,valid:n.validity?.valid,message:n.validationMessage}))}))});throw error;}
    assert.equal(posts,1,'Double submit duplicated save');
    assert.match(await page.locator('#backup-schedule-badge').textContent(),/22\.10 WIB/);
    await page.reload();assert.equal(await clock.inputValue(),'22:10');assert.equal(await days.inputValue(),'365');
    const oldVersion=await form.locator('[name=version]').inputValue();
    await clock.fill('00:05');
    const csrf=await form.locator('[name=csrf_token]').inputValue();
    const conflict=await page.request.post(new URL('/backup_api.php',base).href,{form:{action:'settings',csrf_token:csrf,daily_time:'23:59',retention_days:'1',version:oldVersion}});
    assert.equal(conflict.status(),200);
    const refreshed=page.waitForResponse(response=>response.url().includes('backup_api.php?action=status'));
    await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await refreshed;
    assert.equal(await clock.inputValue(),'00:05','Polling overwrote unsaved time');
    assert.equal(await form.locator('[name=version]').inputValue(),oldVersion,'Polling silently accepted another version');
    await button.click();await page.waitForFunction(()=>document.querySelector('#backup-settings-feedback').textContent.includes('Muat ulang'));
    await page.reload();assert.equal(await clock.inputValue(),'23:59');assert.equal(await days.inputValue(),'1');
    await days.fill('366');assert.equal(await days.evaluate(node=>node.validity.rangeOverflow),true);
    await days.fill('0');assert.equal(await days.evaluate(node=>node.validity.rangeUnderflow),true);
    await clock.fill('01:30');await days.fill('90');
    await page.route('**/backup_api.php',route=>route.request().method()==='POST'?route.abort('connectionreset'):route.continue(),{times:1});
    await button.click();await page.waitForFunction(()=>document.querySelector('#backup-settings-feedback').textContent.includes('Koneksi terputus'));
    assert.equal(await clock.inputValue(),'01:30');
    await button.click();await page.waitForFunction(()=>document.querySelector('#backup-settings-feedback').textContent.includes('berhasil disimpan'));
    const actual=await(await page.request.get(new URL('/backup_api.php?action=status',base).href)).json();
    assert.equal(actual.settings.daily_time,'01:30');assert.equal(actual.settings.retention_days,90);
    const expired={id:'fe'.repeat(16),name:'Cadangan lama.sql',type:'scheduled',state:'expired',created_at:new Date().toISOString(),bytes:100,actor_name:'Sistem',message:'Cadangan otomatis kedaluwarsa.'};
    await page.route('**/backup_api.php?action=status',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({...actual,jobs:[expired,...actual.jobs]})}));
    const statusResponse=page.waitForResponse(response=>response.url().includes('backup_api.php?action=status'));
    await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await statusResponse;
    await page.locator('.dbt-job-status.is-expired').first().waitFor();
    assert.equal(await page.locator('#backup-history-body tr').filter({hasText:'Cadangan lama.sql'}).locator('a').count(),0);
    await page.unroute('**/backup_api.php?action=status');
    for(const unit of [0,1,2,3]){
      await page.setViewportSize({width:1440,height:1000});
      await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
      for(const theme of ['light','dark'])for(const width of [1440,900,390]){
        await page.setViewportSize({width,height:width===390?844:1100});
        await page.addStyleTag({content:'*,*::before,*::after{transition:none!important;animation:none!important}'});
        await page.evaluate(theme=>{document.documentElement.setAttribute('data-theme',theme);localStorage.setItem('spp_theme',theme);},theme);
        const layout=await page.evaluate(()=>{
          const ids=['backup-daily-time','backup-retention-days','backup-settings-save'];
          const boxes=ids.map(id=>{const b=document.getElementById(id).getBoundingClientRect();return {x:b.x,y:b.y,width:b.width,height:b.height,right:b.right,bottom:b.bottom};});
          return {overflow:document.documentElement.scrollWidth>innerWidth+1,boxes};
        });
        assert.equal(layout.overflow,false,JSON.stringify({unit,theme,width,layout}));
        for(const box of layout.boxes)assert.ok(box.height>=44&&box.width>80&&box.right<=width+1);
        if(width===1440)assert.ok(Math.max(...layout.boxes.map(b=>b.bottom))-Math.min(...layout.boxes.map(b=>b.bottom))<1,'Controls misaligned');
        if(width!==900)await page.screenshot({path:path.join(artifacts,`settings_${unit}_${theme}_${width}.png`),fullPage:true});
      }
      assert.equal(await button.isEnabled(),true,'Scope blocked backup settings');
    }
    await clock.focus();assert.equal(await clock.evaluate(node=>node===document.activeElement),true);
    const native=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState(),viewport:{width:390,height:844}});
    const nativePage=await native.newPage();await nativePage.goto(new URL('/backup_restore.php',base).href);
    await nativePage.locator('#backup-daily-time').fill('02:45');await nativePage.locator('#backup-retention-days').fill('90');
    await Promise.all([nativePage.waitForNavigation(),nativePage.locator('#backup-settings-save').click()]);
    assert.match(await nativePage.locator('#backup-feedback').textContent(),/berhasil disimpan/);
    assert.equal(await nativePage.locator('#backup-daily-time').inputValue(),'02:45');
    assert.deepEqual(errors,[]);await native.close();await context.close();
    console.log('PASS: actual settings save/persistence/duplicate-submit/conflict/dirty polling/network retry/expired history/native POST, keyboard and 24 unit/theme/viewport layouts');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
