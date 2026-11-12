const assert=require('node:assert/strict'),fs=require('node:fs'),{pathToFileURL}=require('node:url');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'C:/laragon/backups/spp-management-system/daily_backup_policy_qa_20261013/tools/node_modules/playwright-core');
const root=process.env.SPP_UI_OUTPUT;assert(root&&root.replaceAll('\\','/').includes('/backups/spp-management-system/'));
(async()=>{const b=await chromium.launch({channel:'chrome',headless:true});try{const p=await b.newPage();
 for(const name of ['ordinary','global','shell']){
  const html=fs.readFileSync(root+'/shapes-preview-'+name+'.html','utf8');await p.goto(pathToFileURL(root+'/shapes-preview-'+name+'.html').href);await p.evaluate(()=>document.fonts.ready);
  for(const width of [1280,1440,1920]){await p.setViewportSize({width,height:1000});
   for(const frame of p.frames()){const controls=frame.locator('button,a[class*=button],.preview-back,.badge-nis,.kelas-badge,.report-status,.desktop-page');const values=await controls.evaluateAll(nodes=>nodes.filter(n=>n.getClientRects().length).map(n=>{const s=getComputedStyle(n);return {cls:n.className,r:[s.borderTopLeftRadius,s.borderTopRightRadius,s.borderBottomLeftRadius,s.borderBottomRightRadius]}}));assert(values.every(v=>v.r.every(r=>r==='8px')),name+'/'+width+JSON.stringify(values));}
  }
  if(name==='global'){assert.match(await p.locator('.badge-nis').textContent(),/^0000001234$/);assert.match(await p.locator('.report-status').textContent(),/Belum Diketahui/);}
  if(name==='shell'){
   const frame=p.frames().find(f=>f!==p.mainFrame());assert(frame,'Document frame missing');
   await p.setViewportSize({width:800,height:1000});await p.evaluate(()=>new Promise(requestAnimationFrame));assert.equal(await frame.locator('.badge-nis').evaluate(n=>getComputedStyle(n).borderRadius),'20px','Mobile preview remains unchanged');
   await p.setViewportSize({width:1440,height:1000});await p.emulateMedia({media:'print'});assert.equal(await frame.locator('.badge-nis').evaluate(n=>getComputedStyle(n).borderRadius),'20px','Print preview remains unchanged');await p.emulateMedia({media:'screen'});
  }
  assert(html.includes('min-width:1024px'),'Desktop boundary missing');console.log('PASS actual standalone preview',name,'three desktop widths and preserved labels');
 }
}finally{await b.close()}})().catch(e=>{console.error(e);process.exit(1)});
