<?php
/** Only confirmed jobs can replace the identity-checked application database. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once __DIR__.'/../includes/database_restore.php';
require_once __DIR__.'/database_backup_worker.php';
foreach($argv as $arg)if(str_starts_with($arg,'--config='))putenv('SPP_BACKUP_CONFIG='.substr($arg,9));
function restore_compare(array $actual,array $expected): void {
    foreach(['sql_sha256','schema_signature','table_counts','fingerprints','fingerprint_algorithms','units','totals'] as $key){
        // Target snapshots have a new dump timestamp but must retain every row and object.
        if($key==='sql_sha256')continue;
        if(!array_key_exists($key,$actual)||$actual[$key]!==($expected[$key]??null))throw new RuntimeException('Restore evidence differs: '.$key);
    }
}
function restore_test_failure(array $c,string $stage): void {
    db_backup_test_failure($c,$stage);
    if(($c['test_restore_fail']??'')===$stage){
        if(empty($c['allowed_test'])||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!db_backup_service_registered($c))throw new RuntimeException('Restore test hook refused.');
        throw new RuntimeException('Injected isolated restore failure: '.$stage);
    }
}
function restore_finalize(array $c,array $row,string $dir): void {
    $paths=database_access_paths($c['database'],(int)$c['port']);
    if(is_file($paths['maintenance'])){
        $owner=json_decode(file_get_contents($paths['maintenance']),true,32,JSON_THROW_ON_ERROR);
        if(($owner['id']??'')!==$row['id']||($owner['server_uuid']??'')!==$c['server_uuid'])throw new RuntimeException('Maintenance ownership changed; retained.');
        if(!empty($owner['destructive'])){
            $epoch=bin2hex(random_bytes(16));
            if(file_put_contents($paths['generation'],$epoch,LOCK_EX)!==strlen($epoch))throw new RuntimeException('Session invalidation unavailable; maintenance retained.');
        }
        if(!unlink($paths['maintenance']))throw new RuntimeException('Maintenance finalization unavailable.');
    }
    foreach(['target.cnf','target-auth.json'] as $name){$secret=$dir.'/'.$name;if(is_file($secret)){db_backup_private_path($secret);unlink($secret);}}
}
function restore_verify(array $c,string $dir,string $log='restore-verify'): array {
    db_backup_process(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/verify_backup_instance.ps1','-ConfigFile',$c['_config_file'],'-JobDirectory',$dir,'-DumpFile',$dir.'/dump.sql'],$dir.'/'.$log,1800);
    $report=json_decode(file_get_contents($dir.'/verification.json'),true,64,JSON_THROW_ON_ERROR);
    if(($report['state']??'')!=='verified'||!hash_equals($report['sql_sha256'],hash_file('sha256',$dir.'/dump.sql')))throw new RuntimeException('Restore verification incomplete.');
    return $report;
}
function restore_audit_target(array $c,string $parent,array $expected): void {
    $dir=db_backup_job_dir(bin2hex(random_bytes(16)));if(!mkdir($dir,0700))throw new RuntimeException('Restore audit storage unavailable.');db_backup_private_path($dir);
    db_backup_atomic_json($parent.'/target-audit.json',['directory'=>$dir]);
    $source=db_backup_source($c);db_backup_atomic_json($dir.'/schema.json',db_backup_schema($source));$source->close();
    db_backup_process([$c['mysql_bin'].'/mysqldump.exe','--defaults-file='.$c['source_defaults_file'],'--host='.$c['host'],'--port='.$c['port'],'--protocol=TCP','--single-transaction','--quick','--skip-lock-tables','--routines','--triggers','--events','--hex-blob','--no-tablespaces','--set-gtid-purged=OFF','--column-statistics=0','--default-character-set=utf8mb4','--result-file='.$dir.'/dump.sql','--databases',$c['database']],$dir.'/target-dump',1800);
    $report=restore_verify($c,$dir);restore_compare($report,$expected);
    db_backup_atomic_json($parent.'/target-verified.json',$report);
}
function restore_no_active_import(mysqli $target,array $c,string $dir): void {
    // A surviving mysql client from an interrupted worker must finish before recovery.
    $log=$dir.'/target-import.out.log';$connection=is_file($log)?trim(strtok(file_get_contents($log),"\r\n")):'';
    $until=time()+60;
    do{
        $busy=false;
        foreach($target->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $process){
            if((int)$process['Id']===$target->thread_id)continue;
            if(($connection!==''&&(string)$process['Id']===$connection)||($process['db']===$c['database']&&$process['Command']!=='Sleep')){$busy=true;break;}
        }
        if(!$busy)return;usleep(300000);
    }while(time()<$until);
    throw new RuntimeException('A target client is still running; recovery remains paused.');
}
function restore_import(array $c,string $dir,string $sql,string $hash,bool $rollback=false): void {
    db_backup_private_path($sql);if(!hash_equals($hash,hash_file('sha256',$sql)))throw new RuntimeException('Restore SQL changed.');
    $target=db_restore_target($c,$dir);restore_no_active_import($target,$c,$dir);$target->close();
    $input=$dir.'/execute.sql';$out=fopen($input,'wb');$in=fopen($sql,'rb');if(!$out||!$in)throw new RuntimeException('Restore input unavailable.');
    try{
        $prefix='SELECT CONNECTION_ID();' . "\n".'DROP DATABASE `'.$c['database'].'`;' . "\n";
        if(fwrite($out,$prefix)!==strlen($prefix)||stream_copy_to_stream($in,$out)!==filesize($sql))throw new RuntimeException('Restore input publication incomplete.');
    }finally{fclose($in);fclose($out);}
    db_backup_process([$c['mysql_bin'].'/mysql.exe','--defaults-file='.$dir.'/target.cnf','--host='.$c['host'],'--port='.$c['port'],'--protocol=TCP','--binary-mode','--local-infile=0','--batch','--skip-column-names','--unbuffered','--default-character-set=utf8mb4','--max-allowed-packet=1G'],$dir.'/target-import',1800,$input);
    if(!$rollback)restore_test_failure($c,'restore_import');
}
$db=null;$row=null;$dir=null;$paths=null;$access=null;$destructive=false;$ownsMaintenance=false;
try{
    $c=db_backup_config();
    if(!empty($c['test_restore_fail'])&&empty($c['allowed_test']))throw new RuntimeException('Test configuration refused on production.');
    if(!empty($c['allowed_test'])&&(getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!db_backup_service_registered($c)))throw new RuntimeException('Restore tests require an independently identified fixture.');
    $id='';foreach($argv as $arg)if(str_starts_with($arg,'--job='))$id=substr($arg,6);
    $db=db_restore_store();$row=db_restore_find($db,$id);$dir=db_backup_private_path(db_backup_job_dir($id));
    $workerPath=$dir.'/restore-worker.lock';if(file_exists($workerPath))db_backup_private_path($workerPath);
    $worker=fopen($workerPath,'c');if(!$worker||!flock($worker,LOCK_EX|LOCK_NB))exit;
    if(!in_array($row['state'],['validating','queued','safety_backup','restoring','auditing','rolling_back','recovery_required'],true)){
        if(db_restore_needs_finalization($c,$row)){
            $finishPaths=database_access_paths($c['database'],(int)$c['port']);$finishLock=database_access_lock($finishPaths,LOCK_EX|LOCK_NB);
            if($finishLock){try{restore_finalize($c,$row,$dir);}finally{database_access_release($finishLock);}}
        }
        exit;
    }
    $runnerPath=$c['storage_root'].'/runner.lock';if(file_exists($runnerPath))db_backup_private_path($runnerPath);
    $runner=fopen($runnerPath,'c');if(!$runner||!flock($runner,LOCK_EX))throw new RuntimeException('Restore runner lock unavailable.');
    $paths=database_access_paths($c['database'],(int)$c['port']);
    if(is_file($paths['maintenance'])){
        $maintenance=json_decode(file_get_contents($paths['maintenance']),true,32,JSON_THROW_ON_ERROR);
        if(($maintenance['id']??'')!==$id||($maintenance['server_uuid']??'')!==$c['server_uuid'])throw new RuntimeException('Another maintenance owns the database.');
        $ownsMaintenance=true;$destructive=!empty($maintenance['destructive']);
    }
    if($destructive)throw new RuntimeException('An interrupted restore requires recovery from the safety backup.');
    foreach(['dump.sql','schema.json','expected.json'] as $file)db_backup_private_path($dir.'/'.$file);
    if(!hash_equals($row['sql_sha256'],hash_file('sha256',$dir.'/dump.sql')))throw new RuntimeException('Uploaded SQL changed.');
    $expected=json_decode(file_get_contents($dir.'/expected.json'),true,64,JSON_THROW_ON_ERROR);
    if(!hash_equals($row['sql_sha256'],$expected['sql_sha256']))throw new RuntimeException('Backup evidence checksum differs.');
    if($row['state']==='validating'){
        $target=db_backup_source($c);
        $GLOBALS['koneksi']=$target;db_backup_actor((int)$row['actor']);unset($GLOBALS['koneksi']);
        $schema=db_backup_schema($target);
        if($schema['signature']!==$expected['schema_signature'])throw new RuntimeException('Backup schema is not compatible with this application.');
        if(!db_backup_disk_budget($schema['estimated_bytes'],(int)disk_free_space($c['storage_root'])))throw new RuntimeException('Insufficient restore verification space.');$target->close();
        $report=restore_verify($c,$dir);restore_compare($report,$expected);
        if(($report['active_super_admins']??0)<1)throw new RuntimeException('Backup does not retain an active Super Admin.');
        db_restore_update($db,$id,'ready','SQL terverifikasi. Konfirmasi untuk membuat backup pengaman dan memulihkan seluruh database.');exit;
    }
    if($row['state']==='queued'&&(int)$row['expires_at']<time())throw new RuntimeException('Restore confirmation expired.');
    if(is_file(__DIR__.'/../tmp/financial_migration.lock'))throw new RuntimeException('Financial maintenance is active.');
    if(!$ownsMaintenance){
        $handle=fopen($paths['maintenance'],'x');if(!$handle)throw new RuntimeException('Maintenance already exists.');
        fwrite($handle,json_encode(['id'=>$id,'server_uuid'=>$c['server_uuid'],'destructive'=>false],JSON_THROW_ON_ERROR));fclose($handle);$ownsMaintenance=true;
    }
    $until=time()+60;
    do{$access=database_access_lock($paths,LOCK_EX|LOCK_NB);if($access)break;usleep(200000);}while(time()<$until);
    if(!$access)throw new RuntimeException('Active requests did not finish before restore.');
    if($destructive)throw new RuntimeException('An interrupted restore requires recovery from the safety backup.');
    $target=db_restore_target($c,$dir);db_restore_assert_privileges($target,$c['database']);
    $GLOBALS['koneksi']=$target;db_backup_actor((int)$row['actor']);unset($GLOBALS['koneksi']);
    if(db_backup_schema($target)['signature']!==$expected['schema_signature'])throw new RuntimeException('Schema changed after validation.');
    if(!empty($expected['object_counts']['events'])&&$target->query('SELECT @@global.event_scheduler')->fetch_row()[0]!=='OFF')throw new RuntimeException('Event scheduler must be disabled administratively before restoring events.');
    restore_no_active_import($target,$c,$dir);$target->close();
    db_restore_update($db,$id,'safety_backup','Membuat dan memverifikasi backup pengaman sebelum penggantian data.');
    $safetyId=bin2hex(random_bytes(16));
    $s=$db->prepare("INSERT INTO jobs(id,type,actor,actor_name,created_at) VALUES(?,'manual',?,?,?)");$s->execute([$safetyId,$row['actor'],$row['actor_name'],db_backup_now()->format(DATE_ATOM)]);
    db_restore_update($db,$id,'safety_backup','Membuat dan memverifikasi backup pengaman sebelum penggantian data.',$safetyId);
    db_backup_run_job($c,$db,db_backup_find($db,$safetyId));$row=db_restore_find($db,$id);
    $safety=db_backup_archive($db,$safetyId);if($safety['compressed'])throw new RuntimeException('New safety backup must be SQL.');
    restore_test_failure($c,'restore_safety');
    // Durable journal precedes the first destructive statement. A crash leaves maintenance closed.
    db_backup_atomic_json($paths['maintenance'],['id'=>$id,'server_uuid'=>$c['server_uuid'],'destructive'=>true,'safety_id'=>$safetyId]);$destructive=true;
    db_restore_update($db,$id,'restoring','Memulihkan seluruh database dari SQL terverifikasi.');
    restore_import($c,$dir,$dir.'/dump.sql',$row['sql_sha256']);
    db_restore_update($db,$id,'auditing','Mencocokkan data hasil pemulihan, relasi, dan seluruh unit.');
    restore_audit_target($c,$dir,$expected);restore_test_failure($c,'restore_audit');
    db_restore_update($db,$id,'completed','Pemulihan selesai dan data cocok dengan cadangan. Silakan masuk kembali.');
}catch(Throwable $e){
    if(!$db||!$row||!$dir){fwrite(STDERR,'Restore initialization refused.'.PHP_EOL);exit(1);}
    db_backup_atomic_json($dir.'/restore-failure.json',['time'=>db_backup_now()->format(DATE_ATOM),'error'=>$e->getMessage()]);
    if($destructive&&$ownsMaintenance){
        try{
            if(!$access){$access=database_access_lock($paths,LOCK_EX|LOCK_NB);if(!$access)throw new RuntimeException('Recovery access lock is busy.');}
            $auditMarker=$dir.'/target-audit.json';
            if(is_file($auditMarker)){
                $auditDirectory=json_decode(file_get_contents($auditMarker),true,16,JSON_THROW_ON_ERROR)['directory'];
                if(db_backup_normal_path(dirname($auditDirectory))!==db_backup_normal_path($c['storage_root'].'/jobs')||!preg_match('/^[a-f0-9]{32}$/D',basename($auditDirectory)))throw new RuntimeException('Recovery audit directory differs.');
                db_backup_private_path($auditDirectory);
                db_backup_process(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/verify_backup_instance.ps1','-ConfigFile',$c['_config_file'],'-JobDirectory',$auditDirectory,'-Recover'],$dir.'/recover-audit',120);
            }
            db_restore_update($db,$row['id'],'rolling_back','Pemulihan belum lolos pemeriksaan; mengembalikan backup pengaman.');
            $row=db_restore_find($db,$row['id']);if(!$row['safety_id'])throw new RuntimeException('Safety backup identity unavailable.');
            $safety=db_backup_archive($db,$row['safety_id']);$safetyJob=db_backup_find($db,$row['safety_id']);
            restore_import($c,$dir,$safety['file'],$safetyJob['sql_sha256'],true);
            $safetyExpected=json_decode(file_get_contents(db_backup_job_dir($row['safety_id']).'/verification.json'),true,64,JSON_THROW_ON_ERROR);
            restore_audit_target($c,$dir,$safetyExpected);
            db_restore_update($db,$row['id'],'rolled_back','Pemulihan dibatalkan; backup pengaman berhasil dikembalikan dan diperiksa. Silakan masuk kembali.');
        }catch(Throwable $rollback){
            db_backup_atomic_json($dir.'/restore-recovery-failure.json',['error'=>$rollback->getMessage(),'time'=>db_backup_now()->format(DATE_ATOM)]);
            db_restore_update($db,$row['id'],'recovery_required','Pemulihan pengaman belum selesai. Aplikasi tetap dalam pemeliharaan; coba Lanjutkan Pemulihan atau hubungi pengelola.');
        }
    }else db_restore_update($db,$row['id'],'failed','Pemeriksaan pemulihan belum berhasil. Database tidak diganti; periksa izin, kompatibilitas SQL, dan ruang disk.');
}finally{
    if($db&&$row&&$dir){
        $final=db_restore_find($db,$row['id']);
        if(in_array($final['state'],['completed','rolled_back','failed','cancelled'],true)){
            if($ownsMaintenance)restore_finalize($c,$final,$dir);
            else foreach(['target.cnf','target-auth.json'] as $name){$secret=$dir.'/'.$name;if(is_file($secret)){db_backup_private_path($secret);unlink($secret);}}
        }
    }
    database_access_release($access);
}
