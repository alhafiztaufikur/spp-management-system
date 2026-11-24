// Real Chrome cashier flow on a named disposable clone. Run the PHP fixture first.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

if (process.env.SPP_TEST_ALLOW_MUTATION !== '1'
    || !/^db_spp_audit_[a-z0-9_]+$/.test(process.env.SPP_DB_NAME || '')) {
  throw new Error('Payment browser flow requires a disposable audit clone and test flag.');
}
const base = new URL(process.env.SPP_TEST_BASE_URL || '');
if (base.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(base.hostname)) {
  throw new Error('Payment browser flow requires a local HTTP server.');
}
const passwordFile = process.env.SPP_TEST_ADMIN_PASSWORD_FILE || '';
if (!passwordFile || !fs.existsSync(passwordFile)) throw new Error('Test admin password file is required.');
const password = fs.readFileSync(passwordFile, 'utf8').trim();
if (!password) throw new Error('Test admin password is empty.');
const php = process.env.SPP_PHP_BIN || 'php';
const fixture = path.join(__dirname, 'payment_browser_fixture.php');
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');

function state() {
  return JSON.parse(execFileSync(php, [fixture, 'state'], { encoding: 'utf8', env: process.env }));
}
async function openForm(page) {
  await page.goto(new URL('/pembayaran/form.php', base).href);
  assert.match(await page.title(), /Input Pembayaran/);
  assert.equal(await page.locator('#du-selector-trigger').isVisible(), true);
}
async function student(page, name, nis) {
  await page.locator('#siswa-search').fill(name);
  await page.waitForFunction(expected => document.querySelector('#disp-nis')?.value === expected, nis);
}
async function period(page, month) {
  if (await page.locator('#tahun-bayar').inputValue() !== '2026') {
    await page.locator('#tahun-bayar').fill('2026');
  }
  await page.locator('#bulan-bayar').selectOption(month);
  await page.locator('#sistem-pembayaran').selectOption('Tunai');
  assert.equal(await page.locator('#bulan-bayar').inputValue(), month);
  assert.equal(await page.locator('#tahun-bayar').inputValue(), '2026');
  await page.waitForTimeout(500);
}
async function fillMoney(page, id, amount) {
  const input = page.locator(id);
  await input.waitFor({ state: 'visible' });
  await page.waitForFunction(selector => !document.querySelector(selector)?.readOnly, id);
  await input.fill(String(amount));
}
async function submit(page, button = '#btn-input') {
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 8000 });
  await page.locator(button).click();
  try {
    await navigation;
  } catch (error) {
    const warning = await page.locator('#spp-warning-message').innerText().catch(() => '');
    const title = await page.locator('#spp-warning-title').innerText().catch(() => '');
    const validation = await page.locator('#form-bayar').evaluate(form =>
      Array.from(form.elements).filter(field => field.validationMessage).map(field =>
        `${field.name}: ${field.validationMessage}`).join('; ')).catch(() => '');
    throw new Error(`Payment submit stayed on ${page.url()}: ${title} ${warning}; validation=${validation}`, { cause: error });
  }
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    const identity = await page.request.get(new URL('/tests/browser_clone_identity.php', base).href);
    assert.equal(identity.status(), 200, 'HTTP server is not verified as an audit clone.');
    assert.equal((await identity.json()).database, process.env.SPP_DB_NAME,
      'HTTP server points to the wrong database.');

    await page.goto(new URL('/login.php', base).href);
    await page.locator('#username').fill('admin');
    await page.locator('#password').fill(password);
    await page.locator('#btn-login').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login.php'));

    await openForm(page);
    await page.locator('#du-selector-trigger').click();
    assert.match(await page.locator('#du-selector-menu').innerText(), /Pilih siswa untuk melihat tagihan/);
    await page.locator('#du-selector-trigger').click();

    await student(page, 'BROWSER TANPA TAGIHAN', '9988111003');
    assert.equal(await page.locator('#du-arrear-warning').isVisible(), false);
    await page.locator('#du-selector-trigger').click();
    assert.match(await page.locator('#du-selector-menu').innerText(), /Belum ada tagihan Daftar Ulang/);
    assert.notEqual(await page.locator('#du-input').getAttribute('readonly'), null);
    await page.locator('#du-selector-trigger').click();

    await student(page, 'BROWSER BAYAR TAHUN INI', '9988111002');
    assert.equal(await page.locator('#du-arrear-warning').isVisible(), false);
    assert.notEqual(await page.locator('#tagihan-daftar-ulang-id').inputValue(), '');
    assert.equal(await page.locator('#du-input').getAttribute('readonly'), null);

    await student(page, 'BROWSER BAYAR TUNGGAKAN', '9988111001');
    assert.equal(await page.locator('#du-arrear-warning').isVisible(), true);
    const currentBill = await page.locator('#tagihan-daftar-ulang-id').inputValue();
    await page.locator('#du-selector-trigger').click();
    const options = page.locator('#du-selector-menu .du-selector-option');
    assert.equal(await options.count(), 2);
    assert.match(await options.nth(0).innerText(), /2025\/2026/);
    assert.match(await options.nth(1).innerText(), /2026\/2027/);
    await options.nth(0).click();
    const oldBill = await page.locator('#tagihan-daftar-ulang-id').inputValue();
    assert.notEqual(oldBill, currentBill);
    assert.equal(await page.locator('#tahun-ajaran-du').inputValue(), '2025/2026');

    // The visible SPP control must identify the oldest unpaid month.
    await period(page, '08');
    await page.locator('#spp-input').focus();
    await page.locator('#spp-warning-overlay.show').waitFor({ state: 'visible' });
    assert.match(await page.locator('#spp-warning-message').innerText(), /Juli 2026/);
    await page.locator('#spp-warning-close').click();
    assert.equal(state().payments.length, 0);

    await period(page, '07');
    await fillMoney(page, '#du-input', 300000);
    await fillMoney(page, '#spp-input', 250000);
    await fillMoney(page, '#komite-input', 100000);
    assert.equal(Number(await page.locator('#hidden-total').inputValue()), 650000);
    await submit(page);
    assert.equal(state().payments.length, 1, 'July payment was not saved.');

    const firstId = Number(state().payments[0].id);
    await page.goto(new URL(`/pembayaran/edit.php?id=${firstId}`, base).href);
    assert.match(await page.title(), /Edit Pembayaran/);
    assert.equal(await page.locator('#du-selector-trigger').isVisible(), true);
    assert.equal(await page.locator('#tahun-ajaran-du').inputValue(), '2025/2026');
    assert.equal(await page.locator('#du-arrear-warning').isVisible(), true);
    await fillMoney(page, '#du-input', 200000);
    await submit(page, '#btn-update');
    assert.equal(Number(state().payments[0].total_jumlah), 550000,
      'Editing the historical DU payment did not update its cash total.');

    await page.goto(new URL(`/laporan/cetak_struk.php?id=${firstId}`, base).href);
    const receipt = await page.locator('body').innerText();
    assert.match(receipt, /Uang DU 2025\/2026/);
    assert.match(receipt, /SPP Juli 2026/);
    assert.match(receipt, /Komite Sekolah \(Juli 2026\)/);

    await openForm(page);
    await student(page, 'BROWSER BAYAR TUNGGAKAN', '9988111001');
    await period(page, '08');
    assert.equal(await page.locator('#spp-record-deposit-button, #spp-use-deposit-button').count(), 0);
    await fillMoney(page, '#komite-input', 100000);
    await fillMoney(page, '#spp-input', 250000);
    assert.equal(Number(await page.locator('#hidden-total').inputValue()), 350000);
    await submit(page);
    assert.equal(state().payments.length, 2, 'August direct payment was not saved.');
    await page.goto(new URL(`/pembayaran/edit.php?id=${state().payments[1].id}`, base).href);
    assert.equal(await page.locator('#gunakan-titipan-spp').count(), 0);

    await page.goto(new URL(`/laporan/cetak_struk.php?id=${state().payments[1].id}`, base).href);
    assert.match(await page.locator('body').innerText(), /SPP Agustus 2026/);
    assert.deepEqual(errors, [], `Browser JavaScript errors: ${errors.join('; ')}`);
    console.log('OK: Chrome cashier input, historical DU dropdown, oldest SPP warning, edit, direct payments and receipts.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error.stack || String(error));
  process.exitCode = 1;
});
