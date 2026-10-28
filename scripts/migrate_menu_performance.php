<?php
/** Additive indexes only; no balances, bills or historical records are changed. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/menu_performance.php';
$apply=in_array('--apply',$argv,true);
if($apply){
    require_once __DIR__.'/../includes/database_access.php';
    $access=database_access_lock(database_access_paths(DB_NAME,(int)$koneksi->query('SELECT @@port')->fetch_row()[0]),LOCK_EX);
    if(!$access)throw new RuntimeException('Kunci pemeliharaan tidak tersedia.');
    register_shutdown_function(static fn()=>database_access_release($access));
    $proof='';foreach($argv as $arg)if(str_starts_with($arg,'--backup-proof='))$proof=substr($arg,15);
    require_once __DIR__.'/../includes/legacy_full.php';
    require_once __DIR__.'/../includes/legacy_migration_verification.php';
    $verified=lmr_backup_check($koneksi,$proof);
    $before=menu_performance_payment_fingerprint($koneksi);
    $file=dirname($verified['backup_file']).'/verification.json';
    $verification=is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[];
    if(($verification['sql_sha256']??'')!==$verified['sha256']||($verification['fingerprint_algorithms']['legacy_op_payments_data']??'')!=='sha256-json-lines-primary-key-v1'
        ||($verification['fingerprints']['legacy_op_payments_data']??'')!==$before['sha256']||($verification['table_counts']['legacy_op_payments_data']??-1)!==$before['count'])throw new RuntimeException('Fingerprint seluruh kolom pembayaran berbeda dari backup terverifikasi.');
    $applied=menu_performance_apply($koneksi);
    foreach($applied as $index)echo 'APPLY '.DB_NAME.'.'.$index['table'].' ('.$index['index'].': '.$index['columns'].")\n";
    $after=menu_performance_payment_fingerprint($koneksi);$checksums=lmr_database_checksums($koneksi);
    if($before!==$after||array_keys($checksums)!==array_keys($verified['database_checksums'])||lmr_snapshot($koneksi)!==$verified['snapshot'])throw new RuntimeException('Data berubah selama pemasangan indeks.');
    $changed=array_keys(array_filter($checksums,static fn($value,$table)=>($verified['database_checksums'][$table]??null)!==$value,ARRAY_FILTER_USE_BOTH));
    if($changed)throw new RuntimeException('Checksum tabel berubah selama pemasangan indeks.');
    echo 'VERIFIED visible-column SHA256 '.$after['sha256'].' rows='.$after['count']."; protected fingerprints unchanged\n";
    echo 'VERIFIED all table checksums identical; index changes='.count($applied)."\n";
}
$plan=menu_performance_plan($koneksi);
foreach($plan as $index)echo match($index['state']){
    'ready'=>'READY '.$index['index'],
    'missing'=>'DRY RUN '.DB_NAME.'.'.$index['table'].' ('.$index['index'].': '.$index['columns'].')',
    default=>'SKIP '.$index['table'].' is unavailable',
}."\n";
if(in_array('--strict',$argv,true)&&array_filter($plan,static fn($index)=>$index['state']==='missing'))exit(2);
