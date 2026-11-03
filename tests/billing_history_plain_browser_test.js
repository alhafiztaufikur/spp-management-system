/* Read-only acceptance on the verified, separately restored desktop QA server. */
const fs=require('fs'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
const root=process.env.SPP_DESKTOP_QA,artifacts=process.env.SPP_BROWSER_ARTIFACT_ROOT,base='http://127.0.0.1:58662';
assert(root&&root.includes('pagination_desktop_')&&artifacts);
const sessions=JSON.parse(fs.readFileSync(root+'/sessions.json','utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_QA_BROWSER});
 const results=[],errors=[];
 try{
  for(const width of [1280,1366,1920]){
   const context=await browser.newContext({viewport:{width,height:1000}});
   await context.addCookies([{name:'PHPSESSID',value:sessions[1],url:base}]);
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/laporan/template.php?template=riwayat-tagihan&kelas=tingkat:1',{waitUntil:'networkidle',timeout:120000});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
   const mainTable=page.locator('.billing-rombel table').first();
   assert.equal((await mainTable.locator('thead th').allTextContents())[7],'TOTAL');
   assert.equal(await mainTable.locator('tbody td').first().evaluate(el=>getComputedStyle(el).fontSize),'13px');
   for(const note of await page.locator('.billing-description').allTextContents())assert(!/\b(?:19|20)\d{2}\b/.test(note));
   const heading=page.locator('.billing-rombel>h3').first();assert.match(await heading.innerText(),/2026\/2027/);
   await heading.scrollIntoViewIfNeeded();await page.screenshot({path:artifacts+'/desktop-left-'+width+'.png'});
   await page.locator('.billing-rombel-scroll').first().evaluate(el=>el.scrollLeft=el.scrollWidth);
   await page.screenshot({path:artifacts+'/desktop-right-'+width+'.png'});
   for(const format of ['preview','excel']){
    await page.goto(base+'/laporan/export_global.php?template=riwayat-tagihan&kelas=tingkat:1&format='+format,{waitUntil:'networkidle',timeout:120000});
    const frame=await page.locator('[data-preview-frame]').elementHandle().then(el=>el.contentFrame());assert(frame);
    const printing=format==='preview';
    const title=frame.locator(printing?'.billing-print-year th':'.billing-print-context').first();await title.waitFor();
    await frame.evaluate(()=>document.fonts.ready);
    assert.match(await title.innerText(),/Tahun Ajaran: 2026\/2027/);
    assert.equal(await title.evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
    assert.equal(await title.evaluate(el=>getComputedStyle(el).color),printing?'rgb(0, 0, 0)':'rgb(23, 35, 29)');
    const table=frame.locator(printing?'.billing-print-table':'.billing-rombel table').first();
    assert((await table.locator('thead th').allTextContents()).includes('TOTAL'));
    for(const note of await frame.locator(printing?'.billing-print-description':'.billing-description').allTextContents())assert(!/\b(?:19|20)\d{2}\b/.test(note));
    if(printing){
     const numbers=await frame.locator('.billing-print-student>.billing-print-no').allTextContents();assert.deepEqual(numbers,numbers.map((_,i)=>String(i+1)));
     for(const cell of await table.locator('td').all()){
      assert.equal(await cell.evaluate(el=>getComputedStyle(el).borderLeftWidth),'0px');
      assert.equal(await cell.evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
     }
     assert.match(await table.evaluate(el=>getComputedStyle(el).fontFamily),/Times New Roman|DejaVu Serif/);
     assert.equal(await frame.locator('.billing-print-subtotal').count(),3);
    }
    await title.scrollIntoViewIfNeeded();await page.screenshot({path:artifacts+'/'+format+'-'+width+'.png'});
    await page.emulateMedia({media:'print'});
    const cell=frame.locator(printing?'.billing-print-student>.billing-print-numeric':'.billing-component').first();
    assert.equal(await cell.evaluate(el=>getComputedStyle(el).fontSize),'12px');
    assert.equal(await cell.evaluate(el=>getComputedStyle(el).verticalAlign),'middle');
    assert.equal(await cell.evaluate(el=>getComputedStyle(el).paddingTop),printing?'2.66667px':'5.33333px');
    assert.equal(await cell.evaluate(el=>getComputedStyle(el).paddingLeft),'4px');
    assert(Math.abs(parseFloat(await title.evaluate(el=>getComputedStyle(el).fontSize))-13.3333)<.01);
    await page.emulateMedia({media:'screen'});
   }
   await page.goto(base+'/laporan/export_global.php?template=psb&kelas=tingkat:1&format=preview',{waitUntil:'networkidle',timeout:120000});
   const psbFrame=await page.locator('[data-preview-frame]').elementHandle().then(el=>el.contentFrame());
   assert(!/JUMLAH merupakan kewajiban/.test(await psbFrame.locator('body').innerText()));
   results.push({width,plainContext:true,totalHeader:true,notesWithoutYears:true,printBodyPt:9,printTitlePt:10});
   await context.close();
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(artifacts+'/browser-results.json',JSON.stringify(results,null,2));
  console.log('PASS plain report headings, year-free notes, TOTAL, print typography and desktop screenshots at three widths');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
