/* Uses installed browsers and the separately restored, verified QA instance. */
const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const root=process.env.SPP_MASTER_QA_DIR,f=JSON.parse(fs.readFileSync(path.join(root,'fixture.json')));
assert.equal(f.base,'http://127.0.0.1:58461');assert.equal(f.port,58460);
const checks=[],errors=[];
const check=(ok,label)=>{assert(ok,label);checks.push(label);console.log('PASS '+label);};
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const unit of [0,1,2,3]){
   const context=await browser.newContext({acceptDownloads:true});
   await context.addCookies([{name:'PHPSESSID',value:f.sessions[unit],url:f.base}]);
   await context.route('**/*',route=>new URL(route.request().url()).origin===f.base?route.continue():route.abort());
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   const identity=await context.request.get(f.base+'/tests/browser_clone_identity.php');assert.equal((await identity.json()).database,f.database);
   for(const width of [1440,768,390])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:960});await page.goto(f.base+'/dashboard.php');
    await page.evaluate(theme=>{localStorage.setItem('spp_theme',theme);document.documentElement.dataset.theme=theme;},theme);
    check(await page.locator('.dashboard-breakdown-row').count()===3,`Unit ${unit}/${width}/${theme}: three closing cards`);
    const hero=await page.locator('.dashboard-total-value').innerText(),payment=await page.locator('.dashboard-mini-stat.is-payment strong').innerText();
    check(hero===payment,`Unit ${unit}/${width}/${theme}: hero shows school payments`);
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Unit ${unit}/${width}/${theme}: dashboard fits viewport`);
    await page.goto(f.base+'/master_kelas.php?special_q='+(unit===1?'QA%20Count53':'')+'&special_per_page=10#special-classes');
    check(await page.locator('.sc-managed').count()===0,`Unit ${unit}/${width}/${theme}: no managed-system text`);
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Unit ${unit}/${width}/${theme}: class workspace fits viewport`);
    if(unit===1){
     const menu=page.locator('.sc-row-menu').first(),summary=menu.locator('summary');
     await summary.click();check(await menu.getAttribute('open')!==null,`${width}/${theme}: action menu opens`);
     await summary.press('ArrowDown');check(await page.evaluate(()=>document.activeElement.textContent.includes('Edit')),`${width}/${theme}: action menu keyboard focus`);
     await page.keyboard.press('Escape');check(await menu.getAttribute('open')===null,`${width}/${theme}: Escape closes menu`);
     await summary.click();await page.screenshot({path:path.join(root,'artifacts',`classes-${width}-${theme}.png`),fullPage:false});await summary.press('Escape');
    }
   }
   if(unit===1){
    for(const key of ['single_draft','multi_draft']){
     await page.setViewportSize({width:1440,height:960});await page.goto(f.base+'/laporan/surat_orang_tua_susun.php?preview=1&draft='+f[key]);
     const button=page.locator('#parent-png');await button.waitFor();
     const downloaded=page.waitForEvent('download',{timeout:120000});await button.click();const download=await downloaded;
     const target=path.join(root,'artifacts',key+(key==='single_draft'?'.png':'.zip'));await download.saveAs(target);
     check(fs.statSync(target).size>10000,key+': PNG/ZIP download contains content');
     await page.waitForFunction(()=>document.querySelector('#parent-png')?.disabled===false);
     check((await page.locator('#parent-png-status').innerText()).includes('siap diunduh'),key+': export completes and button resets');
    }
    await page.route('**/surat_orang_tua_pdf.php?**',route=>route.fulfill({status:400,contentType:'text/html',body:'Draf kedaluwarsa'}));
    await page.locator('#parent-png').click();await page.waitForFunction(()=>document.querySelector('#parent-png-status').textContent.includes('Surat tidak tersedia'));
    check(await page.locator('#parent-png').isEnabled(),'Expired draft export reports failure and allows retry');
   }
   await context.close();
  }
  // Native menu/pagination works without any application script.
  const context=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:844}});await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  const page=await context.newPage();await page.goto(f.base+'/master_kelas.php?special_q=QA%20Count53&special_per_page=25#special-classes');
  await page.locator('.sc-row-menu summary').first().click();check(await page.locator('.sc-row-menu[open]').count()===1,'Native action menu works without JavaScript');
  await page.locator('.sc-page-links a[rel=next]').click();check(new URL(page.url()).searchParams.get('special_page')==='2','Pagination works without JavaScript');await context.close();
  check(errors.length===0,'No JavaScript errors in the tested workspaces');
  const browserName=path.basename(process.env.SPP_BROWSER_EXECUTABLE||'chrome.exe','.exe');
  fs.writeFileSync(path.join(root,'browser-'+browserName+'-results.json'),JSON.stringify({browser:await browser.version(),checks:checks.length,passed:checks,errors},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
