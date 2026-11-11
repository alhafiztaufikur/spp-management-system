<?php
if(PHP_SAPI!=='cli-server'||!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)
    ||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))||(int)getenv('SPP_DB_PORT')<=0||(int)getenv('SPP_DB_PORT')===3306){http_response_code(404);exit;}
$root=realpath((string)getenv('SPP_HISTORY_WEBROOT'));$audit=realpath((string)getenv('SPP_HISTORY_AUDIT_ROOT'));$variant=getenv('SPP_HISTORY_VARIANT');
if(!$root||!$audit||str_starts_with(strtolower(str_replace('\\','/',$audit)),'c:/laragon/www/')||!in_array($variant,['before','after'],true))throw new RuntimeException('Private audit setup required.');
$root=str_replace('\\','/',$root);$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));$file=realpath($root.$path);
if(!$file||!str_starts_with(str_replace('\\','/',$file),$root.'/')){http_response_code(404);exit;}
if(pathinfo($file,PATHINFO_EXTENSION)!=='php'){if(str_starts_with($path,'/assets/'))return false;http_response_code(404);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET'||!in_array($path,['/pembayaran/lihat.php','/pembayaran/riwayat_daftar_ulang.php','/master_biaya_lain.php','/pembayaran/detail_daftar_ulang.php','/pembayaran/detail.php','/pembayaran/history_search.php','/pembayaran/export_history.php','/laporan/cetak_struk.php'],true)){http_response_code(404);exit;}
$_SERVER['SCRIPT_FILENAME']=$file;$_SERVER['SCRIPT_NAME']=$path;$_SERVER['PHP_SELF']=$path;
$started=microtime(true);$level=ob_get_level();ob_start();
register_shutdown_function(static function()use($level,$audit,$variant,$started):void{
    while(ob_get_level()>$level+1)ob_end_flush();$body=ob_get_clean();$key=$_SERVER['HTTP_X_AUDIT_CASE']??'';
    if(preg_match('/^[a-z0-9_-]+$/D',$key)){
        $model=[];foreach(['history','selectedModel','data','chosen','masterRows','masterCounts','activeMasters','activeClasses','activeStudents','operatorOptions'] as $name)
            if(isset($GLOBALS[$name])&&is_array($GLOBALS[$name]))$model[$name]=$GLOBALS[$name];
        if(($_SERVER['HTTP_X_AUDIT_MODEL_SCOPE']??'')==='all'){
            $read=$GLOBALS['history']??$GLOBALS['data']??null;
            if(isset($read['read_sql'])){
                $result=lf_query($GLOBALS['koneksi'],$read['read_sql'],$read['read_args']);$digest=hash_init('sha256');$count=0;
                while($row=$result->fetch_assoc()){ksort($row);hash_update($digest,json_encode($row,JSON_THROW_ON_ERROR)."\n");$count++;}
                $model['all_results']=['count'=>$count,'sha256'=>hash_final($digest)];$result->free();
            }
        }
        $events=$GLOBALS['report_sql_events']??[];usort($events,static fn($a,$b)=>$b['ms']<=>$a['ms']);
        file_put_contents($audit.'/'.$variant.'/'.$key.'.json',json_encode(['ms'=>(microtime(true)-$started)*1000,'queries'=>$GLOBALS['report_sql_count']??0,'sql_ms'=>$GLOBALS['report_sql_ms']??0,'peak'=>memory_get_peak_usage(true),'slow'=>array_slice($events,0,str_starts_with($key,'deep_')?200:4),'model'=>$model],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        file_put_contents($audit.'/'.$variant.'/'.$key.'.html',$body);
    }
    if(isset($GLOBALS['koneksi']))$GLOBALS['koneksi']->rollback();echo $body;
});
session_start();require_once $root.'/koneksi.php';session_write_close();
$koneksi->query('START TRANSACTION READ ONLY');chdir(dirname($file));require $file;
