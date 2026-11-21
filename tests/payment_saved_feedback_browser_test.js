/* Real POST/redirect/GET on a restored, separately identified MySQL server. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
const base=process.env.SPP_TEST_BASE_URL;assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
const fixture=JSON.parse(fs.readFileSync(process.env.SPP_PAYMENT_SAVED_FIXTURE,'utf8'));
const phpArgs=['-d','session.save_path='+process.env.SPP_PHP_SESSION_PATH,path.join(__dirname,'payment_saved_feedback_fixture.php'),'state'];
const state=()=>JSON.parse(execFileSync(process.env.SPP_PHP||'php',phpArgs,{encoding:'utf8',env:process.env}));
const values=html=>{
 const form=html.match(/<form\b[^>]*\bid="form-bayar"[^>]*>[\s\S]*?<\/form>/);assert(form,'Payment form');
 return Object.fromEntries(['csrf_token','request_key'].map(name=>{const match=form[0].match(new RegExp('name="'+name+'" value="([a-f0-9]+)"'));assert(match,name);return[name,match[1]];}));
};
function postFields(payload,tokens){
 const result=new URLSearchParams();for(const[k,v]of Object.entries({...payload,...tokens})){
  if(Array.isArray(v))v.forEach(x=>result.append(k+'[]',x));else result.set(k,String(v));
 }return result.toString();
}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const errors=[];let posts=0;
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}});
  // Printing is observed without opening a physical printer dialog.
  await context.addInitScript(()=>{window.print=()=>{window.__testPrintInvoked=true;};});
  const page=await context.newPage();page.setDefaultTimeout(20000);page.on('pageerror',e=>errors.push(e.message));
  let identity;
  for(let i=0;i<30;i++){try{identity=await(await context.request.get(base+'/tests/browser_clone_identity.php')).json();break;}catch(e){if(i===29)throw e;await new Promise(r=>setTimeout(r,100));}}
  assert.equal(identity.isolated_test,true);assert.notEqual(identity.port,3306);
  for(const[k,item]of Object.entries(fixture.cases)){
   if(k==='nojs')continue;
   await context.addCookies([{name:'PHPSESSID',value:fixture.sessions[item.unit],url:base}]);
   let payload,tokens;
   if(k==='browser'){
    await page.goto(base+'/pembayaran/form.php');
    await page.locator('#siswa-search').fill(item.name);
    await page.waitForFunction(nis=>document.getElementById('disp-nis').value===nis,item.payload.no_induk);
    await page.waitForFunction(()=>!document.getElementById('psb-input').readOnly);
    // Selecting a student loads the existing obligation summary asynchronously.
    await page.locator('#spp-warning-overlay.show').waitFor({state:'visible'});
    await page.locator('#spp-warning-close').click();
    await page.locator('#sistem-pembayaran').selectOption('Tunai');
    await page.locator('#psb-input').fill('50000');
    await page.evaluate(nis=>sessionStorage.setItem('paymentDraftReload',JSON.stringify({source_path:location.pathname,edit_id:0,no_induk:nis,uang_psb:'50000'})),item.payload.no_induk);
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#btn-input').click()]).catch(async error=>{
     throw new Error('Browser submit: '+JSON.stringify(await page.evaluate(()=>({fields:[...document.querySelectorAll('#form-bayar input,#form-bayar select')].filter(n=>n.id).map(n=>[n.id,n.value,n.validationMessage]),warnings:[...document.querySelectorAll('#spp-warning-title,#spp-warning-message')].map(n=>n.textContent)}))),{cause:error});
    });
    assert.equal(await page.evaluate(()=>sessionStorage.getItem('paymentDraftReload')),null,'Successful input clears its browser draft');
   }else{
    const form=await context.request.get(base+'/pembayaran/form.php');assert.equal(form.status(),200);
    tokens=values(await form.text());payload=postFields(item.payload,tokens);
    const response=await context.request.post(base+'/pembayaran/proses.php',{data:payload,headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
    assert.equal(response.status(),302,k);assert.equal(response.headers().location,'form.php',k+' returns to input');
    await page.goto(base+'/pembayaran/form.php');
   }
   posts++;
   assert.equal(new URL(page.url()).pathname,'/pembayaran/form.php');
   const dialog=page.locator('#payment-saved-dialog[open]');
   await dialog.waitFor({timeout:15000}).catch(async error=>{throw new Error(k+': '+(await page.locator('#flash-msg,#spp-warning-message,#operation-feedback-message').allTextContents()).join(' | '),{cause:error});});
   assert.equal(await page.locator('#operation-feedback-dialog[open],#receipt-print-modal').count(),0,'Exactly one result popup');
   assert.equal(await dialog.locator('h2').innerText(),'Pembayaran berhasil disimpan');
   assert.equal(await page.locator('#siswa-search').inputValue(),'');assert.equal(await page.locator('#disp-nis').inputValue(),'');
   assert.equal(await page.locator('#sistem-pembayaran').inputValue(),'');
   assert.equal(await page.evaluate(()=>window.paymentDraft),null);
   assert.notEqual((await page.locator('[name=request_key]').first().inputValue()),tokens?.request_key,'Next input gets a fresh request key');
   const rows=state()[k];assert.equal(rows.length,1,k+' stored once');assert.equal(Number(rows[0].total_jumlah),item.total,k+' financial total unchanged');
   const receipt=await dialog.locator('#payment-saved-print').getAttribute('href');assert.equal(new URL(receipt,page.url()).searchParams.get('id'),String(rows[0].id));
   if(k==='psb'){
    assert.equal(await page.locator('#payment-saved-continue').evaluate(n=>n===document.activeElement),true);
    await page.keyboard.press('Tab');assert.equal(await page.locator('#payment-saved-print').evaluate(n=>n===document.activeElement),true);
    for(const width of [390,1440])for(const theme of ['light','dark']){
     await page.setViewportSize({width,height:1000});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
     assert(await dialog.evaluate(n=>n.scrollWidth<=n.clientWidth+1&&n.getBoundingClientRect().right<=innerWidth),'Dialog fits desktop/mobile');
     await page.screenshot({path:path.join(process.env.SPP_UI_OUTPUT,`saved-${width}-${theme}.png`)});
    }
    await page.locator('#payment-saved-continue').click();
    await page.reload();assert.equal(await page.locator('#payment-saved-dialog').count(),0,'Refresh does not repeat result');
    const replay=await context.request.post(base+'/pembayaran/proses.php',{data:payload,headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});assert.equal(replay.status(),302);assert.equal(state()[k].length,1,'Replaying a committed POST cannot duplicate payment');
   }else if(k==='spp'){
    const popupPromise=page.waitForEvent('popup');await page.locator('#payment-saved-print').click();const receiptPage=await popupPromise;await receiptPage.waitForLoadState();assert.match(await receiptPage.locator('body').innerText(),new RegExp(item.name));await receiptPage.close();
    assert.equal(new URL(page.url()).pathname,'/pembayaran/form.php');
   }else if(k==='komite')await page.keyboard.press('Escape');
   else await page.locator('#payment-saved-continue').click();
   if(k!=='psb'){
    await page.waitForFunction(()=>document.activeElement?.id==='siswa-search');
    assert.equal(await page.locator('#payment-saved-dialog[open]').count(),0);
   }
   console.log('PASS '+k+' saved once, one input popup, fresh empty form and correct receipt');
  }
  await context.addCookies([{name:'PHPSESSID',value:fixture.sessions[1],url:base}]);
  const badTokens=values(await(await context.request.get(base+'/pembayaran/form.php')).text());
  const bad={...fixture.cases.psb.payload,uang_psb:'0'};
  const failed=await context.request.post(base+'/pembayaran/proses.php',{data:postFields(bad,badTokens),headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});assert.equal(failed.headers().location,'form.php');
  await page.goto(base+'/pembayaran/form.php');assert.equal(await page.locator('#payment-saved-dialog').count(),0,'Failures never show success');assert.equal((await page.evaluate(()=>window.paymentDraft)).no_induk,bad.no_induk,'Failure preserves submitted draft');
  const warning=page.locator('#spp-warning-overlay.show,#operation-feedback-dialog[open]');await warning.first().waitFor({state:'visible'});assert.equal(await warning.count(),1,'Existing failure feedback remains');
  const plain=await browser.newContext({javaScriptEnabled:false});await plain.addCookies([{name:'PHPSESSID',value:fixture.sessions[1],url:base}]);
  const plainPage=await plain.newPage(),plainTokens=values(await(await plain.request.get(base+'/pembayaran/form.php')).text());
  const nojs=await plain.request.post(base+'/pembayaran/proses.php',{data:postFields(fixture.cases.nojs.payload,plainTokens),headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});assert.equal(nojs.headers().location,'form.php');
  await plainPage.goto(base+'/pembayaran/form.php');assert.equal(await plainPage.locator('#flash-msg').isVisible(),true);assert.equal(await plainPage.locator('#flash-msg a').innerText(),'Cetak struk');assert.equal(await plainPage.locator('#payment-saved-dialog').isVisible(),false);assert.equal(state().nojs.length,1);await plain.close();posts++;
  await context.addCookies([{name:'PHPSESSID',value:fixture.sessions[2],url:base}]);
  execFileSync(process.env.SPP_PHP||'php',[...phpArgs.slice(0,-1),'scope-mismatch'],{encoding:'utf8',env:process.env});
  await page.goto(base+'/pembayaran/form.php');assert.equal(await page.locator('#payment-saved-dialog').count(),0,'Saved result must match the active operational unit');
  assert.equal(await page.locator('#flash-msg a[href*="cetak_struk"]').count(),0,'Do not offer a saved receipt from another unit');
  assert.deepEqual(errors,[],'No page errors');
  fs.writeFileSync(path.join(process.env.SPP_UI_OUTPUT,'results.json'),JSON.stringify({posts,passed:true}));
  console.log('PASS 10 real saves across three units, mixed obligations, replay protection, keyboard/focus, receipt tab, no-JS, errors and unit scope.');
  await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
