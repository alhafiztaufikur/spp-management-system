'use strict';
/** Read-only HTTP pages; synthetic DU cases exist only in the browser DOM. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_CORE||'playwright-core');
const root=path.dirname(process.env.SPP_TEST_ISOLATED_INSTANCE),manifest=JSON.parse(fs.readFileSync(process.env.SPP_TEST_ISOLATED_INSTANCE,'utf8').replace(/^\uFEFF/,'')),f=JSON.parse(fs.readFileSync(path.join(root,'tariff-fixture.json'),'utf8'));
const base=process.env.SPP_TEST_BASE_URL;assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);assert.equal(process.env.SPP_TEST_ALLOW_MUTATION,'1');let checks=0;
const eq=(a,b,label)=>{assert.deepEqual(a,b,label);checks++;};
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[],posts=[];
try{
 const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:f.sessions[2],url:base}]);await context.route('https://fonts.googleapis.com/**',r=>r.abort());
 const identity=await(await context.request.get(base+'/tests/browser_clone_identity.php')).json();eq(identity.server_uuid,manifest.server_uuid,'Verified clone HTTP identity');
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(r.method()==='POST')posts.push(r.url());});
 for(const [name,url] of [['input','/pembayaran/form.php'],['edit','/pembayaran/edit.php?id='+f.units[2].payment]]){
  await page.goto(base+url);await page.waitForLoadState('networkidle');
  if(name==='input'){
   await page.evaluate(()=>{const input=document.getElementById('siswa-search'),opt=document.querySelector('#siswa-list option[data-nis="242507052"]')||document.querySelector('#siswa-list option');input.value=opt.value;pilihSiswaDatalist(input,opt);});await page.waitForLoadState('networkidle');
  }
  for(const [width,height] of [[1366,768],[1650,900],[1920,1080]])for(const theme of ['light','dark']){
   await page.setViewportSize({width,height});await page.evaluate(theme=>{
    document.documentElement.dataset.theme=theme;
    const nis=document.getElementById('disp-nis').value,opt=Array.from(document.querySelectorAll('#siswa-list option')).find(o=>o.dataset.nis===nis);
    if(!opt)throw new Error('Selected student option required');
    opt.dataset.duBills=JSON.stringify([{id:990001,tahun_ajaran:'2026/2027',kelas:'9',total:9999999999999,terbayar:9999999999999,sisa:0,status:'open',is_current:true,is_arrear:false},{id:990002,tahun_ajaran:'2025/2026',kelas:'8',total:4535000,terbayar:1200000,sisa:3335000,status:'open',is_current:false,is_arrear:true},{id:990003,tahun_ajaran:'2024/2025',kelas:'7',total:4535000,terbayar:0,sisa:4535000,status:'open',is_current:false,is_arrear:true}]);
    document.getElementById('tagihan-daftar-ulang-id').value='990001';applyStudentPaymentDetails(opt);
   },theme);
   const trigger=page.locator('#du-selector-trigger'),menu=page.locator('#du-selector-menu');await trigger.click();await page.waitForTimeout(100);
   eq(await menu.isVisible(),true,'DU menu opens');eq(await menu.locator('.spp-dropdown-note').count(),0,'No duplicate heading');eq(await menu.locator('.du-selector-option').count(),3,'Current year and multiple arrears shown');
   const box=await menu.boundingBox();eq(Math.abs(box.width-520)<1,true,'Desktop panel width is 520px');eq(box.x>=7&&box.x+box.width<=width-7&&box.y>=7&&box.y+box.height<=height-7,true,'Panel stays within viewport');
   const layout=await menu.locator('[data-bill-id="990001"] .du-option-values').evaluate(values=>{const rows=[...values.children];return rows.map(row=>{const label=row.querySelector('small').getBoundingClientRect(),n=row.querySelector('strong'),b=n.getBoundingClientRect();return {right:b.right,top:b.top,labelRight:label.right,left:b.left,height:b.height,lineHeight:parseFloat(getComputedStyle(n).lineHeight),whiteSpace:getComputedStyle(n).whiteSpace};});});
   eq(layout.every(r=>Math.abs(r.height-r.lineHeight)<1&&r.whiteSpace==='nowrap'&&r.left>r.labelRight),true,'All currency values remain single-line and separated from labels');eq(Math.max(...layout.map(r=>r.right))-Math.min(...layout.map(r=>r.right))<1,true,'Amounts align to one right edge');eq(layout[0].top<layout[1].top&&layout[1].top<layout[2].top,true,'Amounts occupy three separate rows');
   await page.screenshot({path:path.join(root,`du-${name}-${theme}-${width}.png`)});await menu.locator('[aria-selected="true"]').focus();await page.keyboard.press('Escape');
   const escaped=await page.evaluate(()=>({id:document.activeElement.id,tag:document.activeElement.tagName,hidden:document.getElementById('du-selector-menu').hidden}));eq(escaped.id,'du-selector-trigger','Escape restores focus: '+JSON.stringify(escaped));
   await trigger.click();await menu.locator('[data-bill-id="990002"]').click();eq(await page.locator('#tagihan-daftar-ulang-id').inputValue(),'990002','Click chooses exact DU bill');eq(await page.locator('#du-input').evaluate(e=>e.readOnly),false,'Partial arrear stays payable');
   await trigger.click();await menu.locator('[data-bill-id="990001"]').focus();await page.keyboard.press('Enter');eq(await page.locator('#tagihan-daftar-ulang-id').inputValue(),'990001','Keyboard chooses exact DU bill');eq(await page.locator('#du-input').evaluate(e=>e.readOnly),true,'Settled bill stays locked');
  }
  await page.evaluate(()=>{const nis=document.getElementById('disp-nis').value,opt=Array.from(document.querySelectorAll('#siswa-list option')).find(o=>o.dataset.nis===nis);opt.dataset.duBills='[]';applyStudentPaymentDetails(opt);});await page.locator('#du-selector-trigger').click();eq(await page.locator('.du-selector-empty').isVisible(),true,'Empty state remains clear');await page.keyboard.press('Escape');
 }
 eq(posts,[],'No payment form is submitted');eq(errors,[],'No uncaught JavaScript errors');fs.writeFileSync(path.join(root,'du-desktop-result.json'),JSON.stringify({checks,passed:true,errors,posts},null,2));console.log('PASS '+checks+' read-only DU desktop checks.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
