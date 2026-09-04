-- =========================================================
-- Top-up seeder demo SistemSPP
-- Aman untuk database dev yang sudah punya siswa dan tagihan,
-- tetapi tabel transaksi/riwayat masih kosong atau kurang ramai.
-- Tidak menghapus data siswa, kelas, atau transaksi manual.
-- =========================================================

USE `db_spp`;

SET NAMES utf8mb4;
START TRANSACTION;

SET @seed_year := '2026/2027';
SET @seed_start := '2026-07-01';
SET @seed_end := '2027-06-30';

INSERT INTO `admin` (`username`, `password`, `nama`, `role`) VALUES
('kasir1', MD5('kasir123'), 'Kasir Loket 1', 'kasir'),
('kasir2', MD5('kasir123'), 'Kasir Loket 2', 'kasir'),
('kasir3', MD5('kasir123'), 'Kasir Loket 3', 'kasir'),
('kasir4', MD5('kasir123'), 'Kasir Loket 4', 'kasir')
ON DUPLICATE KEY UPDATE `nama` = VALUES(`nama`), `role` = VALUES(`role`);

INSERT INTO `tahun_ajaran` (`label`, `tanggal_mulai`, `tanggal_selesai`, `status`, `published_at`)
SELECT @seed_year, @seed_start, @seed_end, 'published', '2026-07-01 07:00:00'
WHERE NOT EXISTS (SELECT 1 FROM `tahun_ajaran` WHERE `label` = @seed_year);

SET @seed_year_id := (SELECT `id` FROM `tahun_ajaran` WHERE `label` = @seed_year LIMIT 1);
SET @seed_admin_id := COALESCE(
  (SELECT `id` FROM `admin` WHERE `username` = 'admin' LIMIT 1),
  (SELECT `id` FROM `admin` WHERE `role` = 'admin' ORDER BY `id` LIMIT 1)
);

INSERT INTO `Daftar_ulang` (`tahun_ajaran_id`, `th_ajaran`, `kelas`, `Jumlah`)
SELECT @seed_year_id, @seed_year, x.`kelas`, x.`jumlah`
FROM (
  SELECT '1' AS `kelas`, 1000000 AS `jumlah` UNION ALL
  SELECT '2', 1100000 UNION ALL
  SELECT '3', 1200000 UNION ALL
  SELECT '4', 1300000 UNION ALL
  SELECT '5', 1400000 UNION ALL
  SELECT '6', 1500000
) x
WHERE NOT EXISTS (
  SELECT 1 FROM `Daftar_ulang` du
  WHERE du.`th_ajaran` = @seed_year AND du.`kelas` = x.`kelas`
);

INSERT INTO `master_biaya_lain` (`nama`, `nominal`, `is_active`) VALUES
('Buku Paket', 125000, 1),
('Seragam Olahraga', 175000, 1),
('Kegiatan Tahunan', 150000, 1),
('Modul/Ujian', 95000, 1),
('Ekskul', 80000, 1)
ON DUPLICATE KEY UPDATE `nominal` = VALUES(`nominal`), `is_active` = 1;

UPDATE `siswa`
SET
  `SPP_PERBULAN` = IF(COALESCE(`SPP_PERBULAN`, 0) <= 0,
    CASE LEFT(`KELAS`, 1) WHEN '1' THEN 250000 WHEN '2' THEN 260000 WHEN '3' THEN 275000 WHEN '4' THEN 290000 WHEN '5' THEN 305000 ELSE 320000 END,
    `SPP_PERBULAN`
  ),
  `POMG` = IF(COALESCE(`POMG`, 0) <= 0,
    CASE LEFT(`KELAS`, 1) WHEN '1' THEN 100000 WHEN '2' THEN 110000 WHEN '3' THEN 120000 WHEN '4' THEN 130000 WHEN '5' THEN 140000 ELSE 150000 END,
    `POMG`
  ),
  `PANGKAL` = IF(COALESCE(`PANGKAL`, 0) <= 0,
    CASE LEFT(`KELAS`, 1) WHEN '1' THEN 1000000 WHEN '2' THEN 1100000 WHEN '3' THEN 1200000 WHEN '4' THEN 1300000 WHEN '5' THEN 1400000 ELSE 1500000 END,
    `PANGKAL`
  ),
  `BANGUNAN` = IF(COALESCE(`BANGUNAN`, 0) <= 0,
    CASE LEFT(`KELAS`, 1) WHEN '1' THEN 1500000 WHEN '2' THEN 1550000 WHEN '3' THEN 1600000 WHEN '4' THEN 1650000 WHEN '5' THEN 1700000 ELSE 1750000 END,
    `BANGUNAN`
  ),
  `SERAGAM` = IF(COALESCE(`SERAGAM`, 0) <= 0,
    CASE LEFT(`KELAS`, 1) WHEN '1' THEN 500000 WHEN '2' THEN 525000 WHEN '3' THEN 550000 WHEN '4' THEN 575000 WHEN '5' THEN 600000 ELSE 625000 END,
    `SERAGAM`
  ),
  `KEGIATAN` = IF(COALESCE(`KEGIATAN`, 0) <= 0,
    CASE LEFT(`KELAS`, 1) WHEN '1' THEN 300000 WHEN '2' THEN 325000 WHEN '3' THEN 350000 WHEN '4' THEN 375000 WHEN '5' THEN 400000 ELSE 425000 END,
    `KEGIATAN`
  ),
  `MAKAN` = IF(COALESCE(`MAKAN`, 0) <= 0,
    CASE LEFT(`KELAS`, 1) WHEN '1' THEN 180000 WHEN '2' THEN 185000 WHEN '3' THEN 190000 WHEN '4' THEN 195000 WHEN '5' THEN 200000 ELSE 210000 END,
    `MAKAN`
  ),
  `SORGA` = IF(COALESCE(`SORGA`, 0) <= 0,
    CASE WHEN CAST(LEFT(`KELAS`, 1) AS UNSIGNED) <= 2 THEN 50000 WHEN CAST(LEFT(`KELAS`, 1) AS UNSIGNED) <= 4 THEN 60000 ELSE 70000 END,
    `SORGA`
  ),
  `INFAQ` = IF(COALESCE(`INFAQ`, 0) <= 0,
    CASE WHEN CAST(LEFT(`KELAS`, 1) AS UNSIGNED) <= 2 THEN 25000 WHEN CAST(LEFT(`KELAS`, 1) AS UNSIGNED) <= 4 THEN 30000 ELSE 40000 END,
    `INFAQ`
  ),
  `DAFTAR_ULANG` = IF(COALESCE(`DAFTAR_ULANG`, 0) <= 0,
    CASE LEFT(`KELAS`, 1) WHEN '1' THEN 1000000 WHEN '2' THEN 1100000 WHEN '3' THEN 1200000 WHEN '4' THEN 1300000 WHEN '5' THEN 1400000 ELSE 1500000 END,
    `DAFTAR_ULANG`
  ),
  `tot_pangkal` = IF(COALESCE(`tot_pangkal`, 0) <= 0, GREATEST(`PANGKAL` - COALESCE(`potong_pangkal`, 0), 0), `tot_pangkal`),
  `tot_du` = IF(COALESCE(`tot_du`, 0) <= 0, GREATEST(`DAFTAR_ULANG` - COALESCE(`potong_du`, 0), 0), `tot_du`)
