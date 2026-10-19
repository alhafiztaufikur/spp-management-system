<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/legacy_finance.php';
foreach([null,true,false,0,1.2,'é/雪',[],['x'=>1,0=>'a'],[null,['02'=>'c','q'=>"\n\"\\"]]] as $value){if(lf_json_digest($value)!==hash('sha256',lf_json($value)))throw new RuntimeException('Streaming JSON digest differs.');}
// The logical document is over 256 MiB; the digest must not allocate that document.
ini_set('memory_limit','64M');$leaf=str_repeat('x',131072);$plan=array_fill(0,2048,['description'=>$leaf]);
$first=lf_json_digest($plan);$second=lf_json_digest($plan);
if($first!==$second||memory_get_peak_usage(true)>32*1024*1024)throw new RuntimeException('Migration digest is not bounded/deterministic.');
echo 'PASS: byte-identical JSON digest, deterministic 256 MiB document under 64 MiB limit; peak '.memory_get_peak_usage(true)." bytes.\n";
