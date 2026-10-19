const fs=require('fs'),assert=require('assert'),path=require('path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'C:/laragon/backups/spp-management-system/readiness_browser_tools/node_modules/playwright-core');
const root=path.dirname(path.resolve(process.argv[2]||''));assert(root.includes('legacy_readback_')&&fs.existsSync(root+'/instance.json'),'Pass the private readback config');const c=JSON.parse(fs.readFileSync(root+'/http.json','utf8')),fixtures=JSON.parse(fs.readFileSync(root+'/fixtures.json','utf8'));
(async()=>{const results=[],errors=[];
for(const [name,exe] of [['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe'],['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe']]){
 const browser=await chromium.launch({headless:true,executablePath:exe});if(name==='chromium109')assert(browser.version().startsWith('109.'));
 const ctx=await browser.newContext();await ctx.route('https://**/*',r=>r.abort());const page=await ctx.newPage();page.on('pageerror',e=>errors.push(name+': '+page.url()+': '+e.stack));
 for(const [u,n,palette] of [[1,240,'sd'],[2,194,'smp'],[3,44,'sma']]){
 await ctx.addCookies([{name:'PHPSESSID',value:c.sessions[u],url:'http://127.0.0.1:58250'}]);
 for(const [theme,width] of [['light',1366],['dark',390]]){
  await page.setViewportSize({width,height:900});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
  const res=await page.goto('http://127.0.0.1:58250/pembayaran/lihat.php?tanggal_awal=2026-10-01&tanggal_akhir=2026-10-20',{waitUntil:'domcontentloaded',timeout:180000});assert.equal(res.status(),200);assert.equal(await page.locator('[data-payment-record]').count(),10);assert((await page.locator('.ph-panel-heading').first().innerText()).includes('dari '+n+' transaksi'));assert.equal(await page.locator('html').getAttribute('data-palette'),palette);
  const size=await page.evaluate(()=>({w:innerWidth,s:document.documentElement.scrollWidth}));assert(size.s<=size.w+2,'Overflow '+JSON.stringify({name,u,theme,...size}));await page.locator('[data-payment-record]').nth(1).click();await page.waitForFunction(()=>document.querySelector('[data-payment-detail]')?.getAttribute('aria-busy')!=='true');assert((await page.locator('[data-payment-detail]').innerText()).includes('Histori Legacy'));
  await page.screenshot({path:path.join(root,`history-${name}-${u}-${theme}-${width}.png`),fullPage:false});results.push({browser:name,version:browser.version(),unit:u,theme,width,rows:10});console.log('PASS',name,u,theme,width);
 }
 }
 await ctx.addCookies([{name:'PHPSESSID',value:c.sessions[3],url:'http://127.0.0.1:58250'}]);
 const du=-(1000000000+Number(fixtures[3].du.id));await page.goto('http://127.0.0.1:58250/pembayaran/lihat.php?all_periods=1&selected='+du,{waitUntil:'domcontentloaded',timeout:180000});assert.equal(await page.locator('[data-payment-record][aria-current]').getAttribute('data-id'),String(du));
 for(const [route,label] of [['tabungan/riwayat.php?all_periods=1','savings'],['pembayaran/riwayat_daftar_ulang.php?view=legacy','du']]){
  await page.goto('http://127.0.0.1:58250/'+route,{waitUntil:'domcontentloaded',timeout:180000});assert(!/Fatal error|Warning:/.test(await page.locator('body').innerText()));if(label==='savings')assert.equal(await page.locator('#account-recap tbody tr').count(),25);await page.screenshot({path:path.join(root,`${label}-${name}-dark-390.png`)});const size=await page.evaluate(()=>({w:innerWidth,s:document.documentElement.scrollWidth}));assert(size.s<=size.w+2,label+' overflow '+JSON.stringify(size));results.push({browser:name,route,passed:true});
 }
 const plain=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:900}});await plain.addCookies([{name:'PHPSESSID',value:c.sessions[3],url:'http://127.0.0.1:58250'}]);const np=await plain.newPage();await np.goto('http://127.0.0.1:58250/pembayaran/lihat.php?all_periods=1&selected='+du,{waitUntil:'domcontentloaded',timeout:180000});assert.equal(await np.locator('[data-payment-record][aria-current]').getAttribute('data-id'),String(du));assert(await np.locator('noscript input[type=date]').first().isVisible());await np.keyboard.press('Tab');assert(await np.evaluate(()=>document.activeElement!==document.body));results.push({browser:name,noJavaScript:true,keyboard:true,passed:true});await plain.close();
 await ctx.addCookies([{name:'PHPSESSID',value:c.sessions[3],url:'http://127.0.0.1:58251'}]);await page.goto('http://127.0.0.1:58251/pembayaran/lihat.php?all_periods=1',{waitUntil:'domcontentloaded',timeout:180000});assert(!/Fatal error|Warning:/.test(await page.locator('body').innerText()));results.push({browser:name,mainNative:true,passed:true});
 await ctx.close();await browser.close();}
assert.deepEqual(errors,[]);fs.writeFileSync(root+'/browser-final.json',JSON.stringify({results,errors},null,2));console.log('PASS',results.length,'actual browser cases');
})().catch(e=>{console.error(e);process.exit(1)});
