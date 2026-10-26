<?php
/** Additive feature schema. Preview is the default; no backfill or reconciliation. */
if(PHP_SAPI!=='cli')exit(1);
$o=getopt('',['database:','port:','mode:','backup-proof:']);
$database=$o['database']??'';$port=(int)($o['port']??3306);$mode=$o['mode']??'preview';
if(!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$database)||!in_array($mode,['preview','apply'],true)||$port<1||$port>65535)throw new InvalidArgumentException('Gunakan --database=db_spp_test2 --port=3306 --mode=preview|apply --backup-proof=PRIVATE_FILE.');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$database);putenv('SPP_DB_PORT='.$port);
require __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/spp_student_tariff_schema.php';
require_once __DIR__.'/../includes/spp_student_tariff.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
$target=lmr_target($koneksi);
if($target['database_name']!==$database||(int)$target['port']!==$port)throw new RuntimeException('Identitas target berbeda.');
$ready=sct_ready($koneksi);
$changed=0;
if($mode==='apply'&&!$ready){
    $lock=database_access_lock(database_access_paths($database,$port),LOCK_EX);
    try {lmr_backup_check($koneksi,$o['backup-proof']??'');$changed=sct_schema_apply($koneksi);}
    finally {database_access_release($lock);}
}
echo json_encode(['target'=>$target,'mode'=>$mode,'pending'=>sct_ready($koneksi)?0:1,'changes'=>$changed,'backfill'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
