<?php
/** On-demand jobs use the same private queue and runner, independently of Windows scheduling. */
class BackupDispatchException extends RuntimeException {}
function db_backup_dispatch_tools(array $config): ?array {
    $powershell=rtrim(getenv('WINDIR')?:'C:/Windows','/\\').'/System32/WindowsPowerShell/v1.0/powershell.exe';
    $launcher=__DIR__.'/../scripts/start_database_backup_job.ps1';
    if(PHP_OS_FAMILY!=='Windows'||!function_exists('proc_open')||!is_file($config['php_executable'])||!is_file($powershell)||!is_file($launcher))return null;
    return [$powershell,realpath($launcher)];
}
function db_backup_lock_busy(string $path): bool {
    if(!file_exists($path)&&!is_link($path))return false;
    db_backup_private_path($path);$handle=fopen($path,'c');if(!$handle)throw new RuntimeException('Backup lock unavailable.');
    try{$locked=flock($handle,LOCK_EX|LOCK_NB);if($locked)flock($handle,LOCK_UN);return !$locked;}
    finally{fclose($handle);}
}
function db_backup_job_running(array $config,array $job): bool {
    $dir=db_backup_job_dir($job['id']);
    if(db_backup_lock_busy($dir.'/worker.lock'))return true;
    if(in_array($job['state'],['dumping','verifying'],true)&&db_backup_lock_busy($config['storage_root'].'/runner.lock'))return true;
    $receipt=$dir.'/dispatch.json';
    if(is_file($receipt)){
        db_backup_private_path($receipt);$data=json_decode(file_get_contents($receipt),true,32,JSON_THROW_ON_ERROR);
        if(($data['id']??'')===$job['id']&&(int)($data['time']??0)>time()-10)return true;
    }
    return false;
}
function db_backup_dispatch(string $id): void {
    $config=db_backup_config();$queue=db_backup_store();$job=db_backup_find($queue,$id);
    if(!in_array($job['state'],['queued','dumping','verifying'],true))return;
    $tools=db_backup_dispatch_tools($config);
    if(!$tools)throw new BackupDispatchException('Pemicu backup manual belum tersedia. Periksa PHP CLI dan PowerShell pada PC ini.');
    $dir=db_backup_job_dir($id);
    if(!is_dir($dir)&&!mkdir($dir,0700)&&!is_dir($dir))throw new BackupDispatchException('Direktori backup manual belum dapat dibuat.');
    db_backup_private_path($dir);
    foreach(['dispatch.lock','dispatch.json','worker.lock','worker.out.log','worker.error.log'] as $name){
        $file=$dir.'/'.$name;if(file_exists($file)||is_link($file))db_backup_private_path($file);
    }
    // Tests may defer only on an explicitly allowed, independently identified fixture.
    if(getenv('SPP_BACKUP_TEST_DISPATCH')==='defer'){
        if(empty($config['allowed_test'])||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!db_backup_service_registered($config))throw new RuntimeException('Backup dispatch test hook refused.');
        return;
    }
    $launchPath=$dir.'/dispatch.lock';if(file_exists($launchPath))db_backup_private_path($launchPath);
    $launch=fopen($launchPath,'c');if(!$launch)throw new BackupDispatchException('Pemicu backup manual belum dapat dikunci.');
    if(!flock($launch,LOCK_EX|LOCK_NB)){fclose($launch);return;}
    try{
        $job=db_backup_find($queue,$id);
        if(!in_array($job['state'],['queued','dumping','verifying'],true)||db_backup_job_running($config,$job))return;
        db_backup_atomic_json($dir.'/dispatch.json',['id'=>$id,'time'=>time()]);
        try{
            db_backup_process([$tools[0],'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$tools[1],'-ConfigFile',$config['_config_file'],'-JobId',$id],$dir.'/dispatch',20);
        }catch(Throwable $e){
            $queue->prepare("UPDATE jobs SET state='failed',message=?,finished_at=?,retryable=0 WHERE id=? AND state='queued'")->execute(['Proses backup manual belum dapat dimulai. Coba kembali.',db_backup_now()->format(DATE_ATOM),$id]);
            error_log('Manual backup dispatch failed ('.get_class($e).').');
            throw new BackupDispatchException('Proses backup manual belum dapat dimulai. Coba kembali.');
        }
    }finally{flock($launch,LOCK_UN);fclose($launch);}
}
