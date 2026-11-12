/* Compare real component styles with/without the desktop rule in independent documents. */
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'C:/laragon/backups/spp-management-system/daily_backup_policy_qa_20261013/tools/node_modules/playwright-core');
const cssFiles=['style','global_report_matrix','global_report_scope','pagination_desktop','master_du','payment_history','account_workspace','dropdowns','database_restore','backup_restore','legacy_ui'];
const baseCss=cssFiles.map(n=>fs.readFileSync('assets/css/'+n+'.css','utf8').replace(/@import[^;]+;/g,'')).join('\n');
const shapes=fs.readFileSync('assets/css/desktop_actions.css','utf8');
const markup=`<div class="sidebar-unit-form"><span class="sidebar-unit-kicker"><span class="dot"></span>ADMINISTRATOR</span><div class="sidebar-unit-segments"><button class="sidebar-unit-choice is-selected">SD</button><button class="sidebar-unit-choice" disabled>SMP</button></div></div>
<div class="global-unit-options"><a href="#" class="global-unit-choice">SD</a><span class="global-unit-choice is-active">Semua Unit</span></div>
<div class="desktop-pager"><span class="desktop-page is-disabled" aria-disabled="true">Sebelumnya</span><a class="desktop-page is-next" href="#next">Selanjutnya</a></div>
<div class="report-export-actions"><button class="btn">Cetak</button><button class="btn">Excel</button></div>
<span class="du-page-link is-active" aria-current="page">1</span><span class="du-page-link is-disabled" aria-disabled="true">Akhir</span>
<button id="action" class="btn" onclick="this.dataset.clicked='yes'">Simpan</button><button class="btn" disabled>Loading</button><span class="btn" aria-disabled="true">Tidak tersedia</span>
<div class="du-workspace"><div class="du-master-year-tools"><span class="du-year-status">Tahun Mendatang 2027/2028<span class="du-year-status-dot"></span></span></div></div>
<span class="badge-nis">0000000000001234567890</span><span class="kelas-badge">12 IPA A</span><span class="badge-role">Administrator</span><span class="unit-pill">Semua Unit</span><span class="badge-count">123456789</span><span class="clock-badge">23:59:59 WIB</span><span class="ph-status">Belum Diketahui</span><span class="report-result-pill">1 s.d. 25 dari 1200 baris</span><span class="excel-badge">Belum Bayar</span>
<span class="spp-dropdown-status">Aktif</span><div class="spp-floating-root"><button class="class-picker-option">Kelas 1A</button></div>
<span class="ll-num">1</span><div class="account-step"><h3><span>2</span>Langkah</h3></div><ol class="dbt-steps"><li><span>1</span>Persiapan</li></ol><ol class="legacy-steps"><li><a href="#step"><span>2</span>Tinjau</a></li></ol><ol class="restore-stage-list"><li>Validasi</li></ol>
<span class="admin-avatar" data-exception>A</span><span class="account-avatar" data-exception>A</span><span class="du-year-status-dot" data-exception></span><span class="toggle-track" data-exception><span class="toggle-thumb" data-exception></span></span><input type="checkbox" data-exception><input type="radio" data-exception><div class="main-card" data-exception>Kartu</div>`;
const fields=['borderTopLeftRadius','borderTopRightRadius','borderBottomLeftRadius','borderBottomRightRadius','width','height','color','backgroundColor','font','cursor'];
async function snapshot(page){return page.locator('body *').evaluateAll((nodes,fields)=>nodes.map(n=>{const c=getComputedStyle(n),styles={};for(const field of fields)styles[field]=c[field];return {tag:n.tagName,cls:n.className,exception:n.hasAttribute('data-exception'),styles,tabIndex:n.tabIndex,role:n.getAttribute('role'),disabled:n.hasAttribute('disabled')||n.getAttribute('aria-disabled')==='true'}}),fields);}
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const original=await browser.newPage(),updated=await browser.newPage();let count=0;
 for(const palette of ['sd','smp','sma','super'])for(const theme of ['light','dark']){
  const html=extra=>`<!doctype html><html data-palette="${palette}" data-theme="${theme}"><head><style>${baseCss}${extra}*{transition:none!important;animation:none!important}</style></head><body id="global-report-workspace" data-readable-workspace="master-registration">${markup}</body></html>`;
  await original.setContent(html(''));await updated.setContent(html(shapes));
  for(const width of [800,1023,1024,1280,1440,1920]){
   await original.setViewportSize({width,height:1000});await updated.setViewportSize({width,height:1000});const a=await snapshot(original),b=await snapshot(updated);
   if(width<1024)assert.deepEqual(b,a,`Mobile ${palette}/${theme}/${width}`);
   else{
    for(let i=0;i<a.length;i++){
     const x=structuredClone(a[i]),y=structuredClone(b[i]);if(!x.exception){for(const k of fields.filter(k=>k.includes('Radius'))){delete x.styles[k];delete y.styles[k];}}
     assert.deepEqual(y,x,`Only geometry may change: ${palette}/${theme}/${width}/${x.cls}`);
    }
    const corners=await updated.locator('#action,.global-unit-choice,.du-page-link,.desktop-page,.badge-nis,.kelas-badge,.badge-role,.unit-pill,.badge-count,.clock-badge,.ph-status,.report-result-pill,.excel-badge,.du-year-status,.spp-dropdown-status,.class-picker-option,.ll-num,.account-step h3>span,.dbt-steps span,.legacy-steps a,.legacy-steps a span').evaluateAll(nodes=>nodes.map(n=>{const c=getComputedStyle(n);return [c.borderTopLeftRadius,c.borderTopRightRadius,c.borderBottomLeftRadius,c.borderBottomRightRadius]}));assert(corners.flat().every(r=>r==='8px'));
    assert.equal(await updated.locator('.restore-stage-list li').evaluate(n=>getComputedStyle(n,'::before').borderRadius),'8px');
    assert.equal(await updated.locator('.ph-status').evaluate(n=>getComputedStyle(n,'::before').borderRadius),await original.locator('.ph-status').evaluate(n=>getComputedStyle(n,'::before').borderRadius));
    const longBadge=updated.locator('.badge-nis');assert(await longBadge.evaluate(n=>{const r=document.createRange();r.selectNodeContents(n);return r.getBoundingClientRect().width<=n.getBoundingClientRect().width}),'Long identity must fit');
   }count++;
  }
  await original.emulateMedia({media:'print'});await updated.emulateMedia({media:'print'});assert.deepEqual(await snapshot(updated),await snapshot(original),'Print styles unchanged');await original.emulateMedia({media:'screen'});await updated.emulateMedia({media:'screen'});
 }
 await updated.locator('#action').click();assert.equal(await updated.locator('#action').getAttribute('data-clicked'),'yes');assert.equal(await updated.locator('.badge-nis').getAttribute('role'),null);assert.equal(await updated.locator('.badge-nis').evaluate(n=>n.tabIndex),-1);
 console.log('PASS',count,'independent desktop/mobile comparisons; four palettes, two themes, unchanged print, layout/color/font/semantics, exceptions, long labels and button behavior.');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});
