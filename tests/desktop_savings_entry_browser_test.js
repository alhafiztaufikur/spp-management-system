const assert=require('node:assert/strict'),fs=require('node:fs'),cp=require('node:child_process');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,fixture=JSON.parse(fs.readFileSync(process.env.SPP_FEEDBACK_FIXTURE,'utf8'));
assert.ok(/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base));
const state=()=>JSON.parse(cp.execFileSync('php',['-d','memory_limit=1024M',__dirname+'/desktop_feedback_fixture.php','state'],{encoding:'utf8'}));
const cents=raw=>{const [whole,fraction='']=String(raw).split('.');return BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));};
const canonical=value=>(value/100n).toString()+'.'+(value%100n).toString().padStart(2,'0');
const typed=value=>canonical(value).replace('.',',');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),context=await browser.newContext({viewport:{width:1440,height:1080}}),page=await context.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 const popup=async type=>{const dialog=page.locator('#operation-feedback-dialog');await dialog.waitFor({state:'visible'});assert.equal(await dialog.getAttribute('data-type'),type);assert.equal(await page.locator('#savings-entry-dialog[open]').count(),0);await dialog.getByRole('button',{name:'Mengerti',exact:true}).click();};
 try{
  assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  for(const unit of [1,2,3]){
   await page.goto(base+'/tabungan/masuk.php');if(await page.locator('#sidebar-unit-select').inputValue()!==String(unit))await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
   for(const type of ['student','account']){
    const item=fixture.units[unit][type],query={...item.identity,q:item.nis,owner_type:type,class_id:String(item.class_id),page:'1'};
    const entry=kind=>base+'/tabungan/'+kind+'.php?'+new URLSearchParams(query);
    let before=state()[unit][type],expected=cents(before.balance);
    await page.goto(entry('masuk'));
    assert.equal(await page.locator('#operation-feedback-dialog[open]').count(),0);
    const form=page.locator('[data-savings-entry]');
    const oldKey=await form.locator('[name=request_key]').inputValue();
    // Real deposit through the existing controller; all context survives PRG.
    await form.locator('[name=tanggal]').fill('2026-11-01');await form.locator('[name=nominal]').fill('496500,25');await form.locator('[name=keterangan]').fill('Uji setoran desktop.');
    const posted=await form.evaluate(e=>Object.fromEntries(new FormData(e)));posted.nominal='496500.25';
    await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Simpan Setoran',exact:true}).click()]);await popup('success');
    assert.ok(new URL(page.url()).pathname.endsWith('/tabungan/masuk.php'));
    for(const [key,value] of Object.entries(query))assert.equal(new URL(page.url()).searchParams.get(key),String(value));
    assert.equal(await form.locator('[name=tanggal]').inputValue(),'2026-11-01');assert.equal(await form.locator('[name=nominal]').inputValue(),'');assert.equal(await form.locator('[name=keterangan]').inputValue(),'');assert.notEqual(await form.locator('[name=request_key]').inputValue(),oldKey);
    let after=state()[unit][type];expected+=49650025n;assert.equal(cents(after.balance),expected);assert.equal(after.journals,before.journals+1);
    await page.reload();assert.equal(await page.locator('#operation-feedback-dialog[open]').count(),0);assert.equal(state()[unit][type].journals,after.journals);
    // Replaying the actual request must never produce a second journal or balance change.
    await page.request.post(base+'/tabungan/proses.php',{form:posted,maxRedirects:0});assert.deepEqual(state()[unit][type],after);
    await page.goto(entry('keluar'));
    if(await page.locator('#operation-feedback-dialog[open]').count())await popup(type==='account'?'success':'error');
    const amount=form.locator('[name=nominal]');
    for(const value of [expected-1n,expected,expected+1n]){
     await amount.fill(typed(value));const exceeds=value>expected;
     assert.equal(await amount.evaluate(e=>e.classList.contains('savings-invalid')),exceeds);
     assert.equal(await page.locator('.sw-balance-box').evaluate(e=>e.classList.contains('is-overdrawn')),exceeds);
     assert.equal(await amount.getAttribute('aria-invalid'),String(exceeds));
    }
    await form.getByRole('button',{name:'Simpan Penarikan',exact:true}).click();await page.locator('#savings-entry-dialog').waitFor({state:'visible'});assert.match(await page.locator('#savings-entry-dialog-message').innerText(),/melebihi saldo/);await page.locator('#savings-entry-dialog-close').click();
    assert.deepEqual(state()[unit][type],after);
    // Forged overdraft is rejected by the real server and keeps the typed draft/context.
    const fields=await form.evaluate(e=>Object.fromEntries(new FormData(e)));
    const rejected=await page.request.post(base+'/tabungan/proses.php',{form:{...fields,nominal:canonical(expected+1n)},maxRedirects:0});assert.equal(rejected.status(),302);
    await page.goto(new URL(rejected.headers().location,base+'/tabungan/').href);await popup('error');assert.equal(await amount.getAttribute('aria-invalid'),'true');assert.deepEqual(state()[unit][type],after);
    await amount.fill('100000,25');await form.locator('[name=tanggal]').fill('2026-11-02');await form.locator('[name=keterangan]').fill('Uji penarikan desktop.');
    await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Simpan Penarikan',exact:true}).click()]);await popup('success');
    assert.ok(new URL(page.url()).pathname.endsWith('/tabungan/keluar.php'));assert.equal(await amount.inputValue(),'');assert.equal(await form.locator('[name=tanggal]').inputValue(),'2026-11-02');
    after=state()[unit][type];expected-=10000025n;assert.equal(cents(after.balance),expected);assert.equal(after.journals,before.journals+2);
    for(const width of [1280,1440,1920])for(const theme of ['light','dark']){
     await page.setViewportSize({width,height:1080});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);await amount.fill(typed(expected+1n));assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false);
     await page.waitForFunction(()=>{const amount=document.getElementById('savings-entry-amount'),balance=document.querySelector('.sw-balance-box strong'),a=getComputedStyle(amount),b=getComputedStyle(balance);return a.color===b.color&&a.borderColor===b.color&&a.outlineColor===b.color;});
     if(process.env.SPP_UI_OUTPUT&&unit===1)await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/overdraw-'+type+'-'+width+'-'+theme+'.png',fullPage:true,animations:'disabled'});
    }
    // A balance change in another tab must still be rejected by authoritative server validation.
    const other=await page.context().newPage();await other.goto(entry('keluar'));
    const stale=await other.locator('[data-savings-entry]').evaluate(e=>Object.fromEntries(new FormData(e)));await other.close();
    stale.nominal=canonical(expected);const withdrawn=await page.request.post(base+'/tabungan/proses.php',{form:stale,maxRedirects:0});assert.equal(withdrawn.status(),302);assert.equal(cents(state()[unit][type].balance),0n);
    await amount.fill('1');await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Simpan Penarikan',exact:true}).click()]);await popup('error');assert.equal(cents(state()[unit][type].balance),0n);
    await amount.fill('0,01');assert.equal(await page.locator('.sw-balance-box.is-overdrawn').count(),1);
    console.log('PASS savings unit '+unit+' '+type+': real deposit/withdrawal, context/date/reset, replay, cents/boundaries, forged overdraft, stale balance and zero balance.');
   }
  }
  assert.deepEqual(errors,[]);
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
