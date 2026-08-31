-- =========================================================
-- Seeder dataset demo laporan SistemSPP
-- Destructive untuk data operasional demo/dev.
-- Mengisi template rombel 1A-6J, 144 siswa demo pada rombel A-D,
-- dan transaksi Juli-Agustus 2026.
-- =========================================================

USE `db_spp`;

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

DELETE FROM `bayar_biaya_lain`;
DELETE FROM `bayar_tahunan_siswa`;
DELETE FROM `bayar_spp_periode`;
DELETE FROM `bayar_du`;
DELETE FROM `transaksi_m`;
DELETE FROM `transaksi_k`;
DELETE FROM `tabungan`;
DELETE FROM `bayar`;
DELETE FROM `tagihan_biaya_lain_audit_log`;
DELETE FROM `tagihan_biaya_lain`;
DELETE FROM `master_biaya_lain`;
DELETE FROM `daftar_ulang_audit_log`;
DELETE FROM `tagihan_daftar_ulang`;
DELETE FROM `tagihan_tahunan_siswa`;
DELETE FROM `Daftar_ulang`;
DELETE FROM `siswa_tahun_ajaran`;
DELETE FROM `siswa_audit_log`;
DELETE FROM `siswa`;
DELETE FROM `tahun_ajaran`;
DELETE FROM `master_kelas`;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

INSERT INTO `admin` (`username`, `password`, `nama`, `role`) VALUES
('kasir1', MD5('kasir123'), 'Kasir Loket 1', 'kasir'),
('kasir2', MD5('kasir123'), 'Kasir Loket 2', 'kasir'),
('kasir3', MD5('kasir123'), 'Kasir Loket 3', 'kasir'),
('kasir4', MD5('kasir123'), 'Kasir Loket 4', 'kasir')
ON DUPLICATE KEY UPDATE `nama` = VALUES(`nama`), `role` = VALUES(`role`);

INSERT INTO `master_kelas` (`tingkat`, `kode_rombel`, `is_placeholder`, `is_active`)
SELECT t.`tingkat`, r.`kode_rombel`, 0, 1
FROM (
  SELECT 1 AS `tingkat` UNION ALL SELECT 2 UNION ALL SELECT 3
  UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) t
CROSS JOIN (
  SELECT 'A' AS `kode_rombel` UNION ALL SELECT 'B' UNION ALL SELECT 'C' UNION ALL SELECT 'D'
  UNION ALL SELECT 'E' UNION ALL SELECT 'F' UNION ALL SELECT 'G' UNION ALL SELECT 'H'
  UNION ALL SELECT 'I' UNION ALL SELECT 'J'
) r
ORDER BY t.`tingkat`, r.`kode_rombel`;

INSERT INTO `tahun_ajaran` (`label`, `tanggal_mulai`, `tanggal_selesai`, `status`, `published_at`)
VALUES ('2026/2027', '2026-07-01', '2027-06-30', 'published', '2026-07-01 07:00:00');

INSERT INTO `Daftar_ulang` (`tahun_ajaran_id`, `th_ajaran`, `kelas`, `Jumlah`)
SELECT ta.`id`, ta.`label`, x.`kelas`, x.`jumlah`
FROM `tahun_ajaran` ta
JOIN (
  SELECT '1' AS `kelas`, 1000000 AS `jumlah` UNION ALL
  SELECT '2', 1100000 UNION ALL
  SELECT '3', 1200000 UNION ALL
  SELECT '4', 1300000 UNION ALL
  SELECT '5', 1400000 UNION ALL
  SELECT '6', 1500000
) x
WHERE ta.`label` = '2026/2027';

INSERT INTO `master_biaya_lain` (`nama`, `nominal`, `is_active`) VALUES
('Buku Paket', 125000, 1),
('Seragam Olahraga', 175000, 1),
('Kegiatan Tahunan', 150000, 1),
('Modul/Ujian', 95000, 1),
('Ekskul', 80000, 1);

CREATE TEMPORARY TABLE `seed_seq` (`n` INT NOT NULL PRIMARY KEY) ENGINE=MEMORY;

INSERT INTO `seed_seq` (`n`)
SELECT h.`i` * 100 + t.`i` * 10 + o.`i` + 1 AS `n`
FROM (
  SELECT 0 AS `i` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
) o
CROSS JOIN (
  SELECT 0 AS `i` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
) t
CROSS JOIN (
  SELECT 0 AS `i` UNION ALL SELECT 1
) h
WHERE h.`i` * 100 + t.`i` * 10 + o.`i` + 1 BETWEEN 1 AND 144;

