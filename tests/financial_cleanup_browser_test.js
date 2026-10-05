const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const base=new URL(process.env.SPP_TEST_BASE_URL),db=process.env.SPP_DB_NAME,dir=process.env.SPP_UI_ARTIFACTS;
assert.match(db,/^db_spp_audit_[a-z0-9_]+$/);assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.ok(['127.0.0.1','localhost'].includes(base.hostname));
(async()=>{fs.mkdirSync(dir,{recursive:true});const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 const context=await browser.newContext({timezoneId:'America/Los_Angeles',viewport:{width:1440,height:1000}});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 assert.equal((await(await page.request.get(new URL('/tests/browser_clone_identity.php',base).href)).json()).database,db);
 await page.goto(new URL('/login.php',base).href);await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await page.locator('#btn-login').click();await page.waitForURL('**/dashboard.php');
 const ids=JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE,'utf8'));let states=0;
 for(const scope of [0,1,2,3]){
  await page.goto(new URL('/dashboard.php',base).href);await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(scope))]);
  const routes=scope===0?['/dashboard.php','/siswa/daftar.php','/pembayaran/lihat.php','/laporan/index.php']:['/siswa/daftar.php','/pembayaran/form.php','/pembayaran/edit.php?id='+ids[scope].id,'/laporan/index.php'];
  for(const theme of ['light','dark'])for(const width of [1440,2560,390]){
   await page.setViewportSize({width,height:width===390?844:1000});
   await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.setAttribute('data-theme',t);},theme);
   for(let n=0;n<routes.length;n++){
    const response=await page.goto(new URL(routes[n],base).href);assert.equal(response.status(),200,routes[n]);await page.waitForLoadState('networkidle');
    await page.addStyleTag({content:'*,*::before,*::after{transition:none!important;animation:none!important}'});
    assert.doesNotMatch(await page.content(),/Fatal error|Warning:|Unknown column/);
    assert.equal(await page.locator('input[name="uang_pangkal"],input[name="pangkal"],input[name="potongan_spp_persen"]').count(),0);
    assert.equal(await page.locator('html').getAttribute('data-palette'),['super','sd','smp','sma'][scope]);
    assert.equal(await page.locator('html').getAttribute('data-theme'),theme);
    await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+2);
    for(const input of await page.locator('[data-date-display]').all()){const v=await input.inputValue();assert.ok(v===''||/^\d{2}\/\d{2}\/\d{4}$/.test(v),v);}
    if(routes[n].includes('siswa/daftar')){const discount=page.locator('#student-potongan-spp');assert.equal(await discount.getAttribute('type'),'text');assert.match(await page.locator('label[for="student-potongan-spp"]').innerText(),/Rp/);assert.equal(await page.locator('#nis-diknas').getAttribute('required'),null);}
    if(scope===1&&theme==='light'&&width===1440&&routes[n].includes('pembayaran/edit')){
     const display=page.locator('[data-date-display="tgl-bayar"]');await display.fill('31/04/2026');await display.blur();assert.equal(await display.evaluate(e=>e.checkValidity()),false);
     await display.fill('29/02/2024');await display.blur();assert.equal(await page.locator('#tgl-bayar').inputValue(),'2024-02-29');assert.equal(await display.evaluate(e=>e.checkValidity()),true);
     await page.locator('.id-date-picker').first().focus();assert.equal(await page.locator('.id-date-picker').first().evaluate(e=>document.activeElement===e),true);
    }
    if(n===0||routes[n].includes('pembayaran/edit'))await page.screenshot({path:path.join(dir,'scope-'+scope+'-'+theme+'-'+width+'-'+n+'.png'),fullPage:true});
    states++;
   }
  }
 }
 for(const scope of [1,2,3]){
  await page.goto(new URL('/dashboard.php',base).href);await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(scope))]);
  for(const route of ['/master_spp.php','/master_kelas.php','/master_daftar_ulang.php','/master_biaya_lain.php','/pembayaran/riwayat_daftar_ulang.php','/otorisasi_transaksi.php','/tabungan/masuk.php','/tabungan/keluar.php','/tabungan/riwayat.php','/tabungan/cetak.php','/laporan/global.php','/laporan/surat_laporan.php','/role_management.php']){
   const r=await page.goto(new URL(route,base).href);assert.equal(r.status(),200,route);assert.doesNotMatch(await page.content(),/Fatal error|Warning:|Unknown column/);
  }
 }
 const parse=await page.evaluate(()=>[sppIsoDate('01/02/2026'),sppIsoDate('29/02/2023'),sppDateLabel('2026-10-04T18:02:03Z',true),sppDateLabel('2026-10-05',true),sppDateLabel('2026-04-31T12:00:00Z',true)]);
 assert.deepEqual(parse,['2026-02-01','','05/10/2026 01:02:03 WIB','05/10/2026','—']);
 assert.match(await page.locator('#liveClock').innerText(),/^\d{2}:\d{2}:\d{2} WIB$/);assert.deepEqual(errors,[]);
 fs.writeFileSync(path.join(dir,'results.json'),JSON.stringify({states,scopes:4,themes:2,widths:[1440,2560,390],otherPages:39,errors},null,2));
 console.log('PASS: '+states+' visual states, 39 navigation pages, Indonesian inputs, no Pangkal, nominal preview/optional Diknas, WIB independent of browser timezone');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});

