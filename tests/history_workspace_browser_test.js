'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const audit=process.env.SPP_HISTORY_AUDIT_ROOT,base=process.env.SPP_HISTORY_AFTER_URL||'http://127.0.0.1:8132';
assert.equal(new URL(base).hostname,'127.0.0.1');
const sessions=JSON.parse(fs.readFileSync(audit+'/sessions.json','utf8'));
async function contrastFailures(page){return page.evaluate(()=>{
 const rgb=s=>{if(s.startsWith('color(srgb')){const a=s.match(/[.\d]+/g).map(Number);return[a[0]*255,a[1]*255,a[2]*255,a[3]??1];}const a=s.match(/[.\d]+/g)?.map(Number);return a?[a[0],a[1],a[2],a[3]??1]:[0,0,0,0];};
 const blend=(a,b)=>a.slice(0,3).map((v,i)=>v*a[3]+b[i]*(1-a[3]));
 const lum=c=>c.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
 const failures=[];for(const e of document.querySelectorAll('.du-combined-record *,.du-detail-identity *,.du-balance-card *,.du-receipt-card *,.du-receipt-heading')){
  if(![...e.childNodes].some(n=>n.nodeType===3&&n.textContent.trim())||!e.getBoundingClientRect().width||getComputedStyle(e).visibility==='hidden')continue;
  const ancestors=[];for(let a=e;a;a=a.parentElement)ancestors.push(a);let bg=[255,255,255];for(const a of ancestors.reverse())bg=blend(rgb(getComputedStyle(a).backgroundColor),bg);
  const s=getComputedStyle(e),fg=blend(rgb(s.color),bg),size=parseFloat(s.fontSize),large=size>=24||(size>=18.66&&parseInt(s.fontWeight)>=700),ratio=(Math.max(lum(fg),lum(bg))+.05)/(Math.min(lum(fg),lum(bg))+.05);
  if(ratio<(large?3:4.5))failures.push({class:e.className,parent:e.parentElement.className,ratio:Math.round(ratio*100)/100,color:s.color,bg,size});
 }return failures;
});}
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true}),checks=[],errors=[],contrast=[];try{
 for(const unit of [1,2,3,0]){
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:sessions['super_admin-'+unit],url:base}]);
  const page=await context.newPage();let injectedFailure=false;page.setDefaultTimeout(60000);page.on('pageerror',e=>errors.push(e.message));
  page.on('console',m=>{if(m.type()==='error'&&!/favicon.ico/.test(m.location().url)&&!(injectedFailure&&m.text().includes('503')))errors.push(m.text());});
  for(const theme of ['light','dark'])for(const width of [1600,900,390]){
   await page.setViewportSize({width,height:1000});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
   const response=await page.goto(base+'/pembayaran/riwayat_daftar_ulang.php');assert.equal(response.status(),200);await page.evaluate(()=>document.fonts.ready);
   assert.equal(await page.locator('.du-summary>div').count(),4);assert.equal(await page.locator('[name=q]').count(),1);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'Overflow '+unit+'/'+theme+'/'+width);
   assert.equal(await page.locator('html').getAttribute('data-theme'),theme);
   assert.equal(await page.locator('.du-record-money>small').first().textContent(),'Terbayar');
   assert.equal(await page.locator('[data-registration-record][aria-current=true]').count(),1);
   const failures=await contrastFailures(page);contrast.push({unit,theme,width,failures});fs.writeFileSync(audit+'/contrast-result.json',JSON.stringify(contrast,null,2));assert.deepEqual(failures,[],'Text contrast '+unit+'/'+theme+'/'+width);
   await page.screenshot({path:`${audit}/du-${unit}-${theme}-${width}.png`,fullPage:true});checks.push('Layout '+unit+'/'+theme+'/'+width);
  }
  await page.setViewportSize({width:1600,height:1000});
  const cards=page.locator('[data-registration-record]'),detail=page.locator('[data-registration-detail]');
  const waitDetail=()=>page.waitForFunction(()=>!document.querySelector('[data-registration-detail]').hasAttribute('aria-busy'));
  const name2=await cards.nth(1).locator('.du-record-identity>strong').textContent();await cards.nth(1).click();await waitDetail();
  assert.equal(await detail.locator('.du-detail-identity h3').textContent(),name2);
  await page.locator('[data-registration-next]').click();await waitDetail();await page.goBack();await waitDetail();assert.equal(await detail.locator('.du-detail-identity h3').textContent(),name2);
  await page.goForward();await waitDetail();assert.equal(await cards.nth(2).getAttribute('aria-current'),'true');
  await page.locator('[data-registration-close]').click();assert.equal(await page.locator('[data-registration-detail-panel]').isVisible(),false);
  assert.equal(await cards.nth(2).evaluate(e=>e===document.activeElement),true);
  await cards.first().focus();await page.keyboard.press('Enter');await waitDetail();assert.equal(await cards.first().getAttribute('aria-current'),'true');checks.push('Keyboard, navigation, history '+unit);
  assert.equal(await cards.first().evaluate(e=>getComputedStyle(e).outlineStyle),'solid','Visible keyboard focus');
  const list=page.locator('.ph-record-list');await list.evaluate(e=>e.scrollTop=100);const scroll=await list.evaluate(e=>e.scrollTop);await cards.nth(2).click();await waitDetail();assert.equal(await list.evaluate(e=>e.scrollTop),scroll,'Selection preserves list scroll');
  const native=await cards.evaluateAll(rows=>rows.map(e=>({id:Number(e.dataset.id),unit:e.dataset.unit})).find(r=>r.id>0));
  if(native){const response=await context.request.get(base+'/pembayaran/detail_daftar_ulang.php?'+new URLSearchParams({tagihan_id:native.id,unit_id:native.unit}));assert.equal(response.status(),200);const data=await response.json();assert.equal(data.ok,true);assert(Array.isArray(data.capabilities)&&data.html);checks.push('Native detail endpoint compatibility '+unit);}
  const archive=await context.request.get(base+'/pembayaran/riwayat_daftar_ulang.php?view=deleted');assert.equal(archive.status(),200);assert(!/Fatal error|Uncaught/.test(await archive.text()));checks.push('Archive compatibility '+unit);
  if(unit===1){
   const href=await cards.nth(1).getAttribute('href');
   await page.route('**/detail_daftar_ulang.php?*',async route=>{const q=new URL(route.request().url()).searchParams,id=q.get('selected');if(id===await cards.first().getAttribute('data-id'))await new Promise(r=>setTimeout(r,500));await route.fulfill({json:{ok:true,html:'<p data-race-id="'+id+'">Uji respons detail</p>'}}).catch(()=>{});});
   await cards.first().click();await cards.nth(1).click();await page.waitForTimeout(700);assert.equal(await detail.locator('[data-race-id]').getAttribute('data-race-id'),await cards.nth(1).getAttribute('data-id'));await page.unroute('**/detail_daftar_ulang.php?*');
injectedFailure=true;await page.route('**/detail_daftar_ulang.php?*',route=>route.fulfill({status:503,json:{ok:false,message:'Uji gagal memuat'}}));await cards.first().click();await page.getByRole('button',{name:'Coba Lagi',exact:true}).waitFor();await page.unroute('**/detail_daftar_ulang.php?*');await page.getByRole('button',{name:'Coba Lagi',exact:true}).click();await waitDetail();assert.equal(await detail.locator('.du-detail-identity h3').count(),1);injectedFailure=false;checks.push('Abort, latest response, retry, expected HTTP 503');
const nojs=await browser.newContext({javaScriptEnabled:false});await nojs.addCookies([{name:'PHPSESSID',value:sessions['super_admin-1'],url:base}]);const plain=await nojs.newPage();await plain.goto(new URL(href,base+'/pembayaran/riwayat_daftar_ulang.php').href);assert.equal(await plain.locator('.du-detail-identity h3').textContent(),name2);assert.equal(await plain.locator('select[name="status_filter[]"]').count(),1);await nojs.close();checks.push('No JavaScript selection/filter fallback');
   const q=new URL(href,base+'/pembayaran/riwayat_daftar_ulang.php').searchParams;q.set('scope','combined');q.set('unit_id','2');q.set('selected_unit','1');const denied=await context.request.get(base+'/pembayaran/detail_daftar_ulang.php?'+q);assert.equal(denied.status(),400);assert.equal((await denied.json()).ok,false);checks.push('Conflicting unit rejected');
  }
  await page.goto(base+'/pembayaran/riwayat_daftar_ulang.php?q=QA_no_matching_student_09f38b');assert.equal(await page.locator('[data-registration-record]').count(),0);assert(await page.locator('.ph-empty').count());checks.push('Empty state '+unit);
  await context.close();console.log('PASS DU UI unit',unit);
 }
 assert.deepEqual(errors,[]);fs.writeFileSync(audit+'/browser-result.json',JSON.stringify({passed:true,checks,errors},null,2));console.log('PASS',checks.length,'browser checks');
}finally{await browser.close();}})().catch(e=>{console.error(e.stack);process.exitCode=1;});
