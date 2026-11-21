'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = process.env.SPP_TEST_BASE_URL;
assert.equal(new URL(base).hostname, '127.0.0.1');
const isolation = JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE, 'utf8'));
const {fixtures} = JSON.parse(fs.readFileSync(process.env.SPP_MANUAL_FEE_FIXTURE, 'utf8'));
const fixture = fixtures[1];

async function selectValue(locator, value) {
  await locator.evaluate((select, choice) => {
    select.value = choice;
    select.dispatchEvent(new Event('change', {bubbles: true}));
  }, value);
}

(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const context = await browser.newContext();
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await context.addCookies([{name:'PHPSESSID', value:fixture.super.id, url:base}]);
    await context.route('https://fonts.googleapis.com/**', route => route.abort());
    const identity = await (await context.request.get(base+'/tests/browser_clone_identity.php')).json();
    assert.equal(identity.server_uuid, isolation.server_uuid);
    assert.notEqual(identity.port, 3306);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    for (const width of [1440, 390]) {
      await page.setViewportSize({width, height:950});
      const theme = width === 1440 ? 'light' : 'dark';
      await page.goto(base+'/master_biaya_lain.php');
      const feedback = page.locator('#operation-feedback-dialog[open]');
      if (await feedback.isVisible()) await feedback.getByRole('button', {name:'Mengerti'}).click();
      await page.evaluate(value => {
        localStorage.setItem('spp_theme', value);
        document.documentElement.dataset.theme = value;
      }, theme);
      const methods = page.locator('#jenis-pembayaran');
      assert.equal(await page.locator('.fee-overview h1').innerText(), 'Master Biaya Lain');
      assert.equal(await page.locator('.fee-overview dl').count(), 1);
      assert.equal(await page.locator('#fee-publish-roster').count(), 0);
      assert.deepEqual((await methods.locator('option').allTextContents()).map(label => label.trim()), ['Berulang','Tidak Berulang','Input Manual']);
      const trigger = page.locator('.spp-select').filter({has:methods}).locator('.spp-select-trigger');
      await trigger.click();
      const menu = page.locator('#'+await trigger.getAttribute('aria-controls'));
      await menu.waitFor({state:'visible', timeout:4000});
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('Enter');
      assert.equal(await methods.inputValue(), 'input_manual');
      assert.equal(await page.locator('#nominal-biaya').isDisabled(), true);
      assert.equal(await page.locator('#fee-amount-row').isVisible(), false);
      assert.equal(await page.locator('#fee-manual-note').isVisible(), true);
      assert.equal(await page.locator('#fee-publish-master option[value="'+fixture.manual+'"]').count(), 0);
      assert.match(await page.locator('tr').filter({hasText:fixture.manualName}).innerText(), /Diisi saat bayar/);
      await selectValue(methods, 'berulang');
      assert.equal(await page.locator('#nominal-biaya').isVisible(), true);
      assert.equal(await page.locator('#nominal-biaya').evaluate(input => input.required), true);

      await page.goto(base+'/pembayaran/form.php');
      assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
      await page.evaluate(nis => {
        const input = document.getElementById('siswa-search');
        const option = [...document.getElementById('siswa-list').options].find(item => item.dataset.nis === nis);
        input.value = option.value;
        pilihSiswaDatalist(input, option);
      }, fixture.nis);
      const choice = 'master:'+fixture.manual;
      const row = page.locator('.biaya-lain-row').first();
      const fees = row.locator('.biaya-lain-select');
      await fees.locator('optgroup[label="Input Manual"] option[value="'+choice+'"]').waitFor({state:'attached'});
      await selectValue(fees, choice);
      const amount = row.locator('.biaya-lain-nominal');
      assert.equal(await amount.inputValue(), '');
      assert.equal(await row.locator('[name="biaya_lain_keterangan[]"]').count(), 0);
      assert.equal(await row.locator('.biaya-lain-total').isVisible(), false);
      assert.equal(await row.locator('.biaya-lain-paid').isVisible(), false);
      assert.equal(await row.locator('.biaya-lain-sisa').isVisible(), false);
      await amount.fill('1.2.3');
      assert.equal(await amount.evaluate(input => input.validity.valid), false);
      await amount.fill('');
      await amount.pressSequentially('100');
      assert.equal(await amount.inputValue(), '100');
      await amount.pressSequentially('0000,50');
      assert.equal(await amount.inputValue(), '1.000.000,50');
      await amount.evaluate(input => input.setSelectionRange(3, 3));
      await amount.press('2');
      assert.equal(await amount.inputValue(), '10.200.000,50');
      await amount.press('Backspace');
      assert.equal(await amount.inputValue(), '1.000.000,50');
      await page.evaluate(() => navigator.clipboard.writeText('210000.75'));
      await amount.press('Control+a');
      await amount.press('Control+v');
      assert.equal(await amount.inputValue(), '210.000,75');
      await amount.fill('210.000,75');
      assert.equal(await amount.evaluate(input => input.validity.valid), true);
      assert.equal(await amount.inputValue(), '210.000,75');
      const bounds = await row.boundingBox();
      assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width + 1);
      await selectValue(fees, 'master:'+fixture.master);
      assert.equal(await amount.inputValue(), '120.000');
      assert.equal(await row.locator('.biaya-lain-total').isVisible(), true);
      await page.locator('#btn-add-biaya-lain').click();
      assert.equal(await page.locator('#biaya-lain-list [name="biaya_lain_keterangan[]"]').count(), 0);
    }

    await page.goto(base+'/pembayaran/edit.php?id='+fixture.manualFirst);
    const saved = page.locator('.biaya-lain-row').first();
    assert.equal(await saved.locator('[name="biaya_lain_keterangan[]"]').count(), 0);
    assert.equal(await saved.locator('.biaya-lain-nominal').inputValue(), '133.120,75');
    assert.equal(await saved.locator('.biaya-lain-total').isVisible(), false);
    const history = await context.request.get(base+'/pembayaran/lihat.php?fee_master_id='+fixture.manual);
    assert.equal(history.status(), 200);
    assert.ok((await history.text()).includes(fixture.manualName));
    const receipt = await context.request.get(base+'/laporan/cetak_struk.php?id='+fixture.manualFirst);
    assert.equal(receipt.status(), 200);
    const receiptHtml = await receipt.text();
    assert.ok(receiptHtml.includes(fixture.manualName));
    assert.match(receiptHtml, /133\.120,75/);
    const report = new URLSearchParams({
      template:'per-item', kategori:'biaya_lain:'+fixture.manual, q:fixture.nis,
      tanggal_awal:'2026-10-01', tanggal_akhir:'2026-10-31', tahun_ajaran:'2026/2027',
    });
    const preview = await context.request.get(base+'/laporan/export_global.php?format=excel&'+report);
    assert.equal(preview.status(), 200);
    assert.match(await preview.text(), /133\.120,75/);
    const spreadsheet = await context.request.get(base+'/laporan/export_global.php?format=excel&download=1&'+report);
    assert.equal(spreadsheet.status(), 200);
    assert.match(spreadsheet.headers()['content-type'], /spreadsheetml/);
    if (process.env.SPP_MASTER_SCALE_FIXTURE) {
      const scale = JSON.parse(fs.readFileSync(process.env.SPP_MASTER_SCALE_FIXTURE, 'utf8').replace(/^\uFEFF/, ''));
      let usageFails = true;
      let rosterRequests = 0;
      await page.route(url => url.pathname.endsWith('/master_biaya_lain.php') && url.searchParams.has('section'), async route => {
        const section = new URL(route.request().url()).searchParams.get('section');
        if (section === 'roster') rosterRequests++;
        if (section === 'usage' && usageFails) {
          usageFails = false;
          await route.fulfill({status:503, body:'Please retry'});
          return;
        }
        await route.continue();
      });
      await page.goto(base+'/master_biaya_lain.php?master_tab=nonaktif');
      const list = page.locator('#daftar-master-biaya');
      const masterRows = list.locator('[data-master-id]');
      await list.locator('#fee-usage-retry').waitFor({state:'visible'});
      assert.equal(await masterRows.count(), 25);
      assert.equal(await list.locator('a[href*="master_tab=hapus"] .badge-count').count(), 0);
      assert.equal(await list.locator('button.btn-tbl-del').count(), 0);
      assert.equal(rosterRequests, 0);
      await list.locator('#fee-usage-retry').click();
      await list.locator('button.btn-tbl-del').first().waitFor();
      const legacy = masterRows.filter({hasText:'Buku Ummi'});
      assert.match(await legacy.innerText(), /3\.547 rincian/);
      assert.match(await legacy.innerText(), /67\.177\.500,00/);
      assert.ok((await legacy.locator('a.fee-transaction-link').first().getAttribute('href')).includes('fee_master_id='));
      assert.equal(await legacy.locator('button.btn-tbl-del').isDisabled(), true);
      const initialIds = await masterRows.evaluateAll(rows => rows.map(row => row.dataset.masterId));
      await list.locator('a[rel="next"]:visible').first().click();
      await list.locator('button.btn-tbl-del').first().waitFor();
      assert.equal(await masterRows.count(), 25);
      assert.equal(await masterRows.first().locator('td').first().innerText(), '26');
      assert.ok((await masterRows.evaluateAll(rows => rows.map(row => row.dataset.masterId))).every(id => !initialIds.includes(id)));
      await list.getByRole('link', {name:'Bisa Dihapus'}).click();
      await list.locator('#fee-delete-count').waitFor({state:'visible'});
      assert.ok(Number((await list.locator('#fee-delete-count').textContent()).replaceAll('.', '')) >= 2000);

      const publish = page.locator('#fee-publish-form');
      await selectValue(publish.locator('#fee-publish-master'), String(scale.master));
      await selectValue(publish.locator('#fee-publish-target'), 'siswa');
      const checkboxes = publish.locator('#fee-publish-ids input[type=checkbox]');
      await checkboxes.first().waitFor();
      assert.equal(rosterRequests, 1);
      assert.equal(await checkboxes.count(), 40);
      const first = await checkboxes.first().inputValue();
      await checkboxes.first().check();
      await publish.locator('#fee-publish-next').click();
      assert.notEqual(await checkboxes.first().inputValue(), first);
      assert.match(await publish.locator('#fee-publish-count').innerText(), /1 terpilih/);
      await publish.locator('#fee-publish-search').fill('unmatched-qa-publish');
      assert.equal(await checkboxes.count(), 0);
      assert.match(await publish.locator('#fee-publish-count').innerText(), /1 terpilih/);
      await publish.locator('#fee-publish-search').fill('');
      assert.equal(await checkboxes.first().isChecked(), true);
      await publish.getByRole('button', {name:'Tinjau Penerbitan'}).click();
      const confirm = page.getByRole('dialog').filter({hasText:'Konfirmasi Penerbitan'});
      await confirm.getByRole('button', {name:'Terbitkan Tagihan'}).click();
      assert.match(await page.locator('#flash-msg').innerText(), /1 tagihan diterbitkan|0 tagihan diterbitkan; 1 siswa/);
      assert.equal(rosterRequests, 1);
      console.log('PASS Master pagination, activity retry, deletable count, lazy roster and target publication with '+scale.masters+' masters.');
    }
    assert.deepEqual(errors, []);
    console.log('PASS Input Manual master, keyboard, payment fields, edit, history, receipt and export on desktop and mobile.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
