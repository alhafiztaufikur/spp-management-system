// Read-only document checks; no login, application mutations or external network.
const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const qa=process.env.SPP_MANUAL_QA;
const server=JSON.parse(fs.readFileSync(path.join(qa,'http.json'),'utf8').replace(/^\uFEFF/,''));
const base=new URL(server.url);assert.equal(base.hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const page=await browser.newPage();const failures=[];page.on('pageerror',e=>failures.push(e.message));
  await page.goto(new URL('documentation/manual_penggunaan_sistemspp.html',base).href);
  const chapters=page.locator('h1[data-manual-heading]');assert.equal(await chapters.count(),46);
  const imageCount=await page.locator('figure img').count();assert.equal(imageCount,26);
  await page.evaluate(()=>Promise.all([...document.images].map(im=>im.decode())));
  const broken=await page.locator('a[href^="#"]').evaluateAll(links=>links.map(a=>a.getAttribute('href')).filter(h=>!document.getElementById(h.slice(1))));assert.deepEqual(broken,[]);
  const layouts=[];
  for(const width of [320,390,768,1440]){
   await page.setViewportSize({width,height:900});
   const measurements=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,images:[...document.images].every(i=>i.complete&&i.naturalWidth>0)}));
   assert.ok(measurements.scroll<=width+1,`Document overflow at ${width}`);assert.ok(measurements.images);
   await page.screenshot({path:path.join(qa,`manual-${width}.png`)});
   layouts.push(measurements);
  }
  await page.getByRole('link',{name:/Memeriksa dan mengonfirmasi pemulihan/}).first().click();
  assert.equal(new URL(page.url()).hash,'#restore');
  const body=await page.locator('body').innerText();
  assert.ok(!/Titipan SPP|mode Advanced/.test(body));
  assert.ok(!/password\s*[:=]\s*\S+/i.test(body),'Manual must not list an initial password');
  assert.match(body,/Administrator juga tidak menyetujui/);
  assert.match(body,/Terapkan ke Penerima Lain/);
  assert.match(body,/A5 potret/);assert.match(body,/A6 lanskap/);
  await page.emulateMedia({media:'print'});
  assert.equal(await page.locator('.reader-tools').evaluate(n=>getComputedStyle(n).display),'none');
  assert.equal(await page.locator('#restore .steps').evaluate(n=>getComputedStyle(n).fontSize),'14px');
  assert.deepEqual(failures,[]);
  fs.writeFileSync(path.join(qa,'manual-browser-results.json'),JSON.stringify({chapters:46,images:imageCount,layouts,brokenAnchors:broken,errors:failures},null,2));
  console.log('PASS: 46 bab, 26 gambar, tautan internal, 4 lebar layar, navigasi dan media cetak.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
