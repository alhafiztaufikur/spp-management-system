<?php
/** Optional, CLI-installed finance archive. HTTP never performs DDL. */
function legacy_finance_ready(mysqli $db): bool {
    return (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('legacy_finance_sources_data','legacy_finance_rows_data','legacy_finance_maps_data','legacy_finance_reviews_data','legacy_savings_openings_data','legacy_debt_masters_data','legacy_debts_data','legacy_finance_audit_data') AND TABLE_TYPE='BASE TABLE'")->fetch_row()[0] === 8
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('legacy_finance_sources','legacy_finance_rows','legacy_finance_maps','legacy_finance_reviews','legacy_savings_openings','legacy_debt_masters','legacy_debts','legacy_finance_audit')")->fetch_row()[0] === 8
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_finance_rows_data' AND COLUMN_NAME='source_identity'")->fetch_row()[0]===1
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'lf_guard_%'")->fetch_row()[0] === 7;
}
function legacy_finance_schema_apply(mysqli $db): void {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Migrasi hanya melalui CLI.');
    require_once __DIR__.'/legacy_schema.php';
    if (!legacy_schema_ready($db)) throw new RuntimeException('Pasang migrasi identitas Legacy terlebih dahulu.');
    $definitions = [
        'legacy_finance_sources'=>"id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
            backup_hash CHAR(64) NOT NULL, source_name VARCHAR(255) NOT NULL, source_date VARCHAR(50) NULL,
            manifest JSON NOT NULL, fingerprint CHAR(64) NOT NULL, row_count INT UNSIGNED NOT NULL,
            published TINYINT NOT NULL DEFAULT 0, imported_by INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY(unit_id,backup_hash), UNIQUE KEY(unit_id,id), CHECK(published IN (0,1)),
            FOREIGN KEY(unit_id) REFERENCES unit_sekolah(id), FOREIGN KEY(imported_by) REFERENCES admin(id)",
        'legacy_finance_rows'=>"id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
            source_id BIGINT NOT NULL, source_table VARCHAR(64) NOT NULL, row_key CHAR(64) NOT NULL,
            row_hash CHAR(64) NOT NULL, occurrence INT UNSIGNED NOT NULL, source_nis VARCHAR(255) NULL,
            source_date VARCHAR(50) NULL, source_operator VARCHAR(255) NULL, amounts JSON NOT NULL, raw_data JSON NOT NULL,
            reference_only TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY(unit_id,source_id,row_key), KEY(unit_id,source_id,source_nis), KEY(unit_id,source_table),
            FOREIGN KEY(unit_id,source_id) REFERENCES legacy_finance_sources_data(unit_id,id) ON DELETE RESTRICT,
            CHECK(reference_only IN (0,1)), CHECK(occurrence>0)",
        'legacy_finance_maps'=>"id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
            source_id BIGINT NOT NULL, source_nis VARCHAR(255) NOT NULL, source_name VARCHAR(255) NULL,
            student_id INT NULL, match_status VARCHAR(30) NOT NULL, reason VARCHAR(1000) NOT NULL,
            UNIQUE KEY(unit_id,source_id,source_nis), UNIQUE KEY(unit_id,id),
            FOREIGN KEY(unit_id,source_id) REFERENCES legacy_finance_sources_data(unit_id,id),
            FOREIGN KEY(unit_id,student_id) REFERENCES siswa_data(unit_id,id) ON DELETE RESTRICT",
        'legacy_finance_reviews'=>"id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
            map_id BIGINT NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, status VARCHAR(30) NOT NULL DEFAULT 'held',
            savings_result JSON NOT NULL, review_data JSON NULL, reviewed_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY(unit_id,map_id), UNIQUE KEY(unit_id,id), FOREIGN KEY(unit_id,map_id) REFERENCES legacy_finance_maps_data(unit_id,id),
            FOREIGN KEY(reviewed_by) REFERENCES admin(id), CHECK(status IN ('ready','held','applied'))",
        'legacy_savings_openings'=>"id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
            student_id INT NOT NULL, review_id BIGINT NOT NULL, amount DECIMAL(15,2) NOT NULL, effective_date DATE NOT NULL,
            evidence VARCHAR(1000) NOT NULL, applied_by INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at TIMESTAMP NULL, cancelled_by INT NULL, cancel_reason VARCHAR(1000) NULL,
            UNIQUE KEY(unit_id,student_id), UNIQUE KEY(unit_id,review_id),
            FOREIGN KEY(unit_id,student_id) REFERENCES siswa_data(unit_id,id) ON DELETE RESTRICT,
            FOREIGN KEY(unit_id,review_id) REFERENCES legacy_finance_reviews_data(unit_id,id),
            FOREIGN KEY(applied_by) REFERENCES admin(id), FOREIGN KEY(cancelled_by) REFERENCES admin(id), CHECK(amount>=0)",
        'legacy_debt_masters'=>"id INT NOT NULL PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL, component VARCHAR(100) NOT NULL,
            period VARCHAR(20) NOT NULL, obligation_key CHAR(64) NOT NULL, UNIQUE KEY(unit_id,obligation_key), UNIQUE KEY(unit_id,id),
            FOREIGN KEY(id) REFERENCES master_biaya_lain_data(id) ON DELETE RESTRICT, FOREIGN KEY(unit_id) REFERENCES unit_sekolah(id)",
        'legacy_debts'=>"id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
            student_id INT NOT NULL, review_id BIGINT NOT NULL, bill_id BIGINT NOT NULL, master_id INT NOT NULL,
            obligation_key CHAR(64) NOT NULL, amount DECIMAL(15,2) NOT NULL, effective_date DATE NOT NULL, evidence VARCHAR(1000) NOT NULL,
            source_row_key CHAR(64) NOT NULL, applied_by INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY(unit_id,student_id,obligation_key), UNIQUE KEY(bill_id),
            FOREIGN KEY(unit_id,student_id) REFERENCES siswa_data(unit_id,id),
            FOREIGN KEY(unit_id,review_id) REFERENCES legacy_finance_reviews_data(unit_id,id),
            FOREIGN KEY(bill_id) REFERENCES tagihan_biaya_lain_data(id), FOREIGN KEY(unit_id,master_id) REFERENCES legacy_debt_masters_data(unit_id,id),
            FOREIGN KEY(applied_by) REFERENCES admin(id), CHECK(amount>0)",
        'legacy_finance_audit'=>"id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
            review_id BIGINT NULL, actor_id INT NOT NULL, action VARCHAR(40) NOT NULL, details JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY(unit_id,review_id),
            FOREIGN KEY(unit_id,review_id) REFERENCES legacy_finance_reviews_data(unit_id,id), FOREIGN KEY(actor_id) REFERENCES admin(id)"
    ];
    foreach ($definitions as $name=>$definition) {
        $db->query("CREATE TABLE IF NOT EXISTS {$name}_data ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $db->query("CREATE OR REPLACE VIEW `$name` AS SELECT * FROM {$name}_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
    }
    if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_finance_rows_data' AND COLUMN_NAME='source_identity'")->fetch_row()[0]){
        $db->query('ALTER TABLE legacy_finance_rows_data ADD source_identity VARCHAR(255) GENERATED ALWAYS AS (UPPER(TRIM(source_nis))) STORED, ADD KEY lf_identity(unit_id,source_id,source_identity), ADD KEY lf_source(source_id)');
        $db->query('CREATE OR REPLACE VIEW legacy_finance_rows AS SELECT * FROM legacy_finance_rows_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION');
    }
    foreach(['master_biaya_lain_data','tagihan_biaya_lain_data'] as $table){
        if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND INDEX_NAME='lf_unit_id'")->fetch_row()[0])$db->query("ALTER TABLE $table ADD UNIQUE KEY lf_unit_id(unit_id,id)");
    }
    foreach(['legacy_debt_masters_data'=>['lf_master_unit','unit_id,id','master_biaya_lain_data'],'legacy_debts_data'=>['lf_bill_unit','unit_id,bill_id','tagihan_biaya_lain_data']] as $table=>$fk){
        if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND CONSTRAINT_NAME='{$fk[0]}'")->fetch_row()[0])$db->query("ALTER TABLE $table ADD CONSTRAINT {$fk[0]} FOREIGN KEY({$fk[1]}) REFERENCES {$fk[2]}(unit_id,id)");
    }
    $name='lf_guard_bill_bi';
    if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_row()[0])
        $db->query("CREATE TRIGGER $name BEFORE INSERT ON tagihan_biaya_lain_data FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM legacy_debt_masters_data WHERE id=NEW.master_biaya_lain_id) AND COALESCE(@legacy_finance_apply,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tunggakan Legacy hanya melalui peninjauan'; END IF; END");
    // Reserved masters can only be created through review. Normal master forms cannot reprice or delete them.
    foreach (['bu'=>'UPDATE','bd'=>'DELETE'] as $suffix=>$event) {
        $name='lf_guard_master_'.$suffix;
        if (!(int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_row()[0])
            $db->query("CREATE TRIGGER $name BEFORE $event ON master_biaya_lain_data FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM legacy_debt_masters_data WHERE id=OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Master Tunggakan Legacy dilindungi'; END IF; END");
    }
    foreach (['legacy_finance_rows_data'=>'rows','legacy_savings_openings_data'=>'opening'] as $table=>$short) {
        foreach (['bu'=>'UPDATE','bd'=>'DELETE'] as $suffix=>$event) {
            $name='lf_guard_'.$short.'_'.$suffix;
            $condition=$short==='opening' && $event==='UPDATE' ? "COALESCE(@legacy_finance_cancel,0)<>1 OR NEW.amount<>OLD.amount OR NEW.student_id<>OLD.student_id OR NEW.review_id<>OLD.review_id OR NEW.unit_id<>OLD.unit_id OR NEW.effective_date<>OLD.effective_date OR NEW.evidence<>OLD.evidence OR NEW.applied_by<>OLD.applied_by OR OLD.cancelled_at IS NOT NULL OR NEW.cancelled_at IS NULL" : 'TRUE';
            if (!(int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_row()[0])
                $db->query("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Catatan Legacy tidak dapat diubah langsung'; END IF; END");
        }
    }
    if (!legacy_finance_ready($db)) throw new RuntimeException('Skema keuangan belum lengkap.');
}
