-- =========================================================
-- BASELINE DEMO STANDAR SISTEMSPP — TA 2026/2027
-- =========================================================
-- Jalankan setelah reset_demo_students_and_finance.sql pada database demo.
-- Tidak memakai USE dan tidak membuat transaksi, saldo tabungan, atau titipan.
-- Aman dijalankan ulang SELAMA belum ada transaksi baru pada data demo ini.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @seed_tahun_ajaran := '2026/2027';
SET @seed_tahun_mulai := '2026-07-01';
SET @seed_tahun_selesai := '2027-06-30';

START TRANSACTION;

-- Pastikan rombel baseline dan kelas PSB tersedia tanpa mengubah rombel lain.
INSERT INTO `master_kelas` (`tingkat`, `kode_rombel`, `is_placeholder`, `is_active`)
VALUES (0, 'PSB', 0, 1)
ON DUPLICATE KEY UPDATE `is_placeholder` = 0, `is_active` = 1;

INSERT IGNORE INTO `master_kelas` (`tingkat`, `kode_rombel`, `is_placeholder`, `is_active`)
SELECT tingkat, kode_rombel, 0, 1
FROM (
  SELECT 1 AS tingkat UNION ALL SELECT 2 UNION ALL SELECT 3
  UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) tingkat_data
CROSS JOIN (
  SELECT 'A' AS kode_rombel UNION ALL SELECT 'B' UNION ALL SELECT 'C' UNION ALL SELECT 'D'
) rombel_data;

-- Tahun ajaran dan tarif baseline.
INSERT INTO `tahun_ajaran` (`label`, `tanggal_mulai`, `tanggal_selesai`, `status`, `published_at`, `closed_at`)
VALUES (@seed_tahun_ajaran, @seed_tahun_mulai, @seed_tahun_selesai, 'published', NOW(), NULL)
ON DUPLICATE KEY UPDATE
  `tanggal_mulai` = VALUES(`tanggal_mulai`),
  `tanggal_selesai` = VALUES(`tanggal_selesai`),
  `status` = 'published',
  `published_at` = COALESCE(`published_at`, NOW()),
  `closed_at` = NULL;
SET @seed_tahun_ajaran_id := (SELECT `id` FROM `tahun_ajaran` WHERE `label` = '2026/2027' LIMIT 1);

INSERT INTO `master_spp_tahun` (`tahun_ajaran_id`, `status`, `published_at`, `closed_at`)
VALUES (@seed_tahun_ajaran_id, 'published', NOW(), NULL)
ON DUPLICATE KEY UPDATE
  `status` = 'published',
  `published_at` = COALESCE(`published_at`, NOW()),
  `closed_at` = NULL;
SET @seed_master_spp_id := (SELECT `id` FROM `master_spp_tahun` WHERE `tahun_ajaran_id` = @seed_tahun_ajaran_id LIMIT 1);

INSERT INTO `master_spp_tarif` (`master_spp_tahun_id`, `tingkat`, `nominal_dasar`) VALUES
(@seed_master_spp_id, 1, 250000),
(@seed_master_spp_id, 2, 260000),
(@seed_master_spp_id, 3, 275000),
(@seed_master_spp_id, 4, 290000),
(@seed_master_spp_id, 5, 305000),
(@seed_master_spp_id, 6, 320000)
ON DUPLICATE KEY UPDATE `nominal_dasar` = VALUES(`nominal_dasar`);

INSERT INTO `Daftar_ulang` (`tahun_ajaran_id`, `th_ajaran`, `kelas`, `Jumlah`) VALUES
(@seed_tahun_ajaran_id, @seed_tahun_ajaran, '1', 1000000),
(@seed_tahun_ajaran_id, @seed_tahun_ajaran, '2', 1100000),
(@seed_tahun_ajaran_id, @seed_tahun_ajaran, '3', 1200000),
(@seed_tahun_ajaran_id, @seed_tahun_ajaran, '4', 1300000),
(@seed_tahun_ajaran_id, @seed_tahun_ajaran, '5', 1400000),
(@seed_tahun_ajaran_id, @seed_tahun_ajaran, '6', 1500000)
ON DUPLICATE KEY UPDATE
  `tahun_ajaran_id` = VALUES(`tahun_ajaran_id`),
  `Jumlah` = VALUES(`Jumlah`);

