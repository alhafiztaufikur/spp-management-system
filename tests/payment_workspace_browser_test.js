const fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(process.argv[2]||'');assert(root.endsWith('ui_time_audit_20261021'));
const {chromium}=require(root+'/../import_audit_20261020/browser-tools/node_modules/playwright-core');
const c=JSON.parse(fs.readFileSync(root+'/http.json','utf8')),fixture=JSON.parse(fs.readFileSync(root+'/time-fixture.json','utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}),checks=[],errors=[];
 try{
  for(const unit of [1,2,3,0]){
   const context=await browser.newContext({viewport:{width:1440,height:1000}});await context.addCookies([{name:'PHPSESSID',value:c.sessions[unit],url:c.base}]);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   for(const [route,name] of [['pembayaran/form.php','input'],['pembayaran/lihat.php?all_periods=1','payments'],['pembayaran/riwayat_daftar_ulang.php','registration'],['tabungan/riwayat.php?all_periods=1','savings'],['laporan/template.php?template=status&unit='+(unit||'all'),'global-status']]){
    const response=await page.goto(c.base+'/'+route,{waitUntil:'networkidle',timeout:120000});assert.equal(response.status(),200);assert(!/Fatal error:|Warning:|Uncaught |Gagal memuat laporan/.test(await page.locator('body').innerText()));
    if(name==='global-status'){
     const statuses=await page.locator('.report-status').evaluateAll(nodes=>nodes.map(n=>({text:n.textContent,width:n.getBoundingClientRect().width,parent:n.parentElement.getBoundingClientRect().width,whiteSpace:getComputedStyle(n).whiteSpace,scroll:n.scrollHeight,height:n.clientHeight})));
     assert(statuses.length>0);for(const s of statuses){assert(s.width<=s.parent+1,s.text);assert(s.scroll<=s.height+1,s.text);assert.notEqual(s.whiteSpace,'nowrap');}
     await page.locator('.report-matrix-scroll').evaluate(el=>el.scrollLeft=el.scrollWidth);
    }
    await page.screenshot({path:root+'/artifacts/ui-'+unit+'-'+name+'.png'});checks.push({unit,name});console.log('PASS',unit,name);
   }
   if(unit===1){
    for(const width of [390,768,1024,1440]){
     await page.setViewportSize({width,height:1000});
     for(const [route,name] of [['pembayaran/riwayat_daftar_ulang.php','registration'],['tabungan/riwayat.php','savings'],['pembayaran/edit.php?id='+fixture.units['1'].known.id,'edit']]){
      const response=await page.goto(c.base+'/'+route,{waitUntil:'networkidle',timeout:120000});assert.equal(response.status(),200);
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Page overflows at '+width+' '+name);
      if(name==='registration'||name==='savings'){
       const parent=name==='registration'?'.ph-filter-form':'.mh-recap-filter';
       const sizes=await page.locator(parent+' label').evaluateAll(nodes=>nodes.map(n=>n.querySelector('button.spp-select-trigger')||n.querySelector('input.field-input,select.field-input')).filter(Boolean).map(n=>({width:n.getBoundingClientRect().width,height:n.getBoundingClientRect().height})).filter(n=>n.height>0));
       assert(sizes.length>=3);for(const size of sizes){assert(Math.abs(size.height-48)<1,JSON.stringify({width,name,size}));assert(Math.abs(size.width-sizes[0].width)<2,JSON.stringify({width,name,sizes}));}
      }else{
       const clock=await page.locator('input[name="date_clock"]').inputValue(),expected=fixture.units['1'].known.TGL_BYR.slice(11);
       assert.equal(clock.length===5?clock+':00':clock,expected);assert.equal(await page.locator('.legacy-edit-aside').count(),1);
       assert.equal(await page.locator('input.id-date-native').evaluate(n=>getComputedStyle(n).opacity),'0','Native date must not duplicate the visible Indonesian control');
      }
      await page.screenshot({path:root+'/artifacts/ui-'+width+'-'+name+'.png'});checks.push({unit,width,name});console.log('PASS',width,name);
     }
     await page.goto(c.base+'/pembayaran/form.php',{waitUntil:'networkidle'});
     await page.locator('#siswa-search').fill(fixture.units['1'].known.NO_INDUK);await page.locator('.student-search-option').first().click();
     await page.locator('#payment-history-scope').selectOption('all',{force:true});
     await page.waitForFunction(()=>document.querySelector('#payment-history-body')?.dataset.historyState==='ready');
     const amounts=await page.locator('.form-payment-table .tbl-input').evaluateAll(nodes=>nodes.map(n=>{const b=n.getBoundingClientRect();return {left:b.left,right:b.right,width:b.width,scroll:n.scrollWidth,client:n.clientWidth};}));
     for(const amount of amounts)assert(amount.left>=0&&amount.right<=width+1&&amount.scroll<=amount.client+1,JSON.stringify({width,amount}));
     assert((await page.locator('#payment-history-body').innerText()).includes(fixture.units['1'].known.TGL_BYR.slice(11)+' WIB'));
     await page.locator('#du-selector-trigger').click();
     const box=await page.locator('#du-selector-menu').boundingBox();assert(box&&box.x>=0&&box.x+box.width<=width+1&&box.y>=0&&box.y+box.height<=1001,JSON.stringify({width,box}));
     await page.screenshot({path:root+'/artifacts/ui-'+width+'-input-dropdown.png'});
     await page.locator('#du-selector-menu').press('Escape');assert(await page.locator('#du-selector-menu').isHidden());
     checks.push({unit,width,name:'input-dropdown-keyboard'});console.log('PASS',width,'input-dropdown-keyboard');
    }
    await page.evaluate(()=>localStorage.setItem('spp_theme','dark'));
    await page.goto(c.base+'/pembayaran/riwayat_daftar_ulang.php',{waitUntil:'networkidle'});assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
    await page.screenshot({path:root+'/artifacts/ui-dark-registration.png'});checks.push({unit,name:'dark-theme'});
   }
   await context.close();
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(root+'/ui-browser-results.json',JSON.stringify({checks:checks.length,results:checks,errors},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
