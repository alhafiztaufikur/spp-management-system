const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8')),out=process.argv[3];
 const b=await chromium.launch({headless:true,executablePath:'C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe'});
 try{for(const width of [390,1366])for(const js of [true,false]){
  const c=await b.newContext({viewport:{width,height:900},javaScriptEnabled:js});try{
   await c.route('https://**/*',r=>r.abort());await c.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);const p=await c.newPage();
   await p.goto(f.base+'/master_kelas.php');await p.locator('[name=lg_q]').fill('1A');await p.locator('[name=lg_q]').press('Enter');
   await p.waitForURL(/lg_q=1A/);assert(await p.locator('#legacy-groups tbody tr').count()===1);
   await p.locator('#legacy-groups tbody a').click();await p.waitForURL(/legacy_accounts.php/);assert(new URL(p.url()).searchParams.has('group'));
   await p.getByRole('link',{name:'Kembali ke Kelompok',exact:false}).click();await p.waitForURL(/master_kelas.php/);assert(await p.locator('[name=lg_q]').inputValue()==='1A');
   const correction=f.routes.find(r=>r.startsWith('legacy_correction'));
   if(correction){await p.goto(f.base+'/'+correction);await p.locator('[name=reason]').fill('Pratinjau UI terisolasi, tidak menerapkan perubahan.');
    await p.getByRole('button',{name:'Pratinjau Dampak Koreksi'}).press('Enter');await p.waitForLoadState();
    const confirm=p.locator('#full-confirm');assert(await confirm.count()===1,'Correction preview failed: '+(await p.locator('body').innerText()).slice(-900));
    if(js){assert(await p.locator('dialog[open]').count()===1);await p.screenshot({path:path.join(out,'modal-'+width+'.png')});await p.keyboard.press('Escape');await p.waitForLoadState();}
    else{await p.getByRole('button',{name:'Kembali Mengubah'}).press('Enter');await p.waitForLoadState();}
    assert(await p.locator('[name=reason]').inputValue()==='Pratinjau UI terisolasi, tidak menerapkan perubahan.','Cancelling preview lost input');
   }
   await p.goto(f.base+'/master_kelas.php');if(js){await p.locator('#legacy-groups').scrollIntoViewIfNeeded();await p.screenshot({path:path.join(out,'workspace-'+width+'.png')});}
  }finally{await c.close();}
 }
 // Browser zoom is represented by the equivalent CSS viewport and pixel density.
 for(const scale of [.8,1.25,2]){
  const viewport={width:Math.round(1366/scale),height:Math.round(900/scale)};
  const c=await b.newContext({viewport,deviceScaleFactor:scale});try{
   await c.route('https://**/*',r=>r.abort());await c.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);const p=await c.newPage();
   const correction=f.routes.find(r=>r.startsWith('legacy_correction'));
   await p.goto(f.base+'/'+correction);await p.locator('[name=reason]').fill('Pratinjau zoom terisolasi; tidak menerapkan perubahan.');await p.getByRole('button',{name:'Pratinjau Dampak Koreksi'}).press('Enter');
   const footer=await p.locator('.lful-modal-footer').boundingBox();assert(footer&&footer.y+footer.height<=viewport.height+1,'Zoom clipped confirmation actions');
   assert(await p.locator('dialog[open]').count()===1);await p.keyboard.press('Tab');assert(await p.evaluate(()=>Boolean(document.activeElement.closest('dialog'))),'Keyboard focus escaped dialog');
   await p.screenshot({path:path.join(out,'modal-zoom-'+Math.round(scale*100)+'.png')});await p.keyboard.press('Escape');await p.waitForLoadState();
  }finally{await c.close();}
 }
 }finally{await b.close();}
 console.log('PASS: server search, group navigation, retained context, correction preview, Escape and no-JS cancellation preserve inputs; no final writes.');
})().catch(e=>{console.error(e);process.exit(1)});
