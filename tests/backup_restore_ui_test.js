const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const base = new URL(process.env.SPP_TEST_BASE_URL);
const db = process.env.SPP_DB_NAME;
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION, '1');
if(process.env.SPP_TEST_ISOLATED_INSTANCE){
  const manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8'));
  assert.equal(manifest.isolated_test,true);assert.equal(manifest.database,db);
  assert.notEqual(manifest.server_uuid,manifest.source_server_uuid);assert.notEqual(manifest.port,3306);
}else assert.match(db, /^db_spp_audit_[a-z0-9_]+$/);
assert.ok(['127.0.0.1','localhost'].includes(base.hostname) && base.protocol === 'http:');
const artifacts = process.env.SPP_UI_ARTIFACTS;
assert.ok(artifacts, 'Store screenshots outside the repository');
const cssTools = process.env.SPP_CSS_TOOLS;
const css = fs.readFileSync('assets/css/backup_restore.css', 'utf8');
require(path.join(cssTools, 'postcss')).parse(css);
require(path.join(cssTools, 'css-tree')).parse(css);
console.log('OK: independent page CSS parsed');
(async () => {
  const browser = await chromium.launch({channel:'chrome',headless:true});
  try {
    const page = await browser.newPage({viewport:{width:1440,height:1000}});
    const errors = [], uploads = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => { if(r.method() !== 'GET' && !['/login.php','/unit_switch.php','/restore_api.php'].includes(new URL(r.url()).pathname)) uploads.push(r.url()); });
    await page.route('**/restore_api.php',route=>route.fulfill({status:400,contentType:'application/json',body:JSON.stringify({error:'File tidak cocok dengan cadangan terverifikasi.'})}));
    assert.equal((await(await page.request.get(new URL('/tests/browser_clone_identity.php',base).href)).json()).database, db);
    const anonymous = await page.request.get(new URL('/backup_restore.php',base).href,{maxRedirects:0});
    assert.equal(anonymous.status(),302);
    await page.goto(new URL('/login.php',base).href);
    await page.locator('#username').fill('superadmin');
    await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());
    await page.locator('#btn-login').click(); await page.waitForURL('**/dashboard.php');
    await page.goto(new URL('/backup_restore.php',base).href);
    await page.waitForLoadState('networkidle');
    assert.equal(await page.getByRole('link',{name:'Backup & Restore',exact:true}).first().count(),1);
    assert.equal(await page.locator('.dbt-metric').count(),4);
    assert.equal(await page.locator('#history-title').count(),1);
    const valid={name:'cadangan.SQL',mimeType:'application/sql',buffer:Buffer.from('not actually validated SQL')};
    const select=async(prefix,file)=>page.locator('#'+prefix+'-file').setInputFiles(file);
    await select('restore',valid);
    assert.match(await page.locator('#restore-status').textContent(),/Belum divalidasi/);
    assert.equal(await page.locator('#restore-review').isEnabled(),true);
    await page.locator('#restore-review').click();
    assert.equal(await page.locator('#restore-dialog').evaluate(e=>e.open),true);
    await page.waitForFunction(()=>document.getElementById('restore-unavailable').textContent.includes('tidak cocok'));
    assert.equal(await page.locator('#restore-understood').isDisabled(),true);
    assert.equal(await page.locator('#restore-confirmation').isDisabled(),true);
    assert.equal(await page.locator('#restore-apply').isDisabled(),true);
    await page.locator('#restore-cancel').focus();
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.locator('#restore-dialog').evaluate(e=>e.contains(document.activeElement)),true);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#restore-review').evaluate(e=>e===document.activeElement),true);
    await page.locator('#restore-review').click();
    await page.waitForFunction(()=>document.getElementById('restore-unavailable').textContent.includes('tidak cocok'));
    assert.equal(await page.locator('#restore-understood').isChecked(),false);
    assert.equal(await page.locator('#restore-confirmation').inputValue(),'');
    await page.locator('#restore-cancel').click();
    for(const [file,message] of [
      [{name:'empty.sql',buffer:Buffer.alloc(0)},/kosong/],
      [{name:'wrong.txt',buffer:Buffer.from('a')},/Pilih satu file SQL/],
      [{name:'SD.dat',buffer:Buffer.from('a')},/Pilih satu file SQL/]
    ]){
      await select('restore',{mimeType:'application/octet-stream',...file});
      assert.match(await page.locator('#restore-status').textContent(),message);
      assert.equal(await page.locator('#restore-review').isDisabled(),true);
    }
    await page.locator('#restore-dropzone').evaluate(zone=>{
      const transfer=new DataTransfer(); transfer.items.add(new File([new Uint8Array(100*1024*1024+1)],'large.sql'));
      zone.dispatchEvent(new DragEvent('drop',{dataTransfer:transfer,bubbles:true}));
    });
    assert.match(await page.locator('#restore-status').textContent(),/100 MB/);
    assert.equal(await page.locator('#restore-review').isDisabled(),true);
    await page.locator('#restore-dropzone').evaluate(zone=>{
      const transfer=new DataTransfer(); transfer.items.add(new File([new Uint8Array(100*1024*1024)],'boundary.sql'));
      zone.dispatchEvent(new DragEvent('drop',{dataTransfer:transfer,bubbles:true}));
    });
    assert.equal(await page.locator('#restore-review').isEnabled(),true,'Exactly 100 MB is accepted by preliminary checks');
    await page.locator('#restore-dropzone').evaluate(zone=>{
      const transfer=new DataTransfer();
      transfer.items.add(new File(['x'],'a.sql')); transfer.items.add(new File(['y'],'b.sql'));
      zone.dispatchEvent(new DragEvent('drop',{dataTransfer:transfer,bubbles:true}));
    });
    assert.match(await page.locator('#restore-status').textContent(),/satu file/);
    await page.locator('#restore-dropzone').evaluate(zone=>{
      const transfer=new DataTransfer(); transfer.items.add(new File(['x'],'<img onerror=alert(1)>.sql'));
      zone.dispatchEvent(new DragEvent('drop',{dataTransfer:transfer,bubbles:true}));
    });
    assert.equal(await page.locator('#restore-name img').count(),0);
    assert.equal(await page.locator('#restore-name').textContent(),'<img onerror=alert(1)>.sql');
    await page.locator('#restore-review').click();
    await page.waitForFunction(()=>document.getElementById('restore-unavailable').textContent.includes('tidak cocok'));
    assert.equal(await page.locator('#restore-dialog-file img').count(),0);
    await page.locator('#restore-cancel').click();
    const chooserPromise=page.waitForEvent('filechooser'); await page.locator('#restore-change').click();
    await(await chooserPromise).setFiles(valid);
    await page.locator('#restore-remove').click();
    assert.equal(await page.locator('#restore-review').isDisabled(),true);
    assert.equal(await page.locator('#restore-summary').isVisible(),false);
    await page.locator('#tab-backup').focus(); await page.keyboard.press('ArrowRight');
    assert.equal(await page.locator('#tab-legacy').getAttribute('aria-selected'),'true');
    assert.equal(await page.locator('#legacy-unit').inputValue(),'');
    assert.deepEqual(await page.locator('#legacy-unit option').evaluateAll(es=>es.map(e=>e.value)),['','SD','SMP','SMA']);
    for(const category of ['classes','payments','savings','students']){
      await page.locator('[data-category="'+category+'"]').click();
      assert.equal(await page.locator('[data-category][aria-pressed="true"]').count(),1);
      assert.ok((await page.locator('#legacy-condition').textContent()).length>50);
    }
    for(const unit of ['SD','SMP','SMA'])await page.locator('#legacy-unit').selectOption(unit);
    await select('legacy',valid);
    assert.equal(await page.locator('#legacy-start').isDisabled(),true);
    assert.equal(await page.locator('#legacy-unit').inputValue(),'SMA');
    await select('legacy',{name:'legacy.dat',mimeType:'application/octet-stream',buffer:Buffer.from('a')});
    assert.match(await page.locator('#legacy-status').textContent(),/Belum diperiksa/);
    await select('legacy',{name:'legacy.dat',mimeType:'application/octet-stream',buffer:Buffer.from('a')}); await page.locator('#legacy-remove').click();
    assert.equal(await page.locator('#legacy-summary').isVisible(),false);
    assert.equal(await page.locator('.dbt-steps li').count(),4);
    assert.equal(await page.locator('button:enabled').filter({hasText:'Pemetaan & Validasi'}).count(),0);
    console.log('OK: preliminary file rules, safe names, change/remove/drop, modal keyboard/focus, rejected SQL remains disabled; operation responses mocked; selection alone never uploads');
    const results=[];
    await page.locator('#tab-backup').click();
    await page.setViewportSize({width:900,height:1000});
    assert.equal(await page.locator('.dbt-metrics').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),2,'Tablet metrics');
    for(const unit of [0,1,2,3]){
      await page.setViewportSize({width:1440,height:1000});
      await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);
      const post=await page.request.post(new URL('/backup_restore.php',base).href,{form:{action:'restore'}});
      assert.ok([405,409].includes(post.status()));
      for(const theme of ['light','dark']){
        await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.setAttribute('data-theme',t);},theme);
        for(const width of [1440,2560,390]){
          await page.setViewportSize({width,height:width===390?844:1100});
          await page.addStyleTag({content:'*,*::before,*::after{transition:none!important;animation:none!important}'});
          for(const mode of ['backup','legacy']){
            await page.locator('#tab-'+mode).click();
            const layout=await page.evaluate(()=>{
              const wrapper=document.querySelector('.db-tools-page');
              return {overflow:document.documentElement.scrollWidth>innerWidth+1,
                metrics:getComputedStyle(document.querySelector('.dbt-metrics')).gridTemplateColumns.split(' ').length,
                actionColumns:getComputedStyle(document.querySelector('.dbt-actions')).gridTemplateColumns.split(' ').length,
                width:wrapper.getBoundingClientRect().width, rules:[...document.styleSheets].find(s=>s.href?.includes('/backup_restore.css')).cssRules.length};
            });
            assert.equal(layout.overflow,false,JSON.stringify({unit,theme,width,mode,layout}));
            if(mode==='backup'){assert.equal(layout.metrics,width===390?1:4);assert.equal(layout.actionColumns,width===390?1:2);}
            assert.ok(layout.rules>60,'Page CSS parsed through final media queries');
            await page.screenshot({path:path.join(artifacts,'unit-'+unit+'-'+theme+'-'+width+'-'+mode+'.png'),fullPage:true});
            results.push({unit,theme,width,mode,...layout});
          }
        }
      }
    }
    for(const route of ['/dashboard.php','/role_management.php','/laporan/global.php','/tabungan/cetak.php']){
      await page.setViewportSize({width:1440,height:1000}); await page.goto(new URL(route,base).href);
      assert.equal(await page.getByRole('link',{name:'Backup & Restore',exact:true}).first().count(),1);
      assert.equal(await page.locator('link[href*="backup_restore.css"]').count(),0,'Page CSS must not leak to existing pages');
    }
    assert.deepEqual(errors,[]); assert.deepEqual(uploads,[]);
    fs.writeFileSync(path.join(artifacts,'visual-results.json'),JSON.stringify(results,null,2));
    console.log('OK: 48 visual states across four palettes, two themes and three widths; read-only POST guard and shared navigation; no file uploads or JS errors');
  }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
