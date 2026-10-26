/* Existing desktop tabs and receipt layout, on an explicitly owned HTTP clone. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const qa=process.env.SPP_QA_DIR,f=JSON.parse(fs.readFileSync(path.join(qa,'fixture.json')));
assert(process.env.SPP_TEST_ALLOW_MUTATION==='1'&&f.port!==3306&&f.database===process.env.SPP_DB_NAME);
const browserName=process.argv.includes('--edge')?'edge':'chrome';
const binary=browserName==='edge'?'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe':'C:/Program Files/Google/Chrome/Application/chrome.exe';
const period='tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22&per_page=10';
let checks=0;const errors=[];function check(value,label){assert(value,label);checks++;}
async function recap(page){return page.locator('[data-component-key]').evaluateAll(rows=>rows.reduce((sum,row)=>sum+Number(row.dataset.cents),0));}
async function cookie(context,unit){await context.addCookies([{name:'PHPSESSID',value:f.sessions[unit],url:f.base}]);}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:binary});
 try{
  const context=await browser.newContext({viewport:{width:1920,height:1080},reducedMotion:'reduce'});
  await cookie(context,1);const identity=await context.request.get(f.base+'/tests/browser_clone_identity.php');check(identity.status()===200&&(await identity.json()).database===f.database,'Owned HTTP clone');
  await context.route('**/*',route=>new URL(route.request().url()).origin===f.base?route.continue():route.abort());
  const page=await context.newPage();page.setDefaultTimeout(120000);page.on('pageerror',error=>errors.push(error.message));
  for(const unit of [1,2,3,0]){
   await cookie(context,unit);await page.setViewportSize({width:unit%2?1920:1366,height:1080});await page.goto(f.base+'/laporan/index.php?'+period);
   check(await page.locator('[data-finance-tab]').count()===3,'Three existing tabs');check(await recap(page)===f.expected[unit].cents,'Recap equals filtered receipts/unit '+unit);
   check(!(await page.locator('.finance-components-table').textContent()).includes('U_SORGA'),'Business recap uses Komite');
   check(await page.locator('[data-component-key="komite"]').count()===1,'One canonical Komite row');
   for(const panel of ['ringkasan','komponen','transaksi']){
    await page.locator('[data-finance-tab='+panel+']').click();
    check(await page.locator('.finance-components').isVisible()===(panel!=='transaksi'),'Component visibility/'+panel);
    check(await page.locator('.finance-transactions').isVisible()===(panel!=='komponen'),'Transaction visibility/'+panel);
    check(await recap(page)===f.expected[unit].cents,'Changing tabs preserves amounts');
   }
   await page.evaluate(theme=>{localStorage.setItem('spp_theme',theme);document.documentElement.setAttribute('data-theme',theme);},unit%2?'light':'dark');
   if(unit===1){await page.locator('[data-finance-tab=ringkasan]').click();await page.screenshot({path:path.join(qa,'artifacts','komite-'+browserName+'-summary.png'),fullPage:true});}
  }
  await cookie(context,1);await page.goto(f.base+'/laporan/index.php?'+period+'&panel=transaksi');const before=await recap(page);
  await page.locator('[data-report-section] a').filter({hasText:'Berikutnya'}).click();await page.waitForFunction(()=>document.querySelector('[data-report-section]').dataset.page==='2');check(await recap(page)===before,'Pagination preserves whole-filter recap');
  await page.goto(f.base+'/laporan/index.php?'+period+'&include_undated=1&panel=komponen');check(await recap(page)>=f.expected[1].cents,'Undated receipts remain visible');
  await page.goto(f.base+'/laporan/index.php?'+period+'&all_periods=1&panel=komponen');check(await recap(page)>f.expected[1].cents,'Whole history includes earlier source amounts');
  await page.goto(f.base+'/laporan/index.php?tanggal_awal=2090-01-01&tanggal_akhir=2090-01-31');check((await page.locator('.finance-components-table').textContent()).includes('Tidak ada pembayaran sesuai filter.'),'True empty state');
  await page.goto(f.base+'/laporan/cetak_struk.php?id='+f.sample_id+'&unit_id=1');const receipt=await page.locator('body').textContent();check(receipt.includes('Komite')&&!receipt.includes('U_SORGA'),'Receipt label normalized');check(receipt.includes('545.000')&&f.sample_cents===54500000,'Receipt total unchanged');await page.screenshot({path:path.join(qa,'artifacts','komite-'+browserName+'-receipt.png'),fullPage:true});
  await context.close();const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:1920,height:1080}});await cookie(nojs,1);const fallback=await nojs.newPage();await fallback.goto(f.base+'/laporan/index.php?'+period+'&panel=komponen');check(await recap(fallback)===f.expected[1].cents,'Recap works without JavaScript');check(await fallback.locator('.finance-components').isVisible()&&!await fallback.locator('.finance-transactions').isVisible(),'Server tab selection without JavaScript');await fallback.locator('[data-finance-tab=transaksi]').click();check(await fallback.locator('.finance-transactions').isVisible(),'GET tab navigation without JavaScript');await nojs.close();check(errors.length===0,'No JavaScript errors: '+errors.join('; '));
 }finally{await browser.close();}
 fs.writeFileSync(path.join(qa,'browser-'+browserName+'.json'),JSON.stringify({browser:browserName,checks,errors},null,2));console.log('PASS: '+browserName+' '+checks+' desktop tab, exact recap, filter, receipt and no-JavaScript checks.');
})().catch(error=>{console.error(error);process.exitCode=1;});
