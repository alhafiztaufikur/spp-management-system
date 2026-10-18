const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core'),root=process.env.SPP_SAVINGS_QA,base=process.env.SPP_TEST_BASE_URL;
assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));assert.equal(new URL(base).hostname,'127.0.0.1');const f=JSON.parse(fs.readFileSync(path.join(root,'fixture.json'),'utf8'));
(async()=>{for(const engine of ['chrome','msedge','chromium109']){
 const browser=await pw.chromium.launch(engine==='chromium109'?{headless:true,executablePath:process.env.SPP_CHROMIUM109_EXECUTABLE}:{headless:true,channel:engine});
 try{const c=await browser.newContext({acceptDownloads:true});await c.addCookies([{name:'PHPSESSID',value:f.sessions.super_admin.id,url:base}]);await c.addInitScript(()=>window.print=()=>{});await c.request.post(base+'/unit_switch.php',{form:{unit_id:'1',csrf_token:f.sessions.super_admin.csrf,next:'/dashboard.php'}});const page=await c.newPage();
  await page.goto(base+'/laporan/cetak_struk.php?id='+f.students[1].http_payment);
  await page.evaluate(()=>{const first=document.querySelector('.receipt-sheet');first.classList.remove('receipt-last');for(let i=2;i<=12;i++){const copy=first.cloneNode(true);copy.querySelector('.document-title').append(' / Bulan '+i);if(i===12)copy.classList.add('receipt-last');document.body.append(copy);}});
  const d=page.waitForEvent('download',{timeout:120000});await page.locator('[data-receipt-png]').click();const download=await d;assert.ok(download.suggestedFilename().endsWith('.zip'));await download.saveAs(path.join(root,'outputs',engine+'-annual.zip'));await page.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);assert.equal(await page.locator('canvas,.receipt-image-holder,.receipt-print-page').count(),0);
  await page.pdf({path:path.join(root,'outputs',engine+'-annual.pdf'),preferCSSPageSize:true,displayHeaderFooter:false});console.log('PASS '+engine+': 12 receipt pages to PNG ZIP and PDF');
 }finally{await browser.close();}
}})().catch(e=>{console.error(e);process.exitCode=1});
