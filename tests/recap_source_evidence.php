<?php
/** Read-only evidence inventory; no backfill, bill issuance or source rewrites. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/general_report_sections.php';
$qa=(string)getenv('SPP_RECAP_QA');if(!is_dir($qa)||!str_contains($qa,'recap_refinements_'))throw new RuntimeException('Private audit output required.');
$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($koneksi,0);$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{
 $result=[];
 $result['payments']=hp_rows($koneksi,"SELECT p.unit_id,COUNT(*) records,SUM(p.source_date IS NULL) missing_date,SUM(p.source_date IS NULL AND (JSON_EXTRACT(r.raw_data,'$.TGL_BYR') IS NULL OR JSON_TYPE(JSON_EXTRACT(r.raw_data,'$.TGL_BYR'))='NULL')) missing_in_original,SUM(r.id IS NULL) missing_source FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id LEFT JOIN legacy_finance_rows r ON r.unit_id=p.unit_id AND r.source_id=b.source_id AND r.source_table=p.source_table AND r.row_key=p.row_key WHERE b.state='completed' GROUP BY p.unit_id");
 $result['raw_clocks']=hp_rows($koneksi,"SELECT source_table,COUNT(*) records,SUM(JSON_CONTAINS_PATH(raw_data,'one','$.jam')) has_clock_field,SUM(JSON_TYPE(JSON_EXTRACT(raw_data,'$.jam'))='STRING') clock_strings FROM legacy_finance_rows WHERE source_table IN ('bayar','bayar_du','transaksi_m','transaksi_k') GROUP BY source_table");
 $result['journals']=hp_rows($koneksi,"SELECT unit_id,origin,COUNT(*) records,SUM(source_date IS NULL) missing_date,SUM(TIME(source_date)='00:00:00') midnight FROM legacy_op_journals GROUP BY unit_id,origin");
 // Compare stable source keys in a bounded cursor; avoid a large anti-join of scoped views.
 $batches=[];foreach(hp_rows($koneksi,"SELECT id,unit_id,source_id FROM legacy_full_batches WHERE state='completed'") as $b)$batches[$b['id']]=$b;
 $paymentKeys=[];foreach(hp_rows($koneksi,'SELECT unit_id,batch_id,source_table,row_key FROM legacy_op_payments') as $p)if(isset($batches[$p['batch_id']]))$paymentKeys[$p['unit_id'].'|'.$batches[$p['batch_id']]['source_id'].'|'.$p['source_table'].'|'.$p['row_key']]=true;
 $journalKeys=[];foreach(hp_rows($koneksi,"SELECT unit_id,batch_id,row_key FROM legacy_op_journals WHERE origin='legacy'") as $p)if(isset($batches[$p['batch_id']]))$journalKeys[$p['unit_id'].'|'.$batches[$p['batch_id']]['source_id'].'|'.$p['row_key']]=($journalKeys[$p['unit_id'].'|'.$batches[$p['batch_id']]['source_id'].'|'.$p['row_key']]??0)+1;
 $result['unmapped_raw_journals']=[];$result['unmapped_raw_payments']=[];$result['journal_source_tables']=[];
 $cursor=$koneksi->query(hp_sql($koneksi,"SELECT unit_id,source_id,source_table,row_key,amounts,source_identity,source_nis,reference_only FROM legacy_finance_rows WHERE source_table IN ('bayar','bayar_du','transaksi_m','transaksi_k','tb_masuk','tb_keluar')"),MYSQLI_USE_RESULT);
 while($r=$cursor->fetch_assoc()){$key=$r['unit_id'].'|'.$r['source_id'].'|'.$r['row_key'];if(in_array($r['source_table'],['bayar','bayar_du'],true)){if(!isset($paymentKeys[$r['unit_id'].'|'.$r['source_id'].'|'.$r['source_table'].'|'.$r['row_key']]))$result['unmapped_raw_payments'][]=$r;}elseif(isset($journalKeys[$key])){$result['journal_source_tables'][$r['source_table']]=($result['journal_source_tables'][$r['source_table']]??0)+$journalKeys[$key];unset($journalKeys[$key]);}elseif(in_array($r['source_table'],['transaksi_m','transaksi_k'],true))$result['unmapped_raw_journals'][]=$r;}
 $cursor->free();$result['unmatched_operational_journal_keys']=array_keys($journalKeys);unset($paymentKeys,$journalKeys);
 foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);$_GET=[];$sections=general_sections($koneksi,['*'],'2026-10-01','2026-10-22','','nama',10,0,[]);foreach($sections as $s)$result['sections'][$unit][$s['type']]=['count'=>$s['row_count'],'totals'=>$s['totals'],'notes'=>$s['notes']??[]];}
}finally{$koneksi->rollback();}
file_put_contents($qa.'/source-evidence.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo 'Read-only source inventory: '.array_sum(array_column($result['payments'],'records')).' payments, '.array_sum(array_column($result['payments'],'missing_date')).' undated, '.count($result['unmapped_raw_journals'])." raw journals without an operational row.\n";
