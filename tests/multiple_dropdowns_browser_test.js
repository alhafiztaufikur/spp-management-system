const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,out=process.env.SPP_XLSX_TEST_OUTPUT;
assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);assert.ok(base&&out);
const password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
const values=async select=>select.evaluate(s=>[...s.selectedOptions].map(o=>o.value));
const pages=['siswa/daftar.php','master_kelas.php','master_spp.php','master_daftar_ulang.php','master_biaya_lain.php','pembayaran/form.php','pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php','otorisasi_transaksi.php?view=history','tabungan/riwayat.php','tabungan/cetak.php','laporan/index.php','laporan/surat_orang_tua.php','role_management.php'];
const reports=['status','per-item','penerimaan','spp-tahunan','tabungan-siswa','saldo-tabungan','riwayat-tagihan','tunggakan-siswa','setoran','kas-tabungan'];
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);assert.ok(!page.url().endsWith('login.php'));
  for(const unit of [1,2,3,0]){
    await page.goto(base+'/dashboard.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
    for(const url of [...pages.filter(p=>unit!==0||!['master_kelas.php','master_spp.php','master_daftar_ulang.php','master_biaya_lain.php','pembayaran/form.php'].includes(p)),...reports.map(t=>'laporan/template.php?template='+t+'&unit=active')]){
      const response=await page.goto(base+'/'+url);assert.equal(response.status(),200,url);await page.waitForLoadState('networkidle');assert.equal(await page.locator('select[data-filter-multiple]:not([multiple])').count(),0,'Unconverted '+url);
      for(const select of await page.locator('select[data-filter-multiple]').all()){
        const trigger=select.locator('..').locator('.spp-select-trigger');if(await trigger.isDisabled()||!await trigger.isVisible())continue;
        await trigger.scrollIntoViewIfNeeded();await trigger.click();const panel=page.locator('.spp-multi-panel:visible');assert.equal(await panel.count(),1,url);assert.ok(await panel.locator('input[type=checkbox]').count());assert.equal(await panel.locator('input[type=search]').count(),0);assert.equal(await panel.locator('.spp-dropdown-note').innerText(),'Bisa pilih beberapa opsi');assert.equal(await panel.getByRole('button',{name:'Terapkan',exact:true}).count(),1);await page.keyboard.press('Escape');
      }
    }
    for(const [width,theme] of [[1440,'light'],[768,'dark'],[390,'light'],[390,'dark']]){
      await page.setViewportSize({width,height:950});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
      await page.goto(base+'/laporan/template.php?template=status&unit=active');await page.waitForLoadState('networkidle');const select=page.locator('select[name="kategori[]"]'),trigger=select.locator('..').locator('.spp-select-trigger');
      const initial=await values(select);await trigger.click();let panel=page.locator('.spp-multi-panel:visible');await panel.locator('.spp-multi-option').filter({hasText:/^Komite$/}).locator('input').check();assert.deepEqual(await values(select),initial,'Draft applied too early');await panel.getByRole('button',{name:'Batal',exact:true}).click();assert.deepEqual(await values(select),initial);
      await trigger.click();panel=page.locator('.spp-multi-panel:visible');await panel.locator('.spp-multi-all input').check();const enabled=await select.evaluate(s=>[...s.options].filter(o=>o.value!=='*'&&!o.disabled).length);assert.match(await panel.locator('.spp-multi-footer>span').textContent(),new RegExp('^'+enabled+' pilihan$'));assert.equal(await panel.locator('input[type=search]').count(),0);assert.equal(await panel.locator('.spp-dropdown-note').innerText(),'Bisa pilih beberapa opsi');const komite=panel.locator('.spp-multi-option').filter({hasText:/^Komite$/}).locator('input');assert.ok(await komite.isChecked());await komite.uncheck();assert.ok(await panel.locator('.spp-multi-all input').evaluate(i=>i.indeterminate));await page.keyboard.press('Escape');assert.deepEqual(await values(select),initial,'Escape applied draft');
      await trigger.click();panel=page.locator('.spp-multi-panel:visible');await panel.locator('.spp-multi-all input').check();await panel.getByRole('button',{name:'Terapkan',exact:true}).click();assert.deepEqual(await values(select),['*']);
      await trigger.click();panel=page.locator('.spp-multi-panel:visible');await panel.locator('.spp-multi-all input').uncheck();assert.ok(await panel.getByRole('button',{name:'Terapkan',exact:true}).isDisabled());assert.match(await panel.locator('.spp-multi-footer>span').textContent(),/Pilih minimal satu/);await page.mouse.click(2,2);assert.deepEqual(await values(select),['*'],'Outside applied draft');
      await trigger.click();panel=page.locator('.spp-multi-panel:visible');const rect=await panel.boundingBox();assert.ok(rect.x>=0&&rect.x+rect.width<=width+1&&rect.y>=0&&rect.y+rect.height<=951,'Panel overflow');await page.screenshot({path:path.join(out,'multi-'+unit+'-'+width+'-'+theme+'.png')});await page.keyboard.press('Escape');
    }
    await page.setViewportSize({width:1440,height:1000});await page.goto(base+'/laporan/template.php?template=per-item&unit=active');await page.waitForLoadState('networkidle');const category=page.locator('[data-report-item-category]');await category.locator('..').locator('.spp-select-trigger').click();let panel=page.locator('.spp-multi-panel:visible');await panel.locator('.spp-multi-option').filter({hasText:/^Daftar Ulang$/}).locator('input').check();await panel.locator('.spp-multi-option').filter({hasText:/^Uang PSB$/}).locator('input').check();await panel.getByRole('button',{name:'Terapkan'}).click();for(const type of ['month','date','academic-year'])assert.ok(await page.locator('[data-per-item-period="'+type+'"]').first().isVisible(),'Period '+type);
    console.log('PASS browser unit '+unit+' menus, Apply/Cancel/All, keyboard and themes');
  }
  // Native option changes, automatic values and reset must keep the multi control in sync.
  await page.goto(base+'/laporan/template.php?template=status&unit=active');
  const dynamic=page.locator('select[name="kategori[]"]'),dynamicButton=dynamic.locator('..').locator('.spp-select-trigger');
  await dynamic.evaluate(s=>{s.innerHTML='<option value="a" selected>Contoh A</option><option value="b">Contoh B</option><option value="c" disabled>Contoh C</option>';});
  await dynamicButton.click();let dynamicPanel=page.locator('.spp-multi-panel:visible');
  assert.ok(await dynamicPanel.locator('.spp-multi-option').filter({hasText:'Contoh C'}).locator('input').isDisabled());
  await dynamicPanel.locator('.spp-multi-all input').check();await dynamicPanel.getByRole('button',{name:'Terapkan',exact:true}).click();
  assert.deepEqual(await values(dynamic),['*']);assert.deepEqual(await dynamic.evaluate(s=>window.sppSelectedValues(s)),['a','b']);
  await dynamic.evaluate(s=>{s.value='b';});assert.match(await dynamicButton.innerText(),/Contoh B/);
  await dynamic.evaluate(s=>{s.form.reset();});await page.waitForFunction(()=>document.querySelector('select[name="kategori[]"]').selectedOptions[0]?.value==='a');
  assert.match(await dynamicButton.innerText(),/Contoh A/);
  await dynamic.evaluate(s=>s.disabled=true);assert.ok(await dynamicButton.isDisabled());
  assert.deepEqual(errors,[]);
  const fallback=await browser.newContext({javaScriptEnabled:false});await fallback.addCookies(await page.context().cookies());const native=await fallback.newPage();await native.goto(base+'/siswa/daftar.php');assert.equal(await native.locator('select[name="status[]"][multiple]').count(),1);assert.ok(await native.locator('select[name="status[]"]').isVisible());await fallback.close();
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
