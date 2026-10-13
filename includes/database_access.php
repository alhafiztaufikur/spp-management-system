<?php
/** Drain HTTP/Legacy operations before a full restore. No database credentials here. */
function database_access_paths(string $database, int $port): array {
    $root=realpath(__DIR__.'/..');$tmp=$root.'/tmp';
    if(!is_dir($tmp)&&!is_link($tmp)&&!mkdir($tmp,0700)&&!is_dir($tmp))throw new RuntimeException('Database access directory unavailable.');
    $resolved=realpath($tmp);
    if(!$resolved||is_link($tmp)||strtolower(str_replace('\\','/',$resolved))!==strtolower(str_replace('\\','/',$tmp)))throw new RuntimeException('Database access directory is not regular.');
    $base=$tmp.'/database-access-'.hash('sha256',$port.':'.$database);
    return ['lock'=>$base.'.lock','maintenance'=>$base.'.json','generation'=>$base.'.generation'];
}
function database_access_lock(array $paths, int $operation) {
    foreach($paths as $path)if(is_link($path))throw new RuntimeException('Database access path is not regular.');
    $handle=fopen($paths['lock'],'c');
    if(!$handle)throw new RuntimeException('Database access lock unavailable.');
    if(!flock($handle,$operation)){fclose($handle);return null;}
    return $handle;
}
function database_access_release($handle): void {
    if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}
}
function database_access_http(string $database,int $port): void {
    $paths=database_access_paths($database,$port);
    $blocked=static function(): never {http_response_code(503);header('Retry-After: 10');exit('SistemSPP sedang memulihkan database. Silakan coba kembali setelah selesai.');};
    if(is_file($paths['maintenance']))$blocked();
    try{$lock=database_access_lock($paths,LOCK_SH|LOCK_NB);}catch(Throwable $e){$blocked();}
    if(!$lock)$blocked();
    register_shutdown_function(static fn()=>database_access_release($lock));
    if(is_file($paths['maintenance']))$blocked();
    database_access_session($database,$port);
}
function database_access_session(string $database,int $port): void {
    if(session_status()===PHP_SESSION_ACTIVE){
        $paths=database_access_paths($database,$port);
        $generation=is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0';
        if(!empty($_SESSION['admin_id'])&&($_SESSION['database_generation']??'0')!==$generation){
            session_unset();session_destroy();
            $prefix=in_array(basename(dirname($_SERVER['SCRIPT_NAME']??'')),['pembayaran','siswa','tabungan','laporan'],true)?'../':'';
            header('Location: '.$prefix.'login.php');exit;
        }
        $_SESSION['database_generation']=$generation;
    }
}
