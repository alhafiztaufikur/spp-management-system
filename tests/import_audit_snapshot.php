<?php
/** Read-only, streaming comparison of an imported database and its isolated restore. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(getenv('SPP_DB_HOST') ?: '127.0.0.1', getenv('SPP_DB_USER') ?: 'root',
    getenv('SPP_DB_PASS') ?: '', getenv('SPP_DB_NAME') ?: 'db_spp_test2', (int)(getenv('SPP_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');
require_once __DIR__.'/../includes/database_backup.php';
$schema = db_backup_schema($db);
$keys = [];
foreach ($db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC) as $r) $keys[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
$tables = [];
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
foreach ($schema['objects']['tables'] as $name => $ignored) {
    if (!isset($keys[$name])) throw new RuntimeException('Explicit primary key required: '.$name);
    $order = implode(',', array_map(static fn($c) => '`'.str_replace('`','``',$c).'`', $keys[$name]));
    $rows = $db->query('SELECT * FROM `'.str_replace('`','``',$name).'` ORDER BY '.$order, MYSQLI_USE_RESULT);
    $hash = hash_init('sha256'); $count = 0;
    try { while ($row = $rows->fetch_assoc()) { hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"); $count++; } }
    finally { $rows->free(); }
    $tables[$name] = ['count'=>$count, 'sha256'=>hash_final($hash)];
}
$db->rollback();
echo json_encode(['schema'=>$schema, 'tables'=>$tables], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
