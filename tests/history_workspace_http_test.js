'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const audit=process.env.SPP_HISTORY_AUDIT_ROOT;
if(!audit)throw Error('Set the private SPP_HISTORY_AUDIT_ROOT.');
const sessions=JSON.parse(fs.readFileSync(path.join(audit,'sessions.json'),'utf8'));
const urls={before:process.env.SPP_HISTORY_BEFORE_URL||'http://127.0.0.1:8131',after:process.env.SPP_HISTORY_AFTER_URL||'http://127.0.0.1:8132'};
for(const value of Object.values(urls))assert.equal(new URL(value).hostname,'127.0.0.1');
const median=values=>[...values].sort((a,b)=>a-b)[Math.floor(values.length/2)];
const variants=(process.env.SPP_HISTORY_VARIANTS||'before,after').split(',');
assert(variants.every(v=>['before','after'].includes(v)));
function stable(value){
 if(Array.isArray(value))return value.map(stable);
 if(value&&typeof value==='object')return Object.fromEntries(Object.entries(value).filter(([key])=>!['read_sql','read_args'].includes(key)).map(([key,v])=>[key,stable(v)]));
 return value;
}
const routes={payment:'/pembayaran/lihat.php?tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22',du:'/pembayaran/riwayat_daftar_ulang.php',other:'/master_biaya_lain.php'};
(async()=>{
 const results=[];
 for(const role of ['super_admin','admin'])for(const unit of [1,2,3,0])for(const [page,route] of Object.entries(routes)){
  const key=[role,unit,page].join('_');if(process.env.SPP_HISTORY_CASE_PREFIX&&!key.startsWith(process.env.SPP_HISTORY_CASE_PREFIX))continue;
  const samples={before:[],after:[]};
  for(let iteration=0;iteration<6;iteration++)for(const variant of variants){
   const name=key+'_'+iteration,start=performance.now();
   const response=await fetch(urls[variant]+route,{headers:{Cookie:'PHPSESSID='+sessions[role+'-'+unit],'X-Audit-Case':name},redirect:'manual',signal:AbortSignal.timeout(180000)});
   const ttfb=performance.now()-start,html=await response.text();assert.equal(response.status,200,key+' HTTP '+variant);assert(!/Fatal error|Uncaught |<b>Warning<\/b>/.test(html),key+' runtime');
   const measured=JSON.parse(fs.readFileSync(path.join(audit,variant,name+'.json'),'utf8'));
   const sample={server:measured.ms,ttfb,total:performance.now()-start,queries:measured.queries,peak:measured.peak};samples[variant].push(sample);
   fs.writeFileSync(path.join(audit,variant,name+'-timing.json'),JSON.stringify(sample));
   if(variant==='after'){
    const before=JSON.parse(fs.readFileSync(path.join(audit,'before',name+'.json'),'utf8'));assert.deepEqual(stable(measured.model),stable(before.model),key+' complete financial model');
   }
  }
  if(!variants.includes('after')){console.log('BASELINE',key,Math.round(median(samples.before.slice(1).map(s=>s.server)))+' ms');continue;}
  if(!variants.includes('before'))for(let i=0;i<6;i++)samples.before.push(JSON.parse(fs.readFileSync(path.join(audit,'before',key+'_'+i+'-timing.json'))));
  const result={key,role,unit,page,first:{before:samples.before[0],after:samples.after[0]},warm:{}};
  for(const variant of ['before','after'])result.warm[variant]=Object.fromEntries(['server','ttfb','total','queries','peak'].map(k=>[k,median(samples[variant].slice(1).map(s=>s[k]))]));
  result.improvement=1-result.warm.after.server/result.warm.before.server;results.push(result);
  fs.writeFileSync(path.join(audit,'benchmark-progress.json'),JSON.stringify({results},null,2));
  console.log('PASS models',key,Math.round(result.warm.before.server)+' -> '+Math.round(result.warm.after.server)+' ms',Math.round(result.improvement*100)+'%');
 }
 if(!variants.includes('after')){console.log('Baseline recorded.');return;}
 for(const result of results){
  if(result.unit===1)assert(result.improvement>=.5,'SD 50% target not reached: '+result.key+' '+Math.round(result.improvement*100)+'%');
  assert(result.warm.after.server<=result.warm.before.server*1.1,'Consistent control regression: '+result.key);
 }
 fs.writeFileSync(path.join(audit,process.env.SPP_HISTORY_CASE_PREFIX?'benchmark-focused.json':'benchmark.json'),JSON.stringify({passed:true,results},null,2));
 console.log('PASS',results.length,'HTTP scenarios, first request + five repeats per variant');
})().catch(error=>{console.error(error.message);process.exitCode=1;});