CREATE TEMPORARY TABLE `seed_first_names` (`id` INT NOT NULL PRIMARY KEY, `nama` VARCHAR(40) NOT NULL) ENGINE=MEMORY;
INSERT INTO `seed_first_names` (`id`, `nama`) VALUES
(1,'Aditya'),(2,'Alya'),(3,'Bagas'),(4,'Citra'),(5,'Dimas'),(6,'Dewi'),
(7,'Farhan'),(8,'Fauzan'),(9,'Gilang'),(10,'Hana'),(11,'Intan'),(12,'Kirana'),
(13,'Laras'),(14,'Mira'),(15,'Nadia'),(16,'Putri'),(17,'Rafi'),(18,'Raka'),
(19,'Rizky'),(20,'Salsa'),(21,'Sabrina'),(22,'Siti'),(23,'Tiara'),(24,'Zahra');

CREATE TEMPORARY TABLE `seed_last_names` (`id` INT NOT NULL PRIMARY KEY, `nama` VARCHAR(40) NOT NULL) ENGINE=MEMORY;
INSERT INTO `seed_last_names` (`id`, `nama`) VALUES
(1,'Pratama'),(2,'Ramadhani'),(3,'Maulana'),(4,'Lestari'),(5,'Saputra'),(6,'Azzahra');

CREATE TEMPORARY TABLE `seed_known_names` (
  `n` INT NOT NULL PRIMARY KEY,
  `no_induk` VARCHAR(10) NOT NULL,
  `nis_diknas` CHAR(10) NOT NULL,
  `nama` VARCHAR(100) NOT NULL
) ENGINE=MEMORY;

INSERT INTO `seed_known_names` (`n`, `no_induk`, `nis_diknas`, `nama`) VALUES
(1,'2024001','D2600001','Ahmad Fauzi'),
(2,'2024002','D2600002','Siti Rahayu'),
(3,'2024003','D2600003','Budi Santoso'),
(4,'2024004','D2600004','Dewi Lestari'),
(5,'2024005','D2600005','Muhammad Rizky'),
(6,'2024006','D2600006','Ayu Putri'),
(7,'2026101','D2600101','Alya Nabila Demo'),
(8,'2026102','D2600102','Rafi Pratama Demo'),
(9,'2026103','D2600103','Kirana Putri Demo'),
(10,'2026104','D2600104','Bagas Saputra Demo'),
(11,'2026105','D2600105','Naya Ramadhani Demo'),
(12,'2026106','D2600106','Dimas Arya Demo'),
(13,'2026107','D2600107','Salsa Azzahra Demo'),
(14,'2026108','D2600108','Fadli Maulana Demo'),
(15,'2026109','D2600109','Citra Maharani Demo'),
(16,'2026110','D2600110','Farhan Hafizh Demo'),
(17,'2026111','D2600111','Andika Pratama Demo'),
(18,'2026112','D2600112','Mira Aulia Demo'),
(19,'2026113','D2600113','Rizky Ramadhan Demo'),
(20,'2026114','D2600114','Tiara Safitri Demo'),
(21,'2026115','D2600115','Gilang Saputra Demo'),
(22,'2026116','D2600116','Putri Amelia Demo'),
(23,'2026117','D2600117','Raka Firmansyah Demo'),
(24,'2026118','D2600118','Zahra Nuraini Demo'),
(25,'2026119','D2600119','Hafiz Alfarizi Demo'),
(26,'2026120','D2600120','Laras Puspita Demo'),
(27,'2026121','D2600121','Naufal Akbar Demo'),
(28,'2026122','D2600122','Sabrina Fitri Demo'),
(29,'2026123','D2600123','Arkan Maulana Demo'),
(30,'2026124','D2600124','Nadya Khairunnisa Demo');

