/* Read/display/export regression against an explicitly owned isolated restore. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,root=process.env.SPP_DU_ARTIFACTS;
assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));
assert.equal(new URL(base).hostname,'127.0.0.1');
(async()=>{
 const browser=await pw.chromium.launch({headless:true,channel:'chrome'}),checks=[];
 const check=(ok,label)=>{assert.ok(ok,label);checks.push(label)};
 try{
  const c=await browser.newContext({viewport:{width:1500,height:1000},acceptDownloads:true});
  const identity=await (await c.request.get(base+'/tests/browser_clone_identity.php')).json();
  const manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8').replace(/^\uFEFF/,''));
  check(identity.isolated_test&&identity.server_uuid===manifest.server_uuid&&identity.port!==3306,'Owned isolated database');
  if(process.env.SPP_TEST_SESSION_ID){await c.addCookies([{name:'PHPSESSID',value:process.env.SPP_TEST_SESSION_ID,url:base}]);check((await c.request.get(base+'/dashboard.php')).status()===200,'Isolated actor session');}else{
  const login=await c.request.post(base+'/login.php',{form:{username:'superadmin',password:process.env.SPP_TEST_SUPERADMIN_PASSWORD},maxRedirects:0});check(login.status()===302,'Isolated login');
  }
  await c.route('https://fonts.googleapis.com/**',r=>r.abort());await c.addInitScript(()=>window.print=()=>{});
  const p=await c.newPage(),errors=[];p.setDefaultTimeout(120000);p.on('pageerror',e=>errors.push(e.message));
  await p.goto(base+'/dashboard.php');
  const csrf=await p.locator('form[action$="unit_switch.php"] input[name="csrf_token"]').first().inputValue();
  const expected={1:[331,'1.362.165.000','1.241.134.700',83],2:[198,'878.805.000','831.750.000',103],3:[46,'221.950.000','204.150.000',21]};
  for(const [unit,[bills,total,paid,unknown]] of Object.entries(expected)){
   await c.request.post(base+'/unit_switch.php',{form:{unit_id:unit,csrf_token:csrf,next:'/dashboard.php'},maxRedirects:0});
   const r=await p.goto(base+'/master_daftar_ulang.php?tahun=2026%2F2027',{timeout:120000});check(r.status()===200,'Master DU unit '+unit);
   const text=await p.locator('.du-save-footer').innerText();
   check(text.includes('Rp '+total),'Master totals use persisted bills unit '+unit);
   check((await p.locator('.du-stat-orange .du-stat-copy strong').innerText())==='0','Covered pupils are excluded from missing bill count unit '+unit);
   check(await p.getByRole('button',{name:'Simpan Tarif & Lengkapi Tagihan',exact:true}).count()===1,'Existing completion control unit '+unit);
   const missingLevel={1:1,2:7,3:10}[unit];const missing=await p.locator('article.du-rate-card').filter({hasText:'Kelas '+missingLevel}).first().innerText();check(missing.includes('DU tercakup dalam PSB')&&missing.includes('Tercakup PSB')&&missing.includes(String(unknown)),'First-class policy card unit '+unit);check(await p.locator('#du-rate-'+missingLevel).count()===0,'No separate first-class tariff field unit '+unit);
   await p.screenshot({path:path.join(root,'master-du-'+unit+'.png'),fullPage:true,animations:'disabled'});
  }
  await c.request.post(base+'/unit_switch.php',{form:{unit_id:'2',csrf_token:csrf,next:'/dashboard.php'},maxRedirects:0});
  await p.goto(base+'/master_daftar_ulang.php?tahun=2026%2F2027',{timeout:120000});check(await p.locator('input[name=confirm_previous_debt]').count()===0,'No blocking prior debt checkbox');
  await Promise.all([p.waitForNavigation({timeout:120000}),p.getByRole('button',{name:'Simpan Tarif & Lengkapi Tagihan',exact:true}).click()]);check((await p.locator('body').innerText()).includes('0 tagihan baru'),'Existing GUI completion keeps paid bills and creates no duplicates: '+await p.locator('#flash-msg').innerText());
  const url=base+'/pembayaran/riwayat_daftar_ulang.php?q=252607034&year=2026%2F2027';
  await p.goto(url,{timeout:120000});const detail=await p.locator('.ph-detail-body').innerText();
  check(detail.includes('Raisa Putri Hapsary')&&detail.includes('4.310.000')&&detail.includes('Lunas'),'Raisa history linked to bill');
  check(detail.includes('Terbayar Rp 4.310.000')&&detail.includes('Sisa Rp 0'),'Operational history agrees with combined source payment: '+detail);
  const receiptUrl=await p.locator('.ph-detail-body a').filter({hasText:'Cetak Bukti Transaksi'}).first().getAttribute('href');
  const receipt=await c.request.get(new URL(receiptUrl,url).href);const html=await receipt.text();check(receipt.ok()&&html.includes('Daftar Ulang'),'DU cash receipt reachable from history');
  await p.screenshot({path:path.join(root,'raisa-du-history.png'),fullPage:true});
  for(const source of ['system','legacy']){await p.goto(url+'&source='+source,{timeout:120000});const filtered=await p.locator('.ph-detail-body').innerText();check(filtered.includes('Lunas')&&filtered.includes('Sisa Rp 0'),'Source '+source+' keeps canonical settlement');}
  await p.goto(url,{timeout:120000});
  const excel=await c.request.get(url+'&output=excel',{timeout:120000});const x=await excel.body();check(excel.ok()&&x.subarray(0,2).toString()==='PK','DU history Excel generated');fs.writeFileSync(path.join(root,'du-history.xlsx'),x);
  const status=await (await c.request.get(base+'/pembayaran/status_spp.php?no_induk=252607034&bulan=10&tahun=2026&details=1')).json();
  const bill=status.payment_details.duBills.find(b=>b.tahun_ajaran==='2026/2027');check(bill&&bill.total===4310000&&bill.terbayar===4310000&&bill.sisa===0,'Input Payment receives canonical DU bill');
  check(!status.obligations.rows.some(r=>r.kind==='du'),'No paid DU in obligations popup');
  const report=base+'/laporan/template.php?template=per-item&kategori=daftar_ulang&tahun_ajaran=2026%2F2027&q=252607034&unit=2';
  await p.goto(report,{timeout:120000});const body=await p.locator('body').innerText();check(body.includes('Raisa Putri Hapsary')&&body.includes('4.310.000'),'Global DU report includes same amount');
  const excelUrl=await p.locator('.report-export-actions a').filter({hasText:'Excel'}).getAttribute('href');const rex=await c.request.get(new URL(excelUrl+'&download=1',report).href,{timeout:120000});const rexBytes=await rex.body();check(rex.ok()&&rexBytes.subarray(0,2).toString()==='PK','Global DU Excel generated');fs.writeFileSync(path.join(root,'du-global.xlsx'),rexBytes);
  const pdfUrl=await p.locator('.report-export-actions a').filter({hasText:'PDF'}).getAttribute('href');const preview=await c.request.get(new URL(pdfUrl,report).href,{timeout:120000});check(preview.ok()&&(await preview.text()).includes('4.310.000'),'Global DU print/PDF preview has canonical amount');
  const pdfDownload=new URL(pdfUrl,report);pdfDownload.searchParams.set('format','pdf');pdfDownload.searchParams.set('download','1');const pdf=await c.request.get(pdfDownload.href,{timeout:120000});const pdfBytes=await pdf.body();check(pdf.ok()&&pdfBytes.subarray(0,4).toString()==='%PDF','Global DU PDF generated');fs.writeFileSync(path.join(root,'du-global.pdf'),pdfBytes);
  await p.screenshot({path:path.join(root,'raisa-du-global.png'),fullPage:true});
  await c.request.post(base+'/unit_switch.php',{form:{unit_id:'1',csrf_token:csrf,next:'/dashboard.php'},maxRedirects:0});
  await p.goto(base+'/master_daftar_ulang.php?tahun=2026%2F2027',{timeout:120000});
  const totalBefore=await p.locator('[data-du-issued-total]').innerText();
  check(await p.locator('#du-rate-1').count()===0,'No editable first-class DU tariff');
  await Promise.all([p.waitForNavigation({timeout:120000}),p.getByRole('button',{name:'Simpan Tarif & Lengkapi Tagihan',exact:true}).click()]);
  check((await p.locator('#flash-msg').innerText()).includes('0 tagihan baru'),'Covered first class creates no new DU bills');
  check((await p.locator('[data-du-issued-total]').innerText())===totalBefore,'Policy save preserves separate DU totals');
  const abd=await (await c.request.get(base+'/pembayaran/status_spp.php?no_induk=262701104&bulan=10&tahun=2026&details=1')).json();
  check(abd.payment_details.duCoveredPsb==='1'&&!abd.payment_details.duBills.some(b=>b.tahun_ajaran==='2026/2027'),'Abdullah is covered without a synthetic bill');
  await p.goto(base+'/pembayaran/form.php');await p.locator('#siswa-search').fill('262701104');await p.locator('#siswa-search').dispatchEvent('input');
  await p.waitForFunction(()=>document.getElementById('disp-nis').value==='262701104'&&document.getElementById('du-context-label').textContent.includes('tercakup PSB'));
  check(await p.locator('#du-input').evaluate(e=>e.readOnly),'Cashier cannot charge separate covered DU');
  check(await p.locator('#du-master-warning').isHidden(),'Covered DU has no missing-tariff warning');
  await p.screenshot({path:path.join(root,'abdullah-cashier-covered.png'),fullPage:true});
  await p.goto(base+'/pembayaran/riwayat_daftar_ulang.php?q=262701104&year=2026%2F2027');
  check((await p.locator('.ph-detail-body').innerText()).includes('Tercakup PSB'),'Covered pupil has readable DU history');
  await p.goto(base+'/master_daftar_ulang.php?tahun=2026%2F2027');
  await p.screenshot({path:path.join(root,'master-du-covered-grade1.png'),fullPage:true});
  await p.setViewportSize({width:390,height:844});
  await p.waitForFunction(()=>document.body.scrollWidth<=innerWidth+1,{},{timeout:5000});
  check(await p.locator('body').evaluate(e=>e.scrollWidth<=innerWidth+1),'Mobile has no horizontal overflow');
  await p.screenshot({path:path.join(root,'master-du-mobile.png'),fullPage:true});
  await p.evaluate(()=>document.documentElement.setAttribute('data-theme','dark'));
  await p.screenshot({path:path.join(root,'master-du-dark.png'),fullPage:true});
  check(errors.length===0,'No browser errors: '+errors.join('; '));
  fs.writeFileSync(path.join(root,'du-browser-results.json'),JSON.stringify({checks:checks.length,passed:checks},null,2));console.log('PASS '+checks.length+' DU browser checks');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
