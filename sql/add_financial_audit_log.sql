-- =========================================================
-- Audit event append-only untuk transaksi finansial dan akun.
-- Tidak melakukan backfill atau menebak histori lama.
-- Idempoten untuk instalasi/upgrade berulang.
-- =========================================================

CREATE TABLE IF NOT EXISTS `audit_event` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type`          VARCHAR(50) NOT NULL,
  `entity_type`         VARCHAR(40) NOT NULL,
  `entity_id`           VARCHAR(64) DEFAULT NULL,
  `action`              VARCHAR(40) NOT NULL,
  `actor_admin_id`      INT DEFAULT NULL,
  `actor_name_snapshot` VARCHAR(100) NOT NULL,
  `request_id`          CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason`              VARCHAR(255) DEFAULT NULL,
  `before_data`         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `after_data`          LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `metadata`            LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at`          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_audit_event_entity` (`entity_type`, `entity_id`, `created_at`),
  KEY `idx_audit_event_actor` (`actor_admin_id`, `created_at`),
  KEY `idx_audit_event_type_time` (`event_type`, `created_at`),
  CONSTRAINT `chk_audit_event_before_json`
    CHECK (`before_data` IS NULL OR JSON_VALID(`before_data`)),
  CONSTRAINT `chk_audit_event_after_json`
    CHECK (`after_data` IS NULL OR JSON_VALID(`after_data`)),
  CONSTRAINT `chk_audit_event_metadata_json`
    CHECK (`metadata` IS NULL OR JSON_VALID(`metadata`))
) ENGINE=InnoDB;

-- Audit mempertahankan ID actor sebagai snapshot. FK sengaja tidak dipakai:
-- aksi hapus akun tidak boleh mengubah actor_admin_id pada histori lama.
DELIMITER $$
DROP PROCEDURE IF EXISTS `migrate_financial_audit_log`$$
CREATE PROCEDURE `migrate_financial_audit_log`()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='audit_event'
      AND CONSTRAINT_NAME='fk_audit_event_actor' AND CONSTRAINT_TYPE='FOREIGN KEY'
  ) THEN
    ALTER TABLE `audit_event` DROP FOREIGN KEY `fk_audit_event_actor`;
  END IF;
END$$
CALL `migrate_financial_audit_log`()$$
DROP PROCEDURE `migrate_financial_audit_log`$$

CREATE OR REPLACE TRIGGER `trg_audit_event_no_update`
BEFORE UPDATE ON `audit_event`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_event is append-only';
END$$

CREATE OR REPLACE TRIGGER `trg_audit_event_no_delete`
BEFORE DELETE ON `audit_event`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_event is append-only';
END$$
DELIMITER ;
