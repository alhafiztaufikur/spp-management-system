'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=process.env.SPP_PERF_AUDIT_ROOT,playwright=process.env.SPP_PLAYWRIGHT_PATH;
if(!root||!playwright)throw Error('Set the private audit directory and Playwright module path.');
const {chromium}=require(playwright),sessions=JSON.parse(fs.readFileSync(path.join(root,'sessions.json'),'utf8'));
const urls={before:'http://127.0.0.1:8131',after:'http://127.0.0.1:8132'};
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),checks=[],errors=[];
 fs.writeFileSync(path.join(root,'browser-result.json'),JSON.stringify({passed:false,checks},null,2));
 const pixelPage=await browser.newPage();
 try{
  for(const unit of [1,2,3])for(const page of ['master_spp','master_daftar_ulang'])for(const theme of ['light','dark'])for(const width of [1440,390]){
   const key=[unit,page,theme,width].join('-'),images={};
   if(process.env.SPP_PERF_BROWSER_CASE&&process.env.SPP_PERF_BROWSER_CASE!==key)continue;
   for(const variant of ['before','after']){
    const context=await browser.newContext({viewport:{width,height:1000}});
    await context.addCookies([{name:'PHPSESSID',value:sessions['super_admin-'+unit],url:urls[variant]}]);
    await context.addInitScript(({theme})=>{localStorage.setItem('spp_theme',theme);const NativeDate=Date;globalThis.Date=class extends NativeDate{constructor(...args){super(...(args.length?args:[1794358800000]));}static now(){return 1794358800000;}};},{theme});
    const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));
    const response=await p.goto(urls[variant]+'/'+page+'.php?tahun=2026%2F2027');assert.equal(response.status(),200);
    await p.evaluate(()=>document.fonts.ready);await p.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important;caret-color:transparent!important}'});
    await p.mouse.move(1,1);await p.evaluate(()=>scrollTo({top:0,left:0,behavior:'instant'}));await p.waitForTimeout(350);
    await p.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),key+' overflow');
    images[variant]=await p.screenshot({path:path.join(root,variant,'ui-'+key+'.png')});
    if(page==='master_spp'){
     if(theme==='light'&&width===1440)await p.goto(urls[variant]+'/'+page+'.php?tahun=2026%2F2027#spp-year-panel');
     const tabs=p.locator('.spp-workspace-tabs [role=tab]');assert.equal(await tabs.count(),2);assert(await p.locator('#spp-students-panel').isVisible());
     await tabs.nth(0).focus();await p.keyboard.press('ArrowRight');assert.equal(await tabs.nth(1).getAttribute('aria-selected'),'true');await p.keyboard.press('Home');assert(await p.locator('#spp-rate-panel').isVisible());await p.keyboard.press('End');
     await p.locator('#spp-student-search').fill('__no_match_masterperf__');assert(await p.locator('#spp-search-empty').isVisible());await p.locator('#spp-student-search').fill('');
     const edit=p.locator('[data-edit-student-tariff]').first();if(await edit.count()){await edit.click();assert(await p.locator('#student-tariff-dialog').isVisible());await p.keyboard.press('Escape');assert(!await p.locator('#student-tariff-dialog').isVisible());}
    }else{assert.equal(await p.locator('#du-year-select').inputValue(),'2026/2027');assert.equal(await p.locator('#du-rate-form').count(),1);}
    const timing=await p.evaluate(()=>{const n=performance.getEntriesByType('navigation')[0];return {ttfb:n.responseStart-n.startTime,dom:n.domContentLoadedEventEnd-n.startTime,load:n.loadEventEnd-n.startTime};});
    checks.push({key,variant,timing});await context.close();
   }
   let comparison={exact:true};
   if(!images.before.equals(images.after))comparison=await pixelPage.evaluate(async({a,b})=>{
    const load=data=>new Promise(resolve=>{const img=new Image();img.onload=()=>resolve(img);img.src='data:image/png;base64,'+data;});
    const [x,y]=await Promise.all([load(a),load(b)]);if(x.width!==y.width||x.height!==y.height)return {sizeChanged:true};
    const c=document.createElement('canvas');c.width=x.width;c.height=x.height;const ctx=c.getContext('2d');ctx.drawImage(x,0,0);const left=ctx.getImageData(0,0,c.width,c.height).data;ctx.clearRect(0,0,c.width,c.height);ctx.drawImage(y,0,0);const right=ctx.getImageData(0,0,c.width,c.height).data;
    let maxDelta=0,aboveTwo=0;for(let i=0;i<left.length;i+=4){const d=Math.max(...[0,1,2,3].map(k=>Math.abs(left[i+k]-right[i+k])));maxDelta=Math.max(maxDelta,d);if(d>2)aboveTwo++;}return {exact:false,maxDelta,aboveTwo,pixels:c.width*c.height};
   },{a:images.before.toString('base64'),b:images.after.toString('base64')});
   // Chrome can dither an unchanged gradient by one channel value between captures.
   assert(comparison.exact||(!comparison.sizeChanged&&comparison.maxDelta<=32&&comparison.aboveTwo<=Math.ceil(comparison.pixels*.00001)),key+' visual difference: '+JSON.stringify(comparison));
   checks.at(-1).comparison=comparison;console.log('PASS visual equivalence and controls',key);
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(root,'browser-result.json'),JSON.stringify({passed:true,pairs:checks.length/2,checks},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(String(e.message).slice(0,1000));process.exitCode=1;});
