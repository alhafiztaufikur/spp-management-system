// Real upload/preflight and UI only. This test never clicks Apply or replaces application data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = new URL(process.env.SPP_TEST_BASE_URL.replace(/\/?$/, '/'));
assert.ok(['localhost','127.0.0.1'].includes(base.hostname));
const root = process.env.SPP_RESTORE_UI_ARTIFACTS;
assert.ok(root && !path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));
const session = JSON.parse(fs.readFileSync(path.join(root,'production-session.json'),'utf8').replace(/^\uFEFF/,''));
const sql = JSON.parse(fs.readFileSync(path.join(root,'production-ui.json'),'utf8').replace(/^\uFEFF/,''));
(async () => {
  const browser = await chromium.launch({channel:'chrome',headless:true});
  try {
    const context = await browser.newContext({viewport:{width:1440,height:1000}});
    await context.addCookies([{name:'PHPSESSID',value:session.id,url:base.href}]);
    const page = await context.newPage();const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(new URL('backup_restore.php',base).href);
    const previous = await page.locator('#database-restore-config').evaluate(node => JSON.parse(node.textContent));
    if(previous.job){
      const prior=await context.request.get(new URL(`restore_api.php?action=status&id=${previous.job.id}`,base).href,{headers:{'X-Restore-Token':previous.job.token}});
      if((await prior.json()).job?.state==='ready')await context.request.post(new URL('restore_api.php',base).href,{form:{action:'cancel',csrf_token:previous.csrf,id:previous.job.id,token:previous.job.token}});
      await page.reload();
    }
    await page.locator('#restore-file').setInputFiles(sql.sql);
    const submitted = page.waitForResponse(response => response.url().endsWith('/restore_api.php') && response.request().method() === 'POST');
    const started = Date.now();await page.locator('#restore-review').click();
    const response = await submitted;assert.equal(response.status(),202);
    const accepted = await response.json();const elapsed = Date.now()-started;
    await page.waitForFunction(() => document.getElementById('restore-validation-state').textContent === 'Terverifikasi',{},{timeout:180000});
    assert.equal(await page.locator('#restore-apply').isDisabled(),true);
    await page.locator('#restore-understood').check();
    await page.locator('#restore-confirmation').fill('pulihkan database');assert.equal(await page.locator('#restore-apply').isDisabled(),true);
    await page.locator('#restore-confirmation').fill('PULIHKAN DATABASE');assert.equal(await page.locator('#restore-apply').isEnabled(),true);
    assert.equal(await page.locator('#restore-resume').isVisible(),false);
    assert.equal(await page.locator('#restore-login').isVisible(),false);
    await page.keyboard.press('Escape');assert.equal(await page.locator('#restore-review').evaluate(node => node === document.activeElement),true);
    for(const unit of [0,1,2,3]) {
      await page.setViewportSize({width:1440,height:1000});
      await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
      await page.waitForFunction(() => document.getElementById('restore-validation-state').textContent === 'Terverifikasi');
      await page.addStyleTag({content:'*,*::before,*::after{transition:none!important;animation:none!important}'});
      await page.locator('#restore-dialog').evaluate(node => node.showModal());
      for(const theme of ['light','dark']) for(const width of [1440,900,390]) {
        await page.setViewportSize({width,height:width===390?844:1000});
        await page.evaluate(theme => document.documentElement.setAttribute('data-theme',theme),theme);
        const layout = await page.locator('#restore-dialog').evaluate(node => {
          const box=node.getBoundingClientRect();return {left:box.left,right:box.right,top:box.top,bottom:box.bottom,width:innerWidth,height:innerHeight,scroll:document.documentElement.scrollWidth};
        });
        assert.ok(layout.left>=0 && layout.right<=layout.width+1 && layout.top>=0 && layout.bottom<=layout.height+1,JSON.stringify(layout));
        assert.ok(Math.abs(layout.left-(layout.width-layout.right))<2,'Restore modal is not centered');
        assert.ok(layout.scroll<=layout.width+1,'Restore page overflows horizontally');
        await page.screenshot({path:path.join(root,`restore_${unit}_${theme}_${width}.png`)});
      }
      await page.keyboard.press('Escape');
    }
    const progressPage=await context.newPage();
    await progressPage.goto(new URL('restore_status.php',base).href);
    await progressPage.waitForFunction(()=>document.getElementById('restore-validation-state').textContent==='Terverifikasi');
    assert.equal(await progressPage.locator('#restore-progress-panel').isVisible(),true);
    assert.equal(await progressPage.locator('#restore-progress-bar').getAttribute('value'),'1');
    assert.equal(await progressPage.locator('#restore-confirm-fields').isVisible(),true);
    await progressPage.screenshot({path:path.join(root,'restore-standalone-ready.png')});
    await progressPage.close();
    const config = await page.locator('#database-restore-config').evaluate(node => JSON.parse(node.textContent));
    const cancelled = await context.request.post(new URL('restore_api.php',base).href,{form:{action:'cancel',csrf_token:config.csrf,id:accepted.job.id,token:accepted.token}});
    assert.equal(cancelled.status(),200);assert.equal((await cancelled.json()).job.state,'cancelled');
    assert.deepEqual(errors,[]);
    fs.writeFileSync(path.join(root,'production-ui-result.json'),JSON.stringify({id:accepted.job.id,accepted_ms:elapsed,validation:'ready',confirmation_button:'enabled',final:'cancelled',layouts:24,application_restore_executed:false},null,2));
    console.log(`PASS: real SQL upload/preflight accepted in ${elapsed} ms, confirmation guards, keyboard, 24 unit/theme/viewport layouts; cancelled without replacing application data`);
    await context.close();
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode=1;});
