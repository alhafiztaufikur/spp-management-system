-- =========================================================
-- Master kelas/rombel, snapshot histori, dan tagihan biaya lain.
-- Idempoten untuk MariaDB/XAMPP. Jalankan pada database db_spp.
-- =========================================================

USE `db_spp`;

-- Preflight read-only. Hasil selain nol perlu ditinjau sebelum migrasi produksi.
SELECT 'kelas_siswa_tidak_valid' AS pemeriksaan, COUNT(*) AS jumlah
FROM `siswa`
WHERE `KELAS` NOT IN ('1','2','3','4','5','6');

SELECT 'pembayaran_tanpa_siswa' AS pemeriksaan, COUNT(*) AS jumlah
FROM `bayar` b
LEFT JOIN `siswa` s ON s.`NO_INDUK` = b.`NO_INDUK`
WHERE b.`NO_INDUK` IS NOT NULL AND s.`NO_INDUK` IS NULL;

SELECT 'biaya_lain_tanpa_master' AS pemeriksaan, COUNT(*) AS jumlah
FROM `bayar_biaya_lain`
WHERE `master_biaya_lain_id` IS NULL;

SELECT 'spp_tanpa_pemetaan_periode' AS pemeriksaan, COUNT(*) AS jumlah
FROM `bayar` b
LEFT JOIN `bayar_spp_periode` bsp ON bsp.`bayar_id` = b.`id`
WHERE b.`U_SPP` > 0 AND bsp.`bayar_id` IS NULL;

CREATE TABLE IF NOT EXISTS `master_kelas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tingkat` TINYINT UNSIGNED NOT NULL,
  `kode_rombel` VARCHAR(10) NOT NULL,
  `is_placeholder` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_master_kelas_tingkat_rombel` (`tingkat`, `kode_rombel`),
  KEY `idx_master_kelas_active` (`is_active`, `tingkat`, `kode_rombel`),
  CONSTRAINT `chk_master_kelas_tingkat` CHECK (`tingkat` BETWEEN 1 AND 6)
) ENGINE=InnoDB;

INSERT INTO `master_kelas` (`tingkat`, `kode_rombel`, `is_placeholder`, `is_active`)
VALUES
  (1, 'BELUM', 1, 1), (2, 'BELUM', 1, 1), (3, 'BELUM', 1, 1),
  (4, 'BELUM', 1, 1), (5, 'BELUM', 1, 1), (6, 'BELUM', 1, 1),
  (1, 'A', 0, 1), (1, 'B', 0, 1), (1, 'C', 0, 1), (1, 'D', 0, 1), (1, 'E', 0, 1),
  (1, 'F', 0, 1), (1, 'G', 0, 1), (1, 'H', 0, 1), (1, 'I', 0, 1), (1, 'J', 0, 1),
  (2, 'A', 0, 1), (2, 'B', 0, 1), (2, 'C', 0, 1), (2, 'D', 0, 1), (2, 'E', 0, 1),
  (2, 'F', 0, 1), (2, 'G', 0, 1), (2, 'H', 0, 1), (2, 'I', 0, 1), (2, 'J', 0, 1),
  (3, 'A', 0, 1), (3, 'B', 0, 1), (3, 'C', 0, 1), (3, 'D', 0, 1), (3, 'E', 0, 1),
  (3, 'F', 0, 1), (3, 'G', 0, 1), (3, 'H', 0, 1), (3, 'I', 0, 1), (3, 'J', 0, 1),
  (4, 'A', 0, 1), (4, 'B', 0, 1), (4, 'C', 0, 1), (4, 'D', 0, 1), (4, 'E', 0, 1),
  (4, 'F', 0, 1), (4, 'G', 0, 1), (4, 'H', 0, 1), (4, 'I', 0, 1), (4, 'J', 0, 1),
  (5, 'A', 0, 1), (5, 'B', 0, 1), (5, 'C', 0, 1), (5, 'D', 0, 1), (5, 'E', 0, 1),
  (5, 'F', 0, 1), (5, 'G', 0, 1), (5, 'H', 0, 1), (5, 'I', 0, 1), (5, 'J', 0, 1),
  (6, 'A', 0, 1), (6, 'B', 0, 1), (6, 'C', 0, 1), (6, 'D', 0, 1), (6, 'E', 0, 1),
  (6, 'F', 0, 1), (6, 'G', 0, 1), (6, 'H', 0, 1), (6, 'I', 0, 1), (6, 'J', 0, 1)
ON DUPLICATE KEY UPDATE
  `is_placeholder` = VALUES(`is_placeholder`),
  `is_active` = 1;

ALTER TABLE `siswa`
  ADD COLUMN IF NOT EXISTS `master_kelas_id` INT DEFAULT NULL AFTER `KELAS`,
  ADD INDEX IF NOT EXISTS `idx_siswa_master_kelas` (`master_kelas_id`, `is_active`, `NAMA`);

ALTER TABLE `siswa_tahun_ajaran`
  ADD COLUMN IF NOT EXISTS `master_kelas_id` INT DEFAULT NULL AFTER `kelas`,
  ADD COLUMN IF NOT EXISTS `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL AFTER `master_kelas_id`,
  ADD COLUMN IF NOT EXISTS `spp_perbulan_snapshot` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `kelas_rombel_snapshot`,
  ADD COLUMN IF NOT EXISTS `komite_snapshot` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `spp_perbulan_snapshot`,
  ADD INDEX IF NOT EXISTS `idx_penempatan_master_kelas` (`tahun_ajaran_id`, `master_kelas_id`, `status`);

