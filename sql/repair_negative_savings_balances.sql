-- =========================================================
-- Repair saldo minus tabungan
-- Idempotent: koreksi hanya dibuat untuk saldo histori yang masih minus.
-- =========================================================

USE `db_spp`;

SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  NULL,
  x.`NO_INDUK`,
  NOW(),
  ABS(x.`saldo_histori`),
  0,
  'SYSTEM_REPAIR'
FROM (
  SELECT
    s.`NO_INDUK`,
    COALESCE(m.`masuk`, 0) - COALESCE(k.`keluar`, 0) AS `saldo_histori`
  FROM `siswa` s
  LEFT JOIN (
    SELECT `NO_INDUK`, SUM(`MASUK`) AS `masuk`
    FROM `transaksi_m`
    GROUP BY `NO_INDUK`
  ) m ON m.`NO_INDUK` = s.`NO_INDUK`
  LEFT JOIN (
    SELECT `NO_INDUK`, SUM(`KELUAR`) AS `keluar`
    FROM `transaksi_k`
    GROUP BY `NO_INDUK`
  ) k ON k.`NO_INDUK` = s.`NO_INDUK`
) x
WHERE x.`saldo_histori` < 0;

INSERT INTO `tabungan` (`NO_INDUK`, `SALDO`)
SELECT
  s.`NO_INDUK`,
  GREATEST(COALESCE(m.`masuk`, 0) - COALESCE(k.`keluar`, 0), 0)
FROM `siswa` s
LEFT JOIN (
  SELECT `NO_INDUK`, SUM(`MASUK`) AS `masuk`
  FROM `transaksi_m`
  GROUP BY `NO_INDUK`
) m ON m.`NO_INDUK` = s.`NO_INDUK`
LEFT JOIN (
  SELECT `NO_INDUK`, SUM(`KELUAR`) AS `keluar`
  FROM `transaksi_k`
  GROUP BY `NO_INDUK`
) k ON k.`NO_INDUK` = s.`NO_INDUK`
ON DUPLICATE KEY UPDATE `SALDO` = VALUES(`SALDO`);

COMMIT;

SET @has_check := (
  SELECT COUNT(*)
  FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'chk_tabungan_saldo_nonnegative'
);

SET @ddl := IF(
  @has_check = 0,
  'ALTER TABLE `tabungan` ADD CONSTRAINT `chk_tabungan_saldo_nonnegative` CHECK (`SALDO` >= 0)',
  'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT
  'tabungan_minus' AS `check_name`,
  COUNT(*) AS `total`
FROM `tabungan`
WHERE `SALDO` < 0
UNION ALL
SELECT
  'tabungan_mismatch',
  COUNT(*)
FROM (
  SELECT
    s.`NO_INDUK`,
    COALESCE(t.`SALDO`, 0) AS `saldo_tersimpan`,
    GREATEST(COALESCE(m.`masuk`, 0) - COALESCE(k.`keluar`, 0), 0) AS `saldo_histori`
  FROM `siswa` s
  LEFT JOIN `tabungan` t ON t.`NO_INDUK` = s.`NO_INDUK`
  LEFT JOIN (
    SELECT `NO_INDUK`, SUM(`MASUK`) AS `masuk`
    FROM `transaksi_m`
    GROUP BY `NO_INDUK`
  ) m ON m.`NO_INDUK` = s.`NO_INDUK`
  LEFT JOIN (
    SELECT `NO_INDUK`, SUM(`KELUAR`) AS `keluar`
    FROM `transaksi_k`
    GROUP BY `NO_INDUK`
  ) k ON k.`NO_INDUK` = s.`NO_INDUK`
) audit
WHERE ABS(audit.`saldo_tersimpan` - audit.`saldo_histori`) > 0.001;
