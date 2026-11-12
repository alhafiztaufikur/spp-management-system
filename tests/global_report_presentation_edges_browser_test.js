/* Supplement the desktop suite with combined units, empty and standalone previews. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE);
const base=process.env.SPP_TEST_BASE_URL,out=process.env.SPP_UI_OUTPUT;
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
const common={siswa_status:'active',tanggal_awal:'2026-09-01',tanggal_akhir:'2026-11-12',tahun_ajaran:'2026/2027',bulan_awal:'9',tahun_awal:'2026',bulan_akhir:'11',tahun_akhir:'2026'};
const cases={
 'status-smp':{template:'status',unit:'2',kelas:'tingkat:7',kategori:'daftar_ulang'},
 'status-sma':{template:'status',unit:'3',kelas:'tingkat:10',kategori:'daftar_ulang'},
 'per-item-all':{template:'per-item',unit:'all',kategori:['spp','komite','daftar_ulang','psb']},
 'spp-empty':{template:'spp-tahunan',unit:'1',q:'__NO_PRESENTATION_MATCH_112026__'},
};
const query=params=>{const q=new URLSearchParams();for(const[k,v]of Object.entries(params))for(const x of Array.isArray(v)?v:[v])q.append(Array.isArray(v)?k+'[]':k,x);return q.toString()};
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});const ctx=await browser.newContext({viewport:{width:1440,height:1000}});const page=await ctx.newPage();page.setDefaultNavigationTimeout(240000);
 try{
  const identity=await(await ctx.request.get(base+'/tests/browser_clone_identity.php')).json();assert.equal(identity.isolated_test,true);assert.notEqual(identity.port,3306);
  await page.goto(base+'/login.php');await page.locator('#username').fill('qa_principal');await page.locator('#password').fill(fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim());await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);
  for(const[name,params]of Object.entries(cases)){
   for(const format of ['pdf','excel','print']){const response=await ctx.request.get(base+'/laporan/export_global.php?'+query({...common,...params,format,download:'1'}),{timeout:300000});assert.equal(response.status(),200);const bytes=await response.body();assert(bytes.subarray(0,format==='excel'?2:5).equals(Buffer.from(format==='excel'?'PK':'%PDF-')));fs.writeFileSync(path.join(out,name+(format==='excel'?'.xlsx':format==='print'?'-print.pdf':'.pdf')),bytes);}
   await page.goto(base+'/laporan/export_global.php?'+query({...common,...params,format:'preview'}));
   for(const width of [1280,1440,1920]){await page.setViewportSize({width,height:1000});const buttons=await page.locator('button,a[class*=button],a.preview-back').evaluateAll(nodes=>nodes.filter(n=>n.getClientRects().length).map(n=>getComputedStyle(n).borderRadius));assert(buttons.every(r=>r==='8px'),name+' preview actions');}
   if(name==='spp-empty'){const frame=page.frames().find(f=>f!==page.mainFrame()&&f.url()!=='about:blank');if(frame)assert.match(await frame.locator('body').innerText(),/Tidak ada data/);}
   console.log('PASS combined/empty/unit preview, PDF, print and Excel:',name);
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