WHERE `is_active` = 1;

DROP TEMPORARY TABLE IF EXISTS `seed_current_students`;
CREATE TEMPORARY TABLE `seed_current_students` AS
SELECT
  ROW_NUMBER() OVER (ORDER BY CAST(s.`KELAS` AS UNSIGNED), COALESCE(mk.`kode_rombel`, ''), s.`NO_INDUK`) AS `n`,
  s.`NO_INDUK` AS `no_induk`,
  s.`NAMA` AS `nama`,
  s.`KELAS` AS `kelas`,
  s.`master_kelas_id`,
  COALESCE(mk.`kode_rombel`, '') AS `rombel`,
  COALESCE(NULLIF(s.`SPP_PERBULAN`, 0), 250000) AS `spp`,
  COALESCE(NULLIF(s.`POMG`, 0), 100000) AS `komite`,
  COALESCE(NULLIF(s.`PANGKAL`, 0), 1000000) AS `pangkal`,
  COALESCE(NULLIF(s.`BANGUNAN`, 0), 1500000) AS `bangunan`,
  COALESCE(NULLIF(s.`SERAGAM`, 0), 500000) AS `seragam`,
  COALESCE(NULLIF(s.`KEGIATAN`, 0), 300000) AS `kegiatan`,
  COALESCE(NULLIF(s.`MAKAN`, 0), 180000) AS `makan`,
  COALESCE(NULLIF(s.`SORGA`, 0), 50000) AS `sorga`,
  COALESCE(NULLIF(s.`INFAQ`, 0), 25000) AS `infaq`,
  COALESCE(s.`potong_pangkal`, 0) AS `potong_pangkal`,
  COALESCE(s.`potong_du`, 0) AS `potong_du`
FROM `siswa` s
LEFT JOIN `master_kelas` mk ON mk.`id` = s.`master_kelas_id`
WHERE s.`is_active` = 1;

ALTER TABLE `seed_current_students` ADD PRIMARY KEY (`no_induk`);
CREATE INDEX `idx_seed_current_n` ON `seed_current_students` (`n`);

UPDATE `tagihan_tahunan_siswa` t
JOIN (
  SELECT 'pangkal' AS `komponen`, sc.`no_induk`, sc.`pangkal` AS `nominal_awal`, sc.`potong_pangkal` AS `potongan`, GREATEST(sc.`pangkal` - sc.`potong_pangkal`, 0) AS `nominal_tagihan` FROM `seed_current_students` sc
  UNION ALL SELECT 'bangunan', sc.`no_induk`, sc.`bangunan`, 0, sc.`bangunan` FROM `seed_current_students` sc
  UNION ALL SELECT 'seragam', sc.`no_induk`, sc.`seragam`, 0, sc.`seragam` FROM `seed_current_students` sc
  UNION ALL SELECT 'kegiatan', sc.`no_induk`, sc.`kegiatan`, 0, sc.`kegiatan` FROM `seed_current_students` sc
  UNION ALL SELECT 'komite', sc.`no_induk`, sc.`komite`, 0, sc.`komite` FROM `seed_current_students` sc
  UNION ALL SELECT 'makan', sc.`no_induk`, sc.`makan`, 0, sc.`makan` FROM `seed_current_students` sc
  UNION ALL SELECT 'sorga', sc.`no_induk`, sc.`sorga`, 0, sc.`sorga` FROM `seed_current_students` sc
  UNION ALL SELECT 'infaq', sc.`no_induk`, sc.`infaq`, 0, sc.`infaq` FROM `seed_current_students` sc
) fees ON fees.`no_induk` = t.`no_induk` AND fees.`komponen` = t.`komponen`
SET
  t.`nominal_awal` = fees.`nominal_awal`,
  t.`potongan` = fees.`potongan`,
  t.`nominal_tagihan` = fees.`nominal_tagihan`
WHERE t.`tahun_ajaran_snapshot` = @seed_year
  AND t.`status` = 'open'
  AND COALESCE(t.`nominal_tagihan`, 0) <= 0;

INSERT INTO `siswa_tahun_ajaran` (
  `tahun_ajaran_id`, `no_induk`, `kelas`, `master_kelas_id`,
  `kelas_rombel_snapshot`, `spp_perbulan_snapshot`, `komite_snapshot`, `status`
)
SELECT
  @seed_year_id, s.`no_induk`, LEFT(s.`kelas`, 1), s.`master_kelas_id`,
  CASE WHEN s.`rombel` <> '' THEN CONCAT(LEFT(s.`kelas`, 1), s.`rombel`) ELSE CONCAT('Kelas ', LEFT(s.`kelas`, 1)) END,
  s.`spp`, s.`komite`, 'aktif'
