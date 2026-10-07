const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_TEST_BASE_URL,out=process.env.SPP_XLSX_TEST_OUTPUT;
assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);assert.ok(base&&out);
const source=JSON.parse(fs.readFileSync(path.join(out,'dropdown-source.json'),'utf8'));
const password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
const pages=['siswa/daftar.php','siswa/aktivasi_legacy.php','master_kelas.php','master_spp.php','master_daftar_ulang.php','master_biaya_lain.php','pembayaran/form.php','pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php','otorisasi_transaksi.php?view=history','tabungan/riwayat.php','tabungan/cetak.php','laporan/index.php','laporan/surat_orang_tua.php','role_management.php','backup_restore.php'];
const reports=['status','per-item','penerimaan','spp-tahunan','tabungan-siswa','saldo-tabungan','riwayat-tagihan','tunggakan-siswa','setoran','kas-tabungan'];
const layouts=['siswa/daftar.php','pembayaran/riwayat_daftar_ulang.php','master_kelas.php','tabungan/riwayat.php'];
async function checkPanel(page,trigger,width,height){
  await trigger.scrollIntoViewIfNeeded();await trigger.click();
  const panel=page.locator('.spp-dropdown-panel:visible');assert.equal(await panel.count(),1);
  assert.equal(await panel.locator('input[type=search],.spp-dropdown-search').count(),0);
  const multi=await panel.evaluate(p=>p.classList.contains('spp-multi-panel'));
  assert.equal(await panel.locator('.spp-dropdown-note').innerText(),multi?'Bisa pilih beberapa opsi':'Pilih satu opsi');
  await trigger.evaluate(t=>new Promise(resolve=>{let previous='',stable=0,frames=0;function tick(){const current=JSON.stringify(t.getBoundingClientRect().toJSON());stable=current===previous?stable+1:0;previous=current;if(stable>=3||++frames>90)resolve();else requestAnimationFrame(tick);}requestAnimationFrame(tick);}));
  const {rect,anchor}=await trigger.evaluate(t=>({rect:document.querySelector('.spp-dropdown-panel:not([hidden]):popover-open').getBoundingClientRect().toJSON(),anchor:t.getBoundingClientRect().toJSON()}));
  assert.ok(rect.x>=7&&rect.y>=7&&rect.x+rect.width<=width-7&&rect.y+rect.height<=height-7,JSON.stringify({rect,anchor,width,height}));
  assert.ok(rect.width<=Math.max(anchor.width,260)+2,'Popup width must follow its control');
  assert.ok(Math.abs(rect.y-anchor.y-anchor.height)<=8||Math.abs(rect.y+rect.height-anchor.y)<=8,'Popup must remain anchored '+page.url()+JSON.stringify({rect,anchor,trigger:await trigger.innerText()}));
  assert.ok(await panel.evaluate(p=>p.contains(document.activeElement)),'Opening focuses an available option '+page.url()+' '+await trigger.innerText());
  await page.keyboard.press('Escape');assert.equal(await trigger.getAttribute('aria-expanded'),'false');assert.ok(await panel.count()===0);
}
async function choose(page,select,values){
  await select.locator('..').locator('.spp-select-trigger').click();const panel=page.locator('.spp-multi-panel:visible');
  await panel.locator('.spp-multi-all input').check();await panel.locator('.spp-multi-all input').uncheck();
  for(const value of values)await panel.locator('.spp-multi-option input').evaluateAll((items,v)=>{const input=items.find(i=>i.value===v);if(!input)throw new Error('Missing choice '+v);input.click();},value);
  await panel.getByRole('button',{name:'Terapkan',exact:true}).click();
}
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/login.php');await page.locator('#username').fill('superadmin');await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  let controls=0;
  for(const unit of (process.env.SPP_DROPDOWN_AUDIT_UNITS||'1,2,3,0').split(',').map(Number)){
    await page.setViewportSize({width:1440,height:1000});await page.goto(base+'/dashboard.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
    assert.equal(await page.locator('#sidebar-unit-select[data-native-select]').count(),1);
    for(const url of (process.env.SPP_DROPDOWN_SKIP_INVENTORY==='1'?[]:[...pages.filter(p=>!p.includes('aktivasi_legacy')&&(unit!==0||!['master_kelas.php','master_spp.php','master_daftar_ulang.php','master_biaya_lain.php','pembayaran/form.php','backup_restore.php'].includes(p))),...reports.map(t=>'laporan/template.php?template='+t+'&unit=active')])){
      await page.goto(base+'/'+url);await page.waitForLoadState('networkidle');console.log('Inspect unit '+unit+' '+url);
      for(const trigger of await page.locator('.spp-select-trigger,[data-class-picker-button],.payment-year-select,.du-selector-trigger').all()){
        if(!await trigger.isVisible()||await trigger.isDisabled())continue;
        await checkPanel(page,trigger,1440,1000);controls++;
      }
      assert.equal(await page.locator('.spp-dropdown-panel input[type=search]').count(),0,url);
    }
    for(const [width,theme] of [[1440,'light'],[1440,'dark'],[768,'light'],[768,'dark'],[390,'light'],[390,'dark']]){
      await page.setViewportSize({width,height:950});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
      for(const url of layouts.filter(p=>unit!==0||p!=='master_kelas.php')){
        await page.goto(base+'/'+url);await page.waitForLoadState('networkidle');
        for(const selector of ['.student-filter-bar','.du-history-filter','.master-class-filter-bar','.savings-search-panel']){
          const form=page.locator(selector);if(!await form.count())continue;
          const triggers=form.locator('.spp-select-trigger');const rects=await triggers.evaluateAll(items=>items.map(i=>({height:i.getBoundingClientRect().height,width:i.getBoundingClientRect().width,lines:getComputedStyle(i.querySelector('.spp-select-caption')).whiteSpace,text:i.textContent})));
          assert.ok(rects.every(r=>Math.abs(r.height-48)<=1&&r.width>0&&r.lines==='nowrap'),'Consistent control heights '+url+JSON.stringify(rects));
          assert.ok(!rects.some(r=>/tahun_ajaran|Semua filter/.test(r.text)),'Human captions');
          const rect=await form.boundingBox();assert.ok(rect.x>=0&&rect.x+rect.width<=width+1,'Filter layout outside viewport '+url);
          for(const trigger of await triggers.all())if(await trigger.isVisible()&&!await trigger.isDisabled())await checkPanel(page,trigger,width,950);
          await form.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(out,'audit-'+unit+'-'+url.replace(/\W/g,'-')+'-'+width+'-'+theme+'.png')});
        }
        const legacy=page.locator('[data-class-picker-button]');if(await legacy.count()&&await legacy.isVisible())await checkPanel(page,legacy,width,950);
      }
    }
    await page.setViewportSize({width:1440,height:1000});
    // Savings results are compared with an independent database query, across all client pages.
    const all=source[unit],classes=[...new Set(all.map(r=>r.KELAS))].slice(0,2);
    const initial=new URL(base+'/tabungan/riwayat.php');initial.searchParams.set('saldo_kelas[0]',classes[0]);initial.searchParams.set('saldo_status[0]','positive');
    await page.goto(initial.href);await page.waitForLoadState('networkidle');
    for(const statuses of [['positive'],['zero'],['positive','zero']]){
      await choose(page,page.locator('#savings-class-filter'),classes);await choose(page,page.locator('#savings-status-filter'),statuses);
      const expected=all.filter(r=>classes.includes(r.KELAS)&&statuses.includes(Number(r.saldo)>0?'positive':'zero')).map(r=>String(r.id)).sort();
      assert.match(await page.locator('#savings-result-count').innerText(),new RegExp('Ditemukan '+expected.length.toLocaleString('id-ID')+' siswa'));
      const actual=[];for(let n=1;n<=Math.max(1,Math.ceil(expected.length/10));n++){
        actual.push(...await page.locator('#savings-recap-table tr[data-student-id]:visible').evaluateAll(rows=>rows.map(r=>r.dataset.studentId)));
        if(n<Math.ceil(expected.length/10))await page.locator('#savings-recap-pagination .du-page-next').click();
      }
      assert.deepEqual(actual.sort(),expected,'Savings identity union');assert.equal(new Set(actual).size,actual.length);
      assert.ok(![...new URL(page.url()).searchParams.keys()].some(k=>/^saldo_(kelas|status)\[\d+\]$/.test(k)),'Stale indexed parameters');
      await page.reload();assert.match(await page.locator('#savings-result-count').innerText(),new RegExp('Ditemukan '+expected.length.toLocaleString('id-ID')+' siswa'),'Reload preserves choices');
    }
    await page.locator('#savings-reset-filter').click();assert.match(await page.locator('#savings-result-count').innerText(),new RegExp('Ditemukan '+all.length.toLocaleString('id-ID')+' siswa'));
    console.log('PASS audit unit '+unit+' all dropdowns, six responsive/theme layouts and exact savings results');
  }
  assert.deepEqual(errors,[]);console.log(controls?'PASS '+controls+' dropdowns audited without internal search':'PASS resumed layout/source checks');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
