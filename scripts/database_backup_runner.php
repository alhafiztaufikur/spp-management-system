<?php
/** Task Scheduler entry point. Operational SQL is read-only; verification is isolated. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once __DIR__.'/../includes/database_backup.php';
foreach($argv as $arg)if(str_starts_with($arg,'--config='))putenv('SPP_BACKUP_CONFIG='.substr($arg,9));
date_default_timezone_set('Asia/Jakarta');
function db_backup_update(PDO $queue,string $id,array $fields): void {
    $allowed=['state','message','started_at','finished_at','next_attempt_at','retryable','archive_name','archive_sha256','sql_sha256','bytes'];$sql=[];$values=[];
    foreach($fields as $key=>$value){if(!in_array($key,$allowed,true))throw new LogicException('Invalid backup field');$sql[]=$key.'=?';$values[]=$value;}$values[]=$id;
    $s=$queue->prepare('UPDATE jobs SET '.implode(',',$sql).' WHERE id=?');$s->execute($values);
}
function db_backup_test_failure(array $c,string $stage): void {
    $wanted=getenv('SPP_BACKUP_TEST_FAIL');if(!$wanted)return;
    if(empty($c['allowed_test'])||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')throw new RuntimeException('Test hook refused on production.');
    if(!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',$c['database'])){
        $file=$c['isolated_instance_manifest']??'';$m=is_file($file)?json_decode(file_get_contents($file),true):[];
        if(empty($m['server_uuid'])||$m['server_uuid']!==$c['server_uuid']||db_backup_normal_path($m['datadir']??'')!==db_backup_normal_path($c['datadir'])||($m['isolated_test']??false)!==true||empty($m['source_server_uuid'])||$m['source_server_uuid']===$m['server_uuid']||db_backup_normal_path($m['source_datadir']??'')===db_backup_normal_path($m['datadir'])||(int)$c['port']===3306||(int)($m['port']??0)!==(int)$c['port'])throw new RuntimeException('Test hook requires a verified isolated test instance.');
    }
    if($wanted===$stage)throw new RuntimeException('Injected isolated backup failure: '.$stage);
}
function db_backup_run_job(array $c,PDO $queue,array $job): void {
    $id=$job['id'];$dir=db_backup_job_dir($id);$stage='source';$source=null;
    if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('Private job storage unavailable.');
    try{
        if($job['type']==='manual'){
            $GLOBALS['koneksi']=db_backup_source($c);db_backup_actor((int)$job['actor']);$GLOBALS['koneksi']->close();unset($GLOBALS['koneksi']);
        }
        db_backup_update($queue,$id,['state'=>'dumping','message'=>'Mencadangkan seluruh database.','started_at'=>db_backup_now()->format(DATE_ATOM)]);
        $source=db_backup_source($c);$schema=db_backup_schema($source);
        if(!db_backup_disk_budget($schema['estimated_bytes'],(int)disk_free_space($c['storage_root'])))throw new RuntimeException('Insufficient private backup space.');
        db_backup_test_failure($c,'disk');db_backup_atomic_json($dir.'/schema.json',$schema);
        $stage='dump';$dump=$dir.'/dump.sql.partial';
        db_backup_test_failure($c,'dump');
        $args=[$c['mysql_bin'].'/mysqldump.exe','--defaults-file='.$c['source_defaults_file'],'--host='.$c['host'],'--port='.$c['port'],'--protocol=TCP','--single-transaction','--quick','--skip-lock-tables','--routines','--triggers','--events','--hex-blob','--no-tablespaces','--set-gtid-purged=OFF','--column-statistics=0','--default-character-set=utf8mb4','--result-file='.$dump,'--databases',$c['database']];
        db_backup_process($args,$dir.'/dump',1800);
        if(!is_file($dump)||filesize($dump)<100||is_file(__DIR__.'/../tmp/financial_migration.lock')||!hash_equals($schema['signature'],db_backup_schema($source)['signature']))throw new RuntimeException('Dump incomplete, maintenance began, or schema changed.');
        db_backup_test_failure($c,'schema');$source->close();$source=null;
        if(!rename($dump,$dir.'/backup.sql'))throw new RuntimeException('Dump publication failed.');
        $sqlHash=hash_file('sha256',$dir.'/backup.sql');$archiveHash=$sqlHash;$archiveBytes=filesize($dir.'/backup.sql');
        db_backup_update($queue,$id,['state'=>'verifying','message'=>'Memeriksa pemulihan cadangan.','archive_name'=>'backup.sql','archive_sha256'=>$archiveHash,'sql_sha256'=>$sqlHash,'bytes'=>$archiveBytes]);
        $stage='verify';db_backup_test_failure($c,'verify');
        // Verify the exact SQL that will be retained and downloaded.
        $dump=$dir.'/dump.sql';db_backup_copy_sql($dir.'/backup.sql',$dump,$sqlHash);
        db_backup_process(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/verify_backup_instance.ps1','-ConfigFile',$c['_config_file'],'-JobDirectory',$dir,'-DumpFile',$dump],$dir.'/verify',1800);
        $report=is_file($dir.'/verification.json')?json_decode(file_get_contents($dir.'/verification.json'),true,64,JSON_THROW_ON_ERROR):[];
        if(($report['state']??'')!=='verified'||!hash_equals($sqlHash,(string)($report['sql_sha256']??''))||!hash_equals($archiveHash,hash_file('sha256',$dir.'/backup.sql')))throw new RuntimeException('Verification report incomplete.');
        $finished=db_backup_now()->format(DATE_ATOM);
        db_backup_atomic_json($dir.'/manifest.json',['version'=>1,'id'=>$id,'state'=>'verified','archive_name'=>'backup.sql','format'=>'sql','database'=>$c['database'],'source_server_uuid'=>$c['server_uuid'],'type'=>$job['type'],'scheduled_for'=>$job['slot'],'schedule_window'=>db_backup_schedule_window($queue,$job,$c['installed_at']),'created_at'=>$job['created_at'],'finished_at'=>$finished,'archive_sha256'=>$archiveHash,'sql_sha256'=>$sqlHash,'bytes'=>$archiveBytes,'verification'=>$report]);
        db_backup_update($queue,$id,['state'=>'verified','message'=>'Cadangan dan pemulihannya berhasil diverifikasi.','finished_at'=>$finished,'retryable'=>0]);
        // Only an owned intermediate is removed; the archive and verification are retained forever.
        if(is_file($dump)&&!is_link($dump)&&hash_equals($sqlHash,hash_file('sha256',$dump)))unlink($dump);
        echo 'VERIFIED '.$id.PHP_EOL;
    }catch(Throwable $e){
        if($source instanceof mysqli)$source->close();
        $message=$stage==='verify'?'Pemulihan cadangan belum lolos pemeriksaan. Berkas dipertahankan untuk ditinjau.':'Pencadangan tertunda. Periksa MySQL dan ruang penyimpanan; sistem akan mencoba kembali.';
        if($stage==='verify'){
            try{db_backup_process(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/verify_backup_instance.ps1','-ConfigFile',$c['_config_file'],'-JobDirectory',$dir,'-Recover'],$dir.'/recover',120);}catch(Throwable $ignored){}
        }
        db_backup_atomic_json($dir.'/failure.json',['time'=>db_backup_now()->format(DATE_ATOM),'stage'=>$stage,'error'=>$e->getMessage()]);
        db_backup_update($queue,$id,['state'=>'failed','message'=>$message,'finished_at'=>db_backup_now()->format(DATE_ATOM),'retryable'=>$stage!=='verify'&&$job['slot']?1:0,'next_attempt_at'=>time()+900]);
        throw new RuntimeException('Backup failed at '.$stage.'. Private diagnostics retained.',0,$e);
    }
}
try{
    $c=db_backup_config();
    if(in_array('--setup',$argv,true)){db_backup_store(true);echo 'Backup queue prepared.'.PHP_EOL;exit;}
    if(!in_array('--tick',$argv,true)&&!in_array('--verify',$argv,true))throw new InvalidArgumentException('Use --setup, --tick, or --verify --id=<backup-id>.');
    $lock=fopen($c['storage_root'].'/runner.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){echo 'Backup dispatcher already running.'.PHP_EOL;exit;}
    $GLOBALS['db_backup_heartbeat_file']=$c['storage_root'].'/heartbeat.json';db_backup_atomic_json($GLOBALS['db_backup_heartbeat_file'],['time'=>time(),'pid'=>getmypid()]);$queue=db_backup_store();
    if(in_array('--verify',$argv,true)){
        $id='';foreach($argv as $arg)if(str_starts_with($arg,'--id='))$id=substr($arg,5);
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
    if(is_file(__DIR__.'/../tmp/financial_migration.lock')){echo 'Backup deferred during maintenance.'.PHP_EOL;exit;}
    db_backup_schedule_job($queue,db_backup_now());
    $job=$queue->query("SELECT * FROM jobs WHERE state='queued' ORDER BY created_at,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if($job)db_backup_run_job($c,$queue,$job);else echo 'No backup due.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
