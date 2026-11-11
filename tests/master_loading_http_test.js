'use strict';
// Run against two loopback audit servers sharing one verified, isolated database.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=process.env.SPP_PERF_AUDIT_ROOT;
if(!root)throw Error('Set SPP_PERF_AUDIT_ROOT to the private benchmark directory.');
const sessions=JSON.parse(fs.readFileSync(path.join(root,'sessions.json'),'utf8'));
const urls={before:process.env.SPP_PERF_BEFORE_URL||'http://127.0.0.1:8131',after:process.env.SPP_PERF_AFTER_URL||'http://127.0.0.1:8132'};
for(const value of Object.values(urls))assert.equal(new URL(value).hostname,'127.0.0.1');
const median=xs=>[...xs].sort((a,b)=>a-b)[Math.floor(xs.length/2)];
function difference(a,b,p='model'){
 if(Object.is(a,b))return null;
 if(!a||!b||typeof a!=='object'||typeof b!=='object')return p;
 if(JSON.stringify(Object.keys(a))!==JSON.stringify(Object.keys(b)))return p+'.keys';
 for(const k of Object.keys(a)){const d=difference(a[k],b[k],p+'.'+k);if(d)return d;}return null;
}
const normalize=html=>html.replace(/mtime=\d+/g,'mtime=TIME').replace(/([?&]v=)\d{9,}/g,'$1TIME');
(async()=>{
 const results=[];
 for(const role of ['super_admin','admin'])for(const unit of [1,2,3,0])for(const year of ['2026/2027','2027/2028','2025/2026','2188/2189'])for(const page of ['master_spp','master_daftar_ulang']){
  const key=[role,unit,year.replace('/','-'),page].join('_'),samples={before:[],after:[]};
  for(let iteration=0;iteration<6;iteration++)for(const variant of ['before','after']){
   const auditCase=key+'_'+iteration,start=performance.now();
   const res=await fetch(urls[variant]+'/'+page+'.php?tahun='+encodeURIComponent(year),{headers:{Cookie:'PHPSESSID='+sessions[role+'-'+unit],'X-Audit-Case':auditCase},redirect:'manual'});
   const ttfb=performance.now()-start,html=await res.text();assert.equal(res.status,200,key+' '+variant+' HTTP');assert(!/Fatal error|Uncaught/.test(html),key+' '+variant+' PHP');
   const measured=JSON.parse(fs.readFileSync(path.join(root,variant,auditCase+'.json'),'utf8'));
   samples[variant].push({server:measured.ms,ttfb,total:performance.now()-start,queries:measured.queries,peak:measured.peak});
   if(variant==='after'){
    const before=JSON.parse(fs.readFileSync(path.join(root,'before',auditCase+'.json'),'utf8'));
    const diff=difference(before.model,measured.model);assert.equal(diff,null,key+' difference at '+diff);
    assert.equal(normalize(html),normalize(fs.readFileSync(path.join(root,'before',auditCase+'.html'),'utf8')),key+' HTML changed');
   }
  }
  const item={role,unit,year,page,first:{before:samples.before[0],after:samples.after[0]},warm:{}};
  for(const variant of ['before','after'])item.warm[variant]=Object.fromEntries(['server','ttfb','total','queries','peak'].map(k=>[k,median(samples[variant].slice(1).map(s=>s[k]))]));
  item.improvement=1-item.warm.after.server/item.warm.before.server;results.push(item);
  fs.writeFileSync(path.join(root,'benchmark.json'),JSON.stringify({passed:false,results},null,2));
  console.log('PASS',key,Math.round(item.warm.before.server)+' -> '+Math.round(item.warm.after.server)+' ms',item.warm.before.queries+' -> '+item.warm.after.queries+' queries');
 }
 for(const page of ['master_spp','master_daftar_ulang'])for(const role of ['super_admin','admin']){
  const baseline=results.find(r=>r.role===role&&r.unit===1&&r.year==='2026/2027'&&r.page===page);assert(baseline.improvement>=.5,page+' '+role+' below 50% improvement');
  if(page==='master_spp')assert(baseline.warm.after.queries<=baseline.warm.before.queries*.3,'SPP query reduction below 70%');
 }
 for(const scenario of results)assert(scenario.warm.after.server<=scenario.warm.before.server*1.1,'Control scenario regressed: '+[scenario.role,scenario.unit,scenario.year,scenario.page].join('/'));
 fs.writeFileSync(path.join(root,'benchmark.json'),JSON.stringify({passed:true,results},null,2));console.log('PASS',results.length,'scenarios, first request plus five repeats per variant, models and HTML identical');
})().catch(e=>{console.error(String(e.message).slice(0,1000));process.exitCode=1;});
