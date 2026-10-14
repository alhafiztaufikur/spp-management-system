// Capture the real UI on a disposable, fictional-data database. No application POSTs.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const qa = process.env.SPP_MANUAL_QA;
assert.ok(qa);
assert.equal(process.env.SPP_DB_NAME, 'db_spp_audit_manual_20261014');
const server = JSON.parse(fs.readFileSync(path.join(qa,'http.json'),'utf8').replace(/^\uFEFF/,''));
const sessions = JSON.parse(fs.readFileSync(path.join(qa,'sessions.json'),'utf8'));
const base = new URL(server.url);
assert.equal(base.hostname,'127.0.0.1');
const output = path.resolve(__dirname,'../documentation/manual_assets');
fs.mkdirSync(output,{recursive:true});
const shots=[];
const selected=(process.env.SPP_MANUAL_SHOTS || '').split(',').filter(Boolean);
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const context=await browser.newContext({viewport:{width:1280,height:1000},deviceScaleFactor:1});
  const identity=await context.request.get(new URL('tests/browser_clone_identity.php',base).href);
  assert.equal(identity.status(),200,'HTTP server must explicitly expose its disposable test identity');
  assert.equal((await identity.json()).database,process.env.SPP_DB_NAME,'HTTP server must use the fictional-data database');
  await context.addCookies([{name:'PHPSESSID',value:sessions.super_admin,url:base.href}]);
  const page=await context.newPage();
  page.on('dialog',dialog=>dialog.dismiss());
  await page.addInitScript(()=>localStorage.setItem('spp_theme','light'));
  // Prevent accidental clicks from starting financial, backup, restore or legacy work.
  await page.route('**/*',route=>route.request().method()==='POST' ? route.abort() : route.continue());
  async function shot(name,route,prepare,markers=[]) {
   if(selected.length && !selected.includes(name))return;
   const response=await page.goto(new URL(route,base).href);assert.equal(response.status(),200,route);
   await page.waitForLoadState('networkidle');await page.evaluate(()=>document.fonts.ready);
   assert.ok(!(await page.locator('body').innerText()).includes('Fatal error'),route);
   await page.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}'});
   await page.evaluate(()=>{const c=document.getElementById('liveClock');if(c)c.textContent='10:00:00 WIB';});
   if(prepare)await prepare(page);
   await page.evaluate(selectors=>{
    document.querySelectorAll('.manual-capture-marker').forEach(n=>n.remove());
    selectors.forEach((selector,index)=>{const node=document.querySelector(selector);if(!node)return;const b=node.getBoundingClientRect();if(b.bottom<90||b.top>920)return;const label=document.createElement('div');label.className='manual-capture-marker';label.textContent=index+1;Object.assign(label.style,{position:'fixed',left:Math.max(0,b.left-10)+'px',top:Math.max(92,b.top-10)+'px',width:'27px',height:'27px',borderRadius:'50%',background:'#0b5e45',border:'2px solid white',color:'white',font:'bold 16px Arial',textAlign:'center',lineHeight:'23px',zIndex:2147483647,boxShadow:'0 1px 4px #555'});document.body.append(label);});
   },markers);
   const main=await page.locator('.main-content').boundingBox();
   let clip=main?{x:Math.ceil(main.x+12),y:92,width:Math.floor(main.width-24),height:450}:{x:170,y:80,width:940,height:600};
   if(name==='24-pemulihan-selesai') {
    const first=await page.locator('#restore-progress-panel').boundingBox();
    const last=await page.locator('#restore-result').boundingBox();
    clip={x:Math.floor(first.x-6),y:Math.floor(first.y-6),width:Math.ceil(first.width+12),height:Math.ceil(last.y+last.height-first.y+12)};
   }
   await page.screenshot({path:path.join(output,name+'.png'),clip});shots.push({name,route});
  }
  await shot('01-dashboard','dashboard.php',null,['.dashboard-unit-switch-form','.closing-hero','.dashboard-actions']);
  await shot('02-siswa','siswa/daftar.php',async p=>{await p.locator('#form-master-siswa').scrollIntoViewIfNeeded();await p.evaluate(()=>scrollBy(0,-110));},['#form-master-siswa','#student-komite-start','#student-potongan-spp']);
  await shot('03-kelas','master_kelas.php',null,['.master-filter-card','.master-classes-grid']);
  await shot('04-kenaikan','master_kelas.php?source_level=7',async p=>{await p.locator('#promotion-batch-form').evaluate(n=>scrollTo(0,scrollY+n.getBoundingClientRect().top-110));},['#promotion-batch-form']);
  await shot('05-spp','master_spp.php?tahun=2026%2F2027',null,['[data-spp-tab="rates"]','[data-spp-tab="students"]','[data-spp-tab="year"]']);
  await shot('06-spp-tarif','master_spp.php?tahun=2026%2F2027',async p=>p.locator('[data-spp-tab="rates"]').first().click(),['#spp-rate-panel']);
  await shot('07-biaya','master_biaya_lain.php',null,['.master-modern-form']);
  await shot('08-daftar-ulang','master_daftar_ulang.php?tahun=2026%2F2027',null,['.du-rate-grid']);
  await shot('09-pembayaran','pembayaran/form.php',null,['#search-siswa']);
  await shot('10-riwayat','pembayaran/lihat.php?tanggal_awal=2026-10-14&tanggal_akhir=2026-10-14',null,[]);
  await shot('11-riwayat-du','pembayaran/riwayat_daftar_ulang.php',null,[]);
  await shot('12-otorisasi','otorisasi_transaksi.php?tab=history',null,[]);
  await shot('13-tabungan-masuk','tabungan/masuk.php',null,[]);
  await shot('14-tabungan-keluar','tabungan/keluar.php',null,[]);
  await shot('15-tabungan-riwayat','tabungan/riwayat.php',null,[]);
  await shot('16-tabungan-cetak','tabungan/cetak.php',null,[]);
  await shot('17-laporan-transaksi','laporan/index.php',null,[]);
  await shot('18-katalog','laporan/global.php?unit=2',null,[]);
  await shot('19-matriks','laporan/template.php?template=status&unit=2&kategori%5B%5D=spp&kategori%5B%5D=komite&tahun_ajaran=2026%2F2027&bulan_awal=10',async p=>{const table=p.locator('table').first();await table.scrollIntoViewIfNeeded();await p.evaluate(()=>scrollBy(0,-120));},[]);
  await shot('20-surat','laporan/surat_laporan.php',null,[]);
  await shot('25-susun-surat','laporan/surat_orang_tua_susun.php?mode=all',async p=>{await p.locator('.parent-compose-layout').scrollIntoViewIfNeeded();await p.evaluate(()=>scrollBy(0,-110));},['.parent-recipients','#parent-message']);
  await shot('26-penempatan-psb','master_kelas.php?source_level=psb',async p=>{await p.locator('#psb-placement-form').evaluate(n=>scrollTo(0,scrollY+n.closest('section').getBoundingClientRect().top-110));},['#psb-placement-form']);
  await shot('21-akun','role_management.php',null,[]);
  await shot('22-backup','backup_restore.php',async p=>p.locator('.dbt-settings').scrollIntoViewIfNeeded(),[]);
  await shot('23-legacy','backup_restore.php',async p=>p.locator('#tab-legacy').click(),[]);
  // Completion illustration uses the current controller, with an explicitly synthetic job.
  await page.route('**/backup_restore.php',async route=>{const response=await route.fetch();let html=await response.text();html=html.replace(/(<script type="application\/json" id="database-restore-config">)([\s\S]*?)(<\/script>)/,(_,a,b,c)=>{const cfg=JSON.parse(b);cfg.job={id:'a'.repeat(32),token:'b'.repeat(64)};return a+JSON.stringify(cfg)+c;});return route.fulfill({response,body:html});});
  const illustration={id:'a'.repeat(32),name:'Cadangan_Contoh.sql',bytes:1024,state:'completed',finalized:true,message:'Pemulihan contoh selesai.',expires_at:Math.floor(Date.now()/1000)+3600,finished_at:'2026-10-14T10:00:00+07:00'};
  await page.route('**/restore_status.php*',route=>route.fulfill({json:{job:illustration}}));
  await page.route('**/restore_api.php*',route=>route.fulfill({json:{job:illustration}}));
  await shot('24-pemulihan-selesai','backup_restore.php',async p=>{await p.waitForSelector('#restore-result:not([hidden])',{state:'attached'});await p.locator('#restore-dialog').evaluate(n=>{if(!n.open)n.showModal();});},[]);
  fs.writeFileSync(path.join(qa,selected.length?'capture-results-selected.json':'capture-results.json'),JSON.stringify({database:process.env.SPP_DB_NAME,shots,syntheticCompletion:shots.some(s=>s.name==='24-pemulihan-selesai')},null,2));
  console.log(`PASS: ${shots.length} screenshot UI; hanya data fiktif, POST aplikasi diblokir.`);
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
