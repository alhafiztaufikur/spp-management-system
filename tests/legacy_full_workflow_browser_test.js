const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE||'playwright-core');
(async()=>{
  const file=process.argv[2];assert(file&&file.includes('legacy_workflow_qa_'));
  const c=JSON.parse(fs.readFileSync(file,'utf8')),root=path.dirname(file);
  assert((await fetch(c.base+'/tests/browser_clone_identity.php').then(r=>r.json())).isolated_test===true);
  const results=[],errors=[];
  for(const [name,executablePath] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'],['chromium109','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']]){
    const browser=await chromium.launch({headless:true,executablePath});
    try{
      if(name==='chromium109')assert(browser.version().startsWith('109.'));
      const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);const page=await context.newPage();page.on('pageerror',e=>errors.push(name+': '+e.message));
      for(const theme of ['light','dark'])for(const width of [360,390,768,1024,1366,1920]){
        await page.setViewportSize({width,height:850});await page.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
        const response=await page.goto(c.base+'/legacy_full.php?id='+c.job+'&step=exceptions&section=students');assert(response.status()===200);
        assert(await page.locator('#group-review').isVisible());assert(await page.getByRole('button',{name:'Periksa Pratinjau'}).isVisible());
        assert((await page.locator('.legacy-steps a').evaluateAll(els=>els.map(el=>el.textContent.trim().replace(/^\d+/,'')))).join('|')==='Unggah|Periksa Hasil|Selesaikan Pengecualian|Konfirmasi|Selesai');
        const geometry=await page.evaluate(()=>({w:innerWidth,doc:document.documentElement.scrollWidth,fields:[...document.querySelectorAll('#group-review input:not([type=hidden]):not([type=checkbox]),#group-review select,#group-review textarea')].map(el=>el.getBoundingClientRect()).filter(r=>r.width).map(r=>({left:r.left,right:r.right,height:r.height}))}));assert(geometry.doc<=geometry.w+2,JSON.stringify(geometry));geometry.fields.forEach(r=>assert(r.left>=-2&&r.right<=width+2&&r.height>=44));
        if([360,1366].includes(width)){await page.locator('#group-review').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(root,`workflow-${name}-${theme}-${width}.png`)});}
        results.push({browser:name,version:browser.version(),theme,width,status:'passed'});
      }
      if(!process.argv.includes('--completed')){await page.goto(c.base+'/legacy_full.php?id='+c.job+'&step=exceptions&section=obligations');assert(await page.locator('[name=obligations_confirmed]').isVisible());assert(!await page.locator('#group-review-form [name=kind]').count());
      // The native server path works without scripting, including a bounded exact preview.
      const plain=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:850}});await plain.addCookies([{name:'PHPSESSID',value:c.session,url:c.base}]);const p=await plain.newPage();
      await p.goto(c.base+'/legacy_full.php?id='+c.job+'&step=exceptions&section=obligations&group_class=ALUMNI');await p.locator('#group-review-form [name=group_evidence]').fill('Official QA group review and remaining obligations.');await p.locator('[name=obligations_confirmed]').evaluate(el=>el.scrollIntoView({behavior:'instant',block:'center'}));await p.locator('[name=obligations_confirmed]').check();await p.getByRole('button',{name:'Periksa Pratinjau'}).click();await p.locator('#group-preview').waitFor();assert(await p.locator('#group-preview tbody tr').count()===1);
      await p.getByRole('button',{name:'Sahkan 1 Data',exact:true}).click();await p.waitForLoadState('load');assert((await p.locator('.dbt-notice').innerText()).includes('Pengesahan kelompok disimpan'));
      const version=await p.locator('#group-review-form input[name=version]').inputValue();const response=await context.request.post(c.base+'/legacy_import_api.php',{form:{action:'full_simulate',id:c.job,version,csrf_token:'a'.repeat(64)}});assert(response.ok());assert((await response.json()).ready);
      await p.goto(c.base+'/legacy_full.php?id='+c.job+'&step=exceptions&section=students');await p.getByRole('button',{name:'Periksa & Konfirmasi Migrasi',exact:true}).click();await p.locator('#full-confirm').waitFor();assert(await p.locator('#full-confirm').isVisible());await plain.close();
      await page.goto(c.base+'/legacy_full.php?id='+c.job+'&step=confirm');await page.getByRole('button',{name:'Periksa & Konfirmasi Migrasi',exact:true}).click();await page.locator('.lful-modal').waitFor();assert(await page.locator('.lful-modal').evaluate(el=>el.contains(document.activeElement)));await page.keyboard.press('Escape');assert(!await page.locator('.lful-modal').isVisible());
      }
      for(const [unit,palette] of [[0,'super'],[1,'sd'],[2,'smp'],[3,'sma']]){await page.goto(c.base+'/legacy_full.php');const csrf=await page.locator('.sidebar-unit-form input[name=csrf_token]').inputValue();await context.request.post(c.base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:csrf,next:'/legacy_full.php'}});await page.goto(c.base+'/legacy_full.php');assert(await page.locator('html').getAttribute('data-palette')===palette);results.push({browser:name,version:browser.version(),unit,palette,status:'passed'});}
      await context.close();
    }finally{await browser.close();}
  }
  assert.deepStrictEqual(errors,[]);fs.writeFileSync(path.join(root,process.argv.includes('--completed')?'workflow-readonly-browser-results.json':'workflow-browser-results.json'),JSON.stringify(results,null,2));console.log('PASS: '+results.length+' layout/theme/palette cases in Chrome, Edge and actual Chromium 109'+(process.argv.includes('--completed')?'; read-only completed/nonempty target views.':'; native group preview/acceptance, no-JS final confirmation, focus/Escape.'));
})().catch(e=>{console.error(e);process.exit(1);});
