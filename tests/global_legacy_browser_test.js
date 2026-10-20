/* Installed-browser QA. Session cookies and screenshots stay outside the repository. */
const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const root=process.env.SPP_GLOBAL_QA_DIR,f=JSON.parse(fs.readFileSync(path.join(root,'fixture.json')));
assert.equal(f.base,'http://127.0.0.1:58471');assert.equal(f.port,58470);
const checks=[],errors=[],check=(ok,label)=>{assert(ok,label);checks.push(label);console.log('PASS '+label);};
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1080}});await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  const identity=await context.request.get(f.base+'/tests/browser_clone_identity.php');assert.equal((await identity.json()).database,f.database);
  await context.route('**/*',r=>new URL(r.request().url()).origin===f.base?r.continue():r.abort());
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  const units=process.env.SPP_GLOBAL_BROWSER_SMOKE==='1'?[1]:[1,2,3,0];
  for(const unit of units)for(const width of [1440,768,390])for(const theme of ['light','dark']){
   await page.setViewportSize({width,height:1080});
   const params=new URLSearchParams({template:'per-item',unit:unit?String(unit):'all',kategori:'spp',bulan_awal:'10',bulan_akhir:'10',tahun_awal:'2026',tahun_akhir:'2026',siswa_status:'all'});
   await page.goto(f.base+'/laporan/template.php?'+params);await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t;},theme);
   check(!await page.locator('body').innerText().then(s=>s.includes('Gagal memuat laporan')),`${unit}/${width}/${theme}: report is available`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${unit}/${width}/${theme}: workspace fits viewport`);
   check(await page.locator('.matrix-group-period').count()===0,`${unit}/${width}/${theme}: table headers have no repeated explanatory labels`);
   const month=page.locator('.report-col-month .matrix-main').first();if(await month.count())check(await month.evaluate(el=>getComputedStyle(el).backgroundColor==='rgba(0, 0, 0, 0)'&&getComputedStyle(el).borderRadius==='0px'),`${unit}/${width}/${theme}: monthly status is clean text`);
   params.set('template','penerimaan');params.set('kategori','semua');params.set('tanggal_awal','2026-10-01');params.set('tanggal_akhir','2026-10-21');
   await page.goto(f.base+'/laporan/template.php?'+params);
   const box=page.locator('input[name=include_undated]');check(await box.isVisible()&&!await box.isChecked(),`${unit}/${width}/${theme}: checkbox is visible and off by default`);
   await box.focus();await page.keyboard.press('Space');check(await box.isChecked(),`${unit}/${width}/${theme}: checkbox supports keyboard activation`);
   check(await page.locator('.report-undated-field').evaluate(el=>el.getBoundingClientRect().right<=innerWidth),`${unit}/${width}/${theme}: checkbox fits the filter panel`);
   const form=box.locator('xpath=ancestor::form');await Promise.all([page.waitForNavigation(),form.locator('button[type=submit]').click()]);
   check(new URL(page.url()).searchParams.get('include_undated')==='1'&&await page.locator('input[name=include_undated]').isChecked(),`${unit}/${width}/${theme}: apply keeps the checkbox state`);
   check(await page.locator('.report-export-btn').evaluateAll(links=>links.every(a=>a.href.includes('include_undated=1'))),`${unit}/${width}/${theme}: print/PDF/Excel share the applied filter`);
   if(unit===1&&[1440,390].includes(width))await page.screenshot({path:path.join(root,'artifacts',`global-${width}-${theme}.png`),fullPage:false,animations:'disabled'});
  }
  await context.close();
  const native=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:900}});await native.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  const page2=await native.newPage();await page2.goto(f.base+'/laporan/template.php?template=penerimaan&unit=1&kategori=semua&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-21');
  const plainBox=page2.locator('input[name=include_undated]');await plainBox.focus();await page2.keyboard.press('Space');
  await plainBox.locator('xpath=ancestor::form').locator('button[type=submit]').focus();await Promise.all([page2.waitForNavigation(),page2.keyboard.press('Enter')]);
  check(new URL(page2.url()).searchParams.get('include_undated')==='1','Checkbox applies without JavaScript');
  const reset=page2.locator('a.btn').filter({hasText:'Reset'}).first();await reset.focus();await Promise.all([page2.waitForNavigation(),page2.keyboard.press('Enter')]);check(!await page2.locator('input[name=include_undated]').isChecked(),'Reset clears the checkbox without JavaScript');
  await native.close();check(errors.length===0,'No JavaScript errors in the tested report workspaces');
  const name=path.basename(process.env.SPP_BROWSER_EXECUTABLE||'chrome.exe','.exe');fs.writeFileSync(path.join(root,'browser-'+name+'.json'),JSON.stringify({browser:await browser.version(),checks:checks.length,passed:checks,errors},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
