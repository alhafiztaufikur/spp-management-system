const assert = require('node:assert/strict'), fs = require('node:fs');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
assert.match(process.env.SPP_DB_NAME || '', /^db_spp_audit_[a-z0-9_]+$/);
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = process.env.SPP_TEST_BASE_URL;
assert.ok(/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base));
const fixture = JSON.parse(fs.readFileSync(process.env.SPP_ROLE_PARENT_FIXTURE, 'utf8').replace(/^\uFEFF/, ''));
const password = fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE, 'utf8').trim();
async function choose(page, index) {
  await page.locator('.parent-recipient').nth(index).click();
  await page.waitForFunction(i => document.querySelectorAll('.parent-recipient')[i].getAttribute('aria-pressed') === 'true'
    && document.getElementById('parent-message').contentEditable === 'true', index);
}
async function applyAll(page) {
  await page.locator('#parent-copy').click();
  await page.locator('#copy-select-all').check();
  await page.locator('#copy-apply').click();
  await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});
}
(async () => {
  const browser = await chromium.launch({channel:'chrome',headless:true});
  const context = await browser.newContext(), page = await context.newPage(), errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    assert.equal((await (await page.request.get(base+'/tests/browser_clone_identity.php')).json()).database, process.env.SPP_DB_NAME);
    await page.goto(base+'/login.php');
    await page.locator('#username').fill('superadmin');await page.locator('#password').fill(password);
    await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
    assert.ok(!page.url().endsWith('/login.php'));
    for (const scope of [1,2,3,0]) {
      await page.goto(base+'/dashboard.php');
      await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(scope))]);
      const keys = scope ? fixture.keys[scope] : [fixture.keys[1][0],fixture.keys[2][0],fixture.keys[3][0],fixture.keys[1][1]];
      await page.goto(base+'/laporan/surat_orang_tua_susun.php?mode=selected&students='+encodeURIComponent(keys.join(',')));
      assert.equal(await page.locator('.parent-recipient').count(),4);
      const draft = JSON.parse(await page.locator('#parent-draft-data').textContent());
      const editor = page.locator('#parent-message');
      assert.equal(await page.locator('.parent-tips,.parent-summary-note,.parent-placement-note,#copy-overwrite,#copy-confirm').count(),0);
      await choose(page,1);await editor.fill('Pesan lama khusus.');
      await choose(page,0);const common='Pengingat bersama '+scope+' & orang tua.';await editor.fill(common);
      await editor.focus();await page.keyboard.press('Control+a');await page.getByRole('button',{name:'Tebal',exact:true}).click();
      await page.locator('#parent-copy').click();await page.locator('#copy-recipient-search').fill('NO_MATCH');
      await page.locator('#copy-select-all').check();assert.equal(await page.locator('#copy-selected-count').innerText(),'3 penerima dipilih');
      await page.locator('#copy-apply').click();await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});
      for (let i=0;i<4;i++) { await choose(page,i);assert.equal(await editor.innerText(),common);assert.ok(await editor.locator('strong,b').count()); }
      await page.reload();
      const saved = JSON.parse(await page.locator('#parent-draft-data').textContent());
      assert.ok(Object.values(saved.messages).every(message=>message.html.includes('Pengingat bersama')));
      await choose(page,2);await editor.fill('Pesan mandiri siswa ketiga.');await choose(page,0);
      await page.locator('#parent-copy').click();await page.locator('.parent-copy-target input').first().check();
      await page.locator('#copy-apply').click();await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});
      await choose(page,2);assert.equal(await editor.innerText(),'Pesan mandiri siswa ketiga.');
      await choose(page,1);assert.equal(await editor.innerText(),common);
      // Apply failures retain the dialog and can be retried without reverting saved text.
      let fail=true;
      await page.route('**/surat_orang_tua_draf.php', async route=>{
        if(route.request().postDataJSON().action==='apply_message'){
          assert.equal(route.request().postDataJSON().overwrite,true);
          if(fail){fail=false;await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,message:'Uji gagal menerapkan'})});return;}
        }
        await route.continue();
      });
      await page.locator('#parent-copy').click();await page.locator('#copy-select-all').check();await page.locator('#copy-apply').click();
      await page.waitForFunction(()=>document.getElementById('copy-result').textContent.includes('Uji gagal menerapkan'));
      assert.equal(await page.locator('#copy-apply').isDisabled(),false);
      await page.locator('#copy-apply').click();await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});
      await page.unroute('**/surat_orang_tua_draf.php');await choose(page,0);
      // Hold an autosave response while the source is edited again, then apply its newest version.
      let release,started;const gate=new Promise(resolve=>release=resolve), began=new Promise(resolve=>started=resolve);let intercepted=false;
      await page.route('**/surat_orang_tua_draf.php', async route=>{
        if(route.request().postDataJSON().action==='save'&&!intercepted){intercepted=true;const response=await route.fetch();started();await gate;await route.fulfill({response});}
        else await route.continue();
      });
      await editor.fill('Versi pertama menunggu.');
      await Promise.race([began,new Promise((_,reject)=>setTimeout(()=>reject(Error('Autosave did not start')),10000))]);
      const latest='Versi terbaru '+scope+'.';await editor.fill(latest);
      await page.locator('#parent-copy').click();await page.locator('#copy-select-all').check();await page.locator('#copy-apply').click();
      assert.equal(await editor.getAttribute('contenteditable'),'false');release();
      await page.locator('#parent-copy-dialog').waitFor({state:'hidden'});await page.unroute('**/surat_orang_tua_draf.php');
      for(let i=0;i<4;i++){await choose(page,i);assert.equal(await editor.innerText(),latest);}
      await page.reload();const final=JSON.parse(await page.locator('#parent-draft-data').textContent());
      assert.equal(Object.keys(final.messages).length,4);assert.ok(Object.values(final.messages).every(message=>message.html.includes(latest)));
      const post=payload=>page.request.post(base+'/laporan/surat_orang_tua_draf.php',{headers:{'X-CSRF-Token':draft.csrf},data:{draft:draft.token,...payload}});
      assert.equal((await post({action:'apply_message',source_key:keys[0],message:'Bad',targets:['99|foreign'],overwrite:true})).status(),400);
      assert.equal((await post({action:'save',messages:{[keys[0]]:'a'.repeat(2001)}})).status(),400);
      assert.equal((await page.request.post(base+'/laporan/surat_orang_tua_draf.php',{data:{draft:draft.token,messages:{}}})).status(),403);
      for (const width of [1440,390]) for (const theme of ['light','dark']) {
        await page.setViewportSize({width,height:1000});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
        await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+2);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false);
        if(process.env.SPP_UI_OUTPUT)await page.screenshot({path:process.env.SPP_UI_OUTPUT+'/compose-'+scope+'-'+width+'-'+theme+'.png',fullPage:true,animations:'disabled'});
      }
      await page.locator('#parent-preview').click();await page.waitForURL('**&preview=1');
      const pdf=await page.request.get(base+'/laporan/surat_orang_tua_pdf.php?draft='+draft.token);
      assert.equal(pdf.status(),200);assert.equal((await pdf.body()).subarray(0,4).toString(),'%PDF');
      console.log('PASS: scope '+scope+' all/partial overwrite, independent edit, reload, rich text, retry, pending autosave, input guards, responsive themes and PDF');
    }
    assert.deepEqual(errors,[]);
  } finally {
    await page.request.post(base+'/logout.php',{form:{csrf_token:await page.locator('form[action$="logout.php"] input[name="csrf_token"]').getAttribute('value').catch(()=> '')}});
    await context.close();await browser.close();
  }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
