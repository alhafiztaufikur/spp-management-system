<?php
/** Exercise an undated receipt only inside a rolled-back fixture on a separate server. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))||(int)getenv('SPP_DB_PORT')<=0||(int)getenv('SPP_DB_PORT')===3306||!getenv('SPP_FILTER_MYSQL_UUID'))throw new RuntimeException('Use the explicitly identified isolated clone.');
require_once __DIR__.'/../koneksi.php';
if($koneksi->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_FILTER_MYSQL_UUID'))throw new RuntimeException('Unexpected test server.');
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/migrated_history.php';
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$db=$koneksi;$db->begin_transaction();
try{
 $receipt=hp_one($db,"SELECT p.id,p.source_date FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0 AND p.source_date>='2026-10-01' AND p.source_date<'2026-10-23' AND p.source_period='10 2026' ORDER BY p.id LIMIT 1 FOR UPDATE");
 $check((bool)$receipt,'Real completed October receipt available');
 $read=static fn(array $input)=>mh_payment_page($db,$input,['values'=>['*']],['*'],'2026-10-01 00:00:00','2026-10-23 00:00:00',1,10);
 $dated=$read([]);$all=$read(['all_periods'=>'1']);$before=report_legacy_periods($db,['2026-10']);
 lf_query($db,'UPDATE legacy_op_payments SET source_date=NULL WHERE id=?',[$receipt['id']]);
 $afterDated=$read([]);$afterAll=$read(['all_periods'=>'1']);
 $check($afterDated['total']===$dated['total']-1,'Date range excludes the undated receipt');
 $check($afterAll['total']===$all['total'],'All periods still includes the receipt');
 $check($afterAll['undated']===$all['undated']+1,'Undated evidence remains available without a public checkbox');
 $scope=static function(array $source):array{$out=[];foreach($source['payments'] as $nis=>$categories)foreach(['spp','komite'] as $category)if(isset($categories[$category]['2026-10']))$out[$nis.'|'.$category]=$categories[$category]['2026-10']['paid'];ksort($out);return $out;};
 $check($scope($before)===$scope(report_legacy_periods($db)),'SPP and Komite obligation credits are independent of receipt date');
}finally{$db->rollback();}
$check(hp_one($db,'SELECT source_date FROM legacy_op_payments WHERE id=?',[$receipt['id']])['source_date']===$receipt['source_date'],'Fixture restored by rollback');
echo "PASS $checks isolated date-range, all-periods and SPP-Komite evidence checks (fixture rolled back)\n";
