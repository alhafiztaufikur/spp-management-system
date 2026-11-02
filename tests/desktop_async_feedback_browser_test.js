/* UI adapters only: backup POST/status responses are mocked; no backup or restore is started. */
const assert=require('node:assert/strict'),fs=require('node:fs');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core'),base=process.env.SPP_TEST_BASE_URL;
assert.ok(/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),page=await browser.newPage({viewport:{width:1440,height:1080}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));let summary,release,settingsMode='hold',manual=0,posts=0;
 const gate=new Promise(resolve=>release=resolve),popup=page.locator('#operation-feedback-dialog');
 try{
  assert.equal((await(await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  await page.route('**/backup_restore.php*',async route=>{
   const response=await route.fetch(),html=await response.text(),pattern=/(<script\b[^>]*id="database-backup-config"[^>]*>)([\s\S]*?)(<\/script>)/,match=html.match(pattern);assert.ok(match);
   const config=JSON.parse(match[2]);summary={...config.summary,configured:true,ready:true,service_registered:true,can_create:true,manual_available:true,active:null,jobs:[],alerts:[],count:0};
   summary.settings={...summary.settings,daily_time:'18:00',retention_days:30,version:1,activated:true};config.summary=summary;
   await route.fulfill({response,body:html.replace(pattern,(_,head,_json,tail)=>head+JSON.stringify(config)+tail)});
  });
  await page.route('**/backup_api.php*',async route=>{
   if(route.request().method()==='GET'){await route.fulfill({json:summary});return;}
   posts++;const raw=route.request().postData(),field=name=>raw.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([^\\r]*)'))?.[1];
   if(field('action')==='settings'){
    if(settingsMode==='hold')await gate;
    if(settingsMode==='error'){await route.fulfill({status:409,json:{error:'Pengaturan berubah. Muat ulang sebelum menyimpan.'}});return;}
    summary.settings={...summary.settings,daily_time:field('daily_time'),retention_days:Number(field('retention_days')),version:summary.settings.version+1};
    await route.fulfill({json:{settings:summary.settings,summary}});return;
   }
   const job={id:(++manual===1?'ab':'cd').repeat(16),name:'UI fixture.sql',type:'manual',state:'queued',created_at:new Date().toISOString(),actor_name:'QA',message:'Menunggu antrean.'};
   summary={...summary,active:job,can_create:false,jobs:[job]};await route.fulfill({json:{job,request_key:'ef'.repeat(16)}});
  });
  await page.goto(base+'/backup_restore.php');const clock=page.locator('#backup-daily-time'),days=page.locator('#backup-retention-days'),save=page.locator('#backup-settings-save');
  await clock.fill('22:10');await days.fill('45');await save.click();assert.equal(await page.locator('#operation-feedback-dialog[open]').count(),0,'No optimistic success before server response');
  settingsMode='success';release();await popup.waitFor({state:'visible'});assert.equal(await popup.getAttribute('data-type'),'success');assert.equal(summary.settings.daily_time,'22:10');assert.equal(posts,1);await popup.getByRole('button',{name:'Mengerti'}).click();
  settingsMode='error';await clock.fill('23:00');await save.click();await popup.waitFor({state:'visible'});assert.equal(await popup.getAttribute('data-type'),'error');assert.match(await popup.innerText(),/Muat ulang/);await popup.getByRole('button',{name:'Mengerti'}).click();assert.equal(await clock.inputValue(),'23:00');
  for(const finalState of ['verified','failed']){
   await page.locator('#backup-create').click();await page.locator('#backup-progress').waitFor({state:'visible'});assert.equal(await page.locator('#operation-feedback-dialog[open]').count(),0,'Queue acknowledgement is not completion');
   const finished={...summary.active,state:finalState,finished_at:new Date().toISOString(),bytes:128,message:finalState==='failed'?'Uji kegagalan pencadangan.':'Selesai terverifikasi.'};summary={...summary,active:null,can_create:true,jobs:[finished]};
   await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await popup.waitFor({state:'visible'});assert.equal(await popup.getAttribute('data-type'),finalState==='verified'?'success':'error');await popup.getByRole('button',{name:'Mengerti'}).click();
   const response=page.waitForResponse(r=>r.url().includes('backup_api.php?action=status'));await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await response;assert.equal(await page.locator('#operation-feedback-dialog[open]').count(),0,'Terminal polling does not notify twice');
  }
  assert.deepEqual(errors,[]);console.log('PASS: AJAX settings wait for success/failure responses; backup queue stays inline and terminal success/failure notify once. All backup API mutations were mocked.');
 }finally{release();await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
