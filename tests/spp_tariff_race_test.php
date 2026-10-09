<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/kelas.php';
unit_set_context($koneksi,0);$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
if(($argv[1]??'')==='--worker'){
    $koneksi->begin_transaction();echo 'READY '.$koneksi->thread_id.PHP_EOL;flush();
    try{$result=spp_master_correct_published_rates($koneksi,(int)$argv[2],array_fill(1,6,300000.0),$argv[3],true);$koneksi->commit();echo json_encode($result).PHP_EOL;}
    catch(Throwable $e){$koneksi->rollback();fwrite(STDERR,$e->getMessage());exit(1);}exit;
}
function tariff_race_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$koneksi->begin_transaction();
try{
    $start=2178;while($start<2190){$year=$start.'/'.($start+1);$s=$koneksi->prepare('SELECT id FROM tahun_ajaran WHERE label=?');$s->bind_param('s',$year);$s->execute();$exists=$s->get_result()->num_rows;$s->close();if(!$exists)break;$start++;}tariff_race_assert($start<2190,'Fresh fixture year unavailable');
    $master=spp_master_ensure_year($koneksi,$year,true);$id=(int)$master['id'];$yearId=(int)$master['tahun_ajaran_id'];spp_master_save_rates($koneksi,$id,array_fill(1,6,250000.0));
    $class=$koneksi->query('SELECT id,tingkat,kode_rombel FROM master_kelas WHERE tingkat=1 AND is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1')->fetch_assoc();$classId=(int)$class['id'];$label=class_label($class);$nis=(string)random_int(9700000000,9799999999);$name='TEST RATE RACE';
    $s=$koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,is_active) VALUES(?,?,'1',?,250000,1)");$s->bind_param('ssi',$nis,$name,$classId);$s->execute();$studentId=(int)$koneksi->insert_id;$s->close();
    $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,status) VALUES(?,?,'1',?,?,250000,'aktif')");$s->bind_param('isis',$yearId,$nis,$classId,$label);$s->execute();$s->close();spp_publish_students($koneksi,$id,[$nis]);$koneksi->commit();
}catch(Throwable $e){$koneksi->rollback();throw $e;}
$version=spp_master_rate_version(spp_master_state($koneksi,$id),spp_master_rates($koneksi,$id));
$koneksi->begin_transaction();$s=$koneksi->prepare('SELECT id FROM siswa WHERE NO_INDUK=? FOR UPDATE');$s->bind_param('s',$nis);$s->execute();$s->close();
$process=proc_open([PHP_BINARY,__FILE__,'--worker',(string)$id,$version],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
tariff_race_assert(is_resource($process),'Cannot start independent correction connection');
try{
    $ready=trim(fgets($pipes[1]));tariff_race_assert(preg_match('/^READY ([0-9]+)$/D',$ready,$m)===1,'Worker did not initialize');$child=(int)$m[1];$parent=$koneksi->thread_id;$waiting=false;$deadline=microtime(true)+5;
    do{$waiting=(int)$koneksi->query("SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID WHERE r.PROCESSLIST_ID=$child AND b.PROCESSLIST_ID=$parent")->fetch_row()[0]>0;if(!$waiting)usleep(50000);}while(!$waiting&&microtime(true)<$deadline);
    tariff_race_assert($waiting,'Correction did not wait for the cashier student lock');
    $calendarYear=(string)$start;$date=$calendarYear.'-07-01 00:00:00';
    $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,BULAN,TAHUN,TGL_BYR,total_jumlah,payment_link_version) VALUES(?,'1',250000,'07',?,?,250000,1)");$s->bind_param('sss',$nis,$calendarYear,$date);$s->execute();$paymentId=(int)$koneksi->insert_id;$s->close();
    spp_allocate_payment($koneksi,$nis,$paymentId,'07',$calendarYear,250000,$date,'Tunai',(string)$actor);$koneksi->commit();
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$process=null;
    tariff_race_assert($exit===0,'Correction failed: '.$error);$result=json_decode(trim($output),true,512,JSON_THROW_ON_ERROR);tariff_race_assert($result['bills_updated']===11&&$result['bills_locked']===1,'Concurrent payment was repriced');
    $s=$koneksi->prepare('SELECT bulan,nominal_tagihan FROM tagihan_spp WHERE no_induk=? ORDER BY id');$s->bind_param('s',$nis);$s->execute();$bills=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    foreach($bills as $bill)tariff_race_assert((float)$bill['nominal_tagihan']===($bill['bulan']==='07'?250000.0:300000.0),'Payment/correction snapshot mismatch');
    echo "PASS: independent correction waits for concurrent cashier; paid July stays 250000, 11 unpaid months become 300000\n";
}finally{try{$koneksi->rollback();}catch(Throwable $e){}if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}}
