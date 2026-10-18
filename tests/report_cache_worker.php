<?php
if(PHP_SAPI!=='cli')exit(1);require __DIR__.'/../includes/report_artifact.php';
$root=getenv('SPP_QA_DIR');if(!$root||!is_dir($root)||str_contains(strtolower(str_replace('\\','/',$root)),'/www/'))exit(1);
$_SESSION=['admin_id'=>99003,'admin_role'=>'super_admin'];
$artifact=report_artifact_get(['route'=>'concurrent','unit'=>1],['rows'=>[['nis'=>'000001','amount'=>12345]]],'pdf',static function()use($root){file_put_contents($root.'/concurrent-producers.txt',"render\n",FILE_APPEND|LOCK_EX);usleep(400000);return "%PDF-1.4\nconcurrency probe\n%%EOF";});echo json_encode(['cache'=>$artifact['cache'],'fingerprint'=>$artifact['fingerprint']]);
