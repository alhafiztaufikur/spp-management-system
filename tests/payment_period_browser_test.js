'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.SPP_TEST_BASE_URL;
const alternate = process.env.SPP_TEST_ALTERNATE_URL;
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
assert.match(alternate, /^http:\/\/127\.0\.0\.1:\d+$/);
const fixtureFile = process.env.SPP_PAYMENT_PERIOD_FIXTURE;
const fixture = JSON.parse(fs.readFileSync(fixtureFile, 'utf8'));
const manifest = JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE, 'utf8'));
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
const artifacts = path.dirname(fixtureFile);
const cli = (...args) => execFileSync(process.env.SPP_PHP_BIN || 'php',
  ['-d','session.save_path=' + path.join(artifacts,'sessions'),path.join(__dirname,'payment_period_fixture.php'), ...args],
  {encoding:'utf8',env:process.env});
const state = scenario => JSON.parse(cli('state',fixture.cases[scenario].nis));
const post = (ctx,origin,form) => ctx.request.post(origin+'/pembayaran/proses.php',{form,maxRedirects:0,timeout:90000});
const input = page => page.locator('#form-bayar').evaluate(form => Object.fromEntries(new FormData(form)));
const ready = page => page.waitForFunction(() => document.querySelector('[data-payment-period-version]').value.length === 64);
const activeMode = page => page.locator('#payment-period-mode');
const chooseMode = (page,mode) => activeMode(page).selectOption(mode);

