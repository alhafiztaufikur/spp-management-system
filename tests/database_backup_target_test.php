<?php
/** Pure routing checks: no database, queue, credentials or filesystem mutation. */
require_once __DIR__.'/../includes/database_backup.php';
$originalName=getenv('SPP_DB_NAME');$originalConfig=getenv('SPP_BACKUP_CONFIG');$checks=0;
try {
    putenv('SPP_BACKUP_CONFIG');
    foreach(['db_spp','db_spp_test2','db_spp_test3','db_spp_test12','db_spp_audit_fixture','db_spp_test_fixture'] as $name){
        putenv('SPP_DB_NAME='.$name);
        if(db_backup_config_path()!=='C:/laragon/data/spp-backup/'.$name.'/config.json')throw new RuntimeException('Backup config routed to another database.');
        $checks++;
    }
    foreach(['db_spp_test3/../db_spp','db_spp_test3.extra','other_database','db_spp_test'] as $name){
        putenv('SPP_DB_NAME='.$name);$rejected=false;
        try{db_backup_config_path();}catch(RuntimeException $e){$rejected=true;}
        if(!$rejected)throw new RuntimeException('Invalid database accepted in backup path.');$checks++;
    }
    putenv('SPP_DB_NAME=db_spp_test3');putenv('SPP_BACKUP_CONFIG=C:/private/owned/config.json');
    if(db_backup_config_path()!=='C:/private/owned/config.json')throw new RuntimeException('Explicit backup config override lost.');$checks++;
} finally {
    putenv($originalName===false?'SPP_DB_NAME':'SPP_DB_NAME='.$originalName);
    putenv($originalConfig===false?'SPP_BACKUP_CONFIG':'SPP_BACKUP_CONFIG='.$originalConfig);
}
echo "$checks backup target checks passed; no database access.\n";
