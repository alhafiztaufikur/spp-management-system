const fs = require('fs'), path = require('path'), assert = require('assert');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_MODULE || 'playwright-core');
(async () => {
  const file = process.argv[2]; assert(file && file.includes('legacy_ui_qa_'));
  const c = JSON.parse(fs.readFileSync(file, 'utf8')), root = path.dirname(file);
  assert((await fetch(c.base + '/tests/browser_clone_identity.php').then(r => r.json())).isolated_test === true);
  const results = [], errors = [];
  const pages = [
    ['landing','backup_restore.php?tab=legacy',false],['students','legacy_import.php?mode=students',false],
    ['student-result','legacy_import.php?id='+c.students+'&step=result',false],['finance-review','legacy_finance.php?tab=reviews&review='+c.review,false],
    ['archive','legacy_finance.php?tab=archive',false],['full-review','legacy_full.php?id='+c.job+'&step=review',true],
    ['full-exceptions','legacy_full.php?id='+c.job+'&step=exceptions&section=students',true],
    ['full-savings','legacy_full.php?id='+c.job+'&step=exceptions&section=savings&student=9710000001',true]
  ];
  for (const [name, executablePath] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'],['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']]) {
    const browser = await chromium.launch({headless:true,executablePath});
    try {
      if (name === 'chromium109') assert(browser.version().startsWith('109.'));
      const normal = await browser.newContext(), full = await browser.newContext();
      await normal.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);await full.addCookies([{name:'PHPSESSID',value:c.full_session,url:c.base}]);
      const normalPage=await normal.newPage(),fullPage=await full.newPage();
      for (const page of [normalPage,fullPage]) page.on('pageerror', e => errors.push(name+': '+e.message));
      if (!process.argv.includes('--interaction-only')) for (const theme of ['light','dark']) for (const width of [360,390,768,1024,1366,1920]) {
        for (const [label,url,isFull] of pages) {
          const page=isFull?fullPage:normalPage;await page.setViewportSize({width,height:850});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
          const response=await page.goto(c.base+'/'+url);assert(response.status()===200, label+' '+response.status());
          const g=await page.evaluate(()=>({w:innerWidth,doc:document.documentElement.scrollWidth,buttons:[...document.querySelectorAll('.legacy-ui .dbt-button')].filter(el=>el.getClientRects().length&&!el.closest('details:not([open])')).map(el=>({h:el.getBoundingClientRect().height,text:el.textContent.trim()})),summary:document.querySelector('.legacy-summary')?.getBoundingClientRect().top,main:document.querySelector('.legacy-main')?.getBoundingClientRect().top}));
          assert(g.doc<=width+2,label+' overflow '+JSON.stringify(g));g.buttons.forEach(b=>assert(b.h>=43.5,label+' short '+JSON.stringify(b)));if(width<=768&&g.summary!==undefined)assert(g.summary<=g.main,label+' mobile summary order');
          if(name==='chromium109'&&[360,1366].includes(width)&&['landing','full-exceptions','finance-review'].includes(label))await page.screenshot({path:path.join(root,`ui-${label}-${theme}-${width}.png`),fullPage:false});
          results.push({browser:name,version:browser.version(),theme,width,page:label,status:'passed'});
        }
      }
      // Native GET group changes replace the list and clear temporary selections.
      await fullPage.goto(c.base+'/legacy_full.php?id='+c.job+'&step=exceptions&section=students');
      await fullPage.getByText('Pilih sasaran dalam kelompok',{exact:true}).click();
      const members=fullPage.locator('[name="selected_identities[]"]');await members.selectOption('9710000001');
      fullPage.once('dialog',d=>d.accept());await fullPage.locator('[data-legacy-group-context] select').selectOption('ALUMNI');await fullPage.waitForURL(/group_class=ALUMNI/);
      assert.deepStrictEqual(await fullPage.locator('[name="selected_identities[]"] option').evaluateAll(els=>els.map(el=>el.value)),['9710000003']);assert.deepStrictEqual(await fullPage.locator('[name="selected_identities[]"]').evaluate(el=>Array.from(el.selectedOptions,el=>el.value)),[]);
      // Native no-JS preview and return-to-edit preserve inputs without approving.
      const plain=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:850}});await plain.addCookies([{name:'PHPSESSID',value:c.full_session,url:c.base}]);const p=await plain.newPage();
      await p.goto(c.base+'/legacy_full.php?id='+c.job+'&step=exceptions&section=obligations&group_class=ALUMNI');await p.locator('[name=group_evidence]').fill('Official group evidence retained after going back.');await p.locator('[name=obligations_confirmed]').evaluate(el=>el.scrollIntoView({behavior:'instant',block:'center'}));await p.locator('[name=obligations_confirmed]').check();await p.getByRole('button',{name:'Periksa Pratinjau',exact:true}).click();await p.locator('#group-preview').waitFor();assert(await p.locator('#group-preview tbody tr').count()===1);assert(!await p.locator('#group-preview pre').count());await p.getByRole('button',{name:'Kembali Mengubah',exact:true}).click();assert(await p.locator('[name=group_evidence]').inputValue()==='Official group evidence retained after going back.');
      await p.goto(c.base+'/legacy_full.php?id='+c.job+'&step=confirm');await p.getByRole('button',{name:'Periksa & Konfirmasi Migrasi',exact:true}).click();await p.locator('#full-confirm').waitFor();assert(await p.locator('#full-confirm').isVisible());await plain.close();
      await fullPage.goto(c.base+'/legacy_full.php?id='+c.job+'&step=confirm');await fullPage.getByRole('button',{name:'Periksa & Konfirmasi Migrasi',exact:true}).click();await fullPage.locator('.lful-modal').waitFor();assert(await fullPage.locator('.lful-modal').evaluate(el=>el.contains(document.activeElement)));await fullPage.keyboard.press('Escape');await fullPage.waitForURL(/step=confirm/);assert(!await fullPage.locator('.lful-modal').count());
      for(const [unit,palette] of [[0,'super'],[1,'sd'],[2,'smp'],[3,'sma']]){await normalPage.goto(c.base+'/legacy_import.php');const csrf=await normalPage.locator('.sidebar-unit-form input[name=csrf_token]').inputValue();await normal.request.post(c.base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:csrf,next:'/legacy_import.php'}});await normalPage.goto(c.base+'/legacy_import.php');assert(await normalPage.locator('html').getAttribute('data-palette')===palette);results.push({browser:name,version:browser.version(),unit,palette,status:'passed'});}
      // Restore the shared QA session for the next browser.
      const csrf=await normalPage.locator('.sidebar-unit-form input[name=csrf_token]').inputValue();await normal.request.post(c.base+'/unit_switch.php',{form:{unit_id:'1',csrf_token:csrf,next:'/legacy_import.php'}});
      await normal.close();await full.close();
    } finally {await browser.close();}
  }
  assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(root,'ui-browser-results.json'),JSON.stringify(results,null,2));console.log('PASS: '+results.length+' page/viewport/theme/palette cases; Chrome, Edge, actual Chromium 109; group changes, no-JS preview/back/final confirmation, focus and Escape.');
})().catch(e=>{console.error(e);process.exit(1);});
