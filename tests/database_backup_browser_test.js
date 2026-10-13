/* Browser controller/layout tests. Operation responses are mocked after isolated-server identity is proven. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = new URL(process.env.SPP_TEST_BASE_URL);
const database = process.env.SPP_DB_NAME;
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
assert.ok(base.protocol === 'http:' && ['127.0.0.1', 'localhost'].includes(base.hostname));
const manifest = process.env.SPP_TEST_ISOLATED_INSTANCE
  ? JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE, 'utf8')) : null;
if (manifest) {
  assert.equal(manifest.version, 1);
  assert.equal(manifest.isolated_test, true);
  assert.equal(manifest.database, database);
  assert.notEqual(manifest.server_uuid.toLowerCase(), manifest.source_server_uuid.toLowerCase());
  assert.notEqual(manifest.datadir.replaceAll('\\', '/').toLowerCase(), manifest.source_datadir.replaceAll('\\', '/').toLowerCase());
  assert.equal(manifest.host, '127.0.0.1');
  assert.equal(process.env.SPP_DB_HOST, manifest.host);
  assert.equal(Number(process.env.SPP_DB_PORT), manifest.port);
  assert.notEqual(manifest.port, 3306);
} else assert.match(database, /^db_spp_audit_[a-z0-9_]+$/);
const artifactRoot = process.env.SPP_UI_ARTIFACTS;
assert.ok(artifactRoot && path.isAbsolute(artifactRoot), 'Use private screenshot storage outside the repository');
assert.ok(!path.resolve(artifactRoot).toLowerCase().startsWith(path.resolve('.').toLowerCase() + path.sep));
fs.mkdirSync(artifactRoot, {recursive: true});

(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const identity = await (await page.request.get(new URL('/tests/browser_clone_identity.php', base).href)).json();
    assert.equal(identity.database, database);
    if (manifest) {
      assert.equal(identity.isolated_test, true);
      assert.equal(identity.server_uuid, manifest.server_uuid);
      assert.equal(identity.datadir.replaceAll('\\', '/').toLowerCase(), manifest.datadir.replaceAll('\\', '/').toLowerCase());
      assert.equal(identity.port, manifest.port);
    }
    await page.goto(new URL('/login.php', base).href);
    await page.locator('#username').fill('superadmin');
    await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE, 'utf8').trim());
    await page.locator('#btn-login').click();
    await page.waitForURL('**/dashboard.php');
    await page.goto(new URL('/backup_restore.php', base).href);
    assert.equal(await page.locator('#database-backup-form').getAttribute('method'), 'post');
    assert.equal(await page.locator('#database-backup-form').getAttribute('action'), 'backup_api.php');
    assert.equal(await page.locator('#database-backup-form input[name=return]').inputValue(), 'page');
    assert.match(await page.locator('#database-backup-form input[name=request_key]').inputValue(), /^[a-f0-9]{32}$/);
    assert.equal(await page.locator('#restore-apply').isDisabled(), true);
    assert.match(await page.locator('#backup-next').textContent(), /18:00 WIB/);

    const actual = await (await page.request.get(new URL('/backup_api.php?action=status', base).href)).json();
    let summary = {...actual, configured: true, ready: true, active: null, last_success: null,
      count: 0, total_bytes: 0, alerts: [], jobs: []};
    let createRequests = 0;
    let failNext = true;
    const now = new Date().toISOString();
    const job = {id: 'ab'.repeat(16), name: 'SistemSPP_<img onerror=alert(1)>.sql', type: 'manual',
      created_at: now, scheduled_for: null, started_at: now, finished_at: null,
      state: 'queued', message: 'Menunggu layanan.', bytes: 0, actor_name: '<img src=x onerror=alert(1)>'};
    await page.route('**/backup_api.php*', async route => {
      const request = route.request();
      if (request.method() === 'POST') {
        createRequests++;
        // The native return target must be stripped for fetch so the endpoint returns JSON.
        const data = request.postData() || '';
        assert.ok(!/name="return"\r\n\r\npage\r\n/.test(data), 'JS create still requests an HTML redirect');
        if (failNext) { failNext = false; return route.abort('connectionreset'); }
        summary = {...summary, active: {...job}, jobs: [{...job}]};
        await new Promise(resolve => setTimeout(resolve, 150));
        return route.fulfill({status: 202, contentType: 'application/json', body: JSON.stringify({job, request_key: 'cd'.repeat(16)})});
      }
      if (new URL(request.url()).searchParams.get('action') === 'status') {
        return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(summary)});
      }
      return route.continue();
    });
    const refresh = async () => page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await refresh();
    await page.locator('#backup-create:not([disabled])').waitFor();
    await page.locator('#backup-create').click();
    await page.waitForFunction(() => document.querySelector('#backup-feedback').textContent.includes('Koneksi terputus'));
    await page.locator('#backup-create:not([disabled])').waitFor();
    assert.equal(createRequests, 1);
    await page.locator('#database-backup-form').evaluate(form => {
      form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
      form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    });
    await page.waitForFunction(() => document.querySelector('#backup-progress-state').textContent === 'Menunggu'
      && !document.querySelector('#backup-progress').hidden);
    assert.equal(createRequests, 2, 'Double submit should make exactly one new request');
    assert.equal(await page.locator('#backup-create').isDisabled(), true);
    assert.equal(await page.locator('#backup-history-body img').count(), 0, 'Unsafe names rendered as markup');
    for (const [state, label] of [['dumping', 'Mencadangkan'], ['verifying', 'Memverifikasi']]) {
      summary = {...summary, active: {...job, state}, jobs: [{...job, state}]};
      await refresh();
      await page.waitForFunction(expected => document.querySelector('#backup-progress-state').textContent === expected, label);
      assert.equal(await page.locator(`[data-backup-stage=${state}]`).getAttribute('aria-current'), 'step');
    }
    const completed = {...job, state: 'verified', finished_at: now, bytes: 12345,
      download_url: 'backup_api.php?action=download&id=' + job.id};
    summary = {...summary, active: null, last_success: completed, jobs: [completed], count: 1, total_bytes: completed.bytes};
    await refresh();
    await page.waitForFunction(() => document.querySelector('#backup-count').textContent === '1');
    assert.equal(await page.locator('#backup-create').isEnabled(), true);
    assert.equal(await page.locator('#backup-history-body img').count(), 0);
    assert.match(await page.locator('#backup-history-body .dbt-download').getAttribute('href'), /id=abababababababababababababababab$/);
    assert.match(await page.locator('#backup-feedback').textContent(), /selesai.*terverifikasi/);
    console.log('PASS: failed network/retry, JSON enqueue, double-submit guard, four progress states, safe names and download links');

    for (const unit of [0, 1, 2, 3]) {
      await page.setViewportSize({width: 1440, height: 1000});
      await Promise.all([page.waitForNavigation(), page.locator('#sidebar-unit-select').selectOption(String(unit))]);
      await refresh();
      await page.locator('#backup-create:not([disabled])').waitFor();
      assert.equal(await page.locator('#database-backup-form input[name=csrf_token]').isDisabled(), false,
        'All-unit sidebar disabled the backup form');
      for (const theme of ['light', 'dark']) for (const width of [1440, 900, 390]) {
        await page.setViewportSize({width, height: width === 390 ? 844 : 1100});
        await page.evaluate(theme => {
          localStorage.setItem('spp_theme', theme);
          document.documentElement.setAttribute('data-theme', theme);
        }, theme);
        await page.addStyleTag({content: '*,*::before,*::after{transition:none!important;animation:none!important}'});
        const layout = await page.evaluate(() => {
          const button = document.querySelector('#backup-create').getBoundingClientRect();
          const history = document.querySelector('.dbt-table-scroll');
          const filters = document.querySelector('#backup-history-filters');
          const filterItems = [filters.querySelector('input[type=search]'),
            filters.querySelector('.spp-select') || filters.querySelector('select'), filters.querySelector('button[type=submit]')];
          return {overflow: document.documentElement.scrollWidth > innerWidth + 1,
            buttonHeight: button.height, buttonWidth: button.width, historyScrollable: getComputedStyle(history).overflowX,
            filterCenters: filterItems.map(node => {
              const box = node.getBoundingClientRect();
              return box.top + box.height / 2;
            })};
        });
        assert.equal(layout.overflow, false, JSON.stringify({unit, theme, width, layout}));
        assert.ok(layout.buttonHeight >= 44 && layout.buttonWidth > 100, 'Backup touch target too small');
        if (width === 1440) assert.ok(Math.max(...layout.filterCenters) - Math.min(...layout.filterCenters) < 1,
          'Desktop backup search, unit-independent type picker and search button must align on one row');
        if (width !== 900) await page.screenshot({path: path.join(artifactRoot, `backup_${unit}_${theme}_${width}.png`), fullPage: true});
      }
    }
    await page.locator('#backup-create').focus();
    assert.equal(await page.locator('#backup-create').evaluate(node => node === document.activeElement), true);
    console.log('PASS: four unit contexts, keyboard target and 24 desktop/tablet/mobile theme layouts');

    const native = await browser.newContext({javaScriptEnabled: false, storageState: await context.storageState(),
      viewport: {width: 390, height: 844}});
    const nativePage = await native.newPage();
    await nativePage.goto(new URL('/backup_restore.php?q=does-not-exist&type=manual', base).href);
    assert.match(await nativePage.locator('.dbt-empty').textContent(), /Tidak ada cadangan yang cocok/);
    assert.equal(await nativePage.locator('#database-backup-form input[name=return]').inputValue(), 'page');
    assert.equal(await nativePage.locator('#backup-history-filters').getAttribute('method'), 'get');
    assert.equal(await nativePage.locator('#restore-apply').isDisabled(), true);
    assert.equal(await nativePage.locator('text=Reset').count(), 1);
    assert.deepEqual(errors, []);
    console.log('PASS: native form and GET filtering remain available without JavaScript; Restore disabled');
    await native.close();
    await context.close();
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
