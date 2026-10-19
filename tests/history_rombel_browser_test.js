'use strict';
const {chromium}=require('playwright-core'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
const base=process.env.SPP_TEST_BASE_URL,qa=process.env.SPP_SAVINGS_QA,out=process.env.SPP_HISTORY_ARTIFACTS;
const f=JSON.parse(fs.readFileSync(path.join(qa,'rombel-fixture.json'),'utf8'));let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
(async()=>{
  assert.equal((await(await fetch(base+'/tests/browser_clone_identity.php')).json()).port,64421);fs.mkdirSync(out,{recursive:true});
  const browser=await chromium.launch({channel:process.env.SPP_UI_BROWSER||'chrome',headless:true});
  try{
    const c=await browser.newContext();await c.addCookies([{name:'PHPSESSID',value:f.sessions.super_admin.id,url:base}]);await c.route('https://fonts.googleapis.com/**',r=>r.abort());
    const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
    const goto=async route=>{const r=await p.goto(base+'/'+route);check(r.status()===200,'HTTP '+route);check(!/Fatal error|Warning:|Parse error/.test(await p.locator('body').textContent()),'Runtime '+route);};
    for(const unit of [1,2,3]){
      const item=f.units[unit];await c.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:f.sessions.super_admin.csrf,next:'/dashboard.php'},maxRedirects:0});
      for(const theme of ['light','dark'])for(const width of [1650,1024,390]){
        await p.setViewportSize({width,height:1000});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
        await goto('pembayaran/form.php');const sizes=await p.evaluate(()=>[document.querySelector('#bulan-bayar').nextElementSibling,document.querySelector('#tahun-bayar')].map(e=>[e.getBoundingClientRect().width,e.getBoundingClientRect().height]));check(Math.abs(sizes[0][0]-sizes[1][0])<2&&sizes[0][1]===sizes[1][1],'Equal month/year dimensions '+width+': '+JSON.stringify(sizes));
        await p.locator('#tahun-bayar').click();await p.locator('.payment-year-option[data-year="2027"]').click();check(await p.locator('#tahun-bayar').inputValue()==='2027','Year selection value');await p.keyboard.press('Escape');await p.locator('#tahun-bayar').fill('2017');check(await p.locator('#tahun-bayar').inputValue()==='2017','Manual year retained');await p.keyboard.press('Escape');await p.locator('#tahun-bayar').fill('2026');await p.keyboard.press('Escape');
        await p.evaluate(nis=>{const input=document.getElementById('siswa-search');input.value=nis;pilihSiswaDatalist(input);},item.browser_nis);await p.waitForFunction(()=>document.getElementById('payment-history-body').dataset.historyState==='ready');await p.locator('#spp-warning-overlay.show').waitFor();await p.keyboard.press('Escape');await p.waitForFunction(()=>getComputedStyle(document.getElementById('spp-warning-overlay')).opacity==='0');
        check(await p.locator('#payment-history-body td').first().evaluate(e=>parseFloat(getComputedStyle(e).fontSize))>=13,'Readable history body');check(await p.locator('.history-total').first().evaluate(e=>getComputedStyle(e).fontSize)==='15px','Readable history amount');await p.locator('#student-history').screenshot({path:path.join(out,`history-${unit}-${theme}-${width}.png`)});
        check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Payment page fits');
        await goto('siswa/daftar.php?edit='+item.student_id);await p.locator('[data-class-picker-button]').click();check(await p.locator('[data-class-picker-search]').count()===0,'Class search removed');await p.keyboard.press('ArrowDown');check(await p.evaluate(()=>document.activeElement.matches('[data-class-picker-option]')),'Class keyboard navigation retained');await p.keyboard.press('Escape');
        check(await p.locator('.student-panel-discounts svg').count()===0,'Discount has no icons');const layout=await p.locator('.student-panel-discounts .student-money-grid').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length);check(layout===(width<=600?1:2),'Paired discount layout');
        await p.locator('#student-psb').fill('1000000');await p.locator('#student-psb').dispatchEvent('input');await p.locator('#student-potongan-psb').fill('500000');await p.locator('#student-potongan-psb').dispatchEvent('input');check(await p.locator('#student-total-psb').inputValue()==='500.000','PSB derived total unchanged');await p.locator('#student-daftar_ulang').fill('2000000');await p.locator('#student-daftar_ulang').dispatchEvent('input');await p.locator('#student-potong-du').fill('500000');await p.locator('#student-potong-du').dispatchEvent('input');check(await p.locator('#student-total-du').inputValue()==='1.500.000','DU preview calculation unchanged');
        await p.locator('.student-panel-discounts').screenshot({path:path.join(out,`discount-${unit}-${theme}-${width}.png`)});check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Student page fits');
        for(const [route,id] of [['pembayaran/lihat.php','payment-history-rombel'],['tabungan/riwayat.php','savings-history-rombel']]){
          await goto(route+'?rombel[]='+item.custom_id+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-31');check(await p.locator('#'+id).evaluate(e=>e.multiple&&[...e.selectedOptions].some(o=>Number(o.value)>0)),'Current rombel selected');check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'History filter page fits '+route);
          const trigger=p.locator('#'+id+' + button');await trigger.click();check(await p.locator('.spp-multi-panel:visible').count()===1,'Rombel checkbox dropdown opens');await p.keyboard.press('Escape');
          if(route.startsWith('tabungan'))check(!(await p.locator('body').innerText()).includes('Filter Operator membatasi'),'Operator note removed');
          await p.screenshot({path:path.join(out,`${id}-${unit}-${theme}-${width}.png`)});
        }
        await goto('otorisasi_transaksi.php?view=history&menu=tabungan');await p.locator('.auth-record').first().click();await p.locator('.auth-savings-event').first().waitFor();check(await p.locator('.auth-savings-comparison').count()>0,'Savings change comparison cards');check(await p.locator('.auth-savings-event time').first().textContent().then(t=>t.includes('WIB')),'Activity time WIB');check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Authorization fits');await p.locator('.auth-detail-panel').screenshot({path:path.join(out,`activity-${unit}-${theme}-${width}.png`)});
      }
    }
    await c.request.post(base+'/unit_switch.php',{form:{unit_id:'0',csrf_token:f.sessions.super_admin.csrf,next:'/dashboard.php'},maxRedirects:0});await goto('pembayaran/lihat.php');check(await p.locator('#payment-history-rombel optgroup').count()===3,'All-unit options grouped');
    check(errors.length===0,'No JS errors: '+errors.join('; '));fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({checks,errors},null,2));console.log('PASS '+checks+' UI and interaction checks.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
