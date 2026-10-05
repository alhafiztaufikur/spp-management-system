const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const{chromium}=require(process.env.SPP_PLAYWRIGHT_CORE),base=new URL(process.env.SPP_TEST_BASE_URL),dir=process.env.SPP_PROMOTION_ARTIFACTS;
assert.match(process.env.SPP_DB_NAME,/^db_spp_audit_/);assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.ok(['localhost','127.0.0.1'].includes(base.hostname));
const f=JSON.parse(fs.readFileSync(path.join(dir,'promotion-ui-fixture.json'),'utf8'));
(async()=>{const b=await chromium.launch({channel:'chrome',headless:true});try{
 const c=await b.newContext({extraHTTPHeaders:{'X-SPP-Test-Current-Year':f[1].source_year},viewport:{width:1440,height:1000}});
 const p=await c.newPage(),errors=[],dialogs=[];p.on('pageerror',e=>errors.push(e.message));p.on('dialog',async d=>{dialogs.push(d.message());await d.accept();});
 assert.equal((await(await p.request.get(new URL('/tests/browser_clone_identity.php',base).href)).json()).database,process.env.SPP_DB_NAME);
 await p.goto(new URL('/login.php',base).href);await p.locator('#username').fill('superadmin');await p.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await p.locator('#btn-login').click();await p.waitForURL('**/dashboard.php');
 const url=(unit,level)=>new URL('/master_kelas.php?source_year_id='+f[unit].year_id+'&source_level='+level,base).href;
 let states=0;
 for(const unit of [0,1,2,3]){
  await p.goto(new URL('/dashboard.php',base).href);await Promise.all([p.waitForNavigation(),p.locator('#sidebar-unit-select').selectOption(String(unit))]);
  for(const theme of ['light','dark'])for(const width of [1440,2560,390]){
   await p.setViewportSize({width,height:width===390?844:1000});await p.evaluate(t=>localStorage.setItem('spp_theme',t),theme);
   for(const level of unit?[f[unit].first,f[unit].last-1,f[unit].last]:[0]){
    const r=await p.goto(unit?url(unit,level):new URL('/master_kelas.php',base).href);assert.equal(r.status(),200);await p.waitForLoadState('networkidle');
    await p.addStyleTag({content:'*,*::before,*::after{transition:none!important;animation:none!important}'});
    assert.equal(await p.locator('html').getAttribute('data-theme'),theme);assert.equal(await p.locator('html').getAttribute('data-palette'),['super','sd','smp','sma'][unit]);assert.doesNotMatch(await p.content(),/Fatal error|Warning:|Unknown column/);
    await p.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+2);
    if(unit){assert.equal(await p.locator('#promotion-source-year').inputValue(),String(f[unit].year_id));assert.equal(await p.locator('#promotion-source-level').inputValue(),String(level));assert.equal(await p.locator('#promotion-batch-form').getAttribute('data-source-year'),f[unit].source_year);}
    else assert.equal(await p.locator('#promotion-batch-form:visible').count(),0);
    await p.screenshot({path:path.join(dir,'ui','unit-'+unit+'-'+theme+'-'+width+'-'+level+'.png'),fullPage:true});states++;
   }
  }
 }
 await p.setViewportSize({width:1440,height:1000});
 for(const unit of [1,2,3]){
  await p.goto(new URL('/dashboard.php',base).href);await Promise.all([p.waitForNavigation(),p.locator('#sidebar-unit-select').selectOption(String(unit))]);
  await p.goto(new URL('/master_kelas.php',base).href);assert.equal(await p.locator('#promotion-source-level').inputValue(),'');
  await Promise.all([p.waitForNavigation(),p.locator('#promotion-source-level').selectOption(String(f[unit].last-1))]);
  const act=async key=>{const row=p.locator('[data-promotion-student]').filter({has:p.locator('input[value="'+f[unit].students[key].nis+'"]')});assert.equal(await row.count(),1);await row.locator('input[type=checkbox]').check();await Promise.all([p.waitForNavigation(),p.locator('#promotion-submit-button').click()]);};
  await p.locator('#promotion-batch-search').fill(' a');await act('a');
  assert.equal(await p.locator('#promotion-source-level').inputValue(),String(f[unit].last-1));assert.equal(await p.locator('input[name="selected_students[]"][value="'+f[unit].students.a.nis+'"]').count(),0);assert.equal(await p.locator('input[name="selected_students[]"][value="'+f[unit].students.b.nis+'"]').count(),1);
  assert.equal(await p.locator('#promotion-batch-search').inputValue(),'a');
  await p.goto(url(unit,f[unit].first));await act('junior');
  await p.goto(url(unit,f[unit].last-1));await p.locator('#promotion-select-all').click();assert.equal(await p.locator('input[name="selected_students[]"]:checked').count(),1);await Promise.all([p.waitForNavigation(),p.locator('#promotion-submit-button').click()]);
  await p.goto(url(unit,f[unit].last));assert.equal(await p.locator('[data-promotion-student]').count(),1);assert.equal(await p.locator('input[name="selected_students[]"][value="'+f[unit].students.a.nis+'"]').count(),0);await act('senior');
  assert.equal(await p.locator('#promotion-source-level').inputValue(),String(f[unit].last));assert.equal(await p.locator('[data-promotion-student]').count(),0);
 }
 assert.ok(dialogs.every(t=>t.includes(f[1].source_year)));assert.deepEqual(errors,[]);
 fs.writeFileSync(path.join(dir,'browser-results.json'),JSON.stringify({states,dialogs:dialogs.length,errors},null,2));
 console.log('PASS: '+states+' visual states, free level selection, lower-first and junior-before-senior, context/search persistence, no looping graduation, all-unit read-only, dialogs and no JS errors');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exit(1)});

