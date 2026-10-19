<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../includes/legacy_application_wait.php';
if(($argv[1]??'')==='--hold'){$h=database_access_lock(['lock'=>$argv[2]],LOCK_SH);file_put_contents($argv[3],'ready');usleep(650000);database_access_release($h);exit;}
$file=tempnam(sys_get_temp_dir(),'spp-lock-test-');$marker=$file.'.ready';$pipes=[];$proc=null;
try{
    $proc=proc_open([PHP_BINARY,__FILE__,'--hold',$file,$marker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Cannot launch owned lock holder');
    $deadline=microtime(true)+5;while(!is_file($marker)){if(microtime(true)>$deadline)throw new RuntimeException('Lock holder did not start');usleep(10000);}
    $notices=0;$start=microtime(true);$lock=lful_wait_access(['lock'=>$file],static function()use(&$notices){$notices++;},2000);
    if($notices<1||microtime(true)-$start<0.4)throw new RuntimeException('Did not wait for real shared HTTP-style lock');database_access_release($lock);
    $held=database_access_lock(['lock'=>$file],LOCK_SH);$busy=false;
    try{lful_wait_access(['lock'=>$file],static fn()=>null,100);}catch(LegacyApplicationBusy $e){$busy=true;}finally{database_access_release($held);}
    if(!$busy)throw new RuntimeException('Contention did not remain a retryable queue condition');
    echo "PASS: waits for real shared request lock, continues after release, and yields retryable contention rather than failed migration.\n";
}finally{foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($proc))proc_close($proc);if(is_file($marker))unlink($marker);if(is_file($file))unlink($file);}
