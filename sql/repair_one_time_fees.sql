-- =========================================================
-- Rekonsiliasi Uang Pangkal dan Uang PSB
-- =========================================================
-- Jalankan SETELAH backup pada database yang sudah dipilih di DBeaver.
-- Script ini tidak menghapus siswa, pembayaran, atau histori.
-- Tidak memakai USE agar tidak dapat berpindah database tanpa sengaja.
--
-- Yang diselaraskan:
-- 1. tot_pangkal = PANGKAL - potong_pangkal.
-- 2. Diskon Pangkal tidak negatif dan tidak melebihi Pangkal.
-- 3. Enam siswa demo PSB standar memperoleh Pangkal Rp1.000.000
--    bila Pangkal masih Rp0 dan belum pernah membayar Pangkal.
--
-- Baris yang pembayaran Pangkalnya sudah melebihi nominal master tidak
-- disentuh dan akan muncul pada hasil pemeriksaan akhir untuk dikoreksi admin.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @repair_demo_psb_pangkal := 1000000.00;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS `repair_one_time_fee_scope`;
CREATE TEMPORARY TABLE `repair_one_time_fee_scope` AS
SELECT
  s.`NO_INDUK`,
  s.`NAMA`,
  s.`KELAS`,
  s.`PANGKAL`,
  s.`potong_pangkal`,
  s.`tot_pangkal`,
  s.`PSB`,
  COALESCE(SUM(b.`U_PANGKAL`), 0) AS `pangkal_terbayar`,
  CASE
    WHEN s.`NO_INDUK` IN ('PSB0001','PSB0002','PSB0003','PSB0004','PSB0005','PSB0006')
      AND UPPER(TRIM(s.`KELAS`)) = 'PSB'
      AND s.`PANGKAL` = 0
      AND COALESCE(SUM(b.`U_PANGKAL`), 0) <= 0.001
    THEN @repair_demo_psb_pangkal
    ELSE s.`PANGKAL`
  END AS `pangkal_target`
FROM `siswa` s
LEFT JOIN `bayar` b ON b.`NO_INDUK` = s.`NO_INDUK`
GROUP BY s.`NO_INDUK`, s.`NAMA`, s.`KELAS`, s.`PANGKAL`, s.`potong_pangkal`, s.`tot_pangkal`, s.`PSB`;

-- Pemeriksaan sebelum perubahan. Simpan hasil ini bila diperlukan sebagai audit.
SELECT
  `NO_INDUK`, `NAMA`, `KELAS`, `PANGKAL`, `potong_pangkal`, `tot_pangkal`,
  `pangkal_terbayar`, `pangkal_target`,
  CASE
    WHEN `pangkal_terbayar` > GREATEST(`pangkal_target` - LEAST(GREATEST(`potong_pangkal`, 0), `pangkal_target`), 0) + 0.001
      THEN 'PERLU KOREKSI MANUAL: pembayaran melebihi master'
    WHEN ABS(`tot_pangkal` - GREATEST(`pangkal_target` - LEAST(GREATEST(`potong_pangkal`, 0), `pangkal_target`), 0)) > 0.001
      THEN 'AKAN DIREKONSILIASI'
    WHEN `pangkal_target` <> `PANGKAL` THEN 'AKAN DIISI: Pangkal demo PSB'
    ELSE 'SUDAH SESUAI'
  END AS `status_rekonsiliasi`
FROM `repair_one_time_fee_scope`
WHERE `pangkal_target` <> `PANGKAL`
   OR ABS(`tot_pangkal` - GREATEST(`pangkal_target` - LEAST(GREATEST(`potong_pangkal`, 0), `pangkal_target`), 0)) > 0.001
   OR `pangkal_terbayar` > GREATEST(`pangkal_target` - LEAST(GREATEST(`potong_pangkal`, 0), `pangkal_target`), 0) + 0.001
ORDER BY `NO_INDUK`;

-- Hanya baris yang tetap valid terhadap histori pembayaran yang diubah.
UPDATE `siswa` s
JOIN `repair_one_time_fee_scope` r ON r.`NO_INDUK` = s.`NO_INDUK`
SET
  s.`PANGKAL` = r.`pangkal_target`,
  s.`potong_pangkal` = LEAST(GREATEST(s.`potong_pangkal`, 0), r.`pangkal_target`),
  s.`tot_pangkal` = GREATEST(
    r.`pangkal_target` - LEAST(GREATEST(s.`potong_pangkal`, 0), r.`pangkal_target`),
    0
  )
WHERE r.`pangkal_terbayar` <= GREATEST(
  r.`pangkal_target` - LEAST(GREATEST(s.`potong_pangkal`, 0), r.`pangkal_target`),
  0
) + 0.001;

COMMIT;

-- Pemeriksaan setelah rekonsiliasi. Hasil `masalah` harus 0.
SELECT
  'Pangkal: total master tidak konsisten' AS `pemeriksaan`,
  COUNT(*) AS `masalah`
FROM `siswa`
WHERE ABS(`tot_pangkal` - GREATEST(`PANGKAL` - `potong_pangkal`, 0)) > 0.001
UNION ALL
SELECT
  'Pangkal: pembayaran melebihi tagihan',
  COUNT(*)
FROM (
  SELECT s.`NO_INDUK`
  FROM `siswa` s
  LEFT JOIN `bayar` b ON b.`NO_INDUK` = s.`NO_INDUK`
  GROUP BY s.`NO_INDUK`, s.`PANGKAL`, s.`potong_pangkal`
  HAVING COALESCE(SUM(b.`U_PANGKAL`), 0) > GREATEST(s.`PANGKAL` - s.`potong_pangkal`, 0) + 0.001
) `overpaid`
UNION ALL
SELECT
  'Pangkal: siswa aktif dengan sisa tagihan',
  COUNT(*)
FROM (
  SELECT s.`NO_INDUK`
  FROM `siswa` s
  LEFT JOIN `bayar` b ON b.`NO_INDUK` = s.`NO_INDUK`
  WHERE s.`is_active` = 1
  GROUP BY s.`NO_INDUK`, s.`PANGKAL`, s.`potong_pangkal`
  HAVING GREATEST(s.`PANGKAL` - s.`potong_pangkal`, 0) - COALESCE(SUM(b.`U_PANGKAL`), 0) > 0.001
) `unpaid`;

DROP TEMPORARY TABLE IF EXISTS `repair_one_time_fee_scope`;
