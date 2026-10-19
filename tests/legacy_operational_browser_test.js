const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const file=process.argv[2];assert(file&&file.includes('legacy_operational_qa_'));const c=JSON.parse(fs.readFileSync(file,'utf8')),root=path.dirname(file);assert((await fetch(c.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test===true);const results=[],errors=[];
 for(const [name,executablePath] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']]){
  const browser=await chromium.launch({headless:true,executablePath});try{if(name==='chromium109')assert(browser.version().startsWith('109.'));
   for(const javascript of [true,false]){const ctx=await browser.newContext({javaScriptEnabled:javascript});const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));await ctx.addCookies([{name:'PHPSESSID',value:c.sessions[1],url:c.base}]);
    for(const theme of ['light','dark'])for(const width of [390,768,1366]){await p.setViewportSize({width,height:850});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
     for(const url of [`legacy_operational_review.php?id=${c.job}&section=groups`,`legacy_operational_review.php?id=${c.job}&section=people&person=212200001`,`legacy_operational_review.php?id=${c.job}&section=components`,`legacy_operational_review.php?id=${c.job}&section=savings&person=212200001`,`legacy_operational_review.php?id=${c.job}&section=confirm`,`legacy_correction.php?kind=payment&id=${c.payment}`,`legacy_accounts.php?batch=${c.job}&owner=${c.owner}`,`legacy_tariff.php?student=${c.student}`]){
      const r=await p.goto(c.base+'/'+url);assert.strictEqual(r.status(),200,url);if(!javascript)await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);assert.strictEqual(await p.locator('html').getAttribute('data-theme'),theme);assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Overflow '+url+' '+width);assert.strictEqual(await p.locator('html').getAttribute('data-palette'),'sd');
      if(url.includes('legacy_correction')){const b=p.locator('button.dbt-button-primary').last();await b.focus();assert(await b.evaluate(el=>el===document.activeElement));}
      if(name==='chromium109'&&javascript&&width===390&&url.includes('section=people'))await p.screenshot({path:path.join(root,`operational-${theme}-390.png`)});results.push({browser:name,version:browser.version(),javascript,theme,width,url});
     }
    }
    await p.goto(c.base+`/legacy_correction.php?kind=payment&id=${c.payment}`);
    await p.locator('textarea[name=reason]').fill('Owned modal check preserves review input.');
    await p.locator('textarea[name=note]').fill('Uncommitted modal test');
    await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Pratinjau Dampak Koreksi'}).click()]);
    assert(await p.locator('#full-confirm').isVisible());
    if(javascript){assert(await p.locator('dialog').isVisible());await Promise.all([p.waitForNavigation(),p.keyboard.press('Escape')]);}
    else await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Kembali Mengubah'}).click()]);
    assert.strictEqual(await p.locator('textarea[name=note]').inputValue(),'Uncommitted modal test');
    await ctx.close();
   }
   for(const unit of [1,2,3]){
    const ctx=await browser.newContext();try{
     await ctx.addCookies([{name:'PHPSESSID',value:c.sessions[unit],url:c.base}]);const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));
     for(const theme of ['light','dark'])for(const width of [390,1366]){
      await p.setViewportSize({width,height:850});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
      const url=unit===2?`legacy_operational_review.php?id=${JSON.parse(fs.readFileSync(path.join(root,'operational-large-preview.json'),'utf8')).job}&section=groups`:`legacy_accounts.php?batch=${unit===3?JSON.parse(fs.readFileSync(path.join(root,'operational-proposal-fixture.json'),'utf8')).job:c.job}`;
      const r=await p.goto(c.base+'/'+url);assert.strictEqual(r.status(),200);
      assert.strictEqual(await p.locator('html').getAttribute('data-palette'),{1:'sd',2:'smp',3:'sma'}[unit]);
      assert.strictEqual(await p.locator('html').getAttribute('data-theme'),theme);
      assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
      assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));
      results.push({browser:name,version:browser.version(),javascript:true,unit,theme,width,url});
     }
    }finally{await ctx.close();}
   }
  }finally{await browser.close();}
 }
 assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(root,'operational-browser-results.json'),JSON.stringify(results,null,2));console.log(`PASS: ${results.length} browser/viewport/theme/JavaScript cases; actual Chromium 109 and installed Chrome.`);
})().catch(e=>{console.error(e);process.exit(1);});
