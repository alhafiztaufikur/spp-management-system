const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const file=process.argv[2],fixture=JSON.parse(fs.readFileSync(file,'utf8')),root=path.dirname(file),results=[],errors=[];
 assert((await fetch(fixture.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test);
 for(const exe of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
  const browser=await chromium.launch({headless:true,executablePath:exe,env:{...process.env,TEMP:root+'/browser-temp',TMP:root+'/browser-temp'}});
  try{for(const js of [true,false])for(const theme of ['light','dark'])for(const palette of ['sd','smp','sma']){
   const context=await browser.newContext({javaScriptEnabled:js});try{
    await context.addCookies([{name:'PHPSESSID',value:fixture.session,url:fixture.base}]);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
    for(const width of [360,768,1366]){
     await page.setViewportSize({width,height:720});await page.goto(fixture.base+'/legacy_operational_review.php?'+new URLSearchParams({id:fixture.job,section:'savings',ledger:fixture.ledger}));
     await page.evaluate(({theme,palette})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.palette=palette},{theme,palette});
     const button=page.getByRole('button',{name:'Setujui Tabungan yang Cocok',exact:true});await button.focus();await page.keyboard.press('Enter');
     await page.getByRole('heading',{name:'Sahkan Tabungan yang Cocok?'}).waitFor();
     const panel=page.locator('#full-confirm');assert(!(await panel.innerText()).includes('Kelompok\n0'),'Unrelated zero counters');
     assert(await panel.locator('.lop-replacement-table tbody tr').count()>=10,'Long replacement fixture absent');
     assert(!/Fatal error|Warning:|Notice:/.test(await page.locator('body').innerText()));
     assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Page overflow');
     if(js){
      const dialog=page.locator('dialog[open]'),box=await dialog.boundingBox();assert(box.x>=0&&box.y>=0&&box.x+box.width<=width+2&&box.height<=722,'Dialog outside viewport');
      const body=panel.locator('.lful-modal-body'),footer=panel.locator('.lful-modal-footer');const before=await footer.boundingBox();await body.evaluate(el=>el.scrollTop=el.scrollHeight);const after=await footer.boundingBox();assert(Math.abs(before.y-after.y)<2,'Footer scrolls away');
      assert(after.y+after.height<=722,'Footer clipped');assert(await panel.getByRole('button',{name:'Setujui & Simulasikan'}).isVisible());
      if(browser.version().startsWith('109.')&&palette==='sd'&&[360,1366].includes(width))await page.screenshot({path:path.join(root,'modal-'+theme+'-'+width+'.png')});
      await page.keyboard.press('Escape');await page.waitForURL(u=>!u.searchParams.has('scope'));
     }else{await panel.getByRole('link',{name:'Kembali Mengubah',exact:true}).click();}
     results.push({browser:browser.version(),width,js,theme,palette});
    }
    if(js&&palette==='sd'){
     for(const zoom of [0.8,2]){await page.setViewportSize({width:1024,height:600});await page.goto(fixture.base+'/legacy_operational_review.php?'+new URLSearchParams({id:fixture.job,section:'savings'}));const cdp=await context.newCDPSession(page);await cdp.send('Emulation.setDeviceMetricsOverride',{width:Math.round(1024/zoom),height:Math.round(600/zoom),deviceScaleFactor:zoom,mobile:false});await page.getByRole('button',{name:'Setujui Tabungan yang Cocok',exact:true}).focus();await page.keyboard.press('Enter');await page.locator('dialog[open]').waitFor();const bounds=await page.locator('dialog[open]').boundingBox();assert(bounds.x>=-2&&bounds.y>=-2&&bounds.x+bounds.width<=Math.round(1024/zoom)+2&&bounds.y+bounds.height<=Math.round(600/zoom)+2,'Zoom viewport clipped');assert(await page.locator('#full-confirm .lful-modal-footer button').isVisible());await page.keyboard.press('Escape');await cdp.detach();results.push({browser:browser.version(),zoom,theme});}
    }
    await page.goto(fixture.base+'/legacy_history.php?'+new URLSearchParams({id:fixture.job,date_from:'2019-01-01',date_to:'2019-12-31'}));assert((await page.locator('h2').allTextContents()).some(t=>t.includes('1 catatan')),'Filtered history missing');
   }finally{await context.close();}
  }}finally{await browser.close();}
 }
 assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(root,'modal-browser-results.json'),JSON.stringify(results,null,2));console.log('PASS '+results.length+' viewport/theme/palette/JavaScript/zoom cases; modal scroll/footer, Escape, long changes and history.');
})().catch(e=>{console.error(e);process.exit(1)});
