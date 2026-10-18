<?php
/** Private render cache. Callers MUST authenticate and build a fresh, scoped model first. */
function report_perf_mark(string $phase):void {
    $now=microtime(true);$last=$GLOBALS['report_perf_last']??($_SERVER['REQUEST_TIME_FLOAT']??$now);
    $elapsed=max(0,($now-$last)*1000);$sql=(float)($GLOBALS['report_sql_ms']??0);$query=max(0,$sql-($GLOBALS['report_perf_sql_last']??0));
    if($phase==='query_model'&&isset($GLOBALS['report_sql_ms'])){
        $GLOBALS['report_perf']['query']=($GLOBALS['report_perf']['query']??0)+$query;
        $phase='model';$elapsed=max(0,$elapsed-$query);
    }
    $GLOBALS['report_perf'][$phase]=($GLOBALS['report_perf'][$phase]??0)+$elapsed;$GLOBALS['report_perf_last']=$now;$GLOBALS['report_perf_sql_last']=$sql;
}
function report_artifact_valid(string $bytes,string $format):bool {
    return $format==='pdf'?str_starts_with($bytes,'%PDF-')&&str_contains(substr($bytes,-64),'%%EOF'):str_starts_with($bytes,'PK');
}
function report_artifact_root():?string {
    $app=realpath(__DIR__.'/..');$base=getenv('SPP_REPORT_CACHE_DIR');
    if($base===false||$base==='')$base=sys_get_temp_dir().'/spp-report-cache-'.substr(hash('sha256',$app),0,12);
    if(is_link($base)||(!is_dir($base)&&!@mkdir($base,0700,true)))return null;
    $real=realpath($base);$normal=static fn($p)=>strtolower(str_replace('\\','/',$p));
    if(!$real||!is_writable($real))return null;
    $webroot=realpath($_SERVER['DOCUMENT_ROOT']??'');
    foreach(array_filter([$app,$webroot]) as $publicRoot)if(str_starts_with($normal($real).'/',rtrim($normal($publicRoot),'/').'/'))return null;
    return $real;
}
function report_artifact_prune(string $dir):void {
    $files=[];
    foreach(glob($dir.'/*.json')?:[] as $path){if(is_link($path))continue;$files[$path]=(int)@filemtime($path);}
    arsort($files);$i=0;
    foreach($files as $path=>$created){if(++$i<=32&&$created>=time()-900)continue;
        $stem=substr($path,0,-5);
        if((int)@filemtime($path)!==$created)continue;
        foreach(['.pdf','.xlsx','.json'] as $ext)if(!is_link($stem.$ext)&&is_file($stem.$ext))@unlink($stem.$ext);
    }
    // Clean abandoned atomic writes as well as artifacts without metadata after a crashed worker.
    foreach(glob($dir.'/*')?:[] as $path){if(is_link($path)||!is_file($path)||(int)@filemtime($path)>=time()-900)continue;
        $name=basename($path);if(preg_match('/^[a-f0-9]{64}\.(pdf|xlsx)$/D',$name)&&!is_file(substr($path,0,strrpos($path,'.')).'.json'))@unlink($path);
    }
    foreach(['.render-*','.meta-*'] as $pattern)foreach(glob($dir.'/'.$pattern)?:[] as $path)if(!is_link($path)&&is_file($path)&&(int)@filemtime($path)<time()-900)@unlink($path);
}
function report_artifact_get(array $identity,array $model,string $format,callable $producer,bool $cacheEnabled=true):array {
    if(!in_array($format,['pdf','xlsx'],true))throw new InvalidArgumentException('Format laporan tidak valid.');
    report_perf_mark('layout');
    $account=['actor'=>(int)($_SESSION['admin_id']??0),'role'=>(string)($_SESSION['admin_role']??''),
        'database'=>[defined('DB_HOST')?DB_HOST:'',defined('DB_NAME')?DB_NAME:'',getenv('SPP_DB_PORT')?:3306]];
    static $version=null;
    if($version===null){$version='report-original-layout-v2';foreach(['excel.php','authorization_pdf.php','transaction_report_pdf.php','global_report_matrix.php','global_report_layout.php','global_report_ui.php','report_pdf_text.php','date_format.php','report_artifact.php','../laporan/export_global.php','../laporan/export_pdf.php','../laporan/export_excel.php','../otorisasi_export_pdf.php','../composer.lock','../assets/img/school-logo.png','../assets/img/favicon.png'] as $file)if(is_file(__DIR__.'/'.$file))$version.=hash_file('sha256',__DIR__.'/'.$file);}
    $key=hash('sha256',json_encode(['version'=>$version,'format'=>$format,'identity'=>$identity,'account'=>$account,'model'=>$model],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    $owner=$account;unset($owner['role']);
    $root=$cacheEnabled?report_artifact_root():null;$dir=$root?$root.'/'.substr(hash('sha256',json_encode($owner)),0,24):null;$lock=null;
    if($dir&&(is_link($dir)||(!is_dir($dir)&&!@mkdir($dir,0700))))$dir=null;
    if($dir){
        // A bounded set of locks avoids accumulating one coordination file per data revision.
        $lockPath=$dir.'/.lock-'.(hexdec(substr($key,0,2))%64);
        if(!is_link($lockPath))$lock=@fopen($lockPath,'c');
        if(!$lock||!flock($lock,LOCK_EX)){$dir=null;if(is_resource($lock))fclose($lock);$lock=null;}
    }
    try {
        $path=$dir?$dir.'/'.$key.'.'.$format:null;$metaPath=$dir?$dir.'/'.$key.'.json':null;
        if($path&&!is_link($path)&&!is_link($metaPath)&&is_file($path)&&is_file($metaPath)){
            $meta=json_decode((string)@file_get_contents($metaPath),true);$bytes=@file_get_contents($path);
            if(is_string($bytes)&&($meta['created']??0)>=time()-900&&($meta['size']??0)===strlen($bytes)&&hash_equals((string)($meta['digest']??''),hash('sha256',$bytes))&&report_artifact_valid($bytes,$format)){
                report_perf_mark('cache');return ['bytes'=>$bytes,'cache'=>'HIT','created'=>$meta['created'],'fingerprint'=>$key];
            }
        }
        report_perf_mark('cache');
        try{$bytes=$producer();if(!is_string($bytes)||!report_artifact_valid($bytes,$format))throw new RuntimeException('Berkas laporan belum dapat diselesaikan.');}
        catch(Throwable $error){
            if(PHP_SAPI==='cli')throw $error;
            error_log('Report generation: '.$error->getMessage());http_response_code(503);header('Cache-Control: no-store, private');header('Content-Type: text/plain; charset=UTF-8');
            exit('Laporan belum dapat disiapkan. Silakan coba lagi.');
        }
        $created=time();$status='BYPASS';
        if($path){$temp=@tempnam($dir,'.render-');$metaTemp=@tempnam($dir,'.meta-');
            try{
                if($temp&&$metaTemp&&@file_put_contents($temp,$bytes)!==false&&@file_put_contents($metaTemp,json_encode(['created'=>$created,'size'=>strlen($bytes),'digest'=>hash('sha256',$bytes)]))!==false){
                    @chmod($temp,0600);@chmod($metaTemp,0600);
                    // Windows cannot replace an existing destination with rename reliably.
                    if(is_file($path)&&!is_link($path))@unlink($path);if(is_file($metaPath)&&!is_link($metaPath))@unlink($metaPath);
                    if(@rename($temp,$path)&&@rename($metaTemp,$metaPath))$status='MISS';
                }
            }finally{foreach([$temp,$metaTemp] as $tmp)if($tmp&&is_file($tmp))@unlink($tmp);}
            report_artifact_prune($dir);
        }
        report_perf_mark('write');return ['bytes'=>$bytes,'cache'=>$status,'created'=>$created,'fingerprint'=>$key];
    }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
function report_artifact_stream(array $artifact,string $filename,string $format,bool $attachment):void {
    header('Content-Type: '.($format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
    header('Content-Disposition: '.($attachment?'attachment':'inline').'; filename="'.preg_replace('/[^a-zA-Z0-9_.-]/','-',$filename).'"');
    header('Cache-Control: no-store, private');header('X-Report-Cache: '.$artifact['cache']);header('X-Report-Fingerprint: '.$artifact['fingerprint']);
    header('X-Report-Generated-At: '.gmdate('c',$artifact['created']));
    $timings=[];foreach($GLOBALS['report_perf']??[] as $phase=>$ms)$timings[]=preg_replace('/[^a-z_]/','',$phase).';dur='.round($ms,2);
    if($timings)header('Server-Timing: '.implode(', ',$timings));header('Content-Length: '.strlen($artifact['bytes']));echo $artifact['bytes'];exit;
}
