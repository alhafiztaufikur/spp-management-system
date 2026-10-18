// Read-only responsive checks of the new buttons; no print dialog or financial POST.
const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_SAVINGS_QA,base=process.env.SPP_TEST_BASE_URL,f=JSON.parse(fs.readFileSync(root+'/alignment-fixture.json','utf8'));let checks=0;
function check(ok,msg){assert.ok(ok,msg);checks++;}
(async()=>{
 assert.equal(new URL(base).hostname,'127.0.0.1');check((await(await fetch(base+'/tests/browser_clone_identity.php')).json()).isolated_test===true,'Isolated server');
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const c=await browser.newContext();await c.addCookies([{name:'PHPSESSID',value:f.sessions.super_admin.id,url:base}]);await c.route('https://fonts.googleapis.com/**',r=>r.abort());const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  for(const unit of [1,2,3,0]){
   await c.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:f.sessions.super_admin.csrf,next:'/dashboard.php'}});
   const du=f.history_students[unit||1].du;
   const urls=['/pembayaran/lihat.php?search='+f.nis+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-31'];
   if(du)urls.push('/pembayaran/riwayat_daftar_ulang.php?'+new URLSearchParams({q:du.NO_INDUK,tahun_ajaran:du.th_ajaran,selected:du.bill_id,selected_unit:unit||1}));
   for(const theme of ['light','dark'])for(const width of [1440,768,390])for(const url of urls){
    await p.setViewportSize({width,height:1000});await p.goto(base+url);await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);
    check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Viewport overflow '+unit+'/'+width+'/'+url);
    check(await p.locator('.history-receipt-button').count()>0,'Receipt action exists');
    check(await p.locator('.history-receipt-button').evaluateAll(buttons=>buttons.every(b=>{const r=b.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1})),'Receipt actions fit viewport');
    check(await p.locator('a a').count()===0,'No nested links');
    if(unit===1&&theme==='light'&&width===390)await p.locator(url.includes('riwayat_daftar_ulang')?'.du-installment':'.ph-record-card').first().screenshot({path:root+'/outputs/'+(url.includes('riwayat_daftar_ulang')?'du':'payment')+'-receipt-button-mobile.png'});
   }
  }
  check(errors.length===0,'No page errors');await c.close();
  console.log('PASS '+checks+' receipt button responsive checks.');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
