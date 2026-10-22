/* Actual browser navigation and responsive checks on the explicitly owned clone. */
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const root=process.env.SPP_GLOBAL_QA_DIR,f=JSON.parse(fs.readFileSync(path.join(root,'fixture.json')));
assert.equal(f.base,'http://127.0.0.1:58471');assert.equal(f.port,58470);
const checks=[],errors=[],check=(ok,label)=>{assert(ok,label);checks.push(label);};
const period='tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22';
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1050}});
  await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  const identity=await context.request.get(f.base+'/tests/browser_clone_identity.php');assert.equal((await identity.json()).database,f.database);
  await context.route('**/*',r=>new URL(r.request().url()).origin===f.base?r.continue():r.abort());
  const page=await context.newPage();page.setDefaultTimeout(30000);page.on('pageerror',e=>errors.push(e.message));
  for(const unit of [1,2,3,0]){
   await context.addCookies([{name:'PHPSESSID',value:f.sessions[unit],url:f.base}]);
   await page.goto(f.base+'/laporan/index.php?'+period+'&jenis_laporan[]=*&panel=komponen&urut=sisa_terbesar');
   check(await page.locator('.report-transactions-table').count()===1&&await page.locator('[data-report-section]').count()===1,'Old URL renders one payment table');
   check(![...new URL(page.url()).searchParams.keys()].some(key=>key.startsWith('jenis_laporan'))&&new URL(page.url()).searchParams.get('panel')==='komponen','Scope navigation retains presentation, removes obsolete report types');
   check(await page.locator('.finance-stat').count()===4,'Four summary cards in every unit');
   for(const width of [1440,1100,1099,768,700,699,390])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:1050});await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t;},theme);
    const layout=await page.evaluate(()=>{
     const form=document.querySelector('.report-general-filter'),controls=[...form.children].filter(e=>e.classList.contains('field-row')&&!e.classList.contains('full-span'));
     const card=document.querySelector('.finance-catalog'),style=getComputedStyle(card),probe=document.createElement('span');probe.style.color='var(--accent)';card.append(probe);const accent=getComputedStyle(probe).color;probe.remove();
     return {columns:getComputedStyle(form).gridTemplateColumns.split(' ').length,controls:controls.length,rows:controls.map(e=>Math.round(e.getBoundingClientRect().top)),overflow:document.documentElement.scrollWidth>innerWidth+1,border:style.borderTopColor,accent,resultColumns:getComputedStyle(document.querySelector('.finance-results')).gridTemplateColumns.split(' ').length};
    });
    check(layout.columns===(width>=1100?4:width>=700?2:1),'Four/two/one filter columns at exact viewport boundary');
    check(layout.controls===4&&!layout.overflow&&layout.resultColumns===1,'No blank result column or page overflow');
    check(layout.border===layout.accent,'Global card border follows unit accent in both themes');
    if([1440,768,390].includes(width))await page.screenshot({path:path.join(root,'artifacts',`transaction-${unit}-${width}-${theme}.png`),fullPage:true});
   }
  }
  await context.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);await page.setViewportSize({width:1440,height:1050});
  await page.goto(f.base+'/laporan/index.php?'+period+'&per_page=10&include_undated=1');
  const next=()=>page.locator('[data-report-section=semua] .du-pagination a').filter({hasText:'Berikutnya'});
  await next().click();await page.waitForFunction(()=>document.querySelector('[data-report-section=semua]').dataset.page==='2');
  check(new URL(page.url()).searchParams.get('include_undated')==='1','Pagination preserves undated checkbox');
  await page.goBack();await page.waitForFunction(()=>document.querySelector('[data-report-section=semua]').dataset.page==='1');
  await page.goForward();await page.waitForFunction(()=>document.querySelector('[data-report-section=semua]').dataset.page==='2');
  check(await page.locator('.report-transactions-table').count()===1,'Back/Forward preserve one table and requested page');
  await page.goto(f.base+'/laporan/index.php?'+period+'&student_id='+f.native[1].student_id);
  await page.locator('.row-print-check:not(:disabled)').first().check();
  check(await page.locator('#btn-print-selected').isEnabled(),'Existing native receipt selection stays enabled');
  check(await page.locator('#print-selected-form [name=mode]').inputValue()==='selected','Selected print form keeps receipt mode');
  await page.getByRole('link',{name:'Reset',exact:true}).click();
  check(!await page.locator('[type=checkbox][name=include_undated]').isChecked()&&!await page.locator('[type=checkbox][name=all_periods]').isChecked(),'Reset clears optional period flags');

  // Nested anchors override stale promotion query parameters.
  await page.goto(f.base+'/master_kelas.php');
  const sourceYear=await page.locator('#promotion-source-year option').evaluateAll(options=>options.find(o=>o.textContent.trim()==='2026/2027')?.value);
  assert(sourceYear);const stale='source_year_id='+sourceYear+'&source_level=6';
  for(const anchor of ['special-classes','special-class-form','legacy-groups']){
   await page.goto(f.base+'/master_kelas.php?'+stale+'#'+anchor);
   check(await page.locator('[data-class-tab=manage]').getAttribute('aria-selected')==='true','Nested manage anchor overrides promotion parameters');
   check(await page.locator('#special-classes').isVisible()&&await page.locator('#legacy-groups').isVisible(),'Both special and Legacy blocks belong to manage');
  }
  await page.locator('[data-class-tab=promotion]').click();
  check(!await page.locator('#special-classes').isVisible()&&!await page.locator('#legacy-groups').isVisible(),'Special and Legacy blocks hidden during promotion');
  check(!await page.locator('.class-create-actions').isVisible(),'Special create shortcut is hidden during promotion');
  check(!await page.getByText(/Kelulusan memindahkan siswa ke Alumni\d{4}/).count(),'Redundant graduation explanation removed');
  await page.reload();check(!await page.locator('#special-classes').isVisible(),'Refresh retains promotion tab');
  await page.locator('[data-class-tab=promotion]').focus();await page.keyboard.press('ArrowLeft');
  check(await page.locator('#special-classes').isVisible()&&await page.locator('[data-class-tab=manage]').evaluate(el=>el===document.activeElement),'Keyboard activates and focuses manage tab');
  await page.keyboard.press('End');check(!await page.locator('#special-classes').isVisible(),'End activates promotion');
  await page.keyboard.press('Home');check(await page.locator('#special-classes').isVisible(),'Home activates manage');
  await page.goto(f.base+'/master_kelas.php?'+stale+'&special_per_page=10#special-classes');
  await Promise.all([page.waitForNavigation(),page.locator('.sc-page-links a[rel=next]').click()]);
  check(await page.locator('[data-class-tab=manage]').getAttribute('aria-selected')==='true','Special pagination reopens manage');
  await page.locator('.sc-search input[name=special_q]').fill('Alumni');
  await Promise.all([page.waitForNavigation(),page.locator('.sc-search button.btn-primary').click()]);
  check(await page.locator('#special-classes').isVisible()&&await page.locator('.sc-page-links [aria-current=page]').textContent()==='1','Special search resets page and retains manage');
  // Member modal links still work from the moved panel.
  await page.locator('[data-sc-members]').first().click();
  await page.waitForFunction(()=>!!document.querySelector('#special-class-members'));
  check(await page.locator('#special-class-members').isVisible(),'Special members remain reachable');
  await page.screenshot({path:path.join(root,'artifacts','master-members.png')});
  await page.goto(f.base+'/master_kelas.php?'+stale+'#naik-kelas');
  await page.screenshot({path:path.join(root,'artifacts','master-promotion.png'),fullPage:true});
  await page.goto(f.base+'/master_kelas.php?'+stale+'#kelola-rombel');
  await page.screenshot({path:path.join(root,'artifacts','master-manage.png'),fullPage:true});

  await context.close();
  const nojs=await browser.newContext({javaScriptEnabled:false,reducedMotion:'reduce',viewport:{width:1440,height:1050}});
  await nojs.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);const fallback=await nojs.newPage();
  await fallback.goto(f.base+'/laporan/index.php?'+period+'&jenis_laporan[]=*&panel=transaksi&per_page=10');
  await fallback.bringToFront();
  await fallback.screenshot({path:path.join(root,'artifacts','nojs-before-pagination.png'),fullPage:true});
  await Promise.all([fallback.waitForNavigation(),fallback.locator('[data-report-section=semua] .du-pagination a').filter({hasText:'Berikutnya'}).click()]);
  check(await fallback.locator('[data-report-section=semua]').getAttribute('data-page')==='2'&&await fallback.locator('.report-transactions-table').count()===1,'Without JavaScript old URLs and regular pagination stay payment-only');
  await nojs.close();check(!errors.length,'No browser script errors');
 }finally{await browser.close();fs.writeFileSync(path.join(root,'browser-result-'+(process.env.SPP_BROWSER_NAME||'chrome')+'.json'),JSON.stringify({checks:checks.length,errors},null,2));}
 console.log('OK: '+checks.length+' browser checks');
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
