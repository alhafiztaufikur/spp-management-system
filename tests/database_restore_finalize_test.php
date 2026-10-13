<?php
/** Reproduce a worker exit after audit success but before maintenance/session cleanup. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/database_restore.php';
spp_test_assert_database_target($koneksi);$c=db_backup_config();
if(empty($c['allowed_test'])||empty($c['isolated_instance_manifest'])||!db_backup_service_registered($c))throw new RuntimeException('Finalize tests require an owned isolated source.');
db_backup_assert_application($c,$koneksi);$db=db_restore_store();$row=$db->query("SELECT * FROM restores WHERE state='rolled_back' ORDER BY updated_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$row)throw new RuntimeException('Run the isolated restore integration suite first.');
$paths=database_access_paths($c['database'],(int)$c['port']);if(is_file($paths['maintenance']))throw new RuntimeException('Another maintenance is active.');
$fingerprint=static function(mysqli $db):array{$rows=[];foreach($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all() as [$table])$rows[$table]=$db->query('CHECKSUM TABLE `'.$table.'` EXTENDED')->fetch_assoc()['Checksum'];return $rows;};
$before=$fingerprint($koneksi);$generation=trim(file_get_contents($paths['generation']));
$lock=database_access_lock($paths,LOCK_EX|LOCK_NB);if(!$lock)throw new RuntimeException('Fixture access lock busy.');
try{db_backup_atomic_json($paths['maintenance'],['id'=>$row['id'],'server_uuid'=>$c['server_uuid'],'destructive'=>true,'safety_id'=>$row['safety_id']]);}finally{database_access_release($lock);}
db_restore_dispatch($row['id']);$until=time()+30;
do{clearstatcache();if(!is_file($paths['maintenance']))break;usleep(300000);}while(time()<$until);
if(is_file($paths['maintenance'])||trim(file_get_contents($paths['generation']))===$generation||db_restore_find($db,$row['id'])['state']!=='rolled_back'||$fingerprint($koneksi)!==$before)throw new RuntimeException('Terminal finalization did not preserve rows/state and release maintenance.');
echo "PASS: interrupted terminal cleanup resumes, invalidates old sessions and reopens access without reimporting data\n";
