// Matrix status labels share wrapping across annual/monthly report templates.
const fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(process.argv[2]);assert(root.endsWith('ui_time_audit_20261021'));
const {chromium}=require(root+'/../import_audit_20261020/browser-tools/node_modules/playwright-core');
const c=JSON.parse(fs.readFileSync(root+'/http.json','utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}),results=[];
 try{
  for(const unit of [1,2,3,0]){
   const context=await browser.newContext({viewport:{width:1440,height:1000}});await context.addCookies([{name:'PHPSESSID',value:c.sessions[unit],url:c.base}]);const page=await context.newPage();
   for(const template of ['spp-tahunan','per-item']){
    const response=await page.goto(c.base+'/laporan/template.php?template='+template+'&unit='+(unit||'all'),{waitUntil:'networkidle',timeout:120000});assert.equal(response.status(),200);
    assert(!/Fatal error:|Warning:|Gagal memuat laporan/.test(await page.locator('body').innerText()));
    const labels=await page.locator('.report-horizontal-matrix .matrix-main').evaluateAll(nodes=>nodes.map(n=>({text:n.textContent,width:n.getBoundingClientRect().width,parent:n.parentElement.getBoundingClientRect().width,whiteSpace:getComputedStyle(n).whiteSpace,scroll:n.scrollHeight,height:n.clientHeight})));
    for(const label of labels){assert.notEqual(label.whiteSpace,'nowrap');assert(label.width<=label.parent+1,label.text);assert(label.scroll<=label.height+1,label.text);}
    await page.locator('.report-matrix-scroll').first().evaluate(n=>n.scrollLeft=n.scrollWidth);
    await page.screenshot({path:root+'/artifacts/ui-'+unit+'-'+template+'-wrapped.png'});results.push({unit,template,labels:labels.length});console.log('PASS',unit,template,labels.length,'labels');
   }await context.close();
  }
  fs.writeFileSync(root+'/status-matrix-browser-results.json',JSON.stringify({checks:results.length,results},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
