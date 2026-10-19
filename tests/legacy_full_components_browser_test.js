const fs = require('fs'), path = require('path'), assert = require('assert');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_MODULE || 'playwright-core');
(async () => {
  const file = process.argv[2]; assert(file && file.includes('legacy_full_qa_'));
  const config = JSON.parse(fs.readFileSync(file, 'utf8')), root = path.dirname(file);
  const sourceOnly = process.argv.includes('--source-only');
  assert((await fetch(config.base + '/tests/browser_clone_identity.php').then(r => r.json())).isolated_test === true);
  const results = [], errors = [];
  const browsers = [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'],['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']];
  for (const [name, executablePath] of browsers) {
    const browser = await chromium.launch({headless:true, executablePath});
    try {
      if (name === 'chromium109') assert(browser.version().startsWith('109.'));
      const context = await browser.newContext();
      await context.addCookies([{name:'PHPSESSID', value:config.session, url:config.base}]);
      const page = await context.newPage(); page.on('pageerror', e => errors.push(String(e)));
      for (const theme of ['light','dark']) for (const width of [360,390,768,1024,1366,1920]) {
        await page.setViewportSize({width,height:850}); await page.addInitScript(t => localStorage.setItem('spp_theme',t),theme);
        const response = await page.goto(config.base + '/legacy_full.php?id=' + config.job+'&step=exceptions&section=components'); assert(response.status() === 200);
        await page.locator('#legacy-components').scrollIntoViewIfNeeded();
        assert(await page.locator('#legacy-components').isVisible());
        if (sourceOnly) assert(await page.locator('.lful-component').count() > 0);
        else assert(await page.locator('.lful-proposal summary').filter({hasText:'Seragam Legacy'}).count() > 0);
        const geometry = await page.evaluate(() => ({width:innerWidth, scroll:document.documentElement.scrollWidth, cards:[...document.querySelectorAll('.lful-component')].map(e => {const r=e.getBoundingClientRect();return [r.left,r.right];})}));
        assert(geometry.scroll <= width+2); geometry.cards.forEach(([left,right]) => assert(left >= -2 && right <= width+2));
        if ([360,1366].includes(width)) await page.screenshot({path:path.join(root,`components-${sourceOnly?'source-':''}${name}-${theme}-${width}.png`)});
        results.push({browser:name,version:browser.version(),theme,width});
      }
      if (!sourceOnly) {
      await page.goto(config.base+'/legacy_full.php?id='+config.job+'&step=confirm');await page.getByRole('button',{name:'Periksa & Konfirmasi Migrasi',exact:true}).click();
      await page.locator('.lful-modal').waitFor(); assert(await page.locator('.lful-modal').evaluate(el=>el.contains(document.activeElement)));
      assert((await page.locator('.lful-modal').innerText()).includes('Seragam Legacy')); await page.keyboard.press('Escape'); assert(!await page.locator('.lful-modal').isVisible());
      }
      const plain = await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:850}}); await plain.addCookies([{name:'PHPSESSID',value:config.session,url:config.base}]);
      const plainPage = await plain.newPage(); await plainPage.goto(config.base+'/legacy_full.php?id='+config.job+'&step=confirm');
      if (!sourceOnly) {await plainPage.getByRole('button',{name:'Periksa & Konfirmasi Migrasi',exact:true}).click(); await plainPage.waitForLoadState('load'); assert(await plainPage.locator('#full-confirm').isVisible());
      assert((await plainPage.locator('#full-confirm').innerText()).includes('Seragam Legacy'));}
      else {await plainPage.goto(config.base+'/legacy_full.php?id='+config.job+'&step=exceptions&section=components');assert(await plainPage.locator('.lful-component form').count() > 0);}
      await plain.close(); await context.close();
    } finally { await browser.close(); }
  }
  assert.deepStrictEqual(errors,[]); fs.writeFileSync(path.join(root,sourceOnly?'components-source-browser-results.json':'components-browser-results.json'),JSON.stringify(results,null,2));
  console.log(sourceOnly?'PASS: '+results.length+' actual long source catalog viewport/theme cases, Chrome/Edge/Chromium 109 and bounded native forms without JavaScript.':'PASS: '+results.length+' component viewport/theme cases, Chrome/Edge/Chromium 109, keyboard/Escape and no-JS confirmation with proposed masters.');
})().catch(e=>{console.error(e);process.exit(1);});
