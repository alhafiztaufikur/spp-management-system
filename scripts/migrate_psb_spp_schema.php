<?php
if(PHP_SAPI!=='cli')exit(1);
$o=getopt('',['database:','port:','mode:','backup-proof:']);$name=$o['database']??'';$port=(int)($o['port']??3306);$mode=$o['mode']??'preview';
if(!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$name)||!in_array($mode,['preview','apply'],true))throw new InvalidArgumentException('Gunakan --database=db_spp_test3 --port=3306 --mode=preview|apply --backup-proof=PRIVATE_FILE');
if($port<1||$port>65535)throw new InvalidArgumentException('Port tidak sah.');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$name);putenv('SPP_DB_PORT='.$port);
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';
require_once __DIR__.'/../includes/spp_student_tariff_schema.php';require_once __DIR__.'/../includes/spp_student_tariff.php';
require_once __DIR__.'/../includes/daftar_ulang.php';require_once __DIR__.'/../includes/other_fee_billing_schema.php';require_once __DIR__.'/../includes/psb_spp_schema.php';
$target=lmr_target($koneksi);if($target['database_name']!==$name||(int)$target['port']!==$port)throw new RuntimeException('Target berbeda.');
$steps=pspp_schema_plan($koneksi);$changes=0;
$tariffPreflight=sct_constraint_inspect($koneksi,true);if($tariffPreflight['conflicts']||$tariffPreflight['invalid'])throw new RuntimeException('Konflik tarif individual: '.json_encode($tariffPreflight,JSON_THROW_ON_ERROR));
ofb_schema_plan($koneksi,true);
$prerequisites=['tariff'=>!sct_ready($koneksi),'du'=>!du_advance_schema_ready($koneksi),'du_psb'=>!du_psb_schema_ready($koneksi),'other_fee'=>!ofb_schema_ready($koneksi)];
if($mode==='apply'&&($steps||in_array(true,$prerequisites,true))){
    $lock=database_access_lock(database_access_paths($name,$port),LOCK_EX);
    try{
        lmr_backup_check($koneksi,$o['backup-proof']??'');$before=lmr_snapshot($koneksi);
        if($prerequisites['tariff'])$changes+=sct_schema_apply($koneksi);
        if($prerequisites['du']){du_advance_schema_apply($koneksi);$changes++;}
        if($prerequisites['du_psb'])$changes+=du_psb_schema_apply($koneksi);
        if($prerequisites['other_fee'])$changes+=ofb_schema_apply($koneksi);
        $changes+=pspp_schema_apply($koneksi);
        if(lmr_snapshot($koneksi)!==$before)throw new RuntimeException('Data terlindungi berubah.');
    }finally{database_access_release($lock);}
}
echo json_encode(['target'=>$target,'mode'=>$mode,'changes'=>$changes,'pending'=>pspp_schema_plan($koneksi),'prerequisites'=>$prerequisites,'steps'=>$steps,'backfill'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
