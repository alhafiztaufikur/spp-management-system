<?php
/** Destructive scenarios are restricted to an owned, disposable MySQL instance. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/database_restore.php';
spp_test_assert_database_target($koneksi);
$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');spp_test_assert_http_clone($base,DB_NAME);
$config=db_backup_config();
if(empty($config['allowed_test'])||empty($config['isolated_instance_manifest'])||!db_backup_service_registered($config))throw new RuntimeException('Restore tests require an owned isolated instance, never the application server.');
$fixture=spp_test_isolated_manifest($koneksi);db_backup_assert_application($config,$koneksi);unit_set_context($koneksi,0);
ob_start();
$checks=0;$sessions=[];$queue=db_restore_store();$paths=database_access_paths(DB_NAME,(int)$config['port']);
function restore_assert(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function restore_test_request(array $session,string $action,array $data=[],?string $file=null): array {
    global $base;
    $status=$action==='status';$url=$base.'/restore_api.php'.($status?'?action=status&id='.urlencode($data['id']):'');$curl=curl_init($url);
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>30,CURLOPT_COOKIE=>'PHPSESSID='.$session['id']];
    if($status)$options[CURLOPT_HTTPHEADER]=['X-Restore-Token: '.$data['token']];
    else{$data=['action'=>$action,'csrf_token'=>$session['csrf']]+$data;if($file)$data['sql']=new CURLFile($file,'application/sql','restore.sql');$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$data;}
    curl_setopt_array($curl,$options);$body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
    if($body===false)throw new RuntimeException('Restore HTTP transport: '.$error);
    return [$code,json_decode($body,true)??['body'=>$body]];
}
function restore_test_session(array $account,int $unit=0): array {
    global $sessions,$paths;
    $id='restoreqa'.bin2hex(random_bytes(12));session_id($id);session_start();
    $csrf=bin2hex(random_bytes(32));$_SESSION=['admin_id'=>(int)$account['id'],'admin_role'=>$account['role'],'active_unit_id'=>$unit,'csrf_backup'=>$csrf,'database_generation'=>is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0'];session_write_close();$sessions[]=$id;
    return ['id'=>$id,'csrf'=>$csrf];
}
function restore_test_reconnect(): mysqli {
    global $fixture;
    $db=new mysqli($fixture['host'],$fixture['username'],$fixture['password'],$fixture['database'],(int)$fixture['port']);$db->set_charset('utf8mb4');$db->query('SET @app_unit_id=0');$GLOBALS['koneksi']=$db;return $db;
}
function restore_test_fingerprint(mysqli $db): array {
    $result=[];foreach($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all() as [$name])$result[$name]=$db->query('CHECKSUM TABLE `'.$name.'` EXTENDED')->fetch_assoc()['Checksum'];return $result;
}
function restore_test_wait(array $session,array $cap,array $terminal): array {
    $until=time()+240;$states=[];
    do{
        [$status,$data]=restore_test_request($session,'status',$cap);restore_assert($status===200,'Restore status failed: '.json_encode($data));$job=$data['job'];
        if(!in_array($job['state'],$states,true)){$states[]=$job['state'];fwrite(STDOUT,'STATE '.$job['state'].PHP_EOL);}
        if(in_array($job['state'],$terminal,true))return [$job,$states];usleep(300000);
    }while(time()<$until);throw new RuntimeException('Restore timeout; private diagnostics retained.');
}
function restore_test_upload(array $session,string $file): array {
    $started=microtime(true);[$status,$data]=restore_test_request($session,'upload',[],$file);
    restore_assert($status===202,'Upload refused: '.json_encode($data));restore_assert(microtime(true)-$started<10,'Upload waited for full SQL verification');
    $cap=['id'=>$data['job']['id'],'token'=>$data['token']];[$job]=restore_test_wait($session,$cap,['ready','failed']);restore_assert($job['state']==='ready','Isolated SQL validation failed: '.json_encode($job));return $cap;
}
$originalConfig=file_get_contents($config['_config_file']);$originalDbName=null;
try{
    $account=$koneksi->query("SELECT id,role,nama FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();$session=restore_test_session($account);
    $before=restore_test_fingerprint($koneksi);
    $backup=db_backup_enqueue((int)$account['id'],bin2hex(random_bytes(16)));db_backup_dispatch($backup['id']);
    $until=time()+180;do{$backup=db_backup_find($queue,$backup['id']);if(in_array($backup['state'],['verified','failed'],true))break;usleep(300000);}while(time()<$until);
    restore_assert($backup['state']==='verified','Fixture backup must be verified');$sql=db_backup_archive($queue,$backup['id'])['file'];
    foreach(['admin','kasir','bendahara'] as $role){$other=$koneksi->query("SELECT id,role FROM admin WHERE role='$role' AND is_active=1 LIMIT 1")->fetch_assoc();[$status]=restore_test_request(restore_test_session($other,1),'upload',[],$sql);restore_assert($status===403,'Non-super role can restore');}
    [$status]=restore_test_request(['id'=>$session['id'],'csrf'=>'wrong'],'upload',[],$sql);restore_assert($status===403,'Invalid CSRF accepted');
    $bad=$config['storage_root'].'/invalid-restore.sql';file_put_contents($bad,"DROP DATABASE mysql;\n");[$status]=restore_test_request($session,'upload',[],$bad);restore_assert($status===400,'Untrusted SQL accepted');
    $cap=restore_test_upload($session,$sql);restore_assert(restore_test_fingerprint($koneksi)===$before,'Validation changed target database');
    restore_assert(!is_file(db_backup_job_dir($cap['id']).'/target.cnf'),'Validation retained privileged connection credentials');
    [$status]=restore_test_request($session,'apply',$cap+['confirmation'=>'wrong','understood'=>'1']);restore_assert($status===400,'Incorrect confirmation accepted');
    [$status]=restore_test_request($session,'apply',$cap+['confirmation'=>'PULIHKAN DATABASE']);restore_assert($status===400,'Missing acknowledgment accepted');
    [$status]=restore_test_request($session,'status',['id'=>$cap['id'],'token'=>str_repeat('0',64)]);restore_assert($status===400,'Foreign capability accepted');
    // New data created after the chosen snapshot must appear only in the safety backup.
    $changed='RESTORE QA '.bin2hex(random_bytes(4));$stmt=$koneksi->prepare('UPDATE admin SET nama=? WHERE id=?');$stmt->bind_param('si',$changed,$account['id']);$stmt->execute();$stmt->close();$changedFingerprint=restore_test_fingerprint($koneksi);
    $gate=database_access_lock($paths,LOCK_SH|LOCK_NB);restore_assert(is_resource($gate),'Could not hold an in-flight request');
    [$status,$data]=restore_test_request($session,'apply',$cap+['confirmation'=>'PULIHKAN DATABASE','understood'=>'1']);restore_assert($status===202,'Confirmed restore refused: '.json_encode($data));
    $until=time()+20;do{clearstatcache();if(is_file($paths['maintenance']))break;usleep(200000);}while(time()<$until);
    restore_assert(is_file($paths['maintenance']),'Restore did not enter maintenance');restore_assert(restore_test_fingerprint($koneksi)===$changedFingerprint,'Restore bypassed active request lock');
    $response=file_get_contents($base.'/backup_restore.php',false,stream_context_create(['http'=>['header'=>'Cookie: PHPSESSID='.$session['id'],'ignore_errors'=>true,'follow_location'=>0]]));
    restore_assert(str_contains($http_response_header[0],' 503 '),'Application remained writable during restore');
    [$status]=restore_test_request($session,'status',$cap);restore_assert($status===200,'Progress unavailable during maintenance');
    database_access_release($gate);$koneksi->close();unset($GLOBALS['koneksi']);
    [$job,$states]=restore_test_wait($session,$cap,['completed','rolled_back','failed','recovery_required']);restore_assert($job['state']==='completed','Restore did not complete: '.json_encode($job));
    $koneksi=restore_test_reconnect();restore_assert(restore_test_fingerprint($koneksi)===$before,'Restored physical rows do not match original snapshot');
    clearstatcache();restore_assert(!is_file($paths['maintenance'])&&is_file($paths['generation']),'Maintenance/session generation not finalized');
    restore_assert(!is_file(db_backup_job_dir($cap['id']).'/target.cnf'),'Restore credentials retained after completion');
    $safety=db_backup_find($queue,$job['safety_id']);restore_assert($safety['type']==='manual'&&$safety['state']==='verified','Safety archive not retained as verified Manual');
    $safetyReport=json_decode(file_get_contents(db_backup_job_dir($job['safety_id']).'/verification.json'),true);restore_assert($safetyReport['fingerprints']!==json_decode(file_get_contents(db_backup_job_dir($backup['id']).'/verification.json'),true)['fingerprints'],'Safety snapshot did not include newer rows');
    $old=file_get_contents($base.'/backup_restore.php',false,stream_context_create(['http'=>['header'=>'Cookie: PHPSESSID='.$session['id'],'ignore_errors'=>true,'follow_location'=>0]]));restore_assert(str_contains($http_response_header[0],' 302 '),'Pre-restore session remained valid');
    echo "PASS: validated restore, request drain, maintenance, safety archive, exact rows and session invalidation\n";
    foreach(['restore_safety','restore_import','restore_audit'] as $failure){
        $session=restore_test_session($account);$cap=restore_test_upload($session,$sql);
        $changed='RECOVERY QA '.$failure;$stmt=$koneksi->prepare('UPDATE admin SET nama=? WHERE id=?');$stmt->bind_param('si',$changed,$account['id']);$stmt->execute();$stmt->close();$expected=restore_test_fingerprint($koneksi);
        $modified=json_decode($originalConfig,true);$modified['test_restore_fail']=$failure;db_backup_atomic_json($config['_config_file'],$modified);
        [$status]=restore_test_request($session,'apply',$cap+['confirmation'=>'PULIHKAN DATABASE','understood'=>'1']);restore_assert($status===202,'Failure scenario not queued');$koneksi->close();unset($GLOBALS['koneksi']);
        [$job]=restore_test_wait($session,$cap,['completed','rolled_back','failed','recovery_required']);
        restore_assert($job['state']===($failure==='restore_safety'?'failed':'rolled_back'),'Failure did not preserve/recover target: '.json_encode($job));
        $koneksi=restore_test_reconnect();restore_assert(restore_test_fingerprint($koneksi)===$expected,'Safety/rollback lost post-backup data');clearstatcache();restore_assert(!is_file($paths['maintenance']),'Successful recovery left maintenance locked');
        file_put_contents($config['_config_file'],$originalConfig,LOCK_EX);
        echo 'PASS: '.$failure.' preserves current target'.PHP_EOL;
    }
    // Reproduce the durable journal left by an interrupted destructive stage. The selected
    // SQL is then deliberately corrupt: recovery must use the verified safety archive only.
    $session=restore_test_session($account);$cap=restore_test_upload($session,$sql);
    $expected=restore_test_fingerprint($koneksi);
    $safety=db_backup_enqueue((int)$account['id'],bin2hex(random_bytes(16)));db_backup_dispatch($safety['id']);
    $until=time()+180;do{$safety=db_backup_find($queue,$safety['id']);if(in_array($safety['state'],['verified','failed'],true))break;usleep(300000);}while(time()<$until);
    restore_assert($safety['state']==='verified','Interrupted restore needs a verified safety archive');
    $restoreDir=db_backup_job_dir($cap['id']);db_restore_credentials($config,$restoreDir);
    $gate=database_access_lock($paths,LOCK_EX|LOCK_NB);restore_assert(is_resource($gate),'Could not reproduce interrupted journal exclusively');
    $partial='PARTIAL RESTORE QA';$stmt=$koneksi->prepare('UPDATE admin SET nama=? WHERE id=?');$stmt->bind_param('si',$partial,$account['id']);$stmt->execute();$stmt->close();
    db_restore_update($queue,$cap['id'],'restoring','Fixture: journal from an interrupted restore.',$safety['id']);
    db_backup_atomic_json($paths['maintenance'],['id'=>$cap['id'],'server_uuid'=>$config['server_uuid'],'destructive'=>true,'safety_id'=>$safety['id']]);
    file_put_contents($restoreDir.'/dump.sql',"DROP DATABASE mysql;\n");
    db_backup_atomic_json($restoreDir.'/target-auth.json',['user'=>'restore_qa_nonexistent','password'=>'invalid']);
    database_access_release($gate);$koneksi->close();unset($GLOBALS['koneksi']);
    [$job]=restore_test_wait($session,$cap,['recovery_required','rolled_back','failed']);restore_assert($job['state']==='recovery_required','Failed safety recovery must remain closed');
    clearstatcache();restore_assert(is_file($paths['maintenance']),'Interrupted recovery reopened application prematurely');
    [$status]=restore_test_request(['id'=>$session['id'],'csrf'=>'invalid'],'resume',$cap);restore_assert($status===403,'Recovery bypasses CSRF');
    db_restore_credentials($config,$restoreDir);
    [$status]=restore_test_request($session,'resume',$cap);restore_assert($status===202,'Safety recovery could not be resumed');
    [$job]=restore_test_wait($session,$cap,['rolled_back','recovery_required','failed']);restore_assert($job['state']==='rolled_back','Interrupted journal recovery did not finish');
    $koneksi=restore_test_reconnect();restore_assert(restore_test_fingerprint($koneksi)===$expected,'Interrupted restore failed to recover exact safety rows');
    clearstatcache();restore_assert(!is_file($paths['maintenance'])&&!is_file($restoreDir.'/target-auth.json'),'Recovery did not finalize maintenance/credentials');
    echo "PASS: interrupted journal, failed recovery stays closed, CSRF and explicit retry restore safety rows\n";
    // Leave the disposable fixture data as it was at test entry.
    $stmt=$koneksi->prepare('UPDATE admin SET nama=? WHERE id=?');$stmt->bind_param('si',$account['nama'],$account['id']);$stmt->execute();$stmt->close();
    restore_assert(restore_test_fingerprint($koneksi)===$before,'Fixture final data differs');
    echo 'PASS: '.$checks.' restore HTTP checks; operational server never targeted'.PHP_EOL;
}finally{
    file_put_contents($config['_config_file'],$originalConfig,LOCK_EX);
    foreach($sessions as $sid){session_id($sid);session_start();session_unset();session_destroy();}
}
