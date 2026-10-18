/* Private sessions against the verified disposable report fixture. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_REPORT_QA,base=process.env.SPP_TEST_BASE_URL;
assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));
assert.equal(new URL(base).hostname,'127.0.0.1');
const fixture=JSON.parse(fs.readFileSync(root+'/report-fixture.json','utf8'));let checks=0;
function check(ok,message){assert.ok(ok,message);checks++;}
(async()=>{
 for(const engine of ['chrome','msedge']){
  const browser=await chromium.launch({channel:engine,headless:true});
  try{
   for(const unit of [1,2,3,0]){
    const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.sessions[unit],url:base}]);
    await context.route('https://fonts.googleapis.com/**',r=>r.abort());
    const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
    const item=fixture.units[unit||1];
    for(const theme of ['light','dark'])for(const width of [1440,768,390]){
     await page.setViewportSize({width,height:1100});
     await page.goto(base+'/laporan/template.php?'+new URLSearchParams({template:'per-item',unit:unit||'all',q:fixture.prefix,kategori:'spp'}));
     await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t},theme);
     for(const category of ['spp','biaya_lain:'+item.master]){
      const form=page.locator('.report-filter-per-item');
      await form.locator('[data-report-item-category]').selectOption([category]);
      const shape=await form.evaluate(f=>{
       const rect=s=>f.querySelector(s).getBoundingClientRect(),status=rect('.report-field-siswa-status'),size=rect('.report-per-page-field'),search=rect('.report-student-field');
       return {status,size,search,width:f.getBoundingClientRect().width,overflow:document.documentElement.scrollWidth-innerWidth};
      });
      check(Math.abs(shape.search.width-shape.width)<2,'Full width student search '+engine+'/'+unit+'/'+width);
      if(width>640)check(Math.abs(shape.status.y-shape.size.y)<2&&shape.size.x>shape.status.x,'Page size beside student status');
      else check(shape.size.y>shape.status.y,'Mobile stacks page size');
      check(shape.search.y>shape.size.y,'Search below page size');check(shape.overflow<=1,'No viewport overflow');
     }
     check(await page.locator('[data-report-filter-pending]').isVisible(),'Unapplied filters explained');
     check((await page.locator('h1').textContent()).includes('SPP'),'Applied report title stays accurate before submit');
     check(await page.locator('.report-export-btn').first().getAttribute('aria-disabled')==='true','Stale exports disabled');
     await page.evaluate(prefix=>{document.querySelector('[name="tanggal_awal"]').value='2026-10-16';document.querySelector('[name="tanggal_akhir"]').value='2026-10-17';document.querySelector('#global-student-query').value=prefix},fixture.prefix);
     await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Tampilkan Rekap',exact:true}).click()]);
     check((await page.locator('h1').textContent()).includes('Item Laporan'),'Selected optional report applied');
     check(await page.locator('[data-report-filter-pending]').isHidden(),'Applied notice clears');
     const row=page.locator('.report-horizontal-matrix tbody tr').filter({hasText:fixture.prefix+'A'}).filter({hasText:'220.000'});
     check(await row.count()===1&&(await row.innerText()).includes('220.000')&&(await row.innerText()).includes('Ada Pembayaran'),'Optional purchase visible with accurate status');
     check(await page.locator('.report-export-btn').first().getAttribute('aria-disabled')===null,'Applied exports enabled');
     if(engine==='chrome'&&unit===1&&theme==='light')await page.locator('.recap-report-header').screenshot({path:root+'/outputs/report-filters-'+width+'.png'});
     await page.locator('[name="status[]"]').selectOption(['ada_pembayaran']);
     await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Tampilkan Rekap',exact:true}).click()]);
     check(await page.locator('.report-horizontal-matrix tbody tr').filter({hasText:fixture.prefix+'A'}).count()===1,'Optional included in multiple Ada Pembayaran filter');
    }
    check(errors.length===0,'No page errors: '+errors.join('; '));
    if(engine==='chrome'&&unit>0){
     for(const format of ['pdf','excel']){
      const response=await context.request.get(base+'/laporan/export_global.php?'+new URLSearchParams({...item.params,format,download:1}));
      const body=await response.body();check(response.status()===200&&(format==='pdf'?body.subarray(0,5).toString()==='%PDF-':body.subarray(0,2).toString()==='PK'),'Report '+format+' export');
      fs.writeFileSync(root+'/outputs/fee-report-'+unit+'.'+(format==='pdf'?'pdf':'xlsx'),body);
     }
    }
    await context.close();
   }
   console.log('PASS '+engine+' report filters, optional receipts and exports');
  }finally{await browser.close()}
 }
 console.log('PASS '+checks+' browser report checks.');
})().catch(e=>{console.error(e);process.exitCode=1});
