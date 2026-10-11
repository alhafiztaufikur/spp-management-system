/* Focused matrix/preview checks after the complete nine-report responsive suite. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = process.env.SPP_HTTP_BASE, dir = process.env.SPP_QA_DIR;
assert.match(process.env.SPP_DB_NAME || '', /^db_spp_audit_/);
assert.match(base || '', /^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const sessions = JSON.parse(fs.readFileSync(dir + '/sessions.json', 'utf8'));
const baseline = JSON.parse(fs.readFileSync(dir + '/baseline.json', 'utf8'));
const query = params => {const q = new URLSearchParams(); for (const [key, value] of Object.entries(params)) Array.isArray(value) ? value.forEach(v => q.append(key + '[]', v)) : q.set(key, value); return q;};
(async () => {
  const browser = await chromium.launch({channel:'chrome', headless:true});
  try {
    const context = await browser.newContext({viewport:{width:1600,height:1000}});
    await context.addCookies([{name:'PHPSESSID',value:sessions.super_admin,url:base}]);
    assert.equal((await (await context.request.get(base + '/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
    const page = await context.newPage(); const errors=[];page.on('pageerror',e=>errors.push(e.message));let count=0;
    for(const unit of ['1','2','3','0']) for(const key of ['status-multi','status-periods','penerimaan-multi','per-item-multi','spp-tahunan']) {
      if(process.env.SPP_MATRIX_BROWSER_CASE && process.env.SPP_MATRIX_BROWSER_CASE!==unit+'/'+key)continue;
      const params={...baseline.cases[unit+'/'+key]};
      if(key==='spp-tahunan')params.tahun_ajaran=baseline.cases[unit+'/status-periods'].tahun_ajaran;
      await page.goto(base+'/laporan/template.php?'+query(params));
      assert.doesNotMatch(await page.locator('body').innerText(),/Fatal error|Warning:|Gagal memuat laporan/);
      assert.equal(await page.locator('.report-horizontal-matrix').count(),1);
      for(const width of [1600,900,390])for(const theme of ['light','dark']) {
        await page.setViewportSize({width,height:1000});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
        const scroll=page.locator('.report-matrix-scroll');await scroll.scrollIntoViewIfNeeded();await scroll.evaluate(e=>e.scrollLeft=0);
        const nis=page.locator('.report-horizontal-matrix tbody .matrix-nis').first();
        if(await nis.count()) {
          const before=await nis.boundingBox();await scroll.evaluate(e=>e.scrollLeft=e.scrollWidth);const after=await nis.boundingBox();
          const bounds=await scroll.boundingBox();
          assert.ok(width<=650?after.x>=bounds.x-2&&after.x+after.width<=bounds.x+bounds.width+2:Math.abs(before.x-after.x)<2,'NIS remains visible '+unit+'/'+key+'/'+width+' '+JSON.stringify({before,after,bounds}));
        }
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Only the table scrolls');
        if(unit==='1'&&key==='status-multi')await page.screenshot({path:dir+'/matrix-'+width+'-'+theme+'.png'});
        count++;
      }
    }
    await page.setViewportSize({width:1600,height:1000});
    for(const format of ['preview','excel']) {
      await page.goto(base+'/laporan/export_global.php?'+query({...baseline.cases['1/status-multi'],format}));
      const frame=page.locator('[data-preview-frame]').contentFrame();
      await frame.locator('table').first().waitFor();
      assert.doesNotMatch(await frame.locator('body').innerText(),/Fatal error|Warning:|\? Kolom/);
      if(format==='preview') {
        assert.equal(await frame.locator('.matrix-screen .report-horizontal-matrix').count(),1);
        assert.ok(await frame.locator('.matrix-print-band').count()>=2);
        await page.emulateMedia({media:'print'});
        assert.equal(await frame.locator('.matrix-screen').isVisible(),false);
        assert.equal(await frame.locator('.matrix-print-band-first').evaluate(e=>getComputedStyle(e).breakBefore),'auto');
        await page.emulateMedia({media:'screen'});
      } else {
        assert.equal(await frame.locator('.excel-section .report-horizontal-matrix').count(),1);
        assert.equal(await frame.locator('.excel-section .report-horizontal-matrix thead tr').count(),2);
        assert.ok(await frame.locator('.excel-section .report-horizontal-matrix tbody .matrix-identity').count()>0);
      }
      await page.screenshot({path:dir+'/matrix-output-'+format+'.png'});
    }
    assert.deepEqual(errors,[]);console.log('PASS: '+count+' matrix layout/theme states, sticky NIS, single table, PDF print bands and Excel grouped/sticky preview');
  } finally {await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
