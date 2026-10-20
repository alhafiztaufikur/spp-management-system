<?php
if(PHP_SAPI!=='cli'||!in_array(getenv('SPP_DB_PORT'),['3306','58360'],true)||getenv('SPP_DB_NAME')!=='db_spp_test2')exit(1);
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/migrated_history.php';require_once __DIR__.'/../includes/payment_history.php';require_once __DIR__.'/../includes/legacy_transactions.php';
$koneksi->query('SET SESSION TRANSACTION READ ONLY');$results=[];
foreach([1=>[46852,446,3064],2=>[60181,286,5084],3=>[11211,61,1140],0=>[118244,793,9288]] as $unit=>$expected){
 unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;$t=microtime(true);$source=mh_payment_source($koneksi);
 $counts=hp_one($koneksi,"SELECT COUNT(*) n,SUM(time_precision='datetime') known,SUM(time_precision='none') missing,SUM(created_at IS NOT NULL) invented FROM $source p");
 if([(int)$counts['n'],(int)$counts['known'],(int)$counts['missing']]!==$expected||(int)$counts['invented']!==0)throw new RuntimeException('Time counts mismatch '.json_encode([$unit,$counts]));
 $rows=hp_rows($koneksi,"SELECT p.id,p.unit_id,p.TGL_BYR,p.time_precision,p.imported_at,p.stored_datetime FROM $source p WHERE p.time_precision='datetime' ORDER BY p.TGL_BYR DESC,p.id DESC");
 $index=payment_time_index($koneksi);foreach($rows as $p){$key=null;foreach($index as $e)if((int)$e['unit_id']===(int)$p['unit_id']&&$e['archive_at']===$p['TGL_BYR']){$key=$e;break;}if(!$key||!$p['imported_at'])throw new RuntimeException('Missing time evidence');}
 if($unit){$p=$rows[0];$model=lt_model($koneksi,-(int)$p['id']-1000000000);if($model['payment']['TGL_BYR']!==$p['TGL_BYR']||$model['payment']['created_at']!==null||!$model['payment']['imported_at'])throw new RuntimeException('Detail/list time mismatch');}
 $results[$unit]=['counts'=>$counts,'ms'=>round((microtime(true)-$t)*1000)];echo 'PASS payment time unit '.$unit.PHP_EOL;
}
if($file=getenv('SPP_TIME_RESULT'))file_put_contents($file,json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