DROP TEMPORARY TABLE IF EXISTS `seed_regular_students`;
CREATE TEMPORARY TABLE `seed_regular_students` AS
SELECT
  n,
  CONCAT('2027', LPAD(n, 4, '0')) AS no_induk,
  CONCAT('D27', LPAD(n, 7, '0')) AS nis_diknas,
  CONCAT('Siswa Demo ', LPAD(n, 3, '0')) AS nama,
  FLOOR((n - 1) / 24) + 1 AS tingkat,
  ELT(MOD(FLOOR((n - 1) / 6), 4) + 1, 'A', 'B', 'C', 'D') AS rombel
FROM (
  SELECT h.i * 100 + t.i * 10 + o.i + 1 AS n
  FROM (SELECT 0 AS i UNION ALL SELECT 1) h
  CROSS JOIN (SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
  CROSS JOIN (SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) o
) sequence_data
WHERE n BETWEEN 1 AND 144;
ALTER TABLE `seed_regular_students` ADD PRIMARY KEY (`no_induk`);

INSERT INTO `siswa` (
  `NO_INDUK`, `NO_induk_diknas`, `NAMA`, `KELAS`, `master_kelas_id`,
  `SPP_PERBULAN`, `potongan_spp_persen`, `PANGKAL`, `PSB`, `asal_psb`,
  `POMG`, `DAFTAR_ULANG`, `potong_pangkal`, `tot_pangkal`, `potong_du`, `tot_du`, `is_active`
)
SELECT
  r.no_induk, r.nis_diknas, r.nama, CAST(r.tingkat AS CHAR), mk.id,
  rates.spp, 0,
  CASE WHEN r.tingkat = 1 THEN 1000000 ELSE 0 END,
  0, 0,
  rates.komite, rates.daftar_ulang,
  0, CASE WHEN r.tingkat = 1 THEN 1000000 ELSE 0 END,
  0, rates.daftar_ulang, 1
FROM `seed_regular_students` r
JOIN `master_kelas` mk ON mk.tingkat = r.tingkat AND BINARY mk.kode_rombel = BINARY r.rombel
JOIN (
  SELECT 1 AS tingkat, 250000 AS spp, 100000 AS komite, 1000000 AS daftar_ulang UNION ALL
  SELECT 2, 260000, 110000, 1100000 UNION ALL
  SELECT 3, 275000, 120000, 1200000 UNION ALL
  SELECT 4, 290000, 130000, 1300000 UNION ALL
  SELECT 5, 305000, 140000, 1400000 UNION ALL
  SELECT 6, 320000, 150000, 1500000
) rates ON rates.tingkat = r.tingkat
ON DUPLICATE KEY UPDATE
  `NO_induk_diknas` = VALUES(`NO_induk_diknas`), `NAMA` = VALUES(`NAMA`), `KELAS` = VALUES(`KELAS`),
  `master_kelas_id` = VALUES(`master_kelas_id`), `SPP_PERBULAN` = VALUES(`SPP_PERBULAN`),
  `potongan_spp_persen` = 0, `PANGKAL` = VALUES(`PANGKAL`), `PSB` = 0, `asal_psb` = 0,
  `POMG` = VALUES(`POMG`), `DAFTAR_ULANG` = VALUES(`DAFTAR_ULANG`),
  `potong_pangkal` = 0, `tot_pangkal` = VALUES(`tot_pangkal`), `potong_du` = 0,
  `tot_du` = VALUES(`tot_du`), `is_active` = 1;

-- Enam calon siswa PSB: Pangkal dan PSB dapat dicicil, tanpa tagihan bulanan.
INSERT INTO `siswa` (
  `NO_INDUK`, `NO_induk_diknas`, `NAMA`, `KELAS`, `master_kelas_id`,
  `SPP_PERBULAN`, `PANGKAL`, `PSB`, `asal_psb`, `POMG`, `DAFTAR_ULANG`,
  `potong_pangkal`, `tot_pangkal`, `tot_du`, `is_active`
)
SELECT p.no_induk, p.nis_diknas, p.nama, 'PSB', mk.id,
       0, 1000000, 3600000, 1, 0, 0, 0, 1000000, 0, 1
FROM (
  SELECT 'PSB0001' AS no_induk, 'D26PSB0001' AS nis_diknas, 'Calon Siswa PSB 01' AS nama
  UNION ALL SELECT 'PSB0002', 'D26PSB0002', 'Calon Siswa PSB 02'
  UNION ALL SELECT 'PSB0003', 'D26PSB0003', 'Calon Siswa PSB 03'
  UNION ALL SELECT 'PSB0004', 'D26PSB0004', 'Calon Siswa PSB 04'
  UNION ALL SELECT 'PSB0005', 'D26PSB0005', 'Calon Siswa PSB 05'
  UNION ALL SELECT 'PSB0006', 'D26PSB0006', 'Calon Siswa PSB 06'
) p
JOIN `master_kelas` mk ON mk.tingkat = 0 AND mk.kode_rombel = 'PSB'
ON DUPLICATE KEY UPDATE
  `NO_induk_diknas` = VALUES(`NO_induk_diknas`), `NAMA` = VALUES(`NAMA`), `KELAS` = 'PSB',
  `master_kelas_id` = VALUES(`master_kelas_id`), `SPP_PERBULAN` = 0, `PANGKAL` = 1000000,
  `PSB` = 3600000, `asal_psb` = 1, `POMG` = 0, `DAFTAR_ULANG` = 0,
  `potong_pangkal` = 0, `tot_pangkal` = 1000000, `potong_du` = 0, `tot_du` = 0, `is_active` = 1;

-- Satu penempatan aktif untuk setiap siswa reguler; PSB tidak masuk timeline reguler.
INSERT INTO `siswa_tahun_ajaran` (
  `tahun_ajaran_id`, `no_induk`, `kelas`, `master_kelas_id`, `kelas_rombel_snapshot`,
  `spp_perbulan_snapshot`, `spp_covered_by_psb`, `komite_snapshot`, `komite_mulai_bulan`, `status`
)
SELECT @seed_tahun_ajaran_id, s.NO_INDUK, s.KELAS, s.master_kelas_id,
       CONCAT(mk.tingkat, UPPER(mk.kode_rombel)), s.SPP_PERBULAN, 0, s.POMG, '07', 'aktif'
FROM `siswa` s
JOIN `master_kelas` mk ON mk.id = s.master_kelas_id
WHERE s.NO_INDUK LIKE '2027%' AND s.KELAS IN ('1','2','3','4','5','6')
ON DUPLICATE KEY UPDATE
  `kelas` = VALUES(`kelas`), `master_kelas_id` = VALUES(`master_kelas_id`),
  `kelas_rombel_snapshot` = VALUES(`kelas_rombel_snapshot`), `spp_perbulan_snapshot` = VALUES(`spp_perbulan_snapshot`),
  `spp_covered_by_psb` = 0, `komite_snapshot` = VALUES(`komite_snapshot`),
  `komite_mulai_bulan` = '07', `status` = 'aktif';

DROP TEMPORARY TABLE IF EXISTS `seed_periods`;
CREATE TEMPORARY TABLE `seed_periods` AS
SELECT '07' AS bulan, '2026' AS tahun UNION ALL SELECT '08', '2026' UNION ALL SELECT '09', '2026'
UNION ALL SELECT '10', '2026' UNION ALL SELECT '11', '2026' UNION ALL SELECT '12', '2026'
UNION ALL SELECT '01', '2027' UNION ALL SELECT '02', '2027' UNION ALL SELECT '03', '2027'
UNION ALL SELECT '04', '2027' UNION ALL SELECT '05', '2027' UNION ALL SELECT '06', '2027';
ALTER TABLE `seed_periods` ADD PRIMARY KEY (`tahun`, `bulan`);

-- Terbitkan 12 SPP dan 12 Komite untuk tiap penempatan reguler.
INSERT IGNORE INTO `tagihan_spp` (
  `master_spp_tahun_id`, `tahun_ajaran_id`, `penempatan_id`, `no_induk`, `tingkat_snapshot`,
  `master_kelas_id`, `kelas_rombel_snapshot`, `bulan`, `tahun`, `tarif_dasar_snapshot`,
  `potongan_persen_snapshot`, `potongan_nominal_snapshot`, `nominal_tagihan`, `status`
)
SELECT @seed_master_spp_id, @seed_tahun_ajaran_id, sta.id, sta.no_induk, CAST(sta.kelas AS UNSIGNED),
       sta.master_kelas_id, sta.kelas_rombel_snapshot, p.bulan, p.tahun, rate.nominal_dasar,
       0, 0, rate.nominal_dasar, 'open'
FROM `siswa_tahun_ajaran` sta
JOIN `master_spp_tarif` rate ON rate.master_spp_tahun_id = @seed_master_spp_id AND rate.tingkat = CAST(sta.kelas AS UNSIGNED)
CROSS JOIN `seed_periods` p
WHERE sta.tahun_ajaran_id = @seed_tahun_ajaran_id AND sta.status = 'aktif' AND sta.kelas IN ('1','2','3','4','5','6');

INSERT IGNORE INTO `tagihan_komite` (
  `tahun_ajaran_id`, `penempatan_id`, `no_induk`, `kelas_rombel_snapshot`, `bulan`, `tahun`, `nominal_tagihan`
)
SELECT @seed_tahun_ajaran_id, sta.id, sta.no_induk, sta.kelas_rombel_snapshot, p.bulan, p.tahun, sta.komite_snapshot
FROM `siswa_tahun_ajaran` sta
CROSS JOIN `seed_periods` p
WHERE sta.tahun_ajaran_id = @seed_tahun_ajaran_id AND sta.status = 'aktif' AND sta.kelas IN ('1','2','3','4','5','6');

INSERT IGNORE INTO `tagihan_daftar_ulang` (
  `tahun_ajaran_id`, `penempatan_id`, `master_daftar_ulang_id`, `no_induk`, `kelas_snapshot`,
  `tahun_ajaran_snapshot`, `nominal_awal`, `nominal_tagihan`
)
SELECT @seed_tahun_ajaran_id, sta.id, du.id, sta.no_induk, sta.kelas,
       @seed_tahun_ajaran, du.Jumlah, du.Jumlah
FROM `siswa_tahun_ajaran` sta
JOIN `Daftar_ulang` du ON du.tahun_ajaran_id = @seed_tahun_ajaran_id AND du.kelas = sta.kelas
WHERE sta.tahun_ajaran_id = @seed_tahun_ajaran_id AND sta.status = 'aktif' AND sta.kelas IN ('1','2','3','4','5','6');

COMMIT;

-- Pemeriksaan baseline. Angka utama harus: 150, 144, 6, 30, 1728, 1728, 144, lalu nol.
SELECT 'siswa_aktif' AS pemeriksaan, COUNT(*) AS jumlah FROM `siswa` WHERE `is_active` = 1
UNION ALL SELECT 'siswa_reguler', COUNT(*) FROM `siswa` WHERE `is_active` = 1 AND `KELAS` IN ('1','2','3','4','5','6')
UNION ALL SELECT 'siswa_psb', COUNT(*) FROM `siswa` WHERE `is_active` = 1 AND `KELAS` = 'PSB'
UNION ALL SELECT 'siswa_dengan_sisa_pangkal', COUNT(*) FROM `siswa` WHERE `is_active` = 1 AND `PANGKAL` - `potong_pangkal` > 0
UNION ALL SELECT 'tagihan_spp', COUNT(*) FROM `tagihan_spp` WHERE `tahun_ajaran_id` = @seed_tahun_ajaran_id
UNION ALL SELECT 'tagihan_komite', COUNT(*) FROM `tagihan_komite` WHERE `tahun_ajaran_id` = @seed_tahun_ajaran_id
UNION ALL SELECT 'tagihan_daftar_ulang', COUNT(*) FROM `tagihan_daftar_ulang` WHERE `tahun_ajaran_id` = @seed_tahun_ajaran_id
UNION ALL SELECT 'pembayaran', COUNT(*) FROM `bayar`
UNION ALL SELECT 'mutasi_tabungan', (SELECT COUNT(*) FROM `transaksi_m`) + (SELECT COUNT(*) FROM `transaksi_k`)
UNION ALL SELECT 'titipan_spp', COUNT(*) FROM `titipan_spp_mutasi`;

DROP TEMPORARY TABLE IF EXISTS `seed_periods`;
DROP TEMPORARY TABLE IF EXISTS `seed_regular_students`;
