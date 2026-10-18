<?php
/** Private artifact behaviour; no database or school records. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../includes/report_artifact.php';
$root=getenv('SPP_QA_DIR');if(!$root||!is_dir($root)||str_contains(strtolower(str_replace('\\','/',$root)),'/www/'))throw new RuntimeException('Private QA directory required.');
$cache=$root.'/cache-unit-'.bin2hex(random_bytes(3));putenv('SPP_REPORT_CACHE_DIR='.$cache);$_SESSION=['admin_id'=>99001,'admin_role'=>'super_admin'];$checks=0;$calls=0;
function rc_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$producer=static function()use(&$calls):string{$calls++;return "%PDF-1.4\ncache probe\n%%EOF";};$identity=['route'=>'test','unit'=>1];$model=['rows'=>[['nis'=>'000001','amount'=>1000]]];
$a=report_artifact_get($identity,$model,'pdf',$producer);$b=report_artifact_get($identity,$model,'pdf',$producer);rc_check($a['cache']==='MISS'&&$b['cache']==='HIT'&&$calls===1,'Identical data uses one render');
$changed=$model;$changed['rows'][0]['amount']=2000;$c=report_artifact_get($identity,$changed,'pdf',$producer);rc_check($c['cache']==='MISS'&&$c['fingerprint']!==$a['fingerprint'],'New financial contents invalidate');
$d=report_artifact_get(['route'=>'test','unit'=>2],$model,'pdf',$producer);rc_check($d['fingerprint']!==$a['fingerprint'],'Scope isolated');
$_SESSION['admin_id']=99002;$d=report_artifact_get($identity,$model,'pdf',$producer);rc_check($d['cache']==='MISS'&&$d['fingerprint']!==$a['fingerprint'],'Account isolated');
$_SESSION['admin_id']=99001;$_SESSION['admin_role']='admin';$d=report_artifact_get($identity,$model,'pdf',$producer);rc_check($d['cache']==='MISS'&&$d['fingerprint']!==$a['fingerprint'],'Permission context isolated');$_SESSION['admin_role']='super_admin';
$path=(glob($cache.'/*/'.$a['fingerprint'].'.pdf')?:[])[0];file_put_contents($path,"%PDF-1.4\ncorrupt content\n%%EOF");$d=report_artifact_get($identity,$model,'pdf',$producer);rc_check($d['cache']==='MISS','Digest catches structurally similar corruption');
$meta=substr($path,0,-4).'.json';$value=json_decode(file_get_contents($meta),true);$value['created']=time()-901;file_put_contents($meta,json_encode($value));$d=report_artifact_get($identity,$model,'pdf',$producer);rc_check($d['cache']==='MISS','Expired files regenerate');
$xls=report_artifact_get($identity,$model,'xlsx',static fn()=>"PK\x03\x04probe");rc_check($xls['fingerprint']!==$a['fingerprint'],'Formats have separate identities');
for($i=0;$i<36;$i++)report_artifact_get($identity,['rows'=>[$i]],'pdf',$producer);
rc_check(count(glob(dirname($path).'/*.json'))<=32,'Bounded 32 artifacts per user');
$orphan=dirname($path).'/'.str_repeat('f',64).'.pdf';$abandoned=dirname($path).'/.render-abandoned';file_put_contents($orphan,'orphan');file_put_contents($abandoned,'incomplete');touch($orphan,time()-901);touch($abandoned,time()-901);report_artifact_prune(dirname($path));rc_check(!file_exists($orphan)&&!file_exists($abandoned),'Abandoned/crashed writes expire');
$documentRoot=$_SERVER['DOCUMENT_ROOT']??null;$_SERVER['DOCUMENT_ROOT']=$root.'/public-test';if(!is_dir($_SERVER['DOCUMENT_ROOT']))mkdir($_SERVER['DOCUMENT_ROOT']);putenv('SPP_REPORT_CACHE_DIR='.$_SERVER['DOCUMENT_ROOT'].'/cache');$d=report_artifact_get($identity,$model,'pdf',$producer);rc_check($d['cache']==='BYPASS','Configured directory inside webroot is rejected');if($documentRoot===null)unset($_SERVER['DOCUMENT_ROOT']);else $_SERVER['DOCUMENT_ROOT']=$documentRoot;
$block=$root.'/cache-blocker';file_put_contents($block,'not a directory');putenv('SPP_REPORT_CACHE_DIR='.$block);$d=report_artifact_get($identity,$model,'pdf',$producer);rc_check($d['cache']==='BYPASS'&&str_starts_with($d['bytes'],'%PDF-'),'Unavailable cache falls back to rendering');
try{report_artifact_get($identity,$model,'pdf',static fn()=>'<html>error</html>');throw new RuntimeException('Bad producer accepted');}catch(RuntimeException $e){rc_check(str_contains($e->getMessage(),'belum dapat'),'Invalid output never becomes a download');}
putenv('SPP_REPORT_CACHE_DIR='.$root.'/cache-concurrent-'.bin2hex(random_bytes(3)));file_put_contents($root.'/concurrent-producers.txt','');$workers=[];
for($i=0;$i<5;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/report_cache_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];}
$results=[];foreach($workers as [$proc,$pipes]){$result=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);rc_check(proc_close($proc)===0&&$error==='','Concurrent worker succeeds');$results[]=json_decode($result,true);}
rc_check(count(file($root.'/concurrent-producers.txt'))===1,'Five simultaneous requests render once');rc_check(count(array_unique(array_column($results,'fingerprint')))===1,'Concurrent requests receive identical model identity');
file_put_contents($root.'/cache-verification.json',json_encode(['checks'=>$checks],JSON_PRETTY_PRINT));echo "PASS $checks private cache checks\n";
