'use strict';
const {chromium}=require('playwright-core'),fs=require('fs'),path=require('path'),assert=require('assert/strict'),cp=require('child_process');
const base=process.env.SPP_TEST_BASE_URL,out=process.env.SPP_QA_DIR,f=JSON.parse(fs.readFileSync(path.join(process.env.SPP_SAVINGS_QA,'rombel-fixture.json'),'utf8'));let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
(async()=>{
 check((await(await fetch(base+'/tests/browser_clone_identity.php')).json()).port===64421,'Private clone');const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const c=await browser.newContext(),p=await c.newPage(),errors=[];await c.addCookies([{name:'PHPSESSID',value:f.sessions.super_admin.id,url:base}]);await c.route('https://fonts.googleapis.com/**',r=>r.abort());p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/dashboard.php');
  const go=async route=>{const r=await p.goto(base+'/'+route);check(r.status()===200,'HTTP '+route);check(!/Fatal error|Warning:|Parse error/.test(await p.locator('body').textContent()),'No runtime error');};
  const switchUnit=async unit=>{await c.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:f.sessions.super_admin.csrf,next:'/dashboard.php'},maxRedirects:0});};
  for(const unit of [1,2,3]){
   await switchUnit(unit);const item=f.units[unit];
   for(const theme of ['light','dark'])for(const width of [1650,1024,390]){
    await p.setViewportSize({width,height:1000});await p.evaluate(t=>localStorage.setItem('spp_theme',t),theme);
    await go('tabungan/cetak_buku.php?'+new URLSearchParams({nis:item.nis,student_id:item.student_id}));
    const spacing=await p.locator('.book-preview').evaluate(e=>{const frame=e.querySelector('iframe').getBoundingClientRect(),info=e.querySelector('.book-muted').getBoundingClientRect(),button=e.querySelector('p:last-child').getBoundingClientRect();return {padding:parseFloat(getComputedStyle(e).paddingLeft),above:frame.top-info.bottom,below:button.top-frame.bottom};});
    check(spacing.above>=14&&spacing.below>=16,'Guide spacing');check(spacing.padding===(width<500?16:24),'Guide card padding');check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Book fits viewport');await p.screenshot({path:path.join(out,`book-${unit}-${theme}-${width}.png`),fullPage:true});
    for(const template of ['status','kas-tabungan']){
     await go('laporan/export_global.php?'+new URLSearchParams({template,unit:String(unit),tahun_ajaran:'2026/2027',bulan_awal:'10',tanggal_awal:'2026-10-01',tanggal_akhir:'2026-10-18'}));
     check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Global preview fits viewport');await p.screenshot({path:path.join(out,`global-${template}-${unit}-${theme}-${width}.png`)});
    }
    for(const route of ['otorisasi_export_pdf.php?'+new URLSearchParams({q:item.nis}),'laporan/export_pdf.php?'+new URLSearchParams({'jenis_laporan[0]':'*',q:item.nis})]){
     await go(route);await p.waitForFunction(()=>document.querySelector('[data-pdf-message]').textContent.startsWith('PDF siap'),null,{timeout:60000});check(await p.locator('[data-pdf-frame]').getAttribute('src').then(v=>v.startsWith('blob:')),'Native PDF loaded');check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Native viewport contained');
    }
   }
   for(const mode of ['pdf','guide']){const r=await c.request.get(base+'/tabungan/cetak_buku.php?'+new URLSearchParams({nis:item.nis,student_id:item.student_id,output:mode}));check(r.ok()&&(r.headers()['content-type']||'').includes('application/pdf'),'Book PDF');fs.writeFileSync(path.join(out,`book-${unit}-${mode}.pdf`),await r.body());}
   await go('laporan/template.php?'+new URLSearchParams({template:'tabungan-siswa',unit:String(unit)}));check(!(await p.locator('select[name^=operator]').textContent()).includes('(@'),'No operator username');
  }
  await switchUnit(1);const route='laporan/export_pdf.php?'+new URLSearchParams({'jenis_laporan[0]':'*',q:f.units[1].nis});let reads=0;p.on('request',r=>{if(r.url().includes('/laporan/export_pdf.php?')&&new URL(r.url()).searchParams.get('output')==='inline')reads++;});
  await go(route);await p.waitForFunction(()=>document.querySelector('[data-pdf-message]').textContent.startsWith('PDF siap'));
  let applied=false;
  try{
   const changed=JSON.parse(cp.execFileSync('C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe',['tests/report_preview_name_fixture.php','apply'],{encoding:'utf8',env:process.env}));applied=true;
   const before=reads,download=p.waitForEvent('download');await p.locator('[data-pdf-download]').click();const file=await download;await file.saveAs(path.join(out,'frontend-fresh.pdf'));check(reads===before+1,'Download rechecks backend after preview');
   const popupPromise=p.waitForEvent('popup');await p.locator('[data-pdf-print]').click();const popup=await popupPromise;await popup.waitForURL(/^blob:/);check(reads===before+2,'Print rechecks backend after preview');await popup.close();fs.writeFileSync(path.join(out,'frontend-expected.json'),JSON.stringify(changed));
  }finally{if(applied)cp.execFileSync('C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe',['tests/report_preview_name_fixture.php','restore'],{env:process.env});}
  await p.route('**/laporan/export_pdf.php?**output=inline',r=>r.fulfill({status:503,contentType:'text/plain',body:'Unavailable'}));await p.locator('[data-pdf-download]').click();await p.locator('[data-pdf-retry]').waitFor();check((await p.locator('[data-pdf-message]').textContent()).includes('belum dapat'),'Failure message');await p.unroute('**/laporan/export_pdf.php?**output=inline');await p.locator('[data-pdf-retry]').click();await p.waitForFunction(()=>document.querySelector('[data-pdf-message]').textContent.startsWith('PDF siap'));
  check(!errors.length,'No JS errors: '+errors.join(';'));fs.writeFileSync(path.join(out,'browser-verification.json'),JSON.stringify({checks,errors},null,2));console.log('PASS '+checks+' preview/theme/freshness checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
