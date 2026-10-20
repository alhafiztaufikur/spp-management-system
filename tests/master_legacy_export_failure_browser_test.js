/* Export failure is all-or-nothing, and Alumni edit/reset retains its scope. */
const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const root=process.env.SPP_MASTER_QA_DIR,f=JSON.parse(fs.readFileSync(path.join(root,'fixture.json')));
assert.equal(f.base,'http://127.0.0.1:58461');assert.equal(f.port,58460);
const checks=[],check=(ok,label)=>{assert(ok,label);checks.push(label);console.log('PASS '+label);};
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  const context=await browser.newContext({acceptDownloads:true,viewport:{width:1440,height:960}});
  await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  await context.route('**/*',r=>new URL(r.request().url()).origin===f.base?r.continue():r.abort());
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(f.base+'/siswa/daftar.php?edit='+f.units[1].student_id);
  check(await page.locator('#student-pomg').isDisabled(),'Alumni financial controls are disabled');
  check(await page.locator('#nama-baru').isEnabled(),'Alumni identity can be edited');
  check(await page.locator('[data-class-picker] [role=option]').count()===1,'Alumni class picker offers only the existing class');
  await Promise.all([page.waitForURL(url=>url.searchParams.get('reset_form')==='1'),page.locator('#student-reset-form').click()]);
  check(!new URL(page.url()).searchParams.has('edit')&&await page.locator('[data-class-picker] [role=option]').count()>1,'Reset leaves Alumni edit mode and restores normal class choices');
  await page.goto(f.base+'/laporan/surat_orang_tua_susun.php?preview=1&draft='+f.multi_draft);
  const downloads=[];page.on('download',d=>downloads.push(d));
  await page.evaluate(()=>{
   window.qaToBlob=HTMLCanvasElement.prototype.toBlob;let calls=0;
   HTMLCanvasElement.prototype.toBlob=function(callback,...args){if(++calls===2)callback(null);else window.qaToBlob.call(this,callback,...args);};
  });
  await page.locator('#parent-png').click();
  await page.waitForFunction(()=>document.querySelector('#parent-png-status').textContent.includes('Halaman PNG gagal'));
  check(downloads.length===0,'A failure after the first rendered page produces no partial download');
  await page.waitForFunction(()=>!document.querySelector('#parent-png').disabled);
  check(await page.locator('#parent-png').isEnabled(),'Failed export releases the button for retry');
  await page.evaluate(()=>{HTMLCanvasElement.prototype.toBlob=window.qaToBlob;});
  let pdfFetches=0;page.on('request',r=>{if(r.resourceType()==='fetch'&&r.url().includes('surat_orang_tua_pdf.php'))pdfFetches++;});
  const downloaded=page.waitForEvent('download',{timeout:120000});
  await page.locator('#parent-png').evaluate(button=>{button.click();button.click();});
  await downloaded;await page.waitForFunction(()=>!document.querySelector('#parent-png').disabled);
  check(downloads.length===1&&pdfFetches===1,'Double click launches one complete export and one PDF fetch');
  check(errors.length===0,'No browser errors during Alumni reset or export failure/retry');
  fs.writeFileSync(path.join(root,'export-failure-browser-results.json'),JSON.stringify({checks:checks.length,passed:checks,browser:await browser.version()},null,2));
  await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
