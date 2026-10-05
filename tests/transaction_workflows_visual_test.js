const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const base=process.env.SPP_TEST_BASE_URL,password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim(),output=process.env.SPP_UI_OUTPUT;
const ids=JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE,'utf8').replace(/^\uFEFF/,'')),url=path=>new URL(path,base).href;
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage();await page.goto(url('/login.php'));await page.locator('#username').fill('superadmin');await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
 for(const owner of [1,2,3]){
  await page.goto(url('/dashboard.php'));await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(owner))]);
  for(const width of [1440,390])for(const theme of ['light','dark']){
   await page.setViewportSize({width,height:900});await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t;},theme);
   await page.goto(url('/otorisasi_transaksi.php?view=history'));await page.waitForLoadState('networkidle');
   const hero=await page.locator('.authorization-hero').evaluate(e=>({background:getComputedStyle(e).backgroundImage,color:getComputedStyle(e.querySelector('h1')).color}));
   if(theme==='dark')assert.ok(!hero.background.includes('231, 248, 240'),hero.background);
   assert.ok(await page.locator('.authorization-history-wrap').evaluate(e=>e.scrollHeight>e.clientHeight));
   if(output)await page.screenshot({path:`${output}/history-${owner}-${width}-${theme}.png`,fullPage:true,animations:'disabled'});
   await page.locator('.open-payment-activity').first().click();await page.locator('.payment-activity-event').first().waitFor();
   const dialog=await page.locator('#payment-activity-dialog').boundingBox();assert.ok(dialog.x>=0&&dialog.x+dialog.width<=width+1);await page.locator('[data-close-activity]').click();
   await page.goto(url('/tabungan/keluar.php'));await page.locator('#siswa-search').fill(ids[owner].nis);await page.waitForFunction(()=>document.getElementById('disp-saldo').value.startsWith('Rp '));await page.locator('#nominal-keluar').fill('200000');await page.locator('#btn-simpan').click();await page.locator('#spp-warning-message').waitFor({state:'visible'});
   if(output)await page.screenshot({path:`${output}/savings-${owner}-${width}-${theme}.png`,animations:'disabled'});
  }
  console.log('OK visual unit '+owner+': history scroll/contrast, dialog, savings popup in desktop/mobile and light/dark');
 }
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
