const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const dir=process.env.SPP_QA_DIR,base=process.env.SPP_HTTP_BASE,fixture=JSON.parse(fs.readFileSync(dir+'/fixture.json','utf8'));
assert.match(fixture.database,/^db_spp_(audit|test)_/);assert.ok(['localhost','127.0.0.1'].includes(new URL(base).hostname));
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});let states=0;try{
 const guard=await (await browser.newContext()).request.get(base+'/tests/browser_clone_identity.php');assert.equal((await guard.json()).database,fixture.database);
 for(const unit of [1,2,3,0]){
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.units[unit].cookie,url:base}]);const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const theme of ['light','dark'])for(const width of [1600,900,390]){
   await page.setViewportSize({width,height:1000});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
   for(const view of ['active','deleted']){
    const response=await page.goto(base+'/pembayaran/riwayat_daftar_ulang.php?view='+view);assert.equal(response.status(),200);assert.doesNotMatch(await page.content(),/Fatal error|Warning:/);await page.waitForTimeout(120);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,'Overflow '+unit+' '+width+' '+view);assert.equal(await page.locator('html').getAttribute('data-theme'),theme);
    assert.equal(await page.locator('.spp-select-panel input[type=search]').count(),0);
    await page.screenshot({path:`${dir}/registration-${unit}-${width}-${theme}-${view}.png`,fullPage:true});states++;
   }
  }
  await page.setViewportSize({width:1600,height:1000});
  const expected=fixture.units[unit].expected.map(r=>`${r.unit_id}|${r.tagihan_id}`).sort(),seen=[];let p=1;
  do{
   await page.goto(base+'/pembayaran/riwayat_daftar_ulang.php?per_page=100&page='+p);
   seen.push(...await page.locator('[data-registration-record]').evaluateAll(rows=>rows.map(e=>`${e.dataset.unit}|${e.dataset.id}`)));
   p++;
  }while(seen.length<expected.length);
  assert.deepEqual(seen.sort(),expected,'All-page identities unit '+unit);assert.equal(new Set(seen).size,seen.length);
  await page.goto(base+'/pembayaran/riwayat_daftar_ulang.php');
  const input=page.locator('#du-history-student');await input.click();await input.fill('Siswa');const suggestion=page.locator('.student-search-panel:not([hidden]) .student-search-option').first();
  await suggestion.waitFor();assert.equal(await suggestion.evaluate(e=>{const r=e.getBoundingClientRect();return e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))}),true,'Student search covered');await page.keyboard.press('Escape');
  if(await page.locator('[data-registration-record]').count()>1){
   await page.locator('[data-registration-next]').click();await page.waitForFunction(()=>!document.querySelector('[data-registration-detail]').hasAttribute('aria-busy'));assert.equal(await page.locator('[data-registration-record]').nth(1).getAttribute('aria-current'),'true');
   await page.locator('[data-registration-close]').click();assert.equal(await page.locator('[data-registration-detail-panel]').isVisible(),false);await page.locator('[data-registration-record]').first().click();await page.waitForFunction(()=>!document.querySelector('[data-registration-detail]').hasAttribute('aria-busy'));
  }
  const invalid=await context.request.get(base+'/pembayaran/riwayat_daftar_ulang.php?kelas[]=rombel:999999');assert.equal(invalid.status(),400);
  const broken=await context.request.get(base+'/pembayaran/detail_daftar_ulang.php?tagihan_id=1&unit_id=1&status[]=bogus');assert.equal(broken.status(),400);assert.equal((await broken.json()).ok,false);
  assert.equal((await context.request.get(base+'/pembayaran/riwayat_daftar_ulang.php?student_id=invalid')).status(),400);
  if(unit===1 && await page.locator('[data-registration-record]').count()>1){
   // The first response arrives after the second: only the latest detail may render.
   const href0=await page.locator('[data-registration-record]').first().getAttribute('href');
   const href1=await page.locator('[data-registration-record]').nth(1).getAttribute('href');
   const id0=new URL(href0,base+'/pembayaran/').searchParams.get('selected');
   const id1=new URL(href1,base+'/pembayaran/').searchParams.get('selected');
   let count=0;
   await page.route('**/detail_daftar_ulang.php?*',async route=>{count++;const q=new URL(route.request().url()).searchParams;if(q.get('tagihan_id')===id0)await new Promise(r=>setTimeout(r,600));await route.fulfill({json:{ok:true,html:'<p data-race-id="'+q.get('tagihan_id')+'">Latest detail</p>'}}).catch(()=>{});});
   await page.locator('[data-registration-record]').first().click();await page.locator('[data-registration-record]').nth(1).click();await page.waitForTimeout(850);assert.equal(await page.locator('[data-race-id]').getAttribute('data-race-id'),id1);assert.ok(count>=1);await page.unroute('**/detail_daftar_ulang.php?*');
   await page.route('**/detail_daftar_ulang.php?*',route=>route.fulfill({status:500,json:{ok:false,message:'Uji gagal memuat'}}));await page.locator('[data-registration-record]').first().click();await page.getByRole('button',{name:'Coba Lagi',exact:true}).waitFor();await page.unroute('**/detail_daftar_ulang.php?*');await page.getByRole('button',{name:'Coba Lagi',exact:true}).click();await page.locator('[data-registration-detail] .ph-student').waitFor();
   const del=page.locator('.open-du-delete').first();if(await del.count()){await del.click();const dlg=page.locator('#du-delete-dialog');await dlg.waitFor();await page.keyboard.press('Escape');assert.equal(await dlg.isVisible(),false);assert.equal(await del.evaluate(e=>e===document.activeElement),true);}
  }

  await context.close();assert.deepEqual(errors,[]);
 }
 for(const role of ['admin','kasir','bendahara']){
  const ctx=await browser.newContext({javaScriptEnabled:false});await ctx.addCookies([{name:'PHPSESSID',value:fixture.roles[role].cookie,url:base}]);const page=await ctx.newPage();assert.equal((await page.goto(base+'/pembayaran/riwayat_daftar_ulang.php')).status(),200);
  const link=page.locator('[data-registration-record]').nth(1);if(await link.count()){const href=await link.getAttribute('href');await link.click();assert.ok(page.url().endsWith(href));assert.ok(await page.locator('[data-registration-detail] .ph-student h3').textContent());}
  await ctx.close();
 }
 console.log(`OK: ${states} visual states, database identities across all pages, search hit test, detail navigation, invalid filters, no-JS roles.`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
