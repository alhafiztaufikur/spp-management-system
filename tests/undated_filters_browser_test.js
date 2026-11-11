'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const audit=process.env.SPP_FILTER_AUDIT_ROOT,base=process.env.SPP_FILTER_AFTER_URL;
if(!audit||!base||!process.env.SPP_PLAYWRIGHT_PATH)throw Error('Set the private audit directory, isolated URL and Playwright module path.');
assert.equal(new URL(base).hostname,'127.0.0.1');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_PATH),sessions=JSON.parse(fs.readFileSync(path.join(audit,'sessions.json'),'utf8'));
const dates='tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22';
const pages=[
 ['payment','/pembayaran/lihat.php?'+dates,'#search-lihat','.ph-filter-form'],
 ['du','/pembayaran/riwayat_daftar_ulang.php?year=2026%2F2027','#registration-history-search','.ph-filter-form'],
 ['transactions','/laporan/index.php?'+dates,'#report-siswa-search','.report-general-filter'],
 ['daily','/laporan/template.php?template=penerimaan&'+dates,'#global-siswa-search','.report-template-filters']
];
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),checks=[],errors=[];
 try{
  for(const theme of ['light','dark'])for(const width of [1440,390])for(const [name,route,search,form] of pages){
   if(process.env.SPP_FILTER_BROWSER_CASE&&process.env.SPP_FILTER_BROWSER_CASE!==[name,theme,width].join('-'))continue;
   const context=await browser.newContext({viewport:{width,height:1000}});
   await context.addCookies([{name:'PHPSESSID',value:sessions['super_admin-1'],url:base}]);
   await context.addInitScript(theme=>localStorage.setItem('spp_theme',theme),theme);
   const p=await context.newPage();p.setDefaultTimeout(90000);p.on('pageerror',error=>errors.push(error.message));p.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
   const response=await p.goto(base+route);assert.equal(response.status(),200);await p.evaluate(()=>document.fonts.ready);
   assert.equal(await p.locator('input[name=undated],input[name=include_undated],.ph-legacy-filter,.report-undated-field,.report-period-options,.du-history-notice').count(),0);
   if(name==='transactions')assert.equal(await p.locator('input[name=all_periods]').count(),0);
   assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+' '+width+' no horizontal overflow');
   const actions=p.locator(form+' '+(name==='payment'||name==='du'?'.ph-filter-actions':'.report-filter-actions'));
   assert(await actions.isVisible(),name+' filter actions visible');
   const spacing=await actions.evaluate(element=>{
    const top=element.getBoundingClientRect().top;
    const bottoms=[...element.parentElement.children].filter(e=>e!==element&&e.getBoundingClientRect().height>0)
     .map(e=>e.getBoundingClientRect().bottom).filter(bottom=>bottom<=top+1);
    return bottoms.length?top-Math.max(...bottoms):0;
   });assert(spacing<=55,name+' no empty filter row: '+spacing);
   await p.locator(form).screenshot({path:path.join(audit,'ui-'+name+'-'+theme+'-'+width+'.png')});
   await p.locator(search).fill('__undated_cleanup_no_match__');
   const submit=actions.getByRole('button',{name:'Tampilkan Rekap'});
   await submit.focus();assert(await submit.evaluate(e=>document.activeElement===e),name+' keyboard focus');
   await Promise.all([p.waitForNavigation(),p.keyboard.press('Enter')]);
   assert(!/[?&](undated|include_undated)=/.test(p.url()),name+' submitted form stays clean');
   assert(/Tidak ada|Tidak ditemukan|Belum ada/.test(await p.locator('main').innerText()),name+' search empty state');
   const reset=p.locator(form).getByRole('link',{name:'Reset',exact:true});
   await Promise.all([p.waitForNavigation(),reset.click()]);assert(!p.url().includes('__undated_cleanup'),name+' reset');
   if(name==='payment'&&theme==='light'&&width===1440){
    await Promise.all([p.waitForNavigation(),p.getByRole('link',{name:'Semua Periode',exact:true}).click()]);assert.equal(new URL(p.url()).searchParams.get('all_periods'),'1');
    const record=p.locator('.ph-record').first();if(await record.count()){await Promise.all([p.waitForNavigation(),record.click()]);assert(await p.locator('.ph-detail-panel').isVisible(),'Payment detail accessible');}
   }
   if(name==='du')assert.equal(await p.locator('.du-summary>div').count(),4,'Four DU summary cards retained');
   checks.push({name,theme,width,spacing});console.log('PASS',name,theme,width);await context.close();
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(audit,'browser-result.json'),JSON.stringify({passed:true,checks,errors},null,2));
 }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
