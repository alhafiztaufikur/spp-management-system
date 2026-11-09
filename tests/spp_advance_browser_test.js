'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME,/^db_spp_audit_/);
const file=process.env.SPP_ADVANCE_FIXTURE,root=path.dirname(file),fixture=JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,'')),base=process.env.SPP_TEST_BASE_URL;
const futureStart=Number(fixture.future.slice(0,4)),futureEnd=futureStart+1;
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);assert.equal(fixture.database,process.env.SPP_DB_NAME);
const state=()=>JSON.parse(execFileSync(process.env.SPP_PHP_BIN,[path.join(__dirname,'spp_advance_browser_fixture.php'),'state',file],{env:process.env,encoding:'utf8'}));
let checks=0;const check=(ok,why)=>{assert.ok(ok,why);checks++;fs.writeFileSync(path.join(root,'browser-progress.json'),JSON.stringify({checks,last:why}));};
async function dismiss(page){for(const selector of ['#operation-feedback-dialog[open]','#spp-warning-overlay.show'])if(await page.locator(selector).isVisible())await page.locator(selector).getByRole('button',{name:'Mengerti',exact:true}).click();}
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[];
try{
 for(const test of (process.env.SPP_ADVANCE_SCOPE_ONLY==='1'?[]:fixture.cases)){
  const context=await browser.newContext({viewport:{width:1440,height:1000},extraHTTPHeaders:{'X-SPP-Test-Current-Year':fixture.year},acceptDownloads:true});
  await context.addCookies([{name:'PHPSESSID',value:test.session,url:base}]);await context.route('https://fonts.googleapis.com/**',r=>r.abort());
  check((await(await context.request.get(base+'/tests/browser_clone_identity.php')).json()).database===fixture.database,'Owned HTTP clone');
  const page=await context.newPage();page.setDefaultTimeout(120000);page.setDefaultNavigationTimeout(120000);page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/master_spp.php?tahun='+encodeURIComponent(fixture.future));await dismiss(page);
  check(await page.locator('.spp-publish-row[data-student-id="'+test.id+'"]').count()===1,'Projected pupil visible before placement');
  await page.locator('#spp-tab-rates').click();
  if(await page.locator('#spp-rate-edit').isVisible())await page.locator('#spp-rate-edit').click();
  for(let grade=test.first+1;grade<=test.last;grade++)await page.locator('[name="jumlah['+grade+']"]').fill('325000');
  check((await page.locator('#spp-rate-save').innerText()).includes('Simpan Tarif & Lengkapi Tagihan'),'Existing save action explains issuance');
  await page.locator('#spp-rate-save').click();await page.locator('#spp-warning-overlay.show').waitFor();
  check((await page.locator('#spp-warning-message').innerText()).includes('seluruh siswa'),'Existing modal confirms whole roster');
  if(test.unit===1&&test.role==='super_admin'){
    await page.setViewportSize({width:390,height:900});await page.evaluate(()=>document.documentElement.dataset.theme='dark');
    await page.waitForFunction(()=>getComputedStyle(document.getElementById('spp-warning-overlay')).opacity==='1');
    await page.locator('#spp-warning-overlay').screenshot({path:path.join(root,'advance-modal-mobile-dark.png')});
    await page.locator('#spp-warning-close').focus();check(await page.locator('#spp-warning-close').evaluate(el=>el===document.activeElement),'Modal keyboard focus');
    await page.setViewportSize({width:1440,height:1000});
  }
  await Promise.all([page.waitForNavigation(),page.locator('#spp-warning-close').click()]);
  const before=state()[test.unit][test.role];check(before.bills.filter(b=>Number(b.tahun)===futureStart&&Number(b.bulan)>=7||Number(b.tahun)===futureEnd).length===12,'Save creates twelve future SPP periods');
  check(before.bills.filter(b=>b.penempatan_id===null).length===12,'Save creates no academic placement');
  check(await page.locator('[data-sct-summary="pending"]').innerText()==='0','Whole roster complete, independent of selection');
  for(const width of [1440,390])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:1000});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
    await page.waitForFunction(()=>document.getElementById('spp-rate-form').getBoundingClientRect().right<=innerWidth);
    check(await page.locator('#spp-rate-form').evaluate(el=>el.getBoundingClientRect().right<=innerWidth),'Master form remains inside viewport');
    if(test.unit===1&&test.role==='super_admin')await page.screenshot({path:path.join(root,'advance-master-'+width+'-'+theme+'.png'),fullPage:true});
  }
  await page.goto(base+'/pembayaran/form.php');await dismiss(page);const original=await page.locator('#tahun-bayar').inputValue();
  await page.locator('#siswa-search').fill(test.nis);await page.evaluate(nis=>{const input=document.getElementById('siswa-search'),opt=[...document.getElementById('siswa-list').options].find(o=>o.dataset.nis===nis);pilihSiswaDatalist(input,opt);},test.nis);
  await page.waitForFunction(()=>sppStatusState.payload&&!sppStatusState.pending);await dismiss(page);check(await page.locator('#tahun-bayar').inputValue()===original,'Regular pupil does not auto-switch to future year');
  await page.locator('#tahun-bayar').click();await page.locator('.payment-year-option[data-year="'+futureStart+'"]').click();await page.locator('#bulan-bayar').selectOption('07',{force:true});await page.locator('#tahun-bayar').dispatchEvent('change');
  await page.waitForFunction(()=>!document.getElementById('spp-input').readOnly);await dismiss(page);
  await page.locator('#spp-input').fill('325000');await page.locator('#komite-input').fill('15000');await page.locator('#sistem-pembayaran').selectOption('Tunai',{force:true});
  const payment=await page.locator('#form-bayar').evaluate(form=>Object.fromEntries(new FormData(form)));
  check((await context.request.post(base+'/pembayaran/proses.php',{form:payment,maxRedirects:0})).status()===302,'Existing payment endpoint accepts future pair');
  let paid=state()[test.unit][test.role];check(paid.payments.length===13,'One future cash receipt added');
  check(paid.payments.at(-1).KELAS===String(test.grade),'Cash keeps original class');
  await context.request.post(base+'/pembayaran/proses.php',{form:payment,maxRedirects:0});check(state()[test.unit][test.role].payments.length===13,'Repeated request adds no cash');
  for(const kind of ['spp','komite']){
    const query='template=per-item&kategori='+kind+'&student_id='+test.id+'&bulan_awal=07&tahun_awal='+futureStart+'&bulan_akhir=06&tahun_akhir='+futureEnd;
    check((await context.request.get(base+'/laporan/template.php?'+query)).status()===200,'Report before placement');
    if(test.unit===1&&test.role==='super_admin')for(const [type,magic] of [['pdf','%PDF'],['excel','PK']]){
      const response=await context.request.get(base+'/laporan/export_global.php?'+query+'&format='+type+'&download=1',{timeout:120000});const body=await response.body();check(response.status()===200&&body.subarray(0,magic.length).toString()===magic,'Actual '+kind+' '+type+' export');fs.writeFileSync(path.join(root,'advance-'+kind+'.'+(type==='excel'?'xlsx':'pdf')),body);
    }
  }
  await context.setExtraHTTPHeaders({'X-SPP-Test-Current-Year':fixture.future});
  await page.goto(base+'/master_kelas.php?source_level='+test.grade+'&source_year_id='+test.source_year_id);await dismiss(page);
  const data=await page.locator('#promotion-batch-form').evaluate(form=>Object.fromEntries(new FormData(form)));
  const promoted=await context.request.post(base+'/master_kelas.php',{form:{csrf_token:data.csrf_token,_operational_unit:String(test.unit),aksi:'naikkan_siswa',source_year_id:data.source_year_id,source_level:String(test.grade),target_tahun_ajaran:fixture.future,no_induk:test.nis,source_placement_id:String(test.origin),target_master_kelas_id:String(test.target)},maxRedirects:0});
  check(promoted.status()===302,'Existing promotion endpoint accepted');
  const after=state()[test.unit][test.role];assert.deepEqual(after.bills.map(b=>b.id),paid.bills.map(b=>b.id));checks++;
  check(after.bills.every(b=>b.penempatan_id!==null),'Promotion binds original invoices');assert.deepEqual(after.payments,paid.payments);checks++;
  for(const kind of ['spp','komite'])check((await context.request.get(base+'/laporan/template.php?template=per-item&kategori='+kind+'&bulan_awal=07&tahun_awal='+futureStart+'&bulan_akhir=06&tahun_akhir='+futureEnd+'&student_id='+test.id)).status()===200,'Reports after placement');
  console.log('PASS GUI '+test.role+' unit '+test.unit);await context.close();
 }
 const all=await browser.newContext({extraHTTPHeaders:{'X-SPP-Test-Current-Year':fixture.year}});await all.addCookies([{name:'PHPSESSID',value:fixture.cases[0].session,url:base}]);const allPage=await all.newPage();await allPage.goto(base+'/master_spp.php');const switchForm=await allPage.locator('.sidebar-unit-form').evaluate(form=>Object.fromEntries(new FormData(form)));
 check((await all.request.post(base+'/unit_switch.php',{form:{...switchForm,unit_id:'0',next:'/master_spp.php'},maxRedirects:0})).status()===303,'All Units scoped switch');
 await allPage.goto(base+'/master_spp.php');check(await allPage.locator('#spp-rate-form').count()===0,'All Units remains a read-only overview');check((await all.request.post(base+'/master_spp.php',{form:{aksi:'simpan_tarif',_operational_unit:'0'},maxRedirects:0})).status()===409,'All Units cannot issue');
 check((await all.request.get(base+'/laporan/template.php?template=spp-tahunan&tahun_ajaran='+encodeURIComponent(fixture.future)+'&q='+fixture.cases[0].nis)).status()===200,'All Units target report readable');await all.close();
 assert.deepEqual(errors,[]);checks++;fs.writeFileSync(path.join(root,process.env.SPP_ADVANCE_SCOPE_ONLY==='1'?'browser-scope-result.json':'browser-result.json'),JSON.stringify({passed:true,checks,cases:process.env.SPP_ADVANCE_SCOPE_ONLY==='1'?0:fixture.cases.length},null,2));console.log('PASS '+checks+' GUI, HTTP, export and promotion checks');
}catch(error){fs.writeFileSync(path.join(root,'browser-failure.txt'),error.stack);throw error;}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
