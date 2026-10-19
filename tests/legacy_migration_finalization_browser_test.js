const fs=require('fs'),assert=require('assert'),path=require('path');const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
(async()=>{const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8')),results=[];
for(const exe of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
 const b=await chromium.launch({headless:true,executablePath:exe});try{for(const js of [true,false])for(const width of [360,1366]){
 const c=await b.newContext({javaScriptEnabled:js,viewport:{width,height:800}});try{await c.addCookies([{name:'PHPSESSID',value:f.session,url:f.base}]);const p=await c.newPage();
 await p.goto(f.base+'/legacy_operational_review.php?id='+f.job);const card=p.locator('#migration-finalization');await card.getByRole('button',{name:'Finalisasi Migrasi',exact:true}).click();
 const modal=p.locator('#full-confirm');await modal.waitFor();assert((await modal.innerText()).includes('Konfirmasi Final Migrasi'));assert(await modal.getByRole('button',{name:'Mulai Migrasi Sekarang',exact:true}).isVisible());assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));
 if(js){assert(await p.locator('dialog[open]').count()===1);await p.keyboard.press('Escape');}else await modal.getByRole('link',{name:'Kembali',exact:true}).click();
 await p.locator('#migration-finalization').waitFor();assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));results.push({browser:b.version(),js,width});
 }finally{await c.close();}}
 }finally{await b.close();}}
fs.writeFileSync(path.join(path.dirname(process.argv[2]),'finalization-browser-results.json'),JSON.stringify(results));console.log('PASS: '+results.length+' Chrome/Chromium109 mobile/desktop JS/no-JS finalization and cancellation cases; no application performed.');
})().catch(e=>{console.error(e);process.exit(1)});