CREATE TEMPORARY TABLE `seed_students` AS
SELECT
  q.`n`,
  COALESCE(k.`no_induk`, CONCAT('2027', LPAD(q.`n`, 4, '0'))) AS `no_induk`,
  COALESCE(k.`nis_diknas`, CONCAT('D27', LPAD(q.`n`, 7, '0'))) AS `nis_diknas`,
  COALESCE(k.`nama`, CONCAT(f.`nama`, ' ', l.`nama`, ' Demo')) AS `nama`,
  q.`tingkat`,
  q.`rombel`,
  mk.`id` AS `master_kelas_id`,
  CASE q.`tingkat`
    WHEN 1 THEN 250000 WHEN 2 THEN 260000 WHEN 3 THEN 275000
    WHEN 4 THEN 290000 WHEN 5 THEN 305000 ELSE 320000
  END AS `spp`,
  CASE q.`tingkat`
    WHEN 1 THEN 100000 WHEN 2 THEN 110000 WHEN 3 THEN 120000
    WHEN 4 THEN 130000 WHEN 5 THEN 140000 ELSE 150000
  END AS `komite`,
  CASE q.`tingkat`
    WHEN 1 THEN 1000000 WHEN 2 THEN 1100000 WHEN 3 THEN 1200000
    WHEN 4 THEN 1300000 WHEN 5 THEN 1400000 ELSE 1500000
  END AS `pangkal`,
  CASE q.`tingkat`
    WHEN 1 THEN 1500000 WHEN 2 THEN 1550000 WHEN 3 THEN 1600000
    WHEN 4 THEN 1650000 WHEN 5 THEN 1700000 ELSE 1750000
  END AS `bangunan`,
  CASE q.`tingkat`
    WHEN 1 THEN 500000 WHEN 2 THEN 525000 WHEN 3 THEN 550000
    WHEN 4 THEN 575000 WHEN 5 THEN 600000 ELSE 625000
  END AS `seragam`,
  CASE q.`tingkat`
    WHEN 1 THEN 300000 WHEN 2 THEN 325000 WHEN 3 THEN 350000
    WHEN 4 THEN 375000 WHEN 5 THEN 400000 ELSE 425000
  END AS `kegiatan`,
  CASE q.`tingkat`
    WHEN 1 THEN 180000 WHEN 2 THEN 185000 WHEN 3 THEN 190000
    WHEN 4 THEN 195000 WHEN 5 THEN 200000 ELSE 210000
  END AS `makan`,
  CASE WHEN q.`tingkat` <= 2 THEN 50000 WHEN q.`tingkat` <= 4 THEN 60000 ELSE 70000 END AS `sorga`,
  CASE WHEN q.`tingkat` <= 2 THEN 25000 WHEN q.`tingkat` <= 4 THEN 30000 ELSE 40000 END AS `infaq`,
  CASE WHEN MOD(q.`n`, 9) = 0 THEN 50000 ELSE 0 END AS `potong_pangkal`,
  CASE WHEN MOD(q.`n`, 10) = 0 THEN 100000 ELSE 0 END AS `potong_du`
FROM (
  SELECT
    s.`n`,
    FLOOR((s.`n` - 1) / 24) + 1 AS `tingkat`,
    ELT(FLOOR(MOD(s.`n` - 1, 24) / 6) + 1, 'A', 'B', 'C', 'D') AS `rombel`
  FROM `seed_seq` s
) q
JOIN `master_kelas` mk
  ON mk.`tingkat` = q.`tingkat`
 AND mk.`kode_rombel` = q.`rombel`
LEFT JOIN `seed_known_names` k ON k.`n` = q.`n`
JOIN `seed_first_names` f ON f.`id` = MOD(q.`n` - 1, 24) + 1
JOIN `seed_last_names` l ON l.`id` = FLOOR((q.`n` - 1) / 24) + 1;

ALTER TABLE `seed_students` ADD PRIMARY KEY (`n`);
CREATE INDEX `idx_seed_students_no_induk` ON `seed_students` (`no_induk`);

INSERT INTO `siswa` (
  `NO_INDUK`, `NO_induk_diknas`, `NAMA`, `KELAS`, `master_kelas_id`, `SPP_PERBULAN`,
  `PANGKAL`, `BANGUNAN`, `SERAGAM`, `KEGIATAN`, `MAKAN`, `SORGA`, `INFAQ`,
  `POMG`, `DAFTAR_ULANG`, `potong_pangkal`, `tot_pangkal`, `tot_du`, `potong_du`, `is_active`
)
SELECT
  `no_induk`, `nis_diknas`, `nama`, CAST(`tingkat` AS CHAR), `master_kelas_id`, `spp`,
  `pangkal`, `bangunan`, `seragam`, `kegiatan`, `makan`, `sorga`, `infaq`,
  `komite`, `pangkal`, `potong_pangkal`, GREATEST(`pangkal` - `potong_pangkal`, 0),
  GREATEST(`pangkal` - `potong_du`, 0), `potong_du`, 1
FROM `seed_students`;

INSERT INTO `siswa_tahun_ajaran` (
  `tahun_ajaran_id`, `no_induk`, `kelas`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `spp_perbulan_snapshot`, `komite_snapshot`, `status`
)
SELECT
  ta.`id`, s.`no_induk`, CAST(s.`tingkat` AS CHAR), s.`master_kelas_id`,
  CONCAT(s.`tingkat`, s.`rombel`), s.`spp`, s.`komite`, 'aktif'
FROM `seed_students` s
JOIN `tahun_ajaran` ta ON ta.`label` = '2026/2027';

