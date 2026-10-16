/* Real print layout in Chromium; produces files only, never physical print jobs. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const pw=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const dir=process.env.SPP_BOOK_PDF_ARTIFACTS;
assert.ok(dir&&!path.resolve(dir).toLowerCase().startsWith(process.cwd().toLowerCase()));
(async()=>{
 for(const engine of (process.env.SPP_UI_BROWSERS||'chrome,msedge').split(',')){
  const options={headless:true};if(engine==='chromium109')options.executablePath=process.env.SPP_CHROMIUM109_EXECUTABLE;else options.channel=engine;
  const browser=await pw.chromium.launch(options);
  try{const page=await browser.newPage();
   for(const name of ['normal','annual','long']){
    await page.setContent(fs.readFileSync(path.join(dir,`receipt-${name}.html`),'utf8'));await page.emulateMedia({media:'print'});
    await page.pdf({path:path.join(dir,`receipt-${engine}-${name}.pdf`),preferCSSPageSize:true,printBackground:true,displayHeaderFooter:false});
    assert.equal(await page.locator('.print-toolbar').isVisible(),false);
    assert.equal(await page.locator('.receipt-sheet').count(),name==='annual'?12:1);
   }
   console.log(`PASS ${engine}: single/annual/long Letter half print documents`);
  }finally{await browser.close();}
 }
})().catch(e=>{console.error(e);process.exitCode=1});