FROM `seed_current_students` s
WHERE NOT EXISTS (
  SELECT 1 FROM `siswa_tahun_ajaran` sta
  WHERE sta.`tahun_ajaran_id` = @seed_year_id AND sta.`no_induk` = s.`no_induk`
);

INSERT INTO `tagihan_daftar_ulang` (
  `tahun_ajaran_id`, `penempatan_id`, `master_daftar_ulang_id`,
  `no_induk`, `kelas_snapshot`, `tahun_ajaran_snapshot`,
  `nominal_awal`, `nominal_tagihan`, `status`
)
SELECT
  @seed_year_id, sta.`id`, du.`id`, s.`no_induk`, LEFT(s.`kelas`, 1), @seed_year,
  COALESCE(NULLIF(sw.`DAFTAR_ULANG`, 0), du.`Jumlah`, s.`pangkal`),
  GREATEST(COALESCE(NULLIF(sw.`tot_du`, 0), NULLIF(sw.`DAFTAR_ULANG`, 0), du.`Jumlah`, s.`pangkal`) - COALESCE(sw.`potong_du`, 0), 0),
  'open'
FROM `seed_current_students` s
JOIN `siswa` sw ON sw.`NO_INDUK` = s.`no_induk`
JOIN `siswa_tahun_ajaran` sta ON sta.`tahun_ajaran_id` = @seed_year_id AND sta.`no_induk` = s.`no_induk`
LEFT JOIN `Daftar_ulang` du ON du.`th_ajaran` = @seed_year AND du.`kelas` = LEFT(s.`kelas`, 1)
WHERE NOT EXISTS (
  SELECT 1 FROM `tagihan_daftar_ulang` td
  WHERE td.`tahun_ajaran_id` = @seed_year_id AND td.`no_induk` = s.`no_induk`
);

INSERT INTO `tagihan_tahunan_siswa` (
  `tahun_ajaran_id`, `penempatan_id`, `no_induk`, `komponen`, `nama_snapshot`,
  `kelas_snapshot`, `kelas_rombel_snapshot`, `tahun_ajaran_snapshot`,
  `nominal_awal`, `potongan`, `nominal_tagihan`, `status`, `created_by`
)
SELECT
  @seed_year_id, sta.`id`, s.`no_induk`, fees.`komponen`, s.`nama`,
  LEFT(s.`kelas`, 1), sta.`kelas_rombel_snapshot`, @seed_year,
  fees.`nominal_awal`, fees.`potongan`, fees.`nominal_tagihan`, 'open', 'seed-fill'
FROM `seed_current_students` s
JOIN `siswa_tahun_ajaran` sta ON sta.`tahun_ajaran_id` = @seed_year_id AND sta.`no_induk` = s.`no_induk`
JOIN (
  SELECT 'pangkal' AS `komponen`, sc.`no_induk`, sc.`pangkal` AS `nominal_awal`, sc.`potong_pangkal` AS `potongan`, GREATEST(sc.`pangkal` - sc.`potong_pangkal`, 0) AS `nominal_tagihan` FROM `seed_current_students` sc
  UNION ALL SELECT 'bangunan', sc.`no_induk`, sc.`bangunan`, 0, sc.`bangunan` FROM `seed_current_students` sc
  UNION ALL SELECT 'seragam', sc.`no_induk`, sc.`seragam`, 0, sc.`seragam` FROM `seed_current_students` sc
  UNION ALL SELECT 'kegiatan', sc.`no_induk`, sc.`kegiatan`, 0, sc.`kegiatan` FROM `seed_current_students` sc
  UNION ALL SELECT 'komite', sc.`no_induk`, sc.`komite`, 0, sc.`komite` FROM `seed_current_students` sc
  UNION ALL SELECT 'makan', sc.`no_induk`, sc.`makan`, 0, sc.`makan` FROM `seed_current_students` sc
  UNION ALL SELECT 'sorga', sc.`no_induk`, sc.`sorga`, 0, sc.`sorga` FROM `seed_current_students` sc
  UNION ALL SELECT 'infaq', sc.`no_induk`, sc.`infaq`, 0, sc.`infaq` FROM `seed_current_students` sc
) fees ON fees.`no_induk` = s.`no_induk`
WHERE NOT EXISTS (
  SELECT 1 FROM `tagihan_tahunan_siswa` t
  WHERE t.`tahun_ajaran_id` = @seed_year_id
    AND t.`no_induk` = s.`no_induk`
    AND t.`komponen` = fees.`komponen`
);

INSERT INTO `tagihan_biaya_lain` (
  `master_biaya_lain_id`, `no_induk`, `master_kelas_id`,
  `nama_snapshot`, `nominal_tagihan`, `kelas_rombel_snapshot`, `status`, `created_by`
)
SELECT
  m.`id`, s.`no_induk`, s.`master_kelas_id`, m.`nama`, m.`nominal`,
  CASE WHEN s.`rombel` <> '' THEN CONCAT(LEFT(s.`kelas`, 1), s.`rombel`) ELSE CONCAT('Kelas ', LEFT(s.`kelas`, 1)) END,
  'open', @seed_admin_id
FROM `seed_current_students` s
JOIN `master_biaya_lain` m ON m.`is_active` = 1
WHERE NOT EXISTS (
  SELECT 1 FROM `tagihan_biaya_lain` t
  WHERE t.`master_biaya_lain_id` = m.`id` AND t.`no_induk` = s.`no_induk`
);

