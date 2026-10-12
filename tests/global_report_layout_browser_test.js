/* Real geometry/occlusion regression for horizontal and vertical scrolling. */
const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const base=process.env.SPP_HTTP_BASE,dir=process.env.SPP_QA_DIR;
assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);assert.match(base||'',/^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const sessions=JSON.parse(fs.readFileSync(dir+'/sessions.json')),baseline=JSON.parse(fs.readFileSync(dir+'/baseline.json'));
const query=params=>{const q=new URLSearchParams();for(const[k,v]of Object.entries(params))Array.isArray(v)?v.forEach(x=>q.append(k+'[]',x)):q.set(k,v);return q;};
async function inspect(page,table,width){
  const errors=await table.evaluate((t,small)=>{
    const failures=[],near=(a,b)=>Math.abs(a-b)<2;
    if(t.getBoundingClientRect().width===0)failures.push('Report table must remain visible on small screens');
    const identity=[...t.querySelectorAll('thead .matrix-identity,thead .global-layout-identity')];
    const row=[...t.tBodies[0]?.rows||[]].find(r=>r.cells.length===t.querySelectorAll('colgroup col').length);
    if(!row)return failures;
    [...t.tHead.rows[0].cells].forEach((head,i)=>{
      if(/^(No|NIS|NIS Diknas|Nama Siswa)$/i.test(head.textContent.trim())&&getComputedStyle(row.cells[i]).textAlign!=='center')failures.push('Identity must be centered '+head.textContent);
    });
    row.querySelectorAll('.report-col-date').forEach(cell=>{
      if(cell.getBoundingClientRect().width<180)failures.push('Date/time column too narrow');
      const date=cell.querySelector('.report-date'),time=cell.querySelector('.report-time');
      if(date&&time&&time.getBoundingClientRect().top<date.getBoundingClientRect().bottom+2)failures.push('Date/time lines overlap');
    });
    identity.forEach((head,i)=>{
      const body=row.cells[i],a=head.getBoundingClientRect(),b=body.getBoundingClientRect();
      if(!near(a.x,b.x)||!near(a.width,b.width))failures.push('header/body identity misalignment '+head.textContent);
      if(getComputedStyle(head).position==='sticky'){
        const y=Math.min(a.bottom-5,innerHeight-5),x=Math.max(a.left+5,5);
        if(y>80&&y<innerHeight&&x<a.right&&x<innerWidth){
          const hit=document.elementFromPoint(x,y)?.closest('th');
          if(hit!==head)failures.push('leaf/group header covers identity '+head.textContent);
        }
      }
      const color=getComputedStyle(body).backgroundColor;
      if(getComputedStyle(body).position==='sticky'&&/rgba\(.*,[\s.]*0\./.test(color))failures.push('translucent identity '+color);
    });
    const pinned=identity.filter(c=>getComputedStyle(c).position==='sticky').map(c=>c.getBoundingClientRect());
    if(!small)for(let i=1;i<pinned.length;i++)if(!near(pinned[i-1].right,pinned[i].left))failures.push('sticky columns overlap or leave gaps');
    if(t.classList.contains('report-horizontal-matrix')){
      const headColors=[...t.tHead.querySelectorAll('th')].map(cell=>getComputedStyle(cell).backgroundColor);
      if(new Set(headColors).size!==1)failures.push('Frozen/scrolling header colors differ');
      const leaves=t.tHead.rows[1]?[...t.tHead.rows[1].cells]:[...t.tHead.rows[0].cells].slice(identity.length);
      leaves.forEach((head,i)=>{const a=head.getBoundingClientRect(),b=row.cells[i+identity.length].getBoundingClientRect();if(!near(a.x,b.x)||!near(a.width,b.width))failures.push('leaf header/body mismatch '+head.textContent);});
      const footer=t.tFoot?.rows[0];
      if(footer){
        const footerColors=[...footer.cells].map(cell=>getComputedStyle(cell).backgroundColor);
        if(new Set(footerColors).size!==1)failures.push('Subtotal frozen/scrolling palettes differ');
        if(footerColors[0]===getComputedStyle(row.cells[0]).backgroundColor)failures.push('Subtotal must differ from ordinary rows');
        const final=document.querySelector('.report-total-grid .report-summary-card');
        if(final&&getComputedStyle(final).backgroundColor===footerColors[0])failures.push('Final totals must differ from subtotals');
        if(footer.cells.length!==row.cells.length)failures.push('Subtotal columns differ from body');
        [...footer.cells].forEach((cell,i)=>{
          const a=cell.getBoundingClientRect(),b=row.cells[i].getBoundingClientRect();
          if(!near(a.x,b.x)||!near(a.width,b.width))failures.push('subtotal/body misalignment '+i);
          if(getComputedStyle(cell).position==='sticky'){
            const x=Math.max(a.left+5,5),y=a.top+Math.min(15,a.height/2);
            const hit=document.elementFromPoint(x,y);
            if(y>85&&y<innerHeight-5&&x<a.right&&x<innerWidth&&!hit?.closest('.bottom-nav-item')&&hit?.closest('th,td')!==cell)failures.push('Subtotal identity covered');
          }
        });
      }
    }
    if(t.getBoundingClientRect().width+2<t.parentElement.clientWidth)failures.push('Blank space beside table');
    return failures;
  },width<=650);
  assert.deepEqual(errors,[]);
}
(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});let states=0;
  try{
    const context=await browser.newContext({viewport:{width:1600,height:1000}});await context.addCookies([{name:'PHPSESSID',value:sessions.super_admin,url:base}]);
    assert.equal((await(await context.request.get(base+'/tests/browser_clone_identity.php')).json()).database,process.env.SPP_DB_NAME);
    const page=await context.newPage(),jsErrors=[];page.on('pageerror',e=>jsErrors.push(e.message));
    const names=['status','penerimaan','status-multi','status-periods','penerimaan-multi','per-item','per-item-multi','spp-tahunan','riwayat-tagihan','tabungan-siswa','saldo-tabungan','setoran','kas-tabungan'].filter(name=>!process.env.SPP_LAYOUT_NAMES||process.env.SPP_LAYOUT_NAMES.split(',').includes(name));
    for(const unit of ['1','2','3','0'])for(const name of names){
      if(process.env.SPP_LAYOUT_CASE&&process.env.SPP_LAYOUT_CASE!==unit+'/'+name)continue;
      const params={...baseline.cases[unit+'/'+name]};
      await page.goto(base+'/laporan/template.php?'+query(params));
      assert.doesNotMatch(await page.locator('body').innerText(),/Fatal error|Warning:|Gagal memuat laporan/);
      for(const width of [1600,900,390])for(const theme of ['light','dark']){
        await page.setViewportSize({width,height:1000});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);await page.waitForTimeout(220);
        if(name==='per-item'&&width>650){
          const fields=await page.locator('.report-inline-search-row').evaluate(el=>[...el.children].filter(c=>c.matches('.field-row')).map(c=>c.getBoundingClientRect().top));
          assert.equal(fields.length,2);assert.ok(Math.abs(fields[0]-fields[1])<2,'SPP search must sit next to page size');
        }
        const tables=page.locator('.report-horizontal-matrix,.global-layout-table');
        for(const table of await tables.all()){
          await table.evaluate(t=>{window.scrollTo({top:t.getBoundingClientRect().top+scrollY-180,behavior:'instant'});});
          for(const fraction of [0,.45,1]){
            await table.evaluate((t,f)=>{const box=t.parentElement;box.style.scrollBehavior='auto';box.scrollLeft=(box.scrollWidth-box.clientWidth)*f;},fraction);
            try{await inspect(page,table,width);}catch(e){console.error('Layout context',unit,name,width,theme,fraction);e.message=unit+'/'+name+'/'+width+'/'+theme+'/'+fraction+': '+e.message;throw e;}
          }
          if(await table.evaluate(t=>!!t.tFoot)){
            await table.evaluate(t=>window.scrollTo({top:t.tFoot.getBoundingClientRect().bottom+scrollY-850,behavior:'instant'}));
            for(const fraction of [0,.45,1]){
              await table.evaluate((t,f)=>{const box=t.parentElement;box.scrollLeft=(box.scrollWidth-box.clientWidth)*f;},fraction);
              try{await inspect(page,table,width);}catch(e){e.message=unit+'/'+name+'/'+width+'/'+theme+'/subtotal/'+fraction+': '+e.message;throw e;}
            }
          }
        }
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Page itself must not scroll horizontally '+unit+'/'+name+'/'+width+'/'+theme);
        if(width===1600){await page.evaluate(()=>{const f=document.querySelector('.report-filter-actions');window.scrollTo(0,f.getBoundingClientRect().top+scrollY-35);});
          assert.ok(await page.evaluate(()=>!document.elementFromPoint(innerWidth-160,35)?.closest('.report-filter-actions')),'Filter actions must not cover topbar');}
        if(unit==='1'&&name==='status-multi')await page.screenshot({path:dir+'/layout-'+width+'-'+theme+'.png'});
        states++;
      }
    }
    await page.setViewportSize({width:1600,height:1000});
    for(const name of ['status-multi','penerimaan','per-item','tabungan-siswa'])for(const format of ['preview','excel']){
      await page.goto(base+'/laporan/export_global.php?'+query({...baseline.cases['1/'+name],format}));
      const frame=page.locator('[data-preview-frame]').contentFrame();await frame.locator('.report-horizontal-matrix').first().waitFor();
      for(const width of [1600,900,390]){
        await page.setViewportSize({width,height:1000});
        const table=frame.locator('.report-horizontal-matrix').first();
        await table.evaluate(t=>t.closest('.report-matrix-scroll').scrollLeft=400);
        const centered=await table.evaluate(t=>[...t.querySelectorAll('tbody td.report-col-no,tbody td.report-col-id,tbody td.report-col-name')].every(c=>getComputedStyle(c).textAlign==='center'));assert.ok(centered,'Preview No/NIS/name alignment');
        const alignment=await table.evaluate(t=>{const a=[...t.tHead.querySelectorAll('.matrix-identity')],b=t.tBodies[0].rows[0].cells;return a.every((c,i)=>Math.abs(c.getBoundingClientRect().x-b[i].getBoundingClientRect().x)<2);});assert.ok(alignment,'Preview header/body alignment');
        await page.screenshot({path:dir+'/layout-'+name+'-'+format+'-'+width+'.png'});
      }
      if(format==='preview')assert.match(await page.locator('script').last().textContent(),/format=print/);
    }
    assert.deepEqual(jsErrors,[]);console.log('PASS: '+states+' layout states, all scroll positions, header/body geometry, occlusion, opaque identities, topbar and interactive previews');
  }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
