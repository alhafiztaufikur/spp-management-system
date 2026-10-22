/* Mutative UI regression: only the owned, flagged database clone can expose its identity. */
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE),root=process.env.SPP_GLOBAL_QA_DIR;
const f=JSON.parse(fs.readFileSync(path.join(root,'fixture.json')));
assert.equal(f.base,'http://127.0.0.1:58471');assert.match(f.database,/^db_spp_audit_/);
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  const identity=await context.request.get(f.base+'/tests/browser_clone_identity.php');assert.equal((await identity.json()).database,f.database);
  const page=await context.newPage();await page.goto(f.base+'/master_kelas.php?special_new=1#special-class-form');
  const year=await page.locator('#promotion-source-year option').evaluateAll(o=>o.find(v=>v.textContent.trim()==='2026/2027').value);
  const stale='source_year_id='+year+'&source_level=6',name='QA Routing '+Date.now();
  const fields=selector=>page.locator(selector).evaluate(form=>Object.fromEntries(new FormData(form)));
  const post=async data=>{
   const response=await context.request.post(f.base+'/master_kelas.php?'+stale,{form:data,maxRedirects:0});assert.equal(response.status(),302);
   const destination=new URL(response.headers().location,f.base+'/master_kelas.php');assert.equal(destination.hash,'#special-classes');
   await page.goto(destination.href);assert.equal(await page.locator('[data-class-tab=manage]').getAttribute('aria-selected'),'true');
   assert.equal(await page.locator('.alert-success').count(),1);
  };
  await post({...await fields('#special-class-form form'),nama_custom:name,is_active:'1'});
  const row=page.locator('.sc-table tbody tr').filter({hasText:name});assert.equal(await row.count(),1);
  const id=await row.locator('input[name=id]').inputValue();assert(Number(id)>0);
  await page.goto(f.base+'/master_kelas.php?'+stale+'&special_edit='+id+'#special-class-form');
  assert.equal(await page.locator('[data-class-tab=manage]').getAttribute('aria-selected'),'true');
  await post({...await fields('#special-class-form form'),nama_custom:name+' Edit'});
  assert.equal(await page.locator('.sc-table tbody tr').filter({hasText:name+' Edit'}).count(),1);
  await page.goto(f.base+'/master_kelas.php?'+stale+'&special_delete='+id+'#special-delete-confirm');
  assert.equal(await page.locator('#special-delete-confirm').isVisible(),true);
  await post(await fields('#special-delete-confirm form'));
  assert.equal(await page.locator('.sc-table tbody tr').filter({hasText:name}).count(),0);
  const members=await page.locator('.sc-table tbody tr').evaluateAll(rows=>rows.map(row=>({count:Number(row.querySelector('[data-label="Anggota"]').textContent.replace(/\D/g,'')),href:row.querySelector('[data-sc-members]').href})).sort((a,b)=>b.count-a.count)[0]);
  assert(members.count>25);const memberUrl=new URL(members.href);memberUrl.searchParams.set('source_year_id',year);memberUrl.searchParams.set('source_level','6');
  await page.goto(memberUrl.href);assert.equal(await page.locator('[data-class-tab=manage]').getAttribute('aria-selected'),'true');
  await page.locator('.sc-member-footer a[data-sc-members]').filter({hasText:'Berikutnya'}).click();
  await page.waitForFunction(()=>document.querySelector('.sc-member-footer').textContent.includes('Halaman 2'));
  await page.goto(f.base+'/master_kelas.php?'+stale+'#naik-kelas');
  assert.equal(await page.locator('.class-create-actions').isVisible(),false);
  fs.writeFileSync(path.join(root,'master-actions-result.json'),JSON.stringify({ok:true,created_edited_deleted_on_clone:true,member_pagination:true}));
  console.log('OK: create/edit/delete redirects, nested anchors and hidden promotion shortcut on clone');
 }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
