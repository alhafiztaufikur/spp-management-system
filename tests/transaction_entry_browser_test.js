'use strict';
// Run the fixture on a verified restored server, with the same private session.save_path as HTTP.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const manifestPath = process.env.SPP_TEST_ISOLATED_INSTANCE;
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
assert.ok(manifestPath);
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8').replace(/^\uFEFF/, ''));
const root = path.dirname(manifestPath);
const fixture = JSON.parse(fs.readFileSync(path.join(root, 'entry-fixture.json'), 'utf8'));
const base = process.env.SPP_TEST_BASE_URL;
assert.ok(/^http:\/\/127\.0\.0\.1:\d+$/.test(base));
const today = new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Jakarta'}).format(new Date());
const days = ['2020-01-02', today, '2028-02-29', '2030-12-31'];
let checks = 0;
const eq = (actual, expected, message) => {assert.deepEqual(actual, expected, message); checks++;};
const state = () => JSON.parse(execFileSync(process.env.SPP_PHP_BIN || 'php', [path.join(__dirname,'transaction_entry_fixture.php'), 'state'], {encoding:'utf8',env:process.env}));
const post = (context, route, values) => context.request.post(base+route, {
  data:new URLSearchParams(values).toString(), headers:{'Content-Type':'application/x-www-form-urlencoded'}, maxRedirects:0
});
async function fields(page, selector) {
  return page.locator(selector).evaluate(form => Object.fromEntries(new FormData(form).entries()));
}
async function selectStudent(page, nis) {
  await page.locator('#siswa-search').fill(nis);
  await page.waitForFunction(value => document.getElementById('disp-nis').value === value, nis);
  await page.waitForFunction(() => !document.querySelector('#spp-input')?.dataset.loading);
}
async function paymentInput(page, unit, day) {
  await page.goto(base+'/pembayaran/form.php');
  const values = await fields(page, '#form-bayar');
  return {...values, no_induk:fixture.units[unit].nis, tanggal_bayar:day,
    bulan_bayar:'11', tahun_bayar:'2026', sistem_pembayaran:'Tunai', uang_psb:'1000',
    uang_spp:'0', uang_komite:'0', uang_du:'0', potongan_spp:'0', catatan:'QA custom date payment'};
}
(async () => {
  const browser = await chromium.launch({channel:process.env.SPP_UI_BROWSER || 'chrome',headless:true});
  const errors = [];
  const initial = state();
  const contexts = {};
  try {
    for (const unit of [1,2,3]) {
      const context = await browser.newContext();
      contexts[unit] = context;
      await context.route('https://fonts.googleapis.com/**', route => route.abort());
      await context.addCookies([{name:'PHPSESSID',value:fixture.sessions[unit],url:base}]);
      const identity = await (await context.request.get(base+'/tests/browser_clone_identity.php')).json();
      eq(identity.server_uuid,manifest.server_uuid,'HTTP UUID matches owned clone');
      eq(identity.port,manifest.port,'HTTP port matches owned clone');
      const page = await context.newPage();
      page.on('pageerror',error => errors.push(error.message));
      await page.goto(base+'/pembayaran/form.php');
      eq(await page.locator('#tgl-bayar-display').isEditable(),true,'Payment cash date is editable');
      eq(await page.locator('#tgl-bayar').inputValue(),today,'Payment date defaults to Jakarta today');

      if (unit === 2) {
        await page.locator('#siswa-search').fill('Aditya Pratama');
        eq(await page.locator('#disp-nis').inputValue(),'','Ambiguous name never selects first student');
        for (const [nis,kelas] of [['252607102','8C'],['242507056','9C']]) {
          await page.locator('#siswa-search').fill('Aditya Pratama');
          await page.locator('.student-search-option').filter({hasText:'NIS '+nis}).click();
          eq(await page.locator('#disp-nis').inputValue(),nis,'Clicked duplicate has correct NIS');
          eq(await page.locator('#disp-kelas').inputValue(),kelas,'Clicked duplicate has correct class');
          await page.locator('#siswa-search').fill(nis);
          await page.locator('#siswa-search').press('Enter');
          eq(await page.locator('#disp-nis').inputValue(),nis,'Keyboard and exact NIS preserve identity');
        }
        await page.route('**/status_spp.php?**',async route => {
          if(new URL(route.request().url()).searchParams.get('no_induk')==='242507056') await new Promise(resolve=>setTimeout(resolve,250));
          try {await route.continue();} catch (_) {}
        });
        await selectStudent(page,'242507056');
        await selectStudent(page,'252607102');
        await page.waitForTimeout(400);
        eq(await page.locator('#disp-nis').inputValue(),'252607102','Late response keeps selected NIS');
        eq(await page.locator('#disp-kelas').inputValue(),'8C','Late response keeps selected class');
        await page.unroute('**/status_spp.php?**');
        await selectStudent(page,'242507088');
        await page.selectOption('#bulan-bayar','11');
        await page.locator('#tahun-bayar').dispatchEvent('change');
        await page.waitForFunction(()=>document.getElementById('spp-context-label').textContent==='SPP November 2026');
        eq(await page.locator('#spp-sisa').inputValue(),'655.000','Achmad outstanding amount is unchanged');
        eq(await page.locator('#komite-context-label').innerText(),'Komite November 2026','Komite label has no false settlement claim');
      }

      // Exercise actual component rendering for outstanding, partial, settled and unpublished bills.
      await selectStudent(page,fixture.units[unit].nis);
      for (const paid of [0,500,1000,null]) {
        const labels = await page.evaluate(paid => {
          const opt=selectedStudentOption(),period=selectedPaymentPeriod();
          sppStatusState.contextKey='';
          opt.dataset.sppBilling=JSON.stringify({tagihan:paid===null?[]:[{bulan:period.slice(0,2),tahun:period.slice(3),total:1000,paid}]});
          opt.dataset.komiteBilling=JSON.stringify(paid===null?{}:{[period]:{total:1000,paid}});
          refreshPublishedSppUi(opt);
          return ['spp','komite'].map(key=>document.getElementById(key+'-context-label').textContent);
        },paid);
        eq(labels.some(label=>label.includes('lunas penuh')),false,'Labels agree with all balance states, unit '+unit);
      }

      const unitDays = unit===1 ? days : [today];
      for (const day of unitDays) {
        const values = await paymentInput(page,unit,day);
        const response = await post(context,'/pembayaran/proses.php',values);
        eq(response.status(),302,'Payment save redirects');
        eq(response.headers().location,'lihat.php','Payment accepted with custom date '+day);
        const stored = state().units[unit].payments.at(-1);
        eq(stored.TGL_BYR.slice(0,10),day,'Stored payment cash day');
        eq([stored.BULAN,stored.TAHUN],['11','2026'],'Cash date does not change billing period');
        const receipt = await context.request.get(base+'/laporan/cetak_struk.php?id='+stored.id);
        eq(receipt.status(),200,'Payment receipt loads');
        eq((await receipt.text()).includes(day.split('-').reverse().join('-')),true,'Payment receipt shows chosen date');
        const history = await (await context.request.get(base+'/pembayaran/history_siswa.php?'+new URLSearchParams({no_induk:fixture.units[unit].nis,student_id:fixture.units[unit].student_id,unit_id:unit,scope:'all'}))).json();
        eq(history.rows.find(row=>row.id===Number(stored.id)).tanggal_raw.slice(0,10),day,'Payment history uses chosen cash day');
        const count=state().units[unit].payments.length;
        await post(context,'/pembayaran/proses.php',values);
        eq(state().units[unit].payments.length,count,'Payment replay does not duplicate');
      }

      const invalid=await paymentInput(page,unit,'2028-02-29');
      invalid.sistem_pembayaran='invalid';
      await post(context,'/pembayaran/proses.php',invalid);
      await page.goto(base+'/pembayaran/form.php');
      eq(await page.locator('#tgl-bayar').inputValue(),'2028-02-29','Failure restores chosen cash date');
      eq(await page.locator('#tgl-bayar-display').inputValue(),'29/02/2028','Failure restores visible date');
      eq(await page.locator('#disp-nis').inputValue(),fixture.units[unit].nis,'Failure restores selected identity');
      await page.locator('#tgl-bayar-display').fill('31/12/2030');
      await page.locator('#tgl-bayar-display').dispatchEvent('change');
      eq(await page.locator('#tahun-bayar').inputValue(),'2026','Changing date leaves billing year intact');
      await page.evaluate(()=>sessionStorage.setItem('paymentDraftReload',JSON.stringify(capturePaymentDraftInBrowser())));
      await page.reload();
      eq(await page.locator('#tgl-bayar-display').inputValue(),'31/12/2030','Browser reload restores visible custom date');

      for (const day of unitDays) {
        for (const kind of ['masuk','keluar']) {
          await page.goto(base+'/tabungan/'+kind+'.php?student_id='+fixture.units[unit].student_id);
          eq(await page.locator('input[name=tanggal]').isEditable(),true,'Savings date is editable');
          const values={...await fields(page,'[data-savings-entry]'),tanggal:day,nominal:kind==='masuk'?'1000':'100',keterangan:'QA custom date student'};
          const response=await post(context,'/tabungan/proses.php',values);
          eq(response.status(),302,'Savings save redirects');
          assert.match(response.headers().location,/^riwayat\.php/,'Savings accepted: '+await response.text());checks++;
          const saved=state().units[unit][kind].at(-1);
          eq(saved.TANGGAL.slice(0,10),day,'Savings stores chosen cash day');
          const receipt=await context.request.get(base+'/tabungan/cetak_struk.php?jenis='+kind+'&id='+saved.id);
          eq(receipt.status(),200,'Savings receipt loads');
          eq((await receipt.text()).includes(day.split('-').reverse().join('-')),true,'Savings receipt shows chosen date');
          const count=state().units[unit][kind].length;
          await post(context,'/tabungan/proses.php',values);
          eq(state().units[unit][kind].length,count,'Savings replay leaves one transaction');
        }
      }
      eq(Number(state().units[unit].balance),Number(initial.units[unit].balance)+900*unitDays.length,'Savings balance matches exact cash movements');
    }

    const context=contexts[1],page=await context.newPage();
    page.on('pageerror',error=>errors.push(error.message));
    const owner=fixture.account.id;
    for (const day of days) for (const kind of ['masuk','keluar']) {
      await page.goto(base+'/tabungan/'+kind+'.php?owner_id='+owner);
      const values={...await fields(page,'[data-savings-entry]'),tanggal:day,nominal:kind==='masuk'?'1000':'100',keterangan:'QA custom date account'};
      const response=await post(context,'/tabungan/proses.php',values);
      assert.match(response.headers().location,/^riwayat\.php/,'Non-student custom date accepted');checks++;
      const saved=state().account.journals.at(-1);
      eq(saved.source_date.slice(0,10),day,'Non-student journal uses selected date');
      eq(saved.source_date.slice(11),values.request_time.slice(11),'Non-student request clock stays stable');
      const count=state().account.journals.length;
      await post(context,'/tabungan/proses.php',values);
      eq(state().account.journals.length,count,'Non-student retry creates no extra journal');
      const receipt=await context.request.get(base+'/tabungan/cetak_struk.php?jenis='+kind+'&id='+(-1000000000-Number(saved.id)));
      eq(receipt.status(),200,'Non-student receipt loads');
      eq((await receipt.text()).includes(day.split('-').reverse().join('-')),true,'Non-student receipt shows chosen date');
    }
    eq(Number(state().account.balance),Number(initial.account.balance)+days.length*900,'Non-student balance matches new transactions exactly');

    for (const route of ['payment','savings']) for (const day of ['', '2026-02-29',null,[]]) {
      let values,url;
      if(route==='payment'){values=await paymentInput(page,1,today);url='/pembayaran/proses.php';}
      else {await page.goto(base+'/tabungan/masuk.php?owner_id='+owner);values={...await fields(page,'[data-savings-entry]'),nominal:'1000'};url='/tabungan/proses.php';}
      const key=route==='payment'?'tanggal_bayar':'tanggal';
      delete values[key];
      if(Array.isArray(day))values[key+'[]']=today;
      else if(day!==null)values[key]=day;
      const before=state();
      const response=await post(context,url,values);
      eq(response.status(),302,'Invalid date rejected safely');
      eq(state(),before,'Invalid date cannot write money or balances');
    }
    await page.goto(base+'/tabungan/keluar.php?owner_id='+owner);
    const bad={...await fields(page,'[data-savings-entry]'),tanggal:'2028-02-29',nominal:'9999999999999'};
    await post(context,'/tabungan/proses.php',bad);
    await page.goto(base+'/tabungan/keluar.php?owner_id='+owner);
    eq(await page.locator('input[name=tanggal]').inputValue(),'2028-02-29','Savings failure retains custom date');
    const wrongUnit=await paymentInput(page,1,today);wrongUnit._operational_unit='2';
    eq((await post(context,'/pembayaran/proses.php',wrongUnit)).status(),409,'Unit mismatch remains blocked');
    eq(errors,[],'No uncaught JavaScript errors');
    fs.writeFileSync(path.join(root,'entry-browser-result.json'),JSON.stringify({checks,errors,state:state()},null,2));
    console.log('PASS '+checks+' browser and HTTP transaction checks.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
