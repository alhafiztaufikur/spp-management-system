<?php
/** Standalone backup service tests. Queue mutations require a private audit configuration. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/database_backup.php';

function db_backup_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function db_backup_test_instant(string $actual, string $expected, string $message): void {
    $value = new DateTimeImmutable($actual);
    db_backup_test_assert($value->format('Y-m-d\TH:i:sP') === $expected, $message . ': ' . $actual);
}

$cases = [
    ['2026-10-13T17:59:59+07:00', '2026-10-12T18:00:00+07:00', '2026-10-13T18:00:00+07:00'],
    ['2026-10-13T18:00:00+07:00', '2026-10-13T18:00:00+07:00', '2026-10-14T18:00:00+07:00'],
    ['2026-10-13T18:00:01+07:00', '2026-10-13T18:00:00+07:00', '2026-10-14T18:00:00+07:00'],
    ['2026-10-14T00:00:00+07:00', '2026-10-13T18:00:00+07:00', '2026-10-14T18:00:00+07:00'],
    ['2027-01-01T00:00:00+07:00', '2026-12-31T18:00:00+07:00', '2027-01-01T18:00:00+07:00'],
    ['2028-03-01T08:00:00+07:00', '2028-02-29T18:00:00+07:00', '2028-03-01T18:00:00+07:00'],
    ['2026-10-13T11:00:00+00:00', '2026-10-13T18:00:00+07:00', '2026-10-14T18:00:00+07:00'],
    ['2026-10-13T07:00:00-04:00', '2026-10-13T18:00:00+07:00', '2026-10-14T18:00:00+07:00'],
];
$originalTimezone = date_default_timezone_get();
try {
    foreach (['UTC', 'America/New_York', 'Asia/Jakarta'] as $hostTimezone) {
        date_default_timezone_set($hostTimezone);
        foreach ($cases as [$input, $due, $next]) {
            $now = new DateTimeImmutable($input);
            db_backup_test_instant(db_backup_latest_due($now), $due, 'Latest due must use WIB regardless of host timezone');
            db_backup_test_instant(db_backup_next_schedule($now), $next, 'Next run must use WIB and be strictly in the future');
        }
    }
} finally {
    date_default_timezone_set($originalTimezone);
}
echo "PASS: 24 scheduling cases; exact 18:00 WIB, midnight, leap day, year boundary and foreign host timezones\n";

foreach ([[0, 2147483647, false], [0, 2147483648, true], [536870912, 2147483648, true],
    [1073741824, 4294967295, false], [1073741824, 4294967296, true]] as [$estimate, $available, $expected]) {
    db_backup_test_assert(db_backup_disk_budget($estimate, $available) === $expected,
        'Disk budget must reserve at least 2 GiB or four times the estimated source size');
}
echo "PASS: disk budget minimum and four-times-size boundaries\n";

function db_backup_test_schedule_store(): PDO {
    $store = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $store->exec('CREATE TABLE jobs(id TEXT PRIMARY KEY,manual_key TEXT UNIQUE,slot TEXT,type TEXT NOT NULL,
        actor INTEGER NOT NULL DEFAULT 0,actor_name TEXT NOT NULL DEFAULT "Sistem",state TEXT NOT NULL DEFAULT "queued",
        message TEXT NOT NULL DEFAULT "Menunggu",created_at TEXT NOT NULL,started_at TEXT,finished_at TEXT,
        next_attempt_at INTEGER NOT NULL DEFAULT 0,retryable INTEGER NOT NULL DEFAULT 0,archive_name TEXT,
        archive_sha256 TEXT,sql_sha256 TEXT,bytes INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE schedule_slots(slot TEXT PRIMARY KEY,last_job_id TEXT NOT NULL REFERENCES jobs(id));');
    return $store;
}

$schedule = db_backup_test_schedule_store();
$atSix = new DateTimeImmutable('2026-10-13T18:00:00+07:00');
$scheduled = db_backup_schedule_job($schedule, $atSix);
db_backup_test_assert($scheduled !== null && $scheduled['type'] === 'scheduled'
    && $scheduled['slot'] === '2026-10-13T18:00:00+07:00', 'The actual daily slot must be scheduled');
db_backup_test_assert(db_backup_schedule_job($schedule, $atSix->modify('+30 seconds')) === null,
    'An outstanding scheduled job must be idempotent');
$schedule->prepare("UPDATE jobs SET state='verified' WHERE id=?")->execute([$scheduled['id']]);
db_backup_test_assert(db_backup_schedule_job($schedule, $atSix->modify('+6 hours')) === null,
    'A successful scheduled slot must never run twice');

$schedule = db_backup_test_schedule_store();
$late = new DateTimeImmutable('2026-10-17T10:30:00+07:00');
$catchup = db_backup_schedule_job($schedule, $late);
db_backup_test_assert($catchup !== null && $catchup['type'] === 'catchup'
    && $catchup['slot'] === '2026-10-16T18:00:00+07:00'
    && $catchup['created_at'] === '2026-10-17T10:30:00+07:00',
    'Missed days must yield one latest catchup with its actual creation time');
db_backup_test_assert((int)$schedule->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === 1,
    'Do not fabricate one snapshot for each missed historical day');
db_backup_test_assert(db_backup_schedule_job($schedule, $late->modify('+1 minute')) === null,
    'Repeated queue polling must not duplicate catchup');

$retryAt = $late->modify('+15 minutes')->getTimestamp();
$schedule->prepare("UPDATE jobs SET state='failed',retryable=1,next_attempt_at=? WHERE id=?")
    ->execute([$retryAt, $catchup['id']]);
db_backup_test_assert(db_backup_schedule_job($schedule, $late->modify('+14 minutes 59 seconds')) === null,
    'Failed source or disk checks must wait the full fifteen minutes');
$retry = db_backup_schedule_job($schedule, $late->modify('+15 minutes'));
db_backup_test_assert($retry !== null && $retry['id'] !== $catchup['id'],
    'Retry must create a new attempt without overwriting failed diagnostics');
db_backup_test_assert(db_backup_find($schedule, $catchup['id'])['state'] === 'failed',
    'Retry must preserve the failed attempt');
$schedule->prepare("UPDATE jobs SET state='failed',retryable=0 WHERE id=?")->execute([$retry['id']]);
db_backup_test_assert(db_backup_schedule_job($schedule, $late->modify('+30 minutes')) === null,
    'An unsafe nonretryable failure must not loop automatically');

$schedule = db_backup_test_schedule_store();
db_backup_schedule_job($schedule, new DateTimeImmutable('2026-10-14T08:00:00+07:00'));
$latest = db_backup_schedule_job($schedule, new DateTimeImmutable('2026-10-17T08:00:00+07:00'));
db_backup_test_assert($latest !== null && $latest['slot'] === '2026-10-16T18:00:00+07:00',
    'An offline queue must move to the latest missed slot');
db_backup_test_assert((int)$schedule->query("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','dumping','verifying')")->fetchColumn() === 1,
    'An offline queue must not accumulate outdated snapshots');
echo "PASS: scheduled and catchup idempotency, latest-slot coalescing, fifteen-minute retry and immutable failed attempts\n";

$schedule = db_backup_test_schedule_store();
$previous = db_backup_schedule_job($schedule, new DateTimeImmutable('2026-10-13T18:00:00+07:00'));
$schedule->prepare("UPDATE jobs SET state='verified' WHERE id=?")->execute([$previous['id']]);
$missed = db_backup_schedule_job($schedule, new DateTimeImmutable('2026-10-17T08:00:00+07:00'));
$window = db_backup_schedule_window($schedule,$missed,'2026-10-10T10:00:00+07:00');
db_backup_test_assert($window === ['first_due'=>'2026-10-14T18:00:00+07:00','last_due'=>'2026-10-16T18:00:00+07:00','count'=>3], 'Manifest must record every missed daily slot while producing one current snapshot');
db_backup_test_assert(db_backup_schedule_window($schedule,['slot'=>null],'2026-10-10T10:00:00+07:00')===null,'Manual backups must not invent a daily schedule');
$firstWindow=db_backup_schedule_window(db_backup_test_schedule_store(),$missed,'2026-10-13T08:00:00+07:00');
db_backup_test_assert($firstWindow['count']===4 && $firstWindow['first_due']==='2026-10-13T18:00:00+07:00','First catchup records missed slots since installation');
echo "PASS: missed daily windows recorded without backdating snapshots\n";

require_once __DIR__ . '/http_form_scope.php';
$guardEnvironment = [];
foreach (['SPP_TEST_ISOLATED_INSTANCE', 'SPP_TEST_ALLOW_MUTATION', 'SPP_DB_HOST', 'SPP_DB_PORT', 'SPP_DB_NAME'] as $key) {
    $guardEnvironment[$key] = getenv($key);
}
$guardFile = tempnam(sys_get_temp_dir(), 'spp-isolation-guard-');
db_backup_test_assert($guardFile !== false, 'Cannot create disposable isolation-guard fixture');
$guardManifest = ['version' => 1, 'isolated_test' => true, 'database' => 'db_spp', 'host' => '127.0.0.1',
    'port' => 54321, 'server_uuid' => '11111111-1111-1111-1111-111111111111', 'datadir' => 'C:/spp-test-owned/mysql',
    'source_server_uuid' => '22222222-2222-2222-2222-222222222222', 'source_datadir' => 'C:/spp-test-source/mysql'];
try {
    foreach (['SPP_TEST_ISOLATED_INSTANCE' => $guardFile, 'SPP_TEST_ALLOW_MUTATION' => '1',
        'SPP_DB_HOST' => '127.0.0.1', 'SPP_DB_PORT' => '54321', 'SPP_DB_NAME' => 'db_spp'] as $key => $value) putenv($key . '=' . $value);
    file_put_contents($guardFile, json_encode($guardManifest, JSON_THROW_ON_ERROR));
    db_backup_test_assert(spp_test_isolated_manifest()['port'] === 54321, 'Explicit valid isolated test manifest rejected');
    foreach (['port' => 3306, 'source_server_uuid' => $guardManifest['server_uuid'],
        'source_datadir' => $guardManifest['datadir'], 'isolated_test' => false, 'host' => 'localhost'] as $key => $invalid) {
        $candidate = $guardManifest;
        $candidate[$key] = $invalid;
        file_put_contents($guardFile, json_encode($candidate, JSON_THROW_ON_ERROR));
        $rejected = false;
        try { spp_test_isolated_manifest(); } catch (RuntimeException $expected) { $rejected = true; }
        db_backup_test_assert($rejected, 'Unsafe isolated manifest accepted: ' . $key);
    }
    file_put_contents($guardFile, json_encode($guardManifest, JSON_THROW_ON_ERROR));
    putenv('SPP_DB_NAME=db_spp_audit_mismatched');
    $rejected = false;
    try { spp_test_assert_database_target(); } catch (RuntimeException $expected) { $rejected = true; }
    db_backup_test_assert($rejected, 'An explicit bad isolated manifest must not fall back to accepting an audit name');
    putenv('SPP_DB_NAME=db_spp');
    putenv('SPP_TEST_ALLOW_MUTATION=0');
    $rejected = false;
    try { spp_test_isolated_manifest(); } catch (RuntimeException $expected) { $rejected = true; }
    db_backup_test_assert($rejected, 'Isolation requires explicit mutation opt-in');
} finally {
    foreach ($guardEnvironment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    unlink($guardFile);
}
echo "PASS: explicit isolated test identity guard; operational port, source identity, missing opt-in and fallback rejected\n";

if (in_array('--schedule-only', $argv, true)) exit;

$configPath = (string)getenv('SPP_BACKUP_CONFIG');
db_backup_test_assert($configPath !== '' && is_file($configPath), 'Set SPP_BACKUP_CONFIG to a private disposable audit configuration');
$config = db_backup_config();
db_backup_test_assert(!empty($config['allowed_test']), 'Queue tests require allowed_test');
$database = (string)($config['database'] ?? '');
spp_test_assert_database_target();
$storage = realpath((string)($config['storage_root'] ?? ''));
$repo = realpath(__DIR__ . '/..');
db_backup_test_assert($storage !== false && $repo !== false && !str_starts_with(strtolower(str_replace('\\', '/', $storage)) . '/', strtolower(str_replace('\\', '/', $repo)) . '/'), 'Private test storage must be outside the repository');

db_backup_test_assert((string)getenv('SPP_DB_NAME') === $database, 'Application actor lookup must use the same audit clone');
require_once __DIR__ . '/../koneksi.php';
spp_test_assert_database_target($koneksi);
$sourceIdentity = $koneksi->query('SELECT @@server_uuid server_uuid,@@datadir datadir,@@port port')->fetch_assoc();
db_backup_test_assert(strtolower($config['server_uuid']) === strtolower($sourceIdentity['server_uuid'])
    && db_backup_normal_path($config['datadir']) === db_backup_normal_path($sourceIdentity['datadir'])
    && (int)$config['port'] === (int)$sourceIdentity['port'],
    'Queue configuration must use the same disposable server identity as actor lookup');
unit_set_context($koneksi, 0);
$actor = (int)($koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_row()[0] ?? 0);
db_backup_test_assert($actor > 0, 'Active Super Admin audit fixture required');
$store = db_backup_store(true);
db_backup_test_assert((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === 0,
    'Use fresh disposable queue storage for the model suite');
$key = bin2hex(random_bytes(16));
$aliasKey = bin2hex(random_bytes(16));
$first = db_backup_enqueue($actor, $key);
$same = db_backup_enqueue($actor, $key);
$other = db_backup_enqueue($actor, $aliasKey);
db_backup_test_assert($first['id'] === $same['id'] && $other['id'] === $first['id'],
    'Repeat requests and double clicks must share one pending backup');
db_backup_test_assert((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === 1,
    'Duplicate enqueue created another job');
db_backup_test_assert($first['state'] === 'queued' && !isset($first['download_url']),
    'Unverified jobs must never offer a download');
db_backup_test_assert(preg_match('/^[a-f0-9]{32}$/D', $first['id']) === 1, 'Queue IDs must be opaque safe hex IDs');
foreach (['archive_name', 'archive_sha256', 'sql_sha256', 'manual_key', 'actor', 'storage_root'] as $privateKey) {
    db_backup_test_assert(!array_key_exists($privateKey, $first), 'Public job exposed private internals: ' . $privateKey);
}
foreach (['', '../outside', str_repeat('a', 33), str_repeat('A', 32)] as $invalid) {
    $rejected = false;
    try { db_backup_job_dir($invalid); } catch (InvalidArgumentException $expected) { $rejected = true; }
    db_backup_test_assert($rejected, 'Invalid ID accepted: ' . $invalid);
}
$store->prepare("UPDATE jobs SET state='verified',finished_at=? WHERE id=?")
    ->execute([db_backup_now()->format(DATE_ATOM), $first['id']]);
$new = db_backup_enqueue($actor, bin2hex(random_bytes(16)));
db_backup_test_assert($new['id'] !== $first['id'], 'A new request after completion should create a new job');
db_backup_test_assert(db_backup_enqueue($actor, $aliasKey)['id'] === $first['id'],
    'A request key that reused a pending job must still reference that completed job, even while another job is active');
$store->prepare("UPDATE jobs SET state='failed',message='Uji kegagalan' WHERE id=?")->execute([$first['id']]);
db_backup_test_assert(db_backup_enqueue($actor, $key)['id'] === $first['id'],
    'Retrying a delivered request key must not create another job');
$summary = db_backup_summary();
db_backup_test_assert($summary['configured'] === true && $summary['count'] === 0
    && $summary['active']['id'] === $new['id'], 'Summary must distinguish active jobs from verified backups');
echo "PASS: manual queue idempotency, safe opaque IDs, sanitized public jobs and summary; private audit queue only\n";
