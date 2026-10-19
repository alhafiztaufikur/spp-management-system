/* Run only against a guarded isolated HTTP fixture; artifacts stay outside the repository. */
const fs = require('fs'), path = require('path'), assert = require('assert');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_MODULE || 'playwright-core');
(async () => {
  const configFile = process.argv[2];
  assert(configFile && /legacy_finance_qa_/.test(configFile));
  const config = JSON.parse(fs.readFileSync(configFile, 'utf8'));
  const root = path.dirname(configFile), proof = await fetch(config.base + '/tests/browser_clone_identity.php').then(r => r.json());
  assert(proof.isolated_test === true, 'Refuse operational HTTP server');
  const browsers = [
    ['chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe'],
    ['edge', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'],
    ['chromium109', path.join(root, 'browser109/runtime/chrome-win/chrome.exe')]
  ];
  const report = []; const failures = [];
  for (const [name, executablePath] of browsers) {
    assert(fs.existsSync(executablePath), 'Browser unavailable: ' + name);
    const browser = await chromium.launch({executablePath, headless: true});
    const version = browser.version(); if (name === 'chromium109') assert(version.startsWith('109.'), version);
    const context = await browser.newContext({viewport:{width:1366,height:900}});
    await context.addCookies([{name:'PHPSESSID',value:config.session,url:config.base}]);
    const page = await context.newPage();page.on('pageerror', e => failures.push(name + ': ' + e.message));
    if(name==='chrome' && process.env.SPP_FINANCE_UPLOAD){
      await page.goto(config.base+'/backup_restore.php#panel-legacy');
      assert(await page.locator('#panel-legacy').isVisible(),'Return link did not select Import Legacy');
      await page.locator('[data-category=finance]').click();await page.locator('#legacy-unit').selectOption('SD');
      await page.locator('#legacy-file').setInputFiles(process.env.SPP_FINANCE_UPLOAD);
      await page.waitForFunction(()=>!document.getElementById('legacy-start').disabled);
      await page.locator('#legacy-start').click();
      await page.waitForFunction(()=>document.getElementById('legacy-progress-status').textContent.includes('Siap Ditinjau'),{},{timeout:180000});
      assert((await page.locator('#legacy-counts').textContent()).includes('archive_issues'),'Finance source issues absent');
      await page.locator('#legacy-confirmation').fill('IMPOR LEGACY');await page.locator('#legacy-confirm').click();
      await page.waitForFunction(()=>document.getElementById('legacy-progress-status').textContent.includes('Arsip Tersimpan'),{},{timeout:180000});
      await page.locator('#legacy-close').click();
      console.log('PASS: actual finance upload, source preview/issues, worker confirmation and idempotent archive publication through UI.');
    }
    for (const theme of ['light','dark']) for (const width of [360,390,768,1024,1366,1920]) {
      await page.setViewportSize({width,height:850});
      await page.addInitScript(t => localStorage.setItem('spp_theme', t), theme);
      const response=await page.goto(config.base + '/legacy_finance.php?review=' + config.review + '&source=' + config.source);
      assert(response.status()===200);await page.waitForTimeout(150);
      const geometry=await page.evaluate(() => ({document:document.documentElement.scrollWidth,viewport:innerWidth,form:[...document.querySelectorAll('.lf-form input:not([type=hidden]),.lf-form select,.lf-form textarea')].filter(el=>el.getBoundingClientRect().width).map(el=>({left:el.getBoundingClientRect().left,right:el.getBoundingClientRect().right}))}));
      assert(geometry.document<=geometry.viewport+2, name+' horizontal page overflow '+width);
      for(const field of geometry.form)assert(field.left>=-2&&field.right<=width+2, name+' field overflow '+width);
      await page.locator('select[name="debts[0][kind]"]').selectOption('spp');
      assert(await page.locator('select[name="debts[0][kind]"]').inputValue()==='spp');
      await page.locator('select[name="debts[0][kind]"]').selectOption('other');
      if([360,1366].includes(width))await page.screenshot({path:path.join(root,`${name}-${theme}-${width}.png`),fullPage:false});
      report.push({browser:name,version,theme,width,status:'passed'});
    }
    await page.setViewportSize({width:390,height:760});
    for(const theme of ['light','dark'])for(const [unit,palette] of [[0,'super'],[2,'smp'],[3,'sma'],[1,'sd']]){
      await page.goto(config.base+'/legacy_finance.php?source='+config.source);
      const token=await page.locator('.sidebar-unit-form input[name=csrf_token]').inputValue();
      await context.request.post(config.base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:token,next:'/legacy_finance.php'}});
      await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
      await page.goto(config.base+'/legacy_finance.php?source='+config.source);
      assert(await page.locator('html').getAttribute('data-palette')===palette,'Unit palette mismatch');
      report.push({browser:name,version,theme,width:390,unit,palette,status:'passed'});
    }
    await page.goto(config.base + '/legacy_finance.php?review=' + config.review + '&source=' + config.source);
    await page.getByLabel('Saya mengesahkan nominal', {exact:false}).check();
    await page.getByRole('button',{name:'Periksa & Konfirmasi'}).click();
    await page.locator('.lf-confirm-dialog').waitFor({state:'visible'});
    assert(await page.locator('.lf-confirm-dialog').evaluate(el=>el.contains(document.activeElement)), 'Modal focus');
    await page.keyboard.press('Tab');assert(await page.locator('.lf-confirm-dialog').evaluate(el=>el.contains(document.activeElement)), 'Modal focus trap');
    await page.screenshot({path:path.join(root,name+'-confirmation.png')});
    await page.keyboard.press('Escape');await page.waitForURL(/review=/);assert(await page.locator('.lf-confirm-dialog').count()===0,'Escape did not return to review');
    const nojs = await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:850}});
    await nojs.addCookies([{name:'PHPSESSID',value:config.session,url:config.base}]);const plain=await nojs.newPage();await plain.goto(config.base+'/legacy_finance.php?review='+config.review+'&source='+config.source);await plain.getByLabel('Saya mengesahkan nominal',{exact:false}).check();await plain.getByRole('button',{name:'Periksa & Konfirmasi'}).click();await plain.locator('#lf-confirmation').waitFor({state:'visible'});assert(await plain.locator('input[name=confirmation_token]').count()===1,'No-JS confirmation');
    await nojs.close();await context.close();await browser.close();
  }
  assert.deepStrictEqual(failures, []);
  fs.writeFileSync(path.join(root,'browser-results.json'),JSON.stringify(report,null,2));
  console.log('PASS: '+report.length+' layout cases, real Chrome/Edge/Chromium 109, keyboard/Escape confirmation and forms without JavaScript.');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
