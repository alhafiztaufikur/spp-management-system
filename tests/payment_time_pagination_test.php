<?php
/** Compare narrow ordering + batch hydration against the full source before pagination. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='3306'||getenv('SPP_DB_NAME')!=='db_spp_test2')exit(1);
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/migrated_history.php';require_once __DIR__.'/../includes/history_class_filter.php';require_once __DIR__.'/../includes/history_operator_filter.php';
$koneksi->query('SET SESSION TRANSACTION READ ONLY');$checks=[];
foreach([1,2,3,0] as $unit){
 $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$classes=history_class_filter($koneksi,[]);$ops=history_operator_choices(null,history_operator_options($koneksi));$source=mh_payment_source($koneksi);
 foreach([['tanggal_awal'=>'2022-01-01'],['all_periods'=>'1'],['undated'=>'1'],['source'=>'legacy','all_periods'=>'1']] as $input){
  $where=isset($input['undated'])?'p.TGL_BYR IS NULL':(isset($input['tanggal_awal'])?"p.stored_datetime>='2022-01-01' AND p.stored_datetime<'2027-01-01'":'1=1');
  if(isset($input['source']))$where.=" AND p.source_kind='legacy'";
  foreach([1,2] as $page){
   $offset=($page-1)*10;$expected=hp_rows($koneksi,"SELECT p.* FROM $source p WHERE $where ORDER BY p.TGL_BYR DESC,p.unit_id,p.id DESC LIMIT 10 OFFSET $offset");
   $actual=mh_payment_page($koneksi,$input,$classes,$ops,'2022-01-01 00:00:00','2027-01-01 00:00:00',$page,10);
   if($expected!==$actual['rows'])throw new RuntimeException('Page/time/identity mismatch '.json_encode([$unit,$input,$page]));
   $checks[]=['unit'=>$unit,'input'=>$input,'page'=>$page];echo 'PASS narrow/full pagination unit '.$unit.' page '.$page.' '.json_encode($input).PHP_EOL;
  }
 }
 $target=hp_one($koneksi,"SELECT p.id FROM $source p ORDER BY p.TGL_BYR DESC,p.unit_id,p.id DESC LIMIT 1 OFFSET 35");
 if($target){$actual=mh_payment_page($koneksi,['all_periods'=>'1','selected'=>(string)$target['id']],$classes,$ops,'2022-01-01 00:00:00','2027-01-01 00:00:00',1,10);if($actual['page']!==4||!in_array($target['id'],array_column($actual['rows'],'id'),true))throw new RuntimeException('Selected page rank mismatch');$checks[]=['unit'=>$unit,'selected_rank'=>true];}
}
if($file=getenv('SPP_PAGINATION_RESULT'))file_put_contents($file,json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
