const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const{chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);const base=new URL(process.env.SPP_TEST_BASE_URL);assert.match(process.env.SPP_DB_NAME,/^db_spp_audit_/);assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
(async()=>{const b=await chromium.launch({channel:'chrome',headless:true});try{
 const p=await b.newPage({viewport:{width:390,height:844}}),errors=[];p.on('pageerror',e=>errors.push(e.message));
 assert.equal((await(await p.request.get(new URL('/tests/browser_clone_identity.php',base).href)).json()).database,process.env.SPP_DB_NAME);
 await p.goto(new URL('/login.php',base).href);await p.locator('#username').fill('superadmin');await p.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await p.locator('#btn-login').click();await p.waitForURL('**/dashboard.php');
 const ids=JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE,'utf8'));
 for(const scope of [1,2,3]){
  await p.goto(new URL('/dashboard.php',base).href);await Promise.all([p.waitForNavigation(),p.locator('#sidebar-unit-select').selectOption(String(scope))]);
  await p.goto(new URL('/siswa/daftar.php',base).href);await p.locator('[data-class-picker-button]').click();await p.locator('[data-class-picker-option][data-label="'+{1:'1A',2:'7A',3:'10A'}[scope]+'"]').click();
  await p.locator('#student-potongan-spp').fill('25.000');
  const expected=await p.locator('#student-spp-base').evaluate(e=>Number(e.dataset.base)-25000);
  assert.equal(await p.locator('#student-spp-effective').innerText(),'Rp '+Math.max(0,expected).toLocaleString('id-ID'));
  await p.goto(new URL('/pembayaran/edit.php?id='+ids[scope].id,base).href);await p.waitForLoadState('networkidle');
  const dates=await p.locator('#payment-history-body tr td:first-child').allInnerTexts();assert.ok(dates.length>0);for(const date of dates)assert.match(date,/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2} WIB$/);
  assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
  await p.screenshot({path:path.join(process.env.SPP_UI_ARTIFACTS,'history-fixed-'+scope+'.png'),fullPage:true});
 }
 assert.deepEqual(errors,[]);console.log('PASS: live nominal preview after class choice and actual payment-history dates in three units/mobile');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exit(1)});

