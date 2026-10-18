/* Uses private sessions and an owned HTTP fixture. Downloads files; never prints physically. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_SAVINGS_QA,base=process.env.SPP_TEST_BASE_URL;assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));assert.equal(new URL(base).hostname,'127.0.0.1');
const fixture=JSON.parse(fs.readFileSync(path.join(root,'fixture.json'),'utf8'));let checks=0;
function check(ok,why){assert.ok(ok,why);checks++;}
(async()=>{
 for(const engine of ['chrome','msedge','chromium109']){
  const browser=await pw.chromium.launch(engine==='chromium109'?{headless:true,executablePath:process.env.SPP_CHROMIUM109_EXECUTABLE}:{headless:true,channel:engine});
  try{
   const context=await browser.newContext({acceptDownloads:true});await context.addCookies([{name:'PHPSESSID',value:fixture.sessions.super_admin.id,url:base}]);await context.addInitScript(()=>window.print=()=>{});
   const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.route('https://fonts.googleapis.com/**',r=>r.abort());
   for(const unit of [1,2,3]){
    await context.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:fixture.sessions.super_admin.csrf,next:'/tabungan/riwayat.php'}});
    const item=fixture.students[unit];
    for(const theme of ['light','dark'])for(const width of [1440,768,390]){
     await page.setViewportSize({width,height:960});
     for(const url of ['/otorisasi_transaksi.php?source=all&view=history&q='+fixture.nis,'/master_biaya_lain.php','/tabungan/riwayat.php?nis='+fixture.nis+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-31&jenis=masuk&id='+item.in,'/tabungan/cetak_buku.php?student_id='+item.sid+'&nis='+fixture.nis]){
      const response=await page.goto(base+url);await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t},theme);check(response.status()===200,'Page '+url);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Overflow '+engine+'/'+width+'/'+url);
     }
    }
    await page.setViewportSize({width:1440,height:960});await page.goto(base+'/pembayaran/edit.php?id='+item.http_payment);await page.waitForFunction(()=>!!window.refreshBiayaLainOptions);const choice=await page.locator('.biaya-lain-select').first().inputValue();check(choice==='master:'+item.master,'Edited optional choice');
    const optional=await page.evaluate(id=>{const row=document.querySelector('.biaya-lain-row'),s=row.querySelector('select');s.value='master:'+id;refreshBiayaLainRow(row,false);refreshBiayaLainAvailability();return {value:row.querySelector('.biaya-lain-nominal').value,message:s.validationMessage,hidden:row.querySelector('.biaya-lain-sisa').closest('label').hidden}},item.master);
    check(optional.value==='100.000'&&!optional.message&&optional.hidden,'Optional prefill/unlimited choice');
    for(const [name,url] of [['savings','/tabungan/cetak_struk.php?jenis=masuk&id='+item.in],['payment','/laporan/cetak_struk.php?id='+item.http_payment]]){
     await page.goto(base+url);check(await page.locator('.receipt-sheet').count()===1,'Single receipt');
     const download=page.waitForEvent('download',{timeout:120000});await page.locator('[data-receipt-png]').click();const file=await download;const target=path.join(root,'outputs',engine+'-'+unit+'-'+name+'.png');await file.saveAs(target);const b=fs.readFileSync(target);check(b.toString('ascii',1,4)==='PNG'&&b.readUInt32BE(16)===2550&&b.readUInt32BE(20)===1650,'PNG dimensions');
     await page.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);check(await page.locator('canvas,.receipt-image-holder,.receipt-print-page').count()===0,'Export cleanup');
     await page.pdf({path:path.join(root,'outputs',engine+'-'+unit+'-'+name+'.pdf'),preferCSSPageSize:true,displayHeaderFooter:false});
    }
    if(unit===1){
     await page.goto(base+'/laporan/cetak_struk.php?id='+item.http_payment);await page.evaluate(()=>{const body=document.querySelector('.receipt-document>tbody'),line=body.querySelector('.receipt-line');for(let i=0;i<70;i++){const copy=line.cloneNode(true);copy.querySelector('td').textContent='Rincian tambahan '+i;body.insertBefore(copy,body.querySelector('.receipt-closing'));}});
     const download=page.waitForEvent('download',{timeout:120000});await page.locator('[data-receipt-png]').click();const file=await download;check(file.suggestedFilename().endsWith('.zip'),'Multipage ZIP');await file.saveAs(path.join(root,'outputs',engine+'-multipage.zip'));await page.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);
     await page.evaluate(()=>{const capture=window.html2canvas;window.html2canvas=()=>Promise.reject(new Error('Kegagalan uji'));window.captureOriginal=capture});await page.locator('[data-receipt-png]').click();await page.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);check((await page.locator('[data-receipt-export-status]').textContent()).includes('Unduhan gagal'),'Failure displayed');check(await page.locator('canvas,.receipt-image-holder,.receipt-print-page').count()===0,'Failure cleanup');
    }
   }
   check(errors.length===0,'Browser errors: '+errors.join('; '));await context.close();console.log('PASS '+engine+' layouts and PNG/PDF downloads');
  }finally{await browser.close();}
 }
 console.log('PASS '+checks+' browser checks');
})().catch(e=>{console.error(e);process.exitCode=1});
