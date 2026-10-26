/* Existing report search and receipt evidence on an isolated local HTTP clone. */
const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const root=process.env.SPP_GLOBAL_QA_DIR;
const fixture=JSON.parse(fs.readFileSync(path.join(root,'fixture.json')));
const manifest=JSON.parse(fs.readFileSync(path.join(root,'isolated-instance.json')));
assert.equal(fixture.base,'http://127.0.0.1:58471');assert.equal(manifest.port,58470);
const checks=[],errors=[];
function check(ok,label){assert(ok,label);checks.push(label);}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  const context=await browser.newContext();
  await context.addCookies([{name:'PHPSESSID',value:fixture.sessions['1'],url:fixture.base}]);
  const identity=await context.request.get(fixture.base+'/tests/browser_clone_identity.php');
  assert.equal((await identity.json()).server_uuid,manifest.server_uuid);
  await context.route('**/*',route=>new URL(route.request().url()).origin===fixture.base?route.continue():route.abort());
  const page=await context.newPage();page.setDefaultNavigationTimeout(180000);page.on('pageerror',error=>errors.push(error.message));
  for(const width of [1440,390])for(const theme of ['light','dark']){
   await page.setViewportSize({width,height:1000});
   const params=new URLSearchParams({template:'per-item',unit:'1',kategori:'biaya_lain:20',tanggal_awal:'2026-10-01',tanggal_akhir:'2026-10-26',q:'252601095'});
   await page.goto(fixture.base+'/laporan/template.php?'+params);
   await page.evaluate(value=>{localStorage.setItem('spp_theme',value);document.documentElement.dataset.theme=value;},theme);
   let row=page.locator('tbody tr').filter({hasText:'252601095'}).first();
   check(await row.count()===1&&await row.innerText().then(text=>text.includes('450.000')&&text.includes('Ada Pembayaran')),`${width}/${theme}: the recap tracks the source payment`);
   check(await row.innerText().then(text=>!text.includes('Belum Diketahui')),`${width}/${theme}: recorded payment is not labelled unknown`);
   check(await page.locator('.report-export-btn').count()===3,`${width}/${theme}: existing export actions are retained`);
   await page.locator('#global-siswa-search').fill('Aqila Areta Azzahra');
   await Promise.all([page.waitForNavigation(),page.locator('form.report-global-filter-form button[type=submit]').click()]);
   row=page.locator('tbody tr').filter({hasText:'252601095'}).first();
   check(await row.innerText().then(text=>text.includes('450.000')),`${width}/${theme}: existing name search finds Legacy fee evidence`);
   params.set('tanggal_awal','2026-10-26');params.set('tanggal_akhir','2026-10-26');
   await page.goto(fixture.base+'/laporan/template.php?'+params);
   row=page.locator('tbody tr').filter({hasText:'252601095'}).first();
   check(await row.innerText().then(text=>text.includes('Ada Pembayaran di Luar Periode')&&!text.includes('450.000')),`${width}/${theme}: date scope preserves evidence without extra cash`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${width}/${theme}: existing layout fits viewport`);
   await page.screenshot({path:path.join(root,'artifacts',`fee-${width}-${theme}.png`),fullPage:false,animations:'disabled'});
  }
  check(errors.length===0,'No JavaScript errors in the existing report controls');
  await context.close();
 }finally{await browser.close();}
 fs.writeFileSync(path.join(root,'fee-browser-results.json'),JSON.stringify({checks:checks.length,passed:checks,errors},null,2));
 console.log(`PASS ${checks.length} fee report browser checks.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
