const fs=require('fs'),assert=require('assert');const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
(async()=>{const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8')),results=[];
for(const exe of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
 const b=await chromium.launch({headless:true,executablePath:exe});try{for(const width of [390,1366])for(const js of [true,false]){
 const c=await b.newContext({viewport:{width,height:900},javaScriptEnabled:js});try{await c.addCookies([{name:'PHPSESSID',value:f.session,url:f.base}]);const p=await c.newPage();
 for(const route of ['master_kelas.php','pembayaran/lihat.php','tabungan/riwayat.php','legacy_operational_review.php?id='+f.job+'&section=confirm']){
  const r=await p.goto(f.base+'/'+route);assert(r.status()===200);assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));
  if(route.startsWith('legacy_operational'))assert((await p.locator('#migration-finalization').innerText()).includes('Migrasi Selesai'));
 }
 results.push({browser:b.version(),width,js});
 }finally{await c.close();}}
 }finally{await b.close();}}
fs.writeFileSync(process.argv[3],JSON.stringify(results));console.log('PASS: '+results.length+' merged-source Chrome/Chromium109 mobile/desktop JS/no-JS cases for class, payment, savings and migration pages.');
})().catch(e=>{console.error(e);process.exit(1)});
