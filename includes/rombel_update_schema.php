<?php
require_once __DIR__.'/workflow_update_schema.php';

/** Explicit CLI migration; keep unit and relationship checks in existing triggers. */
function rombel_schema_apply(mysqli $db): void {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
    foreach ([
        ['master_kelas_data','jenis',"ENUM('reguler','psb','out','custom') NOT NULL DEFAULT 'reguler'"],
        ['master_kelas_data','nama_custom','VARCHAR(100) NULL'],
        ['bayar_biaya_lain_data','tarif_referensi_snapshot','DECIMAL(15,2) NULL'],
    ] as [$table,$column,$definition]) {
        if (!workflow_column_exists($db,$table,$column)) $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
    $db->query('ALTER TABLE master_kelas_data MODIFY tingkat TINYINT UNSIGNED NULL');
    // Recreate the original guard with only the new nonacademic values added.
    foreach (['master_kelas_data','siswa_data'] as $table) foreach (['bi','bu'] as $suffix) {
        $name=$table.'_'.$suffix;
        $row=$db->query("SHOW CREATE TRIGGER `$name`")->fetch_assoc();
        $sql=$row['SQL Original Statement'];
        $sql=preg_replace('/CREATE DEFINER=.+? TRIGGER /','CREATE TRIGGER ',$sql,1);
        if ($table==='siswa_data') $sql=str_replace("NEW.`KELAS` NOT IN ('0','PSB')", "NEW.`KELAS` NOT IN ('0','PSB','CUSTOM','OUT')",$sql);
        if ($table==='master_kelas_data' && !str_contains($sql,"NEW.jenis IN ('custom','out')")) {
            $sql=str_replace('IF NEW.`tingkat`<>0', "IF NEW.jenis IN ('custom','out') AND NEW.tingkat IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rombel nonakademik tidak memiliki tingkat'; END IF; IF NEW.jenis IN ('reguler','psb') AND NEW.tingkat IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tingkat rombel wajib diisi'; END IF; IF NEW.jenis='psb' AND NEW.tingkat<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tingkat PSB harus nol'; END IF; IF NEW.`tingkat`<>0",$sql);
        }
        $db->query("DROP TRIGGER `$name`");$db->query($sql);
    }
    // Labels are snapshots, not codes. Long custom names must survive every output.
    $columns=$db->query("SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%\\_data' AND COLUMN_NAME IN ('kelas_rombel_snapshot','riwayat_kelas_snapshot')")->fetch_all(MYSQLI_ASSOC);
    $views=['master_kelas','bayar_biaya_lain'];
    foreach ($columns as $column) {
        $table=$column['TABLE_NAME'];$name=$column['COLUMN_NAME'];$nullable=$column['IS_NULLABLE']==='YES'?'NULL':'NOT NULL';
        $db->query("ALTER TABLE `$table` MODIFY `$name` VARCHAR(100) $nullable");$views[]=substr($table,0,-5);
    }
    foreach (array_unique($views) as $view) $db->query("CREATE OR REPLACE VIEW `$view` AS SELECT * FROM `{$view}_data` WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
    for ($unit=1;$unit<=3;$unit++) {
        $db->query('SET @app_unit_id='.$unit);
        $db->query("UPDATE master_kelas SET jenis='psb' WHERE tingkat=0 AND kode_rombel='PSB' AND jenis='reguler'");
        $db->query("INSERT INTO master_kelas(tingkat,kode_rombel,jenis,nama_custom,is_active) SELECT NULL,'OUT','out','Out',1 WHERE NOT EXISTS(SELECT 1 FROM master_kelas WHERE jenis='out')");
    }
    if (!$db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_kelas_data' AND INDEX_NAME='uk_rombel_custom_name'")->num_rows) {
        $db->query('ALTER TABLE master_kelas_data ADD UNIQUE KEY uk_rombel_custom_name(unit_id,nama_custom)');
    }
    $db->query("INSERT IGNORE INTO workflow_migration_log VALUES('rombel_payment_20261017',NOW(),JSON_OBJECT('version',1))");
}
