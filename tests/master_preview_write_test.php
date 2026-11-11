<?php
/** A version produced by the batched GET preview must still authorize the existing live writer. */
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/spp_advance.php';require_once __DIR__.'/../includes/kelas.php';
$db=$koneksi;if((int)$db->query('SELECT @@port')->fetch_row()[0]===3306)throw new RuntimeException('Separate server required.');
$checks=0;$check=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$actors=[];foreach($db->query("SELECT id,role FROM admin WHERE is_active=1 ORDER BY id") as $a)$actors[$a['role']]??=(int)$a['id'];
$db->begin_transaction();
try{
 foreach(['super_admin','admin'] as $role)foreach([1,2,3] as $unit){
  $_SESSION=['admin_id'=>$actors[$role],'admin_role'=>$role,'active_unit_id'=>$unit];unit_set_context($db,$unit);$_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2186/2187';$_SERVER['REQUEST_METHOD']='POST';unset($GLOBALS['lb_master_read'][$db->thread_id]);lb_cache($db,true);sct_invalidate($db);
  [$first,$last]=unit_level_bounds();$psb=hp_one($db,"SELECT id FROM master_kelas WHERE tingkat=0 AND kode_rombel='PSB' LIMIT 1");$class=hp_one($db,'SELECT * FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1',[$first+1]);
  $prefix='998867'.($role==='admin'?'2':'1');$regularNis=$prefix.'001';$psbNis=$prefix.'002';
  lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,'Master preview regular',?,?,250000,15000,1)",[$regularNis,$first+1,$class['id']]);$regular=(int)$db->insert_id;
  lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB,is_active) VALUES(?,'Master preview PSB','0',?,1000000,1)",[$psbNis,$psb['id']]);$admission=(int)$db->insert_id;
  pspp_save($db,$admission,'325000,50','15000','2187/2188');
  $current=spp_master_ensure_year($db,'2186/2187');if(spp_master_rates_editable(spp_master_state($db,(int)$current['id'])))spp_master_save_rates($db,(int)$current['id'],array_fill($first+1,$last-$first,250000));
  $sync=null;class_sync_student_current_year($db,$regularNis,(int)$class['id'],250000,15000,true,$sync);spp_publish_students($db,(int)$current['id'],[$regularNis]);
  $initial=sct_context_for_student($db,(int)$current['id'],$regular);sct_save($db,(int)$current['id'],$regular,'2187-01','0.00','QA explicit zero master preview',$initial['version']);
  lf_query($db,"UPDATE tagihan_spp SET status='cancelled',cancel_reason='Manual preview fixture' WHERE no_induk=? AND bulan='12' AND tahun='2186'",[$regularNis]);
  $versions=[];
  foreach(['2186/2187','2187/2188'] as $year){
   $_SERVER['REQUEST_METHOD']='POST';$master=spp_master_ensure_year($db,$year);$evidence=[];$rows=spa_roster($db,$year,$evidence);$bills=sct_master_read_bills($db,(int)$master['id']);$rates=spp_master_rates($db,(int)$master['id']);$expected=[];
   foreach([$regular,$admission] as $id)if(isset($rows[$id]))$expected[$id]=sct_context_for_student($db,(int)$master['id'],$id);
   if(isset($rows[$admission])){
    $direct=sct_admission_student($db,$admission,(int)$master['tahun_ajaran_id']);foreach($direct as $key=>$value)$check(($rows[$admission][$key]??null)===$value,'Batched PSB row matches direct reader: '.$key);
   }
   $_SERVER['REQUEST_METHOD']='GET';lb_master_read($db,$year,'spp',array_merge(array_column($rows,'no_induk'),array_keys($bills)));
   foreach($expected as $id=>$context){$actual=sct_context($db,$master,$rows[$id],$rates,$bills,$evidence['spp']);$check($actual===$context,'GET preview and locked writer context match exactly');$versions[$year][$id]=$actual['version'];}
  }
  $_SERVER['REQUEST_METHOD']='POST';$cancelled=hp_one($db,"SELECT * FROM tagihan_spp WHERE no_induk=? AND bulan='12' AND tahun='2186'",[$regularNis]);
  $result=sct_save($db,(int)$current['id'],$regular,'2186-11','375000.50','QA apply batched preview version',$versions['2186/2187'][$regular]);$check($result['updated']>0,'GET version authorizes existing POST writer');
  $check(hp_one($db,"SELECT * FROM tagihan_spp WHERE no_induk=? AND bulan='12' AND tahun='2186'",[$regularNis])===$cancelled,'Manual cancellation preserved');
  $rejected=false;try{sct_save($db,(int)$current['id'],$regular,'2186-11','450000','QA stale preview version',$versions['2186/2187'][$regular]);}catch(DomainException $e){$rejected=true;}$check($rejected,'Stale GET preview rejected by writer');
 }
 echo 'PASS '.$checks.' PSB, zero, cancellation, role/unit and GET-to-POST version checks',PHP_EOL;
}finally{$db->rollback();}
