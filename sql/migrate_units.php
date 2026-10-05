<?php
/**
 * One-time, additive multi-unit migration. Run on a backup clone first.
 * Requires a DB account allowed to create views, routines and triggers.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/readiness_migration_guard.php';
$migrationTarget = (string)getenv('SPP_DB_NAME');
if ($migrationTarget === 'db_spp' && !in_array('--apply', $argv, true)) {
    throw new RuntimeException('Migrasi utama memerlukan --apply dan prasyarat persetujuan/backup.');
}
readiness_migration_assert_apply_allowed($argv, $migrationTarget);
require_once __DIR__ . '/../koneksi.php';

function unit_migration_table(mysqli $db, string $name): ?string {
    $stmt = $db->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->bind_param('s', $name); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row['TABLE_TYPE'] ?? null;
}

$tables = [
    'master_kelas','siswa','siswa_audit_log','bayar','transaksi_otorisasi',
    'bayar_spp_periode','master_spp_tahun','master_spp_tarif','tagihan_spp',
    'spp_alokasi_batch','spp_alokasi','spp_audit_log',
    'master_biaya_lain','tagihan_biaya_lain','tagihan_biaya_lain_audit_log',
    'bayar_biaya_lain','tahun_ajaran','siswa_tahun_ajaran','tagihan_komite',
    'bayar_komite','daftar_ulang','tagihan_daftar_ulang','daftar_ulang_audit_log',
    'bayar_du','tagihan_tahunan_siswa','bayar_tahunan_siswa','tabungan',
    'transaksi_m','transaksi_k',
];

if (unit_migration_table($koneksi, 'siswa') === 'VIEW') {
    require_once __DIR__ . "/payment_activity_schema.php";
    payment_activity_schema_apply($koneksi);
    echo "SKIPPED: views multiunit sudah tersedia.\n";
    exit(0);
}
foreach ($tables as $table) {
    if (unit_migration_table($koneksi, $table) !== 'BASE TABLE') {
        throw new RuntimeException("Tabel {$table} tidak ditemukan; migrasi dibatalkan.");
    }
}
if (unit_migration_table($koneksi, 'unit_sekolah')) {
    throw new RuntimeException('Migrasi pernah dimulai tetapi belum selesai. Periksa database sebelum melanjutkan.');
}

$koneksi->query("CREATE TABLE unit_sekolah (
  id TINYINT UNSIGNED PRIMARY KEY,
  kode VARCHAR(3) NOT NULL UNIQUE,
  nama VARCHAR(32) NOT NULL,
  tingkat_awal TINYINT UNSIGNED NOT NULL,
  tingkat_akhir TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB");
$koneksi->query("INSERT INTO unit_sekolah VALUES (1,'SD','SD',1,6),(2,'SMP','SMP',7,9),(3,'SMA','SMA',10,12)");
$koneksi->query("ALTER TABLE admin MODIFY role ENUM('super_admin','admin','bendahara','kasir') NOT NULL DEFAULT 'admin', ADD COLUMN unit_id TINYINT UNSIGNED NULL DEFAULT 1, ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1, ADD KEY idx_admin_unit_role (unit_id,role,is_active), ADD CONSTRAINT fk_admin_unit FOREIGN KEY (unit_id) REFERENCES unit_sekolah(id)");

foreach ($tables as $table) {
    $koneksi->query("ALTER TABLE `{$table}` ADD COLUMN unit_id TINYINT UNSIGNED NOT NULL DEFAULT 1, ADD KEY `idx_unit_id` (unit_id)");
}

// Existing global uniques become unique within a unit. Student NIS is converted by the final Legacy identity stage.
$koneksi->query('ALTER TABLE master_kelas DROP INDEX uk_master_kelas_tingkat_rombel, ADD UNIQUE KEY uk_master_kelas_unit_tingkat_rombel (unit_id,tingkat,kode_rombel)');
$koneksi->query('ALTER TABLE tahun_ajaran DROP INDEX uk_tahun_ajaran_label, ADD UNIQUE KEY uk_tahun_ajaran_unit_label (unit_id,label)');
$koneksi->query('ALTER TABLE master_biaya_lain DROP INDEX nama, ADD UNIQUE KEY uk_master_biaya_lain_unit_nama (unit_id,nama)');
$koneksi->query('ALTER TABLE daftar_ulang DROP INDEX uk_daftar_ulang_period_class, ADD UNIQUE KEY uk_daftar_ulang_unit_period_class (unit_id,th_ajaran,kelas)');
$koneksi->query('ALTER TABLE siswa DROP INDEX uk_siswa_no_induk_diknas, ADD UNIQUE KEY uk_siswa_unit_diknas (unit_id,NO_induk_diknas)');

foreach ([
    'master_kelas' => 'chk_master_kelas_tingkat',
    'master_spp_tarif' => 'chk_master_spp_tingkat',
    'siswa' => 'chk_siswa_kelas_sd',
    'siswa_tahun_ajaran' => 'chk_penempatan_kelas_sd',
    'tagihan_daftar_ulang' => 'chk_tagihan_du_kelas',
    'tagihan_tahunan_siswa' => 'chk_tagihan_tahunan_kelas',
] as $table => $check) {
    $stmt = $koneksi->prepare("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='CHECK'");
    $stmt->bind_param('ss', $table, $check); $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_assoc()['n'] > 0; $stmt->close();
    if ($exists) $koneksi->query("ALTER TABLE `{$table}` DROP CHECK `{$check}`");
}

// A single rename updates all existing foreign keys to point at base tables.
$renames = array_map(static fn($table) => "`{$table}` TO `{$table}_data`", $tables);
$koneksi->query('RENAME TABLE ' . implode(', ', $renames));
$koneksi->query('ALTER TABLE daftar_ulang_data MODIFY kelas VARCHAR(2) NULL');

// Some older databases do not cascade detail rows when a payment is removed.
foreach (['bayar_du','bayar_spp_periode','bayar_tahunan_siswa'] as $child) {
    $base = $child . '_data';
    $stmt = $koneksi->prepare("SELECT COUNT(*) n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='bayar_id' AND REFERENCED_TABLE_NAME='bayar_data'");
    $stmt->bind_param('s', $base); $stmt->execute();
    $hasForeignKey = (int)$stmt->get_result()->fetch_assoc()['n'] > 0; $stmt->close();
    if ($hasForeignKey) continue;
    $orphan = (int)$koneksi->query("SELECT COUNT(*) n FROM `{$base}` d LEFT JOIN bayar_data b ON b.id=d.bayar_id WHERE d.bayar_id IS NOT NULL AND b.id IS NULL")->fetch_assoc()['n'];
    if ($orphan > 0) throw new RuntimeException("{$child} memiliki {$orphan} detail pembayaran tanpa induk. Perbaiki sebelum migrasi.");
    $koneksi->query("ALTER TABLE `{$base}` ADD CONSTRAINT `fk_{$child}_unit_bayar` FOREIGN KEY (bayar_id) REFERENCES bayar_data(id) ON DELETE CASCADE");
}

$koneksi->query("CREATE FUNCTION current_unit_id() RETURNS INT NOT DETERMINISTIC NO SQL RETURN COALESCE(@app_unit_id, 1)");
foreach ($tables as $table) {
    $koneksi->query("CREATE VIEW `{$table}` AS SELECT * FROM `{$table}_data` WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
}

// Database guards prevent a forged child ID from linking different units.
$refs = [];
$result = $koneksi->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME LIKE '%\\_data' AND REFERENCED_TABLE_NAME LIKE '%\\_data'");
foreach ($result as $row) $refs[$row['TABLE_NAME']][] = $row;

// Older installations may lack foreign keys that are present in schema.sql.
$inferredParents = [
    'NO_INDUK' => ['siswa_data','NO_INDUK'], 'no_induk' => ['siswa_data','NO_INDUK'],
    'siswa_id' => ['siswa_data','id'], 'master_kelas_id' => ['master_kelas_data','id'],
    'tahun_ajaran_id' => ['tahun_ajaran_data','id'], 'penempatan_id' => ['siswa_tahun_ajaran_data','id'],
    'bayar_id' => ['bayar_data','id'], 'batch_id' => ['spp_alokasi_batch_data','id'],
    'master_spp_tahun_id' => ['master_spp_tahun_data','id'],
    'master_spp_tarif_id' => ['master_spp_tarif_data','id'],
    'master_biaya_lain_id' => ['master_biaya_lain_data','id'],
    'master_daftar_ulang_id' => ['daftar_ulang_data','id'],
    'tagihan_daftar_ulang_id' => ['tagihan_daftar_ulang_data','id'],
    'tagihan_spp_id' => ['tagihan_spp_data','id'],
    'tagihan_komite_id' => ['tagihan_komite_data','id'],
    'tagihan_biaya_lain_id' => ['tagihan_biaya_lain_data','id'],
    'tagihan_tahunan_id' => ['tagihan_tahunan_siswa_data','id'],
];
foreach ($tables as $table) {
    $base = $table . '_data';
    $columns = $koneksi->query("SHOW COLUMNS FROM `{$base}`");
    $known = array_column($refs[$base] ?? [], 'COLUMN_NAME');
    foreach ($columns as $column) {
        $name = $column['Field'];
        if ($table === 'siswa' && $name === 'NO_INDUK') continue;
        if (!isset($inferredParents[$name]) || in_array($name, $known, true)) continue;
        [$parent, $parentColumn] = $inferredParents[$name];
        $refs[$base][] = ['COLUMN_NAME'=>$name,'REFERENCED_TABLE_NAME'=>$parent,'REFERENCED_COLUMN_NAME'=>$parentColumn];
    }
}

$gradeColumns = [
    'master_kelas_data' => 'tingkat', 'siswa_data' => 'KELAS',
    'master_spp_tarif_data' => 'tingkat',
    'siswa_tahun_ajaran_data' => 'kelas',
    'tagihan_daftar_ulang_data' => 'kelas_snapshot',
    'tagihan_tahunan_siswa_data' => 'kelas_snapshot',
];
foreach ($tables as $table) {
    $base = $table . '_data';
    $conditions = [];
    foreach ($refs[$base] ?? [] as $ref) {
        $column = $ref['COLUMN_NAME']; $parent = $ref['REFERENCED_TABLE_NAME'];
        $parentColumn = $ref['REFERENCED_COLUMN_NAME'];
        $conditions[] = "IF NEW.`{$column}` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `{$parent}` WHERE `{$parentColumn}`=NEW.`{$column}` AND unit_id=NEW.unit_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Relasi lintas unit ditolak'; END IF;";
    }
    if (isset($gradeColumns[$base])) {
        $column = $gradeColumns[$base];
        $isEntryClass = in_array($base, ['master_kelas_data', 'master_spp_tarif_data'], true)
            ? "NEW.`{$column}`<>0" : "NEW.`{$column}` NOT IN ('0','PSB')";
        $conditions[] = "IF {$isEntryClass} AND NOT EXISTS (SELECT 1 FROM unit_sekolah WHERE id=NEW.unit_id AND CAST(NEW.`{$column}` AS UNSIGNED) BETWEEN tingkat_awal AND tingkat_akhir AND CAST(NEW.`{$column}` AS CHAR)=CAST(CAST(NEW.`{$column}` AS UNSIGNED) AS CHAR)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tingkat kelas tidak sesuai unit'; END IF;";
    }
    $checks = implode(' ', $conditions);
    $koneksi->query("CREATE TRIGGER `{$base}_bi` BEFORE INSERT ON `{$base}` FOR EACH ROW BEGIN IF current_unit_id() NOT BETWEEN 1 AND 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pilih unit operasional'; END IF; SET NEW.unit_id=current_unit_id(); {$checks} END");
    $koneksi->query("CREATE TRIGGER `{$base}_bu` BEFORE UPDATE ON `{$base}` FOR EACH ROW BEGIN IF current_unit_id() NOT BETWEEN 1 AND 3 OR OLD.unit_id<>current_unit_id() OR NEW.unit_id<>OLD.unit_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Perubahan lintas unit ditolak'; END IF; {$checks} END");
}

// Prepare years and class templates independently for the new units.
foreach ([2 => [7,9], 3 => [10,12]] as $unitId => [$start,$end]) {
    $koneksi->query("SET @app_unit_id={$unitId}");
    $koneksi->query("INSERT INTO tahun_ajaran (label,tanggal_mulai,tanggal_selesai,status) SELECT label,tanggal_mulai,tanggal_selesai,'draft' FROM tahun_ajaran_data WHERE unit_id=1");
    $koneksi->query("INSERT INTO master_kelas (tingkat,kode_rombel,is_placeholder,is_active) VALUES (0,'PSB',0,1)");
    for ($level=$start; $level<=$end; $level++) {
        foreach (range('A','J') as $code) {
            $stmt=$koneksi->prepare('INSERT INTO master_kelas (tingkat,kode_rombel,is_placeholder,is_active) VALUES (?,?,0,1)');
            $stmt->bind_param('is',$level,$code); $stmt->execute(); $stmt->close();
        }
    }
}
$koneksi->query('SET @app_unit_id=1');
echo "OK: migrasi unit selesai. Jalankan bootstrap akun dan verifikasi sebelum membuka aplikasi.\n";

require_once __DIR__.'/../includes/legacy_schema.php';
legacy_schema_apply($koneksi);

require_once __DIR__.'/../includes/financial_components_schema.php';
financial_components_apply($koneksi);

require_once __DIR__ . "/payment_activity_schema.php";
payment_activity_schema_apply($koneksi);
