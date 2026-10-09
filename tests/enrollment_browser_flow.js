// Exercises the visible Data Siswa registration form on a disposable clone.
const assert = require('node:assert/strict');
const fs = require('node:fs');

if (process.env.SPP_TEST_ALLOW_MUTATION !== '1'
    || !/^db_spp_audit_[a-z0-9_]+$/.test(process.env.SPP_DB_NAME || '')) {
  throw new Error('Tes browser pendaftaran memerlukan clone audit dan flag tes.');
}
const base = new URL(process.env.SPP_TEST_BASE_URL || '');
if (base.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(base.hostname)) {
  throw new Error('SPP_TEST_BASE_URL harus menunjuk server HTTP lokal.');
}
const passwordFile = process.env.SPP_TEST_ADMIN_PASSWORD_FILE || '';
if (!passwordFile || !fs.existsSync(passwordFile)) throw new Error('File sandi admin latihan wajib ada.');
const password = fs.readFileSync(passwordFile, 'utf8').trim();
if (!password) throw new Error('Sandi admin latihan kosong.');
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');

const regularNis = '9988222001';
const psbNis = '9988222002';
const invalidNis = '9988222003';

async function openStudentForm(page) {
  await page.goto(new URL('/siswa/daftar.php', base).href);
  assert.match(await page.title(), /Data Siswa/);
  assert.equal(await page.locator('#form-master-siswa').isVisible(), true);
}

async function chooseClass(page, label) {
  await page.locator('[data-class-picker-button]').click();
  const option = page.locator(`[data-class-picker-option][data-label="${label}"]`);
  assert.equal(await option.count(), 1, `Pilihan kelas ${label} tidak tersedia.`);
  await option.click();
  assert.equal((await page.locator('[data-class-picker-label]').innerText()).trim(), label);
  assert.ok(Number(await page.locator('#kelas-baru').inputValue()) > 0);
}

async function submit(page) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.getByRole('button', { name: 'Tambah Siswa', exact: true }).click(),
  ]);
  return (await page.locator('#flash-msg').innerText()).trim();
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const context = await browser.newContext({
      viewport: { width: 1440, height: 900 },
      extraHTTPHeaders: { 'X-SPP-Test-Current-Year': '2026/2027' },
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());

    const identity = await page.request.get(new URL('/tests/browser_clone_identity.php', base).href);
    assert.equal(identity.status(), 200, 'Server browser tidak mengonfirmasi clone latihan.');
    assert.equal((await identity.json()).database, process.env.SPP_DB_NAME,
      'Server browser terhubung ke database yang berbeda.');

    await page.goto(new URL('/login.php', base).href);
    await page.locator('#username').fill('admin');
    await page.locator('#password').fill(password);
    await page.locator('#btn-login').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login.php'));

    await openStudentForm(page);
    await chooseClass(page, '1A');
    await page.locator('#nis-baru').fill(regularNis);
    await page.locator('#nama-baru').fill('UJI BROWSER REGULER');
    await page.locator('#student-komite-start').selectOption('09');
    assert.equal(await page.locator('#advanced-enabled').count(), 0);
    await page.locator('#student-pomg').fill('100000');
    await page.locator('#student-daftar_ulang').fill('500000');
    await page.locator('#student-potongan-spp').fill('25000');
    await page.locator('#student-potong-du').fill('50000');
    assert.match(await submit(page), /berhasil ditambahkan/i);
    await page.goto(new URL(`/siswa/daftar.php?q=${regularNis}`, base).href);
    assert.equal(await page.locator('tbody tr').filter({ hasText: regularNis }).count(), 1,
      'Siswa reguler tidak terlihat pada daftar setelah disimpan.');

    await openStudentForm(page);
    await chooseClass(page, '1A');
    await page.locator('#nis-baru').fill(regularNis);
    await page.locator('#nama-baru').fill('UJI DUPLIKAT');
    assert.match(await submit(page), /Nomor induk sudah digunakan/i);

    await openStudentForm(page);
    await chooseClass(page, 'PSB');
    await page.locator('#nis-baru').fill(invalidNis);
    await page.locator('#nama-baru').fill('UJI PSB TANPA NOMINAL');
    assert.equal(await page.locator('#advanced-enabled').count(), 0);
    assert.match(await submit(page), /Nominal Uang PSB wajib diisi/i);

    await openStudentForm(page);
    await chooseClass(page, 'PSB');
    await page.locator('#nis-baru').fill(psbNis);
    await page.locator('#nama-baru').fill('UJI BROWSER PSB');
    assert.equal(await page.locator('#advanced-enabled').count(), 0);
    await page.locator('#student-psb').fill('3600000');
    assert.match(await submit(page), /berhasil ditambahkan/i);
    await page.goto(new URL(`/siswa/daftar.php?q=${psbNis}`, base).href);
    assert.equal(await page.locator('tbody tr').filter({ hasText: psbNis }).count(), 1,
      'Siswa PSB tidak terlihat pada daftar setelah disimpan.');

    assert.deepEqual(errors, [], `JavaScript error: ${errors.join('; ')}`);
    console.log('OK: Chrome mendaftarkan siswa reguler dan PSB, menolak NIS duplikat dan PSB tanpa nominal.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error.stack || String(error));
  process.exitCode = 1;
});
