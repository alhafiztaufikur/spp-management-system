/* All financial mutations require a verified disposable MySQL instance and private sessions. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_REFINEMENT_QA,base=process.env.SPP_TEST_BASE_URL;
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.ok(root&&process.env.SPP_TEST_ISOLATED_INSTANCE);assert.equal(new URL(base).hostname,'127.0.0.1');
const fixture=JSON.parse(fs.readFileSync(root+'/fixture.json','utf8'));let checks=0;
function check(ok,message){assert.ok(ok,message);checks++;}
function state(){return JSON.parse(cp.execFileSync('php',['tests/savings_refinement_fixture.php','state'],{encoding:'utf8'}));}
const savingsUrl=item=>base+'/tabungan/riwayat.php?nis='+item.nis+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-31&jenis=masuk&id='+item.in;
(async()=>{
 const identity=await fetch(base+'/tests/browser_clone_identity.php').then(r=>r.json());check(identity.isolated_test===true,'HTTP instance isolation');
 const browser=await chromium.launch({channel:process.env.SPP_UI_BROWSER||'chrome',headless:true});
 try{
  for(const unit of [1,2,3]){
   const session=fixture.sessions[unit].super_admin,item=fixture.students[unit];
   const context=await browser.newContext({acceptDownloads:true});await context.addCookies([{name:'PHPSESSID',value:session.id,url:base}]);
   await context.addInitScript(()=>window.print=()=>{});await context.route('https://fonts.googleapis.com/**',r=>r.abort());
   const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
   page.on('dialog',dialog=>{errors.push('Native dialog: '+dialog.message());dialog.dismiss();});
   for(const theme of ['light','dark'])for(const width of [1440,768,390]){
    await page.setViewportSize({width,height:960});await page.goto(base+'/otorisasi_transaksi.php?menu=tabungan&view=history');
    await page.evaluate(t=>{document.documentElement.dataset.theme=t;localStorage.setItem('spp_theme',t)},theme);
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Authorization overflow');
    const geometry=await page.locator('.authorization-filter-form').evaluate(form=>{
     const fields=[...form.querySelectorAll('.field-row')].map(el=>{const r=el.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width}});
     const buttons=[...form.querySelectorAll('.authorization-filter-actions .btn')].map(el=>{const r=el.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}});return {fields,buttons};
    });
    check(geometry.buttons[1].x>=geometry.buttons[0].x+geometry.buttons[0].width+10,'Filter button gap');
    check(geometry.buttons.every(b=>b.height>=46),'Filter button height');
    if(width===1440)check(geometry.fields.slice(0,3).every(f=>Math.abs(f.y-geometry.fields[0].y)<1),'Three aligned filters');
    if(width===390)check(geometry.fields.every((f,i,a)=>!i||f.y>a[i-1].y),'Mobile stacked filters');
    await page.goto(savingsUrl(item));await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
    const form=page.locator('form[data-savings-confirm]').first();await page.locator('[data-savings-open="edit"]').click();
    await form.locator('[name="nominal"]').fill('90000');check(await form.locator('[name="nominal"]').inputValue()==='90.000','Thousands formatting');
    await form.locator('[name="reason"]').fill('Salah input nominal pada transaksi.');await form.locator('button[type="submit"]').click();
    const dialog=page.locator('[data-savings-editor="edit"]');check(await dialog.getAttribute('open')!==null,'App confirmation');
    check((await dialog.innerText()).includes(item.name)&&(await dialog.innerText()).includes('Rp 90.000'),'Confirmation identity and amount');
    check(await dialog.evaluate(d=>{const r=d.getBoundingClientRect();return r.x>=0&&r.right<=innerWidth&&r.y>=0&&r.bottom<=innerHeight+1}),'Dialog viewport bounds');
    await page.keyboard.press('Shift+Tab');check(await page.locator('.sw-editor-dialog[open] [data-sw-accept]').evaluate(b=>b===document.activeElement),'Keyboard focus trap');
    await page.keyboard.press('Escape');check(await dialog.getAttribute('open')===null,'Escape closes');
    check(await page.locator('[data-savings-open="edit"]').evaluate(b=>b===document.activeElement),'Focus restored');
    if(unit===1&&width===1440&&theme==='light'){await page.locator('[data-savings-open="edit"]').click();await form.locator('button[type="submit"]').click();await page.screenshot({path:root+'/outputs/correction-dialog.png'});await page.locator('.sw-editor-dialog[open] [data-sw-cancel]').click();}
    const deleteForm=page.locator('form[data-savings-confirm]').nth(1);await page.locator('[data-savings-open="hapus"]').click();await deleteForm.locator('[name="reason"]').fill('Transaksi ganda perlu dibatalkan.');await deleteForm.locator('button[type="submit"]').click();
    check(await page.locator('[data-savings-editor="hapus"]').getAttribute('data-danger')==='true'&&(await page.locator('.sw-editor-dialog[open] [data-sw-accept]').innerText())==='Hapus Transaksi','Delete confirmation');await page.locator('.sw-editor-dialog[open] [data-sw-cancel]').click();
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Savings page overflow');
    if(unit===1&&width===1440&&theme==='light')await page.screenshot({path:root+'/outputs/authorization-placeholder.png'});
    await page.goto(base+'/tabungan/cetak_struk.php?jenis=masuk&id='+item.in);check(await page.locator('.document-title').innerText()==='BUKTI TRANSAKSI TABUNGAN','SPP style savings receipt');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Savings preview overflow');
   }
   await page.setViewportSize({width:1440,height:960});
   await page.goto(base+'/pembayaran/form.php');
   const expected=new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Jakarta',month:'2-digit'}).format(new Date());check(await page.locator('#bulan-bayar').inputValue()===expected,'Current WIB month');
   await page.locator('#bulan-bayar').selectOption('09');await page.evaluate(n=>{const s=document.getElementById('siswa-search');s.value=n;pilihSiswaDatalist(s)},item.nis);
   await page.waitForFunction(()=>document.getElementById('payment-history-body').dataset.historyState==='ready');
   check(await page.locator('#payment-history-body tr').count()===50,'Last 50 payments');check(await page.locator('#bulan-bayar').inputValue()==='09','Manual older month preserved');
   await page.locator('#bulan-bayar').selectOption('12');await page.evaluate(n=>{const s=document.getElementById('siswa-search');s.value=n;pilihSiswaDatalist(s)},item.empty_nis);
   await page.waitForFunction(()=>document.getElementById('payment-history-body').dataset.historyState==='empty');check(await page.locator('#bulan-bayar').inputValue()==='12','Manual future month preserved');
   await page.evaluate(async nis=>{window.paymentDraft={no_induk:nis,bulan_bayar:'08',tahun_bayar:'2025'};await restorePaymentDraft();window.paymentDraft=null;},item.nis);
   check(await page.locator('#bulan-bayar').inputValue()==='08'&&await page.locator('#tahun-bayar').inputValue()==='2025','Draft period overrides default');
   // Simulate late responses that ignore abort, which must still fail the generation check.
   await page.evaluate(async nis=>{
    window.realHistoryFetch=window.fetch;window.fetch=(url,opts)=>{
     if(!String(url).includes('history_siswa.php'))return realHistoryFetch(url,opts);
     const n=new URL(String(url),location.href).searchParams.get('no_induk'),old=n===nis;
     return new Promise(resolve=>setTimeout(()=>resolve(new Response(JSON.stringify({ok:true,period:old?'OLD':'NEW',rows:[{operator:old?'OLD_OPERATOR':'NEW_OPERATOR'}]}),{status:200,headers:{'Content-Type':'application/json'}})),old?200:20));
    };
    document.getElementById('disp-nis').value=nis;refreshPaymentHistory(nis);
    document.getElementById('disp-nis').value=nis+'E';refreshPaymentHistory(nis+'E');
    await new Promise(r=>setTimeout(r,260));
   },item.nis);
   check((await page.locator('#payment-history-body').innerText()).includes('NEW_OPERATOR')&&!(await page.locator('#payment-history-body').innerText()).includes('OLD_OPERATOR'),'Stale history response ignored');
   await page.evaluate(async nis=>{document.getElementById('disp-nis').value=nis;refreshPaymentHistory(nis);document.getElementById('disp-nis').value='';refreshPaymentHistory('');await new Promise(r=>setTimeout(r,260));},item.nis);
   check(await page.locator('#payment-history-body').getAttribute('data-history-state')==='unselected','Clear cancels history');
   for(const [code,status,payload] of [['session_expired',401,{}],['student_scope_mismatch',409,{ok:false,code:'student_scope_mismatch'}],['unit_changed',409,{ok:false,code:'unit_changed'}],['history_unavailable',503,{ok:false,message:'SQL secret must not be displayed'}],['invalid_response',200,'<html>Error</html>'],['network_error',0,null]]){
    await page.evaluate(async ({nis,status,payload})=>{
     window.fetch=(url,opts)=>String(url).includes('history_siswa.php')?(status===0?Promise.reject(new TypeError('Network failed')):Promise.resolve(new Response(typeof payload==='string'?payload:JSON.stringify(payload),{status,headers:{'Content-Type':typeof payload==='string'?'text/html':'application/json'}}))):realHistoryFetch(url,opts);
     document.getElementById('disp-nis').value=nis;await refreshPaymentHistory(nis);
    },{nis:item.nis,status,payload});
    check(await page.locator('#payment-history-body').getAttribute('data-history-state')===code,'History state '+code);check(!(await page.locator('#payment-history-body').innerText()).includes('SQL secret'),'Safe error wording');
   }
   await page.evaluate(()=>window.fetch=window.realHistoryFetch);await page.locator('[data-history-retry]').click();await page.waitForFunction(()=>document.getElementById('payment-history-body').dataset.historyState==='ready');
   check(await page.locator('#payment-history-body tr').count()===50,'Retry recovers');
   const invalid=await context.request.get(base+'/pembayaran/history_siswa.php?no_induk[]=bad');check(invalid.status()===400&&(await invalid.json()).code==='invalid_request','Array parameter rejected');
   const wrongUnit=await context.request.get(base+'/pembayaran/history_siswa.php?no_induk='+item.nis+'&unit_id='+(unit===1?2:1));check(wrongUnit.status()===409&&(await wrongUnit.json()).code==='unit_changed','Unit changed response');
   const wrongId=await context.request.get(base+'/pembayaran/history_siswa.php?no_induk='+item.nis+'&student_id='+fixture.students[unit===1?2:1].sid);check(wrongId.status()===409,'Student ID isolation');
   const expired=await browser.newContext();const expiredResponse=await expired.request.get(base+'/pembayaran/history_siswa.php?no_induk='+item.nis);check(expiredResponse.status()===401&&(await expiredResponse.json()).code==='session_expired','Real session expiry');await expired.close();
   // Real correction, replay and deletion on the disposable fixture.
   await page.goto(savingsUrl(item));
   const form=page.locator('form[data-savings-confirm]').first();await page.locator('[data-savings-open="edit"]').click();
   const amount=form.locator('[name="nominal"]');await amount.fill('0');await form.locator('[name="reason"]').fill('Koreksi untuk uji nominal.');await form.locator('button[type="submit"]').click();check(await page.locator('.sw-editor-dialog[open] .sw-review-stage').isHidden(),'Zero rejected before review');
   await amount.fill('1234567');check(await amount.inputValue()==='1.234.567','Millions format');
   await amount.evaluate(input=>{input.setSelectionRange(2,2);input.setRangeText('9',2,2,'end');input.dispatchEvent(new Event('input',{bubbles:true}));});check(await amount.inputValue()==='19.234.567'&&await amount.evaluate(i=>i.selectionStart)===2,'Middle edit caret');
   await amount.fill('90000');
   check(await amount.evaluate(input=>{const clipboardData=new DataTransfer();clipboardData.setData('text/plain','9.000,50');const event=new ClipboardEvent('paste',{bubbles:true,cancelable:true,clipboardData});input.dispatchEvent(event);return event.defaultPrevented&&input.value==='90.000';}),'Malformed paste rejected');
   await amount.fill('90000');
   const replay=await form.evaluate(f=>Object.fromEntries(new FormData(f)));let posts=0;page.on('request',r=>{if(r.method()==='POST'&&r.url().endsWith('/tabungan/koreksi.php'))posts++});
   await form.locator('button[type="submit"]').click();await Promise.all([page.waitForNavigation(),page.locator('.sw-editor-dialog[open] [data-sw-accept]').evaluate(b=>{b.click();b.click()})]);
   check(posts===1,'One correction POST');let data=state()[unit];check(Number(data.in.amount)===90000&&Number(data.balance)===80000&&Number(data.in.user_id)===item.owner,'Direct correction canonical amount and original owner');
   await context.request.post(base+'/tabungan/koreksi.php',{form:replay});data=state()[unit];check(Number(data.in.amount)===90000,'Stale form does not change amount');
   await page.goto(savingsUrl(item));const rowLink=page.locator('.sw-transaction[data-kind="keluar"][data-id="'+item.out+'"] .sw-transaction-select');await rowLink.click();await page.waitForFunction(()=>!document.getElementById('sw-history-detail').hasAttribute('aria-busy'));
   const deletion=page.locator('form[data-savings-confirm]').nth(1);await page.locator('[data-savings-open="hapus"]').click();await deletion.locator('[name="reason"]').fill('Penarikan salah pada data uji.');await deletion.locator('button[type="submit"]').click();check((await page.locator('.sw-editor-dialog[open]').innerText()).includes('Rp 10.000'),'AJAX detail confirmation');
   if(unit===1)await page.screenshot({path:root+'/outputs/delete-dialog.png'});
   await page.locator('.sw-editor-dialog[open] [data-sw-cancel]').click();check(state()[unit].out!==null,'Cancel deletion unchanged');
   // Export both transaction types before applying the deletion.
   for(const [kind,id] of [['masuk',item.in],['keluar',item.out]]){
    await page.goto(base+'/tabungan/cetak_struk.php?jenis='+kind+'&id='+id);
    check((await page.locator('.identity').innerText()).includes('PSB'),'PSB class label preserved');
    const pdf=await context.request.get(base+'/tabungan/cetak_struk.php?jenis='+kind+'&id='+id+'&output=pdf');check(pdf.status()===200&&(await pdf.body()).subarray(0,5).toString()==='%PDF-','Server PDF');fs.writeFileSync(root+'/outputs/'+unit+'-'+kind+'.pdf',await pdf.body());
    const promise=page.waitForEvent('download');await page.locator('[data-receipt-png]').click();const download=await promise;check(download.suggestedFilename().endsWith('.png'),'Single PNG');await download.saveAs(root+'/outputs/'+unit+'-'+kind+'.png');await page.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);
    await page.pdf({path:root+'/outputs/'+unit+'-'+kind+'-browser.pdf',preferCSSPageSize:true});
    if(unit===1&&kind==='masuk')await page.screenshot({path:root+'/outputs/receipt.png',fullPage:true});
   }
   await page.goto(savingsUrl(item).replace('jenis=masuk&id='+item.in,'jenis=keluar&id='+item.out));
   const deleteAgain=page.locator('form[data-savings-confirm]').nth(1);await page.locator('[data-savings-open="hapus"]').click();await deleteAgain.locator('[name="reason"]').fill('Penarikan salah pada data uji.');await deleteAgain.locator('button[type="submit"]').click();await Promise.all([page.waitForNavigation(),page.locator('.sw-editor-dialog[open] [data-sw-accept]').click()]);
   check(state()[unit].out===null&&Number(state()[unit].balance)===90000,'Delete recalculates balance');
   await page.goto(base+'/pembayaran/edit.php?id='+item.pid);check(await page.locator('#bulan-bayar').inputValue()==='10'&&await page.locator('#tahun-bayar').inputValue()==='2026','Edit period preserved');
   check(errors.length===0,'Browser errors: '+errors.join('; '));await context.close();console.log('PASS unit '+unit+' ('+checks+' checks)');
  }
  // Request-only roles see approval wording and cannot change balances immediately.
  for(const role of ['admin','kasir']){
   const item=fixture.students[1],session=fixture.sessions[1][role],context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:session.id,url:base}]);const page=await context.newPage();await page.goto(savingsUrl(item));
   const form=page.locator('form[data-savings-confirm]').first();await page.locator('[data-savings-open="edit"]').click();await form.locator('[name="nominal"]').fill('95000');await form.locator('[name="reason"]').fill('Pengajuan koreksi nominal oleh petugas.');await form.locator('button[type="submit"]').click();
   check((await page.locator('.sw-editor-dialog[open]').innerText()).includes('setelah pengajuan disetujui'),'Proposal copy');await Promise.all([page.waitForNavigation(),page.locator('.sw-editor-dialog[open] [data-sw-accept]').click()]);
   check(Number(state()[1].in.amount)===90000,'Proposal does not change transaction');
   const cancel=page.locator('form').filter({has:page.locator('input[name="action"][value="cancel"]')});await Promise.all([page.waitForNavigation(),cancel.locator('button').click()]);
   const deletion=page.locator('form[data-savings-confirm]').nth(1);await page.locator('[data-savings-open="hapus"]').click();await deletion.locator('[name="reason"]').fill('Pengajuan hapus oleh petugas untuk pengujian.');await deletion.locator('button[type="submit"]').click();
   check((await page.locator('.sw-editor-dialog[open]').innerText()).includes('setelah pengajuan disetujui')&&await page.locator('.sw-editor-dialog[open] [data-sw-accept]').innerText()==='Ajukan Hapus','Delete proposal confirmation');
   await Promise.all([page.waitForNavigation(),page.locator('.sw-editor-dialog[open] [data-sw-accept]').click()]);check(state()[1].in!==null&&Number(state()[1].balance)===90000,'Delete proposal preserves transaction and balance');
   const cancelDelete=page.locator('form').filter({has:page.locator('input[name="action"][value="cancel"]')});await Promise.all([page.waitForNavigation(),cancelDelete.locator('button').click()]);
   await context.close();
  }
  console.log('PASS '+checks+' refinement browser, endpoint and disposable financial checks.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
