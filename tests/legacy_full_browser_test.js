const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
  const configFile=process.argv[2];assert(configFile&&configFile.includes('legacy_full_qa_'));
  const c=JSON.parse(fs.readFileSync(configFile,'utf8')),root=path.dirname(configFile);
  const proof=await fetch(c.base+'/tests/browser_clone_identity.php').then(r=>r.json());assert(proof.isolated_test===true);
  const cases=[],errors=[];
  const browsers=[['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'],['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']];
  for(const [name,executablePath] of browsers){assert(fs.existsSync(executablePath));const browser=await chromium.launch({headless:true,executablePath});const version=browser.version();if(name==='chromium109')assert(version.startsWith('109.'));
    const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);const page=await context.newPage();page.on('pageerror',e=>errors.push(name+': '+e.message));
    for(const theme of ['light','dark'])for(const width of [360,390,768,1024,1366,1920]){
      await page.setViewportSize({width,height:850});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
      const response=await page.goto(c.base+'/legacy_full.php?id='+c.job+'&step=exceptions&section=students&student=9810000001');assert(response.status()===200);await page.waitForTimeout(100);
      const geo=await page.evaluate(()=>({document:document.documentElement.scrollWidth,width:innerWidth,fields:[...document.querySelectorAll('.lf-form input:not([type=hidden]),.lf-form select,.lf-form textarea')].filter(el=>el.getBoundingClientRect().width&&!el.closest('.dbt-table-scroll')&&!el.closest('details:not([open])')).map(el=>({left:el.getBoundingClientRect().left,right:el.getBoundingClientRect().right}))}));assert(geo.document<=geo.width+2,name+' page overflow '+width+' '+JSON.stringify(geo));for(const field of geo.fields)assert(field.left>=-2&&field.right<=width+2,name+' input overflow');
      assert(await page.locator('html').getAttribute('data-theme')===theme);assert(await page.getByRole('button',{name:'Simpan Peninjauan Siswa',exact:true}).isVisible());
      if([360,1366].includes(width))await page.screenshot({path:path.join(root,`full-${name}-${theme}-${width}.png`),fullPage:false});cases.push({browser:name,version,theme,width,status:'passed'});
    }
    if(!process.argv.includes('--completed')){await page.setViewportSize({width:390,height:760});await page.goto(c.base+'/legacy_full.php?id='+c.job+'&step=confirm');await page.getByRole('button',{name:'Periksa & Konfirmasi Migrasi',exact:true}).click();await page.locator('.lful-modal').waitFor({state:'visible'});assert(await page.locator('.lful-modal').evaluate(el=>el.contains(document.activeElement)));await page.keyboard.press('Escape');assert(!await page.locator('.lful-modal').isVisible());
    const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:760}});await nojs.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);const plain=await nojs.newPage();await plain.goto(c.base+'/legacy_full.php?id='+c.job+'&step=confirm');await plain.getByRole('button',{name:'Periksa & Konfirmasi Migrasi',exact:true}).click();await plain.waitForLoadState('load');if(!await plain.locator('#full-confirm').isVisible()){console.log('NOJS:',await plain.locator('.dbt-warning').allTextContents());await plain.screenshot({path:path.join(root,'full-nojs-failure.png'),fullPage:false});}assert(await plain.locator('#full-confirm').isVisible());assert(await plain.locator('#full-confirm input[name=confirmation_token]').count()===1);await nojs.close();}
    for(const [unit,palette] of [[0,'super'],[1,'sd'],[2,'smp'],[3,'sma']]){
      await page.goto(c.base+'/legacy_full.php');const csrf=await page.locator('.sidebar-unit-form input[name=csrf_token]').inputValue();await context.request.post(c.base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:csrf,next:'/legacy_full.php'}});await page.goto(c.base+'/legacy_full.php');assert(await page.locator('html').getAttribute('data-palette')===palette);cases.push({browser:name,version,unit,palette,status:'passed'});
    }
    await context.close();await browser.close();
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(root,'full-browser-results.json'),JSON.stringify(cases,null,2));console.log('PASS: '+cases.length+' viewport/theme/unit cases, 3 actual browsers, focus/Escape and server confirmation without JavaScript.');
})().catch(e=>{console.error(e);process.exit(1);});
