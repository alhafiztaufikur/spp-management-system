<?php
if (PHP_SAPI !== 'cli') exit(1);
$options = getopt('', ['database:', 'port:', 'mode:', 'backup-proof:']);
$name = $options['database'] ?? '';
$port = (int)($options['port'] ?? 3306);
$mode = $options['mode'] ?? 'preview';
if (!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D', $name) || $port < 1 || $port > 65535 || !in_array($mode, ['preview','apply'], true)) {
    throw new InvalidArgumentException('Gunakan --database=db_spp_nama --port=3306 --mode=preview|apply --backup-proof=PRIVATE_FILE');
}
putenv('SPP_DB_HOST=127.0.0.1');
putenv('SPP_DB_NAME='.$name);
putenv('SPP_DB_PORT='.$port);
require __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
require_once __DIR__.'/../includes/payment_period_schema.php';
$target = lmr_target($koneksi);
if ($target['database_name'] !== $name || (int)$target['port'] !== $port) throw new RuntimeException('Identitas target berbeda.');
$steps = payment_period_schema_plan($koneksi);
$changes = 0;
if ($mode === 'apply' && $steps) {
    $lock = database_access_lock(database_access_paths($name, $port), LOCK_EX);
    try {
        lmr_backup_check($koneksi, $options['backup-proof'] ?? '');
        $readKomite = static fn() => $koneksi->query('SELECT * FROM bayar_komite_data ORDER BY bayar_id,tagihan_komite_id')->fetch_all(MYSQLI_ASSOC);
        $komite = $readKomite();
        $before = lmr_snapshot($koneksi);
        $changes = payment_period_schema_apply($koneksi);
        if ($readKomite() !== $komite || lmr_snapshot($koneksi) !== $before) throw new RuntimeException('Data terlindungi berubah.');
    } finally {
        database_access_release($lock);
    }
}
echo json_encode(['target'=>$target, 'mode'=>$mode, 'steps'=>$steps, 'changes'=>$changes,
    'pending'=>payment_period_schema_plan($koneksi)], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), PHP_EOL;
