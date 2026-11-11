'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{pathToFileURL}=require('node:url');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const tools=process.env.SPP_CSS_TOOLS,{PNG}=require(tools+'/pngjs');
const audit=process.env.SPP_HISTORY_AUDIT_ROOT,sessions=JSON.parse(fs.readFileSync(audit+'/sessions.json','utf8'));
(async()=>{const pixelmatch=(await import(pathToFileURL(tools+'/pixelmatch/index.js'))).default,browser=await chromium.launch({channel:'chrome',headless:true}),checks=[];try{
 for(const unit of [1,2,3])for(const theme of ['light','dark'])for(const width of unit===1?[1600,390]:[1600])for(const [name,route] of [['payment','/pembayaran/lihat.php?tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22'],['other','/master_biaya_lain.php?master_tab=nonaktif']]){
  const context=await browser.newContext({viewport:{width,height:1000}});await context.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);const page=await context.newPage();let before,assetRoot;
  await page.route('http://127.0.0.1:*/**',async r=>{const p=new URL(r.request().url()).pathname;if(p==='/__static'){await r.fulfill({contentType:'text/html',body:'<!doctype html><title>Captured HTTP render</title>'});return;}const file=assetRoot+p;if(!p.startsWith('/assets/')&&!p.startsWith('/vendor/')){await r.fulfill({status:404,body:''});return;}await r.fulfill({path:file}).catch(()=>r.fulfill({status:404,body:''}));});
  for(const [variant,port] of [['before',8131],['after',8132]]){
   assetRoot=variant==='before'?audit+'/before':process.cwd().replaceAll('\\','/');await page.goto('http://127.0.0.1:'+port+'/__static');
   const capture=name==='payment'?'super_admin_'+unit+'_payment_5':'equivalence_super_admin_'+unit+'_fee_inactive';
   const html=fs.readFileSync(`${audit}/${variant}/${capture}.html`,'utf8').replace('<head>','<head><base href="http://127.0.0.1:'+port+(name==='payment'?'/pembayaran/lihat.php':'/master_biaya_lain.php')+'">');
   await page.setContent(html);await page.evaluate(()=>document.fonts.ready);
   const buffer=await page.screenshot({fullPage:true,animations:'disabled',mask:[page.locator('#liveClock')]});fs.writeFileSync(`${audit}/${name}-${unit}-${theme}-${width}-${variant}.png`,buffer);const png=PNG.sync.read(buffer);
   if(variant==='before')before=png;else{assert.equal(png.width,before.width);assert.equal(png.height,before.height);const pixels=pixelmatch(before.data,png.data,null,png.width,png.height,{threshold:.1});assert.equal(pixels,0,'Unchanged UI '+[name,unit,theme,width].join('/'));}
  }
  checks.push([name,unit,theme,width]);await context.close();console.log('PASS unchanged UI',name,unit,theme,width);
 }
 fs.writeFileSync(audit+'/unchanged-ui-result.json',JSON.stringify({passed:true,checks},null,2));
}finally{await browser.close();}})().catch(e=>{console.error(e.stack);process.exitCode=1;});
