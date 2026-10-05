<?php

/** Additive journal: deliberately no cascading links to payments, students or users. */
function payment_activity_schema_apply(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS pembayaran_aktivitas_data (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        unit_id TINYINT UNSIGNED NOT NULL,
        payment_id INT NOT NULL,
        event_key VARCHAR(128) NOT NULL,
        action VARCHAR(32) NOT NULL,
        actor_id INT NULL,
        actor_name VARCHAR(100) NOT NULL DEFAULT '',
        actor_username VARCHAR(50) NOT NULL DEFAULT '',
        actor_role VARCHAR(32) NOT NULL DEFAULT '',
        occurred_at DATETIME NOT NULL,
        authorization_id BIGINT UNSIGNED NULL,
        note TEXT NULL,
        before_snapshot LONGTEXT NULL,
        after_snapshot LONGTEXT NULL,
        reconstructed TINYINT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uk_payment_activity_event (unit_id,event_key),
        KEY idx_payment_activity_payment (unit_id,payment_id,occurred_at,id),
        KEY idx_payment_activity_archive (unit_id,action,occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->query("CREATE OR REPLACE VIEW pembayaran_aktivitas AS
        SELECT * FROM pembayaran_aktivitas_data
        WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
    foreach (['bi','bu','bd'] as $suffix) {
        $name = 'pembayaran_aktivitas_data_' . $suffix;
        $s = $db->prepare('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
        $s->bind_param('s', $name); $s->execute(); $exists = $s->get_result()->num_rows > 0; $s->close();
        if ($exists) continue;
        if ($suffix === 'bi') {
            $db->query("CREATE TRIGGER `$name` BEFORE INSERT ON pembayaran_aktivitas_data FOR EACH ROW
                BEGIN IF current_unit_id() NOT BETWEEN 1 AND 3 OR NEW.unit_id<>current_unit_id()
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Aktivitas lintas unit ditolak'; END IF; END");
        } else {
            $operation = $suffix === 'bu' ? 'UPDATE' : 'DELETE';
            $db->query("CREATE TRIGGER `$name` BEFORE $operation ON pembayaran_aktivitas_data FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Jurnal pembayaran tidak dapat diubah atau dihapus'");
        }
    }
}
