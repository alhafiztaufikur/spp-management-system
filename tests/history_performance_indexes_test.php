<?php
/** Explicitly authorized optional indexes on a verified disposable restore only. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/menu_performance.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';require_once __DIR__.'/../includes/database_backup.php';
$db=$koneksi;$audit=getenv('SPP_HISTORY_AUDIT_ROOT');$initial=json_decode(file_get_contents($audit.'/data-before.json'),true,512,JSON_THROW_ON_ERROR);
$identity=$db->query('SELECT @@server_uuid uuid,@@port port,@@datadir datadir,@@global.event_scheduler scheduler')->fetch_assoc();
if((int)$identity['port']===3306||$identity['uuid']!==$initial['3320']['identity']['uuid']||$identity['scheduler']!=='OFF')throw new RuntimeException('Verified isolated server required.');
$before=lmr_database_checksums($db);$original=$initial['3320']['checksums'];$check=$before;unset($check['legacy_op_payments_data'],$original['legacy_op_payments_data']);if($check!==$original)throw new RuntimeException('Clone data differs from the verified starting snapshot.');
$proof=json_decode(file_get_contents($audit.'/target-backup-proof.json'),true,512,JSON_THROW_ON_ERROR);$verified=json_decode(file_get_contents(dirname($proof['backup_file']).'/verification.json'),true,512,JSON_THROW_ON_ERROR);
$fingerprint=menu_performance_payment_fingerprint($db);if($fingerprint['sha256']!==$verified['fingerprints']['legacy_op_payments_data']||$fingerprint['count']!==$verified['table_counts']['legacy_op_payments_data'])throw new RuntimeException('Visible payment data differs from the verified restore.');
$schema=db_backup_schema($db);$snapshot=lmr_snapshot($db);$changes=menu_performance_apply($db,'history');
$after=lmr_database_checksums($db);$check=$after;$expected=$before;unset($check['legacy_op_payments_data'],$expected['legacy_op_payments_data']);
if($check!==$expected||lmr_snapshot($db)!==$snapshot||menu_performance_payment_fingerprint($db)!==$fingerprint)throw new RuntimeException('Index installation changed protected data.');
if(menu_performance_apply($db,'history')!==[])throw new RuntimeException('Index replay was not empty.');
$actual=db_backup_schema($db);if(!menu_performance_schema_compatible($actual,$schema))throw new RuntimeException('Backup schema compatibility changed.');
$backupSchema=json_decode(file_get_contents(dirname($proof['backup_file']).'/schema.json'),true,512,JSON_THROW_ON_ERROR);
$paymentSchema=static fn($s)=>['objects'=>['tables'=>['legacy_op_payments_data'=>$s['objects']['tables']['legacy_op_payments_data']]]];
if(!menu_performance_schema_compatible($paymentSchema($actual),$paymentSchema($backupSchema)))throw new RuntimeException('Pre-index payment schema compatibility changed.');
foreach(menu_performance_plan($db,'history') as $index)if($index['state']!=='ready')throw new RuntimeException('Missing history index.');
$wrong=$actual;$wrong['objects']['tables']['legacy_op_audit_data']['ddl'].=' unrelated';if(menu_performance_schema_compatible($wrong,$schema))throw new RuntimeException('Unrelated schema was ignored.');
file_put_contents($audit.'/index-clone-final.json',json_encode(['passed'=>true,'identity'=>$identity,'changes'=>$changes,'replay'=>0,'unchanged_checksums'=>count($check),'payment_columns'=>$fingerprint,'checksums'=>$after],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'PASS optional indexes, replay, backup compatibility; 66 checksums and all original payment columns unchanged.'."\n";
