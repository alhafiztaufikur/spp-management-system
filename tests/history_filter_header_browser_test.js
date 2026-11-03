/* Read-only regression: scrolling payment filters must stay behind the sticky header. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = process.env.SPP_TEST_BASE_URL || '';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost):\d+$/);
assert.match(process.env.SPP_DB_NAME || '', /^db_spp_audit_[a-z0-9_]+$/);

(async () => {
  const browser = await chromium.launch({channel:'chrome', headless:true});
  try {
    const page = await browser.newPage({viewport:{width:1440,height:1000}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      if (request.method() === 'POST' && !/\/(login|unit_switch)\.php$/.test(request.url())) {
        errors.push('Unexpected mutation: ' + request.url());
      }
    });
    const identity = await (await page.request.get(base + '/tests/browser_clone_identity.php')).json();
    assert.equal(identity.database, process.env.SPP_DB_NAME);
    await page.goto(base + '/login.php');
    await page.locator('#username').fill('superadmin');
    await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());
    await Promise.all([page.waitForNavigation(), page.locator('#btn-login').click()]);

    async function headerVisible() {
      const covered = await page.evaluate(() => {
        const bar = document.querySelector('.topbar'), rect = bar.getBoundingClientRect();
        return Array.from({length:12}, (_, index) => {
          const x = rect.left + 20 + (rect.width - 40) * index / 11;
          const hit = document.elementFromPoint(x, rect.top + 25);
          return hit && !bar.contains(hit) ? hit.className : null;
        }).filter(Boolean);
      });
      assert.deepEqual(covered, [], 'A scrolling filter covered the header');
    }

    for (const width of [1280,1440,1920]) for (const theme of ['light','dark']) {
      await page.setViewportSize({width,height:1000});
      await page.goto(base + '/pembayaran/lihat.php');
      await page.evaluate(value => document.documentElement.dataset.theme = value, theme);
      for (const y of [160,200,240,280,340]) {
        await page.evaluate(value => scrollTo(0,value), y);
        await headerVisible();
      }
      for (const selector of ['.report-date-range-button','.ph-filter-card .spp-select-trigger']) {
        await page.evaluate(() => scrollTo(0,0));
        const trigger = page.locator(selector).first();
        await trigger.click();
        await page.locator('[data-spp-floating-open]').waitFor();
        const rect = await trigger.boundingBox();
        await page.evaluate(y => scrollTo(0,y), rect.y - 40);
        await page.waitForFunction(() => !document.querySelector('[data-spp-floating-open]'));
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
        await headerVisible();
      }
    }
    assert.deepEqual(errors, []);
    console.log('PASS: payment filter stacking and floating panel dismissal at three desktop widths, both themes.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
