<?php
if(PHP_SAPI!=='cli')exit(1);
$o=getopt('',['database:','port:','mode:','backup-proof:']);$name=$o['database']??'';$port=(int)($o['port']??3306);$mode=$o['mode']??'preview';
if(!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$name)||!in_array($mode,['preview','apply'],true)||$port<1||$port>65535)throw new InvalidArgumentException('Gunakan --database=db_spp --port=3306 --mode=preview|apply --backup-proof=PRIVATE_FILE');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$name);putenv('SPP_DB_PORT='.$port);
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/other_fee_billing_schema.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';
$target=lmr_target($koneksi);if($target['database_name']!==$name||(int)$target['port']!==$port)throw new RuntimeException('Target berbeda.');
$plan=ofb_schema_plan($koneksi,true);$changes=0;
if($mode==='apply'&&$plan){$lock=database_access_lock(database_access_paths($name,$port),LOCK_EX);try{lmr_backup_check($koneksi,$o['backup-proof']??'');$changes=ofb_schema_apply($koneksi);}finally{database_access_release($lock);}}
echo json_encode(['target'=>$target,'mode'=>$mode,'changes'=>$changes,'pending'=>count(ofb_schema_plan($koneksi)),'steps'=>$plan],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