INSERT INTO `tagihan_daftar_ulang` (
  `tahun_ajaran_id`, `penempatan_id`, `master_daftar_ulang_id`,
  `no_induk`, `kelas_snapshot`, `tahun_ajaran_snapshot`, `nominal_awal`, `nominal_tagihan`, `status`
)
SELECT
  ta.`id`, sta.`id`, du.`id`, s.`no_induk`, CAST(s.`tingkat` AS CHAR), ta.`label`,
  siswa.`DAFTAR_ULANG`, siswa.`tot_du`, 'open'
FROM `seed_students` s
JOIN `siswa` siswa ON siswa.`NO_INDUK` = s.`no_induk`
JOIN `tahun_ajaran` ta ON ta.`label` = '2026/2027'
JOIN `siswa_tahun_ajaran` sta ON sta.`tahun_ajaran_id` = ta.`id` AND sta.`no_induk` = s.`no_induk`
JOIN `Daftar_ulang` du ON du.`tahun_ajaran_id` = ta.`id` AND du.`kelas` = CAST(s.`tingkat` AS CHAR);

INSERT INTO `tagihan_tahunan_siswa` (
  `tahun_ajaran_id`, `penempatan_id`, `no_induk`, `komponen`, `nama_snapshot`,
  `kelas_snapshot`, `kelas_rombel_snapshot`, `tahun_ajaran_snapshot`,
  `nominal_awal`, `potongan`, `nominal_tagihan`, `status`, `created_by`
)
SELECT
  ta.`id`, sta.`id`, s.`no_induk`, fees.`komponen`, siswa.`NAMA`,
  CAST(s.`tingkat` AS CHAR), CONCAT(s.`tingkat`, s.`rombel`), ta.`label`,
  fees.`nominal_awal`, fees.`potongan`, fees.`nominal_tagihan`, 'open', 'seed'
FROM `seed_students` s
JOIN `siswa` siswa ON siswa.`NO_INDUK` = s.`no_induk`
JOIN `tahun_ajaran` ta ON ta.`label` = '2026/2027'
JOIN `siswa_tahun_ajaran` sta ON sta.`tahun_ajaran_id` = ta.`id` AND sta.`no_induk` = s.`no_induk`
JOIN (
  SELECT 'pangkal' AS `komponen`, sf.`NO_INDUK`, sf.`PANGKAL` AS `nominal_awal`, sf.`potong_pangkal` AS `potongan`, sf.`tot_pangkal` AS `nominal_tagihan` FROM `siswa` sf
  UNION ALL SELECT 'bangunan', sf.`NO_INDUK`, sf.`BANGUNAN`, 0, sf.`BANGUNAN` FROM `siswa` sf
  UNION ALL SELECT 'seragam', sf.`NO_INDUK`, sf.`SERAGAM`, 0, sf.`SERAGAM` FROM `siswa` sf
  UNION ALL SELECT 'kegiatan', sf.`NO_INDUK`, sf.`KEGIATAN`, 0, sf.`KEGIATAN` FROM `siswa` sf
  UNION ALL SELECT 'komite', sf.`NO_INDUK`, sf.`POMG`, 0, sf.`POMG` FROM `siswa` sf
  UNION ALL SELECT 'makan', sf.`NO_INDUK`, sf.`MAKAN`, 0, sf.`MAKAN` FROM `siswa` sf
  UNION ALL SELECT 'sorga', sf.`NO_INDUK`, sf.`SORGA`, 0, sf.`SORGA` FROM `siswa` sf
  UNION ALL SELECT 'infaq', sf.`NO_INDUK`, sf.`INFAQ`, 0, sf.`INFAQ` FROM `siswa` sf
) fees ON fees.`NO_INDUK` = s.`no_induk`;

INSERT INTO `tagihan_biaya_lain` (
  `master_biaya_lain_id`, `no_induk`, `master_kelas_id`, `nama_snapshot`,
  `nominal_tagihan`, `kelas_rombel_snapshot`, `status`, `created_by`
)
SELECT
  m.`id`, s.`no_induk`, s.`master_kelas_id`, m.`nama`, m.`nominal`,
  CONCAT(s.`tingkat`, s.`rombel`), 'open',
  (SELECT `id` FROM `admin` WHERE `username` = 'admin' LIMIT 1)
FROM `seed_students` s
JOIN `master_biaya_lain` m
WHERE
  m.`nama` IN ('Buku Paket', 'Kegiatan Tahunan')
  OR (m.`nama` = 'Seragam Olahraga' AND MOD(s.`n`, 2) = 0)
  OR (m.`nama` = 'Modul/Ujian' AND s.`tingkat` >= 4)
  OR (m.`nama` = 'Ekskul' AND MOD(s.`n`, 3) = 0);

