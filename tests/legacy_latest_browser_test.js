const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const file=process.argv[2];assert(file&&file.includes('legacy_latest_qa_'));const c=JSON.parse(fs.readFileSync(file,'utf8')),root=path.dirname(file);
 assert((await fetch(c.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test===true);
 const results=[],errors=[],ledgerOnly=process.argv.includes('--ledger-only');
 for(const [name,executablePath] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']]){
  const browser=await chromium.launch({headless:true,executablePath});
  try{if(name==='chromium109')assert(browser.version().startsWith('109.'));
   for(const javascript of [true,false]){const ctx=await browser.newContext({javaScriptEnabled:javascript});const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));
    for(const unit of (ledgerOnly?[1]:[1,2,3])){await ctx.clearCookies();await ctx.addCookies([{name:'PHPSESSID',value:c.sessions[unit],url:c.base}]);
     for(const theme of ['light','dark'])for(const width of [390,1366]){await p.setViewportSize({width,height:850});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
      for(const url of (ledgerOnly?[`legacy_full.php?id=${c.jobs[unit]}&step=exceptions&section=savings`]:['legacy_import.php?mode=students','legacy_import.php?mode=finance','legacy_full.php',`legacy_full.php?id=${c.jobs[unit]}&step=exceptions&section=savings`])){
       const r=await p.goto(c.base+'/'+url);assert(r.status()===200);if(!javascript)await p.evaluate(t=>document.documentElement.setAttribute('data-theme',t),theme);assert(await p.locator('html').getAttribute('data-theme')===theme);assert(!await p.locator('input[type=file][accept=".dat"]').count());assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));assert(await p.locator('html').getAttribute('data-palette')===({1:'sd',2:'smp',3:'sma'}[unit]));
       if(unit===1&&url.includes('section=savings')){assert(await p.locator('#additional-savings').isVisible());assert(await p.locator('.legacy-ledger-row').count()<=6);assert(await p.locator('#ledger-detail').isVisible());assert((await p.locator('#ledger-detail').innerText()).includes('migrasi belum dapat dilanjutkan'));if(javascript&&name==='chromium109'){await p.locator('#additional-savings').scrollIntoViewIfNeeded();await p.screenshot({path:path.join(root,`latest-ledger-${theme}-${width}.png`)});}}
       results.push({browser:name,version:browser.version(),javascript,unit,theme,width,url,status:'passed'});
      }
     }
    }await ctx.close();
   }
  }finally{await browser.close();}
 }
 assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(root,ledgerOnly?'latest-ledger-browser-results.json':'latest-browser-results.json'),JSON.stringify(results,null,2));console.log(`PASS: ${results.length} browser/page/unit/theme/viewport/no-JS cases; existing runtimes reused.`);
})().catch(e=>{console.error(e);process.exit(1);});
