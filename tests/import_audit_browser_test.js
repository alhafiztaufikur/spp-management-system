const fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(process.argv[2]||'');assert(['import_audit_20261020','ui_time_audit_20261021'].some(name=>root.includes(name)));
const {chromium}=require(root+'/../import_audit_20261020/browser-tools/node_modules/playwright-core');
const c=JSON.parse(fs.readFileSync(root+'/http.json','utf8')),fixture=JSON.parse(fs.readFileSync(root+'/http-fixture.json','utf8'));
assert.equal(c.base,'http://127.0.0.1:58361');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 const results=[],errors=[];
 try{
  for(const unit of [1,2,3,0]){
   const ctx=await browser.newContext({viewport:{width:1440,height:960}});
   await ctx.addCookies([{name:'PHPSESSID',value:c.sessions[unit],url:c.base}]);
   const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
   for(const [route,label] of [['pembayaran/lihat.php?tanggal_awal=2022-01-01&tanggal_akhir=2026-12-31','payments'],['pembayaran/riwayat_daftar_ulang.php','registration'],['tabungan/riwayat.php?all_periods=1','savings'],['laporan/template.php?template=tabungan-siswa&unit='+(unit||'all')+'&tanggal_awal=2026-10-16&tanggal_akhir=2026-10-16','report']]){
    const response=await page.goto(c.base+'/'+route,{waitUntil:'networkidle',timeout:120000});assert.equal(response.status(),200);
    assert(!/Fatal error:|Warning:|Uncaught /.test(await page.locator('body').innerText()));
    await page.screenshot({path:root+'/artifacts/browser-'+unit+'-'+label+'.png',fullPage:false});
    results.push({unit,label});
   }
   await page.goto(c.base+'/laporan/template.php?template=penerimaan&unit='+(unit||'all')+'&tanggal_awal=2026-10-16&tanggal_akhir=2026-10-16',{waitUntil:'networkidle'});
   const operator=await page.locator('select[name="operator[]"] option[value^="legacy:"]').first().getAttribute('value');
   assert(/^legacy:[123]:[a-f0-9]*$/.test(operator));
   const filterQuery=new URLSearchParams([['template','penerimaan'],['unit',String(unit||'all')],['tanggal_awal','2026-10-16'],['tanggal_akhir','2026-10-16'],['operator[]',operator],['metode[]','Legacy']]);
   const filtered=await page.goto(c.base+'/laporan/template.php?'+filterQuery,{waitUntil:'networkidle'});assert.equal(filtered.status(),200);
   assert.deepEqual(await page.locator('select[name="operator[]"]').evaluate(el=>Array.from(el.selectedOptions,o=>o.value)),[operator]);
   assert.deepEqual(await page.locator('select[name="metode[]"]').evaluate(el=>Array.from(el.selectedOptions,o=>o.value)),['Legacy']);
   results.push({unit,label:'Legacy operator and method survive rendered filters'});
   if(unit===1){
    await page.goto(c.base+'/pembayaran/form.php',{waitUntil:'networkidle'});
    const name=await page.locator('#siswa-list option[data-student-id="'+fixture.adam.id+'"]').getAttribute('value');
    await page.locator('#siswa-search').fill(name);
    await page.locator('#payment-history-scope').selectOption('all');
    await page.waitForFunction(()=>document.querySelector('#sh-count')?.textContent.includes('54 dari 54'),null,{timeout:30000});
    await page.locator('#student-history').scrollIntoViewIfNeeded();
    await page.screenshot({path:root+'/artifacts/browser-adam-history.png',fullPage:false});
    assert.equal(await page.locator('#payment-history-body tr').count(),54);results.push({unit,label:'Adam 54 rendered transactions'});
    await page.setViewportSize({width:390,height:844});await page.evaluate(()=>localStorage.setItem('spp_theme','dark'));
    await page.goto(c.base+'/pembayaran/riwayat_daftar_ulang.php',{waitUntil:'networkidle'});
    await page.screenshot({path:root+'/artifacts/browser-mobile-registration.png',fullPage:false});
    results.push({unit,label:'Mobile dark registration'});
   }
   await ctx.close();
  }
  assert.deepEqual(errors,[]);
  fs.writeFileSync(root+'/browser-results.json',JSON.stringify({version:browser.version(),checks:results.length,results,errors},null,2));
  console.log('PASS',results.length,'browser cases');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