-- Pembayaran SPP Juli.
INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_SPP`, `U_KOMITE`, `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  s.`no_induk`, CAST(s.`tingkat` AS CHAR), s.`master_kelas_id`, CONCAT(s.`tingkat`, s.`rombel`),
  CASE WHEN MOD(s.`n`, 6) = 5 THEN ROUND(s.`spp` * 0.5, 0) ELSE s.`spp` END,
  CASE WHEN MOD(s.`n`, 3) = 0 THEN s.`komite` ELSE 0 END,
  CONCAT('SEED:SPP:JUL:', s.`no_induk`),
  DATE_ADD('2026-07-01 08:00:00', INTERVAL MOD(s.`n` - 1, 31) DAY),
  '07', '2026', CONCAT('kasir', MOD(s.`n` - 1, 4) + 1),
  ELT(MOD(s.`n` - 1, 3) + 1, 'Tunai', 'VA', 'Qris'),
  '2026/2027',
  CASE WHEN MOD(s.`n`, 6) = 5 THEN ROUND(s.`spp` * 0.5, 0) ELSE s.`spp` END
    + CASE WHEN MOD(s.`n`, 3) = 0 THEN s.`komite` ELSE 0 END,
  1
FROM `seed_students` s
WHERE MOD(s.`n`, 6) <> 0;

INSERT INTO `bayar_spp_periode` (`bayar_id`, `no_induk`, `bulan`, `tahun`)
SELECT b.`id`, b.`NO_INDUK`, '07', '2026'
FROM `bayar` b
WHERE b.`KETERANGAN` LIKE 'SEED:SPP:JUL:%' AND b.`U_SPP` > 0;

-- Pembayaran SPP Agustus, termasuk cicilan kedua untuk sebagian siswa.
INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_SPP`, `U_KOMITE`, `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  s.`no_induk`, CAST(s.`tingkat` AS CHAR), s.`master_kelas_id`, CONCAT(s.`tingkat`, s.`rombel`),
  CASE MOD(s.`n`, 5)
    WHEN 1 THEN 100000
    WHEN 2 THEN s.`spp`
    WHEN 3 THEN FLOOR(s.`spp` / 2)
    ELSE s.`spp`
  END,
  CASE WHEN MOD(s.`n`, 4) = 0 THEN s.`komite` ELSE 0 END,
  CONCAT('SEED:SPP:AUG-A:', s.`no_induk`),
  DATE_ADD('2026-08-01 08:30:00', INTERVAL MOD(s.`n` - 1, 20) DAY),
  '08', '2026', CONCAT('kasir', MOD(s.`n`, 4) + 1),
  ELT(MOD(s.`n`, 3) + 1, 'Tunai', 'VA', 'Qris'),
  '2026/2027',
  CASE MOD(s.`n`, 5)
    WHEN 1 THEN 100000
    WHEN 2 THEN s.`spp`
    WHEN 3 THEN FLOOR(s.`spp` / 2)
    ELSE s.`spp`
  END + CASE WHEN MOD(s.`n`, 4) = 0 THEN s.`komite` ELSE 0 END,
  1
FROM `seed_students` s
WHERE MOD(s.`n`, 5) <> 0;

INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_SPP`, `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  s.`no_induk`, CAST(s.`tingkat` AS CHAR), s.`master_kelas_id`, CONCAT(s.`tingkat`, s.`rombel`),
  s.`spp` - FLOOR(s.`spp` / 2),
  CONCAT('SEED:SPP:AUG-B:', s.`no_induk`),
  DATE_ADD('2026-08-01 13:30:00', INTERVAL MOD(s.`n` + 7, 20) DAY),
  '08', '2026', CONCAT('kasir', MOD(s.`n` + 1, 4) + 1),
  ELT(MOD(s.`n` + 1, 3) + 1, 'Tunai', 'VA', 'Qris'),
  '2026/2027',
  s.`spp` - FLOOR(s.`spp` / 2),
  1
FROM `seed_students` s
WHERE MOD(s.`n`, 5) = 3;

INSERT INTO `bayar_spp_periode` (`bayar_id`, `no_induk`, `bulan`, `tahun`)
SELECT b.`id`, b.`NO_INDUK`, '08', '2026'
FROM `bayar` b
WHERE b.`KETERANGAN` LIKE 'SEED:SPP:AUG-%' AND b.`U_SPP` > 0;