-- SPP Juli dan Agustus. SPP mengikuti aturan baru: wajib full per bulan.
INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_SPP`, `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  q.`no_induk`, q.`kelas`, q.`master_kelas_id`, q.`kelas_rombel_snapshot`,
  q.`amount`, CONCAT('SEED:FILL:SPP:JUL:', q.`no_induk`),
  DATE_ADD('2026-07-01 08:00:00', INTERVAL MOD(q.`n`, 25) DAY),
  '07', '2026', CONCAT('kasir', MOD(q.`n`, 4) + 1),
  ELT(MOD(q.`n`, 3) + 1, 'Tunai', 'VA', 'Qris'),
  @seed_year, q.`amount`, 1
FROM (
  SELECT
    s.*,
    CASE WHEN s.`rombel` <> '' THEN CONCAT(LEFT(s.`kelas`, 1), s.`rombel`) ELSE CONCAT('Kelas ', LEFT(s.`kelas`, 1)) END AS `kelas_rombel_snapshot`,
    CASE WHEN COALESCE(p.`paid`, 0) > 0 THEN 0 ELSE s.`spp` END AS `amount`
  FROM `seed_current_students` s
  LEFT JOIN (
    SELECT bsp.`no_induk`, COALESCE(SUM(b.`U_SPP`), 0) AS `paid`
    FROM `bayar_spp_periode` bsp
    JOIN `bayar` b ON b.`id` = bsp.`bayar_id`
    WHERE bsp.`bulan` = '07' AND bsp.`tahun` = '2026'
    GROUP BY bsp.`no_induk`
  ) p ON p.`no_induk` = s.`no_induk`
) q
WHERE q.`amount` > 0
  AND NOT EXISTS (SELECT 1 FROM `bayar` b WHERE b.`KETERANGAN` = CONCAT('SEED:FILL:SPP:JUL:', q.`no_induk`));

INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_SPP`, `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  q.`no_induk`, q.`kelas`, q.`master_kelas_id`, q.`kelas_rombel_snapshot`,
  q.`amount`, CONCAT('SEED:FILL:SPP:AUG-A:', q.`no_induk`),
  DATE_ADD('2026-08-01 08:30:00', INTERVAL MOD(q.`n`, 20) DAY),
  '08', '2026', CONCAT('kasir', MOD(q.`n` + 1, 4) + 1),
  ELT(MOD(q.`n` + 1, 3) + 1, 'Tunai', 'VA', 'Qris'),
  @seed_year, q.`amount`, 1
FROM (
  SELECT
    s.*,
    CASE WHEN s.`rombel` <> '' THEN CONCAT(LEFT(s.`kelas`, 1), s.`rombel`) ELSE CONCAT('Kelas ', LEFT(s.`kelas`, 1)) END AS `kelas_rombel_snapshot`,
    CASE WHEN COALESCE(p.`paid`, 0) > 0 THEN 0 ELSE s.`spp` END AS `amount`
  FROM `seed_current_students` s
  LEFT JOIN (
    SELECT bsp.`no_induk`, COALESCE(SUM(b.`U_SPP`), 0) AS `paid`
    FROM `bayar_spp_periode` bsp
    JOIN `bayar` b ON b.`id` = bsp.`bayar_id`
    WHERE bsp.`bulan` = '08' AND bsp.`tahun` = '2026'
    GROUP BY bsp.`no_induk`
  ) p ON p.`no_induk` = s.`no_induk`
) q
WHERE q.`amount` > 0
  AND NOT EXISTS (SELECT 1 FROM `bayar` b WHERE b.`KETERANGAN` = CONCAT('SEED:FILL:SPP:AUG-A:', q.`no_induk`));

INSERT INTO `bayar_spp_periode` (`bayar_id`, `no_induk`, `bulan`, `tahun`)
SELECT b.`id`, b.`NO_INDUK`, b.`BULAN`, b.`TAHUN`
FROM `bayar` b
WHERE b.`KETERANGAN` LIKE 'SEED:FILL:SPP:%'
  AND b.`U_SPP` > 0
  AND NOT EXISTS (SELECT 1 FROM `bayar_spp_periode` p WHERE p.`bayar_id` = b.`id`)
  AND NOT EXISTS (SELECT 1 FROM `bayar_spp_periode` p WHERE p.`no_induk` = b.`NO_INDUK` AND p.`bulan` = b.`BULAN` AND p.`tahun` = b.`TAHUN`);

-- Pembayaran komponen tahunan: pangkal, bangunan, seragam, kegiatan, komite, makan, sorga, infaq.
DROP TEMPORARY TABLE IF EXISTS `seed_annual_due`;
CREATE TEMPORARY TABLE `seed_annual_due` AS
SELECT
  s.`n`, s.`no_induk`, s.`kelas`, s.`master_kelas_id`,
  CASE WHEN s.`rombel` <> '' THEN CONCAT(LEFT(s.`kelas`, 1), s.`rombel`) ELSE CONCAT('Kelas ', LEFT(s.`kelas`, 1)) END AS `kelas_rombel_snapshot`,
  t.`id` AS `tagihan_id`, t.`komponen`, t.`nominal_tagihan`,
  CASE t.`komponen`
    WHEN 'pangkal' THEN 1 WHEN 'bangunan' THEN 2 WHEN 'seragam' THEN 3 WHEN 'kegiatan' THEN 4
    WHEN 'komite' THEN 5 WHEN 'makan' THEN 6 WHEN 'sorga' THEN 7 ELSE 8
  END AS `komponen_no`,
  LEAST(
    GREATEST(t.`nominal_tagihan` - COALESCE(p.`paid`, 0), 0),
    CASE MOD(s.`n` + CASE t.`komponen`
      WHEN 'pangkal' THEN 1 WHEN 'bangunan' THEN 2 WHEN 'seragam' THEN 3 WHEN 'kegiatan' THEN 4
      WHEN 'komite' THEN 5 WHEN 'makan' THEN 6 WHEN 'sorga' THEN 7 ELSE 8
    END, 4)
      WHEN 0 THEN t.`nominal_tagihan`
      WHEN 1 THEN ROUND(t.`nominal_tagihan` * 0.5, 0)
      WHEN 2 THEN ROUND(t.`nominal_tagihan` * 0.35, 0)
      ELSE t.`nominal_tagihan`
    END
  ) AS `amount`
