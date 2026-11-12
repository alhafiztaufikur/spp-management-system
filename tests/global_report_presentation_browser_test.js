/* Read-only visual/output coverage on the explicitly identified isolated server. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const base=process.env.SPP_TEST_BASE_URL,out=process.env.SPP_UI_OUTPUT;
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
const query=params=>{const q=new URLSearchParams();for(const[k,v]of Object.entries(params))for(const x of Array.isArray(v)?v:[v])q.append(k,x);return q.toString()};
const common={kelas:'tingkat:1',siswa_status:'active',tanggal_awal:'2026-09-01',tanggal_akhir:'2026-11-12',tahun_ajaran:'2026/2027',bulan_awal:'9',tahun_awal:'2026',bulan_akhir:'11',tahun_akhir:'2026'};
const reports=['riwayat-tagihan','penerimaan','spp-tahunan','per-item','status','tabungan-siswa','saldo-tabungan'];
const routes=['dashboard.php','master_spp.php','master_kelas.php','master_daftar_ulang.php','master_biaya_lain.php','siswa/index.php','siswa/tambah.php','pembayaran/form.php','pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php','tabungan/pemilik.php','tabungan/masuk.php','tabungan/keluar.php','tabungan/riwayat.php','laporan/index.php','laporan/global.php','laporan/surat_laporan.php','laporan/surat_orang_tua.php','role_management.php','backup_restore.php','legacy_import.php','legacy_full.php'];
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const ctx=await browser.newContext({viewport:{width:1440,height:1000}});const page=await ctx.newPage();page.setDefaultNavigationTimeout(240000);const errors=[],inventory=[];page.on('pageerror',e=>errors.push(e.message));
 async function radii(label){const bad=await page.evaluate(()=>[...document.querySelectorAll('button,input[type=submit],input[type=button],input[type=reset],[role=button],[role=tab],a')].filter(n=>n.getClientRects().length).flatMap(n=>{const c=getComputedStyle(n),button=n.tagName!=='A'||/btn|button|page-link|pagination-link|nav-item|preview-back|report-template-link/.test(n.className);const corners=[c.borderTopLeftRadius,c.borderTopRightRadius,c.borderBottomLeftRadius,c.borderBottomRightRadius];return button&&corners.some(x=>x!=='8px')?[{tag:n.tagName,cls:n.className,text:n.textContent.trim().slice(0,35),radius:corners}]:[]}));inventory.push({label,bad});assert.deepEqual(bad,[],label+' action radii');}
 try{
  const identity=await(await ctx.request.get(base+'/tests/browser_clone_identity.php')).json();assert.equal(identity.isolated_test,true);assert.notEqual(identity.port,3306);
  await page.goto(base+'/login.php');await radii('login');await page.locator('#username').fill('qa_principal');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);assert(!page.url().endsWith('/login.php'));
  for(const route of routes){const response=await page.goto(base+'/'+route);assert(response.status()<500,route);await radii(route);}
  for(const unit of [1,2,3,0]){
   await page.goto(base+'/dashboard.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
   for(const width of [1280,1440,1920])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:1000});await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t},theme);
    await page.goto(base+'/laporan/surat_orang_tua.php');await radii(`letters ${unit}/${width}/${theme}`);
    if(unit!==0&&width===1440){await page.locator('#letter-principal-add').click();await radii(`dialog ${unit}/${theme}`);await page.keyboard.press('Escape');}
    await page.goto(base+'/laporan/template.php?'+query({...common,unit:unit===0?'all':String(unit),template:'penerimaan'}));await radii(`report ${unit}/${width}/${theme}`);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Desktop page overflow');
    const summary=page.locator('.global-total-summary');if(await summary.count()){
      const b=await summary.boundingBox();assert(b.width<=481&&b.x+b.width<=width,'Summary fits');assert.equal(await summary.locator('h3').evaluate(n=>getComputedStyle(n).textAlign),'left');assert.equal(await summary.locator('td').first().evaluate(n=>getComputedStyle(n).textAlign),'right');
    }
    if(width===1440)await page.screenshot({path:path.join(out,`totals-u${unit}-${theme}.png`),fullPage:true});
   }
  }
  await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>{localStorage.setItem('spp_theme','light')});
  for(const template of reports){
   const params={...common,unit:'1',template,...(['status','per-item'].includes(template)?{'kategori[]':['spp','komite','daftar_ulang','psb']}:{})};
   await page.goto(base+'/laporan/template.php?'+query(params));await radii(template);
   if(template==='per-item'){
    assert.equal(await page.locator('[data-report-filter-pending]').count(),0);await page.locator('#global-siswa-search').fill('Nama belum diterapkan');
    const link=page.locator('.report-export-btn').first();assert.equal(await link.getAttribute('aria-disabled'),'true');await link.evaluate(n=>n.click());assert(await page.locator('.report-filter-actions [type=submit]').evaluate(n=>n===document.activeElement),'Pending export focuses submit');
   }
   if(template==='spp-tahunan'){const text=await page.locator('.report-horizontal-matrix tbody').innerText();assert(!/Lunas|Belum Bayar|Belum Diketahui/i.test(text));}
   if(template==='tabungan-siswa'){const text=await page.locator('.report-template-table thead').innerText();assert(!/Penyesuaian|Keterangan/.test(text));}
   await page.screenshot({path:path.join(out,template+'-desktop.png'),fullPage:true});
   for(const[format,ext]of [['pdf','pdf'],['excel','xlsx']]){
    const response=await ctx.request.get(base+'/laporan/export_global.php?'+query({...params,format,download:'1'}),{timeout:300000});const bytes=await response.body();assert.equal(response.status(),200,template+': '+bytes.subarray(0,250));assert(bytes.subarray(0,format==='pdf'?5:2).equals(Buffer.from(format==='pdf'?'%PDF-':'PK')),template+' '+format);fs.writeFileSync(path.join(out,template+'.'+ext),bytes);
   }
   await page.goto(base+'/laporan/export_global.php?'+query({...params,format:'excel'}));await radii(template+' preview');
   console.log('PASS screen/PDF/Excel',template);
  }
  fs.writeFileSync(path.join(out,'button-inventory.json'),JSON.stringify(inventory,null,2));assert.deepEqual(errors,[]);console.log('PASS desktop 1280/1440/1920, four unit palettes, light/dark, dialogs, export guard and '+inventory.length+' button inventories.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