ALTER TABLE `bayar`
  ADD COLUMN IF NOT EXISTS `master_kelas_id` INT DEFAULT NULL AFTER `KELAS`,
  ADD COLUMN IF NOT EXISTS `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL AFTER `master_kelas_id`,
  ADD INDEX IF NOT EXISTS `idx_bayar_master_kelas_tanggal` (`master_kelas_id`, `TGL_BYR`),
  ADD INDEX IF NOT EXISTS `idx_bayar_tanggal_operator_metode` (`TGL_BYR`, `user_id`, `sistem_pembayaran`),
  ADD INDEX IF NOT EXISTS `idx_bayar_siswa_periode` (`NO_INDUK`, `TAHUN`, `BULAN`);

ALTER TABLE `transaksi_m`
  ADD INDEX IF NOT EXISTS `idx_transaksi_m_tanggal_user` (`TANGGAL`, `user_id`),
  ADD INDEX IF NOT EXISTS `idx_transaksi_m_siswa_tanggal` (`NO_INDUK`, `TANGGAL`);

ALTER TABLE `transaksi_k`
  ADD INDEX IF NOT EXISTS `idx_transaksi_k_tanggal_user` (`TANGGAL`, `user_id`),
  ADD INDEX IF NOT EXISTS `idx_transaksi_k_siswa_tanggal` (`NO_INDUK`, `TANGGAL`);

UPDATE `siswa` s
JOIN `master_kelas` mk
  ON mk.`tingkat` = CAST(s.`KELAS` AS UNSIGNED)
 AND mk.`is_placeholder` = 1
SET s.`master_kelas_id` = mk.`id`
WHERE s.`master_kelas_id` IS NULL
  AND s.`KELAS` IN ('1','2','3','4','5','6');

UPDATE `siswa_tahun_ajaran` sta
JOIN `siswa` s ON s.`NO_INDUK` = sta.`no_induk`
LEFT JOIN `master_kelas` mk ON mk.`id` = s.`master_kelas_id`
SET sta.`master_kelas_id` = COALESCE(sta.`master_kelas_id`, s.`master_kelas_id`),
    sta.`kelas_rombel_snapshot` = COALESCE(
      NULLIF(sta.`kelas_rombel_snapshot`, ''),
      CASE
        WHEN mk.`is_placeholder` = 1 THEN CONCAT('Kelas ', sta.`kelas`, ' (Belum Ditentukan)')
        ELSE CONCAT(sta.`kelas`, UPPER(mk.`kode_rombel`))
      END
    ),
    sta.`spp_perbulan_snapshot` = CASE
      WHEN sta.`spp_perbulan_snapshot` <= 0 THEN s.`SPP_PERBULAN`
      ELSE sta.`spp_perbulan_snapshot`
    END,
    sta.`komite_snapshot` = CASE
      WHEN sta.`komite_snapshot` <= 0 THEN s.`POMG`
      ELSE sta.`komite_snapshot`
    END;

UPDATE `bayar` b
LEFT JOIN `siswa` s ON s.`NO_INDUK` = b.`NO_INDUK`
LEFT JOIN `master_kelas` mk_current ON mk_current.`id` = s.`master_kelas_id`
LEFT JOIN `master_kelas` mk_legacy
  ON mk_legacy.`tingkat` = CAST(b.`KELAS` AS UNSIGNED)
 AND mk_legacy.`is_placeholder` = 1
SET b.`master_kelas_id` = COALESCE(b.`master_kelas_id`, mk_current.`id`, mk_legacy.`id`),
    b.`kelas_rombel_snapshot` = COALESCE(
      NULLIF(b.`kelas_rombel_snapshot`, ''),
      CASE
        WHEN mk_current.`id` IS NOT NULL AND mk_current.`is_placeholder` = 0
          THEN CONCAT(mk_current.`tingkat`, UPPER(mk_current.`kode_rombel`))
        ELSE CONCAT('Kelas ', COALESCE(NULLIF(b.`KELAS`, ''), s.`KELAS`), ' (Belum Ditentukan)')
      END
    );

CREATE TABLE IF NOT EXISTS `tagihan_biaya_lain` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `master_biaya_lain_id` INT NOT NULL,
  `no_induk` VARCHAR(10) NOT NULL,
  `master_kelas_id` INT DEFAULT NULL,
  `nama_snapshot` VARCHAR(100) NOT NULL,
  `nominal_tagihan` DECIMAL(15,2) NOT NULL,
  `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `status` ENUM('open','cancelled') NOT NULL DEFAULT 'open',
  `cancel_reason` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tagihan_biaya_lain_siswa_master` (`master_biaya_lain_id`, `no_induk`),
  KEY `idx_tagihan_biaya_lain_status` (`master_biaya_lain_id`, `status`, `master_kelas_id`),
  KEY `idx_tagihan_biaya_lain_siswa` (`no_induk`, `status`),
  CONSTRAINT `chk_tagihan_biaya_lain_nominal` CHECK (`nominal_tagihan` > 0),
  CONSTRAINT `fk_tagihan_biaya_lain_master` FOREIGN KEY (`master_biaya_lain_id`) REFERENCES `master_biaya_lain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_biaya_lain_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_biaya_lain_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_biaya_lain_admin` FOREIGN KEY (`created_by`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tagihan_biaya_lain_audit_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `master_biaya_lain_id` INT DEFAULT NULL,
  `aksi` VARCHAR(40) NOT NULL,
  `target` VARCHAR(40) NOT NULL,
  `target_value` VARCHAR(100) DEFAULT NULL,
  `affected_count` INT NOT NULL DEFAULT 0,
  `total_nominal` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `admin_id` INT DEFAULT NULL,
  `admin_name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tagihan_biaya_lain_audit` (`master_biaya_lain_id`, `created_at`),
  CONSTRAINT `fk_tagihan_biaya_lain_audit_master` FOREIGN KEY (`master_biaya_lain_id`) REFERENCES `master_biaya_lain` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_biaya_lain_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

ALTER TABLE `bayar_biaya_lain`
  ADD COLUMN IF NOT EXISTS `tagihan_biaya_lain_id` BIGINT DEFAULT NULL AFTER `master_biaya_lain_id`,
  ADD INDEX IF NOT EXISTS `idx_bayar_biaya_lain_tagihan` (`tagihan_biaya_lain_id`);

-- Bentuk tagihan hanya untuk siswa/item yang memang mempunyai histori bayar.
INSERT INTO `tagihan_biaya_lain` (
  `master_biaya_lain_id`, `no_induk`, `master_kelas_id`, `nama_snapshot`,
  `nominal_tagihan`, `kelas_rombel_snapshot`, `status`, `created_by`
)
SELECT
  d.`master_biaya_lain_id`, b.`NO_INDUK`, s.`master_kelas_id`,
  COALESCE(MAX(m.`nama`), MAX(d.`nama_biaya_snapshot`)),
  GREATEST(MAX(m.`nominal`), SUM(d.`nominal_snapshot`)),
  COALESCE(MAX(b.`kelas_rombel_snapshot`), CONCAT('Kelas ', s.`KELAS`, ' (Belum Ditentukan)')),
  'open', NULL
FROM `bayar_biaya_lain` d
JOIN `bayar` b ON b.`id` = d.`bayar_id`
JOIN `siswa` s ON s.`NO_INDUK` = b.`NO_INDUK`
JOIN `master_biaya_lain` m ON m.`id` = d.`master_biaya_lain_id`
WHERE d.`master_biaya_lain_id` IS NOT NULL
GROUP BY d.`master_biaya_lain_id`, b.`NO_INDUK`, s.`master_kelas_id`, s.`KELAS`
ON DUPLICATE KEY UPDATE
  `nominal_tagihan` = GREATEST(`tagihan_biaya_lain`.`nominal_tagihan`, VALUES(`nominal_tagihan`));

UPDATE `bayar_biaya_lain` d
JOIN `bayar` b ON b.`id` = d.`bayar_id`
JOIN `tagihan_biaya_lain` t
  ON t.`master_biaya_lain_id` = d.`master_biaya_lain_id`
 AND t.`no_induk` = b.`NO_INDUK`
SET d.`tagihan_biaya_lain_id` = t.`id`
WHERE d.`master_biaya_lain_id` IS NOT NULL
  AND d.`tagihan_biaya_lain_id` IS NULL;

DROP PROCEDURE IF EXISTS `add_modular_report_foreign_keys`;
DELIMITER $$
CREATE PROCEDURE `add_modular_report_foreign_keys`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
      AND CONSTRAINT_NAME = 'fk_siswa_master_kelas'
  ) THEN
    ALTER TABLE `siswa`
      ADD CONSTRAINT `fk_siswa_master_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa_tahun_ajaran'
      AND CONSTRAINT_NAME = 'fk_penempatan_master_kelas'
  ) THEN
    ALTER TABLE `siswa_tahun_ajaran`
      ADD CONSTRAINT `fk_penempatan_master_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
      AND CONSTRAINT_NAME = 'fk_bayar_master_kelas'
  ) THEN
    ALTER TABLE `bayar`
      ADD CONSTRAINT `fk_bayar_master_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_biaya_lain'
      AND CONSTRAINT_NAME = 'fk_bayar_biaya_lain_tagihan'
  ) THEN
    ALTER TABLE `bayar_biaya_lain`
      ADD CONSTRAINT `fk_bayar_biaya_lain_tagihan` FOREIGN KEY (`tagihan_biaya_lain_id`) REFERENCES `tagihan_biaya_lain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
  END IF;
END$$
DELIMITER ;

CALL `add_modular_report_foreign_keys`();
DROP PROCEDURE `add_modular_report_foreign_keys`;

-- Pemeriksaan pascamigrasi.
SELECT 'siswa_tanpa_master_kelas' AS pemeriksaan, COUNT(*) AS jumlah
FROM `siswa`
WHERE `KELAS` IN ('1','2','3','4','5','6') AND `master_kelas_id` IS NULL;

SELECT 'penempatan_tanpa_snapshot' AS pemeriksaan, COUNT(*) AS jumlah
FROM `siswa_tahun_ajaran`
WHERE `kelas_rombel_snapshot` IS NULL OR `kelas_rombel_snapshot` = '';

SELECT 'detail_tagihan_yatim' AS pemeriksaan, COUNT(*) AS jumlah
FROM `bayar_biaya_lain` d
LEFT JOIN `tagihan_biaya_lain` t ON t.`id` = d.`tagihan_biaya_lain_id`
WHERE d.`tagihan_biaya_lain_id` IS NOT NULL AND t.`id` IS NULL;