-- Pembayaran biaya awal legacy.
INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_PANGKAL`, `U_BANGUNAN`, `U_SERAGAM`, `U_KEGIATAN`,
  `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  s.`no_induk`, CAST(s.`tingkat` AS CHAR), s.`master_kelas_id`, CONCAT(s.`tingkat`, s.`rombel`),
  CASE WHEN MOD(s.`n`, 4) IN (0,1) THEN ROUND(GREATEST(s.`pangkal` - s.`potong_pangkal`, 0) * 0.5, 0) ELSE 0 END,
  CASE WHEN MOD(s.`n`, 4) = 0 THEN ROUND(s.`bangunan` * 0.4, 0) ELSE 0 END,
  CASE WHEN MOD(s.`n`, 5) = 0 THEN ROUND(s.`seragam` * 0.5, 0) ELSE 0 END,
  CASE WHEN MOD(s.`n`, 6) = 0 THEN ROUND(s.`kegiatan` * 0.5, 0) ELSE 0 END,
  CONCAT('SEED:AWAL:', s.`no_induk`),
  DATE_ADD('2026-07-01 10:15:00', INTERVAL MOD(s.`n` + 3, 31) DAY),
  '07', '2026', CONCAT('kasir', MOD(s.`n` + 2, 4) + 1),
  ELT(MOD(s.`n` + 2, 3) + 1, 'Tunai', 'VA', 'Qris'),
  '2026/2027',
  CASE WHEN MOD(s.`n`, 4) IN (0,1) THEN ROUND(GREATEST(s.`pangkal` - s.`potong_pangkal`, 0) * 0.5, 0) ELSE 0 END
    + CASE WHEN MOD(s.`n`, 4) = 0 THEN ROUND(s.`bangunan` * 0.4, 0) ELSE 0 END
    + CASE WHEN MOD(s.`n`, 5) = 0 THEN ROUND(s.`seragam` * 0.5, 0) ELSE 0 END
    + CASE WHEN MOD(s.`n`, 6) = 0 THEN ROUND(s.`kegiatan` * 0.5, 0) ELSE 0 END,
  1
FROM `seed_students` s
WHERE MOD(s.`n`, 4) IN (0,1) OR MOD(s.`n`, 5) = 0 OR MOD(s.`n`, 6) = 0;

-- Pembayaran Daftar Ulang.
INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `th_ajaran`, `kelas_du`, `total_jumlah`, `payment_link_version`
)
SELECT
  s.`no_induk`, CAST(s.`tingkat` AS CHAR), s.`master_kelas_id`, CONCAT(s.`tingkat`, s.`rombel`),
  CONCAT('SEED:DU:', s.`no_induk`),
  DATE_ADD('2026-07-01 11:00:00', INTERVAL MOD(s.`n` + 9, 51) DAY),
  CASE WHEN MOD(s.`n` + 9, 51) < 31 THEN '07' ELSE '08' END,
  '2026', CONCAT('kasir', MOD(s.`n` + 3, 4) + 1),
  ELT(MOD(s.`n` + 1, 3) + 1, 'Tunai', 'VA', 'Qris'),
  '2026/2027', CAST(s.`tingkat` AS CHAR),
  CASE WHEN MOD(s.`n`, 4) = 0 THEN td.`nominal_tagihan` ELSE ROUND(td.`nominal_tagihan` * 0.5, 0) END,
  1
FROM `seed_students` s
JOIN `tagihan_daftar_ulang` td ON td.`no_induk` = s.`no_induk`
WHERE MOD(s.`n`, 4) IN (0,1);

INSERT INTO `bayar_du` (`bayar_id`, `tagihan_daftar_ulang_id`, `no_induk`, `kelas`, `th_ajaran`, `jumlah`)
SELECT b.`id`, td.`id`, b.`NO_INDUK`, b.`kelas_du`, '2026/2027', b.`total_jumlah`
FROM `bayar` b
JOIN `tagihan_daftar_ulang` td ON td.`no_induk` = b.`NO_INDUK`
WHERE b.`KETERANGAN` LIKE 'SEED:DU:%';

