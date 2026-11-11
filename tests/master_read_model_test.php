<?php
/** Compare bounded master evidence with the existing complete reader on a restored dataset. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))||(int)getenv('SPP_DB_PORT')===3306)throw new RuntimeException('Use a separately restored audit database.');
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/spp_advance.php';require_once __DIR__.'/../includes/legacy_du.php';require_once __DIR__.'/../includes/student_out_schema.php';
$checks=0;$check=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$db=$koneksi;$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try{
 $check(pspp_schema_plan($db)===[]&&spa_schema_plan($db)===[]&&sout_schema_plan($db)===[],'Complete schema contracts remain ready');
 foreach([1,2,3] as $unit){
  $_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);$_SERVER['REQUEST_METHOD']='GET';
  unset($GLOBALS['lb_master_read'][$db->thread_id]);$GLOBALS['lb_report_compact'][$db->thread_id]=false;$GLOBALS['lb_read_projection'][$db->thread_id]=true;
  $full=lb_cache($db);$numbers=array_column($full['students'],'NO_INDUK');
  foreach(['2026/2027','2027/2028','2025/2026','2188/2189'] as $year){
   unset($GLOBALS['lb_master_read'][$db->thread_id]);
   $expected=[];
   foreach($full['students'] as $s)$expected[$s['NO_INDUK']]=array_filter(lb_monthly_plan($db,$s['NO_INDUK'])['positions'],static fn($p)=>$p['category']==='spp'&&$p['year']===$year);
   lb_master_read($db,$year,'spp',$numbers);
   foreach($full['students'] as $s){
    $actual=array_filter(lb_monthly_plan($db,$s['NO_INDUK'])['positions'],static fn($p)=>$p['category']==='spp'&&$p['year']===$year);
    $normalize=static function(array $positions):array{foreach($positions as &$p)sort($p['evidence']['rows'],SORT_STRING);unset($p);ksort($positions);return $positions;};
    $check($normalize($expected[$s['NO_INDUK']])===$normalize($actual),'Monthly proof unchanged for unit '.$unit.' year '.$year.' identity '.$s['student_id']);
    foreach(spp_academic_periods($year) as $p){$period=$p['tahun'].'-'.$p['bulan'];$credit=(float)(($full['paid'][$s['id']]['spp'][$period]??0)/100);
     $check(lb_spp_credit($db,$s['NO_INDUK'],$p['bulan'],$p['tahun'])===$credit,'Exact period credit unchanged');
    }
   }
   unset($GLOBALS['lb_master_read'][$db->thread_id]);$GLOBALS['lb_report_compact'][$db->thread_id]=true;du_psb_invalidate($db);
   $before=du_psb_states($db,$year,true);$annual=lb_cache($db);
   lb_master_read($db,$year,'du');$after=du_psb_states($db,$year,true);
   $check($before===$after,'DU PSB decisions unchanged unit '.$unit.' year '.$year);
   foreach($annual['students'] as $s)$check(lb_du_credit($db,$s['NO_INDUK'],$year)===(float)(($annual['paid'][$s['id']]['du'][$year]??0)/100),'Exact annual DU credit unchanged');
   $GLOBALS['lb_report_compact'][$db->thread_id]=false;
  }
  lb_master_read($db,'2026/2027','spp',[]);$check(lb_cache($db)['students']===[],'Empty identity scope never reads the full archive');
  $_SERVER['REQUEST_METHOD']='POST';$check(count(lb_cache($db)['students'])===count($full['students']),'Writes ignore read-only master evidence');
  $rejected=false;try{lb_master_read($db,'2026/2027','spp');}catch(LogicException $e){$rejected=true;}$check($rejected,'Cannot enable a master profile on POST');
  $_SERVER['REQUEST_METHOD']='GET';unset($GLOBALS['lb_master_read'][$db->thread_id]);
 }
 foreach(["07 2026","7 2026","2026-07","Juli 2026","JULI 2026\t","\nJanuari 2027\r\n","  1 2027  ","2027-06\0"] as $period){
  $check(lb_payment_year($period)==='2026/2027','Accepted monthly period fixture');
  $row=hp_one($db,"SELECT ? REGEXP '(^|[^0-9])(2026|2027)([^0-9]|$)' matched",[$period]);$check((int)$row['matched']===1,'SQL prefilter retains accepted whitespace/month formats');
 }
 echo 'PASS '.$checks.' read-model, credit, scope, period and schema checks',PHP_EOL;
}finally{$db->rollback();}
