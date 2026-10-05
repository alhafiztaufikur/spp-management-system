<?php
/** CLI-only migration implementation; no HTTP DDL. */
function legacy_schema_ready(mysqli $db): bool {
    return (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND COLUMN_NAME='legacy_pending'")->fetch_row()[0] === 1
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND INDEX_NAME='uk_siswa_unit_nis'")->fetch_row()[0] === 2
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_student_import_data'")->fetch_row()[0]===1
        && (int)$db->query("SELECT COUNT(*) FROM (SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 GROUP BY TABLE_NAME,INDEX_NAME HAVING LOWER(cols) REGEXP '(^|,)no_induk(,|$)' AND FIND_IN_SET('unit_id',cols)=0) unsafe_indexes")->fetch_row()[0]===0
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_student_import_data' AND CONSTRAINT_NAME='fk_legacy_student_unit'")->fetch_row()[0]===2
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='siswa_data' AND k.REFERENCED_COLUMN_NAME='NO_INDUK' AND NOT EXISTS(SELECT 1 FROM information_schema.KEY_COLUMN_USAGE u WHERE u.TABLE_SCHEMA=k.TABLE_SCHEMA AND u.TABLE_NAME=k.TABLE_NAME AND u.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND u.COLUMN_NAME='unit_id' AND u.REFERENCED_COLUMN_NAME='unit_id')")->fetch_row()[0]===0
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND CONSTRAINT_NAME='chk_siswa_legacy_pending'")->fetch_row()[0]===1
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'legacy_guard_%'")->fetch_row()[0]===2*(int)$db->query("SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='siswa_data' AND REFERENCED_COLUMN_NAME='NO_INDUK'")->fetch_row()[0];
}
function legacy_schema_apply(mysqli $db): void {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI migration only');
    $tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetch_all(MYSQLI_ASSOC);
    if (!in_array('siswa_data',array_column($tables,'TABLE_NAME'),true)) throw new RuntimeException('Multiunit migration required first');
    $relations=$db->query("SELECT k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='siswa_data' AND k.REFERENCED_COLUMN_NAME='NO_INDUK'")->fetch_all(MYSQLI_ASSOC);
    foreach($relations as $r){
        $t=$r['TABLE_NAME'];$c=$r['COLUMN_NAME'];
        if((int)$db->query("SELECT COUNT(*) FROM `$t` c LEFT JOIN siswa_data s ON s.NO_INDUK=c.`$c` AND s.unit_id=c.unit_id WHERE c.`$c` IS NOT NULL AND s.id IS NULL")->fetch_row()[0]) throw new RuntimeException('Orphan or cross-unit relation: '.$t);
    }
    $missingRelations=$db->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME LIKE '%_data' AND c.TABLE_NAME NOT IN ('siswa_data','spp_audit_log_data') AND LOWER(c.COLUMN_NAME)='no_induk' AND NOT EXISTS(SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=c.TABLE_SCHEMA AND k.TABLE_NAME=c.TABLE_NAME AND k.COLUMN_NAME=c.COLUMN_NAME AND k.REFERENCED_TABLE_NAME='siswa_data' AND k.REFERENCED_COLUMN_NAME='NO_INDUK')")->fetch_all(MYSQLI_ASSOC);
    $restoreDepositRelation=false;
    foreach($missingRelations as $missingRelation){
        if($missingRelation['TABLE_NAME']!=='titipan_spp_mutasi_data')throw new RuntimeException('Missing student relation: '.$missingRelation['TABLE_NAME']);
        $restoreDepositRelation=true;
    }
    if($restoreDepositRelation){
        $columns=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='titipan_spp_mutasi_data'")->fetch_all(MYSQLI_ASSOC);
        if(!in_array('unit_id',array_column($columns,'COLUMN_NAME'),true))throw new RuntimeException('Historical deposit table has no unit identity');
        $orphans=(int)$db->query('SELECT COUNT(*) FROM titipan_spp_mutasi_data t LEFT JOIN siswa_data s ON s.unit_id=t.unit_id AND s.NO_INDUK=t.no_induk WHERE s.id IS NULL')->fetch_row()[0];
        if($orphans)throw new RuntimeException('Historical deposit rows have no matching student: '.$orphans);
    }
    foreach(['siswa_data_bi','siswa_data_bu'] as $trigger){$body=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$trigger'")->fetch_row();if(!$body||!str_contains($body[0],"NEW.`KELAS` NOT IN ('0','PSB')"))throw new RuntimeException('Unknown or missing student unit guard');}
    echo "DDL stage: student marker and unit identity\n";
    $pending=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND COLUMN_NAME='legacy_pending'")->fetch_row()[0];
    if(!$pending) $db->query("ALTER TABLE siswa_data ADD legacy_pending TINYINT(1) NOT NULL DEFAULT 0, ADD CONSTRAINT chk_siswa_legacy_pending CHECK (legacy_pending IN (0,1) AND (legacy_pending=0 OR (is_active=0 AND KELAS='LEGACY')))");
    if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND CONSTRAINT_NAME='chk_siswa_legacy_pending'")->fetch_row()[0])$db->query("ALTER TABLE siswa_data ADD CONSTRAINT chk_siswa_legacy_pending CHECK (legacy_pending IN (0,1) AND (legacy_pending=0 OR (is_active=0 AND KELAS='LEGACY')))");
    $key=(int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND INDEX_NAME='uk_siswa_unit_nis'")->fetch_row()[0];
    if(!$key) $db->query('ALTER TABLE siswa_data ADD UNIQUE KEY uk_siswa_unit_nis (unit_id,NO_INDUK)');
    if($restoreDepositRelation){
        // Preserve pre-retirement deposit history and give its unit/NIS pair a real FK.
        $db->query('ALTER TABLE titipan_spp_mutasi_data ADD CONSTRAINT fk_titipan_spp_student_unit FOREIGN KEY (unit_id,no_induk) REFERENCES siswa_data(unit_id,NO_INDUK) ON DELETE RESTRICT ON UPDATE CASCADE');
        $relations[]=['TABLE_NAME'=>'titipan_spp_mutasi_data','COLUMN_NAME'=>'no_induk','CONSTRAINT_NAME'=>'fk_titipan_spp_student_unit','DELETE_RULE'=>'RESTRICT','UPDATE_RULE'=>'CASCADE'];
    }
    foreach($relations as $r){
        $t=$r['TABLE_NAME'];$c=$r['COLUMN_NAME'];$old=$r['CONSTRAINT_NAME'];
        $size=(int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND CONSTRAINT_NAME='$old'")->fetch_row()[0];
        if($size===2)continue;
        $new='fk_unit_nis_'.substr(hash('sha256',$t.'.'.$c),0,16);
        $db->query("ALTER TABLE `$t` DROP FOREIGN KEY `$old`, ADD CONSTRAINT `$new` FOREIGN KEY (unit_id,`$c`) REFERENCES siswa_data(unit_id,NO_INDUK) ON DELETE {$r['DELETE_RULE']} ON UPDATE {$r['UPDATE_RULE']}");
    }
    $global=(int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND INDEX_NAME='NO_INDUK' AND NON_UNIQUE=0")->fetch_row()[0];
    if($global) $db->query('ALTER TABLE siswa_data DROP INDEX NO_INDUK, ADD KEY idx_siswa_nis (NO_INDUK)');
    // A per-period unique or savings account keyed only by NIS would still collide across units.
    $indexes=$db->query("SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 AND TABLE_NAME LIKE '%_data' GROUP BY TABLE_NAME,INDEX_NAME")->fetch_all(MYSQLI_ASSOC);
    foreach($indexes as $index){
        $cols=explode(',',$index['cols']);
        if(in_array('unit_id',$cols,true)||!array_filter($cols,static fn($c)=>strcasecmp($c,'no_induk')===0))continue;
        $table=$index['TABLE_NAME'];$name=$index['INDEX_NAME'];
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table.$name)||$name==='PRIMARY')throw new RuntimeException('Student-number index requires manual review');
        $quoted=implode(',',array_map(static fn($c)=>'`'.$c.'`',$cols));
        $db->query("ALTER TABLE `$table` DROP INDEX `$name`, ADD UNIQUE KEY `$name` (unit_id,$quoted)");
    }
    echo "DDL stage: student/unit constraints complete; guards and manifest\n";
    // Recreate only the student grade guards: LEGACY is allowed exclusively while pending.
    foreach(['bi'=>'INSERT','bu'=>'UPDATE'] as $suffix=>$event){
        $name='siswa_data_'.$suffix;
        $row=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_assoc();
        if(!$row)throw new RuntimeException('Missing unit student guard');
        $body=str_replace("NEW.`KELAS` NOT IN ('0','PSB')","NEW.`KELAS` NOT IN ('0','PSB') AND NOT (NEW.legacy_pending=1 AND NEW.`KELAS`='LEGACY')",$row['ACTION_STATEMENT']);
        if(!str_contains($body,'NEW.legacy_pending'))throw new RuntimeException('Unknown student grade guard');
        // Avoid repeated replacement on migration replay.
        $body=str_replace(" AND NOT (NEW.legacy_pending=1 AND NEW.`KELAS`='LEGACY') AND NOT (NEW.legacy_pending=1 AND NEW.`KELAS`='LEGACY')"," AND NOT (NEW.legacy_pending=1 AND NEW.`KELAS`='LEGACY')",$body);
        if(!str_contains($body,'Legacy tidak boleh memiliki rombel')){
            $body=substr(rtrim($body),0,-3)." IF NEW.legacy_pending=1 AND NEW.master_kelas_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Legacy tidak boleh memiliki rombel'; END IF; END";
        }
        $db->query("DROP TRIGGER `$name`");
        $db->query("CREATE TRIGGER `$name` BEFORE $event ON siswa_data FOR EACH ROW $body");
    }
    $db->query("CREATE OR REPLACE VIEW siswa AS SELECT * FROM siswa_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
    $db->query("CREATE TABLE IF NOT EXISTS legacy_student_import_data (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL UNIQUE,
        unit_id TINYINT UNSIGNED NOT NULL, source_hash CHAR(64) NOT NULL,
        source_row INT UNSIGNED NOT NULL, source_name VARCHAR(255) NOT NULL,
        source_class VARCHAR(255) NOT NULL, raw_data JSON NOT NULL,
        imported_by INT NOT NULL, imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        activated_by INT NULL, activated_at TIMESTAMP NULL,
        UNIQUE KEY uk_legacy_source_row(unit_id,source_hash,source_row),
        CONSTRAINT fk_legacy_student FOREIGN KEY(student_id) REFERENCES siswa_data(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
    if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data' AND INDEX_NAME='uk_siswa_unit_id'")->fetch_row()[0])$db->query('ALTER TABLE siswa_data ADD UNIQUE KEY uk_siswa_unit_id(unit_id,id)');
    $fk=$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_student_import_data' AND CONSTRAINT_NAME='fk_legacy_student'")->fetch_row()[0];
    if((int)$fk===1)$db->query('ALTER TABLE legacy_student_import_data DROP FOREIGN KEY fk_legacy_student, ADD CONSTRAINT fk_legacy_student_unit FOREIGN KEY(unit_id,student_id) REFERENCES siswa_data(unit_id,id) ON DELETE RESTRICT');
    $db->query("CREATE OR REPLACE VIEW legacy_student_import AS SELECT * FROM legacy_student_import_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
    // DB-level pending guard on every relation with a student number.
    foreach($relations as $r){
        $t=$r['TABLE_NAME'];$c=$r['COLUMN_NAME'];
        foreach(['i'=>'INSERT','u'=>'UPDATE'] as $suffix=>$event){
            $name='legacy_guard_'.substr(hash('sha256',$t),0,16).'_'.$suffix;
            $db->query("DROP TRIGGER IF EXISTS `$name`");
            $db->query("CREATE TRIGGER `$name` BEFORE $event ON `$t` FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM siswa_data s WHERE s.unit_id=NEW.unit_id AND s.NO_INDUK=NEW.`$c` AND s.legacy_pending=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Siswa Legacy harus diaktifkan dan ditempatkan terlebih dahulu'; END IF; END");
        }
    }
    echo "Legacy schema and unit/NIS guards verified\n";
}
