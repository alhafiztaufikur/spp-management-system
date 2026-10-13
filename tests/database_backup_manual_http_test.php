<?php
/** Real asynchronous manual backups: isolated source/private queue, no Windows task registration. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/database_backup.php';
spp_test_assert_database_target($koneksi);
$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');spp_test_assert_http_clone($base,DB_NAME);
$config=db_backup_config();
if(empty($config['allowed_test'])||$config['database']!==DB_NAME||getenv('SPP_BACKUP_TEST_DISPATCH'))throw new RuntimeException('Real manual tests require an isolated configuration with dispatch enabled.');
db_backup_assert_application($config,$koneksi);unit_set_context($koneksi,0);
$checks=0;$sessions=[];
function manual_assert(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function manual_request(string $session,string $path,string $method='GET',?array $data=null): array {
    global $base;
    $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>['method'=>$method,'header'=>"Cookie: PHPSESSID=$session\r\nContent-Type: application/x-www-form-urlencoded",'content'=>$data===null?'':http_build_query($data),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30]]));
    preg_match('/^HTTP\/\S+\s+(\d+)/',$http_response_header[0]??'',$match);return [(int)($match[1]??0),(string)$body,$http_response_header];
}
function manual_session(array $account,int $unit): array {
    global $sessions;
    $id='manualbackup'.bin2hex(random_bytes(12));session_id($id);session_start();
    $_SESSION=['admin_id'=>(int)$account['id'],'admin_role'=>$account['role'],'active_unit_id'=>$unit,'csrf_backup'=>bin2hex(random_bytes(32))];
    $paths=database_access_paths(DB_NAME,(int)(getenv('SPP_DB_PORT')?:3306));
    $_SESSION['database_generation']=is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0';
    $csrf=$_SESSION['csrf_backup'];session_write_close();$sessions[]=$id;return [$id,$csrf];
}
function manual_wait(string $session,string $id): array {
    $until=microtime(true)+180;$states=[];
    do{
        [$status,$body]=manual_request($session,'backup_api.php?action=status');manual_assert($status===200,'Polling failed');
        $summary=json_decode($body,true,512,JSON_THROW_ON_ERROR);$job=null;
        foreach($summary['jobs'] as $row)if($row['id']===$id){$job=$row;break;}
        if($job&&!in_array($job['state'],$states,true))$states[]=$job['state'];
        if($job&&in_array($job['state'],['verified','failed'],true))return [$job,$states,$summary];
        usleep(300000);
    }while(microtime(true)<$until);
    throw new RuntimeException('Manual backup timed out; private diagnostics retained.');
}
function manual_fingerprint(mysqli $db): array {
    $out=[];foreach($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all() as [$table])$out[$table]=$db->query('CHECKSUM TABLE `'.$table.'` EXTENDED')->fetch_assoc()['Checksum'];return $out;
}
ob_start();
$before=manual_fingerprint($koneksi);$queue=db_backup_store(true);$settings=db_backup_settings($queue);
$account=$koneksi->query("SELECT id,role FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
[$session,$csrf]=manual_session($account,0);
try{
    [$status,$body]=manual_request($session,'backup_api.php?action=status');$initial=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    manual_assert($status===200&&!$initial['ready']&&$initial['manual_available']&&$initial['can_create'],'Manual availability must not depend on the daily heartbeat');
    $count=(int)$queue->query('SELECT COUNT(*) FROM jobs')->fetchColumn();$slots=(int)$queue->query('SELECT COUNT(*) FROM schedule_slots')->fetchColumn();
    // An unrelated CLI verification may own the global lock. HTTP must still return promptly;
    // the hidden worker waits in the background and acquires the same lock afterwards.
    $runnerLock=fopen($config['storage_root'].'/runner.lock','c');manual_assert(flock($runnerLock,LOCK_EX|LOCK_NB),'Could not own fixture runner lock');
    $key=bin2hex(random_bytes(16));$started=microtime(true);
    try{
        [$status,$body]=manual_request($session,'backup_api.php','POST',['action'=>'create','csrf_token'=>$csrf,'request_key'=>$key]);$created=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        manual_assert($status===202&&microtime(true)-$started<8,'HTTP waited for full backup instead of dispatching');
        $id=$created['job']['id'];$receipt=file_get_contents(db_backup_job_dir($id).'/dispatch.json');
        [$again,$body]=manual_request($session,'backup_api.php','POST',['action'=>'create','csrf_token'=>$csrf,'request_key'=>$key]);
        manual_assert($again===202&&json_decode($body,true)['job']['id']===$id,'Repeated request duplicated the job');
        manual_assert(file_get_contents(db_backup_job_dir($id).'/dispatch.json')===$receipt,'Repeated request launched another worker');
        [$status,$body]=manual_request($session,'backup_api.php?action=status');$busy=json_decode($body,true);
        manual_assert(!$busy['can_create']&&$busy['active']['id']===$id,'Waiting background worker must disable duplicate clicks');
    }finally{flock($runnerLock,LOCK_UN);fclose($runnerLock);}
    [$job,$states,$summary]=manual_wait($session,$id);
    manual_assert($job['state']==='verified'&&$job['type']==='manual'&&$job['scheduled_for']===null,'Manual job was not verified');
    manual_assert(!$summary['ready']&&$summary['can_create'],'Manual success must reenable the button without faking daily heartbeat');
    manual_assert((int)$queue->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$count+1,'Manual dispatch also created a scheduled backup');
    manual_assert((int)$queue->query('SELECT COUNT(*) FROM schedule_slots')->fetchColumn()===$slots,'Manual dispatch changed daily slots');
    [$status,$sql,$headers]=manual_request($session,$job['download_url']);
    manual_assert($status===200&&hash('sha256',$sql)===hash_file('sha256',db_backup_archive($queue,$id)['file'])&&strlen($sql)===$job['bytes'],'Downloaded SQL differs');unset($sql);
    [$status,$body]=manual_request($session,'backup_api.php','POST',['action'=>'create','csrf_token'=>$csrf,'request_key'=>$key]);
    manual_assert($status===202&&json_decode($body,true)['job']['id']===$id,'Delivered-key replay created another backup');
    echo 'PASS: asynchronous manual backup without a scheduler, cross-process locking, duplicate prevention, SQL verification/download and unchanged daily slots; stages '.implode(' -> ',$states)."\n";

    // A missing configured CLI executable is a clear failure; once repaired, the same queued
    // request can be picked up and completed rather than being disabled forever.
    $configPath=$config['_config_file'];$original=file_get_contents($configPath);$bad=json_decode($original,true);$bad['php_executable']=$config['storage_root'].'/missing-php.exe';
    db_backup_atomic_json($configPath,$bad);
    try{
        [$status,$body]=manual_request($session,'backup_api.php','POST',['action'=>'create','csrf_token'=>$csrf,'request_key'=>bin2hex(random_bytes(16))]);$failed=json_decode($body,true);
        manual_assert($status===503&&isset($failed['request_key'])&&str_contains($failed['error'],'manual'),'Missing CLI launch did not return a recoverable error');
    }finally{file_put_contents($configPath,$original,LOCK_EX);}
    [$status,$body]=manual_request($session,'backup_api.php?action=status');manual_assert($status===200&&json_decode($body,true)['can_create'],'A repaired stale queue must not leave the button disabled');
    [$status,$body]=manual_request($session,'backup_api.php','POST',['action'=>'create','csrf_token'=>$csrf,'request_key'=>$failed['request_key']]);$retried=json_decode($body,true);
    manual_assert($status===202,'Repaired CLI did not resume queued request');
    [$job]=manual_wait($session,$retried['job']['id']);manual_assert($job['state']==='verified','Repaired dispatcher did not complete backup');
    echo "PASS: launch configuration failure and successful retry of the accepted queue\n";

    [$status,,$headers]=manual_request($session,'backup_api.php','POST',['action'=>'create','csrf_token'=>$csrf,'request_key'=>bin2hex(random_bytes(16)),'return'=>'page']);
    manual_assert($status===303&&in_array('Location: backup_restore.php',$headers,true),'Native manual form lost PRG');
    $native=$queue->query("SELECT * FROM jobs ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);[$job]=manual_wait($session,$native['id']);manual_assert($job['state']==='verified','Native form backup not verified');
    foreach(['admin','kasir','bendahara'] as $role){
        $s=$koneksi->prepare('SELECT id,role FROM admin WHERE role=? AND is_active=1 LIMIT 1');$s->bind_param('s',$role);$s->execute();$other=$s->get_result()->fetch_assoc();
        [$otherSession,$otherCsrf]=manual_session($other,1);
        [$status]=manual_request($otherSession,'backup_api.php','POST',['action'=>'create','csrf_token'=>$otherCsrf,'request_key'=>bin2hex(random_bytes(16))]);manual_assert(in_array($status,[302,401,403],true),'Unauthorized role dispatched backup');
    }
    manual_assert(db_backup_settings($queue)===$settings,'Manual backup changed saved time or retention');
    manual_assert(manual_fingerprint($koneksi)===$before,'Manual backup changed source data');
    echo "PASS: native form, role access, settings preserved, source checksums unchanged; $checks checks\n";
}finally{
    foreach($sessions as $session){session_id($session);session_start();$_SESSION=[];session_destroy();session_write_close();}
}
ob_end_flush();
