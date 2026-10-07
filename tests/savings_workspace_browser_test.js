const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
const base=process.env.SPP_HTTP_BASE,dir=process.env.SPP_QA_DIR,fixtures=JSON.parse(fs.readFileSync(dir+'/sessions.json','utf8'));
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 for(const unit of [1,2,3,0]){
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixtures[unit].cookies.PHPSESSID,url:base}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));
  for(const theme of ['light','dark'])for(const width of [1440,900,390]){
   await page.setViewportSize({width,height:1000});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
   for(const route of ['riwayat',...(unit?['masuk','keluar']:[])]){
    await page.goto(base+'/tabungan/'+route+'.php?tanggal_awal=2000-01-01&tanggal_akhir=2030-01-01');await page.waitForTimeout(150);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,'Horizontal overflow '+unit+' '+route+' '+width);
    if(route==='riwayat')assert.ok(await page.locator('#savings-recap-table tbody tr[data-search]:visible').count()<=10,'Hidden recap rows must stay hidden on mobile');
    if(unit===1||width===1440)await page.screenshot({path:dir+'/'+route+'-'+unit+'-'+width+'-'+theme+'.png',fullPage:true});
   }
  }
  if(unit){
   await page.goto(base+'/tabungan/keluar.php');
   const opts=await page.locator('#siswa-list option').evaluateAll(rows=>rows.slice(0,2).map(row=>row.dataset.nis));assert.equal(opts.length,2);
   await page.route('**/get_saldo.php?*',async route=>{const nis=new URL(route.request().url()).searchParams.get('nis');if(nis===opts[0])await new Promise(resolve=>setTimeout(resolve,500));try{await route.fulfill({contentType:'application/json',body:JSON.stringify({saldo:nis===opts[0]?999999:100})});}catch(_){}});
   await page.locator('#siswa-search').fill(opts[0]);await page.locator('#nominal-keluar').fill('50');await page.locator('#form-tabungan').evaluate(form=>form.requestSubmit());await page.locator('#spp-warning-title').waitFor({state:'visible'});assert.match(await page.locator('#spp-warning-title').innerText(),/Saldo belum/);await page.locator('#spp-warning-close').click();
   await page.locator('#siswa-search').fill(opts[1]);await page.waitForFunction(()=>document.getElementById('raw-saldo').value==='100');await page.waitForTimeout(600);assert.equal(await page.locator('#raw-saldo').inputValue(),'100');
   for(const amount of ['50','100']){await page.locator('#nominal-keluar').fill(amount);assert.equal(await page.locator('.savings-invalid').count(),0);}
   await page.locator('#nominal-keluar').fill('200');assert.equal(await page.locator('.savings-invalid').count(),1);assert.match(await page.locator('#savings-amount-hint').innerText(),/Kelebihan Rp 100/);
   await page.locator('#form-tabungan').evaluate(form=>form.requestSubmit());await page.locator('#spp-warning-title').waitFor({state:'visible'});assert.equal(await page.locator('#spp-warning-title').innerText(),'Saldo tidak mencukupi');await page.locator('#spp-warning-close').click();assert.equal(await page.locator('#nominal-keluar').evaluate(node=>node===document.activeElement),true);
   await page.locator('#nominal-keluar').fill('100');assert.equal(await page.locator('.savings-invalid').count(),0);await page.unroute('**/get_saldo.php?*');
   await page.route('**/get_saldo.php?*',route=>route.fulfill({status:503,contentType:'application/json',body:'{}'}));await page.locator('#sw-change-student').click();await page.locator('#siswa-search').fill(opts[1]);await page.waitForFunction(()=>document.getElementById('saldo-preview').textContent==='Saldo belum tersedia');await page.locator('#form-tabungan').evaluate(form=>form.requestSubmit());await page.locator('#spp-warning-title').waitFor({state:'visible'});assert.match(await page.locator('#spp-warning-title').innerText(),/Saldo belum/);await page.unroute('**/get_saldo.php?*');
  }
  await page.goto(base+'/tabungan/riwayat.php?tanggal_awal=2000-01-01&tanggal_akhir=2030-01-01');const cards=page.locator('.sw-transaction-select');
  if(await cards.count()>1){await cards.nth(1).click();await page.waitForFunction(()=>!document.getElementById('sw-history-detail').hasAttribute('aria-busy'));assert.match(await page.locator('#sw-history-detail').innerText(),/Referensi Transaksi/);}
  if(await cards.count()>1){
   const first=await cards.first().locator('..').evaluate(e=>e.dataset.kind+':'+e.dataset.id);
   await page.route('**/tabungan/detail.php?*',async route=>{const q=new URL(route.request().url()).searchParams,old=q.get('jenis')+':'+q.get('id')===first;if(old)await new Promise(resolve=>setTimeout(resolve,500));try{await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,html:'<p>'+(old?'OLD_DETAIL':'LATEST_DETAIL')+'</p>'})});}catch(_){}});
   await cards.first().click();await cards.nth(1).click();await page.waitForTimeout(650);assert.equal((await page.locator('#sw-history-detail').innerText()).trim(),'LATEST_DETAIL');await page.unroute('**/tabungan/detail.php?*');
  }
  assert.deepEqual(errors,[],'Browser errors unit '+unit);await context.close();console.log('OK browser unit '+unit+': responsive themes, detail, savings validation');
 }
 const context=await browser.newContext({javaScriptEnabled:false});await context.addCookies([{name:'PHPSESSID',value:fixtures[1].cookies.PHPSESSID,url:base}]);const page=await context.newPage();await page.goto(base+'/tabungan/riwayat.php?tanggal_awal=2000-01-01&tanggal_akhir=2030-01-01');const second=page.locator('.sw-transaction-select').nth(1);if(await second.count()){await second.click();assert.match(await page.locator('#sw-history-detail').innerText(),/Referensi Transaksi/);}await context.close();
 if(fixtures[1].print_cookies){const c=await browser.newContext();await c.addCookies([{name:'PHPSESSID',value:fixtures[1].print_cookies.PHPSESSID,url:base}]);const p=await c.newPage();await p.goto(base+'/tabungan/riwayat.php');assert.equal(await p.locator('#sw-print-modal').count(),1);await p.keyboard.press('Tab');assert.equal(await p.locator('#sw-print-later').evaluate(e=>e===document.activeElement),true);await p.keyboard.press('Escape');assert.equal(await p.locator('#sw-print-modal').count(),0);await p.reload();assert.equal(await p.locator('#sw-print-modal').count(),0);await c.close();}
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
