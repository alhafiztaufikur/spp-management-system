const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const file=process.argv[2];assert(file&&file.includes('legacy_latest_qa_'));const c=JSON.parse(fs.readFileSync(file,'utf8')),root=path.dirname(file);
 assert((await fetch(c.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test===true);const results=[],errors=[];
 for(const [browserName,executablePath] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']]){
  const browser=await chromium.launch({headless:true,executablePath});try{
   if(browserName==='chromium109')assert(browser.version().startsWith('109.'));
   for(const javascript of [true,false]){const ctx=await browser.newContext({javaScriptEnabled:javascript});await ctx.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));
    for(const theme of ['light','dark'])for(const width of [390,1366]){
     await p.setViewportSize({width,height:900});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
     const r=await p.goto(c.base+'/siswa/aktivasi_legacy.php?id='+c.student);assert(r.status()===200);
     if(!javascript)await p.evaluate(t=>document.documentElement.setAttribute('data-theme',t),theme);
     const form=p.locator('#legacy-activation');assert(await form.locator('[name=id]').inputValue()===String(c.student));assert(await form.locator('[name=komite]').inputValue()==='15000.00');assert((await p.locator('.dbt-card').first().innerText()).includes('Komite (POMG)'));
     await form.locator('[name=class_id]').selectOption(String(c.class),{force:true});await form.locator('[name=year_id]').selectOption(String(c.year),{force:true});await form.locator('[name=psb]').fill('12345');
     if(javascript)assert(Number(await form.locator('[name=spp]').inputValue())===Number(c.rate));
     await form.locator('button[name=action]').click();await p.locator('#legacy-activation').waitFor();if(!javascript)await p.evaluate(t=>document.documentElement.setAttribute('data-theme',t),theme);
     assert(await form.locator('[name=id]').inputValue()===String(c.student));assert(await form.locator('[name=class_id]').inputValue()===String(c.class));assert(await form.locator('[name=year_id]').inputValue()===String(c.year));assert(Number(await form.locator('[name=spp]').inputValue())===Number(c.rate));assert(await form.locator('[name=psb]').inputValue()==='12345');assert(!await form.locator('[name=confirmed]').isChecked());
     assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
     if(javascript&&browserName==='chromium109'&&width===390){await form.scrollIntoViewIfNeeded();await p.screenshot({path:path.join(root,`activation-${theme}-390.png`)});}
     results.push({browser:browserName,version:browser.version(),javascript,theme,width,status:'passed'});
    }await ctx.close();
   }
  }finally{await browser.close();}
 }
 assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(root,'activation-browser-results.json'),JSON.stringify(results,null,2));console.log(`PASS: ${results.length} activation browser/theme/mobile/desktop/no-JS cases; correct ID, source Komite, server preview and retained fields.`);
})().catch(e=>{console.error(e);process.exit(1);});