async function selectStudent(page,scenario,expectSummary = false) {
  const openingPopup = await page.locator('#siswa-search').evaluate((field,nis) => {
    const option = [...document.getElementById('siswa-list').options].find(item => item.dataset.nis === nis);
    if (!option) throw new Error('Missing test student');
    field.value = option.value;
    pilihSiswaDatalist(field,option);
    const popup = document.getElementById('spp-warning-overlay');
    return popup.classList.contains('show') && popup.dataset.statusCode;
  },fixture.cases[scenario].nis);
  await page.waitForFunction(() => !sppStatusState.pending);
  const summary = page.locator('#spp-warning-overlay[data-status-code=obligations_summary].show');
  if (expectSummary) {
    assert.equal(openingPopup,'obligations_loading');
    await summary.waitFor();
    assert.match(await summary.innerText(),/Ringkasan Kewajiban Siswa/);
    assert.match(await summary.innerText(),/Total Kewajiban/);
  }
  if (await summary.isVisible() && await activeMode(page).inputValue() !== 'monthly') {
    await summary.getByRole('button',{name:'Mengerti',exact:true}).click();
  } else if (await page.locator('#spp-warning-overlay.show').isVisible()) {
    await page.keyboard.press('Escape');
  }
}
async function openForm(ctx,origin = base) {
  const page = await ctx.newPage();
  await page.goto(origin+'/pembayaran/form.php');
  await page.locator('.payment-period-select .spp-select-trigger').waitFor();
  const saved = page.locator('#payment-saved-dialog[open] #payment-saved-continue');
  if (await saved.isVisible()) await saved.click();
  return page;
}
async function cashSetup(page) {
  await page.locator('#tgl-bayar').evaluate(field => { field.value = '2026-11-23'; });
  await page.selectOption('#sistem-pembayaran','Tunai');
}
async function choose(page,scenario,mode,value,expectSummary = false) {
  await chooseMode(page,mode);
  await selectStudent(page,scenario,expectSummary);
  await page.selectOption('#payment-period-choice',value);
  await cashSetup(page);
}
async function enterAdditionalFees(page, mode) {
  await page.locator('#psb-input').fill('40000');
  await page.locator('#du-input').fill('25000,50');
  await page.locator('#biaya-lain-list .biaya-lain-select').selectOption('master:'+fixture.fees[mode].id);
  await page.locator('#biaya-lain-list .biaya-lain-nominal').fill(mode === 'berulang' ? '20000' : '12345,50');
}
async function oneReceipt(page,transaction) {
  const url = base+'/laporan/cetak_struk.php?id='+transaction.id;
  await page.goto(url);
  const slip = page.locator('article.receipt-sheet');
  assert.equal(await slip.count(),1);
  assert.equal(await slip.locator('.document-title').innerText(),'SLIP PEMBAYARAN SEKOLAH');
  const contents = await slip.innerText();
  assert.doesNotMatch(contents,/TRX-\d+|Bulan Tagihan/);
  assert.match(contents,/Periode Tagihan/);
  for (const key of ['U_SPP','U_KOMITE','total_jumlah']) {
    const amount = new Intl.NumberFormat('id-ID',{maximumFractionDigits:2}).format(transaction[key]);
    assert.ok(contents.includes(amount),key+' missing from receipt');
  }
  if (Number(transaction.U_PSB) > 0) assert.match(contents,/Uang PSB\s*:\s*40\.000/);
  if (transaction.du.length) {
    const label = slip.getByText('Uang DU 2026/2027',{exact:true});
    assert.match(contents,/Uang DU 2026\/2027\s*:\s*25\.000,50/);
    const lineCounts = await label.evaluate(async cell => {
      await document.fonts.ready;
      return [cell, cell.nextElementSibling, cell.parentElement.lastElementChild].map(part => {
        const text = document.createRange();
        text.selectNodeContents(part);
        return text.getClientRects().length;
      });
    });
    assert.deepEqual(lineCounts,[1,1,1]);
  }
  for (const item of transaction.other) assert.ok(contents.includes(item.nama_biaya_snapshot));
  assert.ok(await slip.locator('.receipt-payment-signature').evaluate(el=>el.getBoundingClientRect().height>52));
  const printPages = await page.evaluate(async () => {
    await document.fonts.ready;
    window.receiptPagination.prepare();
    const count = document.querySelectorAll('.receipt-print-page').length;
    window.receiptPagination.clean();
    return count;
  });
  assert.equal(printPages,1);
  const bytes = await (await page.request.get(url+'&output=pdf')).body();
  assert.equal(bytes.subarray(0,4).toString(),'%PDF');
  fs.writeFileSync(path.join(artifacts,'kuitansi-'+transaction.id+'.pdf'),bytes);
}
async function approve(ctx,id) {
  const screen = await ctx.newPage();
  await screen.goto(base+'/otorisasi_transaksi.php?selected='+id);
  const form = screen.locator('form:has(input[name=aksi][value=otorisasi_setujui])');
  assert.equal(await form.locator('input[name=request_id]').inputValue(),String(id));
  const token = await form.locator('input[name=csrf_token]').inputValue();
  const response = await post(ctx,base,{aksi:'otorisasi_setujui',request_id:id,csrf_token:token,_operational_unit:'1'});
  assert.ok([302,303].includes(response.status()));
  assert.match(response.headers().location || '',/otorisasi_transaksi\.php/);
  await screen.close();
}

