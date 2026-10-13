<?php
/** Actual dump/restore runner integration. Requires a separately owned local MySQL instance. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once __DIR__ . '/http_form_scope.php';
require_once __DIR__ . '/../includes/database_backup.php';

function backup_runner_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function backup_runner_process(array $arguments, string $log, array $environment = []): array {
    $prior = [];
    foreach ($environment as $key=>$value) { $prior[$key]=getenv($key); putenv($key.'='.$value); }
    try {
        $pipes=[];
        $process=proc_open($arguments,[0=>['pipe','r'],1=>['file',$log.'.out.log','w'],2=>['file',$log.'.error.log','w']],$pipes);
    } finally {
        foreach ($prior as $key=>$value) putenv($value === false ? $key : $key.'='.$value);
    }
    if (!is_resource($process)) throw new RuntimeException('Test child could not start.');
    fclose($pipes[0]); $started=time();
    do {
        $status=proc_get_status($process);
        if (!$status['running']) break;
        if (time()-$started>1800) { proc_terminate($process); proc_close($process); throw new RuntimeException('Runner test timeout; private diagnostics retained.'); }
        usleep(200000);
    } while (true);
    $exit=$status['exitcode']; $closed=proc_close($process);
    return ['code'=>$exit<0?$closed:$exit,'out'=>(string)file_get_contents($log.'.out.log'),'error'=>(string)file_get_contents($log.'.error.log')];
}
function backup_runner_financial_fingerprint(mysqli $db): array {
    $result=[];
    foreach (['siswa_data','siswa_tahun_ajaran_data','bayar_data','tagihan_spp_data','spp_alokasi_data','tagihan_komite_data','bayar_komite_data','tagihan_daftar_ulang_data','bayar_du_data','tabungan_data','transaksi_m_data','transaksi_k_data'] as $table) {
        $exists=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND TABLE_TYPE='BASE TABLE'")->fetch_row()[0];
        if ($exists) $result[$table]=$db->query('CHECKSUM TABLE `'.$table.'` EXTENDED')->fetch_assoc()['Checksum'];
    }
    backup_runner_assert(count($result)>=8,'Financial fingerprint must cover the physical multiunit tables.');
    return $result;
}

$baseConfigFile=(string)getenv('SPP_BACKUP_CONFIG');
backup_runner_assert($baseConfigFile!=='','Explicit private backup test configuration is required.');
$base=db_backup_config();
backup_runner_assert(($base['allowed_test']??false)===true && !empty($base['isolated_instance_manifest']), 'Runner integration requires an isolated QA configuration.');
backup_runner_assert(db_backup_normal_path((string)realpath((string)getenv('SPP_TEST_ISOLATED_INSTANCE')))===db_backup_normal_path((string)realpath($base['isolated_instance_manifest'])),'Test manifest must match backup configuration.');
$source=db_backup_source($base);
spp_test_assert_database_target($source);
$isolated=spp_test_isolated_manifest($source);
backup_runner_assert($isolated['server_uuid']===$base['server_uuid'] && (int)$isolated['port']===(int)$base['port'],'Backup source must be the owned disposable instance.');
$financialBefore=backup_runner_financial_fingerprint($source);
$suffix=date('Ymd_His').'_'.bin2hex(random_bytes(4));
$artifactRoot=dirname($baseConfigFile).'/storage_runner_'.$suffix;
backup_runner_assert(!file_exists($artifactRoot),'Never reuse an integration artifact directory.');
mkdir($artifactRoot,0700); mkdir($artifactRoot.'/jobs',0700);
$config=$base; unset($config['_config_file']);
$config['storage_root']=$artifactRoot;
$configFile=$artifactRoot.'/config.json';
$ownedAccount=false; $temporaryTable=null; $success=false;
try {
    // Provision the exact export-only grants used by the installer, on the isolated server only.
    $accountCount=(int)$source->query("SELECT COUNT(*) FROM mysql.user WHERE User='spp_backup_local' AND Host='127.0.0.1'")->fetch_row()[0];
    backup_runner_assert($accountCount===0,'Existing export account is not owned by this test and must not be replaced.');
    $password=base64_encode(random_bytes(36));
    $source->query("CREATE USER 'spp_backup_local'@'127.0.0.1' IDENTIFIED BY '$password'"); $ownedAccount=true;
    foreach (["GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON db_spp.* TO 'spp_backup_local'@'127.0.0.1'","GRANT SHOW_ROUTINE ON *.* TO 'spp_backup_local'@'127.0.0.1'","GRANT EXECUTE ON FUNCTION db_spp.current_unit_id TO 'spp_backup_local'@'127.0.0.1'"] as $sql) $source->query($sql);
    $credentials=$artifactRoot.'/source-client.cnf';
    file_put_contents($credentials,"[client]\r\nhost=127.0.0.1\r\nport=".$config['port']."\r\nprotocol=TCP\r\nuser=spp_backup_local\r\npassword=\"$password\"\r\n");
    $password=null; $config['source_defaults_file']=$credentials;
    db_backup_atomic_json($configFile,$config); putenv('SPP_BACKUP_CONFIG='.$configFile);
    $export=db_backup_source(db_backup_config());
    $grants=implode('\n',array_column($export->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM),0));
    backup_runner_assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALL PRIVILEGES)\b/i',$grants),'Export account must not have financial DML privileges.');
    $inventory=db_backup_schema($export); $export->close();
    echo "PASS: isolated identity and least-privilege export account inventory\n";

    $temporaryTable='qa_backup_schema_'.bin2hex(random_bytes(5));
    $source->query('CREATE TABLE `'.$temporaryTable.'` (id INT PRIMARY KEY) ENGINE=InnoDB');
    backup_runner_assert(db_backup_schema($source)['signature']!==$inventory['signature'],'A real schema change must invalidate the inventory signature.');
    $source->query('DROP TABLE `'.$temporaryTable.'`'); $temporaryTable=null;
    backup_runner_assert(db_backup_schema($source)['signature']===$inventory['signature'],'Dropping the owned fixture must restore the original inventory.');
    echo "PASS: actual DDL change detection and restoration on an unrelated isolated table\n";

    $runner=__DIR__.'/../scripts/database_backup_runner.php';
    $args=[$config['php_executable'],$runner,'--config='.$configFile];
    $setup=backup_runner_process([...$args,'--setup'],$artifactRoot.'/setup');
    backup_runner_assert($setup['code']===0,'Runner setup must prepare a private queue.');
    $queue=db_backup_store();
    $full=backup_runner_process([...$args,'--tick'],$artifactRoot.'/full');
    backup_runner_assert($full['code']===0,'Least-privilege full backup/restore failed; inspect private full logs.');
    $good=$queue->query("SELECT * FROM jobs WHERE state='verified' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    backup_runner_assert((bool)$good,'A full runner invocation must yield a verified archive.');
    $goodArchive=db_backup_archive($queue,$good['id']);
    backup_runner_assert($good['archive_name']==='backup.sql' && basename($goodArchive['file'])==='backup.sql' && $good['archive_sha256']===$good['sql_sha256'] && (int)$good['bytes']===filesize($goodArchive['file']),'New backups must retain plain SQL as their primary verified artifact.');
    $download=db_backup_download_archive($queue,$good['id']);
    backup_runner_assert(str_ends_with($download['name'],'.sql') && hash_file('sha256',$download['file'])===$good['sql_sha256'],'New verified downloads must be directly readable SQL.');
    $goodHash=hash_file('sha256',$goodArchive['file']);
    $goodDirectory=db_backup_job_dir($good['id']);
    $verification=json_decode(file_get_contents($goodDirectory.'/verification.json'),true,64,JSON_THROW_ON_ERROR);
    backup_runner_assert(($verification['checks']['health']??'')==='ok' && ($verification['checks']['financial_integrity']??'')==='passed' && count($verification['units']??[])===3,'Verification must cover health, finances and all three units.');
    echo "PASS: full plain-SQL snapshot/download, all-unit financial/FK/health verification\n";

    $rowsBefore=(int)$queue->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    $lock=fopen($artifactRoot.'/runner.lock','c');
    backup_runner_assert((bool)$lock && flock($lock,LOCK_EX|LOCK_NB),'Parent test must own dispatcher lock.');
    try { $blocked=backup_runner_process([...$args,'--tick'],$artifactRoot.'/locked'); }
    finally { flock($lock,LOCK_UN); fclose($lock); }
    backup_runner_assert($blocked['code']===0 && str_contains($blocked['out'],'already running') && (int)$queue->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$rowsBefore,'Independent process must refuse a concurrent runner without creating work.');
    echo "PASS: cross-process dispatcher locking\n";

    foreach (['disk','dump','schema','verify'] as $hook) {
        $queue->exec('DELETE FROM schedule_slots');
        $before=time();
        $failure=backup_runner_process([...$args,'--tick'],$artifactRoot.'/failure-'.$hook,['SPP_BACKUP_TEST_FAIL'=>$hook]);
        backup_runner_assert($failure['code']!==0,'Injected '.$hook.' failure must fail the runner.');
        $failed=$queue->query("SELECT * FROM jobs ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        backup_runner_assert($failed['state']==='failed' && is_file(db_backup_job_dir($failed['id']).'/failure.json'),'Failure diagnostics must remain private and persistent.');
        backup_runner_assert((int)$failed['next_attempt_at']>=$before+900 && (int)$failed['next_attempt_at']<=time()+900,'Retry metadata must wait fifteen minutes.');
        backup_runner_assert((int)$failed['retryable']===($hook==='verify'?0:1),'Unsafe verification failure must not be silently retried.');
        backup_runner_assert(db_backup_schedule_job($queue,db_backup_now())===null,'Polling immediately after a failure must not create another attempt.');
        backup_runner_assert(hash_file('sha256',$goodArchive['file'])===$goodHash && db_backup_find($queue,$good['id'])['state']==='verified','Failures must preserve the previous good backup.');
        if ($hook==='verify') backup_runner_assert(is_file(db_backup_job_dir($failed['id']).'/backup.sql'),'Failed verification must retain its plain SQL archive.');
        echo 'PASS: '.$hook." failure, preserved good archive, private diagnostics and retry policy\n";
    }

    $queue->exec('DELETE FROM schedule_slots');
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errorNumber,$errorMessage);
    backup_runner_assert((bool)$listener,'An unused local port must be reserved.');
    $unavailablePort=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);
    fclose($listener); // Refuse immediately; a listening socket without a MySQL handshake can block indefinitely.
    $offline=$config; $offline['port']=$unavailablePort;
    db_backup_atomic_json($configFile,$offline);
    try { $unavailable=backup_runner_process([...$args,'--tick'],$artifactRoot.'/source-unavailable'); }
    finally { db_backup_atomic_json($configFile,$config); }
    $failed=$queue->query('SELECT * FROM jobs ORDER BY rowid DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    backup_runner_assert($unavailable['code']!==0 && $failed['state']==='failed' && (int)$failed['retryable']===1,'An unavailable MySQL endpoint must yield a retryable source failure without stopping the source.');
    backup_runner_assert(db_backup_schedule_job($queue,db_backup_now())===null,'Unavailable source must respect retry delay.');
    $retryAt=(new DateTimeImmutable('@'.$failed['next_attempt_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'));
    $retry=db_backup_schedule_job($queue,$retryAt);
    backup_runner_assert($retry && $retry['id']!==$failed['id'] && db_backup_find($queue,$failed['id'])['state']==='failed','A due retry must create a fresh attempt and preserve old diagnostics.');
    $queue->prepare("UPDATE jobs SET state='failed',retryable=0 WHERE id=?")->execute([$retry['id']]);
    echo "PASS: unavailable MySQL, fifteen-minute fresh retry and preserved source process\n";

    $legacyId=bin2hex(random_bytes(16)); $legacyDirectory=$artifactRoot.'/jobs/'.$legacyId; mkdir($legacyDirectory,0700);
    $sqlBytes=file_get_contents($goodArchive['file']); $legacyBytes=gzencode($sqlBytes,6); $legacyHash=hash('sha256',$legacyBytes);
    file_put_contents($legacyDirectory.'/backup.sql.gz',$legacyBytes);
    copy($goodDirectory.'/schema.json',$legacyDirectory.'/schema.json');
    db_backup_atomic_json($legacyDirectory.'/manifest.json',['id'=>$legacyId,'state'=>'verified','archive_sha256'=>$legacyHash,'sql_sha256'=>$good['sql_sha256']]);
    $queue->prepare("INSERT INTO jobs(id,type,state,created_at,finished_at,archive_name,archive_sha256,sql_sha256,bytes) VALUES(?,'manual','verified',?,?,'backup.sql.gz',?,?,?)")->execute([$legacyId,db_backup_now()->format(DATE_ATOM),db_backup_now()->format(DATE_ATOM),$legacyHash,$good['sql_sha256'],strlen($legacyBytes)]);
    $legacyDownload=db_backup_download_archive($queue,$legacyId);
    backup_runner_assert(str_ends_with($legacyDownload['name'],'.sql') && hash_file('sha256',$legacyDownload['file'])===$good['sql_sha256'] && hash_file('sha256',$legacyDirectory.'/backup.sql.gz')===$legacyHash,'Legacy downloads must materialize exact SQL without altering the compressed original.');
    backup_runner_assert(db_backup_download_archive($queue,$legacyId)['file']===$legacyDownload['file'],'Repeated legacy downloads must safely reuse the verified SQL copy.');
    $legacyReverify=backup_runner_process([...$args,'--verify','--id='.$legacyId],$artifactRoot.'/legacy-reverify');
    backup_runner_assert($legacyReverify['code']===0 && !is_file($legacyDirectory.'/dump.sql') && hash_file('sha256',$legacyDirectory.'/backup.sql.gz')===$legacyHash,'Legacy compressed backups must remain CLI-verifiable and intact.');
    $expanded=$artifactRoot.'/roundtrip.sql';
    db_backup_expand_archive($legacyDirectory.'/backup.sql.gz',$expanded,$good['sql_sha256']);
    backup_runner_assert(hash_file('sha256',$expanded)===$good['sql_sha256'],'Archive roundtrip must equal the verified SQL checksum.');
    unlink($expanded);
    $corrupt=$artifactRoot.'/truncated.sql.gz';
    $compressed=$legacyBytes;
    file_put_contents($corrupt,substr($compressed,0,intdiv(strlen($compressed),2)));
    $rejected=false;
    try { db_backup_expand_archive($corrupt,$expanded,$good['sql_sha256']); } catch (Throwable $error) { $rejected=true; }
    backup_runner_assert($rejected && !is_file($expanded),'Truncated archive must never publish SQL.');
    echo "PASS: legacy gzip-to-SQL downloads, CLI verification, roundtrip and truncated archive rejection\n";

    // Force an audit mismatch, then retry the same archive to prove stale reports cannot grant success.
    $schemaPath=$goodDirectory.'/schema.json'; $savedSchema=file_get_contents($schemaPath);
    $badSchema=json_decode($savedSchema,true,64,JSON_THROW_ON_ERROR);
    $changedTable=array_key_first($badSchema['objects']['tables']);
    $badSchema['objects']['tables'][$changedTable]['ddl'].=" COMMENT='backup verification mismatch'";
    $badSchema['signature']=hash('sha256',json_encode($badSchema['objects'],JSON_THROW_ON_ERROR));
    db_backup_atomic_json($schemaPath,$badSchema);
    try { $reverifyFail=backup_runner_process([...$args,'--verify','--id='.$good['id']],$artifactRoot.'/reverify-failed'); }
    finally { file_put_contents($schemaPath,$savedSchema); }
    backup_runner_assert($reverifyFail['code']!==0 && !is_file($goodDirectory.'/dump.sql') && !is_dir($goodDirectory.'/verifier-work'),'Failed reverification must reject stale prior success and clean its owned SQL/workspace.');
    $reverified=backup_runner_process([...$args,'--verify','--id='.$good['id']],$artifactRoot.'/reverify-retry');
    backup_runner_assert($reverified['code']===0 && str_contains($reverified['out'],'REVERIFIED') && !is_file($goodDirectory.'/dump.sql'),'Reverification retry must work without stale log concatenation or leftover SQL.');
    backup_runner_assert(hash_file('sha256',$goodArchive['file'])===$goodHash,'Reverification must not alter the archive.');
    echo "PASS: failed reverification, stale success rejection, cleanup and successful retry\n";

    // Restore the successful current slot so recovery does not also create a new full backup.
    $queue->exec('DELETE FROM schedule_slots');
    $queue->prepare('INSERT INTO schedule_slots(slot,last_job_id) VALUES(?,?)')->execute([db_backup_latest_due(db_backup_now()),$good['id']]);
    $orphanId=bin2hex(random_bytes(16)); $orphanDirectory=$artifactRoot.'/jobs/'.$orphanId; mkdir($orphanDirectory,0700);
    $queue->prepare("INSERT INTO jobs(id,slot,type,state,created_at,started_at) VALUES(?,?,'scheduled','verifying',?,?)")->execute([$orphanId,'2000-01-01T18:00:00+07:00',db_backup_now()->format(DATE_ATOM),db_backup_now()->format(DATE_ATOM)]);
    $recovery=backup_runner_process([...$args,'--tick'],$artifactRoot.'/orphan-recovery');
    $orphan=db_backup_find($queue,$orphanId);
    backup_runner_assert($recovery['code']===0 && $orphan['state']==='failed' && (int)$orphan['retryable']===1 && is_file($orphanDirectory.'/verifier-result.json'),'Interrupted metadata without a running verifier must recover safely and remain retryable.');
    backup_runner_assert(backup_runner_financial_fingerprint($source)===$financialBefore,'Runner tests must leave all source financial tables unchanged.');
    backup_runner_assert(db_backup_find($queue,$good['id'])['state']==='verified' && hash_file('sha256',$goodArchive['file'])===$goodHash,'All failure and recovery tests must preserve the verified backup.');
    db_backup_atomic_json($artifactRoot.'/test-result.json',['state'=>'passed','verified_job'=>$good['id'],'financial_before'=>$financialBefore,'financial_after'=>backup_runner_financial_fingerprint($source),'finished_at'=>db_backup_now()->format(DATE_ATOM)]);
    echo "PASS: interrupted-job recovery and unchanged source finances\n";
    $success=true;
} finally {
    if ($temporaryTable) $source->query('DROP TABLE `'.$temporaryTable.'`');
    if ($ownedAccount) $source->query("DROP USER 'spp_backup_local'@'127.0.0.1'");
    if (isset($credentials) && is_file($credentials)) unlink($credentials);
    putenv('SPP_BACKUP_CONFIG='.$baseConfigFile);
    $source->close();
}
if ($success) echo "PASS: database backup runner integration complete; private diagnostics retained\n";
