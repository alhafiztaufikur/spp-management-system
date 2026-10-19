const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const root=process.argv[2];assert(root&&root.includes('legacy_review_qa_'));const fixtures=[['sd','review-ui-fixture.json'],['smp','review-fixture.json'],['sma','review-ui-fixture-3.json']],results=[];
 for(const executablePath of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
  const browser=await chromium.launch({headless:true,executablePath,env:{...process.env,TEMP:root+'/browser-temp',TMP:root+'/browser-temp'}});
  try{for(const [palette,file] of fixtures)for(const javaScriptEnabled of [true,false])for(const theme of ['light','dark']){
   const c=JSON.parse(fs.readFileSync(path.join(root,file),'utf8'));const ctx=await browser.newContext({javaScriptEnabled});try{
    await ctx.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);const p=await ctx.newPage();await p.setViewportSize({width:390,height:850});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
    const r=await p.goto(c.base+'/legacy_operational_review.php?'+new URLSearchParams({id:c.job,section:'groups',targets:'A2025'}));assert.strictEqual(r.status(),200);if(!javaScriptEnabled)await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);
    assert.strictEqual(await p.locator('html').getAttribute('data-palette'),palette);assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));
    results.push({browser:browser.version(),palette,theme,javaScriptEnabled,accent:await p.locator('.lop-review').evaluate(el=>getComputedStyle(el).getPropertyValue('--accent').trim())});
    if(palette==='smp'&&javaScriptEnabled)await p.screenshot({path:path.join(root,'review-smp-'+theme+'.png')});
   }finally{await ctx.close();}
  }}finally{await browser.close();}
 }
 fs.writeFileSync(path.join(root,'review-palettes-results.json'),JSON.stringify(results,null,2));console.log('PASS: '+results.length+' actual SD/SMP/SMA palette, theme, no-JavaScript and Chromium 109 cases.');
})().catch(e=>{console.error(e);process.exit(1);});
