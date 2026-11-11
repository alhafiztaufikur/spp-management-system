<?php
/** Loopback-only read-only harness; models containing student data stay outside the web root. */
if(PHP_SAPI!=='cli-server'||!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)
    ||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))||(int)getenv('SPP_DB_PORT')<=0||(int)getenv('SPP_DB_PORT')===3306){http_response_code(404);exit;}
$root=realpath((string)getenv('SPP_FILTER_WEBROOT'));$audit=realpath((string)getenv('SPP_FILTER_AUDIT_ROOT'));$variant=getenv('SPP_FILTER_VARIANT');
if(!$root||!$audit||str_starts_with(strtolower(str_replace('\\','/',$audit)),'c:/laragon/www/')||!in_array($variant,['before','after'],true))throw new RuntimeException('Private audit setup required.');
$root=str_replace('\\','/',$root);$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));$file=realpath($root.$path);
if(!$file||!str_starts_with(str_replace('\\','/',$file),$root.'/')){http_response_code(404);exit;}
if(pathinfo($file,PATHINFO_EXTENSION)!=='php'){if(str_starts_with($path,'/assets/'))return false;http_response_code(404);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET'||!in_array($path,['/pembayaran/lihat.php','/pembayaran/riwayat_daftar_ulang.php','/pembayaran/history_search.php','/laporan/index.php','/laporan/template.php','/laporan/export_global.php','/laporan/export_excel.php','/laporan/export_pdf.php'],true)){http_response_code(404);exit;}
$_SERVER['SCRIPT_FILENAME']=$file;$_SERVER['SCRIPT_NAME']=$path;$_SERVER['PHP_SELF']=$path;
$level=ob_get_level();ob_start();
register_shutdown_function(static function()use($level,$audit,$variant):void{
    while(ob_get_level()>$level+1)ob_end_flush();$body=ob_get_clean();$key=$_SERVER['HTTP_X_AUDIT_CASE']??'';
    if(preg_match('/^[a-z0-9_-]+$/D',$key)){
        $model=[];foreach(['history','data','report','filters','section','componentTotals','doc'] as $name)
            if(isset($GLOBALS[$name])&&is_array($GLOBALS[$name]))$model[$name]=$GLOBALS[$name];
        file_put_contents($audit.'/'.$variant.'/'.$key.'.json',json_encode($model,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        if(!str_contains(implode(' ',headers_list()),'spreadsheet'))file_put_contents($audit.'/'.$variant.'/'.$key.'.html',$body);
    }
    if(isset($GLOBALS['koneksi']))$GLOBALS['koneksi']->rollback();echo $body;
});
session_start();require_once $root.'/koneksi.php';session_write_close();
$koneksi->query('START TRANSACTION READ ONLY');chdir(dirname($file));require $file;
