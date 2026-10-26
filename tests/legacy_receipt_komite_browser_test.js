/* Real UI and download assertions, only against the owned isolated clone. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_RECEIPT_KOMITE_ARTIFACTS,base=process.env.SPP_TEST_BASE_URL;
assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));
assert.equal(new URL(base).hostname,'127.0.0.1');
(async()=>{
 const config=JSON.parse(fs.readFileSync(path.join(root,'browser-config.json'),'utf8'));
 const manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8').replace(/^\uFEFF/,''));
 const browser=await pw.chromium.launch({headless:true,channel:'chrome'}),checks=[];
 const check=(ok,label)=>{assert.ok(ok,label);checks.push(label)};
 try{
  const c=await browser.newContext({acceptDownloads:true,viewport:{width:1500,height:1000}});
  const identity=await (await c.request.get(base+'/tests/browser_clone_identity.php')).json();check(identity.isolated_test&&identity.server_uuid===manifest.server_uuid&&identity.port!==3306,'Owned isolated server');
  const login=await c.request.post(base+'/login.php',{form:{username:'superadmin',password:process.env.SPP_TEST_SUPERADMIN_PASSWORD},maxRedirects:0});check(login.status()===302,'Clone login');
  await c.addInitScript(()=>{window.print=()=>{}});await c.route('https://fonts.googleapis.com/**',r=>r.abort());
  const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.goto(base+'/dashboard.php');const csrf=await p.locator('form[action$="unit_switch.php"] input[name="csrf_token"]').first().inputValue();await c.request.post(base+'/unit_switch.php',{form:{unit_id:'2',csrf_token:csrf,next:'/dashboard.php'},maxRedirects:0});
  await p.goto(base+'/pembayaran/form.php');
  const response=p.waitForResponse(r=>r.url().includes('/pembayaran/status_spp.php')&&r.url().includes('252607034')&&r.status()===200);
  await p.locator('#siswa-search').fill('Raisa Putri Hapsary');const data=await (await response).json();check(data.obligations.rows.length===0&&data.obligations.oldest_period===null,'Raisa API has no false obligations');
  await p.waitForFunction(()=>document.getElementById('disp-nis').value==='252607034'&&document.getElementById('komite-context-label').textContent.includes('Oktober'));
  check(!(await p.locator('#spp-warning-overlay').evaluate(e=>e.classList.contains('show')&&e.dataset.statusCode==='obligations_summary')),'No false Komite popup on student selection');
  const october=data;check(october.selected.remaining===327500&&october.komite.total-october.komite.paid===15000,'October pair is still due');
  await p.screenshot({path:path.join(root,'raisa-october.png'),fullPage:true});
  const old=await p.evaluate(async()=>{document.getElementById('tahun-bayar').value='2025';document.getElementById('bulan-bayar').value='08';return requestSppStatus({interactive:false,force:true});});
  check(old.obligations.rows.length===0,'Historic selection has no false arrears');
  const api=await (await c.request.get(base+'/pembayaran/status_spp.php?no_induk=252607034&bulan=08&tahun=2025')).json();
  check(api.selected.remaining===0&&api.komite.total===15000&&api.komite.paid===15000,'August 2025 SPP and Komite remain settled');
  await p.goto(base+'/pembayaran/lihat.php?all_periods=1&selected='+config.payment_id);
  const history=await p.locator('[data-payment-detail]').innerText();check(history.includes('Formulir Pendaftaran')&&!/Legacy #55313|kode 0018|slot 1/.test(history),'History item has only its name');
  await p.goto(base+'/laporan/cetak_struk.php?id='+config.payment_id);await p.evaluate(()=>document.fonts.ready);
  const body=await p.locator('.receipt-sheet').innerText();check(body.includes('Formulir Pendaftaran')&&body.includes('75.000')&&!/Legacy #55313|kode 0018|slot 1/.test(body),'Receipt label clean and amount preserved');
  const du=await p.locator('.payments tr').filter({hasText:'Sisa DU'}).innerText();check(du.trim().endsWith('-')&&!du.includes('Belum dapat'),'Unknown DU displays dash');
  await p.screenshot({path:path.join(root,'receipt-preview.png'),fullPage:true});
  const pdf=await c.request.get(base+'/laporan/cetak_struk.php?id='+config.payment_id+'&output=pdf');const bytes=await pdf.body();check(pdf.ok()&&bytes.subarray(0,4).toString()==='%PDF','PDF generated from clean document');fs.writeFileSync(path.join(root,'receipt.pdf'),bytes);
  const dl=p.waitForEvent('download',{timeout:120000});await p.locator('[data-receipt-png]').click();const download=await dl;await download.saveAs(path.join(root,'receipt.png'));
  const png=fs.readFileSync(path.join(root,'receipt.png'));check(png.subarray(1,4).toString()==='PNG','PNG actually downloaded');check(png.readUInt32BE(16)===2550&&png.readUInt32BE(20)===1650,'PNG has Letter half dimensions');
  await p.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);check((await p.locator('[data-receipt-export-status]').innerText()).includes('Gambar selesai'),'PNG export finished successfully');
  // Twelve fixture copies exercise the existing annual paginator/image exporter without issuing bills.
  await p.evaluate(()=>{const first=document.querySelector('.receipt-sheet');first.classList.remove('receipt-last');for(let i=1;i<12;i++){const copy=first.cloneNode(true);if(i===11)copy.classList.add('receipt-last');document.body.append(copy);}});
  const annualDownload=p.waitForEvent('download',{timeout:120000});await p.locator('[data-receipt-png]').click();await (await annualDownload).saveAs(path.join(root,'receipt-annual.zip'));
  const zip=fs.readFileSync(path.join(root,'receipt-annual.zip'));const count=await p.evaluate(async(encoded)=>Object.keys((await JSZip.loadAsync(encoded,{base64:true})).files).filter(n=>n.endsWith('.png')).length,zip.toString('base64'));check(count===12,'Annual PNG export contains twelve receipts');
  await p.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);
  check((await p.locator('.receipt-sheet').allInnerTexts()).every(t=>t.includes('Sisa DU')&&!/Belum dapat ditentukan|Legacy #55313|kode 0018/.test(t)),'Annual document preserves clean labels and unknown DU');
  await p.pdf({path:path.join(root,'receipt-annual-browser.pdf'),preferCSSPageSize:true,printBackground:true});
  check(errors.length===0,'No browser errors: '+errors.join('; '));
  fs.writeFileSync(path.join(root,'browser-results.json'),JSON.stringify({checks:checks.length,passed:checks},null,2));console.log('PASS '+checks.length+' browser/download checks');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
