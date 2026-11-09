<?php
if(PHP_SAPI!=='cli')exit(1);
$o=getopt('',['database:','port:','mode:','backup-proof:']);$name=$o['database']??'';$port=(int)($o['port']??3306);$mode=$o['mode']??'preview';
if(!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$name)||$port<1||$port>65535||!in_array($mode,['preview','apply'],true))throw new InvalidArgumentException('Gunakan --database=db_spp_test3 --port=3306 --mode=preview|apply --backup-proof=PRIVATE_FILE');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$name);putenv('SPP_DB_PORT='.$port);
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';require_once __DIR__.'/../includes/spp_advance.php';
$db=$koneksi;$target=lmr_target($db);if($target['database_name']!==$name||(int)$target['port']!==$port)throw new RuntimeException('Identitas target berbeda.');
if(!pspp_ready($db))throw new RuntimeException('Pasang prasyarat SPP PSB melalui installer resminya dahulu.');
$steps=spa_schema_plan($db);$changes=0;
if($mode==='apply'&&$steps){
    $lock=database_access_lock(database_access_paths($name,$port),LOCK_EX);
    try{lmr_backup_check($db,$o['backup-proof']??'');$before=lmr_snapshot($db);$changes=spa_schema_apply($db);if(lmr_snapshot($db)!==$before)throw new RuntimeException('Data terlindungi berubah.');}
    finally{database_access_release($lock);}
}
echo json_encode(['target'=>$target,'mode'=>$mode,'changes'=>$changes,'steps'=>$steps,'pending'=>spa_schema_plan($db),'backfill'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
