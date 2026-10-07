const assert=require('node:assert/strict'),fs=require('node:fs');
if(process.env.SPP_TEST_ALLOW_MUTATION!=='1'||!/^db_spp_audit_/.test(process.env.SPP_DB_NAME||''))throw Error('Gunakan salinan database audit.');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
const fixture=JSON.parse(fs.readFileSync(process.env.SPP_ROLE_PARENT_FIXTURE,'utf8').replace(/^\uFEFF/,''));
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});const errors=[];
try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',e=>errors.push(e.message));
 assert.equal((await (await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
 await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
 await page.goto(base+'/role_management.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('0')]);
 const url='/role_management.php?account_unit[]=1&account_unit[]=2&account_role[]=admin&account_role[]=kasir&account_status[]=active';
 await page.goto(base+url);assert.equal(await page.locator('tbody tr:has([data-label=Akun])').count(),fixture.accounts.filter(a=>[1,2].includes(Number(a.unit_id))&&['admin','kasir'].includes(a.role)&&Number(a.is_active)===1).length);
 await page.locator('input[name=q]').fill('Administrator');await Promise.all([page.waitForNavigation(),page.locator('#account-filter-form').getByRole('button',{name:'Tampilkan',exact:true}).click()]);assert.ok(await page.locator('tbody tr:has([data-label=Akun])').count()>0);
 await page.locator('.account-more summary').first().click();await page.locator('[data-account-shortcut=password]').first().click();await page.locator('#reset-password-modal.show').waitFor();
 await page.keyboard.press('Escape');await page.locator('#reset-password-modal').waitFor({state:'hidden'});
 await Promise.all([page.waitForNavigation(),page.locator('.account-filter-reset').click()]);assert.equal(await page.locator('tbody tr:has([data-label=Akun])').count(),fixture.accounts.length);
 const context=await browser.newContext({javaScriptEnabled:false});await context.addCookies(await page.context().cookies());const fallback=await context.newPage();await fallback.goto(base+url);assert.equal(await fallback.locator('select[name="account_role[]"][multiple]').count(),1);await fallback.locator('select[name="account_role[]"]').selectOption(['admin','bendahara']);await Promise.all([fallback.waitForNavigation(),fallback.getByRole('button',{name:'Tampilkan',exact:true}).click()]);assert.equal(await fallback.locator('tbody tr:has([data-label=Akun])').count(),fixture.accounts.filter(a=>[1,2].includes(Number(a.unit_id))&&['admin','bendahara'].includes(a.role)&&Number(a.is_active)===1).length);await context.close();
 await page.goto(base+'/role_management.php?account_unit[]=2&account_unit[]=3');
 const keys=fixture.keys[1];await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('1')]);
 assert.equal(new URL(page.url()).searchParams.has('account_unit[0]'),false);assert.equal(await page.locator('tbody tr:has([data-label=Akun])').count(),fixture.accounts.filter(a=>Number(a.unit_id)===1).length);
 await page.goto(base+'/laporan/surat_orang_tua_susun.php?mode=selected&students='+encodeURIComponent(keys.join(',')));
 const editor=page.locator('#parent-message'),draft=JSON.parse(await page.locator('#parent-draft-data').textContent());
 await editor.fill('Pengingat kegiatan sekolah.');
 for(const name of ['Tebal','Miring','Garis bawah']){await editor.focus();await page.keyboard.press('Control+a');await page.getByRole('button',{name,exact:true}).click();}
 assert.ok(await editor.evaluate(e=>!!e.querySelector('b,strong')&&!!e.querySelector('i,em')&&!!e.querySelector('u')));
 for(const [label,tag] of [['Daftar berpoin','ul'],['Daftar bernomor','ol']]){await editor.focus();await page.keyboard.press('Control+a');await page.getByRole('button',{name:label,exact:true}).click();assert.ok(await editor.evaluate((e,t)=>!!e.querySelector(t),tag));}
 await page.locator('[data-format=formatBlock]').click();await page.locator('#parent-save').click();await page.waitForFunction(()=>document.getElementById('parent-save-status').textContent==='Draf tersimpan sementara.');
 await page.locator('#parent-copy').click();await page.locator('.parent-copy-target input').first().check();assert.equal(await page.locator('#copy-select-all').evaluate(e=>e.indeterminate),true);await page.getByRole('button',{name:'Batal',exact:true}).click();await page.locator('#parent-copy').click();assert.equal(await page.locator('.parent-copy-target input:checked').count(),0);await page.locator('#copy-select-all').check();
 let failing=true;await page.route('**/surat_orang_tua_draf.php',async route=>{if(route.request().postDataJSON().action==='apply_message'&&failing){await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,message:'Uji gagal menerapkan'})});}else await route.continue();});
 await page.locator('#copy-apply').click();await page.waitForFunction(()=>document.getElementById('copy-result').textContent.includes('Uji gagal menerapkan'));assert.equal(await page.locator('#copy-apply').isDisabled(),false);failing=false;await page.locator('#copy-apply').click();await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});await page.unroute('**/surat_orang_tua_draf.php');
 // A pending autosave response cannot replace newer typing or a bulk application.
 let started=false,release;const gate=new Promise(resolve=>release=resolve);
 await page.route('**/surat_orang_tua_draf.php',async route=>{if(route.request().postDataJSON().action==='save'&&!started){started=true;const response=await route.fetch();await gate;await route.fulfill({response});}else await route.continue();});
 await editor.fill('Versi pertama menunggu.');await page.waitForTimeout(700);assert.equal(started,true);await editor.fill('Versi terbaru tetap tersimpan.');await page.locator('#parent-copy').click();await page.locator('#copy-select-all').check();await page.locator('#copy-overwrite').check();await page.locator('#copy-confirm').check();await page.locator('#copy-apply').click();release();await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});await page.unroute('**/surat_orang_tua_draf.php');
 await page.locator('.parent-recipient').nth(1).click();await page.waitForFunction(()=>document.querySelectorAll('.parent-recipient')[1].getAttribute('aria-pressed')==='true');assert.equal(await editor.innerText(),'Versi terbaru tetap tersimpan.');
 const endpoint=base+'/laporan/surat_orang_tua_draf.php';
 for(const targets of [[keys[2],'99|foreign'],[keys[0]]])assert.equal((await page.request.post(endpoint,{headers:{'X-CSRF-Token':draft.csrf},data:{action:'apply_message',draft:draft.token,source_key:keys[0],message:'Dilarang',targets,overwrite:true}})).status(),400);
 const second=await browser.newContext();await second.addCookies([]);assert.equal((await second.request.post(endpoint,{headers:{'X-CSRF-Token':draft.csrf},data:{draft:draft.token,messages:{}}})).status(),401);await second.close();
 for(const scope of [1,2,3,0]){await page.goto(base+'/role_management.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(scope))]);for(const width of [1440,768,390])for(const theme of ['light','dark']){await page.setViewportSize({width,height:1000});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false);}}
 assert.deepEqual(errors,[]);console.log('OK final: compound account filters/search/reset/no-JS, account shortcuts, all rich text commands, partial/all/cancel, apply retry, stale autosave, server rejection and responsive layouts.');
}finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
