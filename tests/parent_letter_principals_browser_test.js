const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');
const base=process.env.SPP_TEST_BASE_URL,output=process.env.SPP_UI_OUTPUT;
assert.match(base||'',/^http:\/\/(127\.0\.0\.1|localhost):\d+$/);
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  async function switchUnit(unit){await page.goto(base+'/dashboard.php');await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(unit))]);await page.goto(base+'/laporan/surat_orang_tua.php');}
  async function data(){return page.locator('#letter-principal-data').evaluate(n=>JSON.parse(n.textContent));}
  async function saved(){await page.waitForFunction(()=>document.getElementById('letter-principal-panel').getAttribute('aria-busy')!=='true');}
  async function responseClick(selector){const response=page.waitForResponse(r=>r.url().endsWith('/surat_orang_tua_kepala.php')&&r.request().method()==='POST');await page.locator(selector).click();const result=await response;await saved();return result;}
  async function pdfFor(url,file){await page.goto(url);const token=new URL(page.url()).searchParams.get('draft');assert.ok(token);const response=await page.request.get(base+'/laporan/surat_orang_tua_pdf.php?draft='+token);assert.equal(response.status(),200);assert.match(response.headers()['content-type'],/pdf/);if(output)fs.writeFileSync(path.join(output,file),await response.body());return token;}
  try{
    const identity=await (await page.request.get(base+'/tests/browser_clone_identity.php')).json();assert.equal(identity.isolated_test,true);assert.notEqual(identity.port,3306);
    await page.goto(base+'/login.php');await page.locator('#username').fill(process.env.SPP_TEST_ADMIN_USER||'qa_principal');await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);assert.ok(!page.url().endsWith('/login.php'));
    const keys=[];
    for(const [unit,principal,school] of [[1,'Hairul Laka',"SD Al-Qur'an (SDA) Mutiara Hikmah"],[2,'Ahmad Hubbudin','SMP IT Mutiara Hikmah'],[3,'Nur Qomariyah','SMA IT Mutiara Hikmah']]){
      await switchUnit(unit);assert.equal(await page.locator('#letter-principal-select option:checked').textContent(),principal);assert.equal(await page.locator('.letter-principal-school strong').textContent(),school);
      const first=await page.locator('.letter-action-cell a').first().getAttribute('href');const key=new URL(first,base+'/laporan/').searchParams.get('student_key');keys.push(key);assert.ok(key.startsWith(unit+'|'));
      for(const width of [1280,1440,1920])for(const theme of ['light','dark']){
        await page.setViewportSize({width,height:1000});await page.evaluate(theme=>{localStorage.setItem('spp_theme',theme);document.documentElement.dataset.theme=theme;},theme);
        await page.evaluate(()=>{document.body.offsetWidth;document.getAnimations().filter(animation=>animation instanceof CSSTransition).forEach(animation=>animation.finish());});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
        const rects=await page.locator('.letter-principal-school,.letter-principal-choice,.letter-principal-buttons').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom};}));assert.ok(rects[0].right<=rects[1].left&&rects[1].right<=rects[2].left);assert.ok(rects[2].right<=width);
        const contrasts=await page.locator('#letter-principal-select,.letter-principal-buttons button').evaluateAll(nodes=>{
          const ctx=document.createElement('canvas').getContext('2d');ctx.canvas.width=ctx.canvas.height=1;
          const color=value=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=value;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data];};
          const background=node=>{const layers=[];for(let parent=node;parent;parent=parent.parentElement)layers.push(color(getComputedStyle(parent).backgroundColor));return layers.reverse().reduce((back,front)=>front.slice(0,3).map((v,i)=>v*front[3]/255+back[i]*(1-front[3]/255)),[255,255,255]);};
          const luminance=rgb=>rgb.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
          return nodes.map(node=>{const a=luminance(color(getComputedStyle(node).color)),b=luminance(background(node));return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);});
        });assert.ok(contrasts.every(ratio=>ratio>=4.5),`Control contrast ${unit}/${theme}: ${contrasts}`);
        if(output)await page.screenshot({path:path.join(output,`principal-u${unit}-${width}-${theme}.png`)});
        if(process.env.SPP_TEST_VISUAL_ONLY==='1'&&width===1440){await page.locator('#letter-principal-add').click();const modal=await page.locator('#letter-principal-dialog').boundingBox();assert.ok(Math.abs(modal.x+modal.width/2-width/2)<2&&Math.abs(modal.y+modal.height/2-500)<2,'Dialog must be centered');if(output)await page.screenshot({path:path.join(output,`principal-dialog-u${unit}-${theme}.png`)});await page.keyboard.press('Escape');}
      }
      if(process.env.SPP_TEST_VISUAL_ONLY==='1')continue;
      await page.setViewportSize({width:1440,height:1000});await page.locator('.letter-select').first().check();
      await page.locator('#letter-principal-delete').click();assert.equal(await page.locator('#letter-principal-save').isDisabled(),true);await page.keyboard.press('Escape');
      await page.locator('#letter-principal-add').click();await page.locator('#letter-principal-name').fill('Kepala Sekolah Uji '+unit);assert.equal((await responseClick('#letter-principal-save')).status(),200);assert.equal(await page.locator('#print-selected').textContent(),'Cetak Dipilih (1)');
      let cfg=await data();let api=await (await page.request.get(base+'/laporan/surat_orang_tua_kepala.php')).json();let current=api.states[0];const added=current.selected_id,original=cfg.states[0].selected_id;
      const token=await pdfFor(base+'/laporan/'+first,`browser-u${unit}-new.pdf`);
      await page.goto(base+'/laporan/surat_orang_tua.php');await page.locator('#letter-principal-edit').click();await page.locator('#letter-principal-name').fill('Kepala Sekolah Diperbarui '+unit);assert.equal((await responseClick('#letter-principal-save')).status(),200);
      const oldPdf=await page.request.get(base+'/laporan/surat_orang_tua_pdf.php?draft='+token);assert.equal(oldPdf.status(),200);if(output)fs.writeFileSync(path.join(output,`browser-u${unit}-old-draft.pdf`),await oldPdf.body());
      await page.reload();assert.equal(await page.locator('#letter-principal-select option:checked').textContent(),'Kepala Sekolah Diperbarui '+unit);
      await page.locator('#letter-principal-add').click();await page.locator('#letter-principal-name').fill('  '+principal.toLowerCase()+'  ');assert.equal((await responseClick('#letter-principal-save')).status(),422);assert.equal(await page.locator('#letter-principal-dialog').evaluate(n=>n.open),true);await page.keyboard.press('Escape');
      // Hold a selection request and confirm print actions cannot open a draft while it is pending.
      let release,markStarted;const gate=new Promise(resolve=>release=resolve),started=new Promise(resolve=>markStarted=resolve);
      await page.route('**/surat_orang_tua_kepala.php',async route=>{if(route.request().method()==='POST'){markStarted();await gate;}await route.continue();});
      try{await page.locator('#letter-principal-select').selectOption(String(original));await started;await page.waitForFunction(()=>document.getElementById('letter-principal-panel').getAttribute('aria-busy')==='true');const pageCount=context.pages().length;await page.locator('.letter-action-cell a').first().click({force:true});assert.equal(context.pages().length,pageCount);}finally{release();}
      await saved();await page.unroute('**/surat_orang_tua_kepala.php');
      await page.locator('#letter-principal-select').selectOption(String(added));await saved();
      await page.locator('#letter-principal-delete').click();await page.locator('#letter-principal-replacement').selectOption(String(original));assert.equal((await responseClick('#letter-principal-save')).status(),200);assert.equal(await page.locator('#letter-principal-select option').count(),1);
      cfg=await data();api=await (await page.request.get(base+'/laporan/surat_orang_tua_kepala.php')).json();current=api.states[0];
      const post=(body,csrf=cfg.csrf)=>page.request.post(base+'/laporan/surat_orang_tua_kepala.php',{headers:{'X-CSRF-Token':csrf},data:{unit_id:unit,version:current.version,...body}});
      assert.equal((await post({action:'select',id:original},'bad')).status(),403);
      assert.equal((await post({action:'select',id:original,unit_id:unit===1?2:1})).status(),409);
      assert.equal((await post({action:'edit',id:original,name:principal,version:0})).status(),409);
      // An external writer changes the version; this tab must preserve table selection and offer refresh.
      await page.locator('.letter-select').first().check();assert.equal((await post({action:'edit',id:original,name:principal+' sementara'})).status(),200);
      await page.locator('#letter-principal-edit').click();await page.locator('#letter-principal-name').fill('Stale edit');assert.equal((await responseClick('#letter-principal-save')).status(),409);await page.keyboard.press('Escape');assert.equal(await page.locator('#print-selected').textContent(),'Cetak Dipilih (1)');await page.locator('#letter-principal-reload').click();await saved();
      await page.locator('#letter-principal-edit').click();await page.locator('#letter-principal-name').fill(principal);assert.equal((await responseClick('#letter-principal-save')).status(),200);
      if(unit===1){
        await page.route('**/surat_orang_tua_kepala.php',route=>route.request().method()==='POST'?route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,message:'Uji gagal menyimpan. Silakan coba kembali.'})}):route.continue());
        await page.locator('#letter-principal-edit').click();await page.locator('#letter-principal-name').fill('Nama belum tersimpan');assert.equal((await responseClick('#letter-principal-save')).status(),503);assert.equal(await page.locator('#letter-principal-dialog').evaluate(n=>n.open),true);assert.equal(await page.locator('#letter-principal-select option:checked').textContent(),principal);if(output)await page.screenshot({path:path.join(output,'principal-dialog-error.png')});await page.keyboard.press('Escape');await page.unroute('**/surat_orang_tua_kepala.php');
      }
      await pdfFor(base+'/laporan/'+first,`browser-u${unit}-default.pdf`);
    }
    if(process.env.SPP_TEST_VISUAL_ONLY==='1'){assert.deepEqual(errors,[]);console.log('PASS: desktop matrix and measured text contrast for SD/SMP/SMA in both themes.');return;}
    await switchUnit(0);assert.equal(await page.locator('.letter-principal-summary>div').count(),3);assert.equal(await page.locator('#letter-principal-select').count(),0);let cfg=await data();assert.equal((await page.request.post(base+'/laporan/surat_orang_tua_kepala.php',{headers:{'X-CSRF-Token':cfg.csrf},data:{unit_id:1,version:1,action:'add',name:'Forbidden'}})).status(),409);
    const token=await pdfFor(base+'/laporan/surat_orang_tua_susun.php?mode=selected&students='+encodeURIComponent(keys.join(',')),'browser-mixed.pdf');
    await page.goto(base+'/laporan/surat_orang_tua_susun.php?draft='+token+'&preview=1');assert.equal(await page.locator('#parent-pdf-frame').count(),1);
    const download=await page.request.get(base+'/laporan/surat_orang_tua_pdf.php?draft='+token+'&download=1');assert.match(download.headers()['content-disposition'],/attachment/);
    if(output){
      fs.writeFileSync(path.join(output,'browser-mixed-download.pdf'),await download.body());await page.screenshot({path:path.join(output,'principal-mixed-preview.png')});
      const pngDownload=page.waitForEvent('download',{timeout:120000});await page.locator('#parent-png').click();await (await pngDownload).saveAs(path.join(output,'browser-mixed.zip'));
      const singleToken=await pdfFor(base+'/laporan/surat_orang_tua_susun.php?mode=single&student_key='+encodeURIComponent(keys[0]),'browser-single.pdf');
      await page.goto(base+'/laporan/surat_orang_tua_susun.php?draft='+singleToken+'&preview=1');const singleDownload=page.waitForEvent('download',{timeout:120000});await page.locator('#parent-png').click();await (await singleDownload).saveAs(path.join(output,'browser-single.png'));
    }
    assert.deepEqual(errors,[]);console.log('PASS: desktop matrix, CRUD, immutable PDFs, persistent defaults, duplicate/CSRF/unit/version rejection, pending-print guard, conflict recovery, mixed-unit preview/download.');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