FROM `tagihan_tahunan_siswa` t
JOIN `seed_current_students` s ON s.`no_induk` = t.`no_induk`
LEFT JOIN (
  SELECT
    b.`NO_INDUK`,
    x.`komponen`,
    COALESCE(SUM(x.`jumlah`), 0) AS `paid`
  FROM `bayar` b
  JOIN (
    SELECT `id`, 'pangkal' AS `komponen`, `U_PANGKAL` AS `jumlah` FROM `bayar`
    UNION ALL SELECT `id`, 'bangunan', `U_BANGUNAN` FROM `bayar`
    UNION ALL SELECT `id`, 'seragam', `U_SERAGAM` FROM `bayar`
    UNION ALL SELECT `id`, 'kegiatan', `U_KEGIATAN` FROM `bayar`
    UNION ALL SELECT `id`, 'komite', `U_KOMITE` FROM `bayar`
    UNION ALL SELECT `id`, 'makan', `U_MAKAN` FROM `bayar`
    UNION ALL SELECT `id`, 'sorga', `U_SORGA` FROM `bayar`
    UNION ALL SELECT `id`, 'infaq', `U_INFAQ` FROM `bayar`
  ) x ON x.`id` = b.`id`
  WHERE b.`th_ajaran` = @seed_year
  GROUP BY b.`NO_INDUK`, x.`komponen`
) p ON p.`NO_INDUK` = t.`no_induk` AND p.`komponen` = t.`komponen`
WHERE t.`tahun_ajaran_snapshot` = @seed_year AND t.`status` = 'open';

ALTER TABLE `seed_annual_due` ADD INDEX `idx_seed_annual_due` (`no_induk`, `komponen`);

INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_PANGKAL`, `U_BANGUNAN`, `U_SERAGAM`, `U_KEGIATAN`, `U_KOMITE`, `U_MAKAN`, `U_SORGA`, `U_INFAQ`,
  `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  d.`no_induk`, d.`kelas`, d.`master_kelas_id`, d.`kelas_rombel_snapshot`,
  CASE WHEN d.`komponen` = 'pangkal' THEN d.`amount` ELSE 0 END,
  CASE WHEN d.`komponen` = 'bangunan' THEN d.`amount` ELSE 0 END,
  CASE WHEN d.`komponen` = 'seragam' THEN d.`amount` ELSE 0 END,
  CASE WHEN d.`komponen` = 'kegiatan' THEN d.`amount` ELSE 0 END,
  CASE WHEN d.`komponen` = 'komite' THEN d.`amount` ELSE 0 END,
  CASE WHEN d.`komponen` = 'makan' THEN d.`amount` ELSE 0 END,
  CASE WHEN d.`komponen` = 'sorga' THEN d.`amount` ELSE 0 END,
  CASE WHEN d.`komponen` = 'infaq' THEN d.`amount` ELSE 0 END,
  CONCAT('SEED:FILL:ANNUAL:', d.`komponen`, ':', d.`no_induk`),
  DATE_ADD('2026-07-03 10:15:00', INTERVAL MOD(d.`n` + d.`komponen_no`, 48) DAY),
  CASE WHEN MOD(d.`n` + d.`komponen_no`, 48) < 29 THEN '07' ELSE '08' END,
  '2026',
  CONCAT('kasir', MOD(d.`n` + d.`komponen_no`, 4) + 1),
  ELT(MOD(d.`n` + d.`komponen_no`, 3) + 1, 'Tunai', 'VA', 'Qris'),
  @seed_year, d.`amount`, 1
FROM `seed_annual_due` d
WHERE d.`amount` > 0
  AND NOT EXISTS (
    SELECT 1 FROM `bayar` b
    WHERE b.`KETERANGAN` = CONCAT('SEED:FILL:ANNUAL:', d.`komponen`, ':', d.`no_induk`)
  );

-- Pembayaran Daftar Ulang.
INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `kelas_du`, `total_jumlah`, `payment_link_version`
)
SELECT
  q.`no_induk`, q.`kelas`, q.`master_kelas_id`, q.`kelas_rombel_snapshot`,
  CONCAT('SEED:FILL:DU:', q.`no_induk`),
  DATE_ADD('2026-07-04 11:00:00', INTERVAL MOD(q.`n`, 44) DAY),
  CASE WHEN MOD(q.`n`, 44) < 28 THEN '07' ELSE '08' END,
  '2026', CONCAT('kasir', MOD(q.`n` + 3, 4) + 1),
  ELT(MOD(q.`n` + 1, 3) + 1, 'Tunai', 'VA', 'Qris'),
  @seed_year, LEFT(q.`kelas`, 1), q.`amount`, 1
FROM (
  SELECT
    s.*,
    CASE WHEN s.`rombel` <> '' THEN CONCAT(LEFT(s.`kelas`, 1), s.`rombel`) ELSE CONCAT('Kelas ', LEFT(s.`kelas`, 1)) END AS `kelas_rombel_snapshot`,
    LEAST(
      GREATEST(td.`nominal_tagihan` - COALESCE(p.`paid`, 0), 0),
      CASE WHEN MOD(s.`n`, 3) = 0 THEN td.`nominal_tagihan` ELSE ROUND(td.`nominal_tagihan` * 0.5, 0) END
    ) AS `amount`
  FROM `seed_current_students` s
  JOIN `tagihan_daftar_ulang` td ON td.`tahun_ajaran_id` = @seed_year_id AND td.`no_induk` = s.`no_induk`
  LEFT JOIN (
    SELECT `tagihan_daftar_ulang_id`, COALESCE(SUM(`jumlah`), 0) AS `paid`
    FROM `bayar_du`
    GROUP BY `tagihan_daftar_ulang_id`
  ) p ON p.`tagihan_daftar_ulang_id` = td.`id`
) q
WHERE q.`amount` > 0
  AND NOT EXISTS (SELECT 1 FROM `bayar` b WHERE b.`KETERANGAN` = CONCAT('SEED:FILL:DU:', q.`no_induk`));

