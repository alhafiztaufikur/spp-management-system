-- =========================================================
-- Normalisasi referensi operator transaksi legacy.
--
-- Hanya nilai user_id yang cocok persis dengan admin.username yang
-- dikonversi menjadi admin.id. Nilai kosong, tidak dikenal, ambigu secara
-- numerik, atau ID admin yang sudah valid dibiarkan untuk review manual.
-- Aman dijalankan ulang.
-- =========================================================

USE `db_spp`;

START TRANSACTION;

UPDATE `bayar` b
JOIN `admin` legacy_operator
  ON legacy_operator.`username` = TRIM(b.`user_id`)
LEFT JOIN `admin` current_operator
  ON CAST(current_operator.`id` AS CHAR) = TRIM(b.`user_id`)
SET b.`user_id` = CAST(legacy_operator.`id` AS CHAR)
WHERE TRIM(COALESCE(b.`user_id`, '')) <> ''
  AND current_operator.`id` IS NULL;

SET @normalized_bayar_operators = ROW_COUNT();

UPDATE `transaksi_m` tm
JOIN `admin` legacy_operator
  ON legacy_operator.`username` = TRIM(tm.`user_id`)
LEFT JOIN `admin` current_operator
  ON CAST(current_operator.`id` AS CHAR) = TRIM(tm.`user_id`)
SET tm.`user_id` = CAST(legacy_operator.`id` AS CHAR)
WHERE TRIM(COALESCE(tm.`user_id`, '')) <> ''
  AND current_operator.`id` IS NULL;

SET @normalized_savings_in_operators = ROW_COUNT();

UPDATE `transaksi_k` tk
JOIN `admin` legacy_operator
  ON legacy_operator.`username` = TRIM(tk.`user_id`)
LEFT JOIN `admin` current_operator
  ON CAST(current_operator.`id` AS CHAR) = TRIM(tk.`user_id`)
SET tk.`user_id` = CAST(legacy_operator.`id` AS CHAR)
WHERE TRIM(COALESCE(tk.`user_id`, '')) <> ''
  AND current_operator.`id` IS NULL;

SET @normalized_savings_out_operators = ROW_COUNT();

COMMIT;

SELECT
  @normalized_bayar_operators AS `normalized_bayar_operators`,
  @normalized_savings_in_operators AS `normalized_savings_in_operators`,
  @normalized_savings_out_operators AS `normalized_savings_out_operators`;

-- Queue read-only. Setiap baris di sini membutuhkan rekonsiliasi manusia;
-- migrasi tidak menebak operator berdasarkan tanggal, role, atau urutan data.
SELECT `source_table`, `operator_value`, `row_count`
FROM (
  SELECT
    'bayar' AS `source_table`,
    TRIM(COALESCE(b.`user_id`, '')) AS `operator_value`,
    COUNT(*) AS `row_count`
  FROM `bayar` b
  LEFT JOIN `admin` a ON CAST(a.`id` AS CHAR) = TRIM(b.`user_id`)
  WHERE TRIM(COALESCE(b.`user_id`, '')) = '' OR a.`id` IS NULL
  GROUP BY TRIM(COALESCE(b.`user_id`, ''))

  UNION ALL

  SELECT
    'transaksi_m',
    TRIM(COALESCE(tm.`user_id`, '')),
    COUNT(*)
  FROM `transaksi_m` tm
  LEFT JOIN `admin` a ON CAST(a.`id` AS CHAR) = TRIM(tm.`user_id`)
  WHERE TRIM(COALESCE(tm.`user_id`, '')) <> '' AND a.`id` IS NULL
  GROUP BY TRIM(COALESCE(tm.`user_id`, ''))

  UNION ALL

  SELECT
    'transaksi_k',
    TRIM(COALESCE(tk.`user_id`, '')),
    COUNT(*)
  FROM `transaksi_k` tk
  LEFT JOIN `admin` a ON CAST(a.`id` AS CHAR) = TRIM(tk.`user_id`)
  WHERE TRIM(COALESCE(tk.`user_id`, '')) <> '' AND a.`id` IS NULL
  GROUP BY TRIM(COALESCE(tk.`user_id`, ''))
) unresolved
ORDER BY `source_table`, `operator_value`;
