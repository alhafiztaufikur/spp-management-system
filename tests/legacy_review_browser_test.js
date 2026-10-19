const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
 const file=process.argv[2];assert(file&&file.includes('legacy_review_qa_'));
 const c=JSON.parse(fs.readFileSync(file,'utf8')),results=[],errors=[];
 assert((await fetch(c.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test);
 for(const executablePath of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
  const browser=await chromium.launch({headless:true,executablePath,env:{...process.env,TEMP:path.dirname(file)+'/browser-temp',TMP:path.dirname(file)+'/browser-temp'}});
  try{for(const javaScriptEnabled of [true,false])for(const theme of ['light','dark']){
   const ctx=await browser.newContext({javaScriptEnabled});try{
    await ctx.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
    for(const width of [360,390,768,1366])for(const section of ['groups','components','savings']){
     await p.setViewportSize({width,height:900});const url=c.base+'/legacy_operational_review.php?'+new URLSearchParams({id:c.job,section,targets:'A2025',person:'212200001'});const r=await p.goto(url);assert.strictEqual(r.status(),200);
     if(!javaScriptEnabled)await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);
     assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Horizontal overflow '+section+' '+width);
     if(section==='groups'){
      assert.strictEqual(await p.locator('.lop-target-table tbody tr').count(),25);
      assert((await p.locator('.lop-targets').innerText()).includes('Saldo sumber'));
      await p.locator('.lop-manual-settings > summary').click();const group=p.locator('.lf-debt').filter({has:p.locator('summary span',{hasText:/^A2025$/})}).first();await group.locator('summary').first().click();
      assert(await group.locator('input[name*="graduation_year"]').isVisible());
      const metrics=await group.locator('textarea').evaluate(el=>({height:el.getBoundingClientRect().height,radius:parseFloat(getComputedStyle(el).borderRadius)}));assert(metrics.height>=88&&metrics.radius>=10,JSON.stringify(metrics));
      const checks=await group.locator('.lop-check').evaluateAll(els=>els.map(e=>e.getBoundingClientRect().top));assert(checks[1]>checks[0]&&checks[2]>checks[1]);
     }
     if(section==='components'){assert((await p.locator('body').innerText()).includes('Robotik Legacy QA'));assert((await p.locator('body').innerText()).includes('Belum Digunakan QA'));}
     if(section==='savings')assert(await p.getByRole('button',{name:'Periksa Saldo FIX'}).isVisible());
     if(width===390&&javaScriptEnabled&&browser.version().startsWith('109.')&&section==='groups')await p.screenshot({path:path.join(path.dirname(file),'review-'+theme+'-390.png')});
     results.push({browser:browser.version(),javaScriptEnabled,theme,width,section});
    }
    await p.goto(c.base+'/legacy_operational_review.php?'+new URLSearchParams({id:c.job,section:'groups',targets:'A2025',target_page:'2'}));assert.strictEqual(await p.locator('.lop-target-table tbody tr').count(),1);
    await p.goto(c.base+'/legacy_operational_review.php?'+new URLSearchParams({id:c.job,section:'savings',person:'212200001'}));await p.locator('input[name=amount]').fill('900');await p.locator('textarea[name=evidence]').first().fill('Bukti rekening yayasan untuk pengujian.');await p.locator('input[name=attest]').first().evaluate(el=>el.scrollIntoView({behavior:'instant',block:'center'}));await p.locator('input[name=attest]').first().focus();await p.keyboard.press('Space');assert(await p.locator('input[name=attest]').first().isChecked());await p.getByRole('button',{name:'Periksa Saldo FIX'}).focus();await p.keyboard.press('Enter');
    try{await p.getByRole('heading',{name:'Sahkan saldo peralihan?'}).waitFor({state:'visible'});}catch(e){fs.writeFileSync(path.join(path.dirname(file),'browser-failure.html'),await p.content());console.error({browser:browser.version(),javaScriptEnabled,theme});throw e;}
    if(javaScriptEnabled){assert(await p.locator('dialog[open]').isVisible());await p.keyboard.press('Escape');}else await p.getByRole('link',{name:'Kembali Mengubah'}).click();
    assert.strictEqual(Number(await p.locator('input[name=amount]').inputValue()),900);
   }finally{await ctx.close();}
  }}finally{await browser.close();}
 }
 assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(path.dirname(file),'review-browser-results.json'),JSON.stringify(results,null,2));console.log('PASS: '+results.length+' layout cases, pagination, keyboard modal/cancel, input retention, themes, no JavaScript and actual Chromium 109.');
})().catch(e=>{console.error(e);process.exit(1);});
