/* Read-only acceptance on the verified, independently restored QA server. */
const fs=require('fs'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
const out=process.env.SPP_BROWSER_ARTIFACT_ROOT,root=process.env.SPP_DESKTOP_QA,base='http://127.0.0.1:58662';
assert(out&&root&&root.includes('pagination_desktop_'));
const sessions=JSON.parse(fs.readFileSync(root+'/sessions.json','utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_QA_BROWSER}),results=[],errors=[];
 try{
  for(const width of [1280,1366,1920]){
   const context=await browser.newContext({viewport:{width,height:1000}});await context.addCookies([{name:'PHPSESSID',value:sessions[1],url:base}]);
   const page=await context.newPage();page.setDefaultNavigationTimeout(180000);page.on('pageerror',e=>errors.push(e.message));
   const goto=async path=>{const r=await page.goto(base+'/'+path,{waitUntil:'networkidle',timeout:180000});assert.equal(r.status(),200,path);assert(!/Fatal error|Warning:|Uncaught/.test(await page.locator('body').innerText()),path);};
   let styleReference;
   for(const view of ['active','deleted','legacy']){
    await goto('pembayaran/riwayat_daftar_ulang.php?view='+view);
    const nav=page.locator('.du-history-tabs');assert.deepEqual(await nav.locator('a').allTextContents(),['Riwayat Aktif','Riwayat Dihapus','Histori Legacy']);
    assert.equal(await nav.locator('[aria-current="page"]').innerText(),{active:'Riwayat Aktif',deleted:'Riwayat Dihapus',legacy:'Histori Legacy'}[view]);
    const style=await nav.locator('a').first().evaluate(e=>{const s=getComputedStyle(e);return [s.fontSize,s.borderRadius,s.padding,s.minHeight]});
    if(styleReference)assert.deepEqual(style,styleReference);styleReference=style;assert.equal(style[3],'44px');
    assert.equal(await nav.locator('[aria-current]').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(22, 139, 87)');
    assert(!(await page.locator('body').innerText()).includes('Arsip pembayaran Daftar Ulang berdasarkan bukti penghapusan.'));
    const next=nav.locator('a').filter({hasText:'Histori Legacy'});assert.equal(new URL(await next.getAttribute('href'),base).searchParams.get('source'),'legacy');
    await page.screenshot({path:out+'/du-'+view+'-'+width+'.png'});
   }
   await goto('master_kelas.php');assert.equal(await page.locator('[data-class-view="cards"]').count(),0);
   const groups=page.locator('[data-level-group]');assert(await groups.count());assert.equal(await page.locator('[data-level-group][open]').count(),0);
   await groups.first().locator('summary').click();assert.equal(await page.locator('[data-level-group][open]').count(),1);
   await page.locator('[data-class-view="table"]').click();assert.equal(await page.locator('[data-level-group][open]').count(),await groups.count());
   await page.reload({waitUntil:'networkidle'});assert.equal(await page.locator('.class-list-panel').getAttribute('data-rombel-view'),'table');
   await page.locator('[data-class-view="groups"]').click();assert.equal(await page.locator('[data-level-group][open]').count(),0);
   await page.locator('.class-view-switch').scrollIntoViewIfNeeded();await page.screenshot({path:out+'/rombel-'+width+'.png'});
   await page.evaluate(()=>localStorage.setItem('spp_master_kelas_view','cards'));await page.reload({waitUntil:'networkidle'});assert.equal(await page.locator('.class-list-panel').getAttribute('data-rombel-view'),'groups');assert.equal(await page.locator('[data-level-group][open]').count(),0);
   await goto('laporan/template.php?template=penerimaan&kelas=tingkat:1');await page.locator('.report-date-range-button').click();
   const panel=page.locator('.report-date-range-popover:visible');assert.equal(await panel.count(),1);const rect=await panel.boundingBox();assert(rect.width>=459&&rect.x>=0&&rect.x+rect.width<=width+1);
   assert(await panel.evaluate(e=>e.scrollWidth<=e.clientWidth+1),'Date panel horizontal overflow');
   for(const input of await panel.locator('.id-date-control>input:not(.id-date-native)').all()){assert.match(await input.inputValue(),/^\d{2}\/\d{2}\/\d{4}$/);assert((await input.boundingBox()).width>=128);assert(await input.evaluate(e=>e.scrollWidth<=e.clientWidth+1),'Date text clipped');}
   await page.screenshot({path:out+'/receipt-dates-'+width+'.png'});await panel.locator('[data-range-apply]').click();assert.equal(await page.locator('.report-date-range-popover:visible').count(),0);
   await goto('laporan/template.php?template=riwayat-tagihan&kelas=tingkat:1');
   const table=page.locator('.billing-rombel table').first();assert((await table.locator('thead th').allTextContents()).includes('D.ULANG'));
   assert.equal(await table.locator('thead .billing-total-column').evaluate(e=>getComputedStyle(e).paddingRight),'12px');assert.equal(await table.locator('thead .billing-description-column').evaluate(e=>getComputedStyle(e).paddingLeft),'12px');
   await table.scrollIntoViewIfNeeded();await page.locator('.billing-rombel-scroll').first().evaluate(e=>e.scrollLeft=e.scrollWidth);await page.screenshot({path:out+'/billing-gap-'+width+'.png'});
   for(const name of ['billing','receipts'])for(const format of ['preview','excel']){
    const q=new URLSearchParams(name==='billing'?{template:'riwayat-tagihan',kelas:'tingkat:1',format}:{template:'penerimaan',kelas:'tingkat:1',tanggal_awal:'2026-08-01',tanggal_akhir:'2026-11-03',siswa_status:'active',format});
    await goto('laporan/export_global.php?'+q);if(name==='billing'&&format==='excel'){assert.equal(await page.locator('.preview-heading-copy p').count(),0);assert.equal(await page.locator('[data-preview-generated]').count(),0);}const frame=await page.locator('[data-preview-frame]').elementHandle().then(e=>e.contentFrame());assert(frame);await frame.evaluate(()=>document.fonts.ready);
    const text=await frame.locator('body').innerText();assert(!/Fatal error|Warning:/.test(text));
    if(name==='billing'){
     assert(text.includes('D.ULANG'));if(format==='preview'){
      assert.equal(await frame.locator('.title div').count(),0);assert(text.includes('Dibuat:')&&text.includes('Petugas:'));
      for(const cell of await frame.locator('.billing-print-total').all())assert(parseFloat(await cell.evaluate(e=>getComputedStyle(e).paddingRight))>=8);
      for(const cell of await frame.locator('.billing-print-description').all())assert(parseFloat(await cell.evaluate(e=>getComputedStyle(e).paddingLeft))>=8);
     }else{assert(!text.includes('Dibuat:')&&!text.includes('Petugas:'));assert(text.includes('Tahun Ajaran: 2026/2027'));assert.equal(await frame.locator('.excel-meta').count(),0);}
    }else{assert(!text.includes('Unit SD')&&!text.includes('Unit: SD')&&!text.includes('Status siswa:')&&!/\bRp\s/.test(text));assert.equal(await frame.locator('.kop p').evaluate(e=>getComputedStyle(e).fontSize),'14px');assert(!text.includes('D.ULANG'));}
    assert(await frame.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Preview page overflow');
    await page.screenshot({path:out+'/'+name+'-'+format+'-'+width+'.png'});
   }
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
   results.push({width,duTabs:true,rombelClosed:true,dateFits:true,billingGap:true,previewAndExcel:true});console.log('PASS four menus',width);await context.close();
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(out+'/browser-results.json',JSON.stringify(results,null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
