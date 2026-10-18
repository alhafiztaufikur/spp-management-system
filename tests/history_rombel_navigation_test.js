/* Read-only navigation and filter submissions on the verified private clone. */
const {chromium}=require('playwright-core'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
const base=process.env.SPP_TEST_BASE_URL,f=JSON.parse(fs.readFileSync(path.join(process.env.SPP_SAVINGS_QA,'rombel-fixture.json'),'utf8'));let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const selected=url=>[...new URL(url).searchParams].filter(([k])=>/^rombel(?:\[.*\])?$/.test(k)).map(([,v])=>v).sort();
(async()=>{
  assert.equal((await(await fetch(base+'/tests/browser_clone_identity.php')).json()).port,64421);
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try{
    const c=await browser.newContext({viewport:{width:1650,height:1000}});await c.addCookies([{name:'PHPSESSID',value:f.sessions.super_admin.id,url:base}]);await c.route('https://fonts.googleapis.com/**',r=>r.abort());
    await c.request.post(base+'/unit_switch.php',{form:{unit_id:'1',csrf_token:f.sessions.super_admin.csrf,next:'/dashboard.php'},maxRedirects:0});const p=await c.newPage(),item=f.units[1];
    for(const [route,id,form,record,detail,archive] of [
      ['pembayaran/lihat.php','payment-history-rombel','.ph-filter-form','[data-payment-record]','[data-payment-detail]','deleted'],
      ['tabungan/riwayat.php','savings-history-rombel','.sw-filter-form','.sw-transaction-select','#sw-history-detail','archive']]){
      await p.goto(base+'/'+route+'?rombel[]='+item.custom_id+'&tanggal_awal=2020-01-01&tanggal_akhir=2035-12-31');
      const extra=route.startsWith('pembayaran')?await p.locator('#'+id+' option').evaluateAll(opts=>opts.find(o=>o.textContent==='1A').value):String(item.out_id);
      await p.locator('#'+id+' + button').click();const menu=p.locator('.spp-multi-panel:visible');await menu.locator('input[value="'+extra+'"]').check();await menu.locator('.spp-multi-apply').click();
      check(selected(p.url()).length===1,'Selection waits for submit');await Promise.all([p.waitForNavigation(),p.locator(form+' button[type=submit]').click()]);
      const wanted=[String(item.custom_id),String(extra)].sort();check(JSON.stringify(selected(p.url()))===JSON.stringify(wanted),'OR filters submit together');
      if(await p.locator('.du-pagination a[rel=next]').count()){await p.locator('.du-pagination a[rel=next]').first().click();check(JSON.stringify(selected(p.url()))===JSON.stringify(wanted),'Pagination retains classes');}
      await p.locator(record).first().click();await p.waitForFunction(selector=>{const el=document.querySelector(selector);return !el.hasAttribute('aria-busy')&&!el.textContent.includes('Memuat detail');},detail);check(JSON.stringify(selected(p.url()))===JSON.stringify(wanted),'AJAX detail retains classes');
      if(route.startsWith('tabungan'))check(await p.locator(detail+' [name="return_rombel[]"]').count()>=2,'AJAX correction preserves classes');
      const tab=p.locator(route.startsWith('pembayaran')?'.ph-tabs a':'.payment-history-tabs a').filter({hasText:route.startsWith('pembayaran')?'Transaksi Dihapus':'Arsip Dihapus'});await tab.click();check(new URL(p.url()).searchParams.get('view')===archive,'Archive opens');check(JSON.stringify(selected(p.url()))===JSON.stringify(wanted),'Archive retains classes');
    }
    const switched=await c.request.post(base+'/unit_switch.php',{form:{unit_id:'2',csrf_token:f.sessions.super_admin.csrf,next:'/pembayaran/lihat.php?rombel[]='+item.custom_id},maxRedirects:0});check(switched.status()===303&&!switched.headers().location.includes('rombel'),'Unit switch clears old membership filter');
    console.log('PASS '+checks+' multi-selection, pagination, AJAX, archive and unit navigation checks.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
