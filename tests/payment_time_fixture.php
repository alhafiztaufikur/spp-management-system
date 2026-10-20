<?php
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58360')exit(1);
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/migrated_history.php';
$koneksi->query('SET SESSION TRANSACTION READ ONLY');$out=[];
foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);$s=mh_payment_source($koneksi);
 foreach(['known'=>"p.time_precision='datetime' AND p.student_id IS NOT NULL",'date_only'=>"p.time_precision='date' AND p.student_id IS NOT NULL",'undated'=>"p.time_precision='none'"] as $key=>$condition){
  $out['units'][$unit][$key]=hp_one($koneksi,"SELECT p.* FROM $s p WHERE $condition ORDER BY p.TGL_BYR DESC,p.id DESC LIMIT 1");
 }
}
unit_set_context($koneksi,0);$s=mh_payment_source($koneksi);
$out['clocks']=hp_rows($koneksi,"SELECT -(1000000000+p.id) id,p.unit_id,p.source_date stored_date,JSON_UNQUOTE(JSON_EXTRACT(r.raw_data,'$.TGL_BYR')) source_day,JSON_UNQUOTE(JSON_EXTRACT(r.raw_data,'$.jam')) source_clock FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id JOIN legacy_finance_rows r ON r.unit_id=p.unit_id AND r.source_id=b.source_id AND r.row_key=p.row_key WHERE JSON_TYPE(JSON_EXTRACT(r.raw_data,'$.jam'))='STRING'");
$out['resolved']=hp_rows($koneksi,"SELECT id,unit_id,TGL_BYR,time_precision FROM $s p WHERE time_precision='datetime'");
$path=getenv('SPP_TIME_FIXTURE');if(!$path)exit(1);file_put_contents($path,json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'Created private time fixture'.PHP_EOL;
