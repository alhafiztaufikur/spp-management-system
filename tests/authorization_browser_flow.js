const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
if (process.env.SPP_TEST_ALLOW_MUTATION !== '1' || !/^db_spp_audit_[a-z0-9_]+$/.test(process.env.SPP_DB_NAME || '')) {
  throw new Error('Requires a disposable audit clone and test flag.');
}
const base = new URL(process.env.SPP_TEST_BASE_URL || '');
if (base.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(base.hostname)) throw new Error('Loopback HTTP required.');
const password = fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE, 'utf8').trim();
const fixture = path.join(__dirname, 'authorization_browser_fixture.php');
const state = () => JSON.parse(execFileSync(process.env.SPP_PHP_BIN, [fixture, 'state'], {encoding:'utf8', env:process.env}));
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
(async () => {
  const browser = await chromium.launch({channel:'chrome', headless:true});
  try {
    const errors = [];
    async function login(username) {
      const context = await browser.newContext();
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      page.on('dialog', dialog => dialog.accept());
      const identity = await page.request.get(new URL('/tests/browser_clone_identity.php', base).href);
      assert.equal(identity.status(), 200);
      assert.equal((await identity.json()).database, process.env.SPP_DB_NAME);
      await page.goto(new URL('/login.php', base).href);
      await page.locator('#username').fill(username);
      await page.locator('#password').fill(password);
      await page.locator('#btn-login').click();
      await page.waitForURL(url => !url.pathname.endsWith('/login.php'));
      return page;
    }
    const cashier = await login('kasir1');
    const admin = await login('superadmin');
    async function propose(amount) {
      const before = state();
      await cashier.goto(new URL(`/pembayaran/edit.php?id=${before.payment.id}`, base).href);
      await cashier.waitForFunction(() => !document.querySelector('#psb-input')?.readOnly);
      await cashier.locator('#psb-input').fill(String(amount));
      await cashier.locator('[name="authorization_reason"]').fill(`Browser koreksi nominal menjadi ${amount}`);
      await Promise.all([cashier.waitForNavigation({waitUntil:'domcontentloaded'}), cashier.locator('#btn-update').click()]);
      const after = state();
      assert.equal(Number(after.payment.total_jumlah), Number(before.payment.total_jumlah), 'Cashier proposal changed payment before approval.');
      assert.equal(after.requests.length, before.requests.length + 1);
      assert.equal(after.requests.at(-1).status, 'pending');
      return after.requests.at(-1).id;
    }
    async function decision(page, id, action, button) {
      await page.goto(new URL('/otorisasi_transaksi.php?selected='+id, base).href);
      const form = page.locator('form').filter({has:page.locator(`input[name="request_id"][value="${id}"]`)}).filter({has:page.locator(action)});
      if (await form.locator('[name="decision_note"]').count()) await form.locator('[name="decision_note"]').fill('Keputusan browser untuk regresi audit');
      await form.getByRole('button', {name:button, exact:true}).click();
      await page.locator('.auth-confirm-dialog[open]').waitFor();
      await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('[data-confirm-accept]').click()]);
    }
    const approved = await propose(200);
    await decision(admin, approved, 'input[name="aksi"][value="otorisasi_setujui"]', 'Setujui dan Terapkan');
    assert.equal(Number(state().payment.total_jumlah), 200);
    assert.equal(state().requests.at(-1).status, 'approved');
    const rejected = await propose(300);
    await decision(admin, rejected, 'input[name="action"][value="reject"]', 'Tolak');
    assert.equal(state().requests.at(-1).status, 'rejected');
    assert.equal(Number(state().payment.total_jumlah), 200);
    const cancelled = await propose(400);
    await decision(cashier, cancelled, 'input[name="action"][value="cancel"]', 'Batalkan Permintaan');
    assert.equal(state().requests.at(-1).status, 'cancelled');
    assert.equal(Number(state().payment.total_jumlah), 200);
    assert.deepEqual(errors, []);
    console.log('OK: Chrome cashier proposal, Super Admin approval/rejection and requester cancellation; final database verified.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.stack || String(error)); process.exitCode = 1; });
