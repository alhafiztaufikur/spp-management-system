const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const file=process.argv[2];assert(file&&file.includes('legacy_bulk_qa_'));const fixture=JSON.parse(fs.readFileSync(file,'utf8')),root=path.dirname(file),results=[],errors=[];
 assert((await fetch(fixture.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test);
 for(const executablePath of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
  const browser=await chromium.launch({headless:true,executablePath,env:{...process.env,TEMP:root+'/browser-temp',TMP:root+'/browser-temp'}});
  try{for(const javaScriptEnabled of [true,false])for(const theme of ['light','dark']){
   const context=await browser.newContext({javaScriptEnabled});try{
    await context.addCookies([{name:'PHPSESSID',value:fixture.session,url:fixture.base}]);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
    for(const width of [360,768,1366])for(const section of ['people','components','savings']){
     await page.setViewportSize({width,height:900});const response=await page.goto(fixture.base+'/legacy_operational_review.php?'+new URLSearchParams({id:fixture.job,section,ledger:fixture.ledger}));assert.strictEqual(response.status(),200);
     if(!javaScriptEnabled)await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
     assert(!/Fatal error|Warning:|Notice:/.test(await page.locator('body').innerText()));assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Overflow '+width+' '+section);
     assert(await page.getByRole('button',{name:'Setujui Semua Data',exact:true}).isVisible());const box=await page.getByRole('button',{name:'Setujui Semua Data',exact:true}).boundingBox();assert(box.height>=44);
     if(section==='savings'){assert(await page.locator('[name=main_identity]').count()===1);assert(await page.getByRole('button',{name:'Konfirmasi Saldo Sebenarnya'}).isVisible());}
     results.push({browser:browser.version(),width,section,theme,javaScriptEnabled});
     if(width===360&&section==='savings'&&javaScriptEnabled&&browser.version().startsWith('109.'))await page.screenshot({path:path.join(root,'bulk-savings-'+theme+'.png'),fullPage:true});
    }
    await page.getByRole('button',{name:'Setujui Semua Data',exact:true}).click();await page.getByRole('heading',{name:'Setujui seluruh data yang diusulkan?'}).waitFor({state:'visible'});if(javaScriptEnabled){assert(await page.locator('dialog[open]').isVisible());await page.keyboard.press('Escape');}else await page.getByRole('link',{name:'Kembali',exact:true}).last().click();
    await page.goto(fixture.base+'/legacy_operational_review.php?'+new URLSearchParams({id:fixture.job,section:'savings',ledger:fixture.ledger}));await page.locator('input[name=amount]').fill('925');await page.getByRole('button',{name:'Konfirmasi Saldo Sebenarnya'}).focus();await page.keyboard.press('Enter');await page.getByRole('heading',{name:'Ganti saldo akhir rekening utama?'}).waitFor({state:'visible'});assert((await page.locator('#full-confirm').innerText()).includes('925'));
    if(javaScriptEnabled)await page.keyboard.press('Escape');else await page.getByRole('link',{name:'Kembali Mengubah'}).click();assert.strictEqual(Number(await page.locator('input[name=amount]').inputValue()),925);
   }finally{await context.close();}
  }}finally{await browser.close();}
 }
 assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(root,'bulk-browser-results.json'),JSON.stringify(results,null,2));console.log('PASS: '+results.length+' browser/width/theme/JavaScript cases, mass modal, replacement modal, keyboard Escape and cancelled input retention.');
})().catch(e=>{console.error(e);process.exit(1)});
