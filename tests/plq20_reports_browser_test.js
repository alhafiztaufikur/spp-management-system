'use strict';
const {chromium}=require('playwright-core'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
const base=process.env.SPP_TEST_BASE_URL,out=process.env.SPP_QA_DIR,fixture=JSON.parse(fs.readFileSync(path.join(process.env.SPP_SAVINGS_QA,'rombel-fixture.json'),'utf8'));let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
(async()=>{
 const identity=await(await fetch(base+'/tests/browser_clone_identity.php')).json();check(identity.port===64421,'Only isolated instance');
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const context=await browser.newContext(),errors=[];await context.addCookies([{name:'PHPSESSID',value:fixture.sessions.super_admin.id,url:base}]);await context.route('https://fonts.googleapis.com/**',route=>route.abort());const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/master_kelas.php');
  const go=async route=>{const response=await page.goto(base+'/'+route);check(response.status()===200,'HTTP '+route);check(!/Fatal error|Warning:|Parse error/.test(await page.locator('body').textContent()),'Clean runtime');};
  for(const unit of [1,2,3]){
   const info=fixture.units[unit];await context.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:fixture.sessions.super_admin.csrf,next:'/master_kelas.php'},maxRedirects:0});
   const bookQuery=new URLSearchParams({nis:info.nis,student_id:String(info.student_id)});
   for(const theme of ['light','dark'])for(const width of [1650,1024,390]){
    await page.setViewportSize({width,height:1000});await page.evaluate(value=>{try{localStorage.setItem('spp_theme',value)}catch(e){}},theme);
    await go('master_kelas.php?'+new URLSearchParams({source_year_id:info.source_year_id,source_level:info.source_level,promotion_mode:'regular',show_participants:1}));
    check(await page.locator('.participants-table').first().evaluate(e=>getComputedStyle(e).fontSize)==='14px','Readable participant rows');check(await page.locator('.participants-heading h2').evaluate(e=>parseFloat(getComputedStyle(e).fontSize))>=18,'Readable heading');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Roster viewport contained');await page.locator('.participants-card').screenshot({path:path.join(out,`participants-${unit}-${theme}-${width}.png`)});
    const held=page.locator('.participants-held');if(await held.count()){
     check(await held.locator('.participants-held-chevron svg').count()===1,'SVG held chevron');await held.locator('summary').focus();await page.keyboard.press('Enter');check(await held.evaluate(e=>e.open),'Held opens by keyboard');check(await held.locator('header strong').first().evaluate(e=>getComputedStyle(e).fontSize)==='16px','Readable held names');await held.screenshot({path:path.join(out,`held-${unit}-${theme}-${width}.png`)});
    }
    await go('tabungan/cetak_buku.php?'+bookQuery);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Book viewport contained');
    const calibration=page.locator('.book-calibration');await calibration.locator('summary').click();await page.locator('[data-layout-key=x_top]').fill('9');await page.locator('[data-book-save]').click();check((await page.locator('[data-book-status]').textContent()).includes('disimpan'),'Save browser calibration');await page.reload();check(await page.locator('[data-layout-key=x_top]').inputValue()==='9','Saved calibration loaded');await page.locator('.book-calibration summary').click();await page.locator('[data-book-reset]').click();check(await page.locator('[data-layout-key=x_top]').inputValue()==='8','Calibration reset');
    await page.screenshot({path:path.join(out,`book-${unit}-${theme}-${width}.png`),fullPage:true});
   }
   for(const mode of ['pdf','guide']){const response=await context.request.get(base+'/tabungan/cetak_buku.php?'+bookQuery+'&output='+mode);check(response.status()===200&&(response.headers()['content-type']||'').includes('application/pdf'),'Book '+mode);fs.writeFileSync(path.join(out,`book-http-${unit}-${mode}.pdf`),await response.body());}
   const invalid=await context.request.get(base+'/tabungan/cetak_buku.php?'+bookQuery+'&output=pdf&layout[y_bottom]=108');check(invalid.status()===400,'Reject fold-margin overlap');
   const foreign=fixture.units[unit===3?1:3];const denied=await context.request.get(base+'/tabungan/cetak_buku.php?'+new URLSearchParams({nis:foreign.nis,student_id:String(foreign.student_id),output:'pdf'}));check(denied.status()===404,'Reject foreign account');
   for(const report of ['recap','audit']){
    const route=report==='recap'?'laporan/export_pdf.php?'+new URLSearchParams({'jenis_laporan[0]':'*',unit:'active',tanggal_awal:'2026-10-01',tanggal_akhir:'2026-10-18'}):'otorisasi_export_pdf.php?'+new URLSearchParams({menu:'all',source:'all',q:info.nis});
    for(const theme of ['light','dark'])for(const width of [1650,1024,390]){
     await page.setViewportSize({width,height:1000});await page.evaluate(value=>{try{localStorage.setItem('spp_theme',value)}catch(e){}},theme);await go(route);await page.waitForFunction(()=>document.querySelector('[data-pdf-message]').textContent.startsWith('PDF siap'),null,{timeout:60000});check(await page.locator('[data-pdf-frame]').getAttribute('src').then(v=>v.startsWith('blob:')),'Preview actual PDF');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Report viewport contained');await page.screenshot({path:path.join(out,`${report}-${unit}-${theme}-${width}.png`)});
    }
    const download=page.waitForEvent('download');await page.locator('[data-pdf-download]').click();const artifact=await download;await artifact.saveAs(path.join(out,`${report}-http-${unit}.pdf`));
    const popup=page.waitForEvent('popup');await page.locator('[data-pdf-print]').click();const printPage=await popup;await printPage.waitForURL(/^blob:/);check(printPage.url().startsWith('blob:'),'Print opens same PDF blob');await printPage.close();
   }
  }
  // Recoverable PDF fetch failure is visible and can be retried.
  await page.route('**/otorisasi_export_pdf.php?**output=inline',route=>route.fulfill({status:503,contentType:'text/plain',body:'Unavailable'}));await go('otorisasi_export_pdf.php?q=unavailable-test');await page.locator('[data-pdf-retry]').waitFor();check(await page.locator('[data-pdf-print]').isDisabled(),'Failed PDF disables print');await page.unroute('**/otorisasi_export_pdf.php?**output=inline');await page.locator('[data-pdf-retry]').click();await page.waitForFunction(()=>document.querySelector('[data-pdf-message]').textContent.startsWith('PDF siap'));
  check(errors.length===0,'No JS errors: '+errors.join('; '));fs.writeFileSync(path.join(out,'browser-verification.json'),JSON.stringify({checks,errors},null,2));console.log('PASS '+checks+' browser/HTTP/access checks');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
