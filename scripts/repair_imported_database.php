<?php
/** Explicit CLI recovery for partial phpMyAdmin imports; never reruns financial migrations. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = ['apply'=>false, 'check'=>false, 'maintenance'=>false, 'expected'=>null, 'backup'=>null];
foreach (array_slice($argv,1) as $arg) {
    if ($arg === '--help') {
        echo "php scripts/repair_imported_database.php --check --expect-db=DATABASE\n";
        echo "php scripts/repair_imported_database.php --apply --expect-db=DATABASE --confirm-maintenance --backup-file=PRIVATE_DUMP\n";
        echo "Uses SPP_DB_*; additive constraints and verified empty-digest metadata only. No import or business-data rewrite.\n"; exit;
    }
    if ($arg === '--apply') $options['apply'] = true;
    elseif ($arg === '--check') $options['check'] = true;
    elseif ($arg === '--confirm-maintenance') $options['maintenance'] = true;
    elseif (preg_match('/^--expect-db=([A-Za-z0-9_]{1,64})$/D',$arg,$m) && $options['expected'] === null) $options['expected'] = $m[1];
    elseif (str_starts_with($arg,'--backup-file=') && $options['backup'] === null) $options['backup'] = substr($arg,14);
    else { fwrite(STDERR,"Invalid or repeated option. Use --help.\n"); exit(1); }
}
if (!$options['expected'] || ($options['apply'] && $options['check'])) { fwrite(STDERR,"One --expect-db is required; choose --check or --apply.\n"); exit(1); }
$db = null; $locked = false; $accessLock = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = getenv('SPP_DB_NAME') ?: 'db_spp_test2';
    if ($database !== $options['expected']) throw new RuntimeException('Configured target differs from --expect-db.');
    $db = new mysqli(getenv('SPP_DB_HOST') ?: '127.0.0.1',getenv('SPP_DB_USER') ?: 'root',getenv('SPP_DB_PASS') ?: '',$database,(int)(getenv('SPP_DB_PORT') ?: 3306));
    $db->set_charset('utf8mb4'); $db->query('SET @app_unit_id=0'); $db->query('SET SESSION lock_wait_timeout=30');
    require_once __DIR__.'/../includes/import_repair.php';
    if ($options['apply']) {
        $isolated = (int)$db->query('SELECT @@port')->fetch_row()[0] !== 3306 && getenv('SPP_TEST_ALLOW_MUTATION') === '1';
        if (!$isolated) {
            $backup = $options['backup'] ? realpath($options['backup']) : false;
            $root = strtolower(str_replace('\\','/',realpath(__DIR__.'/..'))).'/';
            if (!$options['maintenance'] || !is_file(__DIR__.'/../tmp/financial_migration.lock') || !$backup
                || filesize($backup) < 1000 || str_starts_with(strtolower(str_replace('\\','/',$backup)), $root)) throw new RuntimeException('Maintenance lock and private database backup required.');
            require_once __DIR__.'/../includes/database_access.php';
            $accessLock = database_access_lock(database_access_paths($database,(int)(getenv('SPP_DB_PORT') ?: 3306)),LOCK_EX | LOCK_NB);
            if (!$accessLock) throw new RuntimeException('An application request is still active; retry after it finishes.');
        }
        if ((int)$db->query("SELECT GET_LOCK('spp_import_repair',0)")->fetch_row()[0] !== 1) throw new RuntimeException('Another repair is running.');
        $locked = true;
    }
    $plan = ir_plan($db);
    echo json_encode(['database'=>$database,'mode'=>$options['apply']?'apply':'check','missing'=>count($plan['missing']),'missing_modules'=>$plan['missing_modules'],'violations'=>$plan['violations'],'mismatches'=>$plan['mismatches']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    if ($plan['violations'] || $plan['mismatches']) throw new RuntimeException('Preflight failed; existing data is preserved.');
    if ($options['apply']) {
        if($plan['missing_modules']){
            require_once __DIR__.'/../sql/savings_authorization_schema.php';
            savings_authorization_schema_apply($db);
            $plan=ir_plan($db);
        }
        ir_apply_schema($db,$plan,static fn($p)=>print(json_encode($p,JSON_THROW_ON_ERROR).PHP_EOL));
        $metadata = ir_repair_manifests($db);
        $after = ir_plan($db);
        if ($after['missing'] || $after['missing_modules'] || $after['violations'] || $after['mismatches']) throw new RuntimeException('Post-repair schema verification failed.');
        echo json_encode(['status'=>'complete','manifest_repairs'=>$metadata],JSON_THROW_ON_ERROR).PHP_EOL;
    } else echo json_encode(['missing_constraints'=>array_map(static fn($r)=>$r['table_name'].'.'.$r['name'],$plan['missing'])],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Repair failed: '.$e->getMessage().PHP_EOL); exit(1); }
finally {
    if ($db && $locked) $db->query("SELECT RELEASE_LOCK('spp_import_repair')");
    if ($accessLock) database_access_release($accessLock);
}
