<?php
/** Optional history read indexes. Preview never runs DDL or publishes obligations. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
$o=getopt('',['database:','port:','mode:','backup-proof:']);$name=$o['database']??'';$port=(int)($o['port']??3306);$mode=$o['mode']??'preview';
if(!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$name)||$port<1||$port>65535||!in_array($mode,['preview','apply'],true))throw new InvalidArgumentException('Gunakan --database=db_spp_test3 --port=3306 --mode=preview|apply --backup-proof=PRIVATE_FILE');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$name);putenv('SPP_DB_PORT='.$port);
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/menu_performance.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;$target=lmr_target($db);if($target['database_name']!==$name||(int)$target['port']!==$port)throw new RuntimeException('Identitas target berbeda.');
$plan=menu_performance_plan($db,'history');if(array_filter($plan,static fn($i)=>$i['state']==='unavailable'))throw new RuntimeException('Tabel prasyarat belum tersedia.');$applied=[];
if($mode==='apply'&&array_filter($plan,static fn($i)=>$i['state']==='missing')){
    $lock=database_access_lock(database_access_paths($name,$port),LOCK_EX);
    try{
        $proof=lmr_backup_check($db,$o['backup-proof']??'');
        $visibleBefore=menu_performance_payment_fingerprint($db);
        $applied=menu_performance_apply($db,'history');
        $checksums=lmr_database_checksums($db);$expected=$proof['database_checksums'];unset($checksums['legacy_op_payments_data'],$expected['legacy_op_payments_data']);
        // The functional key creates a hidden virtual field in physical CHECKSUM
        // TABLE. Compare every original visible payment column instead.
        if(lmr_snapshot($db)!==$proof['snapshot']||$checksums!==$expected||menu_performance_payment_fingerprint($db)!==$visibleBefore)throw new RuntimeException('Data berubah selama pemasangan indeks.');
    }finally{database_access_release($lock);}
}
echo json_encode(['target'=>$target,'mode'=>$mode,'applied'=>$applied,'changes'=>count($applied),'pending'=>menu_performance_plan($db,'history'),'backfill'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
