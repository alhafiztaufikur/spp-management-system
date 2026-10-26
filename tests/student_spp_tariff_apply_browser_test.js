'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
const manifestPath=process.env.SPP_TEST_ISOLATED_INSTANCE,root=path.dirname(manifestPath),manifest=JSON.parse(fs.readFileSync(manifestPath,'utf8').replace(/^\uFEFF/,''));
const f=JSON.parse(fs.readFileSync(path.join(root,'tariff-fixture.json'),'utf8')),base=process.env.SPP_TEST_BASE_URL;
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);let checks=0;
const eq=(a,b,label)=>{assert.deepEqual(a,b,label);checks++;};
const state=()=>JSON.parse(execFileSync('php',[path.join(__dirname,'student_spp_tariff_fixture.php'),'state'],{encoding:'utf8',env:process.env}));
async function apply(page){const [response]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/master_spp.php')&&r.request().method()==='POST'),page.locator('#sct-save').click()]);const data=await response.json();eq(data.ok,true,data.message||'Application succeeds');await page.waitForFunction(()=>!document.getElementById('student-tariff-dialog').open);return data;}
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[];
try{
 for(const unit of [1,2,3]){
  const context=await browser.newContext({viewport:{width:1650,height:900}});await context.addCookies([{name:'PHPSESSID',value:f.sessions[unit],url:base}]);await context.route('https://fonts.googleapis.com/**',r=>r.abort());
  const identity=await(await context.request.get(base+'/tests/browser_clone_identity.php')).json();eq(identity.server_uuid,manifest.server_uuid,'Verified separate HTTP server');
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(base+'/master_spp.php?tahun='+encodeURIComponent(f.year));
  const id=f.units[unit].new.id,row=page.locator('.spp-publish-row[data-student-id="'+id+'"]');await page.locator('#spp-student-search').fill('QA Tarif');await row.locator('input[type=checkbox]').check();await row.locator('select').selectOption('11');
  await row.locator('[data-edit-student-tariff]').click();eq(await page.locator('#student-tariff-form [name=reason]').count(),0,'No reason field or requirement');eq(await page.locator('#sct-save').innerText(),'Terapkan Tarif','One explicit application button');
  await page.locator('[name=nominal_akhir]').fill('765.432,10');eq((await page.locator('#sct-preview-summary').innerText()).includes('8 diterbitkan'),true,'Preview distinguishes new issuance');
  const before=state()[unit].new;eq(before.length,0,'Editing input does not save automatically');const result=await apply(page);eq(result.result.created,8,'One click issues eight SPP bills');eq(result.year_summary.pending,0,'Server summary updates pending count');
  eq((await row.locator('[data-student-bill-status]').innerText()).startsWith('8 bulan'),true,'Row shows published status immediately');eq(await page.locator('[data-sct-summary=pending]').innerText(),'0','Summary updates without reload');
  eq(await page.locator('#spp-student-search').inputValue(),'QA Tarif','Search preserved');eq(await row.locator('input[type=checkbox]').isChecked(),true,'Checkbox preserved');eq(await row.locator('select').inputValue(),'11','Publication month preserved');
  eq(state()[unit].new.length,8,'Exactly the requested range is stored');eq(await row.locator('[data-student-rate]').innerText(),'Rp 765.432,1','Applied tariff immediately visible');
  await row.locator('[data-edit-student-tariff]').click();const repeated=await apply(page);eq(repeated.result.created,0,'Repeated apply never duplicates bills');eq(repeated.result.rules,0,'Equivalent tariff is a no-op');
  for(const [width,height] of [[1366,768],[1650,900],[1920,1080]])for(const theme of ['light','dark']){
   await page.setViewportSize({width,height});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);await row.locator('[data-edit-student-tariff]').click();
   const boxes=await page.evaluate(()=>[document.querySelector('#student-tariff-form [name=nominal_akhir]'),document.querySelector('.sct-fields .spp-select-trigger')].map(e=>{const b=e.getBoundingClientRect();return {width:b.width,height:b.height,top:b.top};}));
   eq(Math.abs(boxes[0].width-boxes[1].width)<1,true,'Tariff and month widths equal');eq(boxes.map(b=>b.height),[48,48],'Visible controls are exactly 48px');eq(Math.abs(boxes[0].top-boxes[1].top)<1,true,'Control tops align');
   const b=await page.locator('#sct-save').boundingBox();eq(b.y>=0&&b.y+b.height<=height,true,'Desktop footer stays inside viewport');
   await page.screenshot({path:path.join(root,`apply-dialog-${unit}-${theme}-${width}.png`)});await page.keyboard.press('Escape');eq(await row.locator('[data-edit-student-tariff]').evaluate(e=>e===document.activeElement),true,'Escape restores focus');
  }
  await page.setViewportSize({width:1650,height:900});await row.locator('[data-edit-student-tariff]').click();await page.locator('[name=start_period]').selectOption('2189-01');await page.locator('[name=nominal_akhir]').fill('0');await apply(page);eq(state()[unit].new.find(b=>b.bulan==='01').status,'waived','Zero uses existing waiver status');
  await row.locator('[data-edit-student-tariff]').click();await page.locator('[name=start_period]').selectOption('2189-03');await page.locator('#sct-reset').click();eq(await page.locator('#sct-save').innerText(),'Terapkan Tarif Bawaan','Reset also applies in one step');await apply(page);eq(Number(state()[unit].new.find(b=>b.bulan==='03').nominal_tagihan),f.units[unit].new.base-25000,'Default reset retains discount');
  if(unit===1){
   const draft=JSON.parse(execFileSync('php',[path.join(__dirname,'student_spp_tariff_fixture.php'),'draft'],{encoding:'utf8',env:process.env}));await page.goto(base+'/master_spp.php?tahun='+encodeURIComponent(draft.year));
   for(const kind of ['new','control']){await page.locator('[data-edit-student-tariff="'+f.units[unit][kind].id+'"]').click();await page.locator('[name=start_period]').selectOption('2186-11');await page.locator('[name=nominal_akhir]').fill('500.000');await apply(page);}
   eq(await page.locator('[data-sct-year-status]').innerText(),'PUBLISHED','Draft year updates after issuance');eq(await page.locator('[data-sct-summary=published]').innerText(),'2','Second student can apply without page reload');
   eq(await page.locator('[data-sct-close-year]').isVisible(),true,'Existing close-year action becomes available');await page.locator('[data-spp-tab=rates]').first().click();await page.locator('#spp-rate-edit').click();eq(await page.locator('#spp-rate-form [data-level]').first().isEnabled(),true,'Existing class tariff edit still works after first publication');await page.locator('#spp-rate-cancel').click();
  }
  await context.close();
 }
 eq(errors,[],'No uncaught JavaScript errors');fs.writeFileSync(path.join(root,'tariff-apply-browser-result.json'),JSON.stringify({checks,passed:true,errors},null,2));console.log('PASS '+checks+' one-action desktop browser checks.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
