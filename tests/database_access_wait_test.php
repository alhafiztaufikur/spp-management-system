<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/database_access.php';require_once __DIR__.'/../includes/database_access_wait.php';
if(($argv[1]??'')==='--request'){
    $_SERVER['REQUEST_METHOD']=$argv[4];$_SERVER['SCRIPT_NAME']=$argv[5];$_SERVER['REQUEST_URI']='/spp-management-system/legacy_operational_review.php?id=test';$_SERVER['SCRIPT_FILENAME']=__DIR__.'/../legacy_operational_review.php';
    register_shutdown_function(static function(){fwrite(STDERR,json_encode(['status'=>http_response_code()?:200]));});
    database_access_http($argv[2],(int)$argv[3]);echo 'READY';exit;
}
function dw_assert(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
$get=database_access_wait_model(false,'GET');$post=database_access_wait_model(false,'POST');$restore=database_access_wait_model(true,'GET');
dw_assert($get['refresh']&&!$post['refresh']&&$get['code']==='migration_in_progress'&&$restore['code']==='database_restoring','Wrong gate status');
$html=database_access_wait_html($get,'/x/?q="<test>','/spp-management-system/');dw_assert(str_contains($html,'http-equiv="refresh"')&&!str_contains($html,'q="<test>')&&str_contains($html,'Finalisasi migrasi sedang berjalan'),'Unsafe or missing wait page');dw_assert(!str_contains(database_access_wait_html($post,'/','/'),'http-equiv="refresh"'),'POST automatically replayed');
foreach(['//example.test',"/x\r\nheader",'/\\example.test','https://example.test'] as $url)dw_assert(database_access_wait_retry_url($url)==='/','Unsafe retry redirect');
$_SERVER['SCRIPT_FILENAME']=realpath(__DIR__.'/../laporan/export_pdf.php');$_SERVER['SCRIPT_NAME']='/spp-management-system/laporan/export_pdf.php';dw_assert(database_access_wait_asset_base()==='/spp-management-system/','Nested asset path wrong');
$name='db_spp_audit_wait_'.bin2hex(random_bytes(8));$paths=database_access_paths($name,58991);$lock=null;
$request=static function(string $method,string $script)use($name):array{$p=proc_open([PHP_BINARY,__FILE__,'--request',$name,'58991',$method,$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$meta=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);dw_assert(proc_close($p)===0,'Child failed: '.$meta);return [$body,json_decode($meta,true)['status']];};
try{
 $lock=database_access_lock($paths,LOCK_EX|LOCK_NB);dw_assert(is_resource($lock),'Fixture lock unavailable');
 [$body,$status]=$request('GET','/spp-management-system/legacy_operational_review.php');dw_assert($status===503&&str_contains($body,'Finalisasi migrasi sedang berjalan')&&str_contains($body,'http-equiv="refresh"'),'Exclusive gate not rendered as migrating');
 [$body,$status]=$request('GET','/spp-management-system/legacy_import_api.php');$json=json_decode($body,true);dw_assert($status===503&&$json['code']==='migration_in_progress'&&$json['retry_after']===10,'API gate is not JSON');
 [$body,$status]=$request('POST','/spp-management-system/pembayaran/proses.php');dw_assert($status===503&&!str_contains($body,'http-equiv="refresh"')&&str_contains($body,'Permintaan ini belum diproses'),'POST wait unsafe');
 file_put_contents($paths['maintenance'],'{}');[$body,$status]=$request('GET','/spp-management-system/login.php');dw_assert($status===503&&str_contains($body,'Pemulihan database sedang berjalan'),'Actual restore mislabeled');unlink($paths['maintenance']);
 dw_assert(database_access_lock($paths,LOCK_SH|LOCK_NB)===null,'Gate removed by waiting response');database_access_release($lock);$lock=null;
 [$body,$status]=$request('GET','/spp-management-system/login.php');dw_assert($status===200&&$body==='READY','Access did not recover on release');
 echo "PASS: real exclusive gate, migration/restore messages, API JSON, safe GET retry, POST no replay, escaped URLs, nested assets and recovery after release.\n";
}finally{database_access_release($lock);foreach([$paths['maintenance'],$paths['lock']] as $file)if(is_file($file))unlink($file);}
