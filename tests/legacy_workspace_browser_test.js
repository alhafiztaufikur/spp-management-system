/* Reads published fixture data; correction previews only write the test session. */
const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8')),out=process.argv[3];fs.mkdirSync(out,{recursive:true});const results=[];
 const executables=['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe'];
 for(const exe of executables){const b=await chromium.launch({headless:true,executablePath:exe});try{
  for(const width of [390,768,1366])for(const theme of ['light','dark'])for(const js of [true,false]){
   const c=await b.newContext({viewport:{width,height:1000},javaScriptEnabled:js});try{
    await c.route('https://**/*',route=>route.abort());await c.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
    await c.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);const p=await c.newPage();
    for(const route of f.routes){const r=await p.goto(f.base+'/'+route);assert(r.status()===200,route+' '+r.status());
     if(!js)await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);
     assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()),route);
     const size=await p.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth}));
     assert(size.scroll<=size.width+2,route+' horizontal page overflow '+JSON.stringify(size));
     assert(await p.locator('.topbar .sidebar-toggle').count()===1,route+' missing navigation');
     if(route==='master_kelas.php'){
      const table=await p.locator('#legacy-groups').boundingBox();assert(table.x>=0&&table.x+table.width<=width+1,'Group card out of bounds');
      assert(await p.locator('#legacy-groups .legacy-directory-table tbody tr').count()<=25);
      if(js&&theme==='light')await p.locator('#legacy-groups').screenshot({path:path.join(out,'groups-'+b.version().split('.')[0]+'-'+width+'.png')});
     }
     if(js&&theme==='light'&&width===1366&&route.startsWith('legacy_accounts'))await p.screenshot({path:path.join(out,'accounts-'+b.version().split('.')[0]+'.png')});
    }
    if(js&&width===390){await p.locator('.sidebar-toggle').click();await p.waitForFunction(()=>document.querySelector('.sidebar').getBoundingClientRect().right>50);assert(await p.locator('.sidebar.open').count()===1,'Mobile navigation did not open');}
    results.push({browser:b.version(),width,theme,js});
   }finally{await c.close();}
  }
  // Match the app's unit palettes using read-only pages in every unit.
  for(const unit of [0,2,3]){const c=await b.newContext({viewport:{width:1366,height:1000}});try{
   await c.route('https://**/*',route=>route.abort());await c.addCookies([{name:'PHPSESSID',value:f.sessions[unit],url:f.base}]);const p=await c.newPage();await p.goto(f.base+'/master_kelas.php');
   assert(await p.locator('html').getAttribute('data-palette')==={0:'super',2:'smp',3:'sma'}[unit]);
   await p.locator('#legacy-groups').screenshot({path:path.join(out,'palette-'+unit+'-'+b.version().split('.')[0]+'.png')});
  }finally{await c.close();}}
 }finally{await b.close();}}
 fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));console.log('PASS: '+results.length+' desktop/tablet/mobile, light/dark, JS/no-JS browser cases; all unit palettes.');
})().catch(error=>{console.error(error);process.exit(1)});
