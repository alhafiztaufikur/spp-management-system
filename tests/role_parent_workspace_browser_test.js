const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
if(process.env.SPP_TEST_ALLOW_MUTATION!=='1'||!/^db_spp_audit_/.test(process.env.SPP_DB_NAME||''))throw Error('Gunakan salinan database audit.');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,fixture=JSON.parse(fs.readFileSync(process.env.SPP_ROLE_PARENT_FIXTURE,'utf8').replace(/^\uFEFF/,''));
const password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim(),output=process.env.SPP_UI_OUTPUT;
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[];
 try{
  const page=await browser.newPage({viewport:{width:1600,height:1000}});page.on('pageerror',e=>errors.push(e.message));
  const identity=await page.request.get(base+'/tests/browser_clone_identity.php');assert.equal((await identity.json()).database,process.env.SPP_DB_NAME);
  async function login(p,name){await p.goto(base+'/login.php');await p.locator('#username').fill(name);await p.locator('#password').fill(password);await Promise.all([p.waitForNavigation(),p.locator('#btn-login').click()]);assert.ok(!p.url().endsWith('/login.php'));}
  async function unit(value){await page.goto(base+'/role_management.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(value))]);}
  async function rows(){return page.locator('.account-list-card tbody tr:has([data-label="Akun"])').count();}
  async function choose(index){const b=page.locator('.parent-recipient').nth(index);await b.click();await b.waitFor();await page.waitForFunction(i=>document.querySelectorAll('.parent-recipient')[i].getAttribute('aria-pressed')==='true',index);await page.waitForFunction(()=>document.getElementById('parent-message').contentEditable==='true');}
  await login(page,'superadmin');
  for(const scope of [1,2,3,0]){
   await unit(scope);await page.goto(base+'/role_management.php');
   const scoped=fixture.accounts.filter(a=>!scope||Number(a.unit_id)===scope);
   assert.equal(await rows(),scoped.length);assert.equal(await page.locator('#role option[value=admin]').count(),1);
   const query='account_role[]=admin&account_role[]=kasir&account_status[]=active';
   await page.goto(base+'/role_management.php?'+query);assert.equal(await rows(),scoped.filter(a=>['admin','kasir'].includes(a.role)&&Number(a.is_active)===1).length);
   assert.equal(await page.locator('.account-role-tabs a').first().locator('span').innerText(),String(scoped.filter(a=>Number(a.is_active)===1).length));
   await page.goto(base+'/role_management.php?q=NO_MATCH_AUDIT');assert.equal(await rows(),0);assert.equal(await page.locator('.account-empty').count(),1);
   for(const suffix of ['account_role[]=INVALID','account_status[]=INVALID','account_role[]=*'+encodeURIComponent('&')+'bad'])assert.equal((await page.request.get(base+'/role_management.php?'+suffix)).status(),400);
   if(scope)assert.equal((await page.request.get(base+'/role_management.php?account_unit[]='+(scope===1?2:1))).status(),400);
   await page.goto(base+'/role_management.php');
   const roleSelect=page.locator('[data-control=account_role] .spp-select-trigger');await roleSelect.click();await page.locator('.spp-dropdown-panel:visible input[value=admin]').check();await Promise.all([page.waitForNavigation(),page.locator('.spp-dropdown-panel:visible').getByRole('button',{name:'Terapkan',exact:true}).click()]);
   // All was selected initially; unchecking is tested separately using a scalar initial value.
   await page.goto(base+'/role_management.php?account_role=admin');await page.locator('[data-control=account_role] .spp-select-trigger').click();await page.locator('.spp-dropdown-panel:visible input[value=kasir]').check();await Promise.all([page.waitForNavigation(),page.locator('.spp-dropdown-panel:visible').getByRole('button',{name:'Terapkan',exact:true}).click()]);assert.equal(await rows(),scoped.filter(a=>['admin','kasir'].includes(a.role)).length);
   const keys=scope?fixture.keys[scope]:[fixture.keys[1][0],fixture.keys[2][0],fixture.keys[3][0],fixture.keys[1][1]];
   await page.goto(base+'/laporan/surat_orang_tua_susun.php?mode=selected&students='+encodeURIComponent(keys.join(',')));
   const draft=JSON.parse(await page.locator('#parent-draft-data').textContent()),token=draft.token;
   assert.equal(await page.locator('.parent-recipient').count(),4);
   const post=payload=>page.request.post(base+'/laporan/surat_orang_tua_draf.php',{headers:{'X-CSRF-Token':draft.csrf},data:{draft:token,...payload}});
   assert.equal((await page.request.post(base+'/laporan/surat_orang_tua_draf.php',{data:{draft:token,messages:{}}})).status(),403);
   assert.equal((await post({messages:{'99|foreign':'Tidak sah'}})).status(),400);
   assert.equal((await post({action:'save',messages:{[keys[0]]:{format:'rich_text',html:'<p>'+('a'.repeat(2001))+'</p>'}}})).status(),400);
   await choose(1);await page.locator('#parent-message').fill('Pesan lama khusus.');
   await choose(0);await page.locator('#parent-message').fill('Pengingat kegiatan '+scope+' & pembayaran.');
   await page.locator('#parent-message').focus();await page.keyboard.press('Control+a');await page.getByRole('button',{name:'Tebal',exact:true}).click();assert.ok(await page.locator('#parent-message').evaluate(e=>!!e.querySelector('b,strong')));
   await page.locator('#parent-copy').click();await page.locator('#copy-recipient-search').fill(keys[1].split('|')[1]);await page.locator('#copy-select-all').check();assert.equal(await page.locator('#copy-selected-count').innerText(),'3 penerima dipilih');
   await page.locator('#copy-apply').click();await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});assert.ok((await page.locator('#parent-save-status').innerText()).includes('2 penerima diperbarui; 1'));
   await choose(1);assert.equal(await page.locator('#parent-message').innerText(),'Pesan lama khusus.');
   await choose(2);assert.ok((await page.locator('#parent-message').innerText()).includes('Pengingat kegiatan'));assert.ok(await page.locator('#parent-message').evaluate(e=>!!e.querySelector('strong,b')));
   await page.locator('#parent-message').fill('Pesan mandiri siswa ketiga.');await choose(0);assert.ok((await page.locator('#parent-message').innerText()).includes('Pengingat kegiatan'));
   await page.locator('#parent-copy').click();await page.locator('#copy-select-all').check();await page.locator('#copy-overwrite').check();assert.equal(await page.locator('#copy-apply').isDisabled(),true);assert.equal(await page.locator('#copy-replace-count').innerText(),'3');await page.locator('#copy-confirm').check();await page.locator('#copy-apply').click();await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});
   await choose(1);assert.ok((await page.locator('#parent-message').innerText()).includes('Pengingat kegiatan'));
   await page.locator('#parent-copy').click();await page.keyboard.press('Escape');assert.equal(await page.locator('#parent-copy-dialog').isVisible(),false);assert.equal(await page.locator('#parent-copy').evaluate(e=>e===document.activeElement),true);
   // Failed save keeps the edited text and the selected student until retry succeeds.
   let fail=true;await page.route('**/surat_orang_tua_draf.php',route=>fail?route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,message:'Uji simpan gagal'})}):route.continue());
   await page.locator('#parent-message').fill('RETRY_'+scope);await page.locator('.parent-recipient').nth(2).click();await page.waitForFunction(()=>document.getElementById('parent-save-status').textContent.includes('Uji simpan gagal'));assert.equal(await page.locator('.parent-recipient').nth(1).getAttribute('aria-pressed'),'true');assert.equal(await page.locator('#parent-message').innerText(),'RETRY_'+scope);fail=false;await page.locator('#parent-save').click();await page.waitForFunction(()=>document.getElementById('parent-save-status').textContent==='Draf tersimpan sementara.');await page.unroute('**/surat_orang_tua_draf.php');
   for(const width of [1600,768,390])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:1000});
    for(const [name,url] of [['role','/role_management.php'],['compose','/laporan/surat_orang_tua_susun.php?draft='+token],['preview','/laporan/surat_orang_tua_susun.php?draft='+token+'&preview=1']]){
     await page.goto(base+url);await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);await page.waitForFunction(()=>[...document.querySelectorAll('.main-card')].every(e=>Number(getComputedStyle(e).opacity)>.99));assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,name+' overflow '+scope+' '+width);
     if(output)await page.screenshot({path:path.join(output,`${name}-${scope}-${width}-${theme}.png`),fullPage:true});
     if(name==='compose'){await page.locator('#parent-copy').click();await page.locator('#copy-select-all').check();assert.equal(await page.evaluate(()=>{const r=document.querySelector('#parent-copy-dialog').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight;}),true);if(output)await page.screenshot({path:path.join(output,`copy-${scope}-${width}-${theme}.png`)});await page.keyboard.press('Escape');}
    }
   }
   await page.goto(base+'/laporan/surat_orang_tua_susun.php?draft='+token);await page.locator('#parent-preview').click();await page.waitForURL('**&preview=1');
   const pdf=await page.request.get(base+'/laporan/surat_orang_tua_pdf.php?draft='+token),download=await page.request.get(base+'/laporan/surat_orang_tua_pdf.php?draft='+token+'&download=1');assert.equal(pdf.status(),200);assert.equal(download.status(),200);assert.match(pdf.headers()['content-type'],/pdf/);assert.match(download.headers()['content-disposition'],/attachment/);if(output){fs.writeFileSync(path.join(output,'preview-'+scope+'.pdf'),await pdf.body());fs.writeFileSync(path.join(output,'download-'+scope+'.pdf'),await download.body());}
   console.log('OK scope '+scope+': account results, modes, rich text, bulk protected/overwrite, retry, PDF and responsive themes.');
  }
  for(const username of ['admin','kasir','bendahara']){const other=await browser.newPage();await login(other,username);await other.goto(base+'/role_management.php');assert.ok(!other.url().includes('role_management.php'));await other.close();}
  assert.deepEqual(errors,[]);console.log('OK: access and no browser JavaScript errors.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
