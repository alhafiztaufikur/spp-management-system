/* Read-only catalog UI checks; financial behavior is covered by isolated HTTP tests. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=process.env.SPP_SAVINGS_QA,base=process.env.SPP_TEST_BASE_URL;
assert.ok(root&&!path.resolve(root).toLowerCase().startsWith(process.cwd().toLowerCase()));assert.equal(new URL(base).hostname,'127.0.0.1');
const fixture=JSON.parse(fs.readFileSync(root+'/fixture.json','utf8'));let checks=0;
function check(ok,message){assert.ok(ok,message);checks++;}
(async()=>{
 for(const engine of ['chrome','msedge','chromium109']){
  const browser=await chromium.launch(engine==='chromium109'?{headless:true,executablePath:process.env.SPP_CHROMIUM109_EXECUTABLE}:{headless:true,channel:engine});
  try{
   const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.sessions.super_admin.id,url:base}]);await context.route('https://fonts.googleapis.com/**',r=>r.abort());
   const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
   for(const unit of [1,2,3]){
    await context.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:fixture.sessions.super_admin.csrf,next:'/pembayaran/form.php'}});
    const item=fixture.students[unit];
    for(const theme of ['light','dark'])for(const width of [1440,768,390]){
     await page.setViewportSize({width,height:1000});await page.goto(base+'/master_biaya_lain.php');await page.evaluate(t=>{document.documentElement.dataset.theme=t;localStorage.setItem('spp_theme',t)},theme);
     check(await page.locator('#form-terbit-biaya').count()===0,'Publisher removed');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Master overflow');
     await page.goto(base+'/pembayaran/form.php');await page.evaluate(nis=>{const input=document.getElementById('siswa-search');input.value=nis;pilihSiswaDatalist(input);},fixture.nis);
     await page.waitForFunction(id=>selectedStudentOption()?.dataset.otherFeeChoicesLoaded==='1'&&[...document.querySelector('.biaya-lain-select').options].some(o=>o.value==='master:'+id),item.master);
     await page.evaluate(id=>{const select=document.querySelector('.biaya-lain-select');select.value='master:'+id;select.dispatchEvent(new Event('change',{bubbles:true}));},item.master);
     const row=page.locator('.biaya-lain-row').first();check(await row.locator('.biaya-lain-nominal').inputValue()==='100.000','Master prefill');
     check(await page.evaluate(()=>{const summary=selectedBiayaLainSummary();return summary.total===0&&summary.paid===0}),'Optional reference does not become academic debt');
     const labels=await row.locator('.ll-field-label').allTextContents();check(labels.includes('Tarif Referensi')&&labels.includes('Nominal Dibayar'),'Optional labels');
     check(await row.locator('.biaya-lain-paid').evaluate(input=>getComputedStyle(input.closest('label')).display==='none'),'Paid hidden');check(await row.locator('.biaya-lain-sisa').evaluate(input=>getComputedStyle(input.closest('label')).display==='none'),'Remaining hidden');
     check(await row.locator('.biaya-lain-select').evaluate(s=>s.validationMessage===''),'Repeated optional purchase available');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Input overflow '+engine+'/'+unit+'/'+width);
     const groups=await row.locator('optgroup').evaluateAll(groups=>groups.map(g=>g.label));check(groups.includes('Biaya Lain Opsional')&&groups.includes('Pelunasan Tagihan Lama'),'Separated choices');
     const trigger=row.locator('.ll-field-kind .spp-select-trigger');await trigger.click();
     const menu=page.locator('#'+await trigger.getAttribute('aria-controls'));check((await menu.locator('.spp-dropdown-group').allTextContents()).includes('Biaya Lain Opsional'),'Visible catalog group');await page.keyboard.press('Escape');check(await trigger.getAttribute('aria-expanded')==='false','Catalog keyboard close');
     const legacyId=await row.locator('select').evaluate(s=>[...s.options].find(o=>o.value&&!o.value.startsWith('master:'))?.value);
     if(legacyId){await row.locator('select').evaluate((s,id)=>{s.value=id;s.dispatchEvent(new Event('change',{bubbles:true}))},legacyId);check(await row.locator('.biaya-lain-paid').evaluate(i=>getComputedStyle(i.closest('label')).display!=='none'),'Legacy payment shows already paid');check(await row.locator('.biaya-lain-sisa').evaluate(i=>getComputedStyle(i.closest('label')).display!=='none'),'Legacy payment shows remaining');await row.locator('select').evaluate((s,id)=>{s.value='master:'+id;s.dispatchEvent(new Event('change',{bubbles:true}))},item.master);}
     if(unit===1&&engine==='chrome'&&theme==='light'&&(width===390||width===1440))await row.screenshot({path:root+'/outputs/optional-input-'+width+'.png'});
     await page.locator('#btn-add-biaya-lain').click();await page.evaluate(id=>{const rows=[...document.querySelectorAll('.biaya-lain-row')];rows[1].querySelector('select').value='master:'+id;refreshBiayaLainAvailability();},item.master);
     check(await page.locator('.biaya-lain-select').nth(1).evaluate(s=>s.validationMessage.length>0),'Duplicate master blocked');
     await page.goto(base+'/pembayaran/edit.php?id='+item.http_payment);await page.waitForFunction(()=>!!window.refreshBiayaLainOptions);
     const saved=await page.locator('.biaya-lain-nominal').first().inputValue();
     const snapshot=await page.evaluate(id=>{
      const select=document.querySelector('.biaya-lain-select'),before=select.options[select.selectedIndex].dataset.baseLabel;
      selectedStudentOption().dataset.otherFeeChoicesLoaded='0';window.sppOtherFeeMasters.find(m=>m.id==='master:'+id).nama='Nama master baru untuk pengujian';refreshBiayaLainOptions();
      return {before,after:select.options[select.selectedIndex].dataset.baseLabel,value:select.value};
     },item.master);
     check(snapshot.before===snapshot.after&&snapshot.value==='master:'+item.master,'Editing preserves name snapshot');check(await page.locator('.biaya-lain-nominal').first().inputValue()===saved,'Editing preserves actual amount');
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Edit overflow');
    }
   }
   check(errors.length===0,'Browser errors '+errors.join('; '));await context.close();console.log('PASS '+engine+' optional catalog and payment layouts');
  }finally{await browser.close();}
 }
 console.log('PASS '+checks+' catalog, repeated purchase, snapshot, duplicate and responsive checks.');
})().catch(e=>{console.error(e);process.exitCode=1});
