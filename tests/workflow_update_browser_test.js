'use strict';
const {chromium}=require('playwright-core'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=process.env.SPP_SAVINGS_QA,base=process.env.SPP_TEST_BASE_URL,f=JSON.parse(fs.readFileSync(path.join(root,'workflow-fixture.json'),'utf8')),manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8'));
let checks=0;const check=(value,message)=>{assert.ok(value,message);checks++;};
(async()=>{
 check(process.env.SPP_TEST_ALLOW_MUTATION==='1'&&new URL(base).hostname==='127.0.0.1','Disposable loopback only');
 const identity=await(await fetch(base+'/tests/browser_clone_identity.php')).json();check(identity.server_uuid===manifest.server_uuid&&identity.port===manifest.port,'Owned private instance');
 const engine=process.env.SPP_UI_BROWSER||'chrome';const launch={headless:true};if(engine==='chromium109')launch.executablePath=process.env.SPP_CHROMIUM109;else launch.channel=engine;
 const browser=await chromium.launch(launch);const outputs=path.join(root,'browser');fs.mkdirSync(outputs,{recursive:true});
 try{
  const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:f.sessions.admin.id,url:base}]);await context.route('https://fonts.googleapis.com/**',r=>r.abort());const p=await context.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));await p.addInitScript(()=>{window.print=()=>{};});
  const switchUnit=async(unit,role='admin')=>{const session=f.sessions[role];const response=await context.request.post(base+'/unit_switch.php',{form:{unit_id:String(unit),csrf_token:session.csrf,next:'/pembayaran/lihat.php'},maxRedirects:0});check(response.status()===303,'Unit switch '+unit);};
  for(const unit of [1,2,3]){
   await switchUnit(unit);const item=f.units[unit];
   for(const theme of ['light','dark'])for(const width of [1440,768,390]){
    await p.setViewportSize({width,height:1000});await p.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);
    for(const route of ['master_biaya_lain.php','master_kelas.php?source_level='+item.level+'&promotion_mode=custom#naik-kelas','siswa/daftar.php?edit='+item.wrong_student,'pembayaran/lihat.php?q='+item.nis,'pembayaran/riwayat_daftar_ulang.php?q='+item.nis]){
     const response=await p.goto(base+'/'+route);check(response.status()===200,'Page '+route);check(!/Fatal error|Warning:|Parse error/.test(await p.locator('body').textContent()),'PHP clean '+route);check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Overflow '+route+'/'+width+'/'+theme);
    }
    await p.goto(base+'/pembayaran/form.php');await p.evaluate(nis=>{const input=document.getElementById('siswa-search');input.value=nis;pilihSiswaDatalist(input);},item.nis);
    await p.locator('#spp-warning-overlay.show').waitFor();check((await p.locator('#spp-warning-title').textContent()).includes('Kewajiban'),'Automatic obligation popup');check((await p.locator('#spp-warning-amount').textContent()).includes('PSB'),'PSB obligation');await p.keyboard.press('Escape');check(!await p.locator('#spp-warning-overlay').evaluate(e=>e.classList.contains('show')),'Popup Escape');
    await p.waitForFunction(()=>document.querySelector('#payment-history-body')?.dataset.historyState==='ready');await p.selectOption('#payment-history-scope','all');await p.waitForFunction(()=>document.querySelectorAll('#payment-history-body tr').length>500);check(await p.locator('#payment-history-body tr').count()>500,'Uncapped history');
    check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Payment form overflow');
    await p.goto(base+'/tabungan/riwayat.php?nis='+item.nis+'&jenis=masuk&id='+item.in+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-31');check(await p.locator('[data-savings-open=edit]').textContent().then(t=>t.trim()==='Edit'),'Admin direct action');
    await p.locator('[data-savings-open=edit]').click();const edit=p.locator('[data-savings-editor=edit]');check(await edit.evaluate(e=>e.open),'Editor opens');await edit.locator('[name=nominal]').fill('90000');await edit.locator('[name=nominal]').dispatchEvent('input');check(await edit.locator('[name=nominal]').inputValue()==='90.000','Thousands format');await edit.locator('[name=reason]').fill('Periksa perubahan untuk pengujian dialog');await edit.locator('.sw-editor-stage button[type=submit]').click();check(await edit.locator('.sw-review-stage').isVisible(),'Review stage');await p.keyboard.press('Escape');check(!await edit.evaluate(e=>e.open),'Editor Escape');check(await p.locator('[data-savings-open=edit]').evaluate(e=>e===document.activeElement),'Focus return');
    await p.locator('[data-savings-open=hapus]').click();check(await p.locator('[data-savings-editor=hapus]').evaluate(e=>e.open),'Delete opens');await p.keyboard.press('Escape');await p.locator('[data-savings-activity]').click();await p.waitForFunction(()=>document.querySelector('.sw-activity-dialog')?.open);check(await p.locator('.sw-activity-dialog form').count()===0,'Read-only activity');await p.keyboard.press('Escape');
    if(width===390&&theme==='dark')await p.screenshot({path:path.join(outputs,engine+'-savings-'+unit+'.png'),fullPage:true});
   }
   await p.setViewportSize({width:1440,height:1000});await p.goto(base+'/laporan/cetak_struk.php?id='+item.payment);await p.evaluate(()=>document.fonts.ready);check(await p.locator('body').textContent().then(t=>t.includes('Sisa PSB (terkini)')&&t.includes('Sisa DU')),'Default slip balances');
   await p.screenshot({path:path.join(outputs,engine+'-slip-'+unit+'.png'),fullPage:true});await p.pdf({path:path.join(outputs,engine+'-slip-'+unit+'.pdf'),preferCSSPageSize:true,displayHeaderFooter:false});
   const download=p.waitForEvent('download',{timeout:120000});await p.locator('[data-receipt-png]').click();const file=await download;check(file.suggestedFilename().endsWith('.png'),'Normal receipt must be one PNG');await file.saveAs(path.join(outputs,engine+'-slip-'+unit+'-export.png'));
  }
  await switchUnit(1);await p.goto(base+'/pembayaran/form.php');await p.evaluate(()=>{const input=document.getElementById('siswa-search');input.value='1444443';pilihSiswaDatalist(input);});await p.waitForFunction(()=>window.sppStatusState?.payload?.code==='covered_psb'||document.getElementById('spp-context-label')?.textContent.includes('tercakup'));check(await p.locator('#spp-context-label').textContent().then(t=>t.includes('tercakup')),'Apis coverage explanation');
  check(errors.length===0,'Browser errors: '+errors.join('; '));await context.close();console.log('PASS '+checks+' '+engine+' workflow browser checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
