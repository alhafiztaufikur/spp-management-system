'use strict';
const {chromium}=require('playwright-core'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=process.env.SPP_SAVINGS_QA,base=process.env.SPP_TEST_BASE_URL;
const fixture=JSON.parse(fs.readFileSync(path.join(root,'rombel-fixture.json'),'utf8'));
let checks=0;const check=(ok,msg)=>{assert.ok(ok,msg);checks++;};
(async()=>{
  assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
  const identity=await(await fetch(base+'/tests/browser_clone_identity.php')).json();
  assert.equal(identity.port,64421);
  const browser=await chromium.launch({headless:true,channel:process.env.SPP_UI_BROWSER||'chrome'});
  const errors=[];
  try{
    const context=await browser.newContext();
    await context.addCookies([{name:'PHPSESSID',value:fixture.sessions.super_admin.id,url:base}]);
    await context.route('https://fonts.googleapis.com/**',r=>r.abort());
    const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));
    await p.addInitScript(()=>{window.print=()=>{};});
    const goto=async route=>{const r=await p.goto(base+'/'+route);check(r.status()===200,'HTTP '+route);check(!/Fatal error|Warning:|Parse error/.test(await p.locator('body').textContent()),'Runtime '+route);};
    for(const unit of [1,2,3]){
      const item=fixture.units[unit];
      const r=await context.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:fixture.sessions.super_admin.csrf,next:'/master_kelas.php'},maxRedirects:0});check(r.status()===303,'Switch unit');
      for(const theme of ['light','dark'])for(const width of [1440,390]){
        await p.setViewportSize({width,height:1000});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
        await goto('master_kelas.php?source_year_id='+item.target_year_id+'&source_level='+item.target_level+'#naik-kelas');
        check(await p.locator('.class-year-roster').isVisible(),'Year roster visible');
        check((await p.locator('[name=source_year_id] option').allTextContents()).some(t=>t.includes('2027/2028')),'Future year offered');
        check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Master overflow '+width);
        await goto('siswa/daftar.php?edit='+item.student_id);
        await p.locator('[data-class-picker-button]').click();
        const first=p.locator('[data-class-picker-option]').first();check((await first.textContent()).includes('Out'),'Out before PSB');
        await p.locator('[data-class-picker-search]').fill('Tabungan Guru');
        check(await p.locator('[data-class-picker-option]:visible').count()>0,'Custom search');
        await p.keyboard.press('ArrowDown');check(await p.evaluate(()=>document.activeElement.hasAttribute('data-class-picker-option')),'Dropdown arrow navigation');
        await p.keyboard.press('Enter');check(!await p.locator('[data-class-picker-panel]').isVisible(),'Dropdown selection');check((await p.locator('#student-rate-status').textContent()).includes('Tidak diterbitkan'),'Nonacademic tariff status');
        await p.locator('[data-class-picker-button]').click();await p.keyboard.press('Escape');check(!await p.locator('[data-class-picker-panel]').isVisible(),'Dropdown Escape');
        check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Student overflow '+width);
        await p.screenshot({path:path.join(root,'outputs',`student-${unit}-${theme}-${width}.png`),fullPage:true});
        await goto('pembayaran/form.php');
        await p.evaluate(nis=>{const input=document.getElementById('siswa-search');input.value=nis;pilihSiswaDatalist(input);},item.browser_nis);
        await p.locator('#spp-warning-overlay.show').waitFor();
        await p.waitForFunction(()=>getComputedStyle(document.getElementById('spp-warning-overlay')).opacity==='1');
        check(await p.locator('#spp-warning-amount .obligation-line').count()>1,'Structured obligations');
        check((await p.locator('.obligation-total').textContent()).includes('Total Kewajiban'),'Obligation total');
        await p.screenshot({path:path.join(root,'outputs',`obligations-${unit}-${theme}-${width}.png`)});
        await p.keyboard.press('Escape');
        await p.waitForFunction(()=>document.getElementById('payment-history-body')?.dataset.historyState==='ready');
        check(await p.locator('#payment-history-body tr').count()===3,'Student history rows');
        check(await p.locator('.history-method').count()===3,'Modern history method labels');
        const select=p.locator('.biaya-lain-select').first();
        await p.waitForFunction(id=>!!document.querySelector('.biaya-lain-select option[value="master:'+id+'"]'),item.master_id);
        await select.selectOption('master:'+item.master_id);
        const amount=p.locator('.biaya-lain-nominal').first();check((await amount.inputValue())==='125.000','Autofill exact tariff');
        await p.selectOption('#sistem-pembayaran','Tunai');
        for(const value of ['110000','130000']){
          await amount.fill(value);await amount.dispatchEvent('input');
          await p.locator('#btn-input').click();await p.locator('#spp-warning-overlay.show').waitFor();
          check((await p.locator('#spp-warning-title').textContent()).includes('harus lunas'),'Exact amount popup');
          check((await p.locator('#spp-warning-message').textContent()).includes('125.000'),'Popup tariff');
          await p.keyboard.press('Escape');check(await amount.evaluate(e=>e===document.activeElement),'Focus returns to amount');
        }
        await amount.fill('125000');await amount.dispatchEvent('input');
        await p.locator('#payment-history-panel').scrollIntoViewIfNeeded();
        await p.screenshot({path:path.join(root,'outputs',`history-${unit}-${theme}-${width}.png`)});
        check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Payment overflow '+width);
        await goto('pembayaran/riwayat_daftar_ulang.php?q='+item.nis);
        check(await p.locator('.du-record-receipts [data-print-payment]').count()===2,'DU per-installment receipt buttons');
        check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'DU overflow '+width);
        await goto('master_biaya_lain.php');check(await p.locator('#fee-publish-form button[type=submit] svg').count()===1,'Publish button icon');
      }
      await goto('laporan/cetak_struk.php?id='+item.du_payment_ids[0]);
      check(!/terkini|Sisa Komite Oktober|Uang SPP \(/.test(await p.locator('.receipt-sheet').textContent()),'Clean receipt labels');
      const position=await p.evaluate(()=>{const time=document.querySelector('.receipt-pay-time'),method=time.previousElementSibling;return {time:time.getBoundingClientRect().top,method:method.getBoundingClientRect().bottom};});
      check(position.time>=position.method,'Time below payment method');
      await p.emulateMedia({media:'print'});
      await p.pdf({path:path.join(root,'outputs',`browser-receipt-${unit}.pdf`),preferCSSPageSize:true,printBackground:true});
      await p.emulateMedia({media:'screen'});
    }
    check(errors.length===0,'JS errors: '+errors.join('; '));
    const result='PASS: '+checks+' browser assertions across three units, two themes, desktop/mobile.';fs.writeFileSync(path.join(root,'browser-result.txt'),result);console.log(result);
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
