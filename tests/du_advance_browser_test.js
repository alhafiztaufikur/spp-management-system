/* Existing GUI and payment endpoint on an explicitly owned disposable restore. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,root=process.env.SPP_DU_ARTIFACTS;
assert.equal(new URL(base).hostname,'127.0.0.1');
assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));
(async()=>{
 const browser=await pw.chromium.launch({headless:true,channel:'chrome'}),checks=[];
 const check=(ok,label)=>{if(!ok)console.error('FAILED '+label);assert.ok(ok,label);checks.push(label);fs.writeFileSync(path.join(root,'advance-progress.json'),JSON.stringify({checks:checks.length,passed:checks},null,2))};
 try{
  const c=await browser.newContext({viewport:{width:1500,height:1000},acceptDownloads:true});
  const identity=await(await c.request.get(base+'/tests/browser_clone_identity.php')).json();
  const manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8').replace(/^\uFEFF/,''));
  check(identity.isolated_test&&identity.server_uuid===manifest.server_uuid&&identity.port===3319,'Isolated HTTP identity');
  check((await c.request.post(base+'/login.php',{form:{username:'superadmin',password:process.env.SPP_TEST_SUPERADMIN_PASSWORD},maxRedirects:0})).status()===302,'Login');
  const p=await c.newPage();p.setDefaultTimeout(120000);await c.addInitScript(()=>window.print=()=>{});
  await p.goto(base+'/dashboard.php');const csrf=await p.locator('form[action$="unit_switch.php"] input[name=csrf_token]').first().inputValue();
  await c.request.post(base+'/unit_switch.php',{form:{unit_id:'1',csrf_token:csrf,next:'/dashboard.php'},maxRedirects:0});
  await p.goto(base+'/master_daftar_ulang.php?tahun=2026%2F2027');const oldTotal=await p.locator('[data-du-issued-total]').innerText();
  await p.goto(base+'/master_daftar_ulang.php?tahun=2028%2F2029');check(await p.getByRole('button',{name:'Simpan Tarif & Lengkapi Tagihan',exact:true}).count()===0,'Beyond next year is read-only');
  await p.goto(base+'/master_daftar_ulang.php?tahun=2027%2F2028');check((await p.locator('body').innerText()).includes('Tagihan Mendatang'),'Master explains future billing');
  await p.locator('#du-rate-2').fill('3500000');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Simpan Tarif & Lengkapi Tagihan',exact:true}).click()]);
  const flash=await p.locator('#flash-msg').innerText();const grade2=await p.locator('article.du-rate-card').filter({hasText:'Kelas 2'}).first().innerText();
  check((flash.includes('83 tagihan baru')||flash.includes('0 tagihan baru'))&&grade2.includes('83 dari 83'),'Partial rate covers 83 projected grade-2 invoices');
  await p.screenshot({path:path.join(root,'advance-master.png'),fullPage:true});
  await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Simpan Tarif & Lengkapi Tagihan',exact:true}).click()]);check((await p.locator('#flash-msg').innerText()).includes('0 tagihan baru'),'GUI publication replay has no duplicates');
  await p.goto(base+'/master_daftar_ulang.php?tahun=2026%2F2027');check(await p.locator('[data-du-issued-total]').innerText()===oldTotal,'Current-year totals unchanged');
  const statusUrl=base+'/pembayaran/status_spp.php?no_induk=262701104&bulan=10&tahun=2026&details=1';
  let status=await(await c.request.get(statusUrl)).json();const future=status.payment_details.duBills.find(b=>b.tahun_ajaran==='2027/2028');
  check(future&&future.kelas==='2'&&future.is_future&&!future.is_arrear,'Cashier payload exposes future grade and classification');
  check(!status.obligations.rows.some(r=>r.kind==='du'),'Future omitted from current arrears');
  await p.goto(base+'/pembayaran/form.php');await p.locator('#siswa-search').fill('262701104');await p.locator('#siswa-search').dispatchEvent('input');
  await p.waitForFunction(()=>document.getElementById('disp-nis').value==='262701104'&&document.getElementById('du-context-label').textContent.includes('tercakup PSB'));
  check(await p.locator('#tagihan-daftar-ulang-id').inputValue()==='','Future is never auto-selected');
  await p.locator('#du-selector-trigger').click();const option=p.locator('.du-selector-option').filter({hasText:'2027/2028'});
  check((await option.innerText()).includes('Tagihan Mendatang'),'Dropdown labels future invoice');await option.click();
  check(!(await p.locator('#du-input').evaluate(e=>e.readOnly)),'Explicit future selection enables collection');
  check((await p.locator('#du-context-label').innerText()).includes('Tagihan Mendatang'),'Payment context labels future invoice');
  await p.screenshot({path:path.join(root,'advance-cashier.png'),fullPage:true});
  const post={aksi:'input',_operational_unit:'1',csrf_token:await p.locator('#form-pembayaran input[name=csrf_token],form input[name=csrf_token]').last().inputValue(),request_key:await p.locator('input[name=request_key]').inputValue(),no_induk:'262701104',tanggal_bayar:'2026-10-27',bulan_bayar:'10',tahun_bayar:'2026',sistem_pembayaran:'Tunai',uang_du:'500000',tagihan_daftar_ulang_id:String(future.id),du_expected_total:'3500000',du_expected_paid:'0'};
  // Scope CSRF to the existing payment form, excluding the navigation form.
  post.csrf_token=await p.locator('form').filter({has:p.locator('input[name=request_key]')}).locator('input[name=csrf_token]').inputValue();
  check((await c.request.post(base+'/pembayaran/proses.php',{form:post,maxRedirects:0})).status()===302,'Future instalment submitted');
  status=await(await c.request.get(statusUrl)).json();let paid=status.payment_details.duBills.find(b=>b.id===future.id);check(paid.terbayar===500000&&paid.sisa===3000000,'Instalment reaches only issued target-year bill');
  await c.request.post(base+'/pembayaran/proses.php',{form:post,maxRedirects:0});status=await(await c.request.get(statusUrl)).json();paid=status.payment_details.duBills.find(b=>b.id===future.id);check(paid.terbayar===500000,'Duplicate request cannot collect twice');
  const history=base+'/pembayaran/riwayat_daftar_ulang.php?q=262701104&year=2027%2F2028';await p.goto(history);const detail=await p.locator('.ph-detail-body').innerText();check(detail.includes('500.000')&&detail.includes('3.000.000'),'History reads future instalment');
  const receiptHref=await p.locator('.ph-detail-body a').filter({hasText:'Cetak Bukti Transaksi'}).first().getAttribute('href');const receipt=await c.request.get(new URL(receiptHref,history).href);const html=await receipt.text();check(receipt.ok()&&html.includes('2027/2028')&&html.includes('Kelas 2')&&html.includes('3.000.000'),'Receipt uses paid DU before current coverage');fs.writeFileSync(path.join(root,'advance-receipt.html'),html);
  const printPage=await c.newPage();await printPage.goto(new URL(receiptHref,history).href);await printPage.pdf({path:path.join(root,'advance-receipt.pdf'),format:'A5',printBackground:true});await printPage.close();check(true,'Future DU receipt rendered as PDF');
  const paymentId=new URL(receiptHref,history).searchParams.get('id');check(Number(paymentId)>0,'Receipt retains native payment identity');
  for(const amount of [300000,500000]){
   const corrected=await c.request.post(base+'/pembayaran/proses.php',{form:{...post,aksi:'update',id:paymentId,uang_du:String(amount)},maxRedirects:0});
   status=await(await c.request.get(statusUrl)).json();paid=status.payment_details.duBills.find(b=>b.id===future.id);
   check(corrected.status()===302&&paid.terbayar===amount&&paid.sisa===3500000-amount,'Existing correction updates future position: '+amount);
  }
  const report=base+'/laporan/template.php?template=per-item&kategori=daftar_ulang&tahun_ajaran=2027%2F2028&q=262701104&unit=1';await p.goto(report);check((await p.locator('body').innerText()).includes('500.000'),'Global report uses future payment');
  for(const format of ['Excel','PDF']){
   const href=await p.locator('.report-export-actions a').filter({hasText:format}).getAttribute('href');const u=new URL(href,report);u.searchParams.set('download','1');if(format==='PDF')u.searchParams.set('format','pdf');
   const response=await c.request.get(u.href,{timeout:120000}),bytes=await response.body();check(response.ok()&&bytes.subarray(0,format==='PDF'?4:2).toString()===(format==='PDF'?'%PDF':'PK'),format+' export generated');fs.writeFileSync(path.join(root,'advance-global.'+(format==='PDF'?'pdf':'xlsx')),bytes);
  }
  const removed=await c.request.post(base+'/pembayaran/proses.php',{form:{aksi:'hapus',_operational_unit:'1',id:paymentId,csrf_token:post.csrf_token},maxRedirects:0});status=await(await c.request.get(statusUrl)).json();paid=status.payment_details.duBills.find(b=>b.id===future.id);
  check(removed.status()===302&&paid.terbayar===0&&paid.sisa===3500000,'Existing cancellation restores future balance');
  fs.writeFileSync(path.join(root,'advance-results.json'),JSON.stringify({checks:checks.length,passed:checks},null,2));console.log('PASS '+checks.length+' advance GUI checks');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
