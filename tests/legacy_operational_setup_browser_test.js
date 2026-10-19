const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const file=process.argv[2];assert(file&&file.includes('legacy_operational_qa_'));
 const c=JSON.parse(fs.readFileSync(file,'utf8'));const results=[],errors=[];
 assert((await fetch(c.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test);
 for(const executablePath of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
  const browser=await chromium.launch({headless:true,executablePath});try{
   for(const javaScriptEnabled of [true,false])for(const theme of ['light','dark']){
    const ctx=await browser.newContext({javaScriptEnabled});try{
     await ctx.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));
     await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
     for(const width of [390,1366])for(const section of ['groups','confirm']){
      await p.setViewportSize({width,height:850});const r=await p.goto(c.base+`/legacy_operational_review.php?id=${c.job}&section=${section}`);assert.strictEqual(r.status(),200);
      if(!javaScriptEnabled)await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);
      assert.strictEqual(await p.locator('html').getAttribute('data-theme'),theme);assert.strictEqual(await p.locator('html').getAttribute('data-palette'),'smp');
      assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
      if(section==='groups'){
       const button=p.getByRole('button',{name:'Siapkan & Periksa Hasil'});assert(await button.isVisible());await button.focus();assert(await button.evaluate(el=>el===document.activeElement));
       const details=p.locator('details').filter({has:p.locator('summary',{hasText:'Sesuaikan aturan dan kelompok secara manual'})}).first();assert.strictEqual(await details.getAttribute('open'),null);
       await details.locator('summary').first().focus();await p.keyboard.press('Enter');assert(await details.locator('input[name=cutoff]').isVisible());
       if(browser.version().startsWith('109.')&&width===390)await p.screenshot({path:path.join(path.dirname(file),`source-setup-${theme}.png`)});
      }
      results.push({browser:browser.version(),javaScriptEnabled,theme,width,section});
     }
    }finally{await ctx.close();}
   }
  }finally{await browser.close();}
 }
 assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(path.dirname(file),'source-setup-browser-results.json'),JSON.stringify(results,null,2));console.log(`PASS: ${results.length} source-setup UI cases, native details, keyboard, themes, no JavaScript and actual Chromium 109.`);
})().catch(e=>{console.error(e);process.exit(1);});
