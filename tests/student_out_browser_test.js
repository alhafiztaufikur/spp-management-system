'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.equal(process.env.SPP_DB_PORT,'3320');
const file=process.env.SPP_OUT_FIXTURE,root=path.dirname(file),f=JSON.parse(fs.readFileSync(file,'utf8')),base=process.env.SPP_TEST_BASE_URL;
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);assert.equal(f.database,process.env.SPP_DB_NAME);
const state=()=>JSON.parse(execFileSync(process.env.SPP_PHP_BIN,[path.join(__dirname,'student_out_browser_fixture.php'),'state',file],{env:process.env,encoding:'utf8'}));
const checks=[];function check(ok,label){assert.ok(ok,label);checks.push(label);fs.writeFileSync(path.join(root,'out-browser-progress.json'),JSON.stringify({checks:checks.length,last:label}));}
const cash=s=>Object.fromEntries(['bayar','spp_alokasi_batch','allocations','transaksi_m','transaksi_k','tabungan'].map(k=>[k,s[k]]));
const values=locator=>locator.evaluate(form=>Object.fromEntries(new FormData(form)));
async function dismiss(p){for(const selector of ['#operation-feedback-dialog[open]','#spp-warning-overlay.show'])if(await p.locator(selector).isVisible())await p.locator(selector).getByRole('button',{name:'Mengerti',exact:true}).click();}
async function post(c,url,data){return c.request.post(base+url,{form:data,maxRedirects:0,timeout:120000});}
async function outError(c,url,data){const r=await post(c,url,data);let text=await r.text();if(r.headers().location)text=await(await c.request.get(new URL(r.headers().location,base+url).href,{timeout:120000})).text();check(/OUT/.test(text),'OUT explained by '+url);}
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[];
try{
 for(const item of f.cases){
  const c=await browser.newContext({viewport:{width:1440,height:1000}});await c.addCookies([{name:'PHPSESSID',value:item.session,url:base}]);await c.route('https://fonts.googleapis.com/**',r=>r.abort());const p=await c.newPage();p.setDefaultTimeout(120000);p.setDefaultNavigationTimeout(120000);p.on('pageerror',e=>errors.push(e.message));
  check((await(await c.request.get(base+'/tests/browser_clone_identity.php')).json()).database===f.database,'Owned HTTP restore');
  await p.goto(base+'/master_spp.php#spp-year-panel');await dismiss(p);
  check(await p.locator('.spp-workspace-tabs [role=tab]').count()===2,'Exactly two tabs '+item.unit+' '+item.role);
  check(await p.locator('#spp-students-panel').isVisible(),'Old year link opens students');
  check(await p.locator('#spp-year-panel,.spp-status-card,.spp-cancel-section').count()===0,'Old panels removed');
  const tabs=p.locator('.spp-workspace-tabs [role=tab]');await tabs.nth(0).focus();await p.keyboard.press('ArrowRight');check(await tabs.nth(1).getAttribute('aria-selected')==='true','ArrowRight selects students');await p.keyboard.press('Home');check(await tabs.nth(0).getAttribute('aria-selected')==='true','Home selects rates');await p.keyboard.press('End');check(await tabs.nth(1).getAttribute('aria-selected')==='true','End selects students');
  const token=await p.locator('#spp-rate-form input[name=csrf_token]').inputValue();
  if(item.role==='super_admin'){
   for(const theme of ['light','dark'])for(const width of [1600,390,320]){await p.setViewportSize({width,height:1000});await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);await p.waitForTimeout(350);check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No overflow '+item.unit+' '+theme+' '+width);check(await tabs.evaluateAll(es=>es.every(e=>e.getBoundingClientRect().width>=100)),'Two tabs fit');if(width!==320)await p.screenshot({path:path.join(root,'master-spp-'+item.unit+'-'+theme+'-'+width+'.png'),fullPage:true});}
   await p.setViewportSize({width:1440,height:1000});p.once('dialog',d=>d.accept());await p.locator('.spp-year-actions button').click();await p.waitForLoadState('networkidle');check((await p.locator('[data-sct-year-status]').innerText()).includes('CLOSED'),'Close year works');await dismiss(p);await p.locator('.spp-year-actions button').click();await p.waitForLoadState('networkidle');check(!(await p.locator('[data-sct-year-status]').innerText()).includes('CLOSED'),'Reopen year works');await dismiss(p);
  }
  const beforeManual=state()[item.unit+'|'+item.role];await post(c,'/master_spp.php',{csrf_token:token,aksi:'batalkan_mulai_bulan',tahun_ajaran:f.year,no_induk:item.nis,bulan:'01',tahun:'2027',_operational_unit:String(item.unit)});assert.deepEqual(state()[item.unit+'|'+item.role],beforeManual);check(true,'Stale manual cancellation changes zero');
  await p.goto(base+'/pembayaran/form.php');const paymentDraft=await values(p.locator('#form-bayar'));
  await p.goto(base+'/pembayaran/edit.php?id='+item.payment);const editDraft=await values(p.locator('form').filter({has:p.locator('input[name=uang_psb]')}).first());
  await p.goto(base+'/tabungan/masuk.php?student_id='+item.id);const savingsDraft=await values(p.locator('form').filter({has:p.locator('input[name=request_key]')}).first());
  await p.goto(base+'/tabungan/riwayat.php?student_id='+item.id+'&jenis=masuk&id='+item.journal+'&all_periods=1');const savingsEdit=await values(p.locator('.sw-correction-form').first());
  await p.goto(base+'/siswa/daftar.php?edit='+item.id);await dismiss(p);const studentDraft=await values(p.locator('#form-master-siswa'));
  const before=state()[item.unit+'|'+item.role];await post(c,'/siswa/daftar.php',{...studentDraft,master_kelas_id:String(item.out)});let after=state()[item.unit+'|'+item.role];check(after.siswa[0].KELAS==='OUT'&&Number(after.siswa[0].is_active)===0,'Master Siswa commits OUT');check(after.student_out_periods.length===before.student_out_periods.length+1&&after.student_out_periods.at(-1).returned_at===null,'One new OUT event');check(after.tagihan_spp.some(b=>b.status==='cancelled'&&b.out_period_id),'Future SPP cancelled');assert.deepEqual(cash(after),cash(before));check(true,'OUT preserves cash, allocations and savings');
  await outError(c,'/pembayaran/proses.php',{...paymentDraft,aksi:'input',no_induk:item.nis,uang_spp:'1000',bulan_bayar:'11',tahun_bayar:'2026',tanggal_bayar:'2026-11-11',payment_plan:'monthly'});
  await outError(c,'/pembayaran/proses.php',{...editDraft,aksi:'update',id:String(item.payment)});
  await outError(c,'/tabungan/proses.php',{...savingsDraft,aksi:'masuk',student_id:String(item.id),no_induk:item.nis,nominal:'1000'});
  await outError(c,'/tabungan/proses.php',{...savingsDraft,aksi:'keluar',student_id:String(item.id),no_induk:item.nis,nominal:'1000'});
  await outError(c,'/tabungan/koreksi.php',{...savingsEdit,action:'direct_edit',reason:'QA OUT stale correction'});
  await outError(c,'/tabungan/koreksi.php',{...savingsEdit,action:'direct_hapus',reason:'QA OUT stale cancellation'});
  assert.deepEqual(cash(state()[item.unit+'|'+item.role]),cash(before));check(true,'All rejected endpoints preserve financial columns');
  const receipt=await c.request.get(base+'/laporan/cetak_struk.php?id='+item.payment);check(receipt.status()===200,'OUT receipt remains readable');const history=await c.request.get(base+'/tabungan/riwayat.php?student_id='+item.id+'&all_periods=1');check(history.status()===200,'OUT savings history remains readable');
  await p.goto(base+'/siswa/daftar.php?edit='+item.id);await dismiss(p);const outDraft=await values(p.locator('#form-master-siswa'));await post(c,'/siswa/daftar.php',{...outDraft,master_kelas_id:String(item.class)});after=state()[item.unit+'|'+item.role];check(after.siswa[0].KELAS!=='OUT'&&Number(after.siswa[0].is_active)===1,'Official edit restores active membership');check(after.student_out_periods.at(-1).returned_at!==null,'OUT event closed');check(after.tagihan_spp.every(b=>b.status!=='cancelled'||!b.out_period_id),'Return in same month restores own future invoices');assert.deepEqual(after.tagihan_spp.map(b=>b.id),before.tagihan_spp.map(b=>b.id));assert.deepEqual(cash(after),cash(before));check(true,'Return preserves invoice IDs and every financial row');
  await c.close();console.log('PASS '+item.role+' unit '+item.unit);
 }
 const c=await browser.newContext();await c.addCookies([{name:'PHPSESSID',value:f.cases[0].session,url:base}]);const p=await c.newPage();await p.goto(base+'/master_spp.php');const scope=await values(p.locator('.sidebar-unit-form'));await post(c,'/unit_switch.php',{...scope,unit_id:'0',next:'/master_spp.php'});await p.goto(base+'/master_spp.php');check(await p.locator('.spp-overview-workspace').count()===1,'All Units retains read-only overview');check((await post(c,'/master_spp.php',{aksi:'batalkan_mulai_bulan',_operational_unit:'0'})).status()===409,'All Units writes rejected');await c.close();
 check(errors.length===0,'No browser exceptions: '+errors.join(', '));fs.writeFileSync(path.join(root,'out-browser-result.json'),JSON.stringify({passed:true,checks},null,2));console.log('PASS '+checks.length+' browser/HTTP checks');
}catch(error){fs.writeFileSync(path.join(root,'out-browser-failure.txt'),error.stack);throw error;}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
