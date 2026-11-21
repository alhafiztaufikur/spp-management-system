/* Run --baseline before a presentation change, then run without it on the same verified clone. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const base=process.env.SPP_TEST_BASE_URL,out=path.join(process.env.SPP_UI_OUTPUT,'desktop-totals');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
fs.mkdirSync(out,{recursive:true});
const baselineMode=process.argv.includes('--baseline'),edgesOnly=process.argv.includes('--edges-only'),file=path.join(out,'baseline.json');
const baseline=baselineMode?{}:JSON.parse(fs.readFileSync(file,'utf8'));
const reports=['psb','status','penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','riwayat-tagihan','setoran','kas-tabungan'];
const changed=new Set(['psb','status','riwayat-tagihan','setoran','kas-tabungan']);
const cash=new Set(['setoran','kas-tabungan']);
function params(template,unit){return new URLSearchParams({template,unit:unit===0?'all':String(unit),kelas:'tingkat:'+({1:1,2:7,3:10,0:1}[unit]),siswa_status:'active',tanggal_awal:'2026-09-01',tanggal_akhir:'2026-11-12',tahun_ajaran:'2026/2027',bulan_awal:'9',tahun_awal:'2026',bulan_akhir:'11',tahun_akhir:'2026',...(template==='penerimaan'?{kategori:'semua'}:{})});}
async function setView(page,width,theme,media='screen'){
 await page.setViewportSize({width,height:1000});await page.emulateMedia({media});
 await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t},theme);
 await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>{
  for(const animation of document.getAnimations())if(animation.effect?.getTiming().iterations!==Infinity)animation.finish();
  requestAnimationFrame(resolve);
 })));
}
async function snapshot(page){return page.evaluate(()=>{
 const visible=n=>!!n.getClientRects().length,rect=n=>{const r=n.getBoundingClientRect();return [r.width,r.height].map(v=>Math.round(v*100)/100)};
 const selector='.report-total-section,.report-settlement-total-card,.billing-grand-total,.global-total-summary';
 const summaries=[...document.querySelectorAll(selector)].filter(visible).map(n=>({text:n.innerText,rect:rect(n),style:['color','backgroundColor','fontSize','padding','marginTop','marginBottom'].map(k=>getComputedStyle(n)[k])}));
 return {summaries,shell:rect(document.querySelector('.report-template-shell'))};
});}
async function financialRows(page){return page.locator('.report-template-shell table:not(.global-total-summary table)').allTextContents();}
function screenSummary(page){return page.locator('.global-report-screen-totals:visible');}
async function summaryCells(summary,template){
 return summary.locator('.global-report-total-card').evaluateAll(ns=>ns.map(n=>[n.querySelector('span').textContent,n.querySelector('strong').textContent]));
}
async function oldTotals(page,template){
 if(cash.has(template))return [[await page.locator('.report-settlement-total-card span').textContent(),await page.locator('.report-settlement-total-card strong').textContent()]];
 if(template==='riwayat-tagihan'){
  if(await screenSummary(page,template).count())return summaryCells(screenSummary(page,template),template);
  return [['Total Tunggakan Berjalan',(await page.locator('.billing-grand-total').first().textContent()).split(': ').slice(1).join(': ')]];
 }
 const cards=page.locator('.report-total-section .report-summary-card');
 if(await cards.count())return cards.evaluateAll(ns=>ns.map(n=>[n.querySelector('span').textContent,n.querySelector('strong').textContent]));
 return page.locator('.global-total-summary tbody tr').evaluateAll(ns=>ns.map(n=>[n.querySelector('th').textContent,n.querySelector('td').textContent]));
}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 const ctx=await browser.newContext({viewport:{width:1440,height:1000}}),page=await ctx.newPage(),errors=[],results=[];let paginationChecks=0;
 page.setDefaultNavigationTimeout(300000);page.on('pageerror',e=>errors.push(e.message));
 try{
  const identity=await(await ctx.request.get(base+'/tests/browser_clone_identity.php')).json();assert.equal(identity.isolated_test,true);assert.notEqual(identity.port,3306);
  await page.goto(base+'/login.php');await page.locator('#username').fill('qa_principal');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());
  await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);assert(!page.url().includes('login.php'));
  for(const unit of [1,2,3,0])for(const template of reports){
   if(edgesOnly)continue;
   const key=unit+'/'+template,url=base+'/laporan/template.php?'+params(template,unit);
   const response=await page.goto(url);assert.equal(response.status(),200,key);assert.equal(await page.locator('.report-template-alert').count(),0,key+' report error');
   await page.evaluate(()=>document.fonts.ready);
   if(baselineMode){
    baseline[key]={rows:await financialRows(page),totals:await oldTotals(page,template),surfaces:{}};
   }else{
    const rows=await financialRows(page);assert.deepEqual(rows,baseline[key].rows,key+' detail/subtotal values');
    for(const width of [390,800,1023,1280,1440,1920])for(const theme of ['light','dark']){
     await setView(page,width,theme);
     const summary=screenSummary(page,template);
     assert.equal(await summary.count(),baseline[key].totals.length?1:0,key+' one summary');
     if(await summary.count()){
      const box=await summary.boundingBox(),shell=await page.locator('.report-template-shell').boundingBox();
      assert(box.width>0,key+' visible cards');assert(box.x+box.width<=shell.x+shell.width+1,key+' fits shell');
      const cells=await summaryCells(summary,template);
      if(cash.has(template)){
       assert.equal(cells.at(-1)[1],baseline[key].totals[0][1],key+' cash closing amount');
       assert.deepEqual(cells.map(r=>r[0]),template==='setoran'?['Total Setoran']:['Total Tabungan Masuk','Total Tabungan Keluar','Mutasi Bersih']);
      }else if(template==='riwayat-tagihan'&&baseline[key].totals.length===1)assert.deepEqual(cells.map(row=>row[0]),['Jumlah Tagihan','Sudah Dibayar','Sisa Tagihan'],key+' selected-period totals');
      else assert.deepEqual(cells,baseline[key].totals,key+' labels/order/amounts');
      assert.equal(await summary.locator('h3').evaluate(n=>getComputedStyle(n).textAlign),'left');
      assert(await summary.locator('.global-report-total-card').evaluateAll(ns=>ns.every(n=>getComputedStyle(n).backgroundColor==='rgba(0, 0, 0, 0)')),key+' transparent cards');
      assert(await summary.locator('strong').evaluateAll(ns=>ns.every(n=>+getComputedStyle(n).fontWeight>=700&&getComputedStyle(n).opacity==='1'&&n.scrollWidth<=n.clientWidth+1)),key+' readable amounts');
     }
     assert.equal(await page.locator('.report-total-section:visible,.report-settlement-total-card:visible,.billing-grand-total:visible,.global-total-summary:visible').count(),0,key+' no duplicate closing');
     results.push({key,width,theme});
    }
    if(unit===1&&changed.has(template))await page.screenshot({path:path.join(out,template+'.png'),fullPage:true});
   }
   await setView(page,1440,'light','print');const print=await snapshot(page);
   if(baselineMode)baseline[key].surfaces.print=print;else {
    if(template==='riwayat-tagihan'){
     assert.equal(await page.locator('.billing-print-only-summary:visible').count(),1,key+' one printed total');
     assert.equal(await page.locator('.desktop-pager:visible').count(),0,key+' print excludes navigation');
    }else assert.deepEqual(print,baseline[key].surfaces.print,key+' print unchanged');
    assert.equal(await page.locator('.global-report-screen-totals:visible').count(),0,key+' cards excluded from print');
   }
   await page.emulateMedia({media:'screen'});
   if(!baselineMode&&!cash.has(template)&&baseline[key].totals.length){
    await page.setViewportSize({width:1440,height:1000});const before=await summaryCells(screenSummary(page,template),template);
    const next=page.locator('a').filter({hasText:/^Selanjutnya/}).last();
    if(await next.count()){
     const href=await next.getAttribute('href');if(href&&href.includes('page=')){
      await page.goto(new URL(href,page.url()).href);assert.deepEqual(await summaryCells(screenSummary(page,template),template),before,key+' pagination total');paginationChecks++;
     }
    }
   }
   console.log('PASS '+(baselineMode?'baseline ':'desktop/mobile/print ')+key);
  }
  if(!baselineMode){
   if(!edgesOnly)assert(paginationChecks>0,'Exercise pagination on populated reports');
   for(const template of reports){
    const q=params(template,1);q.set('q','QA-NO-STUDENT-DESKTOP-TOTALS-9f85');
    await page.goto(base+'/laporan/template.php?'+q);await setView(page,1440,'light');
    assert.equal(await page.locator('.report-template-alert').count(),0,template+' empty report');
    assert(!/NaN|undefined/.test(await page.locator('.report-template-shell').innerText()),template+' empty amounts');
    if(template==='riwayat-tagihan')assert.deepEqual(await page.locator('.global-report-total-card strong').allTextContents(),['-','-','-']);
   }
   for(const template of changed){
    const q=params(template,0);q.delete('kelas');
    if(template==='status'){
     for(const category of ['spp','komite','daftar_ulang','psb'])q.append('kategori[]',category);
    }
    await page.goto(base+'/laporan/template.php?'+q);await setView(page,1440,'light');
    assert.equal(await page.locator('.report-template-alert').count(),0,template+' combined units');
    const actual=await summaryCells(screenSummary(page,template),template);
    const expected=await oldTotals(page,template);
    if(cash.has(template))assert.equal(actual.at(-1)[1],expected[0][1],template+' combined cash');
    else assert.deepEqual(actual,expected,template+' complete combined totals');
    if(edgesOnly)for(const [format,extension,magic] of [['pdf','pdf','%PDF-'],['excel','xlsx','PK']]){
     const exportQuery=new URLSearchParams(q);exportQuery.set('format',format);exportQuery.set('download','1');
     const response=await ctx.request.get(base+'/laporan/export_global.php?'+exportQuery,{timeout:300000});const bytes=await response.body();
     assert.equal(response.status(),200,template+' '+format);assert.equal(bytes.subarray(0,magic.length).toString(),magic,template+' valid '+format);
     fs.writeFileSync(path.join(out,template+'-combined.'+extension),bytes);
    }
   }
   await page.goto(base+'/laporan/template.php?'+params('status',1));
   const edge=fs.readFileSync(path.join(out,'edge-cards.html'),'utf8');
   await page.locator('.global-report-screen-totals').evaluate((n,html)=>{n.outerHTML=html},edge);
   for(const width of [390,800,1280,1440,1920])for(const theme of ['light','dark']){
    await setView(page,width,theme);const summary=page.locator('.global-report-screen-totals:visible');
    assert.equal(await summary.count(),1);assert.equal(await summary.locator('script').count(),0);
    assert.deepEqual(await summary.locator('strong').allTextContents(),['Rp 1.234.567.890.123,25','Rp -125,25','Rp 0','—']);
    assert(await summary.locator('span,strong').evaluateAll(ns=>ns.every(n=>n.scrollWidth<=n.clientWidth+1)),'Long label/large amount fits');
   }
   console.log('PASS '+paginationChecks+' pagination checks, 10 empty reports, 5 full combined-unit reports, long/escaped labels, large/negative/fractional/zero/unknown amounts.');
  }
  assert.deepEqual(errors,[]);
  fs.writeFileSync(baselineMode?file:path.join(out,edgesOnly?'edges-results.json':'results.json'),JSON.stringify(baselineMode?baseline:results,null,2));
  console.log('PASS '+(baselineMode?'40 baseline models and print surfaces.':edgesOnly?'card totals edge scenarios.':results.length+' desktop/mobile card checks, 40 source value and unchanged-print comparisons.'));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
