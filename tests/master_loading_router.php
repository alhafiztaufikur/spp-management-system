<?php
/** Loopback-only benchmark router. Every page runs inside a read-only transaction. */
if(PHP_SAPI!=='cli-server'||!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)
    ||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))||(int)getenv('SPP_DB_PORT')===3306){http_response_code(404);exit;}
$root=realpath((string)getenv('SPP_PERF_WEBROOT'));$audit=realpath((string)getenv('SPP_PERF_AUDIT_ROOT'));$variant=getenv('SPP_PERF_VARIANT');
if(!$root||!$audit||str_starts_with(strtolower(str_replace('\\','/',$audit)),'c:/laragon/www/')||!in_array($variant,['before','after'],true))throw new RuntimeException('Use a private audit directory outside the web root.');
$root=str_replace('\\','/',$root);$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));$file=realpath($root.$path);
if(!$file||!str_starts_with(str_replace('\\','/',$file),$root.'/')){http_response_code(404);exit;}
if(pathinfo($file,PATHINFO_EXTENSION)!=='php')return false;
if($_SERVER['REQUEST_METHOD']!=='GET'||!in_array($path,['/master_spp.php','/master_daftar_ulang.php'],true)){http_response_code(404);exit;}
$_SERVER['SCRIPT_FILENAME']=$file;$_SERVER['SCRIPT_NAME']=$path;$_SERVER['PHP_SELF']=$path;
$started=microtime(true);$baseLevel=ob_get_level();ob_start();
register_shutdown_function(static function()use($started,$baseLevel,$audit,$variant):void{
    while(ob_get_level()>$baseLevel+1)ob_end_flush();$html=ob_get_clean();$case=$_SERVER['HTTP_X_AUDIT_CASE']??'';
    if(preg_match('/^[a-z0-9_-]+$/D',$case)){
        $names=str_contains($_SERVER['SCRIPT_NAME'],'master_spp')?['students','studentTariffs','stats','rates','rateVersion','activeStudentCount','publishedStudentCount','pendingStudentCount']:['masters','masterIds','activeByClass','issuedByClass','issuedCountByClass','activeIssuedByClass','coveredByClass','duUnknownByClass','billSummary','masterBases','canEdit'];
        $model=[];foreach($names as $name)$model[$name]=$GLOBALS[$name]??null;
        $dir=$audit.'/'.$variant;if(!is_dir($dir))mkdir($dir);
        $summary=[];foreach($GLOBALS['report_sql_events']??[] as $event){$sql=preg_replace('/\s+/',' ',$event['sql']);$summary[$sql]=($summary[$sql]??0)+1;}arsort($summary);
        file_put_contents($dir.'/'.$case.'.json',json_encode(['ms'=>round((microtime(true)-$started)*1000,3),'queries'=>$GLOBALS['report_sql_count']??0,'sql_ms'=>$GLOBALS['report_sql_ms']??0,'peak'=>memory_get_peak_usage(true),'phases'=>$GLOBALS['report_read_phases']??[],'repeated'=>array_slice($summary,0,8,true),'model'=>$model],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/'.$case.'.html',$html);
    }
    if(isset($GLOBALS['koneksi']))$GLOBALS['koneksi']->rollback();echo $html;
});
session_start();require_once $root.'/koneksi.php';session_write_close();
$koneksi->query('START TRANSACTION READ ONLY');chdir($root);require $file;
