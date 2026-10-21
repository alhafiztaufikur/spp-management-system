const fs=require('fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'C:/laragon/backups/spp-management-system/import_audit_20261020/browser-tools/node_modules/playwright-core');
const root=process.argv[2];assert(root?.includes('menu_speed_'));
const sessions=JSON.parse(fs.readFileSync(root+'/sessions.json','utf8'));
const base='http://127.0.0.1:58311';
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const errors=[],results=[],externalFonts=[];
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}});
  await context.addCookies([{name:'PHPSESSID',value:sessions['1'],url:base}]);
  await context.route('https://**/*',route=>route.abort());
  context.on('request',request=>{if(/fonts\.(googleapis|gstatic)\.com/.test(request.url()))externalFonts.push(request.url());});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/pembayaran/form.php');
  const students=await page.locator('#siswa-list option').evaluateAll(options=>options.slice(0,3).map(o=>({name:o.value,nis:o.dataset.nis,id:o.dataset.studentId})));
  assert(students.length>=2);
  const statusRequests=[],payloads=[];page.on('request',r=>{if(r.url().includes('/status_spp.php?'))statusRequests.push(r.url());});
  for(const student of students.slice(0,2)){
   const started=Date.now();
   const response=page.waitForResponse(r=>r.url().includes('/status_spp.php?')&&new URL(r.url()).searchParams.get('no_induk')===student.nis);
   await page.locator('#siswa-search').fill(student.name);
   const statusResponse=await response;const data=await statusResponse.json();payloads.push(data);assert.equal(data.ok,true,JSON.stringify(data));assert(data.payment_details);
   if(data.obligations.rows.length){await page.locator('#spp-warning-title').waitFor({state:'visible'});assert.equal(await page.locator('#spp-warning-title').innerText(),'Ringkasan Kewajiban Siswa');results.push({summary_ms:Date.now()-started,server_ms:Number(statusResponse.headers()['x-audit-ms'])});await page.locator('#spp-warning-close').click();}
  }
  await page.route('**/status_spp.php?*',async route=>{const first=new URL(route.request().url()).searchParams.get('no_induk')===students[0].nis;if(first)await new Promise(resolve=>setTimeout(resolve,450));try{await route.fulfill({contentType:'application/json',body:JSON.stringify(payloads[first?0:1])});}catch(_){}});
  await page.locator('#siswa-search').fill(students[0].name);await page.locator('#siswa-search').fill(students[1].name);
  await page.waitForFunction(nis=>document.getElementById('disp-nis').value===nis&&sppStatusState.payload?.ok,students[1].nis);await page.waitForTimeout(500);
  assert.equal(await page.locator('#disp-nis').inputValue(),students[1].nis,'Late response cannot replace selected student');
  await page.evaluate(()=>closeSppWarning());await page.unroute('**/status_spp.php?*');
  await page.route('**/status_spp.php?*',route=>route.abort('failed'));
  assert.equal(await page.evaluate(async()=>{const data=await requestSppStatus({force:true,interactive:false});return data?.ok===true;}),false,'Network failure must not be treated as valid status');
  await page.unroute('**/status_spp.php?*');
  assert.equal(await page.evaluate(async()=>{const data=await requestSppStatus({interactive:false});return data?.ok===true;}),true,'Failed status remains retryable');
  await page.screenshot({path:root+'/artifacts/payment.png',fullPage:true});
  await page.goto(base+'/tabungan/keluar.php');await page.locator('.sw-student-option').first().click();
  const form=page.locator('[data-savings-entry]'),amount=page.locator('[name=nominal]');
  await amount.fill('100000');assert.equal(await amount.inputValue(),'100.000');
  await amount.fill('100000,50');assert.equal(await amount.inputValue(),'100.000,50');
  await amount.fill('');await amount.pressSequentially('100.000');await amount.blur();assert.equal(await amount.inputValue(),'100.000','Manually typed thousands separator preserves the amount');
  await amount.fill('');await amount.pressSequentially('100000.13');await amount.blur();assert.equal(await amount.inputValue(),'100.000,13','Typed decimal dot is displayed as Indonesian decimal comma');
  await amount.evaluate(el=>{const data=new DataTransfer();data.setData('text/plain','1234.131');el.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}));});
  assert.equal(await amount.inputValue(),'1234.131','Invalid pasted decimals are never silently changed into a larger amount');
  await form.evaluate(f=>f.requestSubmit());assert.equal(await page.locator('#savings-entry-dialog-title').innerText(),'Periksa nominal');await page.locator('#savings-entry-dialog-close').click();
  await amount.fill('');
  const balance=Number(await form.getAttribute('data-balance'));
  await amount.fill(String(Math.ceil(Math.max(0,balance))+100000));await form.evaluate(f=>f.requestSubmit());
  await page.locator('#savings-entry-dialog').waitFor({state:'visible'});assert.equal(await page.locator('#savings-entry-dialog-title').innerText(),'Saldo tidak mencukupi');
  await page.locator('#savings-entry-dialog-close').click();assert.equal(await amount.evaluate(e=>e===document.activeElement),true);
  await page.screenshot({path:root+'/artifacts/savings-withdrawal.png',fullPage:true});
  for(const route of ['tabungan/masuk.php','tabungan/keluar.php','tabungan/riwayat.php','master_daftar_ulang.php'])for(const theme of ['light','dark'])for(const width of [1440,900,390]){
   await page.setViewportSize({width,height:1000});await page.goto(base+'/'+route);await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t},theme);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,route+' '+width+' overflow');
   if(route==='tabungan/riwayat.php')assert.equal(await page.locator('.sw-toolbar-group').count(),3);
   await page.screenshot({path:root+'/artifacts/'+route.replaceAll('/','-')+'-'+theme+'-'+width+'.png',fullPage:true});
  }
  for(const multiple of [false,true])for(const sort of ['terbaru','nama','kelas','nominal_terbesar','nominal_terkecil','sisa_terbesar']){
   await page.setViewportSize({width:1440,height:1000});await page.goto(base+'/laporan/index.php?'+(multiple?'jenis_laporan%5B%5D=sudah_bayar':'jenis_laporan=semua')+'&urut='+sort);
   assert.equal(await page.locator('.report-transactions-table').count(),1);assert(await page.locator('.btn-tbl-print').count()>0,sort+' has print');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,'report overflow '+sort);
   assert.match(await page.locator('.btn-tbl-print').first().getAttribute('href'),/cetak_(struk|legacy)\.php\?/);
   assert(await page.locator('.btn-tbl-print').first().evaluate(button=>{const cell=button.closest('.table-container').getBoundingClientRect(),rect=button.getBoundingClientRect();return rect.left>=cell.left-1&&rect.right<=cell.right+1}),'Print action remains visible inside the table viewport');
  }
  await page.screenshot({path:root+'/artifacts/transactions-report.png',fullPage:true});
  for(const multiple of [false,true])for(const width of [900,390]){
   await page.setViewportSize({width,height:1000});await page.goto(base+'/laporan/index.php?'+(multiple?'jenis_laporan%5B%5D=sudah_bayar':'jenis_laporan=semua')+'&urut=nama');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,'Responsive report has no page overflow');
   assert(await page.locator('.btn-tbl-print').first().evaluate(button=>{const cell=button.closest('.table-container').getBoundingClientRect(),rect=button.getBoundingClientRect();return rect.left>=cell.left-1&&rect.right<=cell.right+1}),'Responsive print action remains visible');
  }
  await page.goto(base+'/tabungan/riwayat.php');const transaction=page.locator('.sw-transaction-select').first();if(await transaction.count()){await transaction.click();await page.waitForFunction(()=>!document.getElementById('sw-history-detail').hasAttribute('aria-busy'));const link=page.locator('.sw-detail-print-actions a');assert.equal(await link.count(),1);assert.match(await link.getAttribute('href'),/^cetak_buku\.php\?/);}
  assert.deepEqual(errors,[]);assert.deepEqual(externalFonts,[],'Navigation must not request external fonts');fs.writeFileSync(root+'/browser-results.json',JSON.stringify({checks:'responsive layouts, themes, formatted amounts, balance modal, scoped summary, stale responses, transaction print and local fonts',status_requests:statusRequests.length,results,errors,externalFonts},null,2));
  console.log(JSON.stringify({ok:true,results,errors}));await context.close();
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
