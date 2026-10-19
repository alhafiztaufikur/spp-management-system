const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8')),out=process.argv[3];fs.mkdirSync(out,{recursive:true});const results=[];
 for(const exe of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
  const b=await chromium.launch({headless:true,executablePath:exe});try{
   for(const width of [390,768,1366])for(const theme of ['light','dark'])for(const js of [true,false]){
    const c=await b.newContext({viewport:{width,height:1000},javaScriptEnabled:js});try{
     await c.route('https://**/*',r=>r.abort());await c.addCookies([{name:'PHPSESSID',value:f.session,url:f.base}]);await c.addInitScript(theme=>localStorage.setItem('spp_theme',theme),theme);const p=await c.newPage();
     const r=await p.goto(f.base+'/master_kelas.php?special_new=1#special-classes');assert(r.status()===200);if(!js)await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);
     assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));
     assert(await p.locator('#special-class-form').getAttribute('open')!==null,'New form collapsed');
     assert(await p.locator('#special-class-form [name=tingkat]').count()===0,'Academic level leaked into special class form');
     const scroll=await p.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);assert(scroll[0]<=scroll[1]+2,'Horizontal page overflow '+scroll);
     await p.locator('[name=special_q]').fill(f.http_name);await p.locator('[name=special_q]').press('Enter');await p.waitForURL(/special_q=/);
     const row=p.locator('#special-classes .sc-table tbody tr').filter({hasText:f.http_name});assert(await row.count()===1);await row.getByRole('link',{name:'Hapus',exact:true}).click();await p.waitForURL(/special_delete=/);
     assert(await p.locator('#special-delete-confirm').count()===1);if(js){assert(await p.locator('dialog[open]').count()===1);await p.keyboard.press('Tab');assert(await p.evaluate(()=>Boolean(document.activeElement.closest('dialog'))),'Focus escaped modal');await p.keyboard.press('Escape');await p.waitForURL(url=>!url.searchParams.has('special_delete'));}
     else{await p.locator('[data-sc-back]').click();await p.waitForURL(url=>!url.searchParams.has('special_delete'));}
     await p.goto(f.base+'/master_kelas.php?special_edit='+f.class+'#special-classes');assert(await p.locator('[name=nama_custom]').inputValue()===f.name);
     if(js&&theme==='light')await p.screenshot({path:path.join(out,'special-'+b.version().split('.')[0]+'-'+width+'.png')});
     if(theme==='dark'&&width===1366&&js)await p.screenshot({path:path.join(out,'special-dark-'+b.version().split('.')[0]+'.png')});
     await p.goto(f.base+'/master_kelas.php?special_members='+f.class+'#special-classes');assert((await p.locator('.sc-member-section').innerText()).includes('Member'));
     results.push({browser:b.version(),width,theme,js});
    }finally{await c.close();}
   }
  }finally{await b.close();}
 }
 fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));console.log('PASS: '+results.length+' special-class desktop/tablet/mobile, theme, JS/no-JS cases, search, delete cancellation, keyboard focus and member views.');
})().catch(e=>{console.error(e);process.exit(1)});
