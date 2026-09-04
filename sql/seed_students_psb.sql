-- =========================================================
-- Seeder siswa reguler dan PSB SistemSPP
--
-- Aman dijalankan ulang pada database dev:
-- * siswa lama dipertahankan;
-- * siswa reguler demo yang belum ada ditambahkan;
-- * siswa PSB ditambahkan jika belum ada;
-- * kelas placeholder dipindahkan ke rombel A-D lalu dihapus.
--
-- Tidak menghapus transaksi, histori, atau akun operator.
-- =========================================================

USE `db_spp`;

SET NAMES utf8mb4;
SET @old_foreign_key_checks := @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- Pastikan pilihan rombel resmi dan kelas PSB tersedia.
INSERT INTO `master_kelas` (`tingkat`, `kode_rombel`, `is_placeholder`, `is_active`)
VALUES (0, 'PSB', 0, 1)
ON DUPLICATE KEY UPDATE `is_placeholder` = 0, `is_active` = 1;

INSERT IGNORE INTO `master_kelas` (`tingkat`, `kode_rombel`, `is_placeholder`, `is_active`)
SELECT levels.`tingkat`, letters.`kode_rombel`, 0, 1
FROM (
  SELECT 1 AS `tingkat` UNION ALL SELECT 2 UNION ALL SELECT 3
  UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) levels
CROSS JOIN (
  SELECT 'A' AS `kode_rombel` UNION ALL SELECT 'B' UNION ALL SELECT 'C' UNION ALL SELECT 'D'
  UNION ALL SELECT 'E' UNION ALL SELECT 'F' UNION ALL SELECT 'G' UNION ALL SELECT 'H'
  UNION ALL SELECT 'I' UNION ALL SELECT 'J'
) letters;

