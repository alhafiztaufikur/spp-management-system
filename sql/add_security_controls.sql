-- =========================================================
-- Migrasi kontrol keamanan akun dan pencabutan sesi.
-- Idempoten: aman dijalankan ulang pada database yang sama.
-- =========================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `migrate_security_controls`$$
CREATE PROCEDURE `migrate_security_controls`()
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admin'
      AND COLUMN_NAME = 'session_version'
  ) THEN
    ALTER TABLE `admin`
      ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 1
      AFTER `role`;
  END IF;

  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admin'
      AND COLUMN_NAME = 'password_reset_required'
  ) THEN
    ALTER TABLE `admin`
      ADD COLUMN `password_reset_required` TINYINT(1) NOT NULL DEFAULT 0
      AFTER `session_version`;
  END IF;

  -- Hash MD5 lama tidak diubah atau ditebak. Akun ditandai agar administrator
  -- dengan kredensial modern dapat melakukan reset eksplisit.
  UPDATE `admin`
  SET `password_reset_required` = 1
  WHERE `password` REGEXP '^[0-9A-Fa-f]{32}$';

  CREATE TABLE IF NOT EXISTS `login_rate_limit` (
    `bucket_hash`       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `failure_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` DATETIME NOT NULL,
    `blocked_until`     DATETIME DEFAULT NULL,
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_login_rate_limit_cleanup` (`updated_at`),
    KEY `idx_login_rate_limit_blocked` (`blocked_until`)
  ) ENGINE=InnoDB;
END$$
CALL `migrate_security_controls`()$$
DROP PROCEDURE `migrate_security_controls`$$
DELIMITER ;
