const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.SPP_PLAYWRIGHT_MODULE);
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8')),out=process.argv[3];fs.mkdirSync(out,{recursive:true});const results=[];
 for(const executablePath of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/laragon/backups/spp-management-system/legacy_finance_qa_20261016b/browser109/runtime/chrome-win/chrome.exe']){
  const browser=await chromium.launch({headless:true,executablePath});try{
   for(const width of [390,768,1366])for(const theme of ['light','dark'])for(const js of [true,false]){
    const c=await browser.newContext({viewport:{width,height:1000},javaScriptEnabled:js});try{
     await c.route('https://**/*',r=>r.abort());await c.addCookies([{name:'PHPSESSID',value:f.session,url:f.base}]);await c.addInitScript(t=>localStorage.setItem('spp_theme',t),theme);const p=await c.newPage();
     await p.goto(f.base+'/master_kelas.php');if(!js)await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);
     assert(!/Fatal error|Warning:|Notice:/.test(await p.locator('body').innerText()));assert(await p.locator('[name=lg_q]').count()===0,'Source directory loaded by default');
     assert(await p.locator('.class-create-actions .btn-ghost').count()===0,'Duplicate white Add Rombel button');assert(await p.locator('.class-create-actions .class-add-shortcut').count()===1);
     assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Horizontal overflow');
     await p.locator('.class-create-actions').scrollIntoViewIfNeeded();if(js&&theme==='light')await p.screenshot({path:path.join(out,'actions-'+browser.version().split('.')[0]+'-'+width+'.png')});
     await p.locator('#special-classes [name=special_kind]').selectOption('alumni');await p.locator('#special-classes [name=special_q]').fill('Alumni2025');await p.locator('#special-classes .sc-search').getByRole('button',{name:'Cari',exact:true}).click();await p.waitForURL(/special_kind=alumni/);
     const row=p.locator('#special-classes .sc-table tbody tr').filter({hasText:'Alumni2025'});assert(await row.count()===1,'Alumni class duplicated');assert(await row.locator('a',{hasText:'Edit'}).count()===0,'System Alumni exposed custom CRUD');
     await row.getByRole('link',{name:'Rekening & Transaksi',exact:true}).click();await p.waitForURL(/class_id=/);assert(await p.locator('h1').innerText()==='Alumni2025');await p.getByRole('link',{name:'Kembali ke Daftar Kelas',exact:true}).click();await p.waitForURL(/special_members=/);assert(await p.locator('[name=special_q]').inputValue()==='Alumni2025','Class search context lost');
     await p.goto(f.base+'/master_kelas.php?special_members='+f.guru+'#special-classes');if(!js)await p.evaluate(t=>document.documentElement.dataset.theme=t,theme);assert((await p.locator('.sc-member-section').innerText()).includes('125 identitas'));assert(await p.locator('.sc-member-section tbody tr').count()===25);await p.locator('.sc-member-section').getByRole('link',{name:'Berikutnya',exact:true}).click();await p.waitForURL(/special_member_page=2/);
     if(js){await p.locator('#special-classes').scrollIntoViewIfNeeded();await p.screenshot({path:path.join(out,'classes-'+browser.version().split('.')[0]+'-'+width+'-'+theme+'.png')});}
     await p.getByRole('link',{name:'Telusuri Kelompok Legacy',exact:true}).click();await p.waitForURL(/lg_open=1/);assert(await p.locator('[name=lg_q]').count()===1);await p.locator('[name=lg_q]').fill('A2025');await p.locator('[name=lg_q]').press('Enter');await p.waitForURL(/lg_q=A2025/);const sourceRow=p.locator('.legacy-groups tbody tr');assert(await sourceRow.count()===1);assert((await sourceRow.locator('strong').innerText())==='Alumni2025');await sourceRow.getByRole('link',{name:/Lihat Rekening/}).click();await p.waitForURL(/group=/);await p.getByRole('link',{name:'Kembali ke Daftar Kelas',exact:true}).click();await p.waitForURL(/lg_open=1/);assert(await p.locator('[name=lg_q]').inputValue()==='A2025');await p.getByRole('link',{name:'Tutup Penelusuran Legacy',exact:true}).click();await p.waitForURL(url=>!url.searchParams.has('lg_open'));assert(await p.locator('[name=lg_q]').count()===0);
     results.push({browser:browser.version(),width,theme,js});
    }finally{await c.close();}
   }
  }finally{await browser.close();}
 }
 fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));console.log('PASS '+results.length+' unified class desktop/tablet/mobile, themes, JS/no-JS, lazy source navigation, canonical labels, member pagination and return context checks.');
})().catch(e=>{console.error(e);process.exit(1)});
