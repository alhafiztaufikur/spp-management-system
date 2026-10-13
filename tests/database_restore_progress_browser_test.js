// Exercise presentation and completion states without executing a restore or a scheduler command.
const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base=new URL(process.env.SPP_TEST_BASE_URL.replace(/\/?$/,'/'));
assert.ok(['localhost','127.0.0.1'].includes(base.hostname));
const root=process.env.SPP_RESTORE_UI_ARTIFACTS;
assert.ok(root && !path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));
const session=JSON.parse(fs.readFileSync(path.join(root,'production-session.json'),'utf8'));
(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try{
    const context=await browser.newContext({viewport:{width:1440,height:1000}});
    await context.addCookies([{name:'PHPSESSID',value:session.id,url:base.href}]);
    const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/backup_restore.php',async route=>{
      const response=await route.fetch();let html=await response.text();
      html=html.replace(/(<script type="application\/json" id="database-restore-config">)([\s\S]*?)(<\/script>)/,(_,open,json,close)=>{
        const config=JSON.parse(json);config.job=null;return open+JSON.stringify(config)+close;
      });
      return route.fulfill({response,body:html});
    });
    let state='validating',finalized=true,applyCalls=0,backupSummary=null;
    const job=()=>({id:'a'.repeat(32),name:'Cadangan <img onerror=alert(1)>.sql',bytes:1024,state,finalized,message:'Tahap '+state,expires_at:Math.floor(Date.now()/1000)+3600,finished_at:['completed','rolled_back'].includes(state)?'2026-10-13T15:15:00+07:00':null});
    await page.route('**/restore_api.php*',route=>{
      if(route.request().method()==='GET')return route.fulfill({json:{job:job()}});
      const body=route.request().postData() || '';
      if(body.includes('name="action"\r\n\r\napply')){applyCalls++;state='queued';return route.fulfill({status:202,json:{job:job()}});}
      return route.fulfill({status:202,json:{job:job(),token:'b'.repeat(64)}});
    });
    await page.route('**/backup_api.php?action=status',async route=>{
      if(!backupSummary)return route.continue();
      return route.fulfill({json:backupSummary});
    });
    await page.goto(new URL('backup_restore.php',base).href);
    await page.addStyleTag({content:'*,*::before,*::after{transition:none!important;animation:none!important}'});
    backupSummary=await page.locator('#database-backup-config').evaluate(node=>JSON.parse(node.textContent).summary);
    assert.equal(await page.locator('#backup-alerts').textContent(),'');
    assert.match(await page.locator('#backup-scheduler-label').textContent(),/belum aktif/);
    await page.locator('#backup-scheduler-help summary').click();
    const command=await page.locator('#backup-scheduler-command').textContent();
    assert.match(command,/-ConfirmMain -RegisterTask$/);assert.ok(!command.includes('-RunNow'));
    await context.grantPermissions(['clipboard-read','clipboard-write']);
    await page.locator('#backup-scheduler-copy').click();
    await page.waitForFunction(()=>document.getElementById('backup-scheduler-copy-status').textContent==='Perintah disalin.');
    assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),command);
    await page.locator('#backup-scheduler-help summary').click();
    await page.locator('#restore-file').setInputFiles({name:'cadangan.sql',mimeType:'application/sql',buffer:Buffer.alloc(256,65)});
    await page.locator('#restore-review').click();
    await page.waitForFunction(()=>document.getElementById('restore-validation-state').textContent==='Memeriksa SQL');
    assert.equal(await page.locator('#restore-confirm-fields').isVisible(),false);
    assert.equal(await page.locator('#restore-progress-bar').getAttribute('value'),null);
    assert.equal(await page.locator('#restore-dialog-file img').count(),0);
    assert.ok(await page.locator('#restore-dialog').evaluate(node=>node.getBoundingClientRect().height)<500,'Validation modal is too tall');
    state='ready';await page.waitForFunction(()=>document.getElementById('restore-confirm-fields').hidden===false);
    assert.equal(await page.locator('#restore-progress-bar').getAttribute('value'),'1');
    assert.equal(await page.locator('#restore-apply').isDisabled(),true);
    await page.locator('#restore-understood').check();await page.locator('#restore-confirmation').fill('PULIHKAN DATABASE');
    assert.equal(await page.locator('#restore-apply').isEnabled(),true);
    assert.ok(await page.locator('#restore-dialog').evaluate(node=>node.getBoundingClientRect().height)<680,'Confirmation modal is too tall');
    for(const theme of ['light','dark'])for(const width of [1440,900,390]){
      await page.setViewportSize({width,height:width===390?844:1000});await page.evaluate(theme=>document.documentElement.setAttribute('data-theme',theme),theme);
      const layout=await page.locator('#restore-dialog').evaluate(node=>{const b=node.getBoundingClientRect();return {left:b.left,right:b.right,top:b.top,bottom:b.bottom,width:innerWidth,height:innerHeight};});
      assert.ok(layout.left>=0&&layout.right<=layout.width+1&&layout.top>=0&&layout.bottom<=layout.height+1);
      await page.screenshot({path:path.join(root,`compact-restore-${theme}-${width}.png`)});
    }
    await page.locator('#restore-apply').click();assert.equal(applyCalls,1);
    assert.equal(await page.locator('#backup-create').isDisabled(),true);
    assert.equal(await page.locator('#backup-settings-save').isDisabled(),true);
    for(const [stage,value,label] of [['safety_backup','1','Backup pengaman'],['restoring','2','Pulihkan data'],['auditing','3','Verifikasi hasil']]){
      state=stage;await page.waitForFunction(stage=>document.getElementById('restore-validation-state').textContent===stage,label==='Backup pengaman'?'Backup Pengaman':label==='Pulihkan data'?'Memulihkan Database':'Memeriksa Hasil');
      assert.equal(await page.locator('#restore-progress-bar').getAttribute('value'),value);
      assert.equal(await page.locator('#restore-confirm-fields').isVisible(),false);
    }
    await page.keyboard.press('Escape');state='completed';finalized=false;
    await page.waitForFunction(()=>document.getElementById('restore-stage-label').textContent==='Menyelesaikan pemulihan');
    assert.equal(await page.locator('#restore-result').isVisible(),false);
    finalized=true;await page.waitForFunction(()=>document.getElementById('restore-result-title').textContent==='Pemulihan berhasil selesai');
    assert.equal(await page.locator('#restore-dialog').evaluate(node=>node.open),true);
    assert.equal(await page.locator('#restore-progress-bar').getAttribute('value'),'4');
    assert.equal(await page.locator('#restore-login').isVisible(),true);
    assert.match(await page.locator('#restore-result-time').textContent(),/15\.15|15:15/);
    assert.match(await page.title(),/Pemulihan selesai/);
    await page.keyboard.press('Escape');await page.waitForTimeout(2500);assert.equal(await page.locator('#restore-dialog').evaluate(node=>node.open),false,'Finished modal repeatedly reopens');
    // Recreate a page load with the same capability, as the standalone progress screen does.
    await page.goto(new URL('backup_restore.php',base).href);
    state='rolled_back';await page.locator('#restore-file').setInputFiles({name:'cadangan.sql',mimeType:'application/sql',buffer:Buffer.alloc(256,65)});await page.locator('#restore-review').click();
    await page.waitForFunction(()=>document.getElementById('restore-result-title').textContent==='Backup pengaman berhasil dikembalikan');
    assert.equal(await page.locator('#restore-result').getAttribute('data-kind'),'warning');
    assert.equal(await page.locator('#restore-confirm-fields').isVisible(),false);await page.keyboard.press('Escape');
    backupSummary={...backupSummary,service_registered:true,ready:true,alerts:[]};
    await page.evaluate(()=>{document.dispatchEvent(new CustomEvent('database-restore-active',{detail:false}));document.dispatchEvent(new Event('visibilitychange'));});
    await page.waitForFunction(()=>document.getElementById('backup-scheduler-label').textContent==='Backup otomatis aktif',{},{timeout:20000});
    assert.equal(await page.locator('#backup-scheduler-help').isVisible(),false);
    assert.deepEqual(errors,[]);assert.equal(applyCalls,1);
    console.log('PASS: compact modal, six layouts, accessible stages, finalization guard, one-time completion, rollback result, scheduler guidance/copy and live-ready status; all operation responses mocked');
    await context.close();
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
