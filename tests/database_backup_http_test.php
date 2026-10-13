<?php
/** Backup API access, queue idempotency and download safety on a disposable database only. */
require_once __DIR__ . '/http_form_scope.php';
spp_test_assert_database_target();
ob_start();
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/database_backup.php';
spp_test_assert_database_target($koneksi);

function backup_http_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$base = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
spp_test_assert_http_clone($base, DB_NAME);
$config = db_backup_config();
backup_http_assert(!empty($config['allowed_test']) && $config['database'] === DB_NAME,
    'Backup source and HTTP process must use the same disposable audit database.');
$sourceIdentity = $koneksi->query('SELECT @@server_uuid server_uuid,@@datadir datadir,@@port port')->fetch_assoc();
backup_http_assert(strtolower($config['server_uuid']) === strtolower($sourceIdentity['server_uuid'])
    && db_backup_normal_path($config['datadir']) === db_backup_normal_path($sourceIdentity['datadir'])
    && (int)$config['port'] === (int)$sourceIdentity['port'],
    'Backup configuration must target the same disposable server identity as the application.');
$privateStorage = realpath((string)$config['storage_root']);
$repository = realpath(__DIR__ . '/..');
backup_http_assert($privateStorage !== false && $repository !== false
    && !str_starts_with(strtolower(str_replace('\\', '/', $privateStorage)) . '/', strtolower(str_replace('\\', '/', $repository)) . '/'),
    'Use private backup storage outside the repository.');

function backup_http_request(string $path, string $session, string $method = 'GET', ?array $data = null): array {
    global $base;
    if ($method === 'POST' && $data !== null) {
        parse_str((string)(parse_url($path, PHP_URL_QUERY) ?? ''), $query);
        if (array_key_exists('action', $query) && !array_key_exists('action', $data)) $data['action'] = $query['action'];
    }
    $headers = ['Cookie: PHPSESSID=' . $session];
    if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $body = file_get_contents($base . '/' . $path, false, stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $data === null ? '' : http_build_query($data), 'ignore_errors' => true,
        'follow_location' => 0, 'timeout' => 30,
    ]]));
    $responseHeaders = $http_response_header ?? [];
    preg_match('/^HTTP\/\S+\s+(\d+)/', $responseHeaders[0] ?? '', $match);
    return [(int)($match[1] ?? 0), (string)$body, $responseHeaders];
}

function backup_http_fingerprint(mysqli $db): array {
    $fingerprint = [];
    foreach ($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all() as [$name]) {
        $rows = $db->query('SELECT * FROM `' . str_replace('`', '``', $name) . '`')->fetch_all(MYSQLI_ASSOC);
        $encoded = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $fingerprint[$name] = hash('sha256', implode("\n", $encoded));
    }
    ksort($fingerprint);
    return $fingerprint;
}

function backup_http_session(?array $account, int $unit, ?string $claimedRole = null): string {
    $id = 'databasebackup' . bin2hex(random_bytes(12));
    session_id($id);
    session_start();
    $_SESSION = $account ? ['admin_id' => (int)$account['id'], 'admin_role' => $claimedRole ?? $account['role'],
        'active_unit_id' => $unit] : [];
    session_write_close();
    return $id;
}

function backup_http_public_payload(string $body): array {
    global $config;
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    backup_http_assert(is_array($decoded), 'Expected JSON object');
    foreach (['storage_root', 'source_defaults_file', 'mysql_bin', 'php_executable', 'datadir', 'server_uuid', 'password', 'sql_sha256', 'archive_name'] as $privateKey) {
        backup_http_assert(!preg_match('/"' . preg_quote($privateKey, '/') . '"\s*:/', $body), 'Private configuration key exposed: ' . $privateKey);
    }
    foreach (['storage_root', 'source_defaults_file'] as $privateValue) {
        $value = (string)($config[$privateValue] ?? '');
        if ($value !== '') {
            backup_http_assert(!str_contains(str_replace('\\/', '/', $body), str_replace('\\', '/', $value)), 'Internal path exposed');
            backup_http_assert(!str_contains($body, json_encode($value, JSON_THROW_ON_ERROR)), 'JSON internal path exposed');
        }
    }
    backup_http_assert(!preg_match('/Fatal error|Warning:|SELECT\s+.+\s+FROM|CREATE TABLE/i', $body), 'Diagnostic or SQL text leaked');
    return $decoded;
}