INSERT INTO `bayar_du` (`bayar_id`, `tagihan_daftar_ulang_id`, `no_induk`, `kelas`, `th_ajaran`, `jumlah`)
SELECT b.`id`, td.`id`, b.`NO_INDUK`, b.`kelas_du`, @seed_year, b.`total_jumlah`
FROM `bayar` b
JOIN `tagihan_daftar_ulang` td ON td.`tahun_ajaran_id` = @seed_year_id AND td.`no_induk` = b.`NO_INDUK`
WHERE b.`KETERANGAN` LIKE 'SEED:FILL:DU:%'
  AND NOT EXISTS (SELECT 1 FROM `bayar_du` bd WHERE bd.`bayar_id` = b.`id`);

-- Biaya lain: satu transaksi multi-item mengisi slot legacy 1 sampai 4, dan transaksi kedua untuk item kelima bila ada.
DROP TEMPORARY TABLE IF EXISTS `seed_fee_items`;
CREATE TEMPORARY TABLE `seed_fee_items` AS
SELECT
  z.*,
  LEAST(GREATEST(z.`nominal_tagihan` - COALESCE(z.`paid`, 0), 0),
    CASE WHEN MOD(z.`n` + z.`rn`, 4) = 0 THEN ROUND(z.`nominal_tagihan` * 0.5, 0) ELSE z.`nominal_tagihan` END
  ) AS `amount`
FROM (
  SELECT
    s.`n`, s.`no_induk`, s.`kelas`, s.`master_kelas_id`,
    CASE WHEN s.`rombel` <> '' THEN CONCAT(LEFT(s.`kelas`, 1), s.`rombel`) ELSE CONCAT('Kelas ', LEFT(s.`kelas`, 1)) END AS `kelas_rombel_snapshot`,
    t.`id` AS `tagihan_id`, m.`id` AS `master_id`, m.`nama`, t.`nominal_tagihan`,
    ROW_NUMBER() OVER (PARTITION BY s.`no_induk` ORDER BY m.`id`) AS `rn`,
    COALESCE(p.`paid`, 0) AS `paid`
  FROM `seed_current_students` s
  JOIN `tagihan_biaya_lain` t ON t.`no_induk` = s.`no_induk` AND t.`status` = 'open'
  JOIN `master_biaya_lain` m ON m.`id` = t.`master_biaya_lain_id`
  LEFT JOIN (
    SELECT `tagihan_biaya_lain_id`, COALESCE(SUM(`nominal_snapshot`), 0) AS `paid`
    FROM `bayar_biaya_lain`
    GROUP BY `tagihan_biaya_lain_id`
  ) p ON p.`tagihan_biaya_lain_id` = t.`id`
) z;

ALTER TABLE `seed_fee_items` ADD INDEX `idx_seed_fee_items` (`no_induk`, `rn`);

INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_LAIN`, `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `LAIN_LAIN1`, `JUMLAH1`, `LAIN_LAIN2`, `JUMLAH2`, `LAIN_LAIN3`, `JUMLAH3`, `LAIN_LAIN4`, `JUMLAH4`,
  `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  f.`no_induk`, MAX(f.`kelas`), MAX(f.`master_kelas_id`), MAX(f.`kelas_rombel_snapshot`),
  SUM(f.`amount`), CONCAT('SEED:FILL:LAIN-A:', f.`no_induk`),
  DATE_ADD('2026-07-05 12:20:00', INTERVAL MOD(MAX(f.`n`), 45) DAY),
  CASE WHEN MOD(MAX(f.`n`), 45) < 27 THEN '07' ELSE '08' END,
  '2026', CONCAT('kasir', MOD(MAX(f.`n`) + 2, 4) + 1),
  ELT(MOD(MAX(f.`n`) + 2, 3) + 1, 'Tunai', 'VA', 'Qris'),
  MAX(CASE WHEN f.`rn` = 1 THEN f.`nama` END), MAX(CASE WHEN f.`rn` = 1 THEN f.`amount` ELSE 0 END),
  MAX(CASE WHEN f.`rn` = 2 THEN f.`nama` END), MAX(CASE WHEN f.`rn` = 2 THEN f.`amount` ELSE 0 END),
  MAX(CASE WHEN f.`rn` = 3 THEN f.`nama` END), MAX(CASE WHEN f.`rn` = 3 THEN f.`amount` ELSE 0 END),
  MAX(CASE WHEN f.`rn` = 4 THEN f.`nama` END), MAX(CASE WHEN f.`rn` = 4 THEN f.`amount` ELSE 0 END),
  @seed_year, SUM(f.`amount`), 1
FROM `seed_fee_items` f
WHERE f.`rn` BETWEEN 1 AND 4 AND f.`amount` > 0
GROUP BY f.`no_induk`
HAVING SUM(f.`amount`) > 0
   AND NOT EXISTS (SELECT 1 FROM `bayar` b WHERE b.`KETERANGAN` = CONCAT('SEED:FILL:LAIN-A:', f.`no_induk`));

INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_LAIN`, `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `LAIN_LAIN1`, `JUMLAH1`, `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  f.`no_induk`, f.`kelas`, f.`master_kelas_id`, f.`kelas_rombel_snapshot`,
  f.`amount`, CONCAT('SEED:FILL:LAIN-B:', f.`no_induk`),
  DATE_ADD('2026-08-01 12:45:00', INTERVAL MOD(f.`n`, 20) DAY),
  '08', '2026', CONCAT('kasir', MOD(f.`n` + 3, 4) + 1),
  ELT(MOD(f.`n` + 3, 3) + 1, 'Tunai', 'VA', 'Qris'),
  f.`nama`, f.`amount`, @seed_year, f.`amount`, 1
