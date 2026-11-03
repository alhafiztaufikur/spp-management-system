/* Read-only acceptance on the separately restored QA server. */
const fs=require('fs'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
const root=process.env.SPP_DESKTOP_QA,out=process.env.SPP_BROWSER_ARTIFACT_ROOT,base='http://127.0.0.1:58662';
assert(root&&root.includes('pagination_desktop_')&&out);
const sessions=JSON.parse(fs.readFileSync(root+'/sessions.json','utf8')),queries=JSON.parse(fs.readFileSync(out+'/queries.json','utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_QA_BROWSER}),results=[],errors=[];
 try{
  for(const width of [1280,1366,1920]){
   const context=await browser.newContext({viewport:{width,height:1000}});await context.addCookies([{name:'PHPSESSID',value:sessions[1],url:base}]);
   const page=await context.newPage();page.setDefaultNavigationTimeout(120000);page.on('pageerror',e=>errors.push(e.message));
   for(const [name,query] of Object.entries(queries)){
    const q=new URLSearchParams({...query,per_page:'25'});
    await page.goto(base+'/laporan/template.php?'+q,{waitUntil:'networkidle',timeout:120000});
    assert(!/Fatal error|Warning:|Uncaught/.test(await page.locator('body').innerText()),name);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),name+' page overflow');
    const pager=page.locator('.report-template-shell>.desktop-pager:visible'),hasPager=await pager.count();
    if(['setoran','kas-tabungan'].includes(name))assert.equal(hasPager,0,'Cash recaps keep no pager');
    if(hasPager){
     const styles=await pager.evaluate(e=>{const s=getComputedStyle(e);return [s.paddingTop,s.paddingRight,s.paddingBottom,s.paddingLeft,s.columnGap]});
     assert.deepEqual(styles,['16px','24px','20px','24px','8px'],name);
     const buttons=pager.locator('.desktop-page');assert.equal(await buttons.count(),2);assert.equal(await buttons.first().getAttribute('aria-disabled'),'true');
     for(const button of await buttons.all()){const r=await button.boundingBox();assert(Math.abs(r.width-120)<1&&Math.abs(r.height-38)<1,name+' button size');}
     await pager.scrollIntoViewIfNeeded();await page.screenshot({path:out+'/'+name+'-pagination-'+width+'.png'});
     if(width===1366){
      const link=pager.locator('a.is-next'),href=await link.getAttribute('href');assert(href.includes('per_page=25')&&href.includes('kelas='),name+' filters retained');
      await link.click();await page.waitForLoadState('networkidle');assert.equal(await pager.locator('.is-prev').getAttribute('aria-disabled'),null);
      q.set('page','9999');await page.goto(base+'/laporan/template.php?'+q,{waitUntil:'networkidle',timeout:120000});assert.equal(await pager.locator('.is-next').getAttribute('aria-disabled'),'true',name+' last page');
      q.set('page','1');q.set('q','__QA_NO_MATCH_891234__');await page.goto(base+'/laporan/template.php?'+q,{waitUntil:'networkidle',timeout:120000});assert.equal(await pager.count(),0,name+' empty pager');
      q.delete('q');q.set('per_page','100');await page.goto(base+'/laporan/template.php?'+q,{waitUntil:'networkidle',timeout:120000});
      assert.equal(await page.locator('select[name="per_page"]').inputValue(),'100',name+' page size');
     }
    }
    for(const format of ['preview','excel']){
     const exportQ=new URLSearchParams({...query,format});await page.goto(base+'/laporan/export_global.php?'+exportQ,{waitUntil:'networkidle',timeout:120000});
     const iframe=await page.locator('[data-preview-frame]').elementHandle();assert(iframe,name+' preview iframe');const frame=await iframe.contentFrame();await frame.evaluate(()=>document.fonts.ready);
     const text=await frame.locator('body').innerText();assert(!/Fatal error|Warning:|Uncaught/.test(text),name+' '+format);
     if(!['psb','riwayat-tagihan'].includes(name)){
      const table=frame.locator('.global-clean-table,table.data').first();assert(await table.count(),name+' table');
      assert.match(await table.evaluate(e=>getComputedStyle(e).fontFamily),/Times New Roman|DejaVu Serif/);
      const styles=await table.locator('th,td').evaluateAll(cells=>[...new Set(cells.map(e=>{const s=getComputedStyle(e);return [s.backgroundColor,s.color,s.borderLeftWidth,s.borderRightWidth].join('|')}))]);
      assert.deepEqual(styles,['rgb(255, 255, 255)|rgb(0, 0, 0)|0px|0px'],name+' clean cells');
      assert(!text.includes('Data laporan bersifat live'),name+' footer');
      if(['setoran','kas-tabungan','tabungan-siswa'].includes(name)){assert(!text.includes('Unit SD')&&!text.includes('Status siswa:')&&!text.includes('Saldo berjalan belum diketahui'),name+' header');}
      if(name==='kas-tabungan')assert(!text.includes('Arus Tabungan'));
      if(name==='tabungan-siswa')assert.equal(await frame.locator('.kop p').evaluate(e=>getComputedStyle(e).fontSize),'14px');
      assert(await frame.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),name+' preview overflow');
     }
     if(name==='riwayat-tagihan'){
      assert(!text.includes('PSB (sekali masuk)'));if(format==='preview'){assert(!text.includes('Rp ')&&!text.includes('Data laporan bersifat live'));assert.match(await frame.locator('.billing-print-columns').innerText(),/TOTAL/);}
     }
     const table=frame.locator('table.global-clean-table,table.billing-print-table,table.data,.billing-rombel table').first();if(await table.count())await table.scrollIntoViewIfNeeded();
     await page.screenshot({path:out+'/'+name+'-'+format+'-'+width+'.png'});
    }
    results.push({name,width,pagination:hasPager?'24/16/20':'not needed',printAndExcelPreview:true});console.log('PASS',name,width);
   }
   await context.close();
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(out+'/browser-results.json',JSON.stringify(results,null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
