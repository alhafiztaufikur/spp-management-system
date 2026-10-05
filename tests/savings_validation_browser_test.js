const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const base=process.env.SPP_TEST_BASE_URL,password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
const ids=JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE,'utf8').replace(/^\uFEFF/,''));const url=path=>new URL(path,base).href;
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(url('/login.php'));await page.locator('#username').fill('superadmin');await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
 for(const unit of [1,2,3]){
  await page.goto(url('/dashboard.php'));await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
  await page.goto(url('/tabungan/keluar.php'));
  const second=await page.locator('#siswa-list option').evaluateAll((opts,nis)=>opts.find(o=>o.dataset.nis!==nis).dataset.nis,ids[unit].nis);
  await page.route('**/get_saldo.php?*',async route=>{
   const first=new URL(route.request().url()).searchParams.get('nis')===ids[unit].nis;
   if(first)await new Promise(resolve=>setTimeout(resolve,1000));
   try{await route.fulfill({contentType:'application/json',body:JSON.stringify({saldo:first?999999:100})});}catch(_){/* Superseded request was aborted. */}
  });
  await page.locator('#siswa-search').fill(ids[unit].nis);await page.locator('#nominal-keluar').fill('1');await page.locator('#form-tabungan').evaluate(form=>form.requestSubmit());
  await page.locator('#spp-warning-title').waitFor({state:'visible'});assert.equal(await page.locator('#spp-warning-title').innerText(),'Saldo belum tersedia');await page.locator('#spp-warning-close').click();
  await page.locator('#siswa-search').fill(second);await page.waitForFunction(()=>document.getElementById('raw-saldo').value==='100');await page.waitForTimeout(1100);
  assert.equal(await page.locator('#raw-saldo').inputValue(),'100');await page.unroute('**/get_saldo.php?*');
  await page.goto(url('/tabungan/keluar.php'));await page.locator('#siswa-search').fill(ids[unit].nis);await page.waitForFunction(()=>document.getElementById('raw-saldo').value==='100000');
  for(const amount of ['50000','100000']){await page.locator('#nominal-keluar').fill(amount);assert.equal(await page.locator('.savings-invalid').count(),0);}
  // A forged overdraw POST must be rejected by the actual server and keep the balance.
  const fields=await page.locator('#form-tabungan').evaluate(form=>Object.fromEntries(new FormData(form)));
  const post=amount=>page.request.post(url('/tabungan/proses.php'),{form:{...fields,nominal:String(amount)},maxRedirects:0});
  assert.equal((await post(200000)).status(),302);
  let balance=await(await page.request.get(url('/tabungan/get_saldo.php?nis='+ids[unit].nis))).json();assert.equal(balance.saldo,100000);
  await page.goto(url('/tabungan/keluar.php'));await page.locator('#spp-warning-message').waitFor({state:'visible'});assert.ok((await page.locator('#spp-warning-message').innerText()).includes('melebihi saldo'));
  // The key rolled back by the rejected operation remains usable, and a replay cannot withdraw twice.
  assert.equal((await post(100000)).status(),302);assert.equal((await post(100000)).status(),302);
  balance=await(await page.request.get(url('/tabungan/get_saldo.php?nis='+ids[unit].nis))).json();assert.equal(balance.saldo,0);
  await page.goto(url('/tabungan/keluar.php'));await page.locator('#siswa-search').fill(ids[unit].nis);await page.waitForFunction(()=>document.getElementById('disp-saldo').value==='Rp 0');await page.locator('#nominal-keluar').fill('1');assert.equal(await page.locator('.savings-invalid').count(),1);
  console.log('OK unit '+unit+': pending balance, stale response, below/equal/overdraw, server rejection, rollback/replay, zero balance');
 }
 assert.deepEqual(errors,[]);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
