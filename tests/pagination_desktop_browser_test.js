/* Read-only browser acceptance against the separately restored desktop QA server. */
const fs=require('fs'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
const root=process.env.SPP_DESKTOP_QA,base='http://127.0.0.1:58662';
assert(root&&root.includes('pagination_desktop_'));
const sessions=JSON.parse(fs.readFileSync(root+'/sessions.json','utf8'));
const artifactRoot=process.env.SPP_BROWSER_ARTIFACT_ROOT||(fs.existsSync(root+'/compact-revision-path.txt')?fs.readFileSync(root+'/compact-revision-path.txt','utf8').trim():root);
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_QA_BROWSER});
 const results=[],errors=[];
 try{
  for(const width of [1280,1366,1920]){
   const context=await browser.newContext({viewport:{width,height:1000}});
   await context.addCookies([{name:'PHPSESSID',value:sessions[1],url:base}]);
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   for(const [route,name] of [['pembayaran/lihat.php?all_periods=1','payments'],['pembayaran/riwayat_daftar_ulang.php','du'],['master_kelas.php','classes'],['laporan/template.php?template=riwayat-tagihan&kelas=tingkat:4','billing']]){
    const started=Date.now(),response=await page.goto(base+'/'+route,{waitUntil:'networkidle',timeout:120000});assert.equal(response.status(),200);
    const text=await page.locator('body').innerText();assert(!/Fatal error|Warning:|Uncaught/.test(text));
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),name+' page overflow');
    const pagers=page.locator('.desktop-pager:visible');
    for(const pager of await pagers.all()){
     const controls=pager.locator('.desktop-page:visible');assert.equal(await controls.count(),2);
     assert.match(await controls.nth(0).innerText(),/Sebelumnya/);assert.match(await controls.nth(1).innerText(),/Selanjutnya/);
     assert.equal(await pager.locator('svg:visible').count(),2);
     const size=await controls.nth(1).boundingBox();assert.equal(Math.round(size.height),38);assert.equal(Math.round(size.width),120);
     assert.equal(await controls.nth(1).evaluate(el=>getComputedStyle(el).fontSize),'13px');
     assert.equal(await controls.nth(1).evaluate(el=>getComputedStyle(el).borderRadius),'10px');
     assert.equal(await pager.evaluate(el=>getComputedStyle(el).columnGap),'8px');
     assert.equal(await pager.locator('svg').first().evaluate(el=>getComputedStyle(el).width),'14px');
    }
    if(name==='payments'){
     assert.equal(await page.locator('.ph-list-nav').count(),0);
     assert(!/Menampilkan \d+.*transaksi/.test(text));
     const input=page.locator('[data-history-search]');await input.fill('an');await page.waitForSelector('.history-search-panel button',{timeout:15000});
     assert(await page.locator('.history-search-panel button').count()<=20);await page.keyboard.press('Escape');
    }
    if(name==='classes')for(const removed of ['Atur rombel tujuan pada setiap baris siswa.','Rombel tujuan dapat diatur berbeda untuk setiap siswa.','Peserta tersedia pada tingkat dan tahun yang dipilih.','Pilih siswa untuk kenaikan kelas atau kelulusan sesuai tingkat asal.'])assert(!text.includes(removed));
    if(name==='billing'){
     const perPage=await page.locator('.report-per-page-field').boundingBox(),search=await page.locator('.report-student-field').boundingBox();
     assert(Math.abs(perPage.y-search.y)<2&&search.x>=perPage.x+perPage.width,'Search stays beside students/page');
     assert.equal(await page.locator('.billing-rombel>h3').first().evaluate(el=>getComputedStyle(el).paddingLeft),'24px');
     assert.equal(await page.locator('.billing-grand-total').evaluate(el=>getComputedStyle(el).paddingLeft),'24px');
     for(const pager of await pagers.all())assert.equal(await pager.evaluate(el=>getComputedStyle(el).paddingRight),'24px');
     assert(await page.locator('.billing-rombel').count()>0);assert(/keterangan tagihan/i.test(text));assert(/jumlah rombel/i.test(text));
     assert.deepEqual(await page.locator('.billing-rombel').first().locator('thead th').allTextContents(),['No','No Induk','Nama Siswa','PSB','D.ULANG','SPP','Komite','TOTAL','Keterangan Tagihan']);
     for(const note of await page.locator('.billing-description').allTextContents())assert(!/\b(?:19|20)\d{2}\b/.test(note),'Display note still contains a year');
     const table=page.locator('.billing-rombel table').first();
     async function checkRows(){
      const problems=await table.locator('tbody tr').evaluateAll(rows=>rows.flatMap(row=>{
       const cells=[...row.cells],top=cells[0].getBoundingClientRect().top,out=[];
       if(cells.length!==9)out.push('wrong cell count');
       cells.forEach((cell,i)=>{const rect=cell.getBoundingClientRect();if(getComputedStyle(cell).display!=='table-cell'||Math.abs(rect.top-top)>1)out.push('stacked cell '+i);if(getComputedStyle(cell).verticalAlign!=='middle')out.push('vertical alignment '+i);if(i&&rect.left<cells[i-1].getBoundingClientRect().right-1)out.push('overlapping cell '+i);});
       cells.slice(0,2).forEach(cell=>{const s=getComputedStyle(cell);if(s.textAlign!=='center'||s.verticalAlign!=='middle')out.push('identity alignment');});
       return out;
      }));assert.deepEqual(problems,[]);
     }
     await checkRows();
     await page.locator('.billing-rombel').first().evaluate(el=>window.scrollTo(0,el.getBoundingClientRect().top+scrollY-100));
     await page.screenshot({path:artifactRoot+'/billing-table-left-'+width+'.png',animations:'disabled'});
     const scroll=page.locator('.billing-rombel-scroll').first();await scroll.evaluate(el=>el.scrollLeft=el.scrollWidth);
     await page.screenshot({path:artifactRoot+'/billing-table-right-'+width+'.png',animations:'disabled'});
     await table.locator('tbody tr').first().evaluate(row=>{
      row.querySelector('.billing-row-name').textContent='Siswa Dengan Nama Sangat Panjang Untuk Memeriksa Pembungkusan Teks Pada Tabel';
      row.querySelector('.billing-component span').textContent='Rp 99.999.999.999.999';
      row.querySelector('.billing-description').textContent='SPP (Jul, Agu, Sep, Okt, Nov 2026) + Daftar Ulang (2026/2027) + Komite (Jul, Agu, Sep, Okt, Nov 2026)';
     });
     await checkRows();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
     assert(await table.locator('.billing-component span').first().evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Large amount is clipped');
     await scroll.evaluate(el=>el.scrollLeft=0);
     await page.screenshot({path:artifactRoot+'/billing-stress-left-'+width+'.png',animations:'disabled'});
     await scroll.evaluate(el=>el.scrollLeft=el.scrollWidth);
     await page.screenshot({path:artifactRoot+'/billing-stress-right-'+width+'.png',animations:'disabled'});
    }
    if(await pagers.count())await pagers.first().screenshot({path:artifactRoot+'/pager-'+name+'-'+width+'.png'});
    await page.screenshot({path:artifactRoot+'/'+name+'-'+width+'.png',fullPage:false,animations:'disabled'});
    results.push({width,name,ms:Date.now()-started,pagers:await pagers.count()});
   }
   await context.close();
  }
  const context=await browser.newContext({viewport:{width:1366,height:1000}});await context.addCookies([{name:'PHPSESSID',value:sessions[1],url:base}]);const page=await context.newPage();
  for(const route of ['siswa/daftar.php','tabungan/riwayat.php','otorisasi_transaksi.php','master_daftar_ulang.php','legacy_finance.php']){
   await page.goto(base+'/'+route,{waitUntil:'networkidle',timeout:120000});assert(!/Fatal error|Warning:/.test(await page.locator('body').innerText()));
   for(const pager of await page.locator('.desktop-pager:visible').all())assert.equal(await pager.locator('.desktop-page:visible').count(),2);
  }
  await page.goto(base+'/pembayaran/lihat.php?all_periods=1&page=2&per_page=25',{waitUntil:'networkidle'});
  assert.match(await page.locator('.desktop-pager .is-prev').getAttribute('href'),/page=1(?:&|$)/);
  await page.goto(base+'/pembayaran/lihat.php?all_periods=1&page=999999',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.desktop-pager .is-next').getAttribute('aria-disabled'),'true');
  await page.goto(base+'/pembayaran/lihat.php?all_periods=1&search=__missing_student_749892__',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.desktop-pager:visible').count(),0);
  await page.goto(base+'/laporan/template.php?template=riwayat-tagihan&q=__missing_student_749892__',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.billing-empty').evaluate(el=>getComputedStyle(el).paddingLeft),'24px');
  assert.match(await page.locator('.billing-empty').innerText(),/Tidak ada data sesuai filter/);
  assert.equal(await page.locator('.desktop-pager:visible').count(),0);
  await page.locator('.billing-empty').scrollIntoViewIfNeeded();await page.screenshot({path:artifactRoot+'/billing-empty.png'});
  await context.close();
  const fallback=await browser.newContext({javaScriptEnabled:false,viewport:{width:1366,height:1000}});await fallback.addCookies([{name:'PHPSESSID',value:sessions[1],url:base}]);const nojs=await fallback.newPage();
  await nojs.goto(base+'/pembayaran/lihat.php?all_periods=1',{waitUntil:'networkidle'});assert.equal(await nojs.locator('.desktop-pager:visible').count(),1);assert.equal(await nojs.locator('.desktop-pager svg:visible').count(),2);await fallback.close();
  assert.deepEqual(errors,[]);fs.writeFileSync(artifactRoot+'/browser-results.json',JSON.stringify(results,null,2));console.log('PASS desktop pagination and report checks at three widths');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
