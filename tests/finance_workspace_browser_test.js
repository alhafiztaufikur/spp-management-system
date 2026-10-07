const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_HTTP_BASE,dir=process.env.SPP_QA_DIR,fixture=JSON.parse(fs.readFileSync(dir+'/fixture.json','utf8'));
assert.match(fixture.database,/^db_spp_audit_/);assert.ok(['127.0.0.1','localhost'].includes(new URL(base).hostname));
const money=n=>'Rp '+Number(n).toLocaleString('id-ID');
const period='tanggal_awal=2026-10-01&tanggal_akhir=2026-10-07';
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});let states=0;try{
 const identity=await (await browser.newContext()).request.get(base+'/tests/browser_clone_identity.php');assert.equal((await identity.json()).database,fixture.database);
 for(const unit of [1,2,3,0]){
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.units[unit].cookies.PHPSESSID,url:base}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const theme of ['light','dark'])for(const width of [1600,900,390]){
   await page.setViewportSize({width,height:1000});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
   for(const path of ['/laporan/index.php?'+period,'/tabungan/riwayat.php?'+period]){
    const response=await page.goto(base+path);assert.equal(response.status(),200);await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t},theme);assert.equal(await page.locator('html').getAttribute('data-theme'),theme);await page.waitForTimeout(120);
    assert.doesNotMatch(await page.content(),/Fatal error|Warning:/);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,'Overflow '+unit+' '+width+' '+path);
    const input=page.locator(path.startsWith('/laporan')?'#report-siswa-search':'#savings-history-student');
    await input.scrollIntoViewIfNeeded();await input.click();await input.fill('Siswa');
    const option=page.locator('.student-search-panel:not([hidden]) .student-search-option').first();await option.waitFor();
    assert.equal(await option.evaluate(e=>{const r=e.getBoundingClientRect();const hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return r.top>=0&&r.bottom<=innerHeight&&e.contains(hit)}),true,'Student option obscured '+width+' '+path);
    assert.ok(await input.evaluate(e=>e.getBoundingClientRect().width)>180,'Search input squeezed');
    await page.screenshot({path:dir+'/'+(path.startsWith('/laporan')?'finance':'savings')+'-'+unit+'-'+width+'-'+theme+'.png'});
    await option.click();assert.ok(Number(await page.locator('input[name="student_id"]').first().inputValue())>0);await input.press('Escape');
    if(path.startsWith('/laporan')){
     await page.goto(base+'/laporan/index.php?'+period);const expected=fixture.units[unit];
     assert.equal(await page.locator('.finance-stat.is-payment strong').innerText(),money(expected.payment_total));
     assert.equal(await page.locator('.finance-stat.is-count strong').innerText(),String(expected.rows.length));
     assert.equal(await page.locator('.finance-stat.is-in strong').innerText(),money(expected.incoming.total));assert.equal(await page.locator('.finance-stat.is-out strong').innerText(),money(expected.outgoing.total));
     await page.locator('[data-finance-tab="komponen"]').click();assert.equal(await page.locator('.finance-transactions').isVisible(),false);assert.equal(await page.locator('.finance-components').isVisible(),true);
     await page.locator('[data-finance-tab="transaksi"]').click();assert.equal(await page.locator('.finance-components').isVisible(),false);await page.reload();assert.equal(await page.locator('.finance-results').getAttribute('data-finance-panel'),'transaksi');
    }
    states++;
   }
  }
  // All pages match source identities, not merely the first visible table.
  const got=[];for(let p=1;p<=Math.max(1,Math.ceil(fixture.units[unit].rows.length/50));p++){
   await page.goto(base+'/laporan/index.php?'+period+'&per_page=50&page='+p+'&panel=transaksi');
   got.push(...await page.locator('#tbl-laporan .row-print-check').evaluateAll(rows=>rows.map(row=>Number(row.value))));
   for(const link of await page.locator('.du-pagination-footer a').all()){assert.equal(new URL(await link.getAttribute('href'),base+'/laporan/').searchParams.get('panel'),'transaksi');}
  }assert.deepEqual(got,fixture.units[unit].rows.map(r=>Number(r.id)));
  // Strict multiple report selection still renders named sections and keeps export parameters.
  await page.goto(base+'/laporan/index.php?'+period+'&jenis_laporan[]=sudah_bayar&jenis_laporan[]=belum_du&panel=transaksi&per_page=50');
  assert.ok(await page.locator('.report-multiple-section').count()>0);assert.match(await page.locator('.report-multiple-section h3').first().innerText(),/Yang sudah bayar|Daftar ulang/);
  assert.deepEqual(Array.from(new URL(await page.locator('.report-export-actions a').first().getAttribute('href'),base+'/laporan/').searchParams).filter(([key])=>/^jenis_laporan\[.*\]$/.test(key)).map(([,value])=>value),['sudah_bayar','belum_du']);
  assert.equal((await page.request.get(base+'/laporan/index.php?jenis_laporan[]=invalid')).status(),400);
  await page.goto(base+'/laporan/index.php?'+period+'&q=NO_MATCH_FINANCE_QA');assert.equal(await page.locator('.finance-stat.is-count strong').innerText(),'0');assert.match(await page.locator('#tbl-laporan').innerText(),/Belum ada data/);
  if(unit===1){
   await page.evaluate(()=>{localStorage.setItem('spp_theme','light');document.documentElement.dataset.theme='light'});await page.addInitScript(()=>localStorage.setItem('spp_theme','light'));await page.setViewportSize({width:1600,height:1000});
   await page.goto(base+'/laporan/index.php?'+period);await page.locator('.finance-row-menu summary').first().click();await page.locator('.open-payment-activity').first().click();await page.waitForFunction(()=>document.querySelector('.payment-activity-content').textContent&&!document.querySelector('.payment-activity-content').textContent.includes('Memuat'));assert.ok(await page.locator('.payment-activity-list').count()>0);await page.locator('[data-close-activity]').click();
   // Verify the UI switch and its icon color on all units, then reject All on entry forms.
   for(const target of [2,3,0,1]){await Promise.all([page.waitForNavigation(),page.locator('[data-unit-choice="'+target+'"]').click()]);assert.equal(await page.locator('.sidebar-unit-form').getAttribute('data-operational-unit'),String(target));assert.equal(await page.locator('[data-unit-choice="'+target+'"]').getAttribute('aria-pressed'),'true');const c=await page.locator('.sidebar-unit-heading-icon').evaluate(e=>getComputedStyle(e).color);assert.equal(c,{1:'rgb(21, 140, 73)',2:'rgb(22, 112, 201)',3:'rgb(220, 53, 69)',0:'rgb(124, 58, 237)'}[target]);}
   await page.goto(base+'/tabungan/masuk.php');assert.equal(await page.locator('[data-unit-choice="0"]').isDisabled(),true);
  }
  assert.deepEqual(errors,[]);await context.close();
 }
 for(const role of ['admin','bendahara','kasir']){
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.roles[role].cookies.PHPSESSID,url:base}]);const page=await context.newPage();
  const r=await page.goto(base+'/laporan/index.php?'+period);assert.equal(r.status(),200);if(role==='kasir')assert.ok(page.url().includes('/tabungan/masuk.php'));else assert.ok(page.url().includes('/laporan/index.php'));assert.equal(await page.locator('.sidebar-unit-segments').count(),0);
  if(role!=='kasir'){await page.waitForTimeout(120);for(const box of await page.locator('.row-print-check:disabled').all())assert.equal(await box.isDisabled(),true);const locked=await page.locator('.row-print-check:disabled').first().getAttribute('value');if(locked)assert.equal((await page.request.get(base+'/laporan/export_pdf.php?'+period+'&mode=selected&ids[]='+locked)).status(),403);}
  await context.close();
 }
 const nojs=await browser.newContext({javaScriptEnabled:false});await nojs.addCookies([{name:'PHPSESSID',value:fixture.units[3].cookies.PHPSESSID,url:base}]);const page=await nojs.newPage();await page.goto(base+'/laporan/index.php?'+period);await page.locator('[data-finance-tab="transaksi"]').click();assert.equal(await page.locator('.finance-results').getAttribute('data-finance-panel'),'transaksi');assert.equal(await page.locator('#sidebar-unit-select').isVisible(),true);await nojs.close();
 console.log('OK: '+states+' visual/search states; filter/source totals, all pages, tabs, activity, print permissions, unit switches, and no-JS fallback.');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});