-- Pembayaran Biaya Lain.
INSERT INTO `bayar` (
  `NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `U_LAIN`, `KETERANGAN`, `TGL_BYR`, `BULAN`, `TAHUN`, `user_id`, `sistem_pembayaran`,
  `LAIN_LAIN1`, `JUMLAH1`, `th_ajaran`, `total_jumlah`, `payment_link_version`
)
SELECT
  s.`no_induk`, CAST(s.`tingkat` AS CHAR), s.`master_kelas_id`, CONCAT(s.`tingkat`, s.`rombel`),
  CASE WHEN MOD(s.`n` + m.`id`, 4) = 0 THEN ROUND(t.`nominal_tagihan` * 0.5, 0) ELSE t.`nominal_tagihan` END,
  CONCAT('SEED:LAIN:', s.`no_induk`, ':', m.`id`),
  DATE_ADD('2026-07-01 12:20:00', INTERVAL MOD(s.`n` + m.`id`, 51) DAY),
  CASE WHEN MOD(s.`n` + m.`id`, 51) < 31 THEN '07' ELSE '08' END,
  '2026', CONCAT('kasir', MOD(s.`n` + m.`id`, 4) + 1),
  ELT(MOD(s.`n` + m.`id`, 3) + 1, 'Tunai', 'VA', 'Qris'),
  m.`nama`,
  CASE WHEN MOD(s.`n` + m.`id`, 4) = 0 THEN ROUND(t.`nominal_tagihan` * 0.5, 0) ELSE t.`nominal_tagihan` END,
  '2026/2027',
  CASE WHEN MOD(s.`n` + m.`id`, 4) = 0 THEN ROUND(t.`nominal_tagihan` * 0.5, 0) ELSE t.`nominal_tagihan` END,
  1
FROM `tagihan_biaya_lain` t
JOIN `seed_students` s ON s.`no_induk` = t.`no_induk`
JOIN `master_biaya_lain` m ON m.`id` = t.`master_biaya_lain_id`
WHERE MOD(s.`n` + m.`id`, 3) <> 0;

INSERT INTO `bayar_biaya_lain` (
  `bayar_id`, `master_biaya_lain_id`, `tagihan_biaya_lain_id`,
  `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`
)
SELECT
  b.`id`, m.`id`, t.`id`, m.`nama`, b.`U_LAIN`, NULL, 1, 'LL1'
FROM `bayar` b
JOIN `master_biaya_lain` m ON b.`LAIN_LAIN1` = m.`nama`
JOIN `tagihan_biaya_lain` t ON t.`no_induk` = b.`NO_INDUK` AND t.`master_biaya_lain_id` = m.`id`
WHERE b.`KETERANGAN` LIKE 'SEED:LAIN:%';

-- Mutasi tabungan manual agar riwayat tabungan dan rekap tabungan berisi.
INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  NULL, s.`no_induk`,
  DATE_ADD('2026-07-01 09:10:00', INTERVAL MOD(s.`n`, 51) DAY),
  20000 + (MOD(s.`n`, 5) * 10000), 0,
  CONCAT('kasir', MOD(s.`n` - 1, 4) + 1)
FROM `seed_students` s
WHERE MOD(s.`n`, 4) <> 0;

INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  NULL, s.`no_induk`,
  DATE_ADD('2026-08-01 09:40:00', INTERVAL MOD(s.`n`, 20) DAY),
  15000 + (MOD(s.`n`, 4) * 5000), 0,
  CONCAT('kasir', MOD(s.`n`, 4) + 1)
FROM `seed_students` s
WHERE MOD(s.`n`, 3) = 0;

INSERT INTO `transaksi_k` (`NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  s.`no_induk`,
  DATE_ADD('2026-08-01 14:25:00', INTERVAL MOD(s.`n` + 4, 20) DAY),
  0, 10000 + (MOD(s.`n`, 3) * 5000),
  CONCAT('kasir', MOD(s.`n` + 2, 4) + 1)
FROM `seed_students` s
WHERE MOD(s.`n`, 8) = 0;

-- Histori tabungan masuk tambahan: setoran berkala untuk semua siswa.
INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  NULL,
  s.`no_induk`,
  DATE_ADD(e.`tanggal`, INTERVAL MOD(s.`n` + e.`event_no`, 4) HOUR),
  10000 + (MOD(s.`n` + e.`event_no`, 5) * 5000),
  0,
  CONCAT('kasir', MOD(s.`n` + e.`event_no`, 4) + 1)
FROM `seed_students` s
JOIN (
  SELECT 1 AS `event_no`, TIMESTAMP('2026-07-02', '07:45:00') AS `tanggal` UNION ALL
  SELECT 2, TIMESTAMP('2026-07-06', '08:10:00') UNION ALL
  SELECT 3, TIMESTAMP('2026-07-11', '08:25:00') UNION ALL
  SELECT 4, TIMESTAMP('2026-07-16', '09:05:00') UNION ALL
  SELECT 5, TIMESTAMP('2026-07-22', '09:20:00') UNION ALL
  SELECT 6, TIMESTAMP('2026-07-29', '08:35:00') UNION ALL
  SELECT 7, TIMESTAMP('2026-08-04', '08:50:00') UNION ALL
  SELECT 8, TIMESTAMP('2026-08-11', '09:15:00') UNION ALL
  SELECT 9, TIMESTAMP('2026-08-18', '09:30:00')
) e;

-- Histori tabungan keluar tambahan: penarikan berkala agar laporan mutasi keluar ramai.
INSERT INTO `transaksi_k` (`NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  s.`no_induk`,
  DATE_ADD(e.`tanggal`, INTERVAL MOD(s.`n` + e.`event_no`, 5) HOUR),
  0,
  5000 + (MOD(s.`n` + e.`event_no`, 5) * 3000),
  CONCAT('kasir', MOD(s.`n` + e.`event_no` + 1, 4) + 1)
FROM `seed_students` s
JOIN (
  SELECT 1 AS `event_no`, TIMESTAMP('2026-07-14', '10:40:00') AS `tanggal` UNION ALL
  SELECT 2, TIMESTAMP('2026-08-06', '11:05:00') UNION ALL
  SELECT 3, TIMESTAMP('2026-08-19', '10:20:00')
) e
WHERE MOD(s.`n`, 6) <> 0 OR e.`event_no` = 2;

-- Koreksi otomatis jika pola demo menghasilkan saldo minus.
-- Histori keluar tetap dipertahankan, saldo ditutup lewat mutasi masuk koreksi.
INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT
  NULL,
  s.`no_induk`,
  '2026-07-01 07:05:00',
  ABS(x.`saldo`),
  0,
  'SEED_KOREKSI'
FROM `seed_students` s
JOIN (
  SELECT
    z.`NO_INDUK`,
    COALESCE(SUM(z.`masuk`), 0) - COALESCE(SUM(z.`keluar`), 0) AS `saldo`
  FROM (
    SELECT `NO_INDUK`, `MASUK` AS `masuk`, 0 AS `keluar` FROM `transaksi_m`
    UNION ALL
    SELECT `NO_INDUK`, 0, `KELUAR` FROM `transaksi_k`
  ) z
  GROUP BY z.`NO_INDUK`
) x ON x.`NO_INDUK` = s.`no_induk`
WHERE x.`saldo` < 0;

INSERT INTO `tabungan` (`NO_INDUK`, `SALDO`)
SELECT s.`no_induk`, COALESCE(m.`masuk`, 0) - COALESCE(k.`keluar`, 0)
FROM `seed_students` s
LEFT JOIN (
  SELECT `NO_INDUK`, SUM(`MASUK`) AS `masuk`
  FROM `transaksi_m`
  GROUP BY `NO_INDUK`
) m ON m.`NO_INDUK` = s.`no_induk`
LEFT JOIN (
  SELECT `NO_INDUK`, SUM(`KELUAR`) AS `keluar`
  FROM `transaksi_k`
  GROUP BY `NO_INDUK`
) k ON k.`NO_INDUK` = s.`no_induk`;

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
  AND t.`tahun_ajaran_snapshot` = '2026/2027';

UPDATE `siswa` s
LEFT JOIN (
  SELECT
    `no_induk`,
    COALESCE(SUM(CASE WHEN `komponen` = 'pangkal' THEN `jumlah` ELSE 0 END), 0) AS `pangkal_bayar`,
    COALESCE(SUM(CASE WHEN `komponen` = 'bangunan' THEN `jumlah` ELSE 0 END), 0) AS `bangunan_bayar`,
    COALESCE(SUM(CASE WHEN `komponen` = 'seragam' THEN `jumlah` ELSE 0 END), 0) AS `seragam_bayar`,
    COALESCE(SUM(CASE WHEN `komponen` = 'kegiatan' THEN `jumlah` ELSE 0 END), 0) AS `kegiatan_bayar`
  FROM `bayar_tahunan_siswa`
  WHERE `th_ajaran` = '2026/2027'
  GROUP BY `no_induk`
) p ON p.`no_induk` = s.`NO_INDUK`
SET
  s.`PANGKAL_BAYAR` = COALESCE(p.`pangkal_bayar`, 0),
  s.`BANGUNAN_BAYAR` = COALESCE(p.`bangunan_bayar`, 0),
  s.`SERAGAM_BAYAR` = COALESCE(p.`seragam_bayar`, 0),
  s.`KEGIATAN_BAYAR` = COALESCE(p.`kegiatan_bayar`, 0);

COMMIT;

DROP TEMPORARY TABLE IF EXISTS `seed_students`;
DROP TEMPORARY TABLE IF EXISTS `seed_known_names`;
DROP TEMPORARY TABLE IF EXISTS `seed_first_names`;
DROP TEMPORARY TABLE IF EXISTS `seed_last_names`;
DROP TEMPORARY TABLE IF EXISTS `seed_seq`;
