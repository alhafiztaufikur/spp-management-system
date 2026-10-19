/* Private sessions; page reads and report exports only. Never submits financial forms. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_UI_QA,base=process.env.SPP_TEST_BASE_URL;
assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));
assert.equal(new URL(base).hostname,'127.0.0.1');
const fixture=JSON.parse(fs.readFileSync(path.join(root,'ui-fixture.json'),'utf8'));
let checks=0;function check(ok,message){assert.ok(ok,message);checks++;}
(async()=>{
 const browser=await chromium.launch({channel:process.env.SPP_UI_BROWSER||'chrome',headless:true});
 try{
  for(const unit of [1,2,3,0]){
   const context=await browser.newContext({acceptDownloads:true});
   await context.addCookies([{name:'PHPSESSID',value:fixture.sessions[unit],url:base}]);
   await context.route('**/*',route=>{
    const req=route.request();if(req.method()!=='GET'&&!new URL(req.url()).pathname.endsWith('/otorisasi_export_pdf.php'))return route.abort();
    return route.continue();
   });
   const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.route('https://fonts.googleapis.com/**',r=>r.abort());
   const item=fixture.students[unit];
   for(const theme of ['light','dark'])for(const width of [1440,768,390]){
    await page.setViewportSize({width,height:1000});
    for(const menu of ['pembayaran','daftar_ulang','tabungan']){
     const response=await page.goto(base+'/otorisasi_transaksi.php?view=history&menu='+menu);
     await page.evaluate(t=>{document.documentElement.dataset.theme=t;localStorage.setItem('spp_theme',t)},theme);
     check(response.status()===200,'Authorization response');
     check(await page.locator('.auth-menu-card[aria-current="page"]').count()===1,'Active menu');
     check(await page.locator('.alert-error').count()===0,'Authorization SQL failure');
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Authorization overflow '+unit+'/'+theme+'/'+width);
     for(const text of await page.locator('.auth-menu-badge').allTextContents())check(text===({pembayaran:'Riwayat Pembayaran',daftar_ulang:'Riwayat Daftar Ulang',tabungan:'Riwayat Tabungan'})[menu],'Cross-menu row');
    }
    if(item){
     await page.goto(base+'/tabungan/riwayat.php?nis='+encodeURIComponent(item.nis)+'&tanggal_awal=2000-01-01&tanggal_akhir=2030-01-01&jenis=masuk&id='+item.id);
     await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
     const panel=page.locator('.sw-action-panel').first();
     if(await panel.count()){
      await panel.locator('summary').click();
      check(await panel.getAttribute('open')!==null,'Correction accordion');
      const fields=panel.locator('input:not([type="hidden"]),textarea');
      for(const field of await fields.all()){const box=await field.boundingBox();check(!box||box.width<=width,'Correction field overflow');}
     }
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Savings overflow');
     if(unit===1&&(width===1440||width===390)){await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:path.join(root,'outputs','history-'+theme+'-'+width+'.png'),fullPage:true});}
     await page.goto(base+'/tabungan/cetak_struk.php?jenis=masuk&id='+item.id);
     check(await page.locator('.receipt-savings-sheet').count()===1,'Receipt template');
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Receipt overflow');
    }
   }
   await page.setViewportSize({width:1440,height:1000});
   for(const menu of ['pembayaran','daftar_ulang','tabungan']){
    await page.goto(base+'/otorisasi_transaksi.php?view=history&menu='+menu);
    if(unit===1){await page.waitForTimeout(350);await page.screenshot({path:path.join(root,'outputs','authorization-'+menu+'.png'),fullPage:true});}
    const link=await page.locator('.auth-export-actions a').getAttribute('href');check(link.includes('menu='+menu),'Filter export link');
    const response=await context.request.get(base+'/'+link+'&output=pdf');check(response.status()===200&&(await response.body()).subarray(0,5).toString()==='%PDF-','Category PDF export');
    if(menu==='tabungan'&&await page.locator('[data-export-choice]').count()){
     await page.locator('[data-export-choice]').first().check();await page.locator('[data-export-selected]').click();await page.waitForURL('**/otorisasi_export_pdf.php?selection_token=*');
     check((await page.content()).includes('Riwayat Tabungan'),'Selected export retains menu');
    }
   }
   if(item){
    await page.goto(base+'/master_biaya_lain.php');check(await page.locator('#form-terbit-biaya').count()===0,'Optional master catalog');
    await page.goto(base+'/pembayaran/form.php');check(await page.evaluate(()=>Array.isArray(window.sppOtherFeeMasters)),'Optional catalog available');
    await page.goto(base+'/tabungan/cetak_struk.php?jenis=masuk&id='+item.id);
    const pdf=await context.request.get(base+'/tabungan/cetak_struk.php?jenis=masuk&id='+item.id+'&output=pdf');check(pdf.status()===200&&(await pdf.body()).subarray(0,5).toString()==='%PDF-','Savings server PDF');
    fs.writeFileSync(path.join(root,'outputs','savings-'+unit+'.pdf'),await pdf.body());
    const downloadPromise=page.waitForEvent('download');await page.locator('[data-receipt-png]').click();const download=await downloadPromise;
    check(download.suggestedFilename().endsWith('.png'),'Single-page PNG');const target=path.join(root,'outputs','savings-'+unit+'.png');await download.saveAs(target);
    const bytes=fs.readFileSync(target);check(bytes.readUInt32BE(16)===2550&&bytes.readUInt32BE(20)===1650,'PNG dimensions');
    await page.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);
    check(await page.locator('.receipt-image-holder,.receipt-print-page,canvas').count()===0,'PNG cleanup');
    await page.pdf({path:path.join(root,'outputs','savings-browser-'+unit+'.pdf'),preferCSSPageSize:true,displayHeaderFooter:false});
    if(unit===1)await page.screenshot({path:path.join(root,'outputs','receipt-preview.png'),fullPage:true});
   }
   check(errors.length===0,'Browser errors: '+errors.join('; '));await context.close();console.log('Checked unit '+unit+' ('+checks+' checks).');
  }
  console.log('PASS '+checks+' category, responsive UI, restored master, PDF and PNG checks.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
