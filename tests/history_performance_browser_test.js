const fs = require('fs');
const assert = require('assert');
const { chromium } = require(process.env.SPP_PLAYWRIGHT_MODULE || 'C:/laragon/backups/spp-management-system/readiness_browser_tools/node_modules/playwright-core');
const root = process.argv[2];
assert(root && root.includes('history_performance_'));
const config = JSON.parse(fs.readFileSync(root + '/http.json', 'utf8'));
const base = 'http://127.0.0.1:58250';
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.SPP_CHROMIUM109 || 'C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe' });
  const results = [], errors = [];
  try {
    assert(browser.version().startsWith('109.'));
    for (const [unit, palette] of [[1, 'sd'], [2, 'smp'], [3, 'sma']]) {
      for (const [theme, width] of [['light', 1366], ['dark', 390]]) {
        const context = await browser.newContext({ viewport: { width, height: 950 } });
        await context.addCookies([{ name: 'PHPSESSID', value: config.sessions[unit][0], url: base }]);
        await context.route('https://**/*', route => route.abort());
        await context.addInitScript(value => localStorage.setItem('spp_theme', value), theme);
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        for (const [route, label] of [['pembayaran/lihat.php', 'payments'], ['pembayaran/riwayat_daftar_ulang.php', 'du'], ['tabungan/riwayat.php', 'savings']]) {
          const response = await page.goto(base + '/' + route, { waitUntil: 'domcontentloaded', timeout: 120000 });
          assert.equal(response.status(), 200);
          const text = await page.locator('body').innerText();
          assert(!/Fatal error|Warning:|Uncaught/.test(text));
          assert.equal(await page.locator('html').getAttribute('data-palette'), palette);
          assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
          const dimensions = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
          assert(dimensions.scroll <= dimensions.width + 2, `${label} overflow`);
          if (label === 'payments') assert.equal(await page.locator('[data-payment-record]').count(), 10);
          await page.screenshot({ path: `${root}/final-${label}-${unit}-${theme}-${width}.png`, animations: 'disabled' });
          results.push({ unit, palette, theme, width, label });
        }
        await context.close();
      }
    }
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 950 } });
    await context.addCookies([{ name: 'PHPSESSID', value: config.sessions[1][0], url: base }]);
    const page = await context.newPage();
    await page.goto(base + '/pembayaran/lihat.php?page=2');
    const card = page.locator('[data-payment-record]').first();
    const id = await card.getAttribute('data-id');
    await card.focus();
    assert(await card.evaluate(element => document.activeElement === element));
    await card.press('Enter');
    await page.waitForLoadState('domcontentloaded');
    assert(new URL(page.url()).searchParams.get('selected') === id);
    results.push({ noJavaScript: true, keyboard: true, selectedPage: true });
    await page.goto(base + '/tabungan/masuk.php?owner_type=account');
    await page.locator('.sw-student-option').first().click();
    assert(await page.locator('form[data-savings-submit] input[name=owner_id]').count());
    assert(await page.locator('form[data-savings-submit] input[name=_operational_unit]').count());
    assert((await page.locator('body').innerText()).includes('Pemilik non-siswa'));
    await page.screenshot({ path: root + '/final-owner-nojs.png', animations: 'disabled' });
    results.push({ noJavaScript: true, nonStudentForm: true });
    await context.close();
    if (process.argv.includes('--save')) {
      const saving = await browser.newContext({ viewport: { width: 390, height: 950 } });
      await saving.addCookies([{ name: 'PHPSESSID', value: config.sessions[1][0], url: base }]);
      const owners = await (await saving.request.get(base + '/tabungan/pemilik.php?format=json&owner_type=account')).json();
      const owner = owners.rows.find(row => row.state === 'ready');
      const balanceUrl = base + '/tabungan/get_saldo.php?owner_id=' + owner.owner_id;
      const before = (await (await saving.request.get(balanceUrl)).json()).raw_saldo;
      const page = await saving.newPage();
      let posts = 0;
      page.on('request', request => { if (request.method() === 'POST' && request.url().endsWith('/tabungan/proses.php')) posts++; });
      await page.goto(base + '/tabungan/masuk.php?owner_id=' + owner.owner_id);
      await page.locator('input[name=nominal]').fill('0,13');
      const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
      const busy = await page.evaluate(() => {
        const form = document.querySelector('form[data-savings-submit]');
        form.requestSubmit(); form.requestSubmit();
        return { busy: form.getAttribute('aria-busy'), disabled: form.querySelector('button').disabled };
      });
      assert.equal(busy.busy, 'true'); assert(busy.disabled);
      await navigation;
      assert.equal(posts, 1);
      assert(new URL(page.url()).searchParams.get('after_save') === '1');
      const after = (await (await saving.request.get(balanceUrl)).json()).raw_saldo;
      assert(Math.abs(after - before - 0.13) < 0.00001);
      assert((await page.locator('body').innerText()).includes('Transaksi berhasil diproses'));
      await page.screenshot({ path: root + '/final-savings-after-save.png', animations: 'disabled' });
      results.push({ javascriptSave: true, doubleSubmitBlocked: true, exactBalance: true });
      await saving.close();
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(root + '/browser.json', JSON.stringify({ browser: browser.version(), results, errors }, null, 2));
    console.log(`PASS ${results.length} Chromium 109 UI cases`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
