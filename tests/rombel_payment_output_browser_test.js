'use strict';
const {chromium}=require('playwright-core'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=process.env.SPP_SAVINGS_QA,base=process.env.SPP_TEST_BASE_URL,f=JSON.parse(fs.readFileSync(path.join(root,'rombel-fixture.json'),'utf8'));
const output=process.env.SPP_POLISH_OUTPUTS||path.join(root,'outputs');fs.mkdirSync(output,{recursive:true});
(async()=>{
  const identity=await(await fetch(base+'/tests/browser_clone_identity.php')).json();assert.equal(identity.port,64421);assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
  for(const engine of ['chrome','msedge']){
    const browser=await chromium.launch({headless:true,channel:engine});
    try{
      const context=await browser.newContext({acceptDownloads:true});await context.addCookies([{name:'PHPSESSID',value:f.sessions.super_admin.id,url:base}]);await context.addInitScript(()=>window.print=()=>{});
      const p=await context.newPage();
      for(const unit of [1,2,3]){
        await context.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:f.sessions.super_admin.csrf,next:'/dashboard.php'},maxRedirects:0});
        const url=base+'/laporan/cetak_struk.php?id='+f.units[unit].du_payment_ids[0];
        const native=await context.request.get(url+'&output=pdf');assert.equal(native.status(),200);assert.equal((await native.body()).subarray(0,4).toString(),'%PDF');fs.writeFileSync(path.join(output,`native-${engine}-${unit}.pdf`),await native.body());
        await p.goto(url);await p.evaluate(()=>document.fonts.ready);
        assert.equal(await p.locator('.receipt-sheet').count(),1);
        await p.pdf({path:path.join(output,`browser-${engine}-${unit}.pdf`),preferCSSPageSize:true,printBackground:true});
        if(unit===1){
          const download=p.waitForEvent('download',{timeout:60000});await p.locator('[data-receipt-png]').click();await(await download).saveAs(path.join(output,`${engine}-receipt.png`));
          await p.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);
          await p.evaluate(()=>{const first=document.querySelector('.receipt-sheet');first.classList.remove('receipt-last');for(let i=1;i<12;i++){const copy=first.cloneNode(true);if(i===11)copy.classList.add('receipt-last');document.body.append(copy);}});
          await p.pdf({path:path.join(output,`${engine}-annual.pdf`),preferCSSPageSize:true,printBackground:true});
          const zip=p.waitForEvent('download',{timeout:120000});await p.locator('[data-receipt-png]').click();await(await zip).saveAs(path.join(output,`${engine}-annual.zip`));
          await p.waitForFunction(()=>!document.querySelector('[data-receipt-png]').disabled);assert.equal(await p.locator('canvas,.receipt-image-holder,.receipt-print-page').count(),0);
        }
      }
      console.log('PASS '+engine+': native/browser PDF, PNG and 12-receipt PNG ZIP.');
    }finally{await browser.close();}
  }
  fs.writeFileSync(path.join(root,'output-browser-result.txt'),'PASS Chrome/Edge: three-unit native/browser PDF, single PNG, annual PDF and PNG ZIP.');
})().catch(e=>{console.error(e);process.exitCode=1;});