FROM `seed_fee_items` f
WHERE f.`rn` = 5 AND f.`amount` > 0
  AND NOT EXISTS (SELECT 1 FROM `bayar` b WHERE b.`KETERANGAN` = CONCAT('SEED:FILL:LAIN-B:', f.`no_induk`));

INSERT INTO `bayar_biaya_lain` (
  `bayar_id`, `master_biaya_lain_id`, `tagihan_biaya_lain_id`,
  `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`
)
SELECT b.`id`, f.`master_id`, f.`tagihan_id`, f.`nama`, f.`amount`, NULL, f.`rn`, CONCAT('LL', f.`rn`)
FROM `seed_fee_items` f
JOIN `bayar` b ON b.`KETERANGAN` = CONCAT('SEED:FILL:LAIN-A:', f.`no_induk`)
WHERE f.`rn` BETWEEN 1 AND 4 AND f.`amount` > 0
  AND NOT EXISTS (
    SELECT 1 FROM `bayar_biaya_lain` d
    WHERE d.`bayar_id` = b.`id` AND d.`legacy_key` = CONCAT('LL', f.`rn`)
  );

INSERT INTO `bayar_biaya_lain` (
  `bayar_id`, `master_biaya_lain_id`, `tagihan_biaya_lain_id`,
  `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`
)
SELECT b.`id`, f.`master_id`, f.`tagihan_id`, f.`nama`, f.`amount`, NULL, 1, 'LL1'
FROM `seed_fee_items` f
JOIN `bayar` b ON b.`KETERANGAN` = CONCAT('SEED:FILL:LAIN-B:', f.`no_induk`)
WHERE f.`rn` = 5 AND f.`amount` > 0
  AND NOT EXISTS (SELECT 1 FROM `bayar_biaya_lain` d WHERE d.`bayar_id` = b.`id` AND d.`legacy_key` = 'LL1');

-- Detail tahunan dibuat dari kolom bayar agar struk/laporan dan field legacy tetap sinkron.
INSERT INTO `bayar_tahunan_siswa` (`bayar_id`, `tagihan_tahunan_id`, `no_induk`, `komponen`, `th_ajaran`, `jumlah`)
SELECT legacy.`bayar_id`, t.`id`, legacy.`NO_INDUK`, legacy.`komponen`, t.`tahun_ajaran_snapshot`, legacy.`jumlah`
FROM (
  SELECT `id` AS `bayar_id`, `NO_INDUK`, 'pangkal' AS `komponen`, `U_PANGKAL` AS `jumlah` FROM `bayar` WHERE `U_PANGKAL` > 0
  UNION ALL SELECT `id`, `NO_INDUK`, 'bangunan', `U_BANGUNAN` FROM `bayar` WHERE `U_BANGUNAN` > 0
  UNION ALL SELECT `id`, `NO_INDUK`, 'seragam', `U_SERAGAM` FROM `bayar` WHERE `U_SERAGAM` > 0
  UNION ALL SELECT `id`, `NO_INDUK`, 'kegiatan', `U_KEGIATAN` FROM `bayar` WHERE `U_KEGIATAN` > 0
  UNION ALL SELECT `id`, `NO_INDUK`, 'komite', `U_KOMITE` FROM `bayar` WHERE `U_KOMITE` > 0
  UNION ALL SELECT `id`, `NO_INDUK`, 'makan', `U_MAKAN` FROM `bayar` WHERE `U_MAKAN` > 0
  UNION ALL SELECT `id`, `NO_INDUK`, 'sorga', `U_SORGA` FROM `bayar` WHERE `U_SORGA` > 0
  UNION ALL SELECT `id`, `NO_INDUK`, 'infaq', `U_INFAQ` FROM `bayar` WHERE `U_INFAQ` > 0
) legacy
JOIN `tagihan_tahunan_siswa` t ON t.`no_induk` = legacy.`NO_INDUK`
  AND t.`komponen` = legacy.`komponen`
  AND t.`tahun_ajaran_snapshot` = @seed_year
WHERE NOT EXISTS (
  SELECT 1 FROM `bayar_tahunan_siswa` d
  WHERE d.`bayar_id` = legacy.`bayar_id` AND d.`komponen` = legacy.`komponen`
);

-- Riwayat tabungan masuk/keluar dibuat ramai tetapi saldo akhir dijaga tidak minus.
INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  NULL, s.`no_induk`,
  DATE_ADD(e.`tanggal`, INTERVAL MOD(s.`n` + e.`event_no`, 5) HOUR),
  15000 + (MOD(s.`n` + e.`event_no`, 6) * 5000),
  0,
  CONCAT('kasir', MOD(s.`n` + e.`event_no`, 4) + 1)
FROM `seed_current_students` s
JOIN (
  SELECT 1 AS `event_no`, TIMESTAMP('2026-07-02', '07:45:00') AS `tanggal` UNION ALL
  SELECT 2, TIMESTAMP('2026-07-08', '08:10:00') UNION ALL
  SELECT 3, TIMESTAMP('2026-07-15', '08:25:00') UNION ALL
  SELECT 4, TIMESTAMP('2026-07-22', '09:05:00') UNION ALL
  SELECT 5, TIMESTAMP('2026-07-29', '09:20:00') UNION ALL
  SELECT 6, TIMESTAMP('2026-08-05', '08:35:00') UNION ALL
  SELECT 7, TIMESTAMP('2026-08-12', '08:50:00') UNION ALL
  SELECT 8, TIMESTAMP('2026-08-19', '09:15:00')
) e
WHERE NOT EXISTS (
  SELECT 1 FROM `transaksi_m` tm
  WHERE tm.`NO_INDUK` = s.`no_induk`
    AND tm.`TANGGAL` = DATE_ADD(e.`tanggal`, INTERVAL MOD(s.`n` + e.`event_no`, 5) HOUR)
    AND tm.`MASUK` = 15000 + (MOD(s.`n` + e.`event_no`, 6) * 5000)
);

