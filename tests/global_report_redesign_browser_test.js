/* Run against an isolated loopback clone with temporary authenticated QA sessions. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const dir = process.env.SPP_QA_DIR, base = process.env.SPP_HTTP_BASE;
assert.match(process.env.SPP_DB_NAME || '', /^db_spp_audit_/);
assert.match(base || '', /^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const sessions = JSON.parse(fs.readFileSync(dir + '/sessions.json', 'utf8'));
const snapshot = JSON.parse(fs.readFileSync(dir + '/baseline.json', 'utf8'));
const query = params => {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params))
    if (Array.isArray(value)) value.forEach(v => search.append(key + '[]', v));
    else search.set(key, value);
  return search.toString();
};
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const errors = []; let states = 0;
  try {
    const context = await browser.newContext({ viewport: { width: 1600, height: 1000 }, timezoneId: 'Asia/Jakarta' });
    await context.addCookies([{ name: 'PHPSESSID', value: sessions.super_admin, url: base }]);
    assert.equal((await (await context.request.get(base + '/tests/browser_clone_identity.php')).json()).database, process.env.SPP_DB_NAME);
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    for (const unit of ['1', '2', '3', 'all']) {
      const scope = unit === 'all' ? '0' : unit;
      for (const template of ['catalogue', 'status', 'penerimaan', 'spp-tahunan', 'per-item', 'tabungan-siswa', 'saldo-tabungan', 'riwayat-tagihan', 'setoran', 'kas-tabungan']) {
        const params = snapshot.cases[scope + '/' + template];
        const route = template === 'catalogue' ? 'global.php?unit=' + unit : 'template.php?' + query(params);
        const response = await page.goto(base + '/laporan/' + route);
        assert.equal(response.status(), 200, route);
        assert.doesNotMatch(await page.locator('body').innerText(), /Fatal error|Warning:|Gagal memuat laporan/);
        if (template === 'catalogue') assert.equal(await page.locator('.report-template-link').count(), 9);
        else assert.equal(await page.locator('.global-report-hero,.global-filter-group').count(), 0, 'Original header/filter layout restored');
        for (const width of [1600, 900, 390]) for (const theme of ['light', 'dark']) {
          await page.setViewportSize({ width, height: 1000 });
          await page.evaluate(theme => { document.documentElement.dataset.theme = theme; localStorage.setItem('spp_theme', theme); window.scrollTo(0, 0); }, theme);
          await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 2);
          const layout = await page.evaluate(() => ({
            groups: [...document.querySelectorAll('.global-filter-fields')].map(e => ({ columns: getComputedStyle(e).gridTemplateColumns.split(' ').length })),
            fields: [...document.querySelectorAll('.global-filter-fields > .field-row')].filter(e => getComputedStyle(e).display !== 'none').map(e => {
              const label = e.querySelector('.field-label'), control = e.querySelector('.spp-select-trigger,.report-date-range-button,.report-month-range-button,.report-year-range-button,.search-box input[type=text]');
              return { label: label?.textContent, height: control?.getBoundingClientRect().height };
            }),
            actions: [...document.querySelectorAll('.report-filter-actions')].map(e => getComputedStyle(e).position),
            empty: [...document.querySelectorAll('.global-filter-group')].some(e => !e.hidden && ![...e.querySelectorAll('.field-row')].some(f => getComputedStyle(f).display !== 'none')),
          }));
          assert.ok(layout.actions.every(position => position === 'static'), 'Actions must stay in document flow');
          assert.equal(await page.locator('html').getAttribute('data-palette'), { 1: 'sd', 2: 'smp', 3: 'sma', all: 'super' }[unit]);
          if (template === 'status' || template === 'catalogue') await page.screenshot({ path: dir + '/' + template + '-' + unit + '-' + width + '-' + theme + '.png', fullPage: template === 'catalogue', animations: 'disabled' });
          states++;
        }
      }
      console.log('PASS: ' + unit + ', all nine templates/catalogue, three widths and both themes');
    }
    await page.setViewportSize({ width: 1600, height: 1000 });
    await page.goto(base + '/laporan/template.php?' + query(snapshot.cases['1/status']));
    const singleStyle = await page.locator('.report-template-table thead th').first().evaluate(e => ({ bg: getComputedStyle(e).backgroundColor, color: getComputedStyle(e).color, border: getComputedStyle(e).borderRightWidth }));
    // Checkbox drafts apply on submit; every component stays together before pagination.
    const category = page.locator('.spp-select[data-control=kategori]');
    await category.locator('.spp-select-trigger').click();
    await category.locator('.spp-multi-panel input[value=komite]').check();
    await category.locator('.spp-multi-apply').click();
    assert.equal(await page.locator('.report-horizontal-matrix thead th[scope=colgroup]').count(), 1);
    await Promise.all([page.waitForNavigation(), page.locator('.report-filter-actions button[type=submit]').click()]);
    assert.equal(await page.locator('.report-horizontal-matrix').count(), 1);
    assert.equal(await page.locator('.report-horizontal-matrix thead th[scope=colgroup]').count(), 2);
    const multiStyle = await page.locator('.report-template-table thead th').first().evaluate(e => ({ bg: getComputedStyle(e).backgroundColor, color: getComputedStyle(e).color, border: getComputedStyle(e).borderRightWidth }));
    assert.deepEqual(multiStyle, singleStyle);
    assert.equal(await page.locator('.report-horizontal-matrix tbody .badge-nis').count(), 25);
    assert.equal(await page.locator('.report-horizontal-matrix tbody .report-status').count(), 50);
    const container = page.locator('.report-matrix-scroll');
    await container.scrollIntoViewIfNeeded();
    const initial = await page.locator('.report-horizontal-matrix tbody .matrix-nis').first().boundingBox();
    await container.evaluate(e => e.scrollLeft = 700);
    const moved = await page.locator('.report-horizontal-matrix tbody .matrix-nis').first().boundingBox();
    assert.ok(Math.abs(initial.x - moved.x) < 2, 'Identity stays visible on horizontal scroll');
    await page.goto(base + '/laporan/template.php?' + query({ ...snapshot.cases['1/status'], kategori: ['spp', 'komite'], page: '6' }));
    assert.equal(await page.locator('.report-horizontal-matrix tbody tr').count(), 19);
    assert.equal((await page.locator('.matrix-no').allTextContents())[1].trim(), '126');
    assert.equal((await page.locator('.matrix-no').allTextContents()).at(-1).trim(), '144');
    assert.equal(await page.locator('select[name="kategori[]"] option:checked').allTextContents().then(v => v.join(',')), 'SPP,Komite');
    for (const key of ['1/status-multi', '1/status-periods', '1/status-empty', '1/per-item-multi', '1/penerimaan-multi']) {
      await page.goto(base + '/laporan/template.php?' + query(snapshot.cases[key]));
      assert.doesNotMatch(await page.locator('body').innerText(), /Warning:|Fatal error/);
      assert.equal(await page.locator('.report-horizontal-matrix').count(), 1);
    }
    // Existing item-specific period visibility, search, keyboard, clipping and reset.
    for (const item of ['komite', 'daftar_ulang', 'psb']) {
      await page.goto(base + '/laporan/template.php?' + query({ ...snapshot.cases['1/per-item'], kategori: item }));
      const expected = item === 'komite' ? 'month' : item === 'daftar_ulang' ? 'academic-year' : 'date';
      const visible = await page.locator('[data-per-item-period]').evaluateAll(nodes => nodes.filter(e => getComputedStyle(e).display !== 'none').map(e => e.dataset.perItemPeriod));
      assert.ok(visible.includes(expected), item + ' visible period ' + visible);
    }
    for (const width of [1600, 900, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(base + '/laporan/template.php?' + query(snapshot.cases['1/status']));
      const trigger = page.locator('.spp-select[data-control=kategori] .spp-select-trigger');
      await trigger.focus(); await page.keyboard.press('Enter');
      const menu = page.locator('.spp-select[data-control=kategori] .spp-multi-panel');
      assert.equal(await trigger.getAttribute('aria-expanded'), 'true');
      const box = await menu.boundingBox();
      assert.ok(box.x >= 0 && box.x + box.width <= width + 1 && box.y >= 0 && box.y + box.height <= 1001, 'Dropdown clipped ' + width);
      await page.keyboard.press('Escape'); assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
    }
    await page.goto(base + '/laporan/template.php?' + query(snapshot.cases['1/status']));
    await page.locator('#global-siswa-search').fill('NO_MATCH_GLOBAL_REDESIGN');
    await Promise.all([page.waitForNavigation(), page.locator('.report-filter-actions button[type=submit]').click()]);
    assert.match(await page.locator('.report-result-pill').innerText(), /0/);
    await Promise.all([page.waitForNavigation(), page.locator('.report-filter-actions a').click()]);
    assert.equal(await page.locator('#global-siswa-search').inputValue(), '');
    for (const format of ['preview', 'excel']) {
      await page.goto(base + '/laporan/export_global.php?' + query({ ...snapshot.cases['1/status-multi'], format }));
      await page.locator('[data-preview-frame]').contentFrame().locator('table').first().waitFor();
      assert.equal(await page.locator('.preview-help').count(), 0);
      assert.equal(await page.locator('[data-preview-frame]').contentFrame().locator('.badge-nis,.excel-badge-nis').count() > 0, true);
      await page.screenshot({ path: dir + '/output-' + format + '.png', animations: 'disabled' });
    }
    assert.deepEqual(errors, []);
    console.log('PASS: ' + states + ' responsive/theme states, applied filters, categories/pagination, periods, search/reset, keyboard/dropdowns and previews; no JS errors');
  } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
