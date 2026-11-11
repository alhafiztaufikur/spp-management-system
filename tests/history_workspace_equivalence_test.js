'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const audit=process.env.SPP_HISTORY_AUDIT_ROOT,sessions=JSON.parse(fs.readFileSync(audit+'/sessions.json','utf8'));
const urls={before:process.env.SPP_HISTORY_BEFORE_URL||'http://127.0.0.1:8131',after:process.env.SPP_HISTORY_AFTER_URL||'http://127.0.0.1:8132'};
const stable=v=>Array.isArray(v)?v.map(stable):v&&typeof v==='object'?Object.fromEntries(Object.entries(v).filter(([k])=>!['read_sql','read_args'].includes(k)).map(([k,x])=>[k,stable(x)])):v;
(async()=>{const checks=[];
 for(const role of ['super_admin','admin'])for(const unit of [1,2,3,0]){
  const baseline=JSON.parse(fs.readFileSync(audit+'/before/'+role+'_'+unit+'_du_5.json')).model.data;
  const payment=JSON.parse(fs.readFileSync(audit+'/before/'+role+'_'+unit+'_payment_5.json')).model.history.rows[0];
  const operator=Object.keys(baseline.operatorOptions).find(k=>k.startsWith('legacy:'));
  const cases=[
   ['du_all','/pembayaran/riwayat_daftar_ulang.php'],
   ...['lunas','cicilan','unknown','covered_psb'].map(s=>['du_'+s,'/pembayaran/riwayat_daftar_ulang.php?status_filter='+s]),
   ...['system','legacy'].map(s=>['du_'+s,'/pembayaran/riwayat_daftar_ulang.php?source='+s]),
   ['du_historical','/pembayaran/riwayat_daftar_ulang.php?year=2019%2F2020'],
   ['du_empty','/pembayaran/riwayat_daftar_ulang.php?q=QA_no_matching_student_09f38b'],
   ['du_page','/pembayaran/riwayat_daftar_ulang.php?page=2&per_page=10'],
   ['payment_all','/pembayaran/lihat.php?all_periods=1'],
   ['payment_page','/pembayaran/lihat.php?tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22&page=2'],
   ['payment_history','/pembayaran/lihat.php?tanggal_awal=2019-01-01&tanggal_akhir=2019-12-31'],
   ['payment_empty','/pembayaran/lihat.php?tanggal_awal=2198-01-01&tanggal_akhir=2198-12-31'],
   ['payment_deleted','/pembayaran/lihat.php?view=deleted&all_periods=1'],
   ['fee_inactive','/master_biaya_lain.php?master_tab=nonaktif'],['fee_deletable','/master_biaya_lain.php?master_tab=hapus'],
   ['complex_du_operator','/pembayaran/riwayat_daftar_ulang.php?operator='+encodeURIComponent(operator)],
   ['complex_du_search','/pembayaran/riwayat_daftar_ulang.php?q='+encodeURIComponent(baseline.rows[0].identity)],
   ['complex_du_class','/pembayaran/riwayat_daftar_ulang.php?class_id=tingkat%3A'+(unit===2?8:unit===3?11:2)],
   ['complex_payment_operator','/pembayaran/lihat.php?all_periods=1&operator='+encodeURIComponent(payment.user_id)],
   ['complex_payment_search','/pembayaran/lihat.php?all_periods=1&search='+encodeURIComponent(payment.NO_INDUK)],
  ];
  const selectedCases=cases.filter(([label])=>!process.env.SPP_HISTORY_CASE_PREFIX||label.startsWith(process.env.SPP_HISTORY_CASE_PREFIX));
  for(const [label,route] of selectedCases){const key='equivalence_'+role+'_'+unit+'_'+label;let before;
   for(const variant of ['before','after']){
    const res=await fetch(urls[variant]+route,{headers:{Cookie:'PHPSESSID='+sessions[role+'-'+unit],'X-Audit-Case':key,'X-Audit-Model-Scope':'all'},signal:AbortSignal.timeout(180000)}),html=await res.text();
    assert.equal(res.status,200,key+' '+variant);assert(!/Fatal error|Uncaught |<b>Warning<\/b>/.test(html),key+' runtime');
    const model=stable(JSON.parse(fs.readFileSync(audit+'/'+variant+'/'+key+'.json')).model);if(variant==='before')before=model;else assert.deepEqual(model,before,key+' financial results/operators/permissions/all-result digest');
   }
   checks.push(key);
  }
  console.log('PASS',role,'unit',unit,selectedCases.length,'complete model scenarios');
 }
 fs.writeFileSync(audit+(process.env.SPP_HISTORY_CASE_PREFIX?'/equivalence-complex-result.json':'/equivalence-result.json'),JSON.stringify({passed:true,checks},null,2));console.log('PASS',checks.length,'financial model comparisons');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
