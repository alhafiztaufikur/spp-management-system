<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_/',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/kelas.php';
function fp_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$koneksi->begin_transaction();
try{
 foreach(['2026-06-30 16:59:59'=>'2025/2026','2026-06-30 17:00:00'=>'2026/2027','2026-12-31 17:00:00'=>'2026/2027'] as $utc=>$expected){
  $wib=(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Jakarta'));
  fp_assert(du_academic_year_label((int)$wib->format('n'),(int)$wib->format('Y'))===$expected,'WIB school-year boundary wrong');
 }
 echo 'PASS: July school-year boundary and January year change in WIB'.PHP_EOL;
 $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2098/2099';
 foreach([1,2,3] as $unit){
  $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);[$first,$last]=unit_level_bounds();$yearId=class_ensure_academic_year($koneksi,'2098/2099');$ctx=promotion_context($koneksi,$yearId);
  $master=spp_master_ensure_year($koneksi,$ctx['target_year']);spp_master_save_rates($koneksi,(int)$master['id'],array_fill_keys(range($first,$last),300000));
  $fixture=function(int $grade,string $tag)use($koneksi,$unit,$yearId):array{
   $class=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=$grade AND kode_rombel='A' AND is_active=1")->fetch_row()[0];
   $nis='00'.(string)random_int(81000000,81999999);$name='TEST FLEX '.$unit.' '.$tag;$level=(string)$grade;
   $s=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,potongan_spp_nominal,POMG,is_active) VALUES(?,?,?,?,250000,25000,10000,1)");$s->bind_param('sssi',$nis,$name,$level,$class);$s->execute();$id=(int)$koneksi->insert_id;$s->close();
   $label=$grade.'A';$s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,250000,10000,'aktif')");$s->bind_param('issis',$yearId,$nis,$level,$class,$label);$s->execute();$p=(int)$koneksi->insert_id;$s->close();return ['nis'=>$nis,'id'=>$id,'placement'=>$p,'class'=>$class,'level'=>$grade];
  };
  $senior=$fixture($last,'senior');$a=$fixture($last-1,'a');$b=$fixture($last-1,'b');$junior=$fixture($first,'junior');
  $target=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=$last AND kode_rombel='A' AND is_active=1")->fetch_row()[0];
  $r=promotion_batch($koneksi,[$a['nis']],[$a['nis']=>$target],$ctx,$last-1,[$a['nis']=>$a['placement']]);
  fp_assert(count($r['successes'])===1,'Lower grade blocked while senior still pending');
  fp_assert((int)$koneksi->query("SELECT KELAS FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===$last,'Active class not updated');
  fp_assert((float)$koneksi->query("SELECT SPP_PERBULAN FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===275000.0,'Target master/nominal discount not used');
  $seniors=class_students_for_manual_step($koneksi,$last,$ctx['target_year']);fp_assert(!in_array($a['nis'],array_column($seniors,'NO_INDUK'),true),'New senior was returned for source-year graduation');
  if($first<$last-1){$targetJ=(int)$koneksi->query('SELECT id FROM master_kelas WHERE tingkat='.($first+1)." AND kode_rombel='A'")->fetch_row()[0];$j=promotion_batch($koneksi,[$junior['nis']],[$junior['nis']=>$targetJ],$ctx,$first,[$junior['nis']=>$junior['placement']]);fp_assert(count($j['successes'])===1,'Small grade blocked');}
  $g=promotion_batch($koneksi,[$senior['nis']],[],$ctx,$last,[$senior['nis']=>$senior['placement']]);fp_assert(count($g['successes'])===1,'Senior graduation blocked after lower promotion');
  $g=promotion_batch($koneksi,[$a['nis']],[],$ctx,$last,[$a['nis']=>$a['placement']]);fp_assert(!$g['successes']&&count($g['failures'])===1,'New senior graduated in same cycle');
  $replay=promotion_batch($koneksi,[$a['nis']],[$a['nis']=>$target],$ctx,$last-1,[$a['nis']=>$a['placement']]);fp_assert(!$replay['successes'],'Replay accepted');
  $next=(int)$koneksi->query("SELECT id FROM tahun_ajaran WHERE label='{$ctx['target_year']}'")->fetch_row()[0];
  $blocked=false;try{promotion_context($koneksi,$next);}catch(RuntimeException $e){$blocked=true;}fp_assert($blocked,'Future source accepted');
  $mixed=promotion_batch($koneksi,[$a['nis'],$b['nis']],[$a['nis']=>$target,$b['nis']=>$target],$ctx,$last-1,[$a['nis']=>$a['placement'],$b['nis']=>$b['placement']]);
  fp_assert(count($mixed['successes'])===1&&count($mixed['failures'])===1,'Partial batch outcomes wrong');
  fp_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='{$a['nis']}'")->fetch_row()[0]===2,'Placement duplicated');
  fp_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='{$senior['nis']}'")->fetch_row()[0]===1,'Graduation created fake target');
  $source=$koneksi->query("SELECT kelas,status,spp_perbulan_snapshot FROM siswa_tahun_ajaran WHERE id={$a['placement']}")->fetch_assoc();
  fp_assert((int)$source['kelas']===$last-1&&$source['status']==='pindah'&&(float)$source['spp_perbulan_snapshot']===250000.0,'Source history overwritten');
  $bad=promotion_batch($koneksi,[$b['nis']],[$b['nis']=>$target],$ctx,$last-1,[$b['nis']=>$a['placement']]);fp_assert(!$bad['successes'],'Stale placement accepted');
  echo 'PASS: unit '.$unit.' lower-first, small-grade, split promotion, graduation, replay, future-source, partial batch, target tariff and immutable source'.PHP_EOL;
 }
}catch(Throwable $e){fwrite(STDERR,'FAILED: '.$e->getMessage().PHP_EOL);throw $e;}finally{$koneksi->rollback();unset($_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']);}
