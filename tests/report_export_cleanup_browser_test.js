/* Read-only exports on the independently restored QA server. */
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
const out=process.env.SPP_BROWSER_ARTIFACT_ROOT,base=process.env.SPP_HTTP_BASE;
assert(out&&out.includes('pagination_desktop_')&&/^http:\/\/127\.0\.0\.1:58662$/.test(base));
const sessions=JSON.parse(fs.readFileSync(out+'/sessions.json','utf8'));
const common={unit:'1',kelas:'tingkat:1',siswa_status:'active',tanggal_awal:'2026-09-01',tanggal_akhir:'2026-11-03',tahun_ajaran:'2026/2027',bulan_awal:'9',tahun_awal:'2026',bulan_akhir:'11',tahun_akhir:'2026'};
const templates=['penerimaan','setoran','kas-tabungan','tabungan-siswa','saldo-tabungan','status','per-item','spp-tahunan'];
const queries=Object.fromEntries(templates.map(template=>[template,{...common,template,...(['status','per-item'].includes(template)?{'kategori[]':['spp','komite','daftar_ulang','psb']}: {})}]));
queries['per-item-multi']={...queries['per-item'],kelas:''};
queries['status-all-units']={...queries.status,unit:'all',kelas:''};
queries['spp-empty']={...queries['spp-tahunan'],q:'__CLEANUP_NO_MATCH_5321__'};
const query=value=>{const q=new URLSearchParams();for(const [k,v]of Object.entries(value))for(const item of Array.isArray(v)?v:[v])q.append(k,item);return q;};
const selected=new Set(process.argv.slice(2));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SPP_QA_BROWSER}),results=[];
 try{
  const context=await browser.newContext({viewport:{width:1366,height:1000}});
  await context.addCookies([{name:'PHPSESSID',value:sessions[1],url:base}]);
  const page=await context.newPage();page.setDefaultNavigationTimeout(180000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
  fs.mkdirSync(out+'/final',{recursive:true});
  for(const[name,params]of Object.entries(queries)){
   if(selected.size&&!selected.has(name))continue;
   for(const format of ['preview','excel']){
    await page.goto(base+'/laporan/export_global.php?'+query({...params,format}),{waitUntil:'networkidle'});
    const handle=await page.locator('[data-preview-frame]').elementHandle();assert(handle,name+' missing preview');const frame=await handle.contentFrame();await frame.evaluate(()=>document.fonts.ready);
    const text=await frame.locator('body').innerText();assert(!/Fatal error|Warning:|Rp |Status siswa:|Total kewajiban hanya mencakup|Unit:? SD/.test(text),name+' unwanted text');
    assert.equal(await frame.locator('.title').innerText(),(await frame.locator('.title h2').innerText()),name+' context');
    for(const width of [1280,1366,1920]){
     await page.setViewportSize({width,height:1000});
     assert(await frame.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),name+' overflow');
     if(['saldo-tabungan','status','per-item','spp-tahunan'].includes(params.template))assert.equal(await frame.locator('.kop p').evaluate(e=>getComputedStyle(e).fontSize),'15px',name+' address');
     if(params.template==='saldo-tabungan')assert.equal(await frame.locator('td[data-column="nama"]').first().evaluate(e=>getComputedStyle(e).textAlign),'center',name+' owner alignment');
     const bad=await frame.locator('.global-clean-table tbody td').evaluateAll(cells=>cells.flatMap(cell=>{
      const box=cell.getBoundingClientRect(),walker=document.createTreeWalker(cell,NodeFilter.SHOW_TEXT),bad=[];let node;
      while(node=walker.nextNode()){if(!node.textContent.trim())continue;const range=document.createRange();range.selectNodeContents(node);for(const r of range.getClientRects())if(r.width>0&&(r.left<box.left-1||r.right>box.right+1))bad.push({column:cell.dataset.column,text:node.textContent.trim()});}return bad;
     }));assert.deepEqual(bad,[],name+' escaped cells');
     await page.screenshot({path:out+'/'+name+'-'+format+'-'+width+'.png'});
    }
   }
   for(const[format,ext]of [['pdf','pdf'],['excel','xlsx'],['print','print.pdf']]){
    if(format==='print'&&name!==params.template)continue;
    const response=await context.request.get(base+'/laporan/export_global.php?'+query({...params,format,download:'1'}),{timeout:240000});assert(response.ok(),name+' '+format);
    const bytes=await response.body();assert(bytes.subarray(0,format==='excel'?2:5).equals(Buffer.from(format==='excel'?'PK':'%PDF-')),name+' invalid artifact');fs.writeFileSync(out+'/final/'+name+'.'+ext,bytes);
   }
   results.push({report:name,desktop:[1280,1366,1920],print:true,pdf:true,excel:true});console.log('PASS',name);
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(out+'/browser-results'+(selected.size?'-selected':'')+'.json',JSON.stringify(results,null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
