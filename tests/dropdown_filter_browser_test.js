/* Independent source rows are exported from the owned isolated fixture, never production. */
const assert=require('node:assert/strict'),fs=require('node:fs');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,account=JSON.parse(fs.readFileSync(process.env.SPP_DROPDOWN_ACCOUNT_FILE,'utf8'));
const manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8'));
assert.equal(new URL(base).protocol,'http:');assert.ok(['127.0.0.1','localhost'].includes(new URL(base).hostname));
assert.equal(manifest.isolated_test,true);assert.notEqual(Number(manifest.port),3306);assert.notEqual(manifest.datadir,manifest.source_datadir);
const source=JSON.parse(fs.readFileSync(process.env.SPP_DROPDOWN_SOURCE_ROWS,'utf8'));
async function login(page){await page.goto(base+'login.php');await page.locator('#username').fill(account.username);await page.locator('#password').fill(account.password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);}
(async()=>{
 const name=process.env.SPP_DROPDOWN_ENGINE||'chrome',b=await pw.chromium.launch({headless:true,...(name==='chromium109'?{executablePath:process.env.SPP_CHROMIUM109_EXECUTABLE}:{channel:name})});
 try{
  const page=await b.newPage();await page.route('https://fonts.googleapis.com/**',r=>r.abort());await page.route('https://fonts.gstatic.com/**',r=>r.abort());
  const identity=await(await page.request.get(base+'tests/browser_clone_identity.php')).json();assert.equal(identity.server_uuid,manifest.server_uuid);assert.notEqual(manifest.server_uuid,manifest.source_server_uuid);await login(page);
  for(const unit of [1,2,3,0]){
   await page.goto(base+'dashboard.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);await page.goto(base+'siswa/daftar.php',{waitUntil:'domcontentloaded'});
   const classes=[...new Set(source[unit].map(s=>String(s.master_kelas_id)).filter(v=>Number(v)>0))].slice(0,2);assert.equal(classes.length,2);
   await page.locator('[data-control=kelas] .spp-select-trigger').click();await page.locator('.spp-multi-panel:visible .spp-multi-all input').uncheck();for(const id of classes)await page.locator(`.spp-multi-panel:visible .spp-multi-option input[value="${id}"]`).check();await page.locator('.spp-multi-panel:visible .spp-multi-apply').click();
   await page.locator('[data-control=status] .spp-select-trigger').click();await page.locator('.spp-multi-panel:visible .spp-multi-all input').check();await page.locator('.spp-multi-panel:visible .spp-multi-apply').click();
   await page.locator('[data-control=per_page] .spp-select-trigger').click();await page.locator('.spp-dropdown-option:visible').filter({hasText:/^50\s*\/\s*halaman$/}).click();
   await Promise.all([page.waitForNavigation(),page.locator('.student-filter-bar button[type=submit]').click()]);
   const chosen=await page.locator('select[name="kelas[]"]').evaluate(s=>window.sppSelectedValues(s).sort());assert.deepEqual(chosen,classes.sort());await page.reload({waitUntil:'domcontentloaded'});assert.deepEqual(await page.locator('select[name="kelas[]"]').evaluate(s=>window.sppSelectedValues(s).sort()),classes.sort());
   const expected=source[unit].filter(s=>classes.includes(String(s.master_kelas_id))).map(s=>Number(s.id)).sort((a,b)=>a-b),actual=[];
   const initial=page.url();for(let n=1;n<=Math.max(1,Math.ceil(expected.length/50));n++){const url=new URL(initial);url.searchParams.set('page',String(n));await page.goto(url.href,{waitUntil:'domcontentloaded'});actual.push(...await page.locator('[data-student-id]').evaluateAll(rows=>rows.map(r=>Number(r.dataset.studentId))));}
   assert.deepEqual(actual.sort((a,b)=>a-b),expected);assert.equal(new Set(actual).size,actual.length);console.log(`PASS ${name} unit ${unit}: class/status apply, reload and ${expected.length} exact source identities`);
  }
  const nativeContext=await b.newContext({javaScriptEnabled:false});await nativeContext.addCookies(await page.context().cookies());const native=await nativeContext.newPage();await native.route('https://fonts.googleapis.com/**',r=>r.abort());await native.route('https://fonts.gstatic.com/**',r=>r.abort());await native.goto(base+'siswa/daftar.php',{waitUntil:'domcontentloaded'});assert.equal(await native.locator('.spp-select-trigger').count(),0);await native.locator('select[name="per_page"]').selectOption('50');await Promise.all([native.waitForNavigation({waitUntil:'domcontentloaded'}),native.locator('.student-filter-bar button[type=submit]').click({force:true})]);assert.equal(new URL(native.url()).searchParams.get('per_page'),'50');console.log('PASS native form without JavaScript');await nativeContext.close();
 }finally{await b.close();}
})().catch(e=>{console.error(e);process.exit(1);});