INSERT INTO `transaksi_k` (`NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  s.`no_induk`,
  DATE_ADD(e.`tanggal`, INTERVAL MOD(s.`n` + e.`event_no`, 5) HOUR),
  0,
  5000 + (MOD(s.`n` + e.`event_no`, 5) * 3000),
  CONCAT('kasir', MOD(s.`n` + e.`event_no` + 1, 4) + 1)
FROM `seed_current_students` s
JOIN (
  SELECT 1 AS `event_no`, TIMESTAMP('2026-07-16', '10:40:00') AS `tanggal` UNION ALL
  SELECT 2, TIMESTAMP('2026-08-06', '11:05:00') UNION ALL
  SELECT 3, TIMESTAMP('2026-08-20', '10:20:00')
) e
WHERE (MOD(s.`n`, 4) <> 0 OR e.`event_no` = 2)
  AND NOT EXISTS (
    SELECT 1 FROM `transaksi_k` tk
    WHERE tk.`NO_INDUK` = s.`no_induk`
      AND tk.`TANGGAL` = DATE_ADD(e.`tanggal`, INTERVAL MOD(s.`n` + e.`event_no`, 5) HOUR)
      AND tk.`KELUAR` = 5000 + (MOD(s.`n` + e.`event_no`, 5) * 3000)
  );

INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  NULL, x.`NO_INDUK`, '2026-07-01 07:05:00', ABS(x.`saldo`), 0, 'SEED_KOREKSI'
FROM (
  SELECT z.`NO_INDUK`, COALESCE(SUM(z.`masuk`), 0) - COALESCE(SUM(z.`keluar`), 0) AS `saldo`
  FROM (
    SELECT `NO_INDUK`, `MASUK` AS `masuk`, 0 AS `keluar` FROM `transaksi_m`
    UNION ALL
    SELECT `NO_INDUK`, 0, `KELUAR` FROM `transaksi_k`
  ) z
  GROUP BY z.`NO_INDUK`
) x
WHERE x.`saldo` < 0
  AND NOT EXISTS (
    SELECT 1 FROM `transaksi_m` tm
    WHERE tm.`NO_INDUK` = x.`NO_INDUK`
      AND tm.`TANGGAL` = '2026-07-01 07:05:00'
      AND tm.`user_id` = 'SEED_KOREKSI'
  );

INSERT INTO `tabungan` (`NO_INDUK`, `SALDO`)
SELECT s.`no_induk`, GREATEST(COALESCE(m.`masuk`, 0) - COALESCE(k.`keluar`, 0), 0)
FROM `seed_current_students` s
LEFT JOIN (
  SELECT `NO_INDUK`, SUM(`MASUK`) AS `masuk`
  FROM `transaksi_m`
  GROUP BY `NO_INDUK`
) m ON m.`NO_INDUK` = s.`no_induk`
LEFT JOIN (
  SELECT `NO_INDUK`, SUM(`KELUAR`) AS `keluar`
  FROM `transaksi_k`
  GROUP BY `NO_INDUK`
) k ON k.`NO_INDUK` = s.`no_induk`
ON DUPLICATE KEY UPDATE `SALDO` = VALUES(`SALDO`);

UPDATE `siswa` s
LEFT JOIN (
  SELECT
    `no_induk`,
    COALESCE(SUM(CASE WHEN `komponen` = 'pangkal' THEN `jumlah` ELSE 0 END), 0) AS `pangkal_bayar`,
    COALESCE(SUM(CASE WHEN `komponen` = 'bangunan' THEN `jumlah` ELSE 0 END), 0) AS `bangunan_bayar`,
    COALESCE(SUM(CASE WHEN `komponen` = 'seragam' THEN `jumlah` ELSE 0 END), 0) AS `seragam_bayar`,
    COALESCE(SUM(CASE WHEN `komponen` = 'kegiatan' THEN `jumlah` ELSE 0 END), 0) AS `kegiatan_bayar`
  FROM `bayar_tahunan_siswa`
  WHERE `th_ajaran` = @seed_year
  GROUP BY `no_induk`
) p ON p.`no_induk` = s.`NO_INDUK`
SET
  s.`PANGKAL_BAYAR` = COALESCE(p.`pangkal_bayar`, 0),
  s.`BANGUNAN_BAYAR` = COALESCE(p.`bangunan_bayar`, 0),
  s.`SERAGAM_BAYAR` = COALESCE(p.`seragam_bayar`, 0),
  s.`KEGIATAN_BAYAR` = COALESCE(p.`kegiatan_bayar`, 0)
WHERE s.`NO_INDUK` IN (SELECT `no_induk` FROM `seed_current_students`);

COMMIT;

DROP TEMPORARY TABLE IF EXISTS `seed_fee_items`;
DROP TEMPORARY TABLE IF EXISTS `seed_annual_due`;
DROP TEMPORARY TABLE IF EXISTS `seed_current_students`;

SELECT 'seed_fill_demo_completeness selesai' AS `status`;
SELECT 'siswa' AS `tabel`, COUNT(*) AS `total` FROM `siswa`
UNION ALL SELECT 'bayar', COUNT(*) FROM `bayar`
UNION ALL SELECT 'bayar_tahunan_siswa', COUNT(*) FROM `bayar_tahunan_siswa`
UNION ALL SELECT 'bayar_biaya_lain', COUNT(*) FROM `bayar_biaya_lain`
UNION ALL SELECT 'transaksi_m', COUNT(*) FROM `transaksi_m`
UNION ALL SELECT 'transaksi_k', COUNT(*) FROM `transaksi_k`
UNION ALL SELECT 'tabungan', COUNT(*) FROM `tabungan`;
