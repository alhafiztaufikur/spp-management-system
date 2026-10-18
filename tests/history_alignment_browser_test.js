const assert=require('node:assert/strict'),fs=require('node:fs'),cp=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_SAVINGS_QA,base=process.env.SPP_TEST_BASE_URL;
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.ok(process.env.SPP_TEST_ISOLATED_INSTANCE);assert.equal(new URL(base).hostname,'127.0.0.1');
const f=JSON.parse(fs.readFileSync(root+'/alignment-fixture.json','utf8'));let checks=0;
function check(ok,msg){assert.ok(ok,msg);checks++;}
const url=(unit,extra={})=>base+'/tabungan/riwayat.php?'+new URLSearchParams({nis:f.history_nis,tanggal_awal:'2026-10-01',tanggal_akhir:'2026-10-31',jenis:'masuk',id:f.history_students[unit].numeric,...extra});
const state=()=>JSON.parse(cp.execFileSync('php',['tests/history_alignment_state.php'],{encoding:'utf8'}));
async function switchUnit(c,role,unit){const s=f.sessions[role];if(role!=='bendahara')await c.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:s.csrf,next:'/dashboard.php'}});}
async function contextFor(browser,role){const c=await browser.newContext({acceptDownloads:true});await c.addCookies([{name:'PHPSESSID',value:f.sessions[role].id,url:base}]);await c.addInitScript(()=>window.print=()=>{});await c.route('https://fonts.googleapis.com/**',r=>r.abort());return c;}
(async()=>{
 check((await (await fetch(base+'/tests/browser_clone_identity.php')).json()).isolated_test===true,'Verified isolated HTTP server');
 for(const engine of process.env.SPP_ALIGNMENT_ONLY_SUBMISSION==='1'?[]:(process.env.SPP_UI_BROWSER?[process.env.SPP_UI_BROWSER]:['chrome','msedge'])){
  const browser=await chromium.launch(engine==='chromium109'?{executablePath:process.env.SPP_CHROMIUM109_EXECUTABLE,headless:true}:{channel:engine,headless:true});
  try{
   const c=await contextFor(browser,'super_admin'),p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));p.on('dialog',d=>{errors.push('Native dialog '+d.message());d.dismiss()});
   for(const unit of [1,2,3,0]){
    await switchUnit(c,'super_admin',unit);const item=f.history_students[unit||1];
    for(const theme of ['light','dark'])for(const width of [1440,768,390]){
     await p.setViewportSize({width,height:1000});await p.goto(url(unit||1));await p.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t},theme);
     check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Savings overflow '+unit+'/'+width);
     check(await p.locator('#savings-history-operator').getAttribute('multiple')!==null,'Multiple operator control');
     check(await p.locator('[data-savings-open="edit"]').count()===(unit?1:0),'Super direct action and all-unit read-only');
     await p.locator('[data-savings-activity]').click();await p.waitForFunction(()=>!document.querySelector('[data-activity-body]').textContent.includes('Memuat riwayat'));
     check(await p.locator('.sw-activity-dialog').isVisible()&&await p.locator('.sw-activity-dialog form').count()===0,'Read-only activity modal');await p.keyboard.press('Escape');
     if(unit){
      await p.locator('[data-savings-open="edit"]').click();let dlg=p.locator('dialog[data-savings-editor="edit"]');
      check(await dlg.isVisible(),'Edit dialog');check(await dlg.evaluate(d=>d.scrollWidth<=d.clientWidth+1),'Edit dialog overflow');
      await dlg.locator('[name="nominal"]').fill('90000');check(await dlg.locator('[name="nominal"]').inputValue()==='90.000','Thousands formatting');
      await dlg.locator('[name="reason"]').fill('Pemeriksaan dialog tanpa menyimpan.');await dlg.locator('[type="submit"]').click();
      check(await dlg.locator('.sw-review-stage').isVisible()&&(await dlg.innerText()).includes('90.000'),'Same dialog review');await p.keyboard.press('Shift+Tab');check(await dlg.locator('[data-sw-accept]').evaluate(button=>button===document.activeElement),'Review keyboard focus trap');await dlg.locator('[data-sw-back]').click();check(await dlg.locator('[name="nominal"]').inputValue()==='90.000','Back preserves fields');await p.keyboard.press('Escape');
      check(await p.locator('[data-savings-open="edit"]').evaluate(b=>b===document.activeElement),'Restores opener focus');
      await p.locator('[data-savings-open="hapus"]').click();check(await p.locator('dialog[data-danger="true"][open]').count()===1,'Danger delete dialog');await p.keyboard.press('Escape');
     }
     if(engine==='chrome'&&unit===1&&theme==='light'&&width!==768)await p.locator('#sw-history-detail').screenshot({path:root+'/outputs/alignment-detail-'+width+'.png'});
    }
    if(unit){
     await p.setViewportSize({width:1440,height:1000});const payment=f.students[unit].http_payment;
     await p.goto(base+'/pembayaran/lihat.php?search='+f.nis+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-31');
     check(await p.locator('.ph-record-card .history-receipt-button').count()>0,'Payment card print buttons');check(await p.locator('a a').count()===0,'No nested links');
     const print=p.locator('.ph-record-card .history-receipt-button[href$="id='+payment+'"]').first();const before=p.url();
     const popup=await Promise.all([p.waitForEvent('popup'),print.click()]).then(r=>r[0]);await popup.waitForLoadState('domcontentloaded');check(p.url()===before&&new URL(popup.url()).searchParams.get('id')===String(payment),'Card prints selected receipt without selecting detail');await popup.close();
     const du=item.du;if(du){await p.goto(base+'/pembayaran/riwayat_daftar_ulang.php?'+new URLSearchParams({q:du.NO_INDUK,tahun_ajaran:du.th_ajaran,selected:du.bill_id,selected_unit:unit}));check(await p.locator('[data-du-transaction] .history-receipt-button').count()>0,'Print each DU installment');check(await p.locator('[data-registration-record] .history-receipt-button').count()===0,'No aggregate DU print');}
    }
   }
   check(errors.length===0,'Browser errors '+errors.join('; '));await c.close();
   for(const role of ['admin','kasir','bendahara']){
    const c=await contextFor(browser,role);await switchUnit(c,role,1);const p=await c.newPage();await p.goto(url(1));
    check(await p.locator('[data-savings-open="edit"]').count()===(role==='bendahara'?0:1),'Role controls '+role);
    if(role!=='bendahara')check((await p.locator('[data-savings-open="edit"]').innerText()).includes('Ajukan'),'Role proposal label');
    await p.locator('[data-savings-activity]').click();await p.waitForFunction(()=>!document.querySelector('[data-activity-body]').textContent.includes('Memuat riwayat'));check(await p.locator('.sw-activity-dialog [name="action"]').count()===0,'Role has no decision controls');await c.close();
   }
   console.log('PASS '+engine+' layout, role dialogs and transaction receipt buttons');
  }finally{await browser.close()}
 }
 if(process.env.SPP_ALIGNMENT_ONLY_LAYOUT==='1'){console.log('PASS '+checks+' history layout and compatibility checks.');return;}
 // Real dialog submission, cancellation, approval and single delivery on the owned instance.
 const b=await chromium.launch({channel:'chrome',headless:true});
 try{
  const superC=await contextFor(b,'super_admin');await switchUnit(superC,'super_admin',1);const p=await superC.newPage();await p.goto(url(1,{'operator[]':String(f.actors.kasir.id)}));
  const before=state(),directAmount=before.row.MASUK+50,proposedAmount=directAmount+10;await p.locator('[data-savings-open="edit"]').click();let dlg=p.locator('dialog[data-savings-editor="edit"]');
  await dlg.locator('[name="nominal"]').fill('0');await dlg.locator('[name="reason"]').fill('Uji validasi nominal nol.');await dlg.locator('[type="submit"]').click();check(await dlg.locator('.sw-review-stage').isHidden(),'Validation before review');
  await dlg.locator('[name="nominal"]').fill(String(directAmount));await dlg.locator('[type="submit"]').click();check(state().balance===before.balance,'Review does not mutate');await dlg.locator('[data-sw-cancel]').click();check(state().balance===before.balance,'Cancel does not mutate');
  await p.locator('[data-savings-open="edit"]').click();await dlg.locator('[type="submit"]').click();
  await Promise.all([p.waitForNavigation(),dlg.locator('[data-sw-accept]').evaluate(button=>{button.click();button.click()})]);
  check(state().row.MASUK===directAmount&&state().balance===before.balance+50,'Single direct correction');check(await p.locator('#savings-history-operator').evaluate((select,id)=>[...select.selectedOptions].some(o=>o.value===String(id)),f.actors.kasir.id),'Operator retained after correction');
  const adminC=await contextFor(b,'admin');await switchUnit(adminC,'admin',1);const a=await adminC.newPage();await a.goto(url(1));await a.locator('[data-savings-open="edit"]').click();dlg=a.locator('dialog[data-savings-editor="edit"]');await dlg.locator('[name="nominal"]').fill(String(proposedAmount));await dlg.locator('[name="reason"]').fill('Pengajuan koreksi untuk uji persetujuan.');await dlg.locator('[type="submit"]').click();check((await dlg.innerText()).includes('setelah pengajuan disetujui'),'Proposal balance explanation');await Promise.all([a.waitForNavigation(),dlg.locator('[data-sw-accept]').click()]);
  check(state().row.MASUK===directAmount&&state().request.status==='pending','Proposal waits for approval');check(await a.locator('[data-savings-open]').count()===0,'Pending locks mutation');
  await Promise.all([a.waitForNavigation(),a.getByRole('button',{name:'Batalkan Pengajuan Saya'}).click()]);check(state().request.status==='cancelled'&&state().row.MASUK===directAmount,'Own cancellation');
  await a.locator('[data-savings-open="edit"]').click();dlg=a.locator('dialog[data-savings-editor="edit"]');await dlg.locator('[name="nominal"]').fill(String(proposedAmount));await dlg.locator('[name="reason"]').fill('Pengajuan koreksi untuk uji persetujuan.');await dlg.locator('[type="submit"]').click();await Promise.all([a.waitForNavigation(),dlg.locator('[data-sw-accept]').click()]);
  await superC.request.post(base+'/tabungan/koreksi.php',{form:{action:'approve',request_id:state().request.id,csrf_token:f.sessions.super_admin.csrf,_operational_unit:'1'}});check(state().row.MASUK===proposedAmount&&state().request.status==='approved','Super approves proposal');
  check(state().row.user_id===before.row.user_id,'Correction preserves original operator');
  const cashC=await contextFor(b,'kasir');await switchUnit(cashC,'kasir',1);const cash=await cashC.newPage();await cash.goto(url(1));
  await cash.locator('[data-savings-open="hapus"]').click();dlg=cash.locator('dialog[data-savings-editor="hapus"]');await dlg.locator('[name="reason"]').fill('Pengajuan pembatalan transaksi untuk pengujian.');await dlg.locator('[type="submit"]').click();
  const deletionBalance=state().balance;await Promise.all([cash.waitForNavigation(),dlg.locator('[data-sw-accept]').click()]);check(state().row.MASUK===proposedAmount&&state().balance===deletionBalance&&state().request.status==='pending','Delete request does not mutate balance');
  await superC.request.post(base+'/tabungan/koreksi.php',{form:{action:'approve',request_id:state().request.id,csrf_token:f.sessions.super_admin.csrf,_operational_unit:'1'}});check(state().row===null&&state().balance===deletionBalance-proposedAmount,'Approved delete reconciles balance');await cashC.close();
  await p.goto(url(1,{view:'archive','operator[]':String(f.actors.kasir.id)}));check(await p.locator('[data-savings-open]').count()===0&&await p.locator('.sw-detail-print-actions a').filter({hasText:'Cetak Bukti Transaksi'}).count()===0,'Archive has no mutation or active receipt');
  // AJAX replacement invalidates an open form and closes the old dialog.
  await p.goto(url(1,{id:f.history_students[1].proof}));await p.locator('[data-savings-open="hapus"]').click();await p.evaluate(()=>document.querySelector('.sw-transaction-select').click());await p.waitForFunction(()=>!document.querySelector('[data-savings-editor][open]'));check(await p.locator('[data-savings-editor][open]').count()===0,'AJAX closes stale editor');
  await p.waitForFunction(()=>!document.querySelector('#sw-history-detail').hasAttribute('aria-busy'));await p.locator('[data-savings-activity]').click();await p.waitForFunction(()=>!document.querySelector('[data-activity-body]').textContent.includes('Memuat riwayat'));check(await p.locator('.sw-activity-dialog').isVisible(),'AJAX activity works');
  await adminC.close();await superC.close();
 }finally{await b.close()}
 console.log('PASS '+checks+' history alignment browser and submission checks.');
})().catch(e=>{console.error(e);process.exitCode=1});