unit_set_context($koneksi, 0);
$before = backup_http_fingerprint($koneksi);
$sessions = [];
$requests = 0;
$store = db_backup_store(true);
$pendingId = null;
$adminSession = null;
$super = $koneksi->query("SELECT id,role,is_active FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
backup_http_assert((bool)$super, 'Active Super Admin fixture is required');
try {
    $anonymous = backup_http_session(null, 1);
    $sessions[] = $anonymous;
    foreach (['backup_api.php?action=status', 'backup_api.php?action=download&id=' . str_repeat('a', 32)] as $path) {
        [$status] = backup_http_request($path, $anonymous);
        $requests++;
        backup_http_assert(in_array($status, [302, 401, 403], true), 'Anonymous backup API access allowed');
    }
    foreach (['admin', 'kasir', 'bendahara'] as $role) {
        $statement = $koneksi->prepare('SELECT id,role FROM admin WHERE role=? AND is_active=1 ORDER BY id LIMIT 1');
        $statement->bind_param('s', $role);
        $statement->execute();
        $account = $statement->get_result()->fetch_assoc();
        $statement->close();
        backup_http_assert((bool)$account, 'Active role fixture missing: ' . $role);
        $session = backup_http_session($account, 0, 'super_admin'); // Fresh DB role must defeat stale privilege.
        $sessions[] = $session;
        foreach (['GET', 'POST'] as $method) {
            [$status] = backup_http_request('backup_api.php?action=' . ($method === 'GET' ? 'status' : 'create'), $session, $method,
                $method === 'POST' ? ['csrf_token' => 'invalid'] : null);
            $requests++;
            backup_http_assert(in_array($status, [302, 401, 403], true), 'Stale Super Admin claim accepted for ' . $role);
        }
        [$status] = backup_http_request('backup_api.php?action=settings', $session, 'POST', ['csrf_token'=>'invalid','daily_time'=>'20:00','retention_days'=>'90','version'=>'0']);$requests++;
        backup_http_assert(in_array($status,[302,401,403],true),'Non-Super Admin changed backup settings');
    }
    foreach ([0, 1, 2, 3] as $unit) {
        $session = backup_http_session($super, $unit);
        $adminSession = $session;
        $sessions[] = $session;
        [$status, $html] = backup_http_request('backup_restore.php', $session);
        $requests++;
        backup_http_assert($status === 200 && str_contains($html, 'Buat Backup Sekarang'), 'Backup page inaccessible for unit ' . $unit);
        session_id($session);
        session_start();
        $csrf = (string)($_SESSION['csrf_backup'] ?? '');
        session_write_close();
        backup_http_assert(strlen($csrf) >= 32, 'Backup-specific CSRF token missing');

        [$status, $body] = backup_http_request('backup_api.php?action=status', $session);
        $requests++;
        backup_http_assert($status === 200, 'Status inaccessible for unit ' . $unit);
        backup_http_public_payload($body);
        $currentSettings=json_decode($body,true)['settings'];
        backup_http_assert(str_contains($html,'database-backup-settings-form'),'Settings form missing');
        [$status]=backup_http_request('backup_api.php?action=settings',$session);$requests++;
        backup_http_assert($status===405,'Settings mutation allowed over GET');
        $settingData=['daily_time'=>'20:'.sprintf('%02d',$unit),'retention_days'=>(string)([0=>1,1=>90,2=>365,3=>90][$unit]),'version'=>(string)$currentSettings['version'],'csrf_token'=>$csrf];
        foreach(['','invalid'] as $badToken){
            [$status]=backup_http_request('backup_api.php?action=settings',$session,'POST',array_replace($settingData,['csrf_token'=>$badToken]));$requests++;
            backup_http_assert($status===403,'Settings CSRF accepted');
        }
        foreach([['daily_time'=>['20:00']],['daily_time'=>'24:00'],['daily_time'=>'20:61'],['retention_days'=>'0'],['retention_days'=>'366'],['retention_days'=>['90']],['version'=>['0']],['version'=>'-1']] as $bad){
            [$status,$body]=backup_http_request('backup_api.php?action=settings',$session,'POST',array_replace($settingData,$bad));$requests++;
            backup_http_assert($status===400,'Malformed settings accepted');backup_http_public_payload($body);
        }
        $jobCountBefore=(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
        [$status,$body]=backup_http_request('backup_api.php?action=settings',$session,'POST',$settingData);$requests++;
        $settingsResult=backup_http_public_payload($body);
        backup_http_assert($status===200&&$settingsResult['settings']['daily_time']===$settingData['daily_time']&&$settingsResult['settings']['retention_days']===(int)$settingData['retention_days'],'Saved settings differ');
        backup_http_assert((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$jobCountBefore,'Settings save unexpectedly created a backup');
        [$status,$body]=backup_http_request('backup_api.php?action=settings',$session,'POST',$settingData);$requests++;
        backup_http_assert($status===409&&str_contains($body,'Muat ulang'),'Stale settings version accepted');
        $settingData['version']=(string)$settingsResult['settings']['version'];
        [$status,,$headers]=backup_http_request('backup_api.php?action=settings',$session,'POST',$settingData+['return'=>'page']);$requests++;
        backup_http_assert($status===303&&in_array('Location: backup_restore.php',$headers,true),'Native settings form must redirect safely');
        foreach (['', 'invalid'] as $badToken) {
            [$status] = backup_http_request('backup_api.php?action=create', $session, 'POST', ['csrf_token' => $badToken]);
            $requests++;
            backup_http_assert($status === 403, 'Missing/invalid backup CSRF accepted');
        }
        foreach ([['request_key' => ['bad']], ['request_key' => '../outside'], ['request_key' => str_repeat('a', 33)],
            ['request_key' => str_repeat('a', 32), 'return' => ['page']]] as $badData) {
            [$status] = backup_http_request('backup_api.php?action=create', $session, 'POST',
                $badData + ['csrf_token' => $csrf]);
            $requests++;
            backup_http_assert($status === 400, 'Malformed create input accepted');
        }
        foreach (['action[]=status', 'action=unknown', 'action=download&id[]=x', 'action=download&id=..%2F..%2Fkoneksi.php', 'action=download&id=' . str_repeat('f', 33)] as $bad) {
            [$status] = backup_http_request('backup_api.php?' . $bad, $session);
            $requests++;
            backup_http_assert($status === 400, 'Malformed API input accepted: ' . $bad);
        }
        foreach ([['create', 'GET'], ['status', 'POST'], ['download', 'POST'], ['status', 'PUT']] as [$action, $method]) {
            [$status] = backup_http_request('backup_api.php?action=' . $action, $session, $method,
                $method === 'GET' ? null : ['csrf_token' => $csrf]);
            $requests++;
            backup_http_assert($status === 405, 'Invalid method accepted: ' . $action . '/' . $method);
        }
        [$status] = backup_http_request('backup_api.php?action=download&id=' . str_repeat('f', 32), $session);
        $requests++;
        backup_http_assert($status === 404, 'Unknown archive ID accepted');
        $key = bin2hex(random_bytes(16));
        [$status, $body] = backup_http_request('backup_api.php?action=create', $session, 'POST',
            ['csrf_token' => $csrf, 'request_key' => $key]);
        $requests++;
        backup_http_assert($status === 202, 'Manual backup not queued for unit ' . $unit);
        $created = backup_http_public_payload($body);
        $job = $created['job'] ?? null;
        backup_http_assert(is_array($job) && preg_match('/^[a-f0-9]{32}$/D', (string)($job['id'] ?? '')) === 1,
            'Create must return a safe opaque job');
        $pendingId ??= $job['id'];
        backup_http_assert($job['id'] === $pendingId, 'Different units/double clicks created concurrent backup jobs');
        [$status, $body] = backup_http_request('backup_api.php?action=create', $session, 'POST',
            ['csrf_token' => $csrf, 'request_key' => $key]);
        $requests++;
        backup_http_assert($status === 202 && (backup_http_public_payload($body)['job']['id'] ?? null) === $pendingId,
            'Retrying the same manual request duplicated the backup');
        [$status, , $headers] = backup_http_request('backup_api.php?action=create', $session, 'POST',
            ['csrf_token' => $csrf, 'request_key' => $key, 'return' => 'page']);
        $requests++;
        backup_http_assert($status === 303 && count(array_filter($headers,
            static fn(string $header): bool => strtolower($header) === 'location: backup_restore.php')) === 1,
            'Native form must use a safe POST/Redirect/GET response');
        [$status] = backup_http_request('backup_api.php?action=download&id=' . $pendingId, $session);
        $requests++;
        backup_http_assert($status === 400, 'Unverified archive download was allowed');
        if ($unit === 0) {
            foreach (['pembayaran/proses.php', 'tabungan/proses.php', 'master_spp.php'] as $mutationPath) {
                [$status] = backup_http_request($mutationPath, $session, 'POST',
                    ['aksi' => 'input', 'csrf_token' => $csrf, 'nominal' => '1']);
                $requests++;
                backup_http_assert($status === 409, 'Backup exception broadened all-unit writes: ' . $mutationPath);
            }
        }
        session_id($session);
        session_start();
        backup_http_assert((int)$_SESSION['active_unit_id'] === $unit, 'Backup changed operational unit');
        session_write_close();
    }

    // A tiny inert file verifies download integrity without dumping or restoring an application database.
    $downloadId = bin2hex(random_bytes(16));
    $directory = db_backup_job_dir($downloadId);
    backup_http_assert(mkdir($directory, 0700), 'Could not prepare private download fixture');
    $archive = "-- Disposable backup download fixture. No application data.\n";
    $archivePath = $directory . '/backup.sql';
    file_put_contents($archivePath, $archive, LOCK_EX);
    $checksum = hash('sha256', $archive);
    $downloadManifest = ['id' => $downloadId, 'state' => 'verified', 'archive_name'=>'backup.sql', 'archive_sha256' => $checksum, 'sql_sha256'=>$checksum];
    db_backup_atomic_json($directory . '/manifest.json', $downloadManifest);
    $statement = $store->prepare("INSERT INTO jobs(id,type,state,actor_name,created_at,archive_name,archive_sha256,sql_sha256,bytes) VALUES(?,'manual','verified','Uji Unduhan',?,?,?,?,?)");
    $statement->execute([$downloadId, db_backup_now()->format(DATE_ATOM), 'backup.sql', $checksum, $checksum, strlen($archive)]);
    [$status, $body, $headers] = backup_http_request('backup_api.php?action=download&id=' . $downloadId, $adminSession);
    $requests++;
    backup_http_assert($status === 200 && hash('sha256', $body) === $checksum, 'Verified download differs from stored archive');
    backup_http_assert(count(array_filter($headers, static fn(string $header): bool => str_starts_with(strtolower($header), 'content-type: application/sql'))) === 1,
        'Archive download has an incorrect content type');
    backup_http_assert(count(array_filter($headers, static fn(string $header): bool => str_starts_with(strtolower($header), 'content-disposition: attachment; filename="sistemspp_'))) === 1,
        'Archive download filename must be generated from safe metadata');
    backup_http_assert(count(array_filter($headers, static fn(string $header): bool => preg_match('/^content-disposition:.*\.sql"$/i', $header))) === 1 && !str_starts_with($body,"\x1f\x8b"),
        'New backup download must be a directly readable .sql file, never gzip');
    $damaged = $archive;
    $damaged[10] = chr(ord($damaged[10]) ^ 1);
    file_put_contents($archivePath, $damaged, LOCK_EX);
    try {
        [$status, $body] = backup_http_request('backup_api.php?action=download&id=' . $downloadId, $adminSession);
        $requests++;
        backup_http_assert($status === 503, 'Corrupted archive must not be downloaded');
        backup_http_public_payload($body);
    } finally {
        file_put_contents($archivePath, $archive, LOCK_EX);
    }
    db_backup_atomic_json($directory . '/manifest.json', array_replace($downloadManifest, ['archive_sha256' => str_repeat('0', 64)]));
    try {
        [$status, $body] = backup_http_request('backup_api.php?action=download&id=' . $downloadId, $adminSession);
        $requests++;
        backup_http_assert($status === 503, 'Mismatched verification manifest must block download');
        backup_http_public_payload($body);
    } finally {
        db_backup_atomic_json($directory . '/manifest.json', $downloadManifest);
    }

    // Existing compressed backups remain stored unchanged, but the HTTP interface supplies plain SQL.
    $legacyId=bin2hex(random_bytes(16)); $legacyDirectory=db_backup_job_dir($legacyId);
    backup_http_assert(mkdir($legacyDirectory,0700),'Could not prepare private legacy fixture.');
    $legacySql="-- Legacy compressed fixture. No application data.\n";
    $legacyArchive=gzencode($legacySql,6); $legacyHash=hash('sha256',$legacyArchive); $legacySqlHash=hash('sha256',$legacySql);
    file_put_contents($legacyDirectory.'/backup.sql.gz',$legacyArchive,LOCK_EX);
    db_backup_atomic_json($legacyDirectory.'/manifest.json',['id'=>$legacyId,'state'=>'verified','archive_sha256'=>$legacyHash,'sql_sha256'=>$legacySqlHash]);
    $statement->execute([$legacyId,db_backup_now()->format(DATE_ATOM),'backup.sql.gz',$legacyHash,$legacySqlHash,strlen($legacyArchive)]);
    foreach ([1,2] as $attempt) {
        [$status,$body,$headers]=backup_http_request('backup_api.php?action=download&id='.$legacyId,$adminSession); $requests++;
        backup_http_assert($status===200 && hash('sha256',$body)===$legacySqlHash && count(array_filter($headers,static fn(string $header):bool=>preg_match('/^content-disposition:.*\.sql"$/i',$header)))===1,'Legacy gzip must materialize and reuse exact plain SQL downloads.');
        backup_http_assert(count(array_filter($headers,static fn(string $header):bool=>str_starts_with(strtolower($header),'content-type: application/sql')))===1,'Legacy download must also advertise SQL.');
    }
    backup_http_assert(hash_file('sha256',$legacyDirectory.'/backup.sql.gz')===$legacyHash,'Legacy compatibility must preserve the original gzip archive.');
    file_put_contents($legacyDirectory.'/backup.sql.gz',substr($legacyArchive,0,strlen($legacyArchive)-10),LOCK_EX);
    try {
        [$status,$body]=backup_http_request('backup_api.php?action=download&id='.$legacyId,$adminSession); $requests++;
        backup_http_assert($status===503,'A cached SQL copy must not bypass corruption checks on its legacy archive.');
        backup_http_public_payload($body);
    } finally { file_put_contents($legacyDirectory.'/backup.sql.gz',$legacyArchive,LOCK_EX); }

    $configurationFile = (string)getenv('SPP_BACKUP_CONFIG');
    $originalConfiguration = (string)file_get_contents($configurationFile);
    $wrongIdentityConfiguration = json_decode($originalConfiguration, true, 32, JSON_THROW_ON_ERROR);
    $wrongIdentityConfiguration['server_uuid'] = strtolower($config['server_uuid']) === '99999999-9999-9999-9999-999999999999'
        ? '88888888-8888-8888-8888-888888888888' : '99999999-9999-9999-9999-999999999999';
    try {
        db_backup_atomic_json($configurationFile, $wrongIdentityConfiguration);
        [$status, $body] = backup_http_request('backup_api.php?action=status', $adminSession);
        $requests++;
        $mismatched = backup_http_public_payload($body);
        backup_http_assert($status === 200 && $mismatched['configured'] === false && $mismatched['jobs'] === [],
            'A mismatched configured source identity must not expose backup history');
        [$status, $body] = backup_http_request('backup_api.php?action=download&id=' . $downloadId, $adminSession);
        $requests++;
        backup_http_assert($status === 503 && !str_starts_with($body, "\x1f\x8b"),
            'A mismatched configured source identity must block archive download');
        backup_http_public_payload($body);
    } finally {
        file_put_contents($configurationFile, $originalConfiguration, LOCK_EX);
    }

    $inactiveSession = backup_http_session($super, 0);
    $sessions[] = $inactiveSession;
    $koneksi->query('UPDATE admin SET is_active=0 WHERE id=' . (int)$super['id']);
    try {
        [$status] = backup_http_request('backup_api.php?action=status', $inactiveSession);
        $requests++;
        backup_http_assert(in_array($status, [302, 401, 403], true), 'Inactive Super Admin retained API access');
        [$status]=backup_http_request('backup_api.php?action=settings',$inactiveSession,'POST',['csrf_token'=>$csrf,'daily_time'=>'20:00','retention_days'=>'90','version'=>'0']);$requests++;
        backup_http_assert(in_array($status,[302,401,403],true),'Inactive account changed settings');
    } finally {
        $koneksi->query('UPDATE admin SET is_active=1 WHERE id=' . (int)$super['id']);
    }
    $store->prepare("UPDATE jobs SET state='expired' WHERE id=?")->execute([$downloadId]);
    [$status,$body]=backup_http_request('backup_api.php?action=download&id='.$downloadId,$adminSession);$requests++;
    backup_http_assert($status===410&&str_contains($body,'kedaluwarsa'),'Expired download must clearly return 410');
    $store->prepare("UPDATE jobs SET state='verified' WHERE id=?")->execute([$downloadId]);
    backup_http_assert(backup_http_fingerprint($koneksi) === $before, 'Backup API changed an application database record');
    echo "PASS: $requests backup API access/input/method/CSRF requests; four units, fresh roles, native PRG, queue deduplication and checksum-protected plain SQL downloads including legacy gzip; database fingerprints unchanged\n";
} finally {
    foreach ($sessions as $session) {
        session_id($session);
        session_start();
        $_SESSION = [];
        session_destroy();
        session_write_close();
    }
}
ob_end_flush();
