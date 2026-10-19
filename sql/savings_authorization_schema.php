<?php
/** Additive multiunit schema. No existing financial row is rewritten. */
function savings_authorization_schema_apply(mysqli $db): void {
    if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transaksi_m'")->fetch_row()[0])throw new RuntimeException('Migrasi unit wajib tersedia dahulu.');
    $db->query("CREATE TABLE IF NOT EXISTS tabungan_otorisasi_data (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
        jenis ENUM('masuk','keluar') NOT NULL, transaction_id INT NOT NULL,
        transaction_reference VARCHAR(40) NOT NULL, no_induk_snapshot VARCHAR(10) NOT NULL,
        student_name_snapshot VARCHAR(100) NOT NULL, action ENUM('edit','hapus') NOT NULL,
        status ENUM('pending','approved','rejected','cancelled','failed') NOT NULL DEFAULT 'pending',
        pending_id INT GENERATED ALWAYS AS (CASE WHEN status='pending' THEN transaction_id ELSE NULL END) STORED,
        before_snapshot LONGTEXT NOT NULL, snapshot_hash CHAR(64) NOT NULL, proposed_payload LONGTEXT NULL,
        request_reason VARCHAR(500) NOT NULL, requested_by INT NOT NULL, decided_by INT NULL,
        decision_note VARCHAR(1000) NULL, requested_at DATETIME NOT NULL, decided_at DATETIME NULL, applied_at DATETIME NULL,
        UNIQUE KEY uk_savings_pending(unit_id,jenis,pending_id), KEY idx_savings_request(unit_id,jenis,transaction_id,id),
        KEY idx_savings_queue(unit_id,status,requested_at,id),
        FOREIGN KEY(unit_id) REFERENCES unit_sekolah(id), FOREIGN KEY(requested_by) REFERENCES admin(id) ON DELETE RESTRICT,
        FOREIGN KEY(decided_by) REFERENCES admin(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->query("CREATE TABLE IF NOT EXISTS tabungan_aktivitas_data (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unit_id TINYINT UNSIGNED NOT NULL,
        jenis ENUM('masuk','keluar') NOT NULL, transaction_id INT NOT NULL, event_key VARCHAR(128) NOT NULL,
        action VARCHAR(32) NOT NULL, actor_id INT NULL, actor_name VARCHAR(100) NOT NULL DEFAULT '',
        actor_username VARCHAR(50) NOT NULL DEFAULT '', actor_role VARCHAR(32) NOT NULL DEFAULT '',
        occurred_at DATETIME NOT NULL, authorization_id BIGINT UNSIGNED NULL, note TEXT NULL,
        before_snapshot LONGTEXT NULL, after_snapshot LONGTEXT NULL, reconstructed TINYINT NOT NULL DEFAULT 0,
        UNIQUE KEY uk_savings_event(unit_id,event_key), KEY idx_savings_activity(unit_id,jenis,transaction_id,occurred_at,id),
        FOREIGN KEY(unit_id) REFERENCES unit_sekolah(id), FOREIGN KEY(actor_id) REFERENCES admin(id) ON DELETE RESTRICT,
        FOREIGN KEY(authorization_id) REFERENCES tabungan_otorisasi_data(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    foreach(['tabungan_otorisasi','tabungan_aktivitas'] as $name){
        $base=$name.'_data';
        $db->query("CREATE OR REPLACE VIEW $name AS SELECT * FROM $base WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
        foreach(['bi','bu','bd'] as $event){
            $trigger=$base.'_'.$event;
            $s=$db->prepare('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$s->bind_param('s',$trigger);$s->execute();$exists=$s->get_result()->num_rows;$s->close();if($exists)continue;
            if($event==='bi')$sql="BEFORE INSERT ON $base FOR EACH ROW BEGIN IF current_unit_id() NOT BETWEEN 1 AND 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pilih unit operasional'; END IF; SET NEW.unit_id=current_unit_id(); END";
            elseif($name==='tabungan_aktivitas')$sql=($event==='bu'?'BEFORE UPDATE':'BEFORE DELETE')." ON $base FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit tabungan tidak dapat diubah atau dihapus'";
            else $sql=($event==='bu'?'BEFORE UPDATE':'BEFORE DELETE')." ON $base FOR EACH ROW BEGIN IF current_unit_id() NOT BETWEEN 1 AND 3 OR OLD.unit_id<>current_unit_id()".($event==='bu'?' OR NEW.unit_id<>OLD.unit_id':'')." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Perubahan lintas unit ditolak'; END IF; END";
            $db->query('CREATE TRIGGER '.$trigger.' '.$sql);
        }
    }
}
