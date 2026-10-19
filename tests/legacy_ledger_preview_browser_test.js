const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const file=process.argv[2];assert(file&&file.includes('legacy_latest_qa_'));const c=JSON.parse(fs.readFileSync(file,'utf8')),root=path.dirname(file);
 assert((await fetch(c.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test===true);
 const results=[],errors=[];
 for(const [name,executablePath] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']]){
  const browser=await chromium.launch({headless:true,executablePath});
  try{if(name==='chromium109')assert(browser.version().startsWith('109.'));
   for(const javascript of [true,false]){const ctx=await browser.newContext({javaScriptEnabled:javascript});await ctx.addCookies([{name:'PHPSESSID',value:c.sessions[1],url:c.base}]);const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));
    for(const theme of ['light','dark'])for(const width of [360,390,768,1366,1920]){
     await p.setViewportSize({width,height:900});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
     if(width===390){
     const url=c.base+`/legacy_full.php?id=${c.jobs[1]}&step=exceptions&section=savings`;
     let r=await p.goto(url);assert(r.status()===200);if(!javascript)await p.evaluate(t=>document.documentElement.setAttribute('data-theme',t),theme);
     assert(await p.locator('.legacy-ledger-row').count()===6);assert(await p.locator('.legacy-ledger-balances dd').nth(2).innerText()==='Rp 700.000');
     assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
     const row=p.locator('.legacy-ledger-row').nth(1),label=await row.locator('strong').innerText();await row.click();await p.locator('#ledger-person').waitFor();assert(await p.locator('#ledger-person').innerText()===label);
     const selected=p.url();await p.reload();assert(p.url()===selected&&await p.locator('#ledger-person').innerText()===label);
     await p.locator('.legacy-ledger-source summary').click();assert(await p.locator('.legacy-ledger-source[open] dl').isVisible());
     await p.locator('.legacy-ledger-pagination a').filter({hasText:'Berikutnya'}).click();await p.locator('#additional-savings').waitFor();assert(new URL(p.url()).searchParams.get('ledger_page')==='2');assert(await p.locator('.legacy-ledger-row').count()===6,p.url()+' rows='+await p.locator('.legacy-ledger-row').count());
     await p.locator('#ledger-search').fill('1617024');await p.locator('.legacy-ledger-search button').click();await p.locator('#additional-savings').waitFor();assert(await p.locator('.legacy-ledger-row').count()===1);assert((await p.locator('#ledger-detail').innerText()).includes('Rp 700.000'));
     await p.locator('#ledger-search').fill('tidak-ada-rekening');await p.locator('.legacy-ledger-search button').click();await p.locator('#additional-savings').waitFor();assert(await p.locator('.legacy-ledger-row').count()===0&&await p.locator('#ledger-detail').count()===0);
     await p.locator('.legacy-ledger-search a').click();await p.locator('#additional-savings').waitFor();await p.locator('.legacy-ledger-filters a').filter({hasText:'Tersimpan'}).click();await p.locator('#additional-savings').waitFor();assert(await p.locator('.legacy-ledger-row').count()===0);
     }
     const viewResponse=await p.goto(c.base+`/legacy_full.php?id=${c.jobs[1]}&step=exceptions&section=savings`);assert(viewResponse.status()===200);if(!javascript)await p.evaluate(t=>document.documentElement.setAttribute('data-theme',t),theme);
     assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));assert(await p.locator('.legacy-ledger-row').count()===6);
     const first=p.locator('.legacy-ledger-row').first();assert((await first.boundingBox()).height>=44);await first.focus();assert(await first.evaluate(el=>getComputedStyle(el).outlineStyle)!=='none');
     if(javascript&&name==='chromium109'&&[390,1920].includes(width))await p.screenshot({path:path.join(root,`ledger-preview-${theme}-${width}.png`),fullPage:false});
     results.push({browser:name,version:browser.version(),javascript,theme,width,status:'passed'});
    }
    const invalid=await p.goto(c.base+`/legacy_full.php?id=${c.jobs[1]}&ledger_q[]=bad`);assert(invalid.status()===400);
    await ctx.close();console.log(`Checked ${name}, JavaScript ${javascript?'on':'off'}.`);
   }
  }finally{await browser.close();}
 }
 assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(root,'ledger-preview-browser-results.json'),JSON.stringify(results,null,2));console.log(`PASS: ${results.length} browser/theme/viewport/no-JS cases, navigation, search, empty state, pagination and parameter validation.`);
})().catch(e=>{console.error(e);process.exit(1);});
