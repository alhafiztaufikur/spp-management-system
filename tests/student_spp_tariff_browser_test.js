'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
const manifestPath=process.env.SPP_TEST_ISOLATED_INSTANCE,root=path.dirname(manifestPath);
const manifest=JSON.parse(fs.readFileSync(manifestPath,'utf8').replace(/^\uFEFF/,''));
const fixture=JSON.parse(fs.readFileSync(path.join(root,'tariff-fixture.json'),'utf8')),base=process.env.SPP_TEST_BASE_URL;
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
let checks=0;const eq=(a,b,label)=>{assert.deepEqual(a,b,label);checks++;};
const state=()=>JSON.parse(execFileSync(process.env.SPP_PHP_BIN||'php',[path.join(__dirname,'student_spp_tariff_fixture.php'),'state'],{encoding:'utf8',env:process.env}));
const post=(context,values)=>context.request.post(base+'/master_spp.php',{data:new URLSearchParams(values).toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
(async()=>{
 const browser=await chromium.launch({channel:process.env.SPP_UI_BROWSER||'chrome',headless:true});const errors=[];
 try{
  for(const unit of [1,2,3]){
   const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.sessions[unit],url:base}]);
   await context.route('https://fonts.googleapis.com/**',r=>r.abort());
   const identity=await(await context.request.get(base+'/tests/browser_clone_identity.php')).json();eq(identity.server_uuid,manifest.server_uuid,'Verified HTTP clone');
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/master_spp.php?tahun='+encodeURIComponent(fixture.year));
   eq(await page.locator('#student-tariff-form').evaluate(f=>f.parentElement.closest('#spp-publish-form')===null),true,'Dialog form is outside publication form');
   const id=fixture.units[unit].custom.id,control=fixture.units[unit].control.id;
   const row=page.locator('.spp-publish-row[data-student-id="'+id+'"]');
   await page.locator('#spp-student-search').fill('QA Tarif Khusus');
   await row.locator('input[type=checkbox]').check();await page.locator('.spp-publish-row[data-student-id="'+control+'"] input[type=checkbox]').check();
   await row.locator('select[name^="start_month"]').selectOption('10');
   const open=()=>row.locator('[data-edit-student-tariff]').click();await open();
   eq(await page.locator('#sct-student-detail').innerText(),'NIS '+fixture.units[unit].custom.nis+' · '+fixture.units[unit].custom.class+' · TA '+fixture.year,'Duplicate name resolved by student ID');
   eq(await page.locator('#sct-preview-body tr').count(),12,'Twelve billing periods shown');
   await page.locator('#student-tariff-form [name=start_period]').selectOption('2188-11');
   await page.locator('#student-tariff-form [name=nominal_akhir]').fill('600.000,50');

   const oldValues=await page.locator('#student-tariff-form').evaluate(f=>Object.fromEntries(new FormData(f)));
   eq((await page.locator('#sct-preview-body tr').filter({hasText:'November'}).innerText()).includes('600.000,5'),true,'Custom amount appears in preview');
   await Promise.all([page.waitForResponse(r=>r.url().endsWith('/master_spp.php')&&r.request().method()==='POST'),page.locator('#sct-save').click()]);
   await page.waitForFunction(()=>!document.getElementById('student-tariff-dialog').open);
   eq(await page.locator('#spp-student-search').inputValue(),'QA Tarif Khusus','Search survives save');
   eq(await row.locator('input[type=checkbox]').isChecked(),true,'Student selection survives save');
   const updatedYearVersion=await page.locator('#spp-publish-form [name=expected_rate_version]').inputValue();
   eq(updatedYearVersion!==oldValues.expected_rate_version,true,'Publication version advances after tariff save');
   eq(await page.locator('#student-tariff-form [name=expected_rate_version]').inputValue(),updatedYearVersion,'Next tariff edit uses the updated year version');
   eq(await page.locator('.spp-publish-row[data-student-id="'+control+'"] input[type=checkbox]').isChecked(),true,'Other selection survives save');
   eq(await row.locator('select[name^="start_month"]').inputValue(),'10','Publication month survives save');
   await row.locator('select[name^="start_month"]').selectOption('11');
   eq(await row.locator('[data-student-rate]').innerText(),'Rp 600.000,5','Selected month reads new context after save');
   eq(await row.locator('[data-student-rate-badge]').innerText(),'Tarif Khusus','Custom badge shown');
   const saved=state()[unit].custom.find(b=>b.bulan==='11'&&b.tahun==='2188');eq(Number(saved.nominal_tagihan),600000.50,'HTTP save updates correct student only');
   eq(Number(saved.potongan_nominal_snapshot),0,'Custom final rate not discounted twice');
   const stale=await post(context,oldValues);eq(stale.status(),422,'Stale form rejected');
   await open();await page.locator('#student-tariff-form [name=start_period]').selectOption('2189-01');await page.locator('#student-tariff-form [name=nominal_akhir]').fill('0');
   await page.locator('#sct-save').click();await page.waitForFunction(()=>!document.getElementById('student-tariff-dialog').open);
   eq(state()[unit].custom.find(b=>b.bulan==='01'&&b.tahun==='2189').status,'waived','Zero is a waiver through UI');
   await open();await page.locator('#student-tariff-form [name=start_period]').selectOption('2189-03');await page.locator('#sct-reset').click();
   eq(await page.locator('#student-tariff-form [name=nominal_akhir]').getAttribute('readonly'),'','Reset previews the default rate');
   await page.locator('#sct-save').click();await page.waitForFunction(()=>!document.getElementById('student-tariff-dialog').open);
   const bills=state()[unit].custom;eq(Number(bills.find(b=>b.bulan==='03'&&b.tahun==='2189').nominal_tagihan),fixture.units[unit].custom.base-25000,'Partial reset restores base/discount');
   eq(Number(bills.find(b=>b.bulan==='02'&&b.tahun==='2189').nominal_tagihan),0,'Partial reset preserves previous custom month');
   eq(bills.find(b=>b.bulan==='12'&&b.tahun==='2188').status,'cancelled','Cancelled bill retained');
   for(const theme of ['light','dark'])for(const width of process.env.SPP_UI_DESKTOP_ONLY==='1'?[1366,1650,1920]:[1650,390]){
    await page.setViewportSize({width,height:900});await page.evaluate(theme=>document.documentElement.dataset.theme=theme,theme);await open();
    eq(await page.locator('#student-tariff-dialog').evaluate(d=>d.getBoundingClientRect().width<=innerWidth),true,'Responsive dialog fits '+width);
    eq(await page.locator('#sct-save').isVisible(),true,'Save action stays visible');
    await page.screenshot({path:path.join(root,`tariff-dialog-${unit}-${theme}-${width}.png`)});await page.keyboard.press('Escape');
    eq(await row.locator('[data-edit-student-tariff]').evaluate(e=>e===document.activeElement),true,'Escape returns focus to edit button');
   }
   await page.setViewportSize({width:1650,height:1000});await page.goto(base+'/master_spp.php?tahun='+encodeURIComponent(fixture.year));
   const data=await page.locator('#student-tariff-data').textContent();const own=JSON.parse(data)[id];
   eq(own.periods.find(p=>p.period==='2188-11').rate,600000.50,'Custom rates survive reload');
   const form=await page.locator('#student-tariff-form').evaluate(f=>Object.fromEntries(new FormData(f)));
   Object.assign(form,{student_id:String(id),start_period:'2188-11',expected_student_version:own.version,reason:'Invalid request protection',nominal_akhir:'500.000'});
   const before=state();
   for(const bad of ['', '-1','1,234','1e6']){const r=await post(context,{...form,nominal_akhir:bad});eq(r.status(),422,'Invalid final rate rejected');}
   eq((await post(context,{...form,csrf_token:'invalid'})).status(),422,'CSRF protection');
   eq((await post(context,{...form,_operational_unit:String(unit===1?2:1)})).status(),409,'Operational unit protection');
   eq((await post(context,{...form,student_id:String(fixture.units[unit===1?2:1].custom.id)})).status(),422,'Cross-unit student protection');
   eq(state(),before,'Invalid HTTP requests cannot change bills');

   const publishValues=await page.locator('#spp-publish-form').evaluate(f=>Object.fromEntries(new FormData(f)));
   Object.assign(publishValues,{'selected_students[]':fixture.units[unit].custom.nis,confirm_previous_debt:'1',_operational_unit:String(unit)});
   const publish=await post(context,publishValues);eq(publish.status(),302,'Publication still accepts the current version');
   const published=await context.request.get(base+'/master_spp.php?tahun='+encodeURIComponent(fixture.year));
   eq((await published.text()).includes('tagihan SPP baru diterbitkan'),true,'Re-publication completes through the existing flow');
   eq(state(),before,'Re-publication preserves all bill snapshots without duplication');

   for(const tab of ['aktif','nonaktif','hapus']){
    await page.goto(base+'/master_biaya_lain.php?master_tab='+tab);
    for(const summary of await page.locator('.fee-master-transactions').all()){
     const payment=summary.locator('.fee-transaction-link:not(.is-cancelled)');
     if(await payment.count()){
      const href=await payment.getAttribute('href'),url=new URL(href,base);
      eq(url.searchParams.get('all_periods'),'1','History link preserves all dates');eq(Number(url.searchParams.get('fee_master_id'))>0,true,'History link preserves fee master');
      eq(/^\d[\d.]* rincian$/.test(await summary.locator('.fee-transaction-caption').innerText()),true,'Detail count separate from transaction count');
      eq(/^Rp [\d.]+,\d{2}$/.test(await summary.locator('.fee-transaction-amount strong').innerText()),true,'Amount remains two decimals');
     }else eq(await summary.locator('.fee-transaction-empty').innerText(),'Belum ada transaksi','Empty state has no active payment link');
     const cancelled=summary.locator('.is-cancelled');if(await cancelled.count())eq(new URL(await cancelled.getAttribute('href'),base).searchParams.get('view'),'deleted','Cancelled link preserved');
    }
    if(tab==='nonaktif'&&unit===1){for(const theme of ['light','dark']){await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);await page.locator('#daftar-master-biaya').screenshot({path:path.join(root,'fee-summary-'+theme+'.png')});}}
   }
  }
  eq(errors,[],'No uncaught JavaScript errors');fs.writeFileSync(path.join(root,'tariff-browser-result.json'),JSON.stringify({checks,errors},null,2));console.log('PASS '+checks+' student tariff and fee summary browser/HTTP checks.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1});
