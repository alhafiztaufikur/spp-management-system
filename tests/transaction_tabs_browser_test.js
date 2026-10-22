/* Desktop presentation, HTML-first export, fresh PDF actions, and mobile preservation. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),cp=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const qa=process.env.SPP_GLOBAL_QA_DIR,f=JSON.parse(fs.readFileSync(path.join(qa,'fixture.json'))),models=JSON.parse(fs.readFileSync(path.join(qa,'payment-models.json')));
assert.equal(f.base,'http://127.0.0.1:58471');assert.equal(f.port,58470);
const checks=[],errors=[],check=(ok,label)=>{assert(ok,label);checks.push(label);};
const period='tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22&per_page=10';
function fixture(action){cp.execFileSync(process.env.SPP_PHP,[path.join(__dirname,'transaction_preview_cache_fixture.php'),action],{cwd:path.join(__dirname,'..'),env:{...process.env,SPP_TEST_ALLOW_MUTATION:'1',SPP_DB_HOST:'127.0.0.1',SPP_DB_PORT:'58470',SPP_DB_NAME:f.database},stdio:'pipe'});}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  const context=await browser.newContext({viewport:{width:1920,height:1080},reducedMotion:'reduce'});
  await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  const identity=await context.request.get(f.base+'/tests/browser_clone_identity.php');assert.equal((await identity.json()).database,f.database);
  await context.route('**/*',route=>new URL(route.request().url()).origin===f.base?route.continue():route.abort());
  const page=await context.newPage();page.setDefaultTimeout(30000);page.on('pageerror',error=>errors.push(error.message));
  for(const unit of [1,2,3,0]){
   await context.addCookies([{name:'PHPSESSID',value:f.sessions[unit],url:f.base}]);
   await page.goto(f.base+'/laporan/index.php?'+period+'&jenis_laporan[]=belum_spp');
   check(await page.locator('[data-finance-tab]').count()===3&&await page.locator('[data-report-section]').count()===1,'Three presentation tabs, one payment detail table');
   check(await page.locator('.finance-stat').count()===4&&await page.locator('[name=jenis_laporan],.report-unpaid-table').count()===0,'Four cards retained; no other report types');
   const sum=await page.locator('[data-component-key]').evaluateAll(rows=>rows.reduce((a,row)=>a+Number(row.dataset.cents),0));
   check(sum===models[unit].dated.cents,'Full component summary independent of ten-row page');
   for(const width of [1920,1440,1100])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:1080});await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t;},theme);
    await page.locator('[data-finance-tab=ringkasan]').click();
    const a=await page.locator('.finance-components').boundingBox(),b=await page.locator('.finance-transactions').boundingBox();
    check(a&&b&&a.x+a.width<=b.x+1,'Summary has component sidebar beside transactions');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Desktop has no page horizontal overflow');
    await page.locator('[data-finance-tab=komponen]').click();check(await page.locator('.finance-components').isVisible()&&!await page.locator('.finance-transactions').isVisible(),'Components tab uses full result area');
    await page.locator('[data-finance-tab=transaksi]').click();check(await page.locator('.finance-transactions').isVisible()&&!await page.locator('.finance-components').isVisible(),'Transactions tab uses full result area');
   }
   await page.setViewportSize({width:1920,height:1080});
   for(const panel of ['ringkasan','komponen','transaksi']){await page.locator('[data-finance-tab='+panel+']').click();await page.screenshot({path:path.join(qa,'artifacts',`tabs-${unit}-${panel}.png`),fullPage:true});}
  }
  await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  await page.goto(f.base+'/laporan/index.php?'+period+'&include_undated=1');
  await page.locator('[data-finance-tab=ringkasan]').focus();await page.keyboard.press('End');check(await page.locator('[data-finance-tab=transaksi]').getAttribute('aria-selected')==='true','Keyboard End selects transactions');
  await page.keyboard.press('Home');check(await page.locator('[data-finance-tab=ringkasan]').getAttribute('aria-selected')==='true','Keyboard Home selects summary');
  await page.keyboard.press('ArrowRight');check(await page.locator('[data-finance-tab=komponen]').getAttribute('aria-selected')==='true','Keyboard arrows select components');
  await page.locator('[data-finance-tab=transaksi]').click();const sumBefore=await page.locator('.finance-components-table').textContent();
  await page.locator('[data-report-section] a').filter({hasText:'Berikutnya'}).click();await page.waitForFunction(()=>document.querySelector('[data-report-section]').dataset.page==='2');
  check(new URL(page.url()).searchParams.get('panel')==='transaksi'&&new URL(page.url()).searchParams.get('include_undated')==='1','AJAX page keeps tab and filter');
  check(sumBefore===await page.locator('.finance-components-table').textContent(),'Pagination does not narrow component totals');
  await page.goBack();await page.waitForFunction(()=>document.querySelector('[data-report-section]').dataset.page==='1');
  await page.goBack();check(await page.locator('.finance-components').isVisible()&&!await page.locator('.finance-transactions').isVisible(),'Back restores components panel');
  await page.goForward();check(await page.locator('.finance-transactions').isVisible(),'Forward restores transactions panel');
  await page.reload();check(await page.locator('[data-finance-tab=transaksi]').getAttribute('aria-selected')==='true','Refresh retains presentation');
  check((await page.locator('.report-export-actions a').evaluateAll(links=>links.map(a=>new URL(a.href).searchParams.get('panel')))).every(value=>value==='transaksi'),'Both export back links retain the current presentation');
  await Promise.all([page.waitForNavigation(),page.locator('.report-general-filter').evaluate(form=>form.requestSubmit())]);check(new URL(page.url()).searchParams.get('panel')==='transaksi','Filter submit retains presentation');
  await Promise.all([page.waitForNavigation(),page.selectOption('select[name=unit]','all')]);check(new URL(page.url()).searchParams.get('panel')==='transaksi','Scope change retains presentation');
  await Promise.all([page.waitForNavigation(),page.locator('.sidebar-unit-form [data-unit-choice="1"]').click()]);
  await page.goto(f.base+'/laporan/index.php?'+period+'&panel[]=komponen');check(await page.locator('[data-finance-tab=ringkasan]').getAttribute('aria-selected')==='true','Malformed panel falls back to summary');
  await page.goto(f.base+'/laporan/index.php?'+period+'&panel=komponen');await page.setViewportSize({width:390,height:844});
  check(!await page.locator('.finance-tabs').isVisible()&&!await page.locator('.finance-components').isVisible()&&await page.locator('.finance-transactions').isVisible(),'Mobile keeps existing single transaction table regardless of desktop panel');
  check(await page.locator('.finance-results').getAttribute('role')===null,'Mobile does not expose desktop tab semantics');
  await page.setViewportSize({width:1920,height:1080});await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  // Hold the binary request: the real HTML table must be readable before it completes.
  let release,started=0;const gate=new Promise(resolve=>release=resolve);
  const hold=async route=>{if(new URL(route.request().url()).searchParams.get('output')==='inline'){started++;await gate;}await route.continue();};
  await page.route('**/laporan/export_pdf.php?**',hold);
  await page.goto(f.base+'/laporan/export_pdf.php?'+period+'&output=preview');await page.waitForURL(url=>url.searchParams.get('output')==='html');
  await page.frameLocator('[data-preview-frame]').locator('table tbody tr').first().waitFor();await page.waitForFunction(()=>document.querySelector('[data-preview-download]').getAttribute('aria-disabled')==='true');
  check(started===1&&await page.locator('[data-native-pdf]').count()===0,'HTML preview paints before one background PDF completes');
  check(await page.locator('[data-preview-frame]').evaluate(frame=>frame.hasAttribute('srcdoc')&&frame.clientWidth>innerWidth-80&&Number.parseFloat(frame.style.height)>innerHeight),'Full-width HTML preview uses page scrolling');
  await page.screenshot({path:path.join(qa,'artifacts','html-preview-before-pdf.png')});release();await page.waitForFunction(()=>document.querySelector('[data-preview-status]').textContent==='PDF siap.',null,{timeout:120000});await page.unroute('**/laporan/export_pdf.php?**',hold);
  const [download]=await Promise.all([page.waitForEvent('download'),page.locator('[data-preview-download]').click()]);await download.saveAs(path.join(qa,'artifacts','browser-download.pdf'));
  check(fs.readFileSync(path.join(qa,'artifacts','browser-download.pdf')).subarray(0,5).toString()==='%PDF-','Download button returns actual PDF');
  const [popup]=await Promise.all([page.waitForEvent('popup'),page.locator('[data-preview-print]').click()]);await popup.waitForURL('blob:**');check(popup.url().startsWith('blob:'),'Print opens the server PDF, not browser HTML');await popup.close();
  // A source edit after preloading must invalidate the PDF and update the HTML preview.
  await page.goto(f.base+'/laporan/export_pdf.php?'+period+'&output=html&student_id='+f.native[1].student_id);await page.waitForFunction(()=>document.querySelector('[data-preview-status]').textContent==='PDF siap.');
  const oldModel=await page.locator('body').getAttribute('data-model');
  try{fixture('change');const [fresh]=await Promise.all([page.waitForEvent('download'),page.locator('[data-preview-download]').click()]);await fresh.saveAs(path.join(qa,'artifacts','fresh-after-edit.pdf'));check(await page.locator('body').getAttribute('data-model')!==oldModel,'Fresh action invalidates old model and PDF');await page.frameLocator('[data-preview-frame]').locator('text=Rp 44.001,70').waitFor();}finally{fixture('restore');}
  let releaseOld,capturedOld,inlineCount=0;const oldGate=new Promise(resolve=>releaseOld=resolve),captured=new Promise(resolve=>capturedOld=resolve);
  const holdOld=async route=>{if(new URL(route.request().url()).searchParams.get('output')==='inline'&&++inlineCount===1){const response=await route.fetch();capturedOld();await oldGate;await route.fulfill({response});}else await route.continue();};
  await page.route('**/laporan/export_pdf.php?**',holdOld);
  await page.goto(f.base+'/laporan/export_pdf.php?'+period+'&output=html&student_id='+f.native[1].student_id);await captured;const pendingModel=await page.locator('body').getAttribute('data-model');
  try{
   fixture('change');const result=page.waitForEvent('download');await page.locator('[data-preview-download]').evaluate(link=>{link.click();link.click();});releaseOld();const fresh=await result;await fresh.saveAs(path.join(qa,'artifacts','fresh-during-preload.pdf'));
   check(inlineCount===2,'Double click during preload produces one fresh validation and one download');check(await page.locator('body').getAttribute('data-model')!==pendingModel,'An old in-flight preload cannot be downloaded after a source edit');
  }finally{releaseOld();fixture('restore');await page.unroute('**/laporan/export_pdf.php?**',holdOld);}
  await page.route('**/laporan/export_pdf.php?**',route=>new URL(route.request().url()).searchParams.get('output')==='inline'?route.fulfill({status:503,contentType:'text/plain',body:'QA unavailable'}):route.continue());
  await page.goto(f.base+'/laporan/export_pdf.php?'+period+'&output=html&student_id='+f.native[1].student_id);await page.locator('[data-preview-retry]').waitFor();
  check(await page.frameLocator('[data-preview-frame]').locator('table').isVisible(),'PDF preparation failure leaves HTML readable');
  await page.unroute('**/laporan/export_pdf.php?**');await page.locator('[data-preview-retry]').click();await page.waitForFunction(()=>document.querySelector('[data-preview-status]').textContent==='PDF siap.');
  await page.setViewportSize({width:390,height:844});await page.goto(f.base+'/laporan/export_pdf.php?'+period+'&output=preview&student_id='+f.native[1].student_id);
  check(await page.locator('[data-native-pdf]').count()===1&&new URL(page.url()).searchParams.get('output')==='preview','Mobile PDF preview retains native viewer');
  await context.close();
  const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:1440,height:1080}});await nojs.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);const fallback=await nojs.newPage();
  await fallback.goto(f.base+'/laporan/index.php?'+period+'&panel=komponen');check(await fallback.locator('.finance-components').isVisible()&&!await fallback.locator('.finance-transactions').isVisible(),'No-JS direct components tab works');
  await fallback.locator('[data-finance-tab=transaksi]').click();await fallback.locator('[data-report-section] a').filter({hasText:'Berikutnya'}).click();check(await fallback.locator('[data-report-section]').getAttribute('data-page')==='2'&&new URL(fallback.url()).searchParams.get('panel')==='transaksi','No-JS tab and page links preserve presentation');await nojs.close();
  check(errors.length===0,'No browser script errors');
 }finally{await browser.close();fs.writeFileSync(path.join(qa,'tabs-browser-'+(process.env.SPP_BROWSER_NAME||'chrome')+'.json'),JSON.stringify({checks:checks.length,errors},null,2));}
 console.log('OK: '+checks.length+' desktop tab/preview checks');
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
