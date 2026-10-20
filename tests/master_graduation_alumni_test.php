<?php
/** Exercise actual graduation/history against a disposable migrated database. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]===3306)throw new RuntimeException('Use a separate MySQL test instance.');
require_once __DIR__.'/../includes/student_alumni.php';
require_once __DIR__.'/../includes/legacy_class_links.php';
require_once __DIR__.'/../includes/promotion_participants.php';
require_once __DIR__.'/../includes/savings_workspace.php';
$checks=0;
function ma_assert(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function ma_row(mysqli $db,string $sql,array $args=[]):array{return special_class_query($db,$sql,$args)->fetch_assoc()?:[];}
function ma_finance(mysqli $db,string $table):array {
 $columns=['bayar'=>['total_jumlah','U_PSB','U_SPP','U_KOMITE'],'bayar_du'=>['jumlah'],'transaksi_m'=>['MASUK','KELUAR'],'transaksi_k'=>['MASUK','KELUAR'],'tagihan_spp'=>['nominal_tagihan'],'tagihan_komite'=>['nominal_tagihan'],'tagihan_daftar_ulang'=>['nominal_tagihan']][$table];
 $sums=implode(',',array_map(static fn($c)=>'COALESCE(SUM('.$c.'),0) '.$c,$columns));
 return ma_row($db,'SELECT COUNT(*) n,'.$sums.' FROM '.$table);
}
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];
$koneksi->begin_transaction();
try{
 foreach([1,2,3] as $unit){
  $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);[, $last]=unit_level_bounds();
  $sourceYear=du_current_academic_year();$year=class_ensure_academic_year($koneksi,$sourceYear);$ctx=promotion_context($koneksi,$year);
  $expected='Alumni'.substr($sourceYear,5,4);
  // Exercise first creation and reuse without changing any historical Alumni membership.
  $fixture=static function(int $grade,?int $placementYear=null)use($koneksi,$unit,$year):array {
   $placementYear??=$year;
   $class=ma_row($koneksi,'SELECT * FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1',[$grade]);
   if(!$class){special_class_query($koneksi,"INSERT INTO master_kelas(tingkat,kode_rombel,is_active,is_placeholder) VALUES(?,'QA',1,0)",[$grade]);$class=class_find($koneksi,(int)$koneksi->insert_id);}
   $nis='0'.random_int(800000000,899999999);$name='QA GRADUATION '.$unit.' '.$nis;
   special_class_query($koneksi,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,530000,25000,1)',[$nis,$name,(string)$grade,$class['id']]);$id=(int)$koneksi->insert_id;
   special_class_query($koneksi,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,530000,25000,'aktif')",[$placementYear,$nis,(string)$grade,$class['id'],class_label($class)]);
   return ['id'=>$id,'nis'=>$nis,'placement'=>(int)$koneksi->insert_id,'class'=>$class];
  };
  $a=$fixture($last);$b=$fixture($last);$lower=$fixture($last-1);
  $old=ma_row($koneksi,'SELECT * FROM siswa_tahun_ajaran WHERE id=?',[$a['placement']]);
  $finance=[];foreach(['bayar','bayar_du','transaksi_m','transaksi_k','tagihan_spp','tagihan_komite','tagihan_daftar_ulang'] as $t)$finance[$t]=ma_finance($koneksi,$t);
  $batch=promotion_batch($koneksi,[$a['nis'],$lower['nis']],[],$ctx,$last,[$a['nis']=>$a['placement'],$lower['nis']=>$lower['placement']]);
  ma_assert(count($batch['successes'])===1&&count($batch['failures'])===1,"Unit $unit partial batch preserves invalid student's state");
  $after=ma_row($koneksi,'SELECT * FROM siswa WHERE id=?',[$a['id']]);$alumni=class_find($koneksi,(int)$after['master_kelas_id']);
  ma_assert(class_label($alumni)===$expected&&class_kind($alumni)==='alumni'&&!(int)$after['is_active'],"Unit $unit graduated student belongs to inactive $expected");
  ma_assert($after['KELAS']==='0'&&(float)$after['SPP_PERBULAN']===530000.0,"Unit $unit financial master fields preserved");
  $p=ma_row($koneksi,'SELECT * FROM siswa_tahun_ajaran WHERE id=?',[$a['placement']]);$old['status']='lulus';unset($p['updated_at'],$old['updated_at']);
  ma_assert($p===$old,"Unit $unit academic class and all tariff snapshots preserved");
  ma_assert((int)ma_row($koneksi,'SELECT COUNT(*) n FROM siswa_tahun_ajaran WHERE no_induk=?',[$a['nis']])['n']===1,"Unit $unit no invented future academic placement");
  $records=student_alumni_records($koneksi,[$after]);$event=$records[unit_student_key($after)]??[];
  ma_assert(($event['source_year']??'')===$sourceYear&&($event['label']??'')===$expected,"Unit $unit timeline has evidence-backed Alumni event");
  $r=promotion_apply_student($koneksi,$b['nis'],0,$ctx,$b['placement'],$last,true);
  ma_assert($r['alumni_class_id']===(int)$alumni['id'],"Unit $unit reuses canonical Alumni class");
  $replay=promotion_batch($koneksi,[$a['nis']],[],$ctx,$last,[$a['nis']=>$a['placement']]);ma_assert(!$replay['successes'],"Unit $unit replay rejected");
  $participants=participants_model($koneksi,$year,$last,'regular');$found=array_values(array_filter($participants['rows'],fn($x)=>$x['student_id']===$a['id']));
  ma_assert($found[0]['target']===$expected&&$found[0]['current_rombel']===$expected,"Unit $unit participants and export identify Alumni destination");
  ma_assert((int)ma_row($koneksi,'SELECT COUNT(*) n FROM ('.special_class_members_sql($koneksi).') m WHERE class_id=? AND owner_type=\'student\'',[$alumni['id']])['n']>=2,"Unit $unit special-class membership includes native graduates");
  foreach($finance as $table=>$state)ma_assert(ma_finance($koneksi,$table)===$state,"Unit $unit $table counts and nominal totals unchanged by graduation");
  $pastYear=class_ensure_academic_year($koneksi,'2020/2021');$late=$fixture($last,$pastYear);
  $lateResult=promotion_apply_student($koneksi,$late['nis'],0,promotion_context($koneksi,$pastYear),$late['placement'],$last,true);
  ma_assert($lateResult['target']==='Alumni2021',"Unit $unit actual delayed graduation uses Alumni2021");
  ma_assert((int)ma_row($koneksi,'SELECT COUNT(*) n FROM siswa_tahun_ajaran WHERE no_induk=?',[$late['nis']])['n']===1,"Unit $unit delayed graduation has no fabricated following year");
  $future=class_ensure_academic_year($koneksi,$ctx['target_year']);$denied=false;try{promotion_context($koneksi,$future);}catch(RuntimeException $e){$denied=true;}ma_assert($denied,"Unit $unit future source year rejected");
 }
 ma_assert(promotion_alumni_year('2020/2021')===2021,'Late processing follows source academic year');
 ma_assert(str_contains(savings_book_url(['legacy_owner_id'=>12,'student_id'=>null]),'owner_id=12'),'Nonstudent book uses owner ID');
 ma_assert(savings_book_url(['NO_INDUK'=>'00123','student_id'=>19])==='cetak_buku.php?nis=00123&student_id=19','Student book preserves leading zero and internal ID');
}finally{$koneksi->rollback();}
echo "OK: $checks master/graduation checks; all fixtures rolled back.\n";
