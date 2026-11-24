/* Missing letter drafts cannot download, and Alumni edit/reset retains its scope. */
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
  check(await page.locator('#parent-png,script[src*="parent_letter_png"]').count()===0,'Preview has no PNG export controls or script');
  const link=page.getByRole('link',{name:'Unduh PDF',exact:true});
  const url=new URL(await link.getAttribute('href'),page.url());
  const missing=new URL(url);
  missing.searchParams.set('draft','0'.repeat(32));
  const failed=await page.request.get(missing.href);
  check(!failed.ok()&&!failed.headers()['content-disposition'],'Missing draft does not produce a download');
  const correct=await page.request.get(url.href);
  check(correct.ok()&&(await correct.body()).subarray(0,4).toString()==='%PDF','The valid draft still downloads');
  check(errors.length===0,'No browser errors during Alumni reset or PDF preview');
  fs.writeFileSync(path.join(root,'export-failure-browser-results.json'),JSON.stringify({checks:checks.length,passed:checks,browser:await browser.version()},null,2));
  await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
