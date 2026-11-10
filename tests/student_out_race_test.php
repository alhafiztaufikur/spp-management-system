<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/student_out.php';require_once __DIR__.'/../includes/legacy_finance.php';
$db=$koneksi;if((int)$db->query('SELECT @@port')->fetch_row()[0]===3306||!sout_ready($db))throw new RuntimeException('Use an upgraded disposable server.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
if(isset($argv[1])){
    [$file,$mode,$nis,$payment]=$argv;$db->begin_transaction();
    try{
        if(str_starts_with($mode,'out')){
            $s=sout_student($db,$nis,true);$class=sout_rows($db,"SELECT id FROM master_kelas WHERE jenis='out' LIMIT 1")[0];
            sout_write($db,"UPDATE siswa SET KELAS='OUT',master_kelas_id=?,is_active=0 WHERE id=?",[$class['id'],$s['id']]);sout_transition($db,(int)$s['id'],$s,'out',1);
        }else sout_write($db,"UPDATE bayar SET KETERANGAN='accepted before OUT' WHERE id=?",[$payment]);
        if(str_ends_with($mode,'hold')){echo "locked\n";fflush(STDOUT);usleep(500000);}
        $db->commit();echo str_starts_with($mode,'out')?'out_committed':'payment_committed';
    }catch(Throwable $e){$db->rollback();if(!str_contains($e->getMessage(),'OUT'))throw $e;echo 'payment_rejected_out';}exit;
}
function sout_race_pair(string $first,string $second,string $nis,int $payment): array {
    $jobs=[];foreach([$first,$second] as $index=>$mode){
        $p=proc_open([PHP_BINARY,__FILE__,$mode,$nis,(string)$payment],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
        if($index===0&&fgets($pipes[1])!=="locked\n")throw new RuntimeException('First writer did not acquire its lock.');$jobs[]=[$p,$pipes];
    }
    $out=[];foreach($jobs as [$p,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p))throw new RuntimeException($stderr.$stdout);$out[]=$stdout;}return $out;
}
$cases=[];
foreach([['payment_hold','out'],['out_hold','payment']] as $index=>[$first,$second]){
    $nis=(string)random_int(9890000000,9899999999);$class=sout_rows($db,"SELECT id FROM master_kelas WHERE tingkat=2 AND jenis='reguler' AND is_placeholder=0 LIMIT 1")[0];
    sout_write($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,'QA OUT race','2',?,1)",[$nis,$class['id']]);
    sout_write($db,"INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,total_jumlah,BULAN,TAHUN,TGL_BYR,user_id,payment_link_version) VALUES(?,'2',100,100,'11','2026','2026-11-01 10:00:00',1,1)",[$nis]);$id=(int)$db->insert_id;
    $before=sout_rows($db,'SELECT * FROM bayar WHERE id=?',[$id])[0];$result=sout_race_pair($first,$second,$nis,$id);
    $expected=$index===0?['payment_committed','out_committed']:['out_committed','payment_rejected_out'];if($result!==$expected)throw new RuntimeException('Writer order changed: '.json_encode($result));
    $after=sout_rows($db,'SELECT * FROM bayar WHERE id=?',[$id])[0];if($index===0){$before['KETERANGAN']='accepted before OUT';$before['updated_at']=$after['updated_at'];}if($before!==$after)throw new RuntimeException('Financial columns changed outside accepted correction.');
    $cases[]=$result;
}
echo json_encode(['passed'=>true,'checks'=>4,'orders'=>$cases],JSON_PRETTY_PRINT),PHP_EOL;
