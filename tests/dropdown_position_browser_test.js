/* No database: actual component code, old browser and top-layer/fallback geometry. */
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path');
const pw = require(process.env.SPP_PLAYWRIGHT_CORE || 'playwright-core');
const root = path.resolve(__dirname, '..');
const js = fs.readFileSync(path.join(root, 'assets/js/dropdowns.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css/dropdowns.css'), 'utf8');
const engines = (process.env.SPP_DROPDOWN_BROWSERS || 'chrome,msedge,firefox,webkit,chromium109').split(',');
const output = process.env.SPP_DROPDOWN_OUTPUT;
const options = Array.from({length:30}, (_,i)=>`<option value="${i}" ${i===2?'disabled':''}>Pilihan ${i} ${i===4?'dengan nama sangat panjang untuk menguji pembungkusan teks':''}</option>`).join('');
let checks = 0;
async function settle(page) { await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))); }
async function geometry(page, button, panel) {
  await settle(page);
  const data = await page.evaluate(([b,p])=>{
    const r=document.querySelector(b).getBoundingClientRect(), q=document.querySelector(p).getBoundingClientRect();
    return {anchor:r.toJSON(),panel:q.toJSON(),view:window.sppFloatingPanels.viewport()};
  },[button,panel]);
  const {anchor:a,panel:p,view:v}=data;
  assert.ok(p.width>0&&p.height>0,JSON.stringify(data));
  assert.ok(p.left>=v.left+7.5&&p.right<=v.right-7.5&&p.top>=v.top+7.5&&p.bottom<=v.bottom-7.5,JSON.stringify(data));
  assert.ok(Math.abs(p.top-a.bottom-6)<=2||Math.abs(a.top-p.bottom-6)<=2,'Detached panel '+JSON.stringify(data));
  const expected=Math.max(v.left+8,Math.min(a.left,v.right-8-p.width));
  assert.ok(Math.abs(p.left-expected)<=2,'Detached horizontal position '+JSON.stringify(data)); checks++; return data;
}
async function fixture(page, fallback=false) {
  await page.setContent(`<style>:root{--accent:#138a53;--accent-glow:#e6f3eb;--bg-elevated:white;--bg-surface:#f4f8f5;--text-primary:#18261e;--text-muted:#64756c;--border:#c9dfd1;--border-hover:#9ec9ae}body{margin:0;min-height:1800px}#card{position:relative;margin:120px 24px 0;width:280px;padding:12px;transform:translate(7px,3px);backdrop-filter:blur(4px);overflow:hidden;border:1px solid}dialog{width:280px;padding:20px;transform:translate(5px,4px)}#range{display:grid;grid-template-columns:1fr 1fr;gap:8px;background:white;padding:12px;border:1px solid}#range[hidden]{display:none}#search{background:white;height:220px}#scroller{height:260px;overflow:auto}#inside{height:550px;padding-top:80px}button{min-height:44px}</style><style>${css}</style><form id="card"><label>Måned<select id="single" name="month">${options}</select></label><label>Operator<select id="multi" name="operator[]" multiple data-filter-multiple data-select-search="true">${options}</select></label><button id="range-button" type="button">Rentang bulan</button><div id="range" hidden><select id="nested" name="start"><option value="7">Juli</option><option value="8">Agustus</option></select><button id="range-apply" type="button">Terapkan rentang</button></div><input id="search-input"><div id="search" hidden>Daftar siswa</div><div id="scroller"><div id="inside"><select id="scroll-select"><option>Satu</option><option>Dua</option></select></div></div></form><dialog id="modal"><select id="modal-select">${options}</select><button id="modal-close">Tutup</button></dialog>`);
  if(fallback) await page.evaluate(()=>{Object.defineProperty(HTMLElement.prototype,'showPopover',{value:undefined,configurable:true});Object.defineProperty(HTMLElement.prototype,'hidePopover',{value:undefined,configurable:true});const matches=Element.prototype.matches;Element.prototype.matches=function(s){if(s.includes(':popover-open'))throw new DOMException('Unsupported selector','SyntaxError');return matches.call(this,s);};});
  await page.addScriptTag({content:js});
  await page.evaluate(()=>{
    document.dispatchEvent(new Event('DOMContentLoaded'));
    document.querySelector('#range-button').onclick=()=>{const panel=document.querySelector('#range');window.sppFloatingPanels.show(panel,document.querySelector('#range-button'),{minWidth:300,onClose:()=>{panel.hidden=true;window.sppFloatingPanels.hide(panel);}});};
    document.querySelector('#range-apply').onclick=()=>{const panel=document.querySelector('#range');panel.hidden=true;window.sppFloatingPanels.hide(panel);};
  });
}
async function run(name) {
  let type=pw.chromium, launch={headless:true};
  if(name==='chrome'||name==='msedge')launch.channel=name;
  else if(name==='chromium109'){assert.ok(process.env.SPP_CHROMIUM109_EXECUTABLE);launch.executablePath=process.env.SPP_CHROMIUM109_EXECUTABLE;}
  else type=pw[name];
  const browser=await type.launch(launch), errors=[];
  try {
    const page=await browser.newPage(); page.on('pageerror',e=>errors.push(e.message));
    for(const fallback of name==='chrome'?[false,true]:[false]) {
      await fixture(page,fallback);
      for(const width of [360,390,768,1024,1366,1920]) for(const theme of ['light','dark']) for(const unit of [1,2,3]) {
        await page.setViewportSize({width,height:768});
        await page.evaluate(({theme,unit})=>{document.documentElement.dataset.theme=theme;document.documentElement.style.setProperty('--accent',['','#138a53','#c28a17','#2258ab'][unit]);window.scrollTo(0,0);document.querySelector('#card').style.marginTop='120px';},{theme,unit});
        for(const id of ['single','multi']) {
          const button=`#${id} + .spp-select-trigger`;await page.locator(button).click();
          await geometry(page,button,'.spp-dropdown-panel[data-spp-floating-open]');
          await page.keyboard.press('Escape');await settle(page);assert.equal(await page.locator('[data-spp-floating-open]').count(),0);
        }
      }
      // Real selected value, change event, disabled option and draft cancellation.
      await page.locator('#single + button').click();await page.locator('.spp-dropdown-option:visible').filter({hasText:'Pilihan 1 '}).first().click();assert.equal(await page.locator('#single').inputValue(),'1');
      await page.locator('#multi + button').click();await page.locator('.spp-multi-all input').check();await page.locator('.spp-multi-apply').click();assert.equal(await page.locator('#multi').evaluate(s=>window.sppSelectedValues(s).length),29);
      await page.locator('#multi + button').click();await page.locator('.spp-multi-search').fill('Pilihan 4');await settle(page);await geometry(page,'#multi + button','.spp-dropdown-panel[data-spp-floating-open]');await page.keyboard.press('Escape');assert.equal(await page.locator('#multi').evaluate(s=>window.sppSelectedValues(s).length),29);
      await page.evaluate(()=>document.querySelector('#card').reset());await settle(page);assert.equal(await page.locator('#single').inputValue(),'0');
      await page.locator('#single + button').focus();await page.keyboard.press('ArrowDown');await page.keyboard.press('End');await page.keyboard.press('Enter');assert.equal(await page.locator('#single').inputValue(),'29');
      await page.locator('#single + button').click();await page.evaluate(()=>document.querySelector('#single').add(new Option('Pilihan baru dinamis','new')));await settle(page);assert.equal(await page.locator('.spp-dropdown-option:visible').filter({hasText:'Pilihan baru dinamis'}).count(),1);await geometry(page,'#single + button','.spp-dropdown-panel[data-spp-floating-open]');await page.keyboard.press('Escape');
      // Browser zoom changes the CSS viewport; unlike CSS zoom it does not
      // change the coordinate system of fixed descendants on old Chromium.
      for(const zoom of [.8,1,1.25,1.5,2]) {
        const height=Math.round(400/zoom);
        await page.setViewportSize({width:Math.round(1024/zoom),height});await page.evaluate(h=>{document.querySelector('#card').style.marginTop=(h-100)+'px';window.scrollTo(0,0);},height);
        await page.locator('#single + button').click();const data=await geometry(page,'#single + button','.spp-dropdown-panel[data-spp-floating-open]');assert.equal(await page.locator('[data-spp-floating-open]').getAttribute('data-spp-placement'),'top',JSON.stringify({zoom,...data}));await page.keyboard.press('Escape');
      }
      await page.evaluate(()=>{document.body.style.zoom='1';document.querySelector('#card').style.marginTop='100px';});await page.setViewportSize({width:390,height:768});
      await page.locator('#range-button').click();await geometry(page,'#range-button','#range');
      assert.equal(await page.locator('#nested').evaluate(s=>s.form?.id),'card','Portalled controls retain their form');
      assert.equal(await page.evaluate(()=>window.sppFloatingPanels.contains(document.querySelector('#card'),document.querySelector('#nested + button'))),true,'Logical containment survives portal');
      await page.locator('#nested + button').click();await geometry(page,'#nested + button','.spp-dropdown-panel[data-spp-floating-open]');
      await page.locator('.spp-dropdown-option:visible').filter({hasText:'Agustus'}).click();assert.equal(await page.locator('#nested').inputValue(),'8');assert.equal(await page.locator('#range').getAttribute('data-spp-floating-open'),'');await page.locator('#range-apply').click();
      await page.evaluate(()=>document.querySelector('#modal').showModal());await page.locator('#modal-select + button').click();await geometry(page,'#modal-select + button','.spp-dropdown-panel[data-spp-floating-open]');await page.locator('.spp-dropdown-option:visible').filter({hasText:'Pilihan 1 '}).first().click();assert.equal(await page.locator('#modal-select').inputValue(),'1');await page.evaluate(()=>document.querySelector('#modal').close());
      await page.locator('#scroll-select + button').click();await geometry(page,'#scroll-select + button','.spp-dropdown-panel[data-spp-floating-open]');await page.evaluate(()=>document.querySelector('#scroller').scrollTop=30);await geometry(page,'#scroll-select + button','.spp-dropdown-panel[data-spp-floating-open]');await page.evaluate(()=>window.scrollTo(0,1500));await settle(page);assert.equal(await page.locator('[data-spp-floating-open]').count(),0);
      await page.evaluate(()=>window.scrollTo(0,0));await page.locator('#single + button').click();await page.evaluate(()=>document.querySelector('#single').closest('.spp-select').remove());await settle(page);assert.equal(await page.locator('[data-spp-floating-open]').count(),0);
      // A reduced/offset visual viewport models the visible area above a
      // mobile keyboard, independently of the larger layout viewport.
      await page.evaluate(()=>{
        Object.defineProperty(window,'visualViewport',{configurable:true,value:{offsetLeft:10,offsetTop:50,width:360,height:420}});
        const input=document.querySelector('#search-input'),panel=document.querySelector('#search');
        window.sppFloatingPanels.show(panel,input,{maxHeight:320});
      });
      await geometry(page,'#search-input','#search');
      await page.evaluate(()=>{const panel=document.querySelector('#search');panel.hidden=true;window.sppFloatingPanels.hide(panel);delete window.visualViewport;});
      if(output){fs.mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,`${name}-${fallback?'fallback':'native'}.png`)});}
    }
    const touch=await browser.newPage({hasTouch:true,viewport:{width:390,height:844}});touch.on('pageerror',e=>errors.push(e.message));await fixture(touch);
    await touch.locator('#single + button').tap();await geometry(touch,'#single + button','.spp-dropdown-panel[data-spp-floating-open]');await touch.locator('.spp-dropdown-option:visible').filter({hasText:'Pilihan 1 '}).first().tap();assert.equal(await touch.locator('#single').inputValue(),'1');
    await touch.locator('#multi + button').tap();await touch.locator('.spp-multi-all').tap();await touch.locator('.spp-multi-apply').tap();assert.equal(await touch.locator('#multi').evaluate(s=>window.sppSelectedValues(s).length),29);await touch.close();
    assert.deepEqual(errors,[]);console.log(`PASS ${name} ${browser.version()}: viewport/theme/unit matrix, fallback, selections, search, reset, zoom, nested panel, modal, scroll/removal and touch`);
  } finally { await browser.close(); }
}
(async()=>{for(const name of engines)await run(name);console.log(`PASS ${checks} anchored geometry checks`);})().catch(e=>{console.error(e);process.exit(1);});
