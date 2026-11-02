const assert=require('node:assert/strict'),fs=require('node:fs'),{execFileSync}=require('node:child_process');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core'),base=process.env.SPP_TEST_BASE_URL;
assert.match(base||'',/^http:\/\/(127\.0\.0\.1|localhost):\d+$/);
const fixture=JSON.parse(fs.readFileSync(process.env.SPP_POPUP_EDIT_FIXTURE,'utf8'));
const state=()=>JSON.parse(execFileSync('php',['tests/popup_payment_edit_fixture.php','state'],{encoding:'utf8',env:process.env}));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),context=await browser.newContext({viewport:{width:1440,height:1080}}),page=await context.newPage(),errors=[],checks=[];
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>{errors.push('Unexpected native dialog: '+d.message());d.dismiss();});
 const result=page.locator('#operation-feedback-dialog');
 async function closeResult(type){await result.waitFor({state:'visible'});assert.equal(await result.getAttribute('data-type'),type);await result.getByRole('button',{name:'Mengerti',exact:true}).click();}
 async function switchUnit(unit,p=page){if(await p.locator('#sidebar-unit-select').inputValue()!==String(unit))await Promise.all([p.waitForNavigation(),p.locator('#sidebar-unit-select').selectOption(String(unit))]);}
 async function editFromHistory(unit){const f=fixture.units[unit];await page.goto(base+'/pembayaran/lihat.php?all_periods=1&selected='+f.payment_id+'&search='+encodeURIComponent(f.nis));if(await result.isVisible())await result.getByRole('button',{name:'Mengerti',exact:true}).click();await switchUnit(unit);if(!new URL(page.url()).searchParams.has('selected'))await page.goto(base+'/pembayaran/lihat.php?all_periods=1&selected='+f.payment_id+'&search='+encodeURIComponent(f.nis));const link=page.locator('a[href*="edit.php?id='+f.payment_id+'&"]').first();await link.click();await page.locator('#form-bayar').waitFor();assert.equal(await page.locator('[name=return_context]').inputValue().then(v=>v.length),48);return page.locator('#form-bayar');}
 async function fields(form){return form.evaluate(e=>Object.fromEntries(new FormData(e)));}
 async function post(payload){return page.request.post(base+'/pembayaran/proses.php',{form:payload,maxRedirects:0});}
 async function follow(response){const target=new URL(response.headers().location,page.url()).href;if(target===page.url())await page.reload();else await page.goto(target);}
 try{
  assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  for(const unit of [1,2,3]){
   const before=state()[unit],form=await editFromHistory(unit);assert.equal(await form.locator('[name=_operational_unit]').inputValue(),String(unit));assert.equal(await form.locator('[name=_operational_unit]').count(),1);
   const htmlBytes=await page.content().then(v=>Buffer.byteLength(v));
   // Real UI submission with the original unit and a changed payment method.
   const method=before.snapshot.payment.sistem_pembayaran==='VA'?'Tunai':'VA';await form.locator('[name=sistem_pembayaran]').selectOption(method);
   const pending=page.waitForRequest(r=>r.method()==='POST'&&r.url().endsWith('/pembayaran/proses.php'));
   await Promise.all([page.waitForNavigation(),page.locator('#btn-update').click()]);const sent=await pending;
   assert.equal(new URLSearchParams(sent.postData()).get('_operational_unit'),String(unit));await closeResult('success');
   let after=state()[unit];assert.equal(after.payments,before.payments);assert.equal(after.snapshot.payment.sistem_pembayaran,method);assert.equal(after.events.length,before.events.length+1);assert.equal(after.events.at(-1).action,'edited');
   for(const key of ['id','unit_id','NO_INDUK','U_PSB','U_SPP','U_KOMITE','U_LAIN','total_jumlah','TGL_BYR'])assert.equal(after.snapshot.payment[key],before.snapshot.payment[key],key);
   assert.deepEqual(after.snapshot.details,before.snapshot.details);
   const auditBefore=JSON.parse(after.events.at(-1).before_snapshot),auditAfter=JSON.parse(after.events.at(-1).after_snapshot);assert.equal(auditBefore.payment.sistem_pembayaran,before.snapshot.payment.sistem_pembayaran);assert.equal(auditAfter.payment.sistem_pembayaran,method);
   const replay=await page.request.post(base+'/pembayaran/proses.php',{data:sent.postData(),headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});assert.ok([302,303].includes(replay.status()));assert.deepEqual(state()[unit],after);
   // CSRF expiry keeps edits and obtains the current server token/scope on reload.
   let current=await editFromHistory(unit),payload=await fields(current),csrf=payload.csrf_token;payload.csrf_token='expired';payload.sistem_pembayaran='Qris';const failed=await post(payload);assert.ok([302,303].includes(failed.status()));assert.match(failed.headers().location,/edit\.php\?/);await follow(failed);await closeResult('error');assert.equal(await current.locator('[name=sistem_pembayaran]').inputValue(),'Qris');assert.equal(await current.locator('[name=csrf_token]').inputValue(),csrf);assert.equal(await current.locator('[name=_operational_unit]').inputValue(),String(unit));assert.deepEqual(state()[unit],after);
   // A missing marker is still rejected, with recovery to this edit, never new input.
   payload=await fields(current);delete payload._operational_unit;const missing=await post(payload);assert.equal(missing.status(),409);const rejected=await missing.text();assert.match(rejected,/Kembali ke Edit Pembayaran/);assert.doesNotMatch(rejected,/href="form\.php"/);assert.deepEqual(state()[unit],after);
   await page.reload();payload=await fields(current);
   const second=await context.newPage();await second.goto(base+'/pembayaran/lihat.php');await switchUnit(unit===1?2:1,second);
   const stale=await post(payload);assert.equal(stale.status(),409);assert.match(await stale.text(),/Kembali ke Edit Pembayaran/);assert.deepEqual(state()[unit],after);
   await switchUnit(unit,second);await second.close();await page.reload();assert.equal(await current.locator('[name=sistem_pembayaran]').inputValue(),'Qris');assert.equal(await current.locator('[name=_operational_unit]').inputValue(),String(unit));
   const plain=await browser.newContext({javaScriptEnabled:false});await plain.addCookies(await context.cookies());const p=await plain.newPage();await p.goto(page.url());assert.equal(await p.locator('#form-bayar [name=_operational_unit]').inputValue(),String(unit));await plain.close();
   await page.goto(base+'/pembayaran/edit.php?id='+fixture.units[unit].payment_id);assert.equal((await current.locator('[name=return_context]').inputValue()).length,48);
   payload=await fields(current);payload.csrf_token='expired-direct';payload.uang_psb='200';const directFailure=await post(payload);await follow(directFailure);await closeResult('error');assert.equal(await current.locator('[name=uang_psb]').inputValue(),'200');assert.deepEqual(state()[unit],after);
   checks.push({unit,htmlBytes,edit:true,replay:true,csrf:true,missingMarker:true,crossTab:true,noJavaScript:true,directLinkRecovery:true,financialDetailsUnchanged:true,audit:true});
  }
  assert.deepEqual(errors,[]);if(process.env.SPP_UI_OUTPUT)fs.writeFileSync(process.env.SPP_UI_OUTPUT+'/popup-edit-browser.json',JSON.stringify(checks,null,2));console.log('PASS: history-to-edit UI on SD/SMP/SMA, explicit large-form markers, financial/audit invariants, replay, expiry/draft and cross-tab isolation.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
