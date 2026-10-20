<?php
/** Applied precision and archive precedence, all writes rolled back on the owned clone. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58360'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')exit(1);
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/legacy_transactions.php';require_once __DIR__.'/../includes/migrated_history.php';
$fixture=json_decode(file_get_contents(getenv('SPP_TIME_FIXTURE')),true,512,JSON_THROW_ON_ERROR);$checks=[];
$assert=static function(bool $ok,string $name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks[]=$name;echo "PASS $name\n";};
foreach([1,2,3] as $unit){
 $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$row=$fixture['units'][$unit]['known'];$id=-(int)$row['id']-1000000000;$original=lop_record($koneksi,'payment',$id);
 $run=static function(array $change,string $precision,?string $expected)use($unit,$id,$original,$koneksi,$assert){
  $koneksi->begin_transaction();try{
   $input=['date'=>$original['source_date'],'items'=>$original['items'],'note'=>'Audit waktu pada salinan','reason'=>'Memeriksa ketepatan waktu transaksi','preview_expires'=>time()+300,'confirmation'=>'SIMPAN KOREKSI'];
   $input=array_replace($input,$change);$key=bin2hex(random_bytes(32));$version=(int)$original['version'];$preview=lop_correction_preview($koneksi,1,$unit,'payment',$id,$version,$input);
   lop_correction_apply($koneksi,1,$unit,'payment',$id,$version,$input,$preview['digest'],$key,false);
   $stored=lop_record($koneksi,'payment',$id);$shown=lt_model($koneksi,$id)['payment'];
   $assert($shown['time_precision']===$precision&&$shown['TGL_BYR']===$expected,"Unit $unit applied $precision time precedence");
   $assert($shown['updated_at']!==null&&$shown['created_at']===null,"Unit $unit applied audit supplies update time only");
   if(!isset($change['time_precision']))$assert($stored['source_date']===$original['source_date'],"Unit $unit note correction leaves stored archive padding untouched");
   $source=mh_payment_source($koneksi);$sql=hp_one($koneksi,"SELECT TGL_BYR,time_precision FROM $source p WHERE p.id=?",[-1000000000-$id]);
   $assert($sql['TGL_BYR']===$shown['TGL_BYR']&&$sql['time_precision']===$precision,"Unit $unit corrected list and detail agree");
   $events=lt_events($koneksi,$id);$first=$events[0];
   $assert($first['occurred_at']!==null&&$first['occurred_at']!==$original['source_date'],"Unit $unit original activity retains its archive clock");
   $assert(lop_correction_apply($koneksi,1,$unit,'payment',$id,$version,$input,$preview['digest'],$key,false)['replayed'],"Unit $unit correction replay changes time once");
  }finally{$koneksi->rollback();payment_time_invalidate($koneksi);}
 };
 $run([],'datetime',$row['TGL_BYR']);
 $run(['date'=>substr($row['TGL_BYR'],0,10).' 00:00:00','time_precision'=>'datetime'],'datetime',substr($row['TGL_BYR'],0,10).' 00:00:00');
 $run(['date'=>substr($row['TGL_BYR'],0,10),'time_precision'=>'date'],'date',substr($row['TGL_BYR'],0,10).' 00:00:00');
 $run(['date'=>null,'time_precision'=>'none'],'none',null);
 $assert(lop_record($koneksi,'payment',$id)===$original,"Unit $unit all time corrections rolled back exactly");
 unit_set_context($koneksi,$unit===1?2:1);$_SESSION['active_unit_id']=$unit===1?2:1;
 try{lop_record($koneksi,'payment',$id);throw new RuntimeException('Cross-unit payment leaked');}catch(DomainException $e){$assert(true,"Unit $unit cross-unit time edit rejected");}
}
if($file=getenv('SPP_TIME_MUTATION_RESULT'))file_put_contents($file,json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
