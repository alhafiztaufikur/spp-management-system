<?php
/** Two independent connections compete for publication and the same balance. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
if(getenv('SPP_DB_NAME')!=='db_spp_audit_du_advance'||getenv('SPP_DB_PORT')!=='3320')throw new RuntimeException('Use the dedicated disposable advance fixture.');
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/du_master_service.php';
if($koneksi->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Wrong fixture UUID');
$db=$koneksi;$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
$mode=$argv[1]??'';$nis=$argv[2]??'';$year=du_next_year();
if(in_array($mode,['publish','pay','pay_hold','rate'],true)){
 $db->begin_transaction();try{
  if($mode==='publish'||$mode==='rate'){$y=hp_one($db,'SELECT id FROM tahun_ajaran WHERE label=?',[$year]);$r=du_master_save_and_complete($db,(int)$y['id'],$year,[2=>$mode==='rate'?'1.100.000':'1.000.000'],1);echo $mode==='rate'?'rate_saved':'created='.$r['created'];}
  else{
   $raw=hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=?',[$nis,$year]);
   $bill=du_require_selectable_bill($db,(int)$raw['id'],$nis,0,true);du_assert_bill_snapshot($bill,1000000,0);du_assert_payment_amount($bill,600000);
   if($mode==='pay_hold'){echo "locked\n";fflush(STDOUT);usleep(200000);}
   lf_query($db,"INSERT INTO bayar(NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,total_jumlah) VALUES(?,NOW(),'Oktober','2026','1',600000)",[$nis]);$id=(int)$db->insert_id;
   lf_query($db,'INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,2,?,600000)',[$id,$bill['id'],$nis,$year]);echo 'paid=600000';
  }$db->commit();
 }catch(DaftarUlangSelectionException $e){$db->rollback();if($e->reason!=='changed')throw $e;echo 'rejected=changed';}
 catch(mysqli_sql_exception $e){$db->rollback();if(!in_array($e->getCode(),[1213,1205],true))throw $e;echo $mode==='rate'?'rate_retry':'rejected=retry';}exit;
}
function advance_pair(string $mode,string $nis,?string $other=null):array{
 $running=[];foreach([$mode,$other??$mode] as $childMode){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,$childMode,$nis],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);if($childMode==='pay_hold'&&fgets($pipes[1])!=="locked\n")throw new RuntimeException('Payment did not acquire the fixture balance lock');$running[]=[$p,$pipes];}
 $out=[];foreach($running as [$p,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($stderr.$stdout);$out[]=$stdout;}sort($out);return $out;
}
$db->query("DELETE p FROM siswa_tahun_ajaran p JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id WHERE s.NAMA='UJI PERSAINGAN DU' AND NOT EXISTS(SELECT 1 FROM tagihan_daftar_ulang t WHERE t.no_induk=s.NO_INDUK)");
$db->query("DELETE FROM siswa WHERE NAMA='UJI PERSAINGAN DU' AND NOT EXISTS(SELECT 1 FROM tagihan_daftar_ulang t WHERE t.no_induk=siswa.NO_INDUK)");
$nis=(string)random_int(9690000000,9699999999);$db->begin_transaction();
$current=class_ensure_academic_year($db,du_current_academic_year());class_ensure_academic_year($db,$year);
lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES(?,'UJI PERSAINGAN DU','1',1)",[$nis]);
lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,status) VALUES(?,?,1,'aktif')",[$current,$nis]);$db->commit();
$published=advance_pair('publish',$nis);if($published!==['created=0','created=1'])throw new RuntimeException('Concurrent publication duplicated or lost invoice: '.json_encode($published));
$paid=advance_pair('pay',$nis);if($paid!==['paid=600000','rejected=changed'])throw new RuntimeException('Concurrent payment lost balance protection: '.json_encode($paid));
$bill=hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=?',[$nis,$year]);$position=du_find_bill_by_id($db,(int)$bill['id']);
if($position['terbayar']!==600000.0||$position['sisa']!==400000.0)throw new RuntimeException('Final race balance incorrect');
$second=(string)random_int(9690000000,9699999999);$db->begin_transaction();
lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES(?,'UJI PERSAINGAN DU','1',1)",[$second]);
lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,status) VALUES(?,?,1,'aktif')",[$current,$second]);
$future=hp_one($db,'SELECT id FROM tahun_ajaran WHERE label=?',[$year]);du_master_save_and_complete($db,(int)$future['id'],$year,[2=>'1.000.000'],1);$db->commit();
$rateRace=advance_pair('rate',$second,'pay');$b=hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=?',[$second,$year]);$final=du_find_bill_by_id($db,(int)$b['id']);
if(!(($final['terbayar']===600000.0&&$final['nominal_tagihan']===1000000.0)||($final['terbayar']===0.0&&$final['nominal_tagihan']===1100000.0)))throw new RuntimeException('A concurrent rate change overwrote the paid snapshot');
$third=(string)random_int(9690000000,9699999999);$db->begin_transaction();
lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES(?,'UJI PERSAINGAN DU','1',1)",[$third]);
lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,status) VALUES(?,?,1,'aktif')",[$current,$third]);
du_master_save_and_complete($db,(int)$future['id'],$year,[2=>'1.000.000'],1);$db->commit();
$paidFirst=advance_pair('pay_hold',$third,'rate');$b=hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=?',[$third,$year]);$protected=du_find_bill_by_id($db,(int)$b['id']);
if($protected['terbayar']!==600000.0||$protected['nominal_tagihan']!==1000000.0)throw new RuntimeException('Rate change lost the concurrent paid snapshot');
echo json_encode(['checks'=>5,'publication'=>$published,'payment'=>$paid,'remaining'=>$position['sisa'],'rate_vs_payment'=>$rateRace,'rate_first_principal'=>$final['nominal_tagihan'],'rate_first_paid'=>$final['terbayar'],'payment_vs_rate'=>$paidFirst,'protected_paid_principal'=>$protected['nominal_tagihan']]).PHP_EOL;
