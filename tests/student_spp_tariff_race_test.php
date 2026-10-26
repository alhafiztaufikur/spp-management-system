<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest();require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/spp_billing.php';
$root=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$f=json_decode(file_get_contents($root.'/tariff-fixture.json'),true,512,JSON_THROW_ON_ERROR);$u=$f['units'][1];
$_SESSION=['admin_id'=>$f['actor'],'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
if(($argv[1]??'')==='--worker'){
    echo 'READY '.$koneksi->thread_id.PHP_EOL;flush();$koneksi->begin_transaction();
    try{sct_save($koneksi,$u['master'],$u['custom']['id'],'2188-08','650000.00','Concurrent tariff correction',$argv[2]);$koneksi->commit();echo json_encode(['blocked'=>false]);}
    catch(DomainException $e){$koneksi->rollback();echo json_encode(['blocked'=>str_contains($e->getMessage(),'sudah berubah')]);}exit;
}
$context=sct_context_for_student($koneksi,$u['master'],$u['custom']['id']);$nis=$u['custom']['nis'];$koneksi->begin_transaction();
$s=$koneksi->prepare('SELECT id FROM siswa WHERE NO_INDUK=? FOR UPDATE');$s->bind_param('s',$nis);$s->execute();$s->close();
$process=proc_open([PHP_BINARY,__FILE__,'--worker',$context['version']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Cannot start correction worker.');
try{
    $ready=trim(fgets($pipes[1]));if(!preg_match('/^READY ([0-9]+)$/D',$ready,$m))throw new RuntimeException('Worker not ready.');
    $child=(int)$m[1];$waiting=false;$deadline=microtime(true)+6;
    do{$waiting=(int)$koneksi->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$child AND INFO LIKE 'SELECT id FROM siswa WHERE NO_INDUK=%FOR UPDATE'")->fetch_row()[0]>0;if(!$waiting)usleep(50000);}while(!$waiting&&microtime(true)<$deadline);
    if(!$waiting){$status=proc_get_status($process);$detail=$status['running']?json_encode($koneksi->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC)):stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);throw new RuntimeException('Correction did not wait for cashier student lock: '.$detail);}
    $cash=(float)$u['custom']['base']-25000;$level=(string)(preg_replace('/[A-Z]/','',$u['custom']['class']));$actor=(string)$f['actor'];
    $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,BULAN,TAHUN,TGL_BYR,total_jumlah,user_id,payment_link_version) VALUES(?,?,?,'08','2188','2188-08-01 08:00:00',?,?,1)");$s->bind_param('ssdds',$nis,$level,$cash,$cash,$actor);$s->execute();$payment=(int)$koneksi->insert_id;$s->close();
    spp_allocate_payment($koneksi,$nis,$payment,'08','2188',$cash,'2188-08-01 08:00:00','Tunai',$actor);$koneksi->commit();
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($process);$process=null;
    if($exit!==0||!json_decode($output,true)['blocked'])throw new RuntimeException('Stale correction accepted: '.$error.$output);
    $koneksi->begin_transaction();$c=sct_context_for_student($koneksi,$u['master'],$u['custom']['id']);$r=sct_save($koneksi,$u['master'],$u['custom']['id'],'2188-08','650000.00','Fresh correction after cashier',$c['version']);
    $c=sct_context_for_student($koneksi,$u['master'],$u['custom']['id']);
    if($c['periods'][1]['before']!==$cash||$c['periods'][2]['before']!==650000.0||$r['locked']!==2)throw new RuntimeException('Concurrent paid snapshot was repriced.');
    $koneksi->rollback();file_put_contents($root.'/tariff-race-result.json',json_encode(['waiting'=>true,'stale_rejected'=>true,'paid_snapshot_preserved'=>true]));
    echo "PASS independent correction waits for cashier; stale request rejected, retry preserves paid August.\n";
}finally{try{$koneksi->rollback();}catch(Throwable $e){}if(is_resource($process)){proc_terminate($process);foreach($pipes as $p)if(is_resource($p))fclose($p);proc_close($process);}}
