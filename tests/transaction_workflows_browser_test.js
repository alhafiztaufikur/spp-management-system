const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');assert.match(process.env.SPP_DB_NAME||'',/^db_spp_audit_/);
const base=process.env.SPP_TEST_BASE_URL,password=fs.readFileSync(process.env.SPP_TEST_ADMIN_PASSWORD_FILE,'utf8').trim();
const ids=JSON.parse(fs.readFileSync(process.env.SPP_UI_IDS_FILE,'utf8').replace(/^\uFEFF/,'')),output=process.env.SPP_UI_OUTPUT;
const url=path=>new URL(path,base).href;
async function login(page,name){await page.goto(url('/login.php'));await page.locator('#username').fill(name);await page.locator('#password').fill(password);await Promise.all([page.waitForNavigation(),page.locator('#btn-login').click()]);assert.ok(!page.url().endsWith('/login.php'));}
async function unit(page,value){await page.goto(url('/dashboard.php'));await Promise.all([page.waitForNavigation(),page.locator('#sidebar-unit-select').selectOption(String(value))]);}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const errors=[];
 try{
  const page=await browser.newPage({viewport:{width:1440,height:950}});page.on('pageerror',e=>errors.push(e.message));await login(page,'superadmin');const selectedKeys=[];
  for(const owner of process.env.SPP_TEST_ONLY_COMBINED==='1'?[]:[1,2,3]){
   await unit(page,owner);
   await page.goto(url('/otorisasi_transaksi.php?view=history'));
   assert.equal(await page.locator('.authorization-history-table tbody tr').count(),25);
   await page.getByRole('link',{name:'Berikutnya',exact:true}).click();assert.ok((await page.locator('.authorization-history-table tbody tr').count())>0);
   await page.goto(url('/otorisasi_transaksi.php?view=history&q='+ids[owner].nis));
   await page.locator('.open-payment-activity[data-id="'+ids[owner].id+'"]').click();await page.locator('.payment-activity-event').first().waitFor();
   const history=await page.locator('#payment-activity-dialog').innerText();
   for(const text of ['Pembayaran diubah','Pengajuan disetujui','Pengajuan ditolak','Pengajuan dibatalkan','Pembayaran dihapus','Usulan pemohon'])assert.ok(history.includes(text),text);
   await page.locator('[data-close-activity]').click();
   const wrong=await page.request.get(url('/otorisasi_aktivitas.php?id='+ids[owner===1?2:1].id));assert.equal(wrong.status(),404);
   const cashier=await browser.newPage();await login(cashier,ids[owner].cashier);
   assert.equal((await cashier.request.get(url('/otorisasi_aktivitas.php?id='+ids[owner].id))).status(),200);
   assert.equal((await cashier.request.get(url('/otorisasi_aktivitas.php?id='+(9000000+owner*100+1)))).status(),404);await cashier.close();
   const treasurer=await browser.newPage();await login(treasurer,'bendahara'+({1:'',2:'.smp',3:'.sma'}[owner]));
   assert.equal((await treasurer.request.get(url('/otorisasi_aktivitas.php?id='+ids[owner].id))).status(),200);await treasurer.close();

   await page.goto(url('/laporan/surat_orang_tua.php'));const links=await page.locator('.letter-action-cell a').evaluateAll(nodes=>nodes.slice(0,2).map(a=>a.href));assert.equal(links.length,2);
   const keys=links.map(link=>new URL(link).searchParams.get('student_key'));
   selectedKeys.push(keys[0]);
   const classLabel=await page.locator('.letter-student-row').first().locator('td').nth(2).innerText();
   const classChoice=await page.locator('#letter-class option').evaluateAll((options,label)=>options.find(o=>o.value.startsWith('rombel:')&&o.textContent.trim()===label.trim())?.value,classLabel);
   assert.ok(classChoice,'A billed student must have a selectable class');
   await page.goto(url('/laporan/surat_orang_tua_susun.php?mode=selected&students='+encodeURIComponent(keys.join(','))));
   assert.equal(await page.locator('.parent-recipient').count(),2);
   await page.locator('#parent-message').fill('Pesan pertama <b>aman</b> & orang tua.\n\nParagraf kedua.');
   await page.locator('.parent-recipient').nth(1).click();await page.locator('#parent-message').fill('Pesan kedua khusus siswa berbeda.');
   await page.locator('.parent-recipient').first().click();assert.ok((await page.locator('#parent-message').inputValue()).includes('Pesan pertama'));
   const data=JSON.parse(await page.locator('#parent-draft-data').textContent());
   const forbidden=await page.request.post(url('/laporan/surat_orang_tua_draf.php'),{data:{draft:data.token,messages:{}}});assert.equal(forbidden.status(),403);
   const invalid=await page.request.post(url('/laporan/surat_orang_tua_draf.php'),{data:{draft:data.token,messages:{'99|foreign':'malicious'}},headers:{'X-CSRF-Token':data.csrf}});assert.equal(invalid.status(),400);
   await page.locator('#parent-preview').click();await page.waitForURL('**&preview=1');
   const pdf=await page.request.get(url('/laporan/surat_orang_tua_pdf.php?draft='+data.token));assert.equal(pdf.status(),200);
   const pdfData=await pdf.body();assert.ok(pdfData.subarray(0,4).toString()==='%PDF');if(output)fs.writeFileSync(output+'/parent-'+owner+'.pdf',pdfData);
   const download=await page.request.get(url('/laporan/surat_orang_tua_pdf.php?draft='+data.token+'&download=1'));assert.ok(download.headers()['content-disposition'].includes('attachment'));
   await page.getByRole('link',{name:'Kembali ke penyusunan'}).click();await page.locator('.parent-recipient').nth(1).click();assert.equal(await page.locator('#parent-message').inputValue(),'Pesan kedua khusus siswa berbeda.');
   // Single, class, and all modes retain the expected recipient selection.
   await page.goto(links[0]);assert.equal(await page.locator('.parent-recipient').count(),1);
   for(const query of ['mode=class&kelas='+encodeURIComponent(classChoice),'mode=all']){await page.goto(url('/laporan/surat_orang_tua_susun.php?'+query));assert.ok(await page.locator('.parent-recipient').count()>0);}
   for(const width of [1440,768,390])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:950});await page.evaluate(t=>{localStorage.setItem('spp_theme',t);document.documentElement.dataset.theme=t;},theme);
    const box=await page.locator('#parent-message').boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width+1);
    if(output&&width===1440&&theme==='light')await page.screenshot({path:output+'/parent-editor-'+owner+'.png',fullPage:true,animations:'disabled'});
    await page.goto(url('/otorisasi_transaksi.php?view=history'));assert.equal(await page.locator('.alert-error').count(),0);
    if(output&&width===390&&theme==='dark')await page.screenshot({path:output+'/history-phone-'+owner+'.png',fullPage:true,animations:'disabled'});
    await page.goto(url('/tabungan/keluar.php'));await page.locator('#siswa-search').fill(ids[owner].nis);await page.waitForFunction(()=>document.getElementById('disp-saldo').value.startsWith('Rp '));
    await page.locator('#nominal-keluar').fill('200000');assert.equal(await page.locator('.savings-invalid').count(),1);
    await page.locator('#btn-simpan').click();assert.equal(await page.locator('#spp-warning-overlay').getAttribute('aria-hidden'),'false');
    await page.locator('#spp-warning-message').waitFor({state:'visible'});const warning=await page.locator('#spp-warning-message').innerText();assert.ok(warning.includes('melebihi saldo'),JSON.stringify({owner,width,theme,warning}));await page.locator('#spp-warning-close').click();assert.equal(await page.locator('#nominal-keluar').evaluate(e=>e===document.activeElement),true);
    await page.locator('#nominal-keluar').fill('0');assert.equal(await page.locator('.savings-invalid').count(),0);
    await page.goto(url('/laporan/surat_orang_tua_susun.php?draft='+data.token));
   }
   // Network failure and stale response cannot authorize a withdrawal.
   await page.goto(url('/tabungan/keluar.php'));await page.route('**/get_saldo.php?*',route=>route.abort());await page.locator('#siswa-search').fill(ids[owner].nis);await page.locator('#disp-saldo').evaluate(e=>e.value);await page.waitForFunction(()=>document.getElementById('disp-saldo').value==='Saldo belum tersedia');
   await page.locator('#nominal-keluar').fill('1');await page.locator('#btn-simpan').click();await page.locator('#spp-warning-title').waitFor({state:'visible'});assert.ok((await page.locator('#spp-warning-title').innerText()).includes('Saldo belum tersedia'));await page.unroute('**/get_saldo.php?*');
   await page.goto(url('/tabungan/masuk.php'));await page.locator('#btn-simpan').click();await page.locator('#spp-warning-title').waitFor({state:'visible'});assert.equal(await page.locator('#spp-warning-title').innerText(),'Pilih siswa');
   console.log('OK unit '+owner+': history, permissions, per-student drafts, PDF, modes, responsive themes, savings validation');
  }
  await unit(page,0);
  if(!selectedKeys.length){await page.goto(url('/laporan/surat_orang_tua.php'));const available=await page.locator('.letter-action-cell a').evaluateAll(nodes=>nodes.map(a=>new URL(a.href).searchParams.get('student_key')));for(const owner of [1,2,3])selectedKeys.push(available.find(key=>key.startsWith(owner+'|')));}
  await page.goto(url('/laporan/surat_orang_tua_susun.php?mode=selected&students='+encodeURIComponent(selectedKeys.join(','))));assert.equal(await page.locator('.parent-recipient').count(),3);
  for(let index=0;index<3;index++){await page.locator('.parent-recipient').nth(index).click();await page.locator('#parent-message').fill('Pesan gabungan unit '+(index+1));}
  await page.locator('#parent-preview').click();await page.waitForURL('**&preview=1');const combinedToken=new URL(page.url()).searchParams.get('draft');
  const combined=await page.request.get(url('/laporan/surat_orang_tua_pdf.php?draft='+combinedToken));assert.equal(combined.status(),200);if(output)fs.writeFileSync(output+'/parent-combined.pdf',await combined.body());
  await page.locator('#parent-print').click();
  console.log('OK: combined parent letters keep unit identities and individual messages');
  const anonymous=await browser.newContext();assert.equal((await anonymous.request.get(url('/otorisasi_aktivitas.php?id='+ids[1].id))).status(),401);await anonymous.close();
  assert.deepEqual(errors,[]);console.log('OK: no JavaScript errors');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
