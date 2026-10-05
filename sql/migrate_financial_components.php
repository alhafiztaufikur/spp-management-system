<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/readiness_migration_guard.php';
require_once __DIR__.'/../includes/financial_components_schema.php';
echo 'PREFLIGHT database='.DB_NAME.' ready='.(int)financial_components_ready($koneksi).PHP_EOL;
financial_components_preflight($koneksi);
if(!in_array('--apply',$argv,true))exit;
readiness_migration_assert_apply_allowed($argv,DB_NAME);
if(DB_NAME==='db_spp'){
 if(!is_file(__DIR__.'/../tmp/financial_migration.lock'))throw new RuntimeException('Main maintenance required');
 $storage=getenv('SPP_LEGACY_STORAGE')?:'C:/laragon/data/spp-legacy-import';
 $file=rtrim($storage,'/\\').'/db_spp/heartbeat.json';
 if(is_file($file)){$h=json_decode(file_get_contents($file),true);if(time()-($h['time']??0)<=15)throw new RuntimeException('Stop owned importer worker before DDL');}
}
$lock='spp.components.'.DB_NAME;
$s=$koneksi->prepare('SELECT GET_LOCK(?,0)');$s->bind_param('s',$lock);$s->execute();if((int)$s->get_result()->fetch_row()[0]!==1)throw new RuntimeException('Another component migration is running');$s->close();
try{financial_components_apply($koneksi);}finally{$s=$koneksi->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();$s->close();}

