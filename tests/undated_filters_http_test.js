'use strict';
// Compare public pages and exports against a read-only baseline on an isolated clone.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const audit=process.env.SPP_FILTER_AUDIT_ROOT;
if(!audit||!process.env.SPP_FILTER_BEFORE_URL||!process.env.SPP_FILTER_AFTER_URL)throw Error('Set the private audit root and both read-only audit server URLs.');
const urls={before:process.env.SPP_FILTER_BEFORE_URL,after:process.env.SPP_FILTER_AFTER_URL};
for(const url of Object.values(urls))assert.equal(new URL(url).hostname,'127.0.0.1');
const sessions=JSON.parse(fs.readFileSync(path.join(audit,'sessions.json'),'utf8')),checks=[];
const dates='tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22';
function stable(value){
 if(Array.isArray(value))return value.map(stable);
 if(value&&typeof value==='object')return Object.fromEntries(Object.entries(value).filter(([key])=>key!=='generated').map(([key,v])=>[key,stable(v)]));
 return value;
}
async function read(variant,route,session,key){
 const response=await fetch(urls[variant]+route,{headers:{Cookie:'PHPSESSID='+session,'X-Audit-Case':key},redirect:'manual',signal:AbortSignal.timeout(180000)});
 const body=Buffer.from(await response.arrayBuffer());
 assert.equal(response.status,200,variant+' '+route+' HTTP');
 assert(!/Fatal error|Uncaught |<b>Warning<\/b>/.test(body.toString('utf8')),variant+' '+route+' runtime');
 return {body,headers:response.headers,model:stable(JSON.parse(fs.readFileSync(path.join(audit,variant,key+'.json'),'utf8')))};
}
function cleanLinks(html,transaction=false){
 assert(!/name=["'](?:include_undated|undated)["']|(?:[?&]|&amp;)(?:include_undated|undated)=/.test(html),'No obsolete control or navigation parameter');
 assert(!/ph-legacy-filter|du-history-notice|report-undated-field|report-period-options/.test(html),'No removed wrapper');
 if(transaction)assert(!/name=["']all_periods["']|(?:[?&]|&amp;)all_periods=/.test(html),'Transaction report has no all-periods escape');
}
(async()=>{
 const section=process.env.SPP_FILTER_HTTP_SECTION||'all';assert(['all','models','exports'].includes(section));
 const session=sessions['super_admin-1'];
 if(section!=='exports'){
 const routes=[['payment','/pembayaran/lihat.php?'+dates],['du','/pembayaran/riwayat_daftar_ulang.php?year=2026%2F2027'],['transactions','/laporan/index.php?'+dates],['daily','/laporan/template.php?template=penerimaan&'+dates]];
 for(const role of ['super_admin','admin'])for(const unit of [1,2,3,0])for(const [name,route] of routes){
  const key=[role,unit,name].join('_'),session=sessions[role+'-'+unit];
  const before=await read('before',route,session,key),after=await read('after',route,session,key);
  assert.deepEqual(after.model,before.model,key+' unchanged default financial model');cleanLinks(after.body.toString('utf8'),name==='transactions');
  const stale=await read('after',route+'&undated=1&include_undated=1'+(name==='transactions'?'&all_periods=1':''),session,key+'_stale');
  assert.deepEqual(stale.model,after.model,key+' obsolete parameters ignored');cleanLinks(stale.body.toString('utf8'),name==='transactions');
  checks.push(key);console.log('PASS',key);
 }
 const all='/pembayaran/lihat.php?all_periods=1&'+dates;
 const oldAll=await read('before',all,session,'all_periods'),newAll=await read('after',all+'&undated=1&include_undated=1',session,'all_periods');
 assert.deepEqual(newAll.model,oldAll.model,'All periods history unchanged');assert(newAll.model.history.total>0&&newAll.model.history.total>=newAll.model.history.undated,'All periods preserves every available receipt');cleanLinks(newAll.body.toString('utf8'));checks.push('all_periods');
 for(const template of ['setoran','kas-tabungan','tabungan-siswa','per-item','spp-tahunan','status','riwayat-tagihan','psb','saldo-tabungan']){
  const route='/laporan/template.php?template='+template+'&tahun_ajaran=2026%2F2027&'+dates,key='global_'+template.replaceAll('-','_');
  const before=await read('before',route,session,key),after=await read('after',route+'&include_undated=1&undated=1',session,key);
  assert.deepEqual(after.model,before.model,key+' default model retained');cleanLinks(after.body.toString('utf8'));checks.push(key);console.log('PASS',key);
 }
 fs.writeFileSync(path.join(audit,'http-models-result.json'),JSON.stringify({passed:true,checks:[...checks]},null,2));
 }
 if(section!=='models')for(const [key,route,type] of [
  ['transaction_excel','/laporan/export_excel.php?'+dates+'&download=1','xlsx'],
  ['transaction_pdf','/laporan/export_pdf.php?'+dates+'&output=pdf','pdf'],
  ['global_excel','/laporan/export_global.php?template=penerimaan&'+dates+'&format=excel&download=1','xlsx'],
  ['global_pdf','/laporan/export_global.php?template=penerimaan&'+dates+'&format=pdf','pdf'],
  ['global_print','/laporan/export_global.php?template=penerimaan&'+dates+'&format=print','pdf']
 ]){
  const before=await read('before',route,session,key),after=await read('after',route+'&undated=1&include_undated=1'+(key.startsWith('transaction_')?'&all_periods=1':''),session,key);
  assert.deepEqual(after.model,before.model,key+' stale flags cannot widen export');
  assert(after.body.subarray(0,type==='pdf'?5:2).equals(Buffer.from(type==='pdf'?'%PDF-':'PK')),key+' valid artifact');
  checks.push(key);console.log('PASS',key);
 }
 fs.writeFileSync(path.join(audit,section==='all'?'http-result.json':'http-'+section+'-result.json'),JSON.stringify({passed:true,checks},null,2));
 console.log('PASS',checks.length,'public model, legacy URL and export scenarios');
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