(async () => {
  const browser = await chromium.launch({channel:'chrome',headless:true,args:['--disable-print-preview']});
  const errors = [];
  try {
    const context = async (role,width,height = 1000) => {
      const ctx = await browser.newContext({viewport:{width,height},extraHTTPHeaders:{'X-SPP-Test-Current-Year':'2026/2027'}});
      await ctx.route('https://fonts.googleapis.com/**',route => route.abort());
      await ctx.addInitScript(() => { window.print = () => {}; });
      await ctx.addCookies([{name:'PHPSESSID',value:fixture.sessions[role],url:base}]);
      ctx.on('page', p => p.on('pageerror', e => errors.push(String(e))));
      const response = await ctx.request.get(base+'/tests/browser_clone_identity.php');
      assert.equal((await response.json()).server_uuid,manifest.server_uuid);
      return ctx;
    };
    const cashier = await context('cashier',1366);
    const admin = await context('admin',1920,1080);
    const supervisor = await context('super_admin',1920,1080);
    const alternateIdentity = await supervisor.request.get(alternate+'/tests/browser_clone_identity.php');
    assert.equal((await alternateIdentity.json()).server_uuid,manifest.server_uuid);
    const page = await openForm(cashier);
    assert.equal(await activeMode(page).inputValue(),'monthly');
    assert.deepEqual(await activeMode(page).locator('option').allTextContents(),['1 Bulan','Multi Bulan']);
    assert.equal(await page.locator('.payment-period-inputs').isVisible(),true);
    const cards = await page.locator('.payment-settings-grid > .payment-field-card').evaluateAll(nodes=>nodes.map(node=>node.getBoundingClientRect().toJSON()));
    assert.equal(cards.length,3);
    assert.ok(cards.every((card,index)=>card.y===cards[0].y&&(!index||card.x>=cards[index-1].right)));
    await choose(page,'main','range','2026-12',true);
    const [modeField,startField,direction,endField] = await Promise.all([
      page.locator('.payment-period-select .spp-select-trigger').boundingBox(),
      page.locator('.payment-range-start-value').boundingBox(),
      page.locator('.payment-range-direction').boundingBox(),
      page.locator('.payment-period-choice-control .spp-select-trigger').boundingBox(),
    ]);
    assert.ok(modeField.x < startField.x && startField.x < direction.x && direction.x < endField.x);
    assert.ok(Math.abs(modeField.y - endField.y) <= 1);
    for (const [end,count] of [['2026-12',2],['2027-01',3],['2027-04',6],['2027-02',4]]) {
      await page.selectOption('#payment-period-choice',end);
      await ready(page);
      assert.equal(await page.locator('.payment-range-table tbody tr').count(),count);
      assert.equal(await page.locator('[data-payment-period-count]').innerText(),count+' BULAN AKAN DIBAYAR');
    }
    await page.locator('#sistem-pembayaran').evaluate(select => {
      select.value = '';
      select.dispatchEvent(new Event('change',{bubbles:true}));
    });
    await page.locator('#btn-input').click();
    const missingMethod = page.locator('#spp-warning-overlay[data-status-code=method_missing].show');
    await missingMethod.getByRole('button',{name:'Pilih Metode',exact:true}).click();
    await page.getByRole('listbox',{name:'Sistem Pembayaran',exact:true})
      .getByRole('option',{name:'Tunai',exact:true}).click();
    assert.equal(await page.locator('#sistem-pembayaran').inputValue(),'Tunai');
    assert.match(await page.locator('#totalJumlah').innerText(),/1\.200\.001/);
    assert.equal(await page.locator('#spp-input').isDisabled(),true);
    assert.equal(await page.locator('#komite-input').isDisabled(),true);
    assert.equal(await page.locator('.form-payment-table tbody tr:visible').count(),2);
    assert.equal(await page.locator('#psb-input').isVisible(),true);
    assert.equal(await page.locator('#du-input').isVisible(),true);
    const sections = await page.locator('#payment-period-heading, #form-bayar .section-divider span').allTextContents();
    assert.deepEqual(sections.slice(-4),['Rincian SPP & Komite','Rincian Pembayaran','Lain-lain','History Transaksi Siswa']);
    await enterAdditionalFees(page,'berulang');
    await page.selectOption('#payment-period-choice','');
    assert.equal(Number(await page.locator('#hidden-total').inputValue()),85000.50);
    await page.selectOption('#payment-period-choice','2027-02');
    await ready(page);
    assert.equal(Number(await page.locator('#hidden-total').inputValue()),1285001.50);
    assert.equal(await page.locator('#form-bayar').evaluate(form=>form.scrollWidth<=form.clientWidth+2),true);
    await page.evaluate(() => scrollTo(0,0));
    await page.screenshot({path:path.join(artifacts,'desktop-1366-light.png'),fullPage:true});
    const original = await input(page);
    const future = page.locator('#spp-warning-overlay[data-status-code=future_period].show');
    await page.locator('#btn-input').click();
    await future.waitFor();
    assert.match(await future.innerText(),/Februari 2027/);
    assert.match(await future.innerText(),/1\.285\.001,5/);
    await future.getByRole('button',{name:'Kembali',exact:true}).click();
    assert.equal(state('main').payments.length,0);
    await page.locator('#btn-input').click();
    await Promise.all([page.waitForNavigation(),future.getByRole('button',{name:'Tetap Simpan',exact:true}).click()]);
    assert.equal(await page.locator('#payment-saved-dialog').isVisible(),true);
    const initial = state('main').payments[0];
    assert.equal(initial.allocations.length,4);
    assert.equal(Number(initial.total_jumlah),1285001.50);
    assert.equal(Number(initial.U_PSB),40000);
    assert.equal(Number(initial.du[0].jumlah),25000.50);
    assert.equal(Number(initial.du[0].tagihan_daftar_ulang_id),fixture.cases.main.du_id);
    assert.equal(initial.other[0].origin,'purchase');
    assert.equal(Number(initial.other[0].nominal_snapshot),20000);
    assert.deepEqual(initial.allocations.map(row=>row.period),['2026-11','2026-12','2027-01','2027-02']);
    await post(cashier,base,original);
    assert.equal(state('main').payments.length,1);
    const receipt = await cashier.newPage();
    await oneReceipt(receipt,initial);
    const history = await cashier.request.get(base+'/pembayaran/history_siswa.php?scope=all&unit_id=1&no_induk='+fixture.cases.main.nis);
    const historyRow = (await history.json()).rows.find(row => row.id === initial.id);
    assert.match(historyRow.periode,/November 2026.*Februari 2027/);
    assert.equal(historyRow.periode_numeric,'11 2026 – 02 2027');
    assert.ok(historyRow.components.includes('Biaya Lain'));
    assert.equal(historyRow.total,'Rp 1.285.001,50');
    const historyDetail = await cashier.request.get(base+'/pembayaran/'+historyRow.detail_url);
    assert.ok((await historyDetail.text()).includes(fixture.fees.berulang.name));
    console.log('PASS four-month checkout, receipt, replay and student history');

    const annual = await openForm(admin);
    await annual.evaluate(() => document.documentElement.dataset.theme = 'dark');
    await choose(annual,'annual','range','2028-06');
    await ready(annual);
    assert.equal(await annual.locator('.payment-range-table tbody tr').count(),12);
    assert.equal(await annual.locator('#form-bayar').evaluate(form=>form.scrollWidth<=form.clientWidth+2),true);
    await annual.evaluate(() => scrollTo(0,0));
    await annual.screenshot({path:path.join(artifacts,'desktop-1920-dark.png'),fullPage:true});
    await post(admin,base,await input(annual));
    const yearPayment = state('annual').payments[0];
    assert.equal(yearPayment.allocations.length,12);
    assert.deepEqual([yearPayment.range.mulai,yearPayment.range.akhir],['2027-07','2028-06']);
    assert.equal(yearPayment.range.mode,'range');
    await oneReceipt(await admin.newPage(),yearPayment);
    cli('historical_annual',String(yearPayment.id));
    const historical = await supervisor.newPage();
    await historical.goto(base+'/pembayaran/edit.php?id='+yearPayment.id);
    await ready(historical);
    assert.equal(await activeMode(historical).inputValue(),'academic');
    assert.equal(await historical.locator('.payment-range-table tbody tr').count(),12);
    await historical.selectOption('[name=sistem_pembayaran]','VA');
    await Promise.all([historical.waitForNavigation(),historical.getByRole('button',{name:'Simpan Koreksi'}).click()]);
    assert.equal(state('annual').payments[0].range.mode,'academic');
    assert.equal(state('annual').payments[0].sistem_pembayaran,'VA');

    const arrears = await openForm(admin);
    await choose(arrears,'arrears','range','2026-12',true);
    await ready(arrears);
    assert.equal(await activeMode(arrears).inputValue(),'range');
    assert.equal(await arrears.locator('[data-payment-period-oldest]').innerText(),'Oktober 2026');
    const arrearsInput = await input(arrears);
    const blocked = {...arrearsInput,period_version:'a'.repeat(64)};
    await post(admin,base,blocked);
    assert.equal(state('arrears').payments.length,0);
    await post(admin,base,arrearsInput);
    assert.deepEqual(state('arrears').payments[0].allocations.map(row=>row.period),['2026-10','2026-11','2026-12']);

    const extras = await openForm(admin);
    await chooseMode(extras,'range');
    await selectStudent(extras,'extras',true);
    await cashSetup(extras);
    await extras.locator('[data-payment-period-status]').filter({hasText:'Belum ada tagihan SPP atau Komite'}).waitFor();
    assert.equal(await extras.locator('#btn-input').isDisabled(),true);
    await enterAdditionalFees(extras,'input_manual');
    assert.equal(await extras.locator('#payment-period-choice').inputValue(),'');
    assert.equal(Number(await extras.locator('#hidden-total').inputValue()),77346);
    await Promise.all([extras.waitForNavigation(),extras.locator('#btn-input').click()]);
    const extrasPayment = state('extras').payments[0];
    assert.equal(extrasPayment.range,null);
    assert.deepEqual([extrasPayment.BULAN,extrasPayment.TAHUN],['11','2026']);
    assert.equal(Number(extrasPayment.total_jumlah),77346);
    assert.equal(Number(extrasPayment.du[0].jumlah),25000.50);
    assert.equal(Number(extrasPayment.other[0].nominal_snapshot),12345.50);

    const three = await openForm(admin);
    await choose(three,'three','range','2027-01');
    await ready(three);
    await post(admin,base,await input(three));
    assert.equal(state('three').payments[0].allocations.length,3);

    const missing = await openForm(admin);
    await choose(missing,'missing','range','2027-01');
    await missing.waitForFunction(() => document.querySelector('[data-payment-period-status]').textContent.includes('Desember 2026'));
    assert.ok(await missing.locator('[data-bill-state=missing]').count() > 0);
    assert.equal(await missing.locator('[data-payment-period-count]').isVisible(),false);
    assert.equal(await missing.locator('#btn-input').isDisabled(),true);
    assert.equal(await missing.locator('[data-payment-period-version]').inputValue(),'');

    const monthly = await openForm(admin);
    await selectStudent(monthly,'monthly');
    await cashSetup(monthly);
    const perMonth = {...await input(monthly),bulan_bayar:'11',tahun_bayar:'2026',uang_spp:'250000,25',uang_komite:'50000'};
    await post(admin,base,perMonth);
    const monthPayment = state('monthly').payments[0];
    assert.equal(monthPayment.range,null);
    assert.equal(Number(monthPayment.total_jumlah),300000.25);
    await choose(monthly,'monthly','range','2027-01');
    await ready(monthly);
    assert.equal(await monthly.locator('[data-payment-period-oldest]').innerText(),'Desember 2026');
    assert.equal(await monthly.locator('.payment-range-table tbody tr').count(),2);
    console.log('PASS 12-month range, historical annual correction, arrears, additional fees without a range, and monthly checkout');

    const a = await openForm(admin);
    const b = await openForm(supervisor,alternate);
    await choose(a,'race','range','2026-12');
    await choose(b,'race','range','2026-12');
    await Promise.all([ready(a),ready(b)]);
    const payloads = await Promise.all([input(a),input(b)]);
    await Promise.all([post(admin,base,payloads[0]),post(supervisor,alternate,payloads[1])]);
    assert.equal(state('race').payments.length,1);
    const rollback = await openForm(admin);
    await choose(rollback,'rollback','range','2026-12');
    await ready(rollback);
    await rollback.locator('#psb-input').fill('10000');
    const rollbackInput = await input(rollback);
    cli('inject_failure',fixture.cases.rollback.nis);
    try {
      await post(admin,base,rollbackInput);
      assert.equal(state('rollback').payments.length,0);
    } finally {
      cli('remove_failure');
    }
    await post(admin,base,rollbackInput);
    assert.equal(state('rollback').payments.length,1);
    assert.equal(Number(state('rollback').payments[0].U_PSB),10000);
    console.log('PASS concurrent checkout, atomic rollback and retry');

    const edit = await cashier.newPage();
    await edit.setViewportSize({width:1280,height:900});
    await edit.goto(base+'/pembayaran/edit.php?id='+initial.id);
    await ready(edit);
    assert.equal(await edit.locator('[name=no_induk]').inputValue(),fixture.cases.main.nis);
    assert.equal(await edit.locator('[name=no_induk]').evaluate(field=>field.readOnly),true);
    assert.equal(await edit.locator('.payment-edit').evaluate(card=>card.scrollWidth<=card.offsetWidth+2),true);
    const wrongStudent = fixture.cases.annual.nis;
    const wrongPreview = await cashier.request.get(base+'/pembayaran/pratinjau_periode.php?'+new URLSearchParams({
      unit_id:'1',no_induk:wrongStudent,mode:'range',choice:'2027-04',edit_id:initial.id,
    }));
    assert.equal(wrongPreview.status(),422);
    const beforeRejectedRangeEdit = state('main');
    const rangePayload = await edit.locator('form.payment-period-enabled').evaluate(form=>Object.fromEntries(new FormData(form)));
    await post(cashier,base,{...rangePayload,no_induk:wrongStudent,authorization_reason:'Identitas siswa yang berbeda'});
    assert.deepEqual(state('main'),beforeRejectedRangeEdit);

    const monthlyEdit = await supervisor.newPage();
    await monthlyEdit.setViewportSize({width:1440,height:900});
    await monthlyEdit.goto(base+'/pembayaran/edit.php?id='+monthPayment.id);
    assert.equal(await monthlyEdit.locator('[name=no_induk]').inputValue(),fixture.cases.monthly.nis);
    assert.equal(await monthlyEdit.locator('[name=no_induk]').evaluate(field=>field.readOnly),true);
    assert.equal(await monthlyEdit.locator('#siswa-search').count(),0);
    assert.equal(await monthlyEdit.locator('.payment-edit').evaluate(card=>card.scrollWidth<=card.offsetWidth+2),true);
    const wrongMonthlyStatus = await supervisor.request.get(base+'/pembayaran/status_spp.php?'+new URLSearchParams({
      unit_id:'1',no_induk:wrongStudent,bulan:'11',tahun:'2026',edit_id:monthPayment.id,
    }));
    assert.equal(wrongMonthlyStatus.status(),422);
    const beforeRejectedMonthlyEdit = state('monthly');
    await post(supervisor,base,{...await input(monthlyEdit),no_induk:wrongStudent});
    assert.deepEqual(state('monthly'),beforeRejectedMonthlyEdit);
    await monthlyEdit.reload();
    const rejectedMonthly = monthlyEdit.locator('#operation-feedback-dialog[open]');
    await rejectedMonthly.getByText('Siswa pada transaksi ini tidak dapat diubah.',{exact:false}).waitFor();
    await rejectedMonthly.getByRole('button',{name:'Mengerti'}).click();
    assert.equal(await monthlyEdit.locator('[name=no_induk]').inputValue(),fixture.cases.monthly.nis);
    assert.equal(await monthlyEdit.locator('#spp-input').inputValue(),'250.000,25');
    await monthlyEdit.selectOption('[name=sistem_pembayaran]','VA');
    await Promise.all([monthlyEdit.waitForNavigation(),monthlyEdit.locator('#btn-update').click()]);
    const monthlyCorrected = state('monthly').payments[0];
    assert.equal(monthlyCorrected.id,monthPayment.id);
    assert.equal(monthlyCorrected.sistem_pembayaran,'VA');
    assert.equal(monthlyCorrected.total_jumlah,monthPayment.total_jumlah);
    await monthlyEdit.close();

    await edit.reload();
    const rejectedEdit = edit.locator('#operation-feedback-dialog[open]');
    await rejectedEdit.getByText('Siswa pada transaksi ini tidak dapat diubah.', {exact:false}).waitFor();
    await rejectedEdit.getByRole('button',{name:'Mengerti'}).click();
    await ready(edit);
    assert.equal(await edit.locator('[name=no_induk]').inputValue(),fixture.cases.main.nis);
    await edit.selectOption('#payment-period-choice','2027-04');
    await ready(edit);
    await edit.fill('#period-edit-reason','Koreksi periode menjadi enam bulan');
    await edit.selectOption('[name=sistem_pembayaran]','VA');
    assert.equal(await edit.locator('form.payment-period-enabled').evaluate(form=>form.checkValidity()),true);
    const [editResponse] = await Promise.all([edit.waitForNavigation(),edit.getByRole('button',{name:'Ajukan Koreksi'}).click()]);
    assert.ok(editResponse.ok(),await editResponse.text());
    let pending = state('main').requests.at(-1);
    assert.equal(pending.status,'pending');
    assert.equal(state('main').payments[0].allocations.length,4);
    const denied = await post(cashier,base,{aksi:'otorisasi_setujui',request_id:pending.id,_operational_unit:'1'});
    assert.equal(denied.status(),403);
    await approve(supervisor,pending.id);
    const corrected = state('main').payments[0];
    assert.equal(corrected.id,initial.id);
    assert.equal(corrected.sistem_pembayaran,'VA');
    assert.equal(corrected.allocations.length,6);
    assert.equal(corrected.U_PSB,initial.U_PSB);
    assert.deepEqual(corrected.du,initial.du);
    assert.deepEqual(corrected.other,initial.other);
    const historyView = await supervisor.newPage();
    await historyView.setViewportSize({width:1620,height:940});
    await historyView.goto(base+'/pembayaran/lihat.php?all_periods=1&selected='+initial.id+'&search='+fixture.cases.main.nis);
    const serverFeedback = historyView.locator('#operation-feedback-dialog[open]');
    if(await serverFeedback.isVisible()) await serverFeedback.getByRole('button',{name:'Mengerti'}).click();
    const allocation = historyView.locator('.ph-monthly-allocation');
    assert.equal(await allocation.locator('tbody tr').count(),6);
    assert.ok(await allocation.evaluate(section => {
      const systemInfo = section.parentElement.querySelector('.ph-info-grid');
      return section.getBoundingClientRect().top - systemInfo.getBoundingClientRect().bottom >= 15;
    }));
    assert.ok(await historyView.locator('[data-payment-record][aria-current="true"]').evaluate(card => {
      const period = document.createRange();
      period.selectNodeContents(card.querySelector('.ph-record-period > span'));
      const label = period.getBoundingClientRect();
      const total = card.querySelector('.ph-record-total').getBoundingClientRect();
      return label.right <= total.left || label.bottom <= total.top || label.top >= total.bottom;
    }));
    await historyView.locator('.open-payment-activity').click();
    const activity = historyView.locator('#payment-activity-dialog');
    await activity.locator('.payment-activity-values ul').first().waitFor();
    assert.ok(await activity.locator('.payment-activity-values li').count()>=10);
    await historyView.close();
    await oneReceipt(receipt,corrected);
    const reports = JSON.parse(cli('reports',fixture.cases.main.nis));
    assert.equal(reports.receipts.length,1);
    assert.equal(reports.receipts[0].total_penerimaan,1885002);
    assert.equal(reports.monthly.spp['2027-02'],250000.25);
    assert.equal(reports.monthly.komite['2027-04'],50000);

    const params = new URLSearchParams({tanggal_awal:'2026-11-23',tanggal_akhir:'2026-11-23',q:fixture.cases.main.nis});
    const report = await admin.newPage();
    await report.goto(base+'/laporan/index.php?'+params);
    const reportPeriod = report.locator('tbody .report-period-col');
    assert.equal(await reportPeriod.innerText(),'11 2026 – 04 2027');
    assert.equal(await reportPeriod.locator('.report-period-default').textContent(),'November 2026 – April 2027');
    const sheet = await (await admin.request.get(base+'/laporan/export_excel.php?'+params+'&download=1')).body();
    assert.equal(sheet.subarray(0,2).toString(),'PK');
    const spreadsheetPath = path.join(artifacts,'rekap-transaksi.xlsx');
    fs.writeFileSync(spreadsheetPath,sheet);
    assert.ok(JSON.parse(cli('xlsx_rows',spreadsheetPath)).some(row => row.includes('November 2026 – April 2027')));

    const statusParams = new URLSearchParams({template:'status',unit:'1',rekap:'1',tahun_ajaran:'2026/2027',q:fixture.cases.main.nis});
    const expectedPeriods = ['Juli 2026','Agustus 2026','September 2026','Oktober 2026','November 2026','Desember 2026','Januari 2027','Februari 2027','Maret 2027','April 2027','Mei 2027','Juni 2027'];
    const columnPeriods = async () => (await report.locator('.report-horizontal-matrix th[scope=colgroup]').allTextContents()).map(label => label.split(' · ').at(-1));
    statusParams.set('kategori','spp');
    await report.goto(base+'/laporan/template.php?'+statusParams+'&bulan_awal%5B%5D=*');
    assert.deepEqual(await columnPeriods(),expectedPeriods);
    statusParams.set('kategori','komite');
    for (const value of ['01','12','11']) statusParams.append('bulan_awal[]',value);
    await report.goto(base+'/laporan/template.php?'+statusParams);
    assert.deepEqual(await columnPeriods(),expectedPeriods.slice(4,7));
    const statusExcel = await report.getByRole('link',{name:'Excel',exact:true}).getAttribute('href');
    const statusSheet = await (await admin.request.get(base+'/laporan/'+statusExcel+'&download=1')).body();
    assert.equal(statusSheet.subarray(0,2).toString(),'PK');
    const statusPath = path.join(artifacts,'status-pembayaran.xlsx');
    fs.writeFileSync(statusPath,statusSheet);
    const headings = JSON.parse(cli('xlsx_rows',statusPath)).flat().filter(cell => typeof cell === 'string' && /^Komite .+2026\/2027 /i.test(cell));
    assert.deepEqual(headings.map(label => label.match(/\b(?:Nov|Des|Jan) 202[67]$/)?.[0]),['Nov 2026','Des 2026','Jan 2027']);
    const tokenPage = await openForm(cashier);
    const token = await tokenPage.locator('#form-bayar input[name=csrf_token]').inputValue();
    await post(cashier,base,{aksi:'hapus',id:initial.id,_operational_unit:'1',csrf_token:token,authorization_reason:'Pembatalan seluruh kuitansi uji'});
    pending = state('main').requests.at(-1);
    assert.equal(pending.status,'pending');
    await approve(supervisor,pending.id);
    const cancelled = state('main');
    assert.equal(cancelled.payments.length,0);
    assert.ok(cancelled.events.some(event=>event.action==='deleted'&&event.payment_id===initial.id));
    const archived = await openForm(cashier);
    await archived.goto(base+'/pembayaran/lihat.php?view=deleted&all_periods=1&search='+fixture.cases.main.nis);
    const archivedPeriod = archived.locator('[data-payment-record][aria-current="true"] .ph-period-numeric');
    assert.equal(await archivedPeriod.isVisible(),true);
    assert.equal(await archivedPeriod.innerText(),'11 2026 – 04 2027');
    assert.deepEqual(errors,[]);
    console.log('PASS authorization, full cancellation, stable receipt number and reports');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode=1; });
