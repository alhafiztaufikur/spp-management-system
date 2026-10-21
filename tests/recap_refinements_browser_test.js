const fs=require('fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'C:/laragon/backups/spp-management-system/import_audit_20261020/browser-tools/node_modules/playwright-core');
const root=process.argv[2];assert(root?.includes('recap_refinements_'));const fixture=JSON.parse(fs.readFileSync(root+'/fixture.json'));const base='http://127.0.0.1:58512';
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const errors=[],timings=[];
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}});await context.addCookies([{name:'PHPSESSID',value:fixture.sessions.admin['1'],url:base}]);
  await context.route('https://**/*',route=>route.abort());const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(base+'/master_daftar_ulang.php');
  const buttons=page.locator('.du-publishing-actions button');assert.equal(await buttons.count(),2);const boxes=await buttons.evaluateAll(nodes=>nodes.map(n=>n.getBoundingClientRect().top));assert(Math.abs(boxes[0]-boxes[1])<=3,'Publishing buttons aligned');
  await page.screenshot({path:root+'/artifacts/publishing-desktop.png',fullPage:true});
  await page.goto(base+'/master_kelas.php?source_level=1&show_participants=1');let navigations=0;page.on('request',request=>{if(request.isNavigationRequest()&&request.frame()===page.mainFrame())navigations++;});
  await page.locator('#promotion-source-level').selectOption('2');await page.waitForTimeout(350);assert.equal(navigations,0,'Selecting level does not submit');assert(await page.locator('#promotion-context-pending').isVisible());
  await Promise.all([page.waitForURL(url=>url.searchParams.get('source_level')==='2'),page.locator('#promotion-context-form button[type=submit]').click()]);
  await page.locator('.participants-table').waitFor();await page.screenshot({path:root+'/artifacts/participants-desktop.png',fullPage:true});
  await page.goto(base+'/master_biaya_lain.php?master_tab=hapus');assert.equal(await page.locator('.fee-master-tabs a').count(),3);assert.match(await page.locator('.fee-master-tabs [aria-current=page]').innerText(),/Bisa Dihapus/);
  assert.equal(await page.locator('.fee-list-panel button.btn-tbl-del:disabled').count(),0);await page.screenshot({path:root+'/artifacts/fee-groups.png',fullPage:true});
  await page.goto(base+'/legacy_correction.php?kind=journal&id='+fixture.samples['1'].id);assert.equal(await page.locator('[name=owner_id]').getAttribute('type'),'hidden');assert.equal(await page.locator('[name=cancelled]').getAttribute('type'),'hidden');
  const amount=page.locator('[name=incoming_display]');await amount.fill('');await amount.pressSequentially('100000');assert.equal(await amount.inputValue(),'100.000');await amount.fill('');await amount.pressSequentially('100000.13');await amount.blur();assert.equal(await amount.inputValue(),'100.000,13');
  await page.screenshot({path:root+'/artifacts/correction-admin.png',fullPage:true});
  await page.goto(base+'/laporan/index.php?jenis_laporan%5B%5D=*&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-21');assert.equal(await page.locator('[data-report-section]').count(),5);
  const payment=page.locator('[data-report-section=semua]'),du=page.locator('[data-report-section=belum_du]');const duBefore=await du.innerHTML();const documentNav=navigations;
  let responsePromise=page.waitForResponse(r=>r.url().includes('fragment=section')&&r.url().includes('section=semua'));
  const [,response]=await Promise.all([payment.locator('a[data-section-page="2"]').first().click(),responsePromise]);timings.push({type:'semua',ms:Number(response.headers()['x-audit-ms'])});
  await page.waitForFunction(()=>document.querySelector('[data-report-section=semua]').dataset.page==='2');assert.equal(navigations,documentNav,'Section pagination never navigates document');assert.equal(await du.innerHTML(),duBefore,'Other section unchanged');
  await page.goBack();await page.waitForFunction(()=>document.querySelector('[data-report-section=semua]').dataset.page==='1');
  const beforeFailure=await payment.locator('tbody').innerHTML();await page.route('**/*fragment=section*',route=>route.abort('failed'));
  await payment.locator('a[data-section-page="2"]').first().click();await payment.getByRole('button',{name:'Coba lagi'}).waitFor();assert.equal(await payment.locator('tbody').innerHTML(),beforeFailure);await page.unroute('**/*fragment=section*');await payment.getByRole('button',{name:'Coba lagi'}).click();await page.waitForFunction(()=>document.querySelector('[data-report-section=semua]').dataset.page==='2');
  await page.route('**/*fragment=section*',async route=>{try{const response=await route.fetch();if(new URL(route.request().url()).searchParams.get('section_page[semua]')==='3')await new Promise(resolve=>setTimeout(resolve,400));await route.fulfill({response});}catch(_) {}});
  await payment.locator('a[data-section-page="3"]').first().click();await page.waitForTimeout(120);await payment.locator('a[data-section-page="4"]').first().click();await page.waitForFunction(()=>document.querySelector('[data-report-section=semua]').dataset.page==='4');await page.waitForTimeout(500);assert.equal(await payment.getAttribute('data-page'),'4','Late section cannot replace newer selection');await page.unroute('**/*fragment=section*');
  let scrollRequests=0;const onScrollRequest=request=>{if(request.url().includes('fragment=section'))scrollRequests++};page.on('request',onScrollRequest);await payment.locator('.table-container').evaluate(el=>{el.scrollTop=200;el.scrollLeft=200});await page.waitForTimeout(150);assert.equal(scrollRequests,0,'Scrolling never requests data');page.off('request',onScrollRequest);
  await page.screenshot({path:root+'/artifacts/five-sections-desktop.png',fullPage:true});
  for(const route of ['master_daftar_ulang.php','tabungan/keluar.php','legacy_correction.php?kind=journal&id='+fixture.samples['1'].id,'laporan/index.php?jenis_laporan%5B%5D=*&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-21',...['setoran','kas-tabungan','penerimaan','tabungan-siswa'].map(t=>'laporan/template.php?template='+t+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-21')]){
   for(const theme of ['light','dark'])for(const width of [1440,900,390]){
    await page.setViewportSize({width,height:1000});await page.goto(base+'/'+route);await page.evaluate(t=>{document.documentElement.dataset.theme=t;localStorage.setItem('spp_theme',t)},theme);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,route+' '+width+' page overflow');
    if(route.includes('template.php')){const grid=page.locator('.report-template-filters');const columns=await grid.evaluate(e=>getComputedStyle(e).gridTemplateColumns);console.log(JSON.stringify({route,width,columns}));}
    await page.screenshot({path:root+'/artifacts/'+route.split('?')[0].replaceAll('/','-')+'-'+(route.includes('template=')?new URL(base+'/'+route).searchParams.get('template')+'-':'')+theme+'-'+width+'.png',fullPage:true});
   }
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(root+'/browser-results.json',JSON.stringify({ok:true,timings,errors},null,2));console.log(JSON.stringify({ok:true,timings}));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
