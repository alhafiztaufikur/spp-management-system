const assert=require('node:assert/strict');
const fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_[a-z0-9_]+$/);
const base=process.env.SPP_TEST_BASE_URL;assert.ok(/^http:\/\/(localhost|127\.0\.0\.1):/.test(base));
const password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
const ids=JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE,'utf8').replace(/^\uFEFF/,''));
const output=process.env.SPP_UI_OUTPUT;
const url=path=>new URL(path,base).href;
async function post(page,path,data){const r=await page.request.post(url(path),{data:new URLSearchParams(data).toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});assert.equal(r.status(),302);return r;}
async function login(page,name){await page.goto(url('/login.php'));await page.locator('#username').fill(name);await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);assert.ok(!page.url().endsWith('/login.php'));}
async function switchUnit(page,unit){await page.goto(url('/dashboard.php'));await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);}
async function editData(page,id,amount,key){await page.goto(url('/pembayaran/edit.php?id='+id));await page.waitForLoadState('networkidle');
 const data=await page.locator('#form-bayar').evaluate(form=>Array.from(new FormData(form).entries()));
 const change=(name,value)=>{const row=data.find(p=>p[0]===name);if(row)row[1]=String(value);else data.push([name,String(value)]);};
 change('uang_psb',amount);change('total_jumlah',amount);change('authorization_reason','Uji jejak operator pembayaran');if(key)change('activity_request_key',key);return data;}
