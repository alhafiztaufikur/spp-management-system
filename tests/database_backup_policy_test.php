<?php
/** Self-contained policy/rotation tests. No connection to an application database. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/database_backup.php';
$checks=0;
function policy_assert(bool $condition,string $message): void { global $checks;if(!$condition)throw new RuntimeException($message);$checks++; }
function policy_throws(callable $call,string $class): void {try{$call();}catch(Throwable $e){policy_assert($e instanceof $class,'Unexpected exception: '.get_class($e));return;}throw new RuntimeException('Expected '.$class);}
function policy_store(): PDO {
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE jobs(id TEXT PRIMARY KEY,manual_key TEXT,slot TEXT,type TEXT,actor INTEGER DEFAULT 0,actor_name TEXT DEFAULT 'Sistem',state TEXT DEFAULT 'queued',message TEXT DEFAULT 'Menunggu',created_at TEXT,started_at TEXT,finished_at TEXT,next_attempt_at INTEGER DEFAULT 0,retryable INTEGER DEFAULT 0,archive_name TEXT,archive_sha256 TEXT,sql_sha256 TEXT,bytes INTEGER DEFAULT 0);
        CREATE TABLE schedule_slots(slot TEXT PRIMARY KEY,last_job_id TEXT REFERENCES jobs(id));");
    db_backup_policy_schema($db);return $db;
}
$actor=['id'=>42,'nama'=>'Petugas Uji'];$now=new DateTimeImmutable('2026-10-13T12:00:00+07:00');
foreach(['UTC','America/New_York','Asia/Jakarta'] as $zone){
    date_default_timezone_set($zone);
    foreach(['00:00','18:00','23:59'] as $clock){
        [$hour,$minute]=array_map('intval',explode(':',$clock));
        foreach(['2026-12-31','2028-02-29'] as $date){
            $at=(new DateTimeImmutable($date.'T00:00:00+07:00'))->setTime($hour,$minute);
            policy_assert(db_backup_latest_due($at,$clock)===$at->format(DATE_ATOM),'Exact due time differs');
            policy_assert(db_backup_latest_due($at->modify('-1 second'),$clock)===$at->modify('-1 day')->format(DATE_ATOM),'Before due differs');
            policy_assert(db_backup_next_schedule($at,$clock)===$at->modify('+1 day')->format(DATE_ATOM),'Next date differs');
        }
    }
}
$db=policy_store();policy_assert(!db_backup_settings($db)['activated'],'Legacy retention must remain disabled');
$saved=db_backup_save_settings($db,$actor,'20:30','90','0',$now);
policy_assert($saved['version']===1&&$saved['effective_from']==='2026-10-13T20:30:00+07:00','First activation must be future only');
policy_assert(db_backup_schedule_job($db,$now)===null,'Saving must not produce retrospective jobs');
policy_assert((int)$db->query('SELECT COUNT(*) FROM backup_setting_changes')->fetchColumn()===1,'Setting audit missing');
foreach([['24:00','90','1'],['18:60','90','1'],['8:00','90','1'],[['18:00'],'90','1'],['18:00','0','1'],['18:00','366','1'],['18:00','1.5','1'],['18:00',['90'],'1'],['18:00','90','-1'],['18:00','90',['1']]] as $invalid){
    policy_throws(fn()=>db_backup_save_settings($db,$actor,...[...$invalid,$now]),InvalidArgumentException::class);
}
policy_throws(fn()=>db_backup_save_settings($db,$actor,'20:30','1','0',$now),BackupSettingsConflict::class);
policy_assert(db_backup_settings($db)===$saved,'Invalid/conflicting updates changed settings');
policy_assert(db_backup_save_settings($db,$actor,'20:30','90','1',$now)===$saved,'Identical save must not postpone scheduling');
$due=new DateTimeImmutable($saved['effective_from']);$job=db_backup_schedule_job($db,$due);
policy_assert($job&&$job['type']==='scheduled','Configured daily job not scheduled');
policy_assert(db_backup_schedule_job($db,$due->modify('+1 minute'))===null,'Repeated tick duplicated job');
$db->prepare("UPDATE jobs SET state='verified',finished_at=? WHERE id=?")->execute([$due->format(DATE_ATOM),$job['id']]);
$changed=db_backup_save_settings($db,$actor,'23:59','365','1',$due->modify('+1 minute'));
policy_assert(db_backup_schedule_job($db,new DateTimeImmutable($changed['effective_from']))===null,'Clock change duplicated a successful WIB date');
policy_assert(db_backup_policy_next($db,$due->modify('+1 minute'),$changed)==='2026-10-14T23:59:00+07:00','Next schedule ignores already satisfied day');
$later=db_backup_schedule_job($db,new DateTimeImmutable('2026-10-18T10:00:00+07:00'));
policy_assert($later&&$later['type']==='catchup'&&$later['slot']==='2026-10-17T23:59:00+07:00','Catchup must be latest missed real slot');
policy_assert((int)$db->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===2,'Missed dates fabricated snapshots');
$beforeChange=db_backup_save_settings($db,$actor,'00:00','1','2',new DateTimeImmutable('2026-10-18T10:01:00+07:00'));
policy_assert(db_backup_find($db,$later['id'])['state']==='queued','Clock change cancelled queued work');
policy_assert(db_backup_schedule_job($db,new DateTimeImmutable('2026-10-19T00:00:00+07:00'))===null,'Outstanding work duplicated after clock change');
$retryAt=new DateTimeImmutable('2026-10-18T10:20:00+07:00');
$db->prepare("UPDATE jobs SET state='failed',retryable=1,next_attempt_at=? WHERE id=?")->execute([$retryAt->getTimestamp(),$later['id']]);
policy_assert(db_backup_schedule_job($db,$retryAt->modify('-1 second'))===null,'Retry occurred before delay');
$retry=db_backup_schedule_job($db,$retryAt);policy_assert($retry&&$retry['slot']===$later['slot'],'Old clock retry lost original slot');
policy_assert(db_backup_settings($db)['retention_days']===1,'Retention lower boundary differs');
policy_assert(db_backup_schedule_window($db,$retry,'2026-10-01T10:00:00+07:00')['last_due']===$retry['slot'],'Window must preserve accepted slot clock');

// Actual private payload rotation, using an unrelated fake source identity and no MySQL connection.
$root=sys_get_temp_dir().'/spp-backup-policy-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/jobs',0700);
file_put_contents($root.'/client.cnf',"[client]\nuser=test\npassword=unused\n");
$original=getenv('SPP_BACKUP_CONFIG');$configFile=$root.'/config.json';
db_backup_atomic_json($configFile,['version'=>1,'app_root'=>realpath(__DIR__.'/..'),'mysql_bin'=>$root,'php_executable'=>PHP_BINARY,'storage_root'=>$root,'database'=>'db_spp_audit_policy','host'=>'127.0.0.1','port'=>65430,'server_uuid'=>'fake-unconnected-test','datadir'=>$root.'/never-connected','source_defaults_file'=>$root.'/client.cnf','installed_at'=>$now->format(DATE_ATOM)]);
putenv('SPP_BACKUP_CONFIG='.$configFile);
function policy_archive(PDO $db,DateTimeImmutable $created,string $type='scheduled',?DateTimeImmutable $finished=null,string $format='backup.sql'): string {
    $id=bin2hex(random_bytes(16));$dir=db_backup_job_dir($id);mkdir($dir,0700);
    $sql="-- Owned policy test SQL, never executed.\nSELECT '".$id."';\n";$payload=$format==='backup.sql.gz'?gzencode($sql):$sql;
    file_put_contents($dir.'/'.$format,$payload);$hash=hash('sha256',$payload);$sqlHash=hash('sha256',$sql);
    db_backup_atomic_json($dir.'/manifest.json',['id'=>$id,'state'=>'verified','archive_name'=>$format,'archive_sha256'=>$hash]);
    $s=$db->prepare("INSERT INTO jobs(id,type,state,created_at,finished_at,archive_name,archive_sha256,sql_sha256,bytes) VALUES(?,?,'verified',?,?,?,?,?,?)");
    $s->execute([$id,$type,$created->format(DATE_ATOM),($finished??$created)->format(DATE_ATOM),$format,$hash,$sqlHash,strlen($payload)]);return $id;
}
try{
    $store=db_backup_store(true);
    $old=policy_archive($store,$now->modify('-91 days'));
    $manual=policy_archive($store,$now->modify('-366 days'),'manual');
    $edge=policy_archive($store,$now->modify('-90 days'));
    $recent=policy_archive($store,$now->modify('-89 days'));
    policy_assert(db_backup_prune($store,$now)['expired']===0,'Legacy installation must not delete archives');
    db_backup_save_settings($store,$actor,'18:00','90','0',$now->modify('-2 minutes'));
    policy_assert(db_backup_prune($store,$now)['expired']===0,'Policy must wait for verified automatic replacement');
    policy_archive($store,$now,'manual');
    policy_assert(db_backup_prune($store,$now)['expired']===0,'Manual success must not trigger automatic retention');
    $anchor=policy_archive($store,$now);
    $readLock=db_backup_access_lock($old);
    try{$deferred=db_backup_prune($store,$now);policy_assert($deferred['expired']===0&&$deferred['deferred']===1,'Active download/verification must defer cleanup');}
    finally{db_backup_release_lock($readLock);}
    $pruned=db_backup_prune($store,$now);policy_assert($pruned['expired']===1&&$pruned['errors']===0,'Old automatic payload not rotated');
    policy_assert(!is_file(db_backup_job_dir($old).'/backup.sql')&&is_file(db_backup_job_dir($old).'/manifest.json'),'Rotation must remove payload only');
    policy_assert(db_backup_find($store,$old)['state']==='expired','Expired history lost');
    policy_assert(!isset(db_backup_public_job(db_backup_find($store,$old))['download_url']),'Expired download link leaked');
    policy_throws(fn()=>db_backup_archive($store,$old),BackupExpired::class);
    foreach([$manual,$edge,$recent,$anchor] as $id)policy_assert(is_file(db_backup_archive($store,$id)['file']),'Manual/boundary/recent/replacement archive changed');
    policy_assert(db_backup_prune($store,$now)['expired']===0,'Repeated cleanup is not idempotent');
    policy_assert(db_backup_prune($store,$now->modify('+2 days'))['expired']===0,'Idle/failure days must not advance cutoff without a new verified replacement');
    db_backup_save_settings($store,$actor,'18:00','1','1',$now->modify('+1 minute'));
    policy_assert(db_backup_prune($store,$now->modify('+2 minutes'))['expired']===0,'Shortening retention deleted before new success');
    $anchor2=policy_archive($store,$now->modify('+3 minutes'));
    $gzip=policy_archive($store,$now->modify('-3 days'),'catchup',null,'backup.sql.gz');
    $gzipDir=db_backup_job_dir($gzip);$decoded=gzdecode(file_get_contents($gzipDir.'/backup.sql.gz'));file_put_contents($gzipDir.'/download.sql',$decoded);
    $corrupt=policy_archive($store,$now->modify('-5 days'));file_put_contents(db_backup_job_dir($corrupt).'/backup.sql','unexpected content');
    $pruned=db_backup_prune($store,$now->modify('+4 minutes'));
    policy_assert($pruned['expired']===3&&$pruned['errors']===1,'Interval change/gzip/checksum guard differs');
    policy_assert(!is_file($gzipDir.'/backup.sql.gz')&&!is_file($gzipDir.'/download.sql'),'Legacy payload/cache not removed together');
    policy_assert(is_file(db_backup_job_dir($corrupt).'/backup.sql')&&db_backup_find($store,$corrupt)['state']==='verified','Unrecognized content must be preserved');
    // Simulate a crash after durable expiring state and removal of the archive.
    $resuming=policy_archive($store,$now->modify('-6 days'));$resumeDir=db_backup_job_dir($resuming);
    $store->prepare('INSERT INTO backup_expirations(job_id,anchor_id,settings_version,cutoff,planned_at) VALUES(?,?,?,?,?)')->execute([$resuming,$anchor2,2,$now->modify('-1 day')->format(DATE_ATOM),$now->format(DATE_ATOM)]);
    $store->prepare("UPDATE jobs SET state='expiring' WHERE id=?")->execute([$resuming]);unlink($resumeDir.'/backup.sql');
    policy_assert(db_backup_prune($store,$now->modify('+5 minutes'))['expired']===1&&db_backup_find($store,$resuming)['state']==='expired','Interrupted cleanup not resumed');
    // Reopening the store simulates another request/process, not in-memory settings.
    policy_assert(db_backup_settings(db_backup_store())['retention_days']===1,'Settings did not persist across connections');
    $summary=db_backup_summary();policy_assert($summary['count']===5,'Summary must count retained verified archives only');
    policy_assert(count($summary['alerts'])>0,'Incomplete cleanup must be visible');
    // A completed backup in the very same second before Save is not a new replacement.
    $sameSecondOld=policy_archive($store,$now->modify('-20 days'));
    db_backup_save_settings($store,$actor,'18:00','2','2',$now->modify('+3 minutes'));
    policy_assert(db_backup_prune($store,$now->modify('+8 minutes'))['expired']===0,'Same-second earlier success authorized a changed policy');
    policy_assert(is_file(db_backup_archive($store,$sameSecondOld)['file']),'Same-second policy change removed old backup');
    $year=policy_store();db_backup_save_settings($year,$actor,'00:00','365','0',$now);
    $yearOld=policy_archive($year,$now->modify('-366 days'));
    $yearEdge=policy_archive($year,$now->modify('-365 days'));
    policy_archive($year,$now);
    policy_assert(db_backup_prune($year,$now)['expired']===1,'365-day rotation differs');
    policy_assert(is_file(db_backup_archive($year,$yearEdge)['file']),'365-day exact boundary removed');
    $unsafe=policy_archive($year,$now->modify('-400 days'));$unsafeDir=db_backup_job_dir($unsafe);$outside=$root.'/outside-job';
    policy_assert(rename($unsafeDir,$outside),'Could not prepare owned path fixture');
    $command="New-Item -ItemType Junction -Path '".str_replace("'","''",$unsafeDir)."' -Target '".str_replace("'","''",$outside)."' | Out-Null";
    $p=proc_open(['powershell.exe','-NoProfile','-NonInteractive','-Command',$command],[0=>['pipe','r'],1=>['file',$root.'/junction.out.log','w'],2=>['file',$root.'/junction.error.log','w']],$pipes);fclose($pipes[0]);
    policy_assert(proc_close($p)===0,'Could not create owned junction fixture');
    $outsideHash=hash_file('sha256',$outside.'/backup.sql');
    policy_assert(db_backup_prune($year,$now)['errors']===1,'Junction cleanup was not refused');
    policy_assert(hash_file('sha256',$outside.'/backup.sql')===$outsideHash&&db_backup_find($year,$unsafe)['state']==='verified','Cleanup followed a junction outside its job');
    // Remove only the verified junction itself, without recursion or touching its target.
    policy_assert(rmdir($unsafeDir)&&is_file($outside.'/backup.sql'),'Junction removal changed target');
    $worker=<<<'PHP'
<?php
require $argv[1];putenv('SPP_BACKUP_CONFIG='.$argv[2]);
$db=db_backup_store();$until=microtime(true)+10;
while(!is_file($argv[3])){if(microtime(true)>$until)exit(2);usleep(10000);}
try{db_backup_save_settings($db,['id'=>42,'nama'=>'Petugas Uji'],$argv[4],'90','3');echo 'saved';}
catch(BackupSettingsConflict $e){echo 'conflict';exit(40);}
PHP;
    file_put_contents($root.'/settings-worker.php',$worker);$children=[];
    foreach(['18:10','18:20'] as $i=>$clock){
        $args=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=php_pdo_sqlite.dll',$root.'/settings-worker.php',realpath(__DIR__.'/../includes/database_backup.php'),$configFile,$root.'/start-workers',$clock];
        $p=proc_open($args,[0=>['pipe','r'],1=>['file',$root.'/worker-'.$i.'.out.log','w'],2=>['file',$root.'/worker-'.$i.'.error.log','w']],$pipes);fclose($pipes[0]);$children[]=$p;
    }
    file_put_contents($root.'/start-workers','start');$codes=array_map('proc_close',$children);sort($codes);
    policy_assert($codes===[0,40],'Concurrent settings saves must have one success and one version conflict');
    policy_assert(db_backup_settings($store)['version']===4,'Concurrent save advanced more than one version');
    policy_assert((int)$store->query('SELECT COUNT(*) FROM backup_setting_changes')->fetchColumn()===4,'Concurrent audit changes differ');
    $registration=db_backup_config();$registration['task_name']='Task Backup Uji';
    policy_assert(!db_backup_service_registered($registration),'Missing task manifest must not report installed service');
    db_backup_atomic_json($root.'/scheduled-task.json',['registered_at'=>null,'owner_sid'=>'S-1-5-21-42','task_name'=>'Task Backup Uji','config_file'=>$configFile]);
    policy_assert(!db_backup_service_registered($registration),'Failed registration must not report installed service');
    db_backup_atomic_json($root.'/scheduled-task.json',['registered_at'=>$now->format(DATE_ATOM),'owner_sid'=>'S-1-5-21-42','task_name'=>'Task Backup Uji','config_file'=>$configFile]);
    policy_assert(db_backup_service_registered($registration),'Owned registered task manifest not recognized');
    $registration['task_name']='Task lain';policy_assert(!db_backup_service_registered($registration),'Different task manifest accepted');
    $registration['allowed_test']=true;policy_assert(db_backup_service_registered($registration),'Private audit runner fixture must not register a production Windows task');
    echo "PASS: $checks policy checks; private scheduling/settings/rotation/locks/legacy SQL/resume; no application database operations\n";
    echo 'Private artifacts: '.$root."\n";
}finally{putenv($original===false?'SPP_BACKUP_CONFIG':'SPP_BACKUP_CONFIG='.$original);}
