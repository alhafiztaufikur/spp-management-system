const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = process.env.SPP_HTTP_BASE;
const dir = process.env.SPP_QA_DIR;
const data = JSON.parse(fs.readFileSync(dir + '/fixture.json', 'utf8'));
assert.match(data.database, /^db_spp_audit_/);
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname));
const money = n => 'Rp ' + Number(n).toLocaleString('id-ID');
const roman = {1:'I',2:'II',3:'III',4:'IV',5:'V',6:'VI',7:'VII',8:'VIII',9:'IX',10:'X',11:'XI',12:'XII'};
(async () => {
  const browser = await chromium.launch({ channel:'chrome', headless:true });
  let states = 0;
  try {
    for (const unit of [1,2,3,0]) {
      const context = await browser.newContext();
      await context.addCookies([{name:'PHPSESSID',value:data.units[unit].cookie,url:base}]);
      const page = await context.newPage();
      const errors = []; page.on('pageerror', e => errors.push(e.message));
      for (const theme of ['light','dark']) for (const width of [1600,900,390]) {
        await page.setViewportSize({width,height:1000});
        await page.addInitScript(t => localStorage.setItem('spp_theme', t), theme);
        for (const route of ['/siswa/daftar.php','/master_daftar_ulang.php?tahun='+encodeURIComponent(data.year)]) {
          const response = await page.goto(base+route); assert.equal(response.status(),200);
          await page.waitForTimeout(150);
          assert.doesNotMatch(await page.content(), /Fatal error|Warning:/);
          await page.screenshot({path:dir+'/'+(route.includes('siswa/')?'students':'du')+'-'+unit+'-'+width+'-'+theme+'.png',fullPage:true});
          assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 2),false,'Overflow '+route+' '+unit+' '+width);
          const nav = await page.locator('.sidebar').evaluate(e => ({bg:getComputedStyle(e).backgroundImage,accent:getComputedStyle(e).getPropertyValue('--nav-accent').trim()}));
          assert.equal(nav.accent,{1:'#62ddb1',2:'#8bc4ff',3:'#ffc0c7',0:'#d2b6ff'}[unit]);
          assert.ok(nav.bg.includes('linear-gradient'));
          if (route.includes('siswa/')) {
            assert.equal(await page.locator('.student-stat').count(),4);
            assert.equal(await page.locator('.student-stat strong').first().innerText(),String(data.units[unit].students));
            if (unit) {
              assert.equal(await page.locator('.student-panel').count(),5);
              assert.equal(await page.locator('#student-psb').isVisible(),true);
              assert.equal(await page.locator('#student-psb').isEditable(),true);
              assert.equal(await page.locator('#student-psb').isEditable(),true);
              await page.locator('#student-daftar_ulang').fill('1000000');
              await page.locator('#student-potong-du').fill('10000');
              assert.equal(await page.locator('#student-total-du').inputValue(),'990.000');
              assert.equal(await page.locator('#student-hero-effective').innerText(),await page.locator('#student-spp-effective').innerText());
              await page.locator('[data-class-picker-button]').scrollIntoViewIfNeeded();
              await page.locator('[data-class-picker-button]').click();
              const option=page.locator('.class-picker-option').first(); await option.waitFor({state:'visible'});
              assert.equal(await option.evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight&&e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))}),true,'Class option obscured');
              const value=await option.getAttribute('data-value'); await option.click();
              assert.equal(await page.locator('#kelas-baru').inputValue(),value);
              const hero = await page.locator('#student-hero-effective').evaluate(e => {
                const range = document.createRange(); range.selectNodeContents(e);
                const rects = [...range.getClientRects()], card = e.closest('.student-stat').getBoundingClientRect();
                return {lines:rects.length, fits:rects.every(r => r.left >= card.left && r.right <= card.right)};
              });
              assert.equal(hero.lines,1,'Currency remains on one line');
              assert.equal(hero.fits,true,'Currency fits its card');
              if(width===1600) {
                const tops=await page.locator('.student-panel-discounts input').evaluateAll(inputs=>inputs.map(i=>i.getBoundingClientRect().top));
                assert.ok(Math.max(...tops)-Math.min(...tops)<1,'Discount fields share a row');
              }
              await page.locator('[data-class-picker-button]').click(); await page.keyboard.press('Escape');
              assert.equal(await page.locator('[data-class-picker-button]').getAttribute('aria-expanded'),'false');
              const csrf=await page.locator('input[name="csrf_token"]').first().inputValue();
              await page.locator('#student-reset-form').click();
              assert.equal(await page.locator('#form-master-siswa input[name="aksi"]').inputValue(),'tambah');
              assert.equal(await page.locator('#form-master-siswa input[name="id"]').inputValue(),'0');
              assert.equal(await page.locator('#student-komite-start').inputValue(),'');
              assert.equal(await page.locator('#kelas-baru').inputValue(),'');
              assert.equal(await page.locator('#advanced-enabled').count(),0);
              for(const input of await page.locator('#form-master-siswa input[type="text"]').all())assert.equal(await input.inputValue(),'');
              assert.equal(await page.locator('input[name="csrf_token"]').first().inputValue(),csrf);
            } else assert.equal(await page.locator('#form-master-siswa').isVisible(),false);
          } else if (unit) {
            const stats=await page.locator('.master-modern-stats>div').evaluateAll(cards=>cards.map(card=>{
              const i=card.querySelector('.master-workspace-icon').getBoundingClientRect(),n=card.querySelector('strong').getBoundingClientRect();
              return {icon:i.top+i.height/2,value:n.top+n.height/2};
            }));
            for(const stat of stats)assert.ok(Math.abs(stat.icon-stat.value)<1,'Summary icon/value alignment');
            if(width>600)assert.ok(Math.max(...stats.map(s=>s.value))-Math.min(...stats.map(s=>s.value))<1,'Summary values share a row');
            assert.deepEqual(await page.locator('.du-class-roman').allTextContents(),Array.from({length:data.units[unit].last-data.units[unit].first+1},(_,i)=>roman[i+data.units[unit].first]));
            let total=0;
            for (let grade=data.units[unit].first;grade<=data.units[unit].last;grade++) total+=Number(data.units[unit].counts[grade]||0)*Number(data.units[unit].rates[grade]||0);
            assert.equal(await page.locator('[data-du-total-estimate]').innerText(),money(total));
            if (data.units[unit].status!=='closed') {
              const input=page.locator('.du-rate-card .rupiah-input').first();
              const card=page.locator('.du-rate-card').first(); const count=Number(await card.getAttribute('data-students'));
              const before=Number((await input.inputValue()).replace(/\D/g,'')); await input.fill('2000000');
              assert.equal(await card.locator('[data-du-estimate]').innerText(),money(count*2000000));
              assert.equal(await page.locator('[data-du-total-estimate]').innerText(),money(total+count*(2000000-before)));
            }
          } else assert.equal(await page.locator('#du-rate-form').count(),0);
          states++;
        }
      }
      await page.setViewportSize({width:1600,height:1000});
      if(unit) {
        await page.goto(base+'/siswa/daftar.php?edit='+data.units[unit].edit_id);
        assert.equal(await page.locator('#nis-baru').isEditable(),false);
        const values=await page.locator('#form-master-siswa input[name]').evaluateAll(inputs=>Object.fromEntries(inputs.map(i=>[i.name,i.value])));
        const payload=await page.locator('#form-master-siswa').evaluate(form=>Object.fromEntries(new FormData(form)));
        for(const [name,value] of Object.entries(values))assert.equal(payload[name],value,'Locked field still submits its unchanged value');
        // Failure must recover the draft and Advanced state without writing financial data.
        const failed=await page.request.post(base+'/siswa/daftar.php',{form:{...payload,nama:'',advanced_enabled:'1',psb:'1234500'},maxRedirects:0});
        assert.equal(failed.status(),302);await page.goto(base+'/siswa/daftar.php?edit='+data.units[unit].edit_id);
        assert.equal(await page.locator('#advanced-enabled').count(),0);
        assert.equal(await page.locator('#student-psb').inputValue(),'1.234.500');
        assert.match(await page.locator('#flash-msg').innerText(),/Nama siswa wajib/);
        await page.locator('#student-reset-form').click();
        assert.equal(await page.locator('#nis-baru').isEditable(),true);
        assert.equal(await page.locator('#student-form-title').innerText(),'Tambah Siswa Baru');
        assert.equal(await page.locator('#form-master-siswa input[name="aksi"]').inputValue(),'tambah');
        assert.equal(await page.locator('#form-master-siswa input[name="id"]').inputValue(),'0');
        assert.match(await page.locator('#student-reset-status').innerText(),/tersimpan tidak berubah/);
        assert.equal(new URL(page.url()).searchParams.has('edit'),false);
        await page.reload();
        for(const input of await page.locator('#form-master-siswa input[type="text"]').all())assert.equal(await input.inputValue(),'');
        await page.goto(base+'/siswa/daftar.php?edit='+data.units[unit].edit_id);
        assert.equal(await page.locator('#nis-baru').inputValue(),payload.no_induk,'Reset leaves saved student intact');
        assert.equal(await page.locator('#nama-baru').inputValue(),payload.nama);
        await page.goto(base+'/master_daftar_ulang.php?tahun='+encodeURIComponent(data.draft_year));
        assert.equal(await page.locator('#du-rate-form input[name="aksi"]').inputValue(),'simpan_dan_terbitkan');
        assert.match(await page.locator('#du-rate-form button[type="submit"]').innerText(),/Simpan & Terbitkan/);
        await page.goto(base+'/master_daftar_ulang.php?tahun='+encodeURIComponent(data.closed_year));
        for(const input of await page.locator('.du-rate-card input').all())assert.equal(await input.isDisabled(),true);
        assert.equal(await page.locator('#du-rate-form button[type="submit"]').count(),0);
        assert.match(await page.locator('.du-publishing-card').innerText(),/Buka Kembali/);
      }
      if(unit===1){
        await page.goto(base+'/siswa/daftar.php?q=NO_MATCH_MASTER_WORKSPACE');
        assert.equal(await page.locator('.student-stat strong').first().innerText(),'0');
        assert.match(await page.locator('.payment-table').innerText(),/Data siswa tidak ditemukan/);
        await page.goto(base+'/siswa/daftar.php?status[]=active&status[]=archived');
        assert.equal(await page.locator('.student-stat strong').last().innerText(),'Beberapa status');
        await page.goto(base+'/siswa/daftar.php');
        for(const target of [2,3,0,1]) {
          await Promise.all([page.waitForNavigation(),page.locator('[data-unit-choice="'+target+'"]').click()]);
          assert.equal(await page.locator('.sidebar-unit-form').getAttribute('data-operational-unit'),String(target));
          assert.equal(await page.locator('[data-unit-choice="'+target+'"]').getAttribute('aria-pressed'),'true');
        }
      }
      assert.deepEqual(errors,[]);await context.close();
    }
    const nojs=await browser.newContext({javaScriptEnabled:false});
    await nojs.addCookies([{name:'PHPSESSID',value:data.units[1].cookie,url:base}]);
    const page=await nojs.newPage();await page.goto(base+'/siswa/daftar.php?edit='+data.units[1].edit_id);
    assert.equal(await page.locator('#nis-diknas').isEditable(),false);
    assert.equal(await page.locator('#student-psb').isVisible(),true);
    assert.equal(await page.locator('#sidebar-unit-select').isVisible(),true);
    await page.locator('#student-reset-form').focus();
    await Promise.all([page.waitForURL(/reset_form=1/, {waitUntil:'domcontentloaded'}),page.keyboard.press('Enter')]);
    assert.equal(await page.locator('#nis-baru').isEditable(),true);
    assert.equal(await page.locator('#student-komite-start').inputValue(),'');
    for(const input of await page.locator('#form-master-siswa input[type="text"]').all())assert.equal(await input.inputValue(),'');
    await nojs.close();
    for(const role of ['admin','kasir','bendahara']) {
      const c=await browser.newContext(); await c.addCookies([{name:'PHPSESSID',value:data.roles[role],url:base}]);
      const p=await c.newPage();await p.goto(base+'/siswa/daftar.php');
      assert.equal(await p.locator('.sidebar-unit-segments').count(),0);
      assert.equal(await p.locator('#form-master-siswa').count(),role==='bendahara'?0:1);await c.close();
    }
    console.log('OK: '+states+' visual states; source totals/rates, Advanced locking/reset, class picker, all year states, permissions, unit switches, and no-JS.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
