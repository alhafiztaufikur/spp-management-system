<?php
/** Schema only; financial reconciliation remains in legacy_migration_runner.php. */
if(PHP_SAPI!=='cli')exit(1);
$options=getopt('',['database:','port:','mode:','backup-proof:','output:']);$database=$options['database']??'';$port=(int)($options['port']??3306);$mode=$options['mode']??'preview';
if(!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$database)||!in_array($mode,['preview','apply'],true)||$port<1||$port>65535)throw new InvalidArgumentException('Gunakan --database=db_spp --port=3306 --mode=preview|apply.');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$database);putenv('SPP_DB_PORT='.$port);$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>0];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';$db=$koneksi;
$out=['target'=>lmr_target($db),'mode'=>$mode,'ready'=>du_psb_schema_ready($db),'changes'=>0];
if($out['target']['database_name']!==$database||(int)$out['target']['port']!==$port)throw new RuntimeException('Koneksi target berbeda.');
if($mode==='apply'&&!$out['ready']){
    $access=lful_wait_access(database_access_paths($database,$port),static fn()=>fwrite(STDERR,"Menunggu akses aplikasi...\n"));
    try{lmr_backup_check($db,$options['backup-proof']??'');$before=lmr_snapshot($db);$out['changes']=du_psb_schema_apply($db);if(lmr_snapshot($db)!==$before)throw new RuntimeException('Data terlindungi berubah selama peningkatan skema.');$out['ready']=true;$out['protected_data_preserved']=true;}
    finally{database_access_release($access);}
}
$json=json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);if(isset($options['output']))file_put_contents($options['output'],$json);echo $json.PHP_EOL;
