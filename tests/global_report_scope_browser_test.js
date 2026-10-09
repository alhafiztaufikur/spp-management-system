const assert=require('node:assert/strict'),fs=require('node:fs');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
const actors=JSON.parse(fs.readFileSync(process.env.SPP_GLOBAL_ACTORS,'utf8').replace(/^\uFEFF/,''));
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});let states=0;const errors=[];
try{
 for(const role of ['super_admin','admin','kasir','bendahara']){
  const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'Asia/Jakarta'}),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  try{
   assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
   await page.goto(base+'/login.php');await page.locator('#username').fill(role==='super_admin'?'superadmin':actors[1].accounts.find(a=>a.role===role).username);await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
   for(const choice of ['1','2','3','all']){
    await page.goto(base+'/laporan/global.php?unit='+choice);assert.equal(await page.locator('.global-unit-choice').count(),4);assert.equal(await page.locator('.global-unit-choice[aria-current=true]').getAttribute('data-report-unit'),choice==='all'?'0':choice);
    const links=await page.locator('.report-template-link').evaluateAll(nodes=>nodes.map(n=>n.href));assert.equal(links.length,9);assert.ok(links.every(link=>new URL(link).searchParams.get('unit')===choice));
    for(const route of ['/laporan/global.php?unit='+choice,'/laporan/template.php?template=saldo-tabungan&unit='+choice]){
     await page.goto(base+route);
     for(const width of [1440,900,390])for(const theme of ['light','dark']){
      await page.setViewportSize({width,height:1000});await page.evaluate(t=>{document.documentElement.dataset.theme=t;window.scrollTo(0,0);},theme);await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+2);
      await page.waitForFunction(()=>{const boxes=[...document.querySelectorAll('.global-unit-choice')].map(e=>e.getBoundingClientRect());return boxes.length===4&&boxes.every(b=>Math.abs(b.height-boxes[0].height)<1&&Math.abs(b.width-boxes[0].width)<1);});
      const boxes=await page.locator('.global-unit-choice').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect(),s=getComputedStyle(n);return {x:r.x,y:r.y,w:r.width,h:r.height,bg:s.backgroundColor,color:s.color,active:n.hasAttribute('aria-current')};}));
      assert.ok(boxes.every(b=>b.h>=44&&Math.abs(b.h-boxes[0].h)<1&&Math.abs(b.w-boxes[0].w)<1),'Unequal button dimensions '+route+' '+width+' '+theme+' '+JSON.stringify(boxes));
      assert.ok(width<=650?boxes[0].y===boxes[1].y&&boxes[2].y===boxes[3].y&&boxes[2].y>boxes[0].y:boxes.every(b=>b.y===boxes[0].y),'Incorrect responsive layout');
      const active=boxes.find(b=>b.active);assert.equal(active.bg,'rgb(33, 75, 224)');assert.equal(active.color,'rgb(255, 255, 255)');
      if(process.env.SPP_UI_OUTPUT&&role==='kasir')await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/global-'+(route.includes('template')?'template':'catalogue')+'-'+choice+'-'+width+'-'+theme+'.png',fullPage:true,animations:'disabled'});
      states++;
     }
    }
   }
   await page.setViewportSize({width:1440,height:1000});await page.goto(base+'/laporan/template.php?template=status&unit=1&tanggal_awal=2026-07-01&tanggal_akhir=2026-09-01&tahun_ajaran=2026%2F2027&kelas=tingkat%3A1&q=Siswa&operator=1&kategori=psb');
   const target=page.locator('[data-report-unit="2"]');await target.focus();assert.equal(await target.evaluate(e=>e===document.activeElement),true);await Promise.all([page.waitForNavigation(),page.keyboard.press('Enter')]);
   const params=new URL(page.url()).searchParams;assert.equal(params.get('unit'),'2');for(const key of ['kelas','q','operator','kategori'])assert.equal(params.has(key),false);assert.equal(params.get('tanggal_awal'),'2026-07-01');assert.equal(params.get('tahun_ajaran'),'2026/2027');
   assert.equal(await page.locator('#global-siswa-list option').evaluateAll(nodes=>nodes.every(n=>n.dataset.unitId==='2')),true);
   const native=await browser.newContext({javaScriptEnabled:false});await native.addCookies(await context.cookies());const np=await native.newPage();
   await np.goto(base+'/laporan/global.php?unit=2');await Promise.all([np.waitForNavigation(),np.locator('[data-report-unit="0"]').press('Enter')]);assert.equal(new URL(np.url()).searchParams.get('unit'),'all');
   await np.locator('.report-template-link').first().press('Enter');assert.equal(await np.locator('input[name=unit]').inputValue(),'all');await native.close();
   if(role==='super_admin'){await page.goto(base+'/dashboard.php');assert.equal(await page.locator('#sidebar-unit-select').inputValue(),'1');}else{await page.goto(base+'/laporan/global.php?unit=active');assert.equal(await page.locator('.global-unit-choice[aria-current=true]').getAttribute('data-report-unit'),'1');}
   console.log('PASS: '+role+' four scopes, catalogue/template layouts, keyboard/filter reset, native navigation and operational unit');
  }finally{await context.close();}
 }
 assert.deepEqual(errors,[]);console.log('PASS: '+states+' responsive/theme states; no JavaScript errors');
}finally{await browser.close();}})().catch(e=>{console.error(e.stack);process.exitCode=1;});
