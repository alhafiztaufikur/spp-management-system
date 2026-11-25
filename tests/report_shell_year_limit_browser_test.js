'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.SPP_TEST_BASE_URL;
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
const fixture = JSON.parse(fs.readFileSync(process.env.SPP_PAYMENT_PERIOD_FIXTURE, 'utf8'));
const manifest = JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE, 'utf8'));
const templates = ['psb','status','penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','riwayat-tagihan','setoran','kas-tabungan'];

(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const context = await browser.newContext({viewport: {width: 1440,height: 960}});
    const identity = await (await context.request.get(base+'/tests/browser_clone_identity.php')).json();
    assert.equal(identity.server_uuid, manifest.server_uuid);
    assert.notEqual(identity.port, 3306);
    await context.addCookies([{name:'PHPSESSID',value:fixture.sessions.super_admin,url:base}]);
    const reportUrl = name => base+'/laporan/template.php?template='+name+'&unit=1';
    for (const name of templates) {
      const response = await context.request.get(reportUrl(name));
      assert.equal(response.status(), 200);
      const html = await response.text();
      assert.match(html, /report-global-filter-form/);
      assert.match(html, /name="rekap" value="1"/);
      assert.match(html, /class="report-skeleton"/);
      assert.doesNotMatch(html, /data-report-results|report-export-btn/);
      assert.doesNotMatch(html, /data-student-id="/);
    }
    const fallback = await (await context.request.get(reportUrl('status')+'&filter_options=1')).text();
    assert.match(fallback, /class="report-skeleton"/);
    assert.match(fallback, /name="rekap" value="1"/);
    assert.doesNotMatch(fallback, /data-report-results|report-export-btn/);
    const page = await context.newPage();
    const errors = [];
    const optionsRequests = [];
    page.on('request', request => {
      const group = new URL(request.url()).searchParams.get('filter_group');
      if (group) optionsRequests.push(group);
    });
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(reportUrl('status'));
    let run = page.getByRole('button',{name:'Tampilkan Rekap'});
    await run.waitFor();
    assert.equal(await page.locator('[data-report-placeholder]').isVisible(), true);
    assert.deepEqual(optionsRequests,[]);

    let releaseOptions;
    const waiting = new Promise(resolve => { releaseOptions = resolve; });
    await page.route('**/*filter_group=kategori', async route => {
      await waiting;
      await route.continue();
    });
    const category = page.locator('select[data-report-options="kategori"]');
    const categoryTrigger = category.locator('..').getByRole('button');
    await categoryTrigger.press('ArrowDown');
    const categoryPanel = page.getByRole('dialog',{name:'Kategori'});
    await categoryPanel.getByText('Memuat pilihan historis…').waitFor();
    await categoryPanel.getByRole('checkbox',{name:'Komite',exact:true}).check();
    releaseOptions();
    await categoryPanel.getByText('Memuat pilihan historis…').waitFor({state:'hidden',timeout:90000});
    assert.equal(await categoryPanel.getByRole('checkbox',{name:'Komite',exact:true}).isChecked(),true);
    await categoryPanel.getByRole('button',{name:'Batal'}).click();
    assert.deepEqual(optionsRequests,['kategori']);

    const studentSearch = page.locator('#global-siswa-search');
    await studentSearch.fill(fixture.cases.main.nis);
    await page.locator('.student-search-option').first().waitFor();
    await studentSearch.press('ArrowDown');
    await page.keyboard.press('Enter');
    assert.ok(Number(await page.locator('input[name="student_id"]').inputValue())>0);
    assert.equal(await page.locator('#global-student-query').inputValue(),fixture.cases.main.nis);

    await Promise.all([page.waitForURL(url => url.searchParams.get('rekap') === '1',{timeout:90000}),run.click()]);
    await page.locator('[data-report-results]').waitFor({state:'attached'});
    assert.equal(await page.locator('[data-report-placeholder]').isVisible(), false);
    assert.equal(await page.getByRole('link',{name:'Excel',exact:true}).isVisible(), true);
    const unitLink = page.locator('[data-report-unit="2"]');
    const nextUrl = new URL(await unitLink.getAttribute('href'), page.url());
    assert.equal(nextUrl.searchParams.has('rekap'), false);
    await page.setViewportSize({width:390,height:850});
    await page.goto(nextUrl.href);
    await page.evaluate(() => document.documentElement.dataset.theme = 'dark');
    const skeleton = page.locator('.report-skeleton');
    assert.equal(await skeleton.isVisible(), true);
    assert.ok(await skeleton.evaluate(node => node.getBoundingClientRect().right <= window.innerWidth));

    const noScript = await browser.newContext({javaScriptEnabled:false});
    await noScript.addCookies([{name:'PHPSESSID',value:fixture.sessions.super_admin,url:base}]);
    const plainPage = await noScript.newPage();
    await plainPage.goto(reportUrl('status'));
    const completeFilters = plainPage.getByRole('link',{name:'Muat pilihan filter lengkap'});
    assert.equal(await completeFilters.isVisible(),true);
    await plainPage.goto(new URL(await completeFilters.getAttribute('href'),plainPage.url()).href);
    assert.equal(await plainPage.locator('input[name="q"]:not([disabled])').count(),1);
    assert.equal(await plainPage.getByRole('button',{name:'Tampilkan Rekap'}).isVisible(),true);
    await noScript.close();

    await page.setViewportSize({width:1440,height:960});
    for (const [route, selector, component] of [
      ['master_spp.php','#spp-year-choice','SPP'],
      ['master_daftar_ulang.php','#du-year-select','Daftar Ulang'],
    ]) {
      await page.goto(base+'/'+route);
      const select = page.locator(selector);
      const current = await select.inputValue();
      const dialog = page.locator('#master-year-limit');
      const max = await dialog.getAttribute('data-max-year');
      const start = Number(max.split('/')[0]);
      const future = `${start+1}/${start+2}`;
      const further = `${start+2}/${start+3}`;
      await select.selectOption(future);
      await dialog.waitFor();
      assert.match(await dialog.innerText(),new RegExp(component+' tersedia hingga'));
      await page.keyboard.press('Escape');
      await dialog.waitFor({state:'hidden'});
      assert.equal(await select.inputValue(),current);
      await select.selectOption(future);
      await Promise.all([
        page.waitForURL(url => url.searchParams.get('tahun') === max),
        dialog.getByRole('link',{name:'Buka '+max,exact:true}).click(),
      ]);
      if (component === 'SPP') {
        await page.getByRole('link',{name:'Tahun ajaran berikutnya'}).click();
        assert.equal(await dialog.isVisible(),true);
        await page.keyboard.press('Escape');
      }
      const rateForm = component === 'SPP' ? '#spp-rate-form' : '#du-rate-form';
      const csrf = await page.locator(rateForm+' input[name="csrf_token"]').inputValue();
      const denied = await context.request.post(base+'/'+route,{maxRedirects:0,form:{
        aksi:'terbitkan',tahun_ajaran:further,csrf_token:csrf,_operational_unit:'1',
      }});
      assert.equal(denied.status(),303);
      const notice = new URL(denied.headers().location,base+'/');
      assert.equal(notice.searchParams.get('tahun'),further);
      await page.goto(notice.href);
      await page.locator('#master-year-limit[open]').waitFor();
      assert.equal(await page.locator(selector).count(),0);
    }
    assert.deepEqual(errors,[]);
    console.log('PASS Global report shells, filter submit, scopes, mobile, and both academic year guards.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error);process.exitCode=1; });