async function events(page,id){const r=await page.request.get(url('/pembayaran/aktivitas.php?id='+id));assert.equal(r.status(),200);return (await r.json()).events;}
async function requestId(page,id){const list=await events(page,id);return list.filter(e=>e.action.startsWith('request_')).at(-1).authorization_id;}
async function decide(page,id,action){await page.goto(url('/otorisasi_transaksi.php'));const token=await page.locator('form:has(input[name="request_id"]) input[name="csrf_token"]').first().inputValue();
 return post(page,action==='approve'?'/pembayaran/proses.php':'/otorisasi_transaksi.php',action==='approve'?
 {aksi:'otorisasi_setujui',request_id:id,csrf_token:token,decision_note:'Disetujui untuk pengujian'}:
 {action,request_id:id,csrf_token:token,decision_note:'Keputusan untuk pengujian'});}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const errors=[];
 try{
  const admin=await browser.newPage({viewport:{width:1440,height:900}});admin.on('pageerror',e=>errors.push(e.message));
  const identity=await(await admin.request.get(url('/tests/browser_clone_identity.php'))).json();assert.equal(identity.database,process.env.SPP_DB_NAME);
  await login(admin,'superadmin');
  for(const unit of [1,2,3]){
   const fixture=ids[unit];await switchUnit(admin,unit);
   const cashier=await browser.newPage();cashier.on('pageerror',e=>errors.push(e.message));await login(cashier,fixture.cashier);
   const edit=await editData(admin,fixture.id,150);await post(admin,'/pembayaran/proses.php',edit);
   let log=await events(admin,fixture.id);assert.equal(log.at(-1).action,'edited');assert.equal(log.at(-1).role,'super_admin');
   assert.ok(log.at(-1).changes.some(c=>c.label==='PSB'&&c.after==='Rp 150'));
   const count=log.length;await post(admin,'/pembayaran/proses.php',edit);assert.equal((await events(admin,fixture.id)).length,count);
   await post(cashier,'/pembayaran/proses.php',await editData(cashier,fixture.id,200));const proposed=await requestId(admin,fixture.id);
   await decide(admin,proposed,'approve');log=await events(admin,fixture.id);
   assert.ok(log.some(e=>e.action==='request_edit'&&e.role==='kasir'));assert.equal(log.at(-2).action,'approved');assert.equal(log.at(-1).action,'edited');
   assert.equal(log.find(e=>e.action==='created').role,'kasir');assert.equal(log.at(-1).role,'super_admin');
   for(const decision of ['reject','cancel']){
    await post(cashier,'/pembayaran/proses.php',await editData(cashier,fixture.id,250));const rid=await requestId(admin,fixture.id);
    await decide(decision==='cancel'?cashier:admin,rid,decision);
    assert.equal((await events(admin,fixture.id)).at(-1).action,decision==='cancel'?'cancelled':'rejected');
   }
   // Responsive picker and history dialog across palettes and themes.
   for(const width of [1440,768,390])for(const theme of ['light','dark']){
    await admin.setViewportSize({width,height:900});await admin.goto(url('/pembayaran/edit.php?id='+fixture.id));await admin.waitForLoadState('networkidle');
    await admin.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t;},theme);
    const picker=admin.locator('.other-fee-trigger').first();assert.equal(await picker.innerText(),'Belum ada tagihan\n⌄');
    assert.equal(await admin.locator('.other-fee-hint').first().innerText(),'Terbitkan tagihan melalui Master Biaya Lain.');
    await picker.click();assert.equal(await admin.locator('.other-fee-menu').first().isVisible(),true);await admin.keyboard.press('Escape');
    // Use a long option solely in the DOM; no test fee is written to the database.
    await admin.locator('.biaya-lain-select').first().evaluate(select=>{const option=new Option('Contoh Nama Biaya Buku dan Perlengkapan Sekolah yang Sangat Panjang','qa-long');option.dataset.baseLabel=option.textContent;option.dataset.nominal='1000';option.dataset.paid='0';select.append(option);select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));});
    const metrics=await picker.evaluate(e=>{const r=e.getBoundingClientRect(),s=e.querySelector('span');return {left:r.left,right:r.right,viewport:innerWidth,wrap:s.scrollWidth<=s.clientWidth+1};});assert.ok(metrics.left>=0&&metrics.right<=metrics.viewport+1&&metrics.wrap);
    if(output&&width===1440&&theme==='light')await admin.screenshot({path:output+'/other-fee-'+unit+'.png',fullPage:true});
    await admin.goto(url('/pembayaran/lihat.php?search='+fixture.nis));await admin.locator('.open-payment-activity').first().click();
    await admin.locator('.payment-activity-event').first().waitFor();assert.ok((await admin.locator('#payment-activity-dialog').innerText()).includes('Pembayaran dibuat'));
    const dialog=await admin.locator('#payment-activity-dialog').boundingBox();assert.ok(dialog.x>=0&&dialog.x+dialog.width<=width+1);await admin.getByRole('button',{name:'Tutup riwayat aktivitas'}).click();
   }
   await admin.setViewportSize({width:1440,height:900});
   // Delete proposals remain pending and retain financial data until approved.
   await cashier.goto(url('/pembayaran/lihat.php?search='+fixture.nis));const csrf=await cashier.locator('#payment-delete-request-modal input[name="csrf_token"]').inputValue();
   await post(cashier,'/pembayaran/proses.php',{aksi:'hapus',id:fixture.id,csrf_token:csrf,authorization_reason:'Penghapusan untuk pengujian operator'});
   const deletion=await requestId(admin,fixture.id);await decide(admin,deletion,'approve');assert.equal((await events(admin,fixture.id)).at(-1).action,'deleted');
   await admin.goto(url('/pembayaran/lihat.php?view=deleted&search='+fixture.nis));assert.equal(await admin.locator('.open-payment-activity').count(),1);assert.equal(await admin.locator('.btn-tbl-edit').count(),0);
   await admin.locator('.open-payment-activity').click();await admin.locator('.payment-activity-event').first().waitFor();
   if(output)await admin.screenshot({path:output+'/archive-dialog-'+unit+'.png',fullPage:true});await admin.getByRole('button',{name:'Tutup riwayat aktivitas'}).click();
   console.log('OK unit '+unit+': operator, edits, replay, request/approve/reject/cancel/delete, archive, 6 responsive/theme views');await cashier.close();
  }
  await switchUnit(admin,0);await admin.goto(url('/pembayaran/lihat.php?view=deleted&search=UJI%20OPERATOR'));assert.equal(await admin.locator('.open-payment-activity').count(),3);
  await switchUnit(admin,1);assert.equal((await admin.request.get(url('/pembayaran/aktivitas.php?id='+ids[2].id))).status(),404);
  const anonymous=await browser.newContext();assert.equal((await anonymous.request.get(url('/pembayaran/aktivitas.php?id='+ids[1].id))).status(),401);await anonymous.close();
  assert.deepEqual(errors,[]);console.log('OK: Semua Unit, isolation, anonymous access, no JavaScript errors');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
