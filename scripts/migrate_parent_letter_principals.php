<?php
/** Explicit additive installer. No financial reconciliation or HTTP DDL. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
$options=getopt('',['database:','port:','mode:','backup-proof:','output:']);
$database=$options['database']??'';$port=(int)($options['port']??3306);$mode=$options['mode']??'preview';
if(!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$database)||!in_array($mode,['preview','apply'],true)||$port<1||$port>65535)throw new InvalidArgumentException('Gunakan --database=db_spp --port=3306 --mode=preview|apply.');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$database);putenv('SPP_DB_PORT='.$port);
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>0];
require __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/parent_letter_principals_schema.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;$target=lmr_target($db);
if($target['database_name']!==$database||(int)$target['port']!==$port)throw new RuntimeException('Koneksi target berbeda.');
$pending=plp_schema_plan($db);$seeds=plp_seed_pending($db);
$out=['target'=>$target,'mode'=>$mode,'pending_schema'=>$pending,'pending_seed_units'=>$seeds,'schema_changes'=>0,'seeded_units'=>[]];
if($mode==='apply'&&($pending||$seeds)){
    require_once __DIR__.'/../includes/legacy_full.php';
    $access=lful_wait_access(database_access_paths($database,$port),static fn()=>fwrite(STDERR,"Menunggu akses aplikasi...\n"));
    try {
        lmr_backup_check($db,$options['backup-proof']??'');
        $before=lmr_snapshot($db);$checksums=lmr_database_checksums($db);
        $out=array_replace($out,plp_schema_apply($db));
        if(lmr_snapshot($db)!==$before)throw new RuntimeException('Data terlindungi berubah saat pemasangan Kepala Sekolah.');
        $after=lmr_database_checksums($db);
        foreach($checksums as $table=>$checksum)if(!in_array($table,['parent_letter_principals_data','parent_letter_principal_settings_data'],true)&&($after[$table]??null)!==$checksum)throw new RuntimeException('Tabel existing berubah: '.$table);
        $out['protected_data_preserved']=true;$out['pending_schema']=plp_schema_plan($db);$out['pending_seed_units']=plp_seed_pending($db);
    }finally{database_access_release($access);}
}
$out['ready']=plp_schema_ready($db)&&plp_seed_pending($db)===[];
$json=json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
if(isset($options['output']))file_put_contents($options['output'],$json);echo $json.PHP_EOL;
