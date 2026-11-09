'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME,/^db_spp_audit_/);
const fixturePath=process.env.SPP_PSB_FIXTURE,root=path.dirname(fixturePath),f=JSON.parse(fs.readFileSync(fixturePath,'utf8')),base=process.env.SPP_TEST_BASE_URL;
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);assert.equal(f.database,process.env.SPP_DB_NAME);
const state=()=>JSON.parse(execFileSync(process.env.SPP_PHP_BIN,[path.join(__dirname,'psb_spp_browser_fixture.php'),'state'],{env:process.env,encoding:'utf8'}));
let checks=0;const eq=(a,b,label)=>{assert.deepEqual(a,b,label);checks++;};
async function dismiss(page){for(const selector of ['#operation-feedback-dialog[open]','#spp-warning-overlay.show']){const dialog=page.locator(selector);if(await dialog.isVisible())await dialog.getByRole('button',{name:'Mengerti',exact:true}).click();}}
async function values(page){return page.locator('#form-master-siswa').evaluate(form=>Object.fromEntries(new FormData(form)));}
async function post(context,url,data){return context.request.post(base+url,{form:data,maxRedirects:0,timeout:120000});}
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[];
try{
 for(const role of (process.env.SPP_PSB_SCOPE_ONLY==='1'?[]:['super_admin','admin']))for(const unit of [1,2,3]){
  const context=await browser.newContext({viewport:{width:1440,height:1000},extraHTTPHeaders:{'X-SPP-Test-Current-Year':'2026/2027'}});
  await context.addCookies([{name:'PHPSESSID',value:f.sessions[role][unit],url:base}]);await context.route('https://fonts.googleapis.com/**',r=>r.abort());
  eq((await (await context.request.get(base+'/tests/browser_clone_identity.php')).json()).database,f.database,'Owned HTTP clone');
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  page.setDefaultTimeout(120000);page.setDefaultNavigationTimeout(120000);
  await page.goto(base+'/siswa/daftar.php?reset_form=1');await dismiss(page);eq(await page.locator('#student-pomg').inputValue(),'','Komite starts blank');
  await page.locator('[data-class-picker-button]').click();await page.locator('[data-class-picker-option][data-value="'+f.units[unit].psb+'"]').click();
  eq(await page.locator('#student-admission-year').innerText(),'2027/2028','Next year inline');eq(await page.locator('#student-spp_masuk').inputValue(),'','SPP starts blank');
  const nis='998811'+(process.env.SPP_PSB_RUN||'23')+(role==='super_admin'?'01':'02');
  await page.locator('#nis-baru').fill(nis);await page.locator('#nama-baru').fill('QA PSB '+role+' '+unit);await page.locator('#student-psb').fill('5000000');
  await page.locator('#student-submit-form').click();await page.locator('#spp-warning-overlay.show').waitFor();eq((await page.locator('#spp-warning-message').innerText()).includes('SPP'),true,'Missing SPP modal');
  await page.locator('#spp-warning-close').click();eq(await page.locator('#student-spp_masuk').evaluate(el=>el===document.activeElement),true,'Focus restored to SPP');
  await page.locator('#student-spp_masuk').fill('325000,50');await page.locator('#student-submit-form').click();await page.locator('#spp-warning-overlay.show').waitFor();eq((await page.locator('#spp-warning-message').innerText()).includes('Komite'),true,'Missing Komite modal');await page.locator('#spp-warning-close').click();
  await page.locator('#student-pomg').fill('15000');await page.locator('#student-komite-start').selectOption('07',{force:true});
  if(unit===1&&role==='super_admin'){
   await page.locator('#student-spp_masuk').fill('-1');await page.locator('#student-submit-form').click();await page.locator('#spp-warning-overlay.show').waitFor();eq(await page.locator('#student-spp_masuk').inputValue(),'-1','Invalid value preserved for correction');await dismiss(page);await page.locator('#student-spp_masuk').fill('325000,50');
   const data=await values(page);const bad=await post(context,'/siswa/daftar.php',{...data,spp_masuk:''});eq(bad.status(),302,'Server rejects missing tariff without JS');
   await page.goto(base+'/siswa/daftar.php');await page.locator('#spp-warning-overlay.show').waitFor();eq(await page.locator('#nama-baru').inputValue(),'QA PSB '+role+' '+unit,'Failed POST preserves draft');await dismiss(page);await page.locator('#student-spp_masuk').fill('325000,50');
  }
  const replay=await values(page);
  await Promise.all([page.waitForURL(url=>url.pathname.endsWith('/siswa/daftar.php')),page.locator('#student-submit-form').click()]);
  let s=state()[unit]?.[nis];eq(!!s,true,'Student saved');eq(s.bills.length,12,'Twelve native SPP bills');eq(s.plan.label,'2027/2028','Plan persisted');eq(s.bills.every(b=>b.penempatan_id===null),true,'No temporary placement');
  const id=s.id;await post(context,'/siswa/daftar.php',replay);eq(state()[unit][nis].bills.length,12,'Repeated registration does not duplicate bills');
  await page.goto(base+'/master_spp.php?tahun=2027%2F2028');eq(await page.locator('[name="jumlah['+f.units[unit].first+']"]').count(),0,'Generic first grade field removed');eq(await page.locator('.spp-publish-row[data-student-id="'+id+'"]').count(),1,'PSB visible in Master SPP');
  await page.goto(base+'/pembayaran/form.php');
  await page.locator('#siswa-search').fill(nis);
  await page.evaluate(nis=>{const input=document.getElementById('siswa-search'),option=[...document.getElementById('siswa-list').options].find(o=>o.dataset.nis===nis);pilihSiswaDatalist(input,option);},nis);
  await page.waitForFunction(()=>document.getElementById('tahun-bayar').value==='2027'&&document.getElementById('bulan-bayar').value==='07'&&!document.getElementById('spp-input').readOnly);
  eq(await page.locator('#komite-input').getAttribute('readonly'),null,'Komite can be paid while PSB');await dismiss(page);
  await page.locator('#spp-input').fill('325.000,50');await page.locator('#komite-input').fill('15.000');
  await page.locator('#sistem-pembayaran').selectOption('Tunai',{force:true});
  const payment=await page.locator('#form-bayar').evaluate(form=>Object.fromEntries(new FormData(form)));
  const saved=await post(context,'/pembayaran/proses.php',payment);eq(saved.status(),302,'PSB payment endpoint accepts monthly pair');
  s=state()[unit][nis];eq(s.payments.length,1,'Exactly one native payment');eq(Number(s.payments[0].U_SPP),325000.50,'SPP cash retained');eq(s.payments[0].riwayat_kelas_snapshot,'PSB','Receipt keeps PSB context');
  await post(context,'/pembayaran/proses.php',payment);eq(state()[unit][nis].payments.length,1,'Request key prevents duplicate money');
  for(const kind of ['spp','komite']){
    const report=await context.request.get(base+'/laporan/global.php?template=per-item&kategori='+kind+'&student_id='+id+'&bulan_awal=07&tahun_awal=2027&bulan_akhir=06&tahun_akhir=2028');eq(report.status(),200,'Report before placement');
  }
  await context.setExtraHTTPHeaders({'X-SPP-Test-Current-Year':'2027/2028'});
  if(role==='super_admin'){
    await page.goto(base+'/master_kelas.php?source_level=psb');const csrf=await page.locator('#psb-placement-form [name=csrf_token]').inputValue();
    const placed=await post(context,'/master_kelas.php',{csrf_token:csrf,_operational_unit:String(unit),aksi:'tempatkan_psb','selected_students[]':nis,['target_master_kelas_id['+nis+']']:String(f.units[unit].entry),target_tahun_ajaran:'2027/2028'});eq(placed.status(),302,'Master Kelas placement accepted');
  }else{
    await page.goto(base+'/siswa/daftar.php?edit='+id);await dismiss(page);await page.locator('[data-class-picker-button]').click();await page.locator('[data-class-picker-option][data-value="'+f.units[unit].entry+'"]').click();
    await Promise.all([page.waitForURL(url=>!url.searchParams.has('edit')),page.locator('#student-submit-form').click()]);
  }
  const after=state()[unit][nis];eq(after.bills.map(b=>b.id),s.bills.map(b=>b.id),'Same bill IDs after both admission routes');eq(after.bills.every(b=>b.penempatan_id!==null),true,'Plan linked to real placement');eq(after.payments,s.payments,'Placement does not rewrite money or original context');
  await page.goto(base+'/siswa/daftar.php?edit='+id);await dismiss(page);
  for(const width of [1440,390])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:1000});await page.evaluate(t=>{document.documentElement.dataset.theme=t;localStorage.setItem('spp_theme',t);},theme);
    eq(await page.locator('#student-spp_masuk').isVisible(),true,'Tariff readable on both screen sizes/themes');
    eq(await page.locator('#student-spp_masuk').evaluate(el=>{const b=el.getBoundingClientRect();return b.width>100&&b.x>=0&&b.right<=innerWidth;}),true,'Tariff input stays within viewport');
    if(unit===1&&role==='super_admin')await page.locator('#form-master-siswa').screenshot({path:path.join(root,'student-'+width+'-'+theme+'.png')});
  }
  console.log('PASS browser lifecycle '+role+' unit '+unit);await context.close();
 }
 const all=await browser.newContext();await all.addCookies([{name:'PHPSESSID',value:f.sessions.super_admin[0],url:base}]);const allPage=await all.newPage();await allPage.goto(base+'/siswa/daftar.php');
 const scope=await allPage.locator('.sidebar-unit-form').evaluate(form=>Object.fromEntries(new FormData(form)));
 eq((await post(all,'/unit_switch.php',{...scope,unit_id:'0',next:'/siswa/daftar.php'})).status(),303,'Switch to All Units using scoped endpoint');
 await allPage.goto(base+'/siswa/daftar.php');eq(await allPage.locator('#form-master-siswa fieldset').evaluate(el=>el.disabled),true,'All Units remains read only');
 eq((await post(all,'/siswa/daftar.php',{aksi:'tambah',_operational_unit:'0'})).status(),409,'All Units writes rejected');await all.close();
 eq(errors,[],'No browser exceptions');fs.writeFileSync(path.join(root,process.env.SPP_PSB_SCOPE_ONLY==='1'?'browser-scope-result.json':'browser-result.json'),JSON.stringify({checks,passed:true,scope_only:process.env.SPP_PSB_SCOPE_ONLY==='1'},null,2));console.log('PASS '+checks+' PSB browser/HTTP checks');
}catch(error){fs.writeFileSync(path.join(root,'browser-failure.txt'),error.stack);throw error;}finally{await browser.close();}})().catch(error=>{console.error(error);process.exit(1);});
