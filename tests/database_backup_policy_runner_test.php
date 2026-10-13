<?php
/** Verify policy activation and rotation using actual dump/restore on an owned isolated instance. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';
require_once __DIR__.'/../includes/database_backup.php';
$base=db_backup_config();
if(empty($base['allowed_test'])||empty($base['isolated_instance_manifest']))throw new RuntimeException('Owned isolated backup configuration required.');
$source=db_backup_source($base);spp_test_assert_database_target($source);spp_test_isolated_manifest($source);
$root=dirname($base['_config_file']).'/policy_runner_'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/jobs',0700);
$config=$base;unset($config['_config_file']);$config['storage_root']=$root;db_backup_atomic_json($root.'/config.json',$config);
$original=getenv('SPP_BACKUP_CONFIG');putenv('SPP_BACKUP_CONFIG='.$root.'/config.json');
function policy_runner_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function policy_runner_tick(array $config,string $label,?string $fail=null): int {
    $previous=getenv('SPP_BACKUP_TEST_FAIL');if($fail)putenv('SPP_BACKUP_TEST_FAIL='.$fail);else putenv('SPP_BACKUP_TEST_FAIL');
    $p=proc_open([$config['php_executable'],__DIR__.'/../scripts/database_backup_runner.php','--config='.$config['_config_file'],'--tick'],[0=>['pipe','r'],1=>['file',$config['storage_root'].'/'.$label.'.out.log','w'],2=>['file',$config['storage_root'].'/'.$label.'.error.log','w']],$pipes);
    putenv($previous===false?'SPP_BACKUP_TEST_FAIL':'SPP_BACKUP_TEST_FAIL='.$previous);fclose($pipes[0]);$code=proc_close($p);clearstatcache();return $code;
}
function policy_runner_enqueue(PDO $queue,string $slot): string {
    $id=bin2hex(random_bytes(16));$queue->prepare("INSERT INTO jobs(id,slot,type,created_at) VALUES(?,?,'scheduled',?)")->execute([$id,$slot,db_backup_now()->format(DATE_ATOM)]);return $id;
}
try{
    $c=db_backup_config();$queue=db_backup_store(true);
    $before=[];foreach($source->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all() as [$table])$before[$table]=$source->query('CHECKSUM TABLE `'.$table.'` EXTENDED')->fetch_assoc()['Checksum'];
    policy_runner_check(policy_runner_tick($c,'initial')===0,'Initial backup failed');
    $old=$queue->query("SELECT * FROM jobs WHERE state='verified' LIMIT 1")->fetch(PDO::FETCH_ASSOC);policy_runner_check((bool)$old,'Initial verified backup missing');
    $oldPath=db_backup_archive($queue,$old['id'])['file'];$oldHash=hash_file('sha256',$oldPath);
    $manualId=bin2hex(random_bytes(16));$manualDir=db_backup_job_dir($manualId);mkdir($manualDir,0700);copy($oldPath,$manualDir.'/backup.sql');
    db_backup_atomic_json($manualDir.'/manifest.json',['id'=>$manualId,'state'=>'verified','archive_name'=>'backup.sql','archive_sha256'=>$oldHash]);
    $aged=db_backup_now()->modify('-91 days')->format(DATE_ATOM);
    $queue->prepare('UPDATE jobs SET created_at=? WHERE id=?')->execute([$aged,$old['id']]);
    $queue->prepare("INSERT INTO jobs(id,type,state,created_at,finished_at,archive_name,archive_sha256,sql_sha256,bytes) VALUES(?,'manual','verified',?,?,'backup.sql',?,?,?)")->execute([$manualId,$aged,$aged,$oldHash,$old['sql_sha256'],$old['bytes']]);
    $actor=$source->query("SELECT id,nama FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
    $now=db_backup_now();$clock=$now->modify('+2 hours')->format('H:i');
    $settings=db_backup_save_settings($queue,$actor,$clock,'90','0',$now);
    policy_runner_check(db_backup_schedule_job($queue,$now)===null,'Save immediately scheduled a job');
    policy_runner_check(db_backup_prune($queue)['expired']===0&&hash_file('sha256',$oldPath)===$oldHash,'Policy deleted before a new verified automatic backup');
    $slot=db_backup_latest_due($now,$clock);$failed=policy_runner_enqueue($queue,$slot);
    policy_runner_check(policy_runner_tick($c,'failed','dump')!==0,'Injected failure unexpectedly succeeded');
    policy_runner_check(db_backup_find($queue,$failed)['state']==='failed'&&is_file($oldPath),'Failure removed previous good archive');
    policy_runner_check(db_backup_prune($queue)['expired']===0,'Failure authorized cleanup');
    $replacement=policy_runner_enqueue($queue,$slot);
    policy_runner_check(policy_runner_tick($c,'replacement')===0,'Configured backup/verification failed');
    policy_runner_check(db_backup_find($queue,$replacement)['state']==='verified','Replacement not verified');
    policy_runner_check(db_backup_find($queue,$old['id'])['state']==='expired'&&!is_file($oldPath),'Verified replacement did not rotate expired payload');
    policy_runner_check(is_file(db_backup_archive($queue,$replacement)['file']),'Replacement unavailable');
    policy_runner_check(hash_file('sha256',db_backup_archive($queue,$manualId)['file'])===$oldHash,'Manual archive was modified');
    policy_runner_check(policy_runner_tick($c,'idle')===0,'Idle tick failed');
    policy_runner_check(db_backup_settings(db_backup_store())['daily_time']===$clock,'Restart lost configured time');
    foreach($before as $table=>$checksum)policy_runner_check($source->query('CHECKSUM TABLE `'.$table.'` EXTENDED')->fetch_assoc()['Checksum']===$checksum,'Source data changed: '.$table);
    echo "PASS: actual configured runner, failed dump preserves old backup, verified SQL/restore rotates old automatic backup, manual preserved, restart/idle and source checksums unchanged\n";
}finally{$source->close();putenv($original===false?'SPP_BACKUP_CONFIG':'SPP_BACKUP_CONFIG='.$original);}
