const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_HTTP_BASE,dir=process.env.SPP_QA_DIR;
assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
assert.match(base||'',/^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const sessions=JSON.parse(fs.readFileSync(dir+'/ui-scopes.json'));
(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});let states=0;
  try{
    for(const scope of [1,2,3,0]){
      const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:sessions[scope],url:base}]);
      assert.equal((await(await context.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
      const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
      for(const theme of ['light','dark'])for(const width of [1600,900,390]){
        await page.setViewportSize({width,height:1000});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
        for(const route of ['tabungan/cetak.php','master_spp.php?tahun_ajaran=2026%2F2027','master_kelas.php','role_management.php']){
          await page.goto(base+'/'+route);await page.locator('.topbar').waitFor();
          assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Page overflow '+route+'/'+width);
          if(route==='tabungan/cetak.php'){
            const result=await page.locator('.savings-print-hero').evaluate(hero=>({bg:getComputedStyle(hero).backgroundImage,top:hero.getBoundingClientRect().top,bar:document.querySelector('.topbar').getBoundingClientRect().bottom,text:hero.innerText}));
            assert.match(result.bg,/gradient/);assert.ok(result.top-result.bar>=18);assert.doesNotMatch(result.text,/\d+ siswa aktif/);assert.match(result.text,/A5\s+kertas potret/);assert.match(result.text,/A6\s+buku lipat lanskap/);
          }else if(route.startsWith('master_spp')&&scope!==0){
            await page.locator('[data-spp-tab="students"]').first().click();
            const metrics=await page.evaluate(()=>{
              const filter=document.querySelector('.promotion-filter-bar').getBoundingClientRect(),toolbar=document.querySelector('.promotion-selection-toolbar').getBoundingClientRect(),table=document.querySelector('.spp-students-table-wrap').getBoundingClientRect();
              const title=[...document.querySelectorAll('.spp-side-heading h2')].find(e=>e.textContent==='Tarif Dasar per Tingkat'),rect=title.getBoundingClientRect();
              return {gap:toolbar.top-filter.bottom,tableGap:table.top-toolbar.bottom,titleHeight:rect.height,lineHeight:parseFloat(getComputedStyle(title).lineHeight),overflow:title.scrollWidth>title.clientWidth+1};
            });
            assert.ok(metrics.gap>=14&&metrics.tableGap>=14,'SPP controls touch '+JSON.stringify(metrics));assert.ok(metrics.titleHeight<metrics.lineHeight*1.5&&!metrics.overflow,'Tariff heading overflows '+JSON.stringify(metrics));
            for(const tab of ['rates','year']){await page.locator('[data-spp-tab="'+tab+'"]').first().click();assert.ok(await page.locator('[data-spp-panel="'+tab+'"]').isVisible());}
          }else if(route==='master_kelas.php'&&scope!==0){
            const offset=await page.locator('.class-filter-panel .class-panel-heading').evaluate(e=>{const a=e.querySelector('.class-workspace-icon').getBoundingClientRect(),b=e.querySelector('h2').getBoundingClientRect();return Math.abs((a.top+a.bottom-b.top-b.bottom)/2);});assert.ok(offset<2,'Filter heading not vertically centered');
          }else if(route==='role_management.php'){
            const text=await page.locator('main').innerText();assert.doesNotMatch(text,/Kelola akun petugas, ubah password|Akun baru mengikuti unit/);assert.match(text,/Memiliki riwayat/);assert.ok(await page.locator('.btn-purge-account[disabled]').count()>0);
          }
          states++;
        }
      }
      if(scope===1){
        const prefix='qa.browser.delete.'+Date.now();let pending=[];
        await page.goto(base+'/role_management.php');const csrf=await page.locator('#form-tambah-akun [name=csrf_token]').inputValue();
        try{
          for(const width of [1600,390]){
            const username=prefix+'.'+width;await page.request.post(base+'/role_management.php',{form:{aksi:'tambah',csrf_token:csrf,nama:'Hapus Browser '+width,username,role:'kasir',unit_id:'1',password:'Qa_'+prefix,password_confirmation:'Qa_'+prefix}});
            await page.setViewportSize({width,height:1000});await page.goto(base+'/role_management.php?q='+encodeURIComponent(username));
            const button=page.locator('.btn-purge-account:not([disabled])');assert.equal(await button.count(),1);const id=await button.getAttribute('data-account-id');pending.push(id);
            if(width===390){await page.locator('.account-more summary').click();await page.locator('[data-account-shortcut=delete]').click();}else await button.click();
            await page.locator('#purge-account-modal.show').waitFor();assert.match(await page.locator('#purge-account-label').innerText(),new RegExp(username.replace(/\./g,'\\.')));
            await page.keyboard.press('Escape');await page.locator('#purge-account-modal').waitFor({state:'hidden'});assert.equal(await button.count(),1);
            await button.click();await Promise.all([page.waitForURL('**/role_management.php?**'),page.locator('#form-purge-account button[type=submit]').click()]);
            await page.locator('#flash-msg').waitFor();assert.match(await page.locator('#flash-msg').innerText(),/berhasil dihapus/);assert.equal(await page.locator('.btn-purge-account').count(),0);pending=pending.filter(x=>x!==id);
          }
        }finally{for(const id of pending)await page.request.post(base+'/role_management.php',{form:{aksi:'hapus',csrf_token:csrf,account_id:id}});}
      }
      assert.deepEqual(errors,[]);await context.close();
    }
    console.log('PASS: '+states+' workspace states, four unit scopes, themes/viewports, SPP gaps, restored savings banner, centered class filter and desktop/mobile deletion');
  }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