-- Tambahkan siswa reguler demo sampai nomor 20270001-20270144 tersedia.
DROP TEMPORARY TABLE IF EXISTS `seed_student_seq`;
CREATE TEMPORARY TABLE `seed_student_seq` (`n` INT NOT NULL PRIMARY KEY) ENGINE=MEMORY;
INSERT INTO `seed_student_seq` (`n`)
SELECT h.`i` * 100 + t.`i` * 10 + o.`i` + 1
FROM (SELECT 0 AS `i` UNION ALL SELECT 1) h
CROSS JOIN (SELECT 0 AS `i` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
CROSS JOIN (SELECT 0 AS `i` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) o
WHERE h.`i` * 100 + t.`i` * 10 + o.`i` + 1 BETWEEN 1 AND 144;

INSERT INTO `siswa` (
  `NO_INDUK`, `NO_induk_diknas`, `NAMA`, `KELAS`, `SPP_PERBULAN`,
  `PANGKAL`, `BANGUNAN`, `SERAGAM`, `KEGIATAN`, `MAKAN`, `SORGA`, `INFAQ`,
  `POMG`, `DAFTAR_ULANG`, `tot_pangkal`, `tot_du`, `is_active`
)
SELECT
  CONCAT('2027', LPAD(seq.`n`, 4, '0')),
  CONCAT('D27', LPAD(seq.`n`, 7, '0')),
  CONCAT('Siswa Demo ', LPAD(seq.`n`, 3, '0')),
  CAST(FLOOR((seq.`n` - 31) / 19) + 1 AS CHAR),
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 250000 WHEN 2 THEN 260000 WHEN 3 THEN 275000
    WHEN 4 THEN 290000 WHEN 5 THEN 305000 ELSE 320000
  END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 1000000 WHEN 2 THEN 1100000 WHEN 3 THEN 1200000
    WHEN 4 THEN 1300000 WHEN 5 THEN 1400000 ELSE 1500000
  END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 1500000 WHEN 2 THEN 1550000 WHEN 3 THEN 1600000
    WHEN 4 THEN 1650000 WHEN 5 THEN 1700000 ELSE 1750000
  END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 500000 WHEN 2 THEN 525000 WHEN 3 THEN 550000
    WHEN 4 THEN 575000 WHEN 5 THEN 600000 ELSE 625000
  END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 300000 WHEN 2 THEN 325000 WHEN 3 THEN 350000
    WHEN 4 THEN 375000 WHEN 5 THEN 400000 ELSE 425000
  END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 180000 WHEN 2 THEN 185000 WHEN 3 THEN 190000
    WHEN 4 THEN 195000 WHEN 5 THEN 200000 ELSE 210000
  END,
  CASE WHEN FLOOR((seq.`n` - 31) / 19) + 1 <= 2 THEN 50000
       WHEN FLOOR((seq.`n` - 31) / 19) + 1 <= 4 THEN 60000 ELSE 70000 END,
  CASE WHEN FLOOR((seq.`n` - 31) / 19) + 1 <= 2 THEN 25000
       WHEN FLOOR((seq.`n` - 31) / 19) + 1 <= 4 THEN 30000 ELSE 40000 END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 100000 WHEN 2 THEN 110000 WHEN 3 THEN 120000
    WHEN 4 THEN 130000 WHEN 5 THEN 140000 ELSE 150000
  END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 1000000 WHEN 2 THEN 1100000 WHEN 3 THEN 1200000
    WHEN 4 THEN 1300000 WHEN 5 THEN 1400000 ELSE 1500000
  END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 1000000 WHEN 2 THEN 1100000 WHEN 3 THEN 1200000
    WHEN 4 THEN 1300000 WHEN 5 THEN 1400000 ELSE 1500000
  END,
  CASE FLOOR((seq.`n` - 31) / 19) + 1
    WHEN 1 THEN 1000000 WHEN 2 THEN 1100000 WHEN 3 THEN 1200000
    WHEN 4 THEN 1300000 WHEN 5 THEN 1400000 ELSE 1500000
  END,
  1
FROM `seed_student_seq` seq
WHERE seq.`n` BETWEEN 31 AND 144
  AND NOT EXISTS (
    SELECT 1 FROM `siswa` existing
    WHERE existing.`NO_INDUK` = CONCAT('2027', LPAD(seq.`n`, 4, '0'))
  );

-- Siswa PSB adalah data calon siswa dan tidak diberi tagihan reguler.
INSERT INTO `siswa` (
  `NO_INDUK`, `NO_induk_diknas`, `NAMA`, `KELAS`, `SPP_PERBULAN`,
  `PANGKAL`, `BANGUNAN`, `SERAGAM`, `KEGIATAN`, `MAKAN`, `SORGA`, `INFAQ`,
  `POMG`, `DAFTAR_ULANG`, `master_kelas_id`, `is_active`
)
SELECT
  psb.`no_induk`, psb.`diknas`, psb.`nama`, 'PSB',
  0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
  (SELECT `id` FROM `master_kelas` WHERE `tingkat` = 0 AND `kode_rombel` = 'PSB' LIMIT 1),
  1
FROM (
  SELECT 'PSB0001' AS `no_induk`, 'D26PSB0001' AS `diknas`, 'Calon Siswa PSB 01' AS `nama`
  UNION ALL SELECT 'PSB0002', 'D26PSB0002', 'Calon Siswa PSB 02'
  UNION ALL SELECT 'PSB0003', 'D26PSB0003', 'Calon Siswa PSB 03'
  UNION ALL SELECT 'PSB0004', 'D26PSB0004', 'Calon Siswa PSB 04'
  UNION ALL SELECT 'PSB0005', 'D26PSB0005', 'Calon Siswa PSB 05'
  UNION ALL SELECT 'PSB0006', 'D26PSB0006', 'Calon Siswa PSB 06'
) psb
WHERE NOT EXISTS (
  SELECT 1 FROM `siswa` existing WHERE existing.`NO_INDUK` = psb.`no_induk`
);

-- Buat pemetaan rombel A-D yang stabil untuk seluruh siswa reguler.
DROP TEMPORARY TABLE IF EXISTS `seed_student_class_map`;
CREATE TEMPORARY TABLE `seed_student_class_map` AS
SELECT ranked.`no_induk`, ranked.`tingkat`,
       ELT(MOD(ranked.`nomor_urut` - 1, 4) + 1, 'A', 'B', 'C', 'D') AS `kode_rombel`
FROM (
  SELECT s.`NO_INDUK` AS `no_induk`,
         CAST(COALESCE(NULLIF(mk.`tingkat`, 0), NULLIF(s.`KELAS`, '')) AS UNSIGNED) AS `tingkat`,
         ROW_NUMBER() OVER (
           PARTITION BY CAST(COALESCE(NULLIF(mk.`tingkat`, 0), NULLIF(s.`KELAS`, '')) AS UNSIGNED)
           ORDER BY s.`NO_INDUK`
         ) AS `nomor_urut`
  FROM `siswa` s
  LEFT JOIN `master_kelas` mk ON mk.`id` = s.`master_kelas_id`
  WHERE UPPER(TRIM(s.`KELAS`)) <> 'PSB'
    AND CAST(COALESCE(NULLIF(mk.`tingkat`, 0), NULLIF(s.`KELAS`, '')) AS UNSIGNED) BETWEEN 1 AND 6
) ranked;

ALTER TABLE `seed_student_class_map` ADD PRIMARY KEY (`no_induk`);
ALTER TABLE `seed_student_class_map` ADD INDEX `idx_seed_student_class` (`tingkat`, `kode_rombel`);

-- Pindahkan siswa aktif dan sinkronkan identitas kelasnya.
UPDATE `siswa` s
JOIN `seed_student_class_map` map ON map.`no_induk` = s.`NO_INDUK`
JOIN `master_kelas` mk ON mk.`tingkat` = map.`tingkat`
  AND mk.`kode_rombel` = map.`kode_rombel`
  AND mk.`is_placeholder` = 0
SET s.`KELAS` = CAST(map.`tingkat` AS CHAR), s.`master_kelas_id` = mk.`id`;

-- Perbarui relasi yang sebelumnya menunjuk placeholder.
UPDATE `siswa_tahun_ajaran` sta
JOIN `seed_student_class_map` map ON map.`no_induk` = sta.`no_induk`
JOIN `master_kelas` old_mk ON old_mk.`id` = sta.`master_kelas_id` AND old_mk.`is_placeholder` = 1
JOIN `master_kelas` new_mk ON new_mk.`tingkat` = map.`tingkat` AND new_mk.`kode_rombel` = map.`kode_rombel`
SET sta.`kelas` = CAST(map.`tingkat` AS CHAR), sta.`master_kelas_id` = new_mk.`id`,
    sta.`kelas_rombel_snapshot` = CONCAT(map.`tingkat`, map.`kode_rombel`);

UPDATE `bayar` b
JOIN `seed_student_class_map` map ON map.`no_induk` = b.`NO_INDUK`
JOIN `master_kelas` old_mk ON old_mk.`id` = b.`master_kelas_id` AND old_mk.`is_placeholder` = 1
JOIN `master_kelas` new_mk ON new_mk.`tingkat` = map.`tingkat` AND new_mk.`kode_rombel` = map.`kode_rombel`
SET b.`KELAS` = CAST(map.`tingkat` AS CHAR), b.`master_kelas_id` = new_mk.`id`,
    b.`kelas_rombel_snapshot` = CONCAT(map.`tingkat`, map.`kode_rombel`);

UPDATE `tagihan_biaya_lain` t
JOIN `seed_student_class_map` map ON map.`no_induk` = t.`no_induk`
JOIN `master_kelas` old_mk ON old_mk.`id` = t.`master_kelas_id` AND old_mk.`is_placeholder` = 1
JOIN `master_kelas` new_mk ON new_mk.`tingkat` = map.`tingkat` AND new_mk.`kode_rombel` = map.`kode_rombel`
SET t.`master_kelas_id` = new_mk.`id`, t.`kelas_rombel_snapshot` = CONCAT(map.`tingkat`, map.`kode_rombel`);

UPDATE `tagihan_tahunan_siswa` t
JOIN `seed_student_class_map` map ON map.`no_induk` = t.`no_induk`
SET t.`kelas_snapshot` = CAST(map.`tingkat` AS CHAR),
    t.`kelas_rombel_snapshot` = CONCAT(map.`tingkat`, map.`kode_rombel`)
WHERE t.`kelas_rombel_snapshot` LIKE '%Belum Ditentukan%';

-- Referensi placeholder yang tidak punya siswa aktif dipindahkan ke rombel A
-- pada tingkat yang sama, agar kelas placeholder dapat dihapus tanpa FK yatim.
UPDATE `siswa_tahun_ajaran` sta
JOIN `master_kelas` old_mk ON old_mk.`id` = sta.`master_kelas_id` AND old_mk.`is_placeholder` = 1
JOIN `master_kelas` new_mk ON new_mk.`tingkat` = old_mk.`tingkat` AND new_mk.`kode_rombel` = 'A' AND new_mk.`is_placeholder` = 0
SET sta.`master_kelas_id` = new_mk.`id`, sta.`kelas_rombel_snapshot` = CONCAT(new_mk.`tingkat`, new_mk.`kode_rombel`);

UPDATE `bayar` b
JOIN `master_kelas` old_mk ON old_mk.`id` = b.`master_kelas_id` AND old_mk.`is_placeholder` = 1
JOIN `master_kelas` new_mk ON new_mk.`tingkat` = old_mk.`tingkat` AND new_mk.`kode_rombel` = 'A' AND new_mk.`is_placeholder` = 0
SET b.`master_kelas_id` = new_mk.`id`, b.`kelas_rombel_snapshot` = CONCAT(new_mk.`tingkat`, new_mk.`kode_rombel`);

UPDATE `tagihan_biaya_lain` t
JOIN `master_kelas` old_mk ON old_mk.`id` = t.`master_kelas_id` AND old_mk.`is_placeholder` = 1
JOIN `master_kelas` new_mk ON new_mk.`tingkat` = old_mk.`tingkat` AND new_mk.`kode_rombel` = 'A' AND new_mk.`is_placeholder` = 0
SET t.`master_kelas_id` = new_mk.`id`, t.`kelas_rombel_snapshot` = CONCAT(new_mk.`tingkat`, new_mk.`kode_rombel`);

UPDATE `siswa` s
JOIN `master_kelas` old_mk ON old_mk.`id` = s.`master_kelas_id` AND old_mk.`is_placeholder` = 1
JOIN `master_kelas` new_mk ON new_mk.`tingkat` = old_mk.`tingkat` AND new_mk.`kode_rombel` = 'A' AND new_mk.`is_placeholder` = 0
SET s.`master_kelas_id` = new_mk.`id`, s.`KELAS` = CAST(new_mk.`tingkat` AS CHAR);

DELETE FROM `master_kelas` WHERE `is_placeholder` = 1;

DROP TEMPORARY TABLE IF EXISTS `seed_student_class_map`;
DROP TEMPORARY TABLE IF EXISTS `seed_student_seq`;

SET FOREIGN_KEY_CHECKS = @old_foreign_key_checks;
COMMIT;

-- Pemeriksaan akhir ringan.
SELECT 'siswa_reguler' AS `pemeriksaan`, COUNT(*) AS `jumlah`
FROM `siswa` WHERE `KELAS` IN ('1','2','3','4','5','6') AND `is_active` = 1
UNION ALL
SELECT 'siswa_psb', COUNT(*) FROM `siswa` WHERE `KELAS` = 'PSB' AND `is_active` = 1
UNION ALL
SELECT 'kelas_placeholder', COUNT(*) FROM `master_kelas` WHERE `is_placeholder` = 1;
