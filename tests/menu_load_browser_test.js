/* Read-only navigation and dropdown checks on an explicitly owned clone. */
const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const root=process.env.SPP_QA_DIR,base=process.env.SPP_TEST_BASE_URL;
const manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8').replace(/^\uFEFF/,''));
assert.equal(manifest.isolated_test,true);assert.notEqual(manifest.port,3306);assert.notEqual(manifest.server_uuid,manifest.source_server_uuid);
assert.equal(new URL(base).hostname,'127.0.0.1');
const sessions=JSON.parse(fs.readFileSync(root+'/sessions.json','utf8'));
const routes=['dashboard.php','pembayaran/form.php','pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php','otorisasi_transaksi.php','siswa/daftar.php','master_kelas.php','master_spp.php','master_biaya_lain.php','master_daftar_ulang.php','tabungan/masuk.php','tabungan/keluar.php','tabungan/riwayat.php','tabungan/cetak.php','laporan/index.php','laporan/global.php','laporan/surat_laporan.php','role_management.php','backup_restore.php'];
const templates=['status','penerimaan','spp-tahunan','per-item','riwayat-tagihan','tabungan-siswa','saldo-tabungan','setoran','kas-tabungan'];
routes.push('laporan/surat_orang_tua.php');templates.push('tunggakan-siswa');
routes.push(...templates.map(t=>'laporan/template.php?template='+t+'&tahun_ajaran=2026%2F2027&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-27'));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const checks=[],errors=[];
 try{
  for(const [width,theme] of [[1440,'light'],[390,'dark']]){
   const c=await browser.newContext({viewport:{width,height:900}});
   await c.addCookies([{name:'PHPSESSID',value:sessions[1][3],url:base}]);
   await c.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
   const identity=await (await c.request.get(base+'/tests/browser_clone_identity.php')).json();assert.equal(identity.server_uuid,manifest.server_uuid);assert.equal(identity.port,manifest.port);
   const p=await c.newPage();p.setDefaultTimeout(120000);p.on('pageerror',e=>errors.push(String(e)));
   for(const route of routes){
    const start=Date.now();const response=await p.goto(base+'/'+route,{waitUntil:'domcontentloaded'});assert.equal(response.status(),200,route);
    assert(!/Fatal error|Uncaught (?:Error|Exception)|SQLSTATE/.test(await p.locator('body').innerText()),route);
    const controls=p.locator('.spp-select-trigger:not(:disabled),[data-class-picker-button],.du-selector-trigger:not(:disabled),.report-date-range-button');
    let opened=0;
    for(let n=0;n<Math.min(await controls.count(),3);n++){
     const control=controls.nth(n);if(!await control.isVisible())continue;
     await control.scrollIntoViewIfNeeded();await control.click();await p.keyboard.press('Escape');opened++;
    }
    checks.push({width,theme,route,controls:opened,ready_ms:Date.now()-start});
    fs.writeFileSync(root+'/browser-progress.json',JSON.stringify({checks,errors},null,2));
   }
   await p.goto(base+'/master_daftar_ulang.php');await p.screenshot({path:root+'/master-du-'+width+'-'+theme+'.png',fullPage:true});
   await c.close();
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(root+'/browser-results.json',JSON.stringify({passed:checks.length,checks,errors},null,2));console.log('PASS '+checks.length+' page/theme/viewport checks; dropdown interactions and no JavaScript errors.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
