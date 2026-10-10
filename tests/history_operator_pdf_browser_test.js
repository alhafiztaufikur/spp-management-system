const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const dir=process.env.SPP_QA_DIR,base=process.env.SPP_HTTP_BASE;
const fixture=JSON.parse(fs.readFileSync(dir+'/operator-fixture.json','utf8'));
assert.match(fixture.database,/^db_spp_audit_/);assert.ok(['localhost','127.0.0.1'].includes(new URL(base).hostname));
(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});let states=0;
  try{
    const guard=await browser.newContext();assert.equal((await (await guard.request.get(base+'/tests/browser_clone_identity.php')).json()).database,fixture.database);await guard.close();
    for(const scope of process.env.SPP_QA_INTERACTIONS_ONLY==='1'?[]:[1,2,3,0]){
      const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.cookies['super'+scope],url:base}]);
      const page=await context.newPage(),errors=[];await page.goto(base+'/otorisasi_transaksi.php?view=history');if(await page.locator('#sidebar-unit-select').inputValue()!==String(scope))await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(scope))]);page.on('pageerror',error=>errors.push(error.message));
      for(const theme of ['light','dark'])for(const width of [1600,900,390]){
        await page.setViewportSize({width,height:1050});await page.addInitScript(value=>localStorage.setItem('spp_theme',value),theme);
        for(const [name,path] of [['payment','/pembayaran/lihat.php?view=active'],['registration','/pembayaran/riwayat_daftar_ulang.php'],['authorization','/otorisasi_transaksi.php?view=history']]){
          assert.equal((await page.goto(base+path)).status(),200);await page.waitForLoadState('domcontentloaded');
          assert.doesNotMatch(await page.content(),/Fatal error|Warning:/);assert.equal(await page.locator('html').getAttribute('data-theme'),theme);assert.equal(await page.locator('html').getAttribute('data-palette'),({0:'super',1:'sd',2:'smp',3:'sma'})[scope]);
          assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,`${name}/${scope}/${theme}/${width} overflow`);
          if(name!=='authorization'){
            const trigger=page.locator('.history-operator-field .spp-select-trigger');await trigger.click();
            const panel=page.locator('.spp-multi-panel:visible');await panel.locator('.spp-multi-search').waitFor();
            assert.equal(await panel.evaluate(element=>{const r=element.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1;}),true,'Operator panel stays in viewport');
            await panel.locator('.spp-multi-search').fill('no-such-operator');assert.equal(await panel.locator('.spp-multi-option:visible').count(),0);assert.equal(await panel.locator('[data-search-empty]').isVisible(),true);
            await page.keyboard.press('Escape');assert.equal(await trigger.evaluate(element=>element===document.activeElement),true,'Escape restores trigger focus');
          }
          if(width!==900)await page.screenshot({path:`${dir}/${name}-${scope}-${theme}-${width}.png`,fullPage:width!==390,animations:'disabled'});states++;
        }
      }
      assert.deepEqual(errors,[]);await context.close();
    }

    const context=await browser.newContext({viewport:{width:1600,height:1050}});await context.addCookies([{name:'PHPSESSID',value:fixture.cookies.super1,url:base}]);
    const page=await context.newPage(),nis=fixture.bill.no_induk;await page.goto(base+'/otorisasi_transaksi.php?view=history');if(await page.locator('#sidebar-unit-select').inputValue()!=='1')await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('1')]);
    for(const [path,button] of [['/pembayaran/lihat.php?search='+encodeURIComponent(nis)+'&tanggal_awal=2026-10-10&tanggal_akhir=2026-10-10','#btn-filter'],['/pembayaran/riwayat_daftar_ulang.php?student_id='+fixture.bill.student_id,'.du-filter-form button[type=submit]']]){
      await page.goto(base+path);await page.locator('.history-operator-field .spp-select-trigger').click();const panel=page.locator('.spp-multi-panel:visible');
      await panel.locator('.spp-multi-all input').uncheck();await panel.locator('.spp-multi-search').fill('Operator QA Tidak Aktif');const beforeSearch=page.url();await panel.locator('.spp-multi-search').press('Enter');assert.equal(page.url(),beforeSearch,'Search Enter does not submit the history filter');
      await panel.locator('.spp-multi-option input[value="'+fixture.actorB+'"]').check();await panel.locator('.spp-multi-apply').click();
      assert.equal(new URL(page.url()).searchParams.has('operator[]'),false,'Dropdown changes wait for Tampilkan Rekap');
      await Promise.all([page.waitForNavigation(),page.locator(button).click()]);assert.equal(new URL(page.url()).searchParams.get('operator[]'),String(fixture.actorB));
      if(path.includes('riwayat_daftar_ulang')){
        assert.equal(await page.locator('[data-du-transaction="'+fixture.ids[1]+'"]').count(),1);
        assert.equal(await page.locator('[data-du-transaction="'+fixture.ids[0]+'"]').count(),0);
        assert.match(await page.locator('.du-operator-summary').first().textContent(),/Rp 700/);
        const deleted=page.getByRole('link',{name:'Riwayat Dihapus',exact:true});await deleted.click();assert.equal(new URL(page.url()).searchParams.get('operator[0]'),String(fixture.actorB));
        assert.equal(await page.locator('[data-du-transaction="'+fixture.archive[1]+'"]').count(),1);
      }else{
        assert.equal(await page.locator('[data-payment-record]').count(),1);
        const edit=page.locator('[data-payment-detail] a.btn-tbl-edit');await edit.click();
        const back=page.locator('a[href^="lihat.php?"]').first();assert.ok(await back.count(),'Edit returns to filtered payment history');
        assert.ok((await back.getAttribute('href')).includes('operator%5B0%5D='));await back.click();
      }
    }

    await page.goto(base+'/otorisasi_transaksi.php?view=history&q='+encodeURIComponent(nis));
    const first=page.locator('[data-export-choice]').first(),detailBefore=await page.locator('[data-auth-record][aria-current=true]').getAttribute('data-id');
    await first.check();assert.equal(await page.locator('[data-auth-record][aria-current=true]').getAttribute('data-id'),detailBefore,'Checkbox does not open detail');
    await Promise.all([page.waitForNavigation(),page.locator('.workflow-pages a').filter({hasText:'Berikutnya'}).click()]);assert.equal(await page.locator('[data-export-count]').textContent(),'1 transaksi dipilih');
    await page.locator('[data-export-choice]').last().check();await page.reload();assert.equal(await page.locator('[data-export-count]').textContent(),'2 transaksi dipilih');
    await page.locator('[data-export-choice]').last().uncheck();assert.equal(await page.locator('[data-export-count]').textContent(),'1 transaksi dipilih');await page.locator('[data-export-choice]').last().check();
    await Promise.all([page.waitForNavigation(),page.locator('.workflow-pages a').filter({hasText:'Sebelumnya'}).click()]);await page.waitForFunction(()=>document.querySelector('[data-export-count]')?.textContent==='2 transaksi dipilih');assert.equal(await page.locator('[data-export-choice]').first().isChecked(),true,'First-page choice retained');
    const selected=JSON.parse(await page.locator('[name=transactions]').inputValue());assert.equal(selected.length,2);
    await Promise.all([page.waitForNavigation(),page.locator('[data-export-selected]').click()]);assert.match(page.url(),/selection_token=/);
    assert.match(await page.locator('.preview-heading p').textContent(),/2 transaksi terpilih/);
    const preview=page.frameLocator('[data-preview-frame]');assert.equal(await preview.locator('table').first().locator('tbody tr').count(),2);
    const download=await page.request.get(page.url()+'&output=pdf');assert.equal(download.status(),200);assert.ok((await download.body()).subarray(0,5).equals(Buffer.from('%PDF-')));
    await page.goto(base+'/otorisasi_transaksi.php?view=history&q='+encodeURIComponent(nis));assert.equal(await page.locator('[data-export-count]').textContent(),'2 transaksi dipilih');await page.goBack();await page.goForward();await page.waitForFunction(()=>document.querySelector('[data-export-count]')?.textContent==='2 transaksi dipilih');
    await page.locator('[data-export-clear]').click();assert.equal(await page.locator('[data-export-selected]').isDisabled(),true);
    await page.locator('[data-export-all-page]').check();assert.equal(await page.locator('[data-export-choice]:checked').count(),25);assert.equal(await page.locator('[data-export-count]').textContent(),'25 transaksi dipilih');
    await page.goto(base+'/otorisasi_transaksi.php?view=history&q='+encodeURIComponent(nis)+'&kind=hapus');assert.equal(await page.locator('[data-export-count]').textContent(),'0 transaksi dipilih','Changed filter clears choices');
    await page.locator('[data-export-choice]').first().check();await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('0')]);await page.goto(base+'/otorisasi_transaksi.php?view=history');assert.equal(await page.locator('[data-export-count]').textContent(),'0 transaksi dipilih','Changed scope clears choices');
    await page.locator('[data-export-choice]').first().check();await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption('1')]);assert.equal(await page.evaluate(()=>sessionStorage.getItem('spp.authorization.pdf.selection')),null,'Unit switch clears choices immediately, including queue view');
    await page.goto(base+'/otorisasi_transaksi.php?view=history');await page.locator('[data-export-choice]').first().check();await Promise.all([page.waitForNavigation(),page.locator('[data-unit-choice="0"]').click()]);assert.equal(await page.evaluate(()=>sessionStorage.getItem('spp.authorization.pdf.selection')),null,'Visible unit buttons also clear choices');await page.goto(base+'/otorisasi_transaksi.php?view=history');assert.equal(await page.locator('[data-export-count]').textContent(),'0 transaksi dipilih','Returning to original scope does not revive old choices');
    await page.goto(base+'/laporan/index.php');assert.match(await page.title(),/Laporan Transaksi/);assert.equal(await page.locator('.topbar-title h2').textContent(),'Laporan Transaksi');
    await context.close();
    const noJs=await browser.newContext({javaScriptEnabled:false});await noJs.addCookies([{name:'PHPSESSID',value:fixture.cookies.kasir,url:base}]);
    const native=await noJs.newPage();await native.goto(base+'/pembayaran/lihat.php');assert.equal(await native.locator('select[name="operator[]"][multiple]').count(),1);await noJs.close();
    console.log(`OK: ${states} visual states, searchable operator checkboxes, deferred filters, filtered DU/edit return, PDF choices across pages/refresh, preview/download, clear/all-page, filter/unit reset and no-JS dropdown.`);
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
