/* Read-only UI exercise on an explicitly identified disposable database. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,account=JSON.parse(fs.readFileSync(process.env.SPP_DROPDOWN_ACCOUNT_FILE,'utf8'));
assert.ok(base&&process.env.SPP_TEST_ISOLATED_INSTANCE);
assert.equal(new URL(base).protocol,'http:');assert.ok(['127.0.0.1','localhost'].includes(new URL(base).hostname));
const manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8'));
assert.equal(manifest.isolated_test,true);assert.notEqual(manifest.server_uuid,manifest.source_server_uuid);
assert.notEqual(Number(manifest.port),3306);assert.notEqual(manifest.datadir,manifest.source_datadir);
const pages=['dashboard.php','siswa/daftar.php','master_kelas.php','master_spp.php','master_daftar_ulang.php','master_biaya_lain.php','pembayaran/form.php','pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php','otorisasi_transaksi.php?view=history','tabungan/riwayat.php','tabungan/cetak.php','laporan/index.php','laporan/surat_orang_tua.php','role_management.php','backup_restore.php'];
const reports=['status','per-item','penerimaan','spp-tahunan','tabungan-siswa','saldo-tabungan','riwayat-tagihan','tunggakan-siswa','setoran','kas-tabungan'];
const controls='.spp-select-trigger:not(:disabled),[data-class-picker-button],.payment-year-select,.du-selector-trigger:not(:disabled),.report-date-range-button,.report-month-range-button,.report-year-range-button';
let checked=0;
async function settle(page){await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));}
async function stableAnchor(button){await button.evaluate(b=>new Promise(resolve=>{let previous='',stable=0,frames=0;function tick(){const r=b.getBoundingClientRect(),current=[r.x,r.y,r.width,r.height,scrollX,scrollY].join('|');stable=current===previous?stable+1:0;previous=current;if(stable>=6||++frames>=120)resolve();else requestAnimationFrame(tick);}requestAnimationFrame(tick);}));}
async function bounds(page,button){
 await settle(page);const value=await button.evaluate(b=>{const panel=[...document.querySelectorAll('[data-spp-floating-open]')].find(p=>p.id===b.getAttribute('aria-controls'))||[...document.querySelectorAll('[data-spp-floating-open]')].at(-1);return panel?{a:b.getBoundingClientRect().toJSON(),p:panel.getBoundingClientRect().toJSON(),v:window.sppFloatingPanels.viewport()}:null;});
 if(!value&&process.env.SPP_DROPDOWN_OUTPUT)await page.screenshot({path:path.join(process.env.SPP_DROPDOWN_OUTPUT,'missing-'+process.env.SPP_DROPDOWN_ENGINE+'.png')});
 assert.ok(value,'Menu missing: '+page.url()+' '+await button.evaluate(b=>JSON.stringify({button:b.outerHTML,rect:b.getBoundingClientRect().toJSON(),panels:[...document.querySelectorAll('.spp-dropdown-panel')].map(p=>({id:p.id,hidden:p.hidden,open:p.dataset.sppFloatingOpen,rect:p.getBoundingClientRect().toJSON()}))})));const {a,p,v}=value;
 assert.ok(p.width>0&&p.height>0&&p.left>=v.left+7.5&&p.right<=v.right-7.5&&p.top>=v.top+7.5&&p.bottom<=v.bottom-7.5,JSON.stringify(value));
 assert.ok(Math.abs(p.top-a.bottom-6)<=2||Math.abs(a.top-p.bottom-6)<=2,'Anchor '+page.url()+JSON.stringify(value));checked++;
}
async function audit(page,url,width,theme,unit){
 if(process.env.SPP_DROPDOWN_VERBOSE)console.log('Audit',unit,width,theme,url);
 await page.setViewportSize({width,height:900});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);const response=await page.goto(new URL(url,base).href,{waitUntil:'domcontentloaded'});assert.equal(response.status(),200,url);await page.waitForLoadState('domcontentloaded');
 for(const button of await page.locator(controls).all()){
  if(!await button.isVisible()||await button.isDisabled())continue;
  await button.evaluate(b=>b.scrollIntoView({behavior:'instant',block:'center',inline:'nearest'}));await stableAnchor(button);await button.click();await bounds(page,button);await page.keyboard.press('Escape');await settle(page);
 }
 const search=page.locator('input[data-student-search],#siswa-search').first();
 if(await search.count()&&await search.isVisible()){
  await search.scrollIntoViewIfNeeded();await search.click();await bounds(page,search);await page.keyboard.press('Escape');
 }
 if(process.env.SPP_DROPDOWN_OUTPUT&&url==='pembayaran/form.php')await page.screenshot({path:path.join(process.env.SPP_DROPDOWN_OUTPUT,`application-${process.env.SPP_DROPDOWN_ENGINE}-${unit}-${width}-${theme}.png`)});
}
(async()=>{
 const name=process.env.SPP_DROPDOWN_ENGINE||'chrome',type=['firefox','webkit'].includes(name)?pw[name]:pw.chromium;
 const browser=await type.launch({headless:true,...(['chrome','msedge'].includes(name)?{channel:name}:name==='chromium109'?{executablePath:process.env.SPP_CHROMIUM109_EXECUTABLE}:{})});
 try{
  const page=await browser.newPage(),errors=[];await page.route('https://fonts.googleapis.com/**',route=>route.abort());await page.route('https://fonts.gstatic.com/**',route=>route.abort());page.on('pageerror',e=>errors.push({url:page.url(),message:e.message}));
  const identity=await(await page.request.get(new URL('tests/browser_clone_identity.php',base).href)).json();assert.equal(identity.server_uuid,manifest.server_uuid);assert.equal(Number(identity.port),manifest.port);
  await page.goto(new URL('login.php',base).href);await page.locator('#username').fill(account.username);await page.locator('#password').fill(account.password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  for(const unit of (process.env.SPP_DROPDOWN_UNITS||'1,2,3').split(',').map(Number)){
   assert.ok([0,1,2,3].includes(unit));
   await page.goto(new URL('dashboard.php',base).href);await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
   const scopedPages=unit===0?pages.filter(p=>!['master_kelas.php','master_spp.php','master_daftar_ulang.php','master_biaya_lain.php','pembayaran/form.php'].includes(p)):pages;
   for(const url of [...scopedPages,...reports.map(t=>'laporan/template.php?template='+t+'&unit=active')])await audit(page,url,1366,'light',unit);
   for(const width of [360,390,768,1024,1920])for(const theme of ['light','dark'])for(const url of (unit===0?['siswa/daftar.php','laporan/template.php?template=per-item&unit=active']:['pembayaran/form.php','siswa/daftar.php','laporan/template.php?template=per-item&unit=active']))await audit(page,url,width,theme,unit);
   if(unit===0){console.log(`PASS application ${name} Semua Unit`);continue;}
   // Actual legacy menu callbacks and nested report range callbacks.
   await page.goto(new URL('siswa/daftar.php',base).href);await page.locator('[data-class-picker-button]').click();const option=page.locator('[data-class-picker-option]:visible').first();const value=await option.getAttribute('data-value');await option.click();assert.equal(await page.locator('#kelas-baru').inputValue(),value);await settle(page);assert.equal(await page.locator('[data-spp-floating-open]').count(),0);
   await page.goto(new URL('pembayaran/form.php',base).href);await page.locator('#tahun-bayar').click();await page.locator('.payment-year-option:visible').first().click();await settle(page);assert.equal(await page.locator('[data-spp-floating-open]').count(),0);
   const search=page.locator('#siswa-search');await search.click();const student=page.locator('.student-search-option:visible').first();if(await student.count()){await student.click();await page.waitForFunction(()=>document.querySelector('#disp-nis').value!=='');await page.locator('#du-selector-trigger').click();await bounds(page,page.locator('#du-selector-trigger'));await page.keyboard.press('Escape');}
   await page.goto(new URL('laporan/template.php?template=per-item&kategori=spp&unit=active',base).href);const range=page.locator('.report-month-range-button');if(await range.count()){await range.click();const start=page.locator('[data-month-range-start]');await start.locator('..').locator('.spp-select-trigger').click();await page.locator('.spp-dropdown-option:visible').filter({hasText:'Agustus'}).click();assert.equal(await start.inputValue(),'08');assert.equal(await page.locator('.report-month-range-popover').isVisible(),true);await page.locator('[data-month-range-apply]').click();assert.equal(await page.locator('input[name=bulan_awal]').inputValue(),'08');}
   console.log(`PASS application ${name} unit ${unit}`);
  }
  assert.deepEqual(errors,[]);console.log(`PASS ${checked} application dropdowns: ${name} ${browser.version()}`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
