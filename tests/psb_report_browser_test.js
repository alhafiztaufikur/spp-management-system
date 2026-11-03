/* Read-only navigation and export checks against a selected local audit clone. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,dir=process.env.SPP_PSB_ARTIFACTS;
assert.match(base||'',/^http:\/\/(127\.0\.0\.1|localhost):\d+$/);
assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_[a-z0-9_]+$/);
assert.ok(dir&&!path.resolve(dir).toLowerCase().startsWith(process.cwd().toLowerCase()));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const checks=[],errors=[];
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}});page.setDefaultTimeout(60000);
  page.on('pageerror',e=>errors.push(e.message));
  page.on('request',r=>{if(r.method()==='POST'&&!/\/(login|unit_switch)\.php$/.test(r.url()))errors.push('Unexpected mutation '+r.url());});
  const identity=await(await page.request.get(base+'/tests/browser_clone_identity.php')).json();assert.equal(identity.database,process.env.SPP_DB_NAME);
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  for(const width of [1280,1440,1920])for(const theme of ['light','dark']){
   await page.setViewportSize({width,height:1000});await page.evaluate(t=>localStorage.setItem('spp_theme',t),theme);
   await page.goto(base+'/laporan/global.php?unit=1');
   assert.deepEqual(await page.locator('.report-catalog-section').first().locator('h3').allTextContents(),['Pembayaran PSB','Riwayat Tagihan Siswa','Rekap Penerimaan Harian','Rekap SPP Tahun Ajaran per Kelas','Rekap Pembayaran per Item','Laporan Status Pembayaran']);
   assert.equal(await page.locator('.report-catalog-section').first().locator('.report-catalog-count').innerText(),'6 laporan');
   assert.doesNotMatch(await page.locator('body').innerText(),/Pilih area kerja terlebih dahulu|jenis laporan siap digunakan/);assert.equal(await page.locator('.report-catalog-overview strong').innerText(),String(await page.locator('.report-template-card').count()));
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
   await page.screenshot({path:path.join(dir,`catalogue-${width}-${theme}.png`),fullPage:true});
   await page.locator('.report-catalog-section').first().locator('.report-template-link').first().click();await page.waitForLoadState('networkidle');
   assert.equal(new URL(page.url()).searchParams.get('template'),'psb');
   assert.deepEqual(await page.locator('.report-horizontal-matrix thead th').allTextContents(),['No','Kelas','No. Induk','Nama Siswa','JUMLAH','U_PSB','SISA']);
   assert.equal(await page.locator('.recap-report-copy>p,.report-result-pill').count(),0,'PSB explanatory subtitle and result badge removed');
   assert.deepEqual(await page.locator('.report-total-grid .report-summary-card>span').allTextContents(),['JUMLAH','U_PSB','SISA']);
   for(const name of ['kelas','status','siswa_status','per_page'])assert.equal(await page.locator('select[name^='+name+']').count(),1,name+' picker');
   assert.equal(await page.locator('select[name^=siswa_status]').inputValue(),'active');
   assert.equal(await page.locator('select[name=kategori],select[name=tahun_ajaran],input[name=tanggal_awal],input[name=tanggal_akhir]').count(),0);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'PSB workspace must not overflow');
   assert.ok(await page.locator('.report-matrix-scroll').evaluate(e=>e.scrollWidth<=e.clientWidth+2),'All three PSB amounts fit the desktop table');
   const otherFonts=await page.evaluate(()=>[...document.querySelectorAll('main *')].filter(e=>e instanceof HTMLElement&&e.getClientRects().length&&[...e.childNodes].some(n=>n.nodeType===3&&n.textContent.trim())&&!getComputedStyle(e).fontFamily.includes('Segoe UI')).map(e=>e.className));assert.deepEqual(otherFonts,[]);
   for(const select of ['kelas','status','siswa_status']){const trigger=page.locator('.spp-select:has(>select[name^='+select+']) .spp-select-trigger');await trigger.click();await page.locator('[data-spp-floating-open]').waitFor();await page.keyboard.press('Escape');}
   await page.screenshot({path:path.join(dir,`psb-${width}-${theme}.png`),fullPage:true});checks.push({width,theme});
   await page.goto(base+'/laporan/surat_laporan.php');
   assert.equal(await page.locator('.report-catalog-overview strong').innerText(),'2');
   assert.doesNotMatch(await page.locator('body').innerText(),/Buat surat tunggakan untuk orang tua atau rekap resmi|jenis surat tersedia/);
   assert.ok(await page.locator('.report-catalog-overview').evaluate(e=>e.getBoundingClientRect().left>document.querySelector('.report-catalog-intro-copy').getBoundingClientRect().right));
   await page.screenshot({path:path.join(dir,`letters-${width}-${theme}.png`),fullPage:true});
  }
  if(process.env.SPP_PSB_UI_ONLY==='1'){
   assert.deepEqual(errors,[]);fs.writeFileSync(path.join(dir,'desktop-results.json'),JSON.stringify({checks,errors},null,2));console.log('PASS six desktop viewport/theme cases; PSB amounts fit without horizontal scrolling.');return;
  }
  for(const unit of ['1','2','3','all']){
   await page.goto(base+'/laporan/template.php?template=psb&unit='+unit+'&per_page=25');
   assert.equal(await page.locator('.alert-error').count(),0);
   const count=await page.locator('.report-horizontal-matrix tbody tr').count();assert.ok(count<=25);
   const headings=await page.locator('.report-horizontal-matrix thead th').allTextContents();assert.equal(headings.includes('Unit'),unit==='all');
   const params=new URLSearchParams({template:'psb',unit,per_page:'25',format:'excel',download:'1'});
   const response=await page.request.get(base+'/laporan/export_global.php?'+params,{timeout:120000});assert.equal(response.status(),200);const bytes=await response.body();assert.equal(bytes.subarray(0,2).toString(),'PK');fs.writeFileSync(path.join(dir,'http-'+unit+'.xlsx'),bytes);
   checks.push({unit,rowsOnPage:count});
  }
  await page.goto(base+'/laporan/export_global.php?template=psb&unit=1&format=excel');
  assert.deepEqual(await page.frameLocator('iframe').first().locator('table thead th').allTextContents(),['No','Kelas','No. Induk','Nama Siswa','JUMLAH','U_PSB','SISA'],'Excel preview omits NIS Diknas');
  await page.screenshot({path:path.join(dir,'psb-excel-preview.png'),fullPage:false});
  // Two scoped students keep HTTP PDF checks small while exercising the real output route.
  const models=JSON.parse(fs.readFileSync(path.join(dir,'models.json'),'utf8')).models;
  const sample=models['1'].report.rows.find(r=>r.tagihan!==null);assert.ok(sample);
  const params=new URLSearchParams({template:'psb',unit:'1',q:sample.nis,format:'pdf',download:'1'});
  const pdf=await page.request.get(base+'/laporan/export_global.php?'+params,{timeout:120000});assert.equal(pdf.status(),200);const bytes=await pdf.body();assert.equal(bytes.subarray(0,5).toString(),'%PDF-');assert.ok(bytes.includes(Buffer.from('DejaVuSerif')));fs.writeFileSync(path.join(dir,'http-psb.pdf'),bytes);
  params.set('format','print');params.delete('download');const print=await page.request.get(base+'/laporan/export_global.php?'+params,{timeout:120000});assert.equal(print.status(),200);assert.equal((await print.body()).subarray(0,5).toString(),'%PDF-');
  params.set('format','preview');await page.goto(base+'/laporan/export_global.php?'+params);await page.waitForLoadState('networkidle');
  const frame=page.frameLocator('iframe').first();await frame.locator('.psb-table').waitFor();assert.match(await frame.locator('body').evaluate(e=>getComputedStyle(e).fontFamily),/DejaVu Serif/);await page.screenshot({path:path.join(dir,'psb-print-preview.png'),fullPage:true});
  assert.equal(await frame.locator('.psb-heading p').count(),0);
  assert.deepEqual(await frame.locator('.psb-table thead th').allTextContents(),['NO','KELAS','NO. INDUK','NAMA SISWA','JUMLAH','U_PSB','SISA']);
  assert.doesNotMatch(await frame.locator('body').innerText(),/Status siswa:|Dibuat:|Petugas:|Nominal dalam Rupiah/);
  assert.equal(await page.locator('[data-preview-generated]').count(),1,'Preview toolbar metadata remains');
  // Existing reports still render their original matrices and labels.
  for(const template of ['status','per-item','riwayat-tagihan','penerimaan','spp-tahunan']){await page.goto(base+'/laporan/template.php?template='+template+'&unit=1');assert.equal(await page.locator('.alert-error').count(),0);assert.ok(await page.locator('h1').count());}
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(dir,'browser-results.json'),JSON.stringify({checks,errors},null,2));console.log('PASS desktop catalogue/order/fonts, PSB dropdowns, four unit scopes, full Excel exports, preview/print/PDF and existing reports.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
