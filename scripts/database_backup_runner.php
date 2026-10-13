<?php
/** Task Scheduler entry point. Operational SQL is read-only; verification is isolated. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once __DIR__.'/../includes/database_backup.php';
foreach($argv as $arg)if(str_starts_with($arg,'--config='))putenv('SPP_BACKUP_CONFIG='.substr($arg,9));
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__.'/database_backup_worker.php';
require_once __DIR__.'/../includes/database_access.php';

try{
    $c=db_backup_config();
    if(in_array('--setup',$argv,true)){db_backup_store(true);echo 'Backup queue prepared.'.PHP_EOL;exit;}
    $onDemandId=null;foreach($argv as $arg)if(str_starts_with($arg,'--job=')){
        if($onDemandId!==null)throw new InvalidArgumentException('Only one job may be requested.');$onDemandId=substr($arg,6);
    }
    if($onDemandId!==null&&(in_array('--tick',$argv,true)||in_array('--verify',$argv,true)))throw new InvalidArgumentException('Use one runner mode.');
    if($onDemandId===null&&!in_array('--tick',$argv,true)&&!in_array('--verify',$argv,true))throw new InvalidArgumentException('Use --setup, --tick, --job=<backup-id>, or --verify --id=<backup-id>.');
    $queue=db_backup_store();
    if($onDemandId!==null){
        $requested=db_backup_find($queue,$onDemandId);
        if(!in_array($requested['state'],['queued','dumping','verifying'],true)){echo 'Requested backup already completed.'.PHP_EOL;exit;}
        $dir=db_backup_job_dir($onDemandId);if(!is_dir($dir)&&!mkdir($dir,0700)&&!is_dir($dir))throw new RuntimeException('Private job storage unavailable.');
        db_backup_private_path($dir);$workerPath=$dir.'/worker.lock';if(file_exists($workerPath))db_backup_private_path($workerPath);
        $workerLock=fopen($workerPath,'c');if(!$workerLock||!flock($workerLock,LOCK_EX|LOCK_NB)){echo 'Requested backup worker already running.'.PHP_EOL;exit;}
    }
    $runnerPath=$c['storage_root'].'/runner.lock';if(file_exists($runnerPath))db_backup_private_path($runnerPath);
    $lock=fopen($runnerPath,'c');if(!$lock||!flock($lock,$onDemandId!==null?LOCK_EX:(LOCK_EX|LOCK_NB))){echo 'Backup dispatcher already running.'.PHP_EOL;exit;}
    if(in_array('--tick',$argv,true)){
        $GLOBALS['db_backup_heartbeat_file']=$c['storage_root'].'/heartbeat.json';db_backup_atomic_json($GLOBALS['db_backup_heartbeat_file'],['time'=>time(),'pid'=>getmypid()]);
    }
    if(in_array('--verify',$argv,true)){
        $id='';foreach($argv as $arg)if(str_starts_with($arg,'--id='))$id=substr($arg,5);
        db_backup_find($queue,$id);$archiveAccess=db_backup_access_lock($id);
        $archive=db_backup_archive($queue,$id);$dir=db_backup_job_dir($id);$job=db_backup_find($queue,$id);
        if($archive['compressed'])db_backup_expand_archive($archive['file'],$dir.'/dump.sql',$job['sql_sha256']);
        else db_backup_copy_sql($archive['file'],$dir.'/dump.sql',$job['sql_sha256']);
        try{db_backup_process(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/verify_backup_instance.ps1','-ConfigFile',$c['_config_file'],'-JobDirectory',$dir,'-DumpFile',$dir.'/dump.sql'],$dir.'/reverify',1800);echo 'REVERIFIED '.$id.PHP_EOL;}
        finally{if(is_file($dir.'/dump.sql')&&!is_link($dir.'/dump.sql')&&hash_equals($job['sql_sha256'],hash_file('sha256',$dir.'/dump.sql')))unlink($dir.'/dump.sql');}
        exit;
    }
    foreach($queue->query("SELECT * FROM jobs WHERE state IN ('dumping','verifying')")->fetchAll(PDO::FETCH_ASSOC) as $interrupted){
        $dir=db_backup_job_dir($interrupted['id']);
        db_backup_process(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/verify_backup_instance.ps1','-ConfigFile',$c['_config_file'],'-JobDirectory',$dir,'-Recover'],$dir.'/recover',120);
        db_backup_update($queue,$interrupted['id'],['state'=>'failed','message'=>'Proses terputus; berkas dipertahankan dan jadwal akan dicoba kembali.','finished_at'=>db_backup_now()->format(DATE_ATOM),'retryable'=>$interrupted['slot']?1:0,'next_attempt_at'=>time()+900]);
    }
    // Financial maintenance also pauses Legacy; backups must not race administrative DDL/restore.
    $restoreAccess=database_access_paths($c['database'],(int)$c['port']);
    if(is_file(__DIR__.'/../tmp/financial_migration.lock')||is_file($restoreAccess['maintenance'])){
        if($onDemandId!==null)$queue->prepare("UPDATE jobs SET state='failed',message='Backup manual ditunda selama pemeliharaan. Coba kembali setelah pemeliharaan selesai.',finished_at=?,retryable=0 WHERE id=? AND state='queued'")->execute([db_backup_now()->format(DATE_ATOM),$onDemandId]);
        echo 'Backup deferred during maintenance.'.PHP_EOL;exit;
    }
    if($onDemandId!==null){$job=db_backup_find($queue,$onDemandId);if($job['state']!=='queued')$job=null;}
    else{db_backup_schedule_job($queue,db_backup_now());$job=$queue->query("SELECT * FROM jobs WHERE state='queued' ORDER BY created_at,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);}
    if($job)db_backup_run_job($c,$queue,$job);else echo 'No backup due.'.PHP_EOL;
    if($onDemandId!==null)exit;
    // Resumable cleanup also runs on idle ticks, never before a verified automatic replacement.
    try{
        $pruned=db_backup_prune($queue);
        if($pruned['errors'])db_backup_atomic_json($c['storage_root'].'/retention-warning.json',['message'=>'Pembersihan cadangan kedaluwarsa belum selesai. Periksa layanan dan berkas privat.','time'=>time()]);
        elseif(is_file($c['storage_root'].'/retention-warning.json')){db_backup_private_path($c['storage_root'].'/retention-warning.json');unlink($c['storage_root'].'/retention-warning.json');}
        if($pruned['expired'])echo 'EXPIRED '.$pruned['expired'].PHP_EOL;
    }catch(Throwable $e){
        db_backup_atomic_json($c['storage_root'].'/retention-warning.json',['message'=>'Pembersihan cadangan kedaluwarsa belum selesai. Periksa layanan dan berkas privat.','time'=>time()]);
        error_log('Backup retention deferred ('.get_class($e).').');
    }
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
