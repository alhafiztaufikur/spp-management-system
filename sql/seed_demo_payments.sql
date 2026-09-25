-- =========================================================
-- SEEDER 1.000 PEMBAYARAN DEMO SISTEMSPP — TA 2026/2027
-- =========================================================
-- Jalankan HANYA sesudah reset_demo_students_and_finance.sql dan
-- seed_students_psb.sql pada database demo. Tidak memakai USE.
--
-- Sebelum menjalankan, ganti token berikut dengan SEED_PAYMENT_DEMO_2026.
-- Seeder menolak bekerja jika baseline belum lengkap atau sudah ada
-- pembayaran, sehingga transaksi operasional tidak tercampur data demo.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @seed_demo_payment_confirmation := '';

DROP TEMPORARY TABLE IF EXISTS `seed_demo_payment_guard`;
CREATE TEMPORARY TABLE `seed_demo_payment_guard` AS
SELECT 1 AS `allowed`
WHERE @seed_demo_payment_confirmation = 'SEED_PAYMENT_DEMO_2026'
  AND (SELECT COUNT(*) FROM `siswa` WHERE `is_active` = 1) = 150
  AND (SELECT COUNT(*) FROM `siswa` WHERE `is_active` = 1 AND `KELAS` IN ('1','2','3','4','5','6')) = 144
  AND (SELECT COUNT(*) FROM `siswa` WHERE `is_active` = 1 AND `KELAS` = 'PSB') = 6
  AND (SELECT COUNT(*) FROM `tagihan_spp`) = 1728
  AND (SELECT COUNT(*) FROM `tagihan_komite`) = 1728
  AND (SELECT COUNT(*) FROM `tagihan_daftar_ulang`) = 144
  AND (SELECT COUNT(*) FROM `bayar`) = 0;

SELECT CASE
  WHEN @seed_demo_payment_confirmation <> 'SEED_PAYMENT_DEMO_2026'
    THEN 'SEED DIBATALKAN: isi token SEED_PAYMENT_DEMO_2026 terlebih dahulu.'
  WHEN (SELECT COUNT(*) FROM `bayar`) <> 0
    THEN 'SEED DIBATALKAN: pembayaran sudah ada; gunakan reset demo terlebih dahulu.'
  WHEN NOT EXISTS (SELECT 1 FROM `seed_demo_payment_guard`)
    THEN 'SEED DIBATALKAN: baseline 150 siswa atau tagihan TA 2026/2027 belum lengkap.'
  ELSE 'KONFIRMASI DITERIMA: 1.000 pembayaran demo akan dibuat.'
END AS `status_seed`;

START TRANSACTION;

SET @seed_demo_actor_id := COALESCE((SELECT `id` FROM `admin` WHERE `role` = 'admin' ORDER BY `id` LIMIT 1), 0);
SET @seed_demo_operator_admin := COALESCE((SELECT `username` FROM `admin` WHERE `role` = 'admin' ORDER BY `id` LIMIT 1), 'admin');
SET @seed_demo_operator_kasir := COALESCE((SELECT `username` FROM `admin` WHERE `role` = 'kasir' ORDER BY `id` LIMIT 1), @seed_demo_operator_admin);

-- Tiga master khusus demo agar rincian Biaya Lain terlihat pada laporan.
INSERT INTO `master_biaya_lain` (`nama`, `nominal`, `is_active`)
SELECT 'DEMO Buku Paket 2026', 180000, 1 FROM `seed_demo_payment_guard`
ON DUPLICATE KEY UPDATE `nominal` = VALUES(`nominal`), `is_active` = 1
;
INSERT INTO `master_biaya_lain` (`nama`, `nominal`, `is_active`)
SELECT 'DEMO Kegiatan Semester', 250000, 1 FROM `seed_demo_payment_guard`
ON DUPLICATE KEY UPDATE `nominal` = VALUES(`nominal`), `is_active` = 1
;
INSERT INTO `master_biaya_lain` (`nama`, `nominal`, `is_active`)
SELECT 'DEMO Seragam Olahraga', 220000, 1 FROM `seed_demo_payment_guard`
ON DUPLICATE KEY UPDATE `nominal` = VALUES(`nominal`), `is_active` = 1
;

DROP TEMPORARY TABLE IF EXISTS `seed_demo_regular_rank`;
SET @seed_demo_rank := 0;
CREATE TEMPORARY TABLE `seed_demo_regular_rank` AS
SELECT source.*, (@seed_demo_rank := @seed_demo_rank + 1) AS `rank_no`
FROM (
  SELECT s.`NO_INDUK`, s.`KELAS`, s.`master_kelas_id`,
         CONCAT(mk.`tingkat`, UPPER(mk.`kode_rombel`)) AS `kelas_rombel_snapshot`,
         s.`PANGKAL`, s.`potong_pangkal`
  FROM `siswa` s
  JOIN `master_kelas` mk ON mk.`id` = s.`master_kelas_id`
  JOIN `seed_demo_payment_guard` g
  WHERE s.`is_active` = 1 AND s.`KELAS` IN ('1','2','3','4','5','6')
  ORDER BY s.`NO_INDUK`
) source;
ALTER TABLE `seed_demo_regular_rank` ADD PRIMARY KEY (`NO_INDUK`), ADD KEY `idx_seed_demo_rank` (`rank_no`);

DROP TEMPORARY TABLE IF EXISTS `seed_demo_payment_plan`;
CREATE TEMPORARY TABLE `seed_demo_payment_plan` (
  `seed_key` VARCHAR(80) NOT NULL,
  `kategori` ENUM('spp','psb','titipan','lain') NOT NULL,
  `NO_INDUK` VARCHAR(10) NOT NULL,
  `KELAS` VARCHAR(5) NOT NULL,
  `master_kelas_id` INT DEFAULT NULL,
  `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `tanggal` DATETIME NOT NULL,
  `bulan` CHAR(2) NOT NULL,
  `tahun` CHAR(4) NOT NULL,
  `user_id` VARCHAR(100) NOT NULL,
  `metode` ENUM('Tunai','VA','Qris') NOT NULL,
  `uang_pangkal` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `uang_psb` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `uang_spp` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `uang_titipan` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `uang_komite` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `uang_du` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `uang_lain` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `nama_biaya_lain` VARCHAR(100) DEFAULT NULL,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`seed_key`)
) ENGINE=InnoDB;

-- 970 pembayaran SPP + Komite. Seluruh pembayaran per siswa selalu dimulai
-- dari Juli sehingga tidak melompati tunggakan bulan yang lebih lama.
INSERT INTO `seed_demo_payment_plan` (
  `seed_key`,`kategori`,`NO_INDUK`,`KELAS`,`master_kelas_id`,`kelas_rombel_snapshot`,
  `tanggal`,`bulan`,`tahun`,`user_id`,`metode`,`uang_pangkal`,`uang_psb`,`uang_spp`,
  `uang_titipan`,`uang_komite`,`uang_du`,`uang_lain`,`nama_biaya_lain`,`total`
)
SELECT
  CONCAT('SEED-DEMO-SPP-', ts.`NO_INDUK`, '-', ts.`tahun`, ts.`bulan`),
  'spp', ts.`NO_INDUK`, r.`KELAS`, r.`master_kelas_id`, r.`kelas_rombel_snapshot`,
  CONCAT(ts.`tahun`, '-', ts.`bulan`, '-', LPAD(1 + MOD(CRC32(CONCAT(ts.`NO_INDUK`, ts.`bulan`, ts.`tahun`)), 25), 2, '0'),
         ' ', LPAD(8 + MOD(CRC32(CONCAT(ts.`tahun`, ts.`NO_INDUK`)), 8), 2, '0'), ':', LPAD(MOD(CRC32(ts.`NO_INDUK`), 60), 2, '0'), ':00'),
  ts.`bulan`, ts.`tahun`,
  CASE WHEN MOD(CRC32(CONCAT(ts.`NO_INDUK`, ts.`bulan`, ts.`tahun`)), 3) = 0 THEN @seed_demo_operator_kasir ELSE @seed_demo_operator_admin END,
  CASE MOD(CRC32(CONCAT(ts.`tahun`, ts.`bulan`, ts.`NO_INDUK`)), 10)
    WHEN 0 THEN 'Qris' WHEN 1 THEN 'Qris' WHEN 2 THEN 'VA' WHEN 3 THEN 'VA' WHEN 4 THEN 'VA' ELSE 'Tunai' END,
  CASE WHEN r.`KELAS` = '1' AND ts.`bulan` = '07' AND ts.`tahun` = '2026'
    THEN GREATEST(r.`PANGKAL` - r.`potong_pangkal`, 0) ELSE 0 END,
  0, ts.`nominal_tagihan`, 0, tk.`nominal_tagihan`,
  CASE WHEN r.`rank_no` <= 100 AND ts.`bulan` = '07' AND ts.`tahun` = '2026' THEN du.`nominal_tagihan` ELSE 0 END,
  0, NULL,
  ts.`nominal_tagihan` + tk.`nominal_tagihan`
    + CASE WHEN r.`KELAS` = '1' AND ts.`bulan` = '07' AND ts.`tahun` = '2026' THEN GREATEST(r.`PANGKAL` - r.`potong_pangkal`, 0) ELSE 0 END
    + CASE WHEN r.`rank_no` <= 100 AND ts.`bulan` = '07' AND ts.`tahun` = '2026' THEN du.`nominal_tagihan` ELSE 0 END
FROM `tagihan_spp` ts
JOIN `seed_demo_regular_rank` r ON r.`NO_INDUK` = ts.`NO_INDUK`
JOIN `tagihan_komite` tk ON tk.`NO_INDUK` = ts.`NO_INDUK` AND tk.`bulan` = ts.`bulan` AND tk.`tahun` = ts.`tahun` AND tk.`status` = 'open'
LEFT JOIN `tagihan_daftar_ulang` du ON du.`NO_INDUK` = ts.`NO_INDUK` AND du.`tahun_ajaran_snapshot` = '2026/2027' AND du.`status` = 'open'
JOIN `seed_demo_payment_guard` g
WHERE ts.`status` = 'open'
  AND (
    (r.`rank_no` <= 136 AND (CAST(ts.`tahun` AS UNSIGNED) * 100 + CAST(ts.`bulan` AS UNSIGNED)) <= 202701)
    OR (r.`rank_no` BETWEEN 137 AND 142 AND (CAST(ts.`tahun` AS UNSIGNED) * 100 + CAST(ts.`bulan` AS UNSIGNED)) <= 202609)
  );

-- Enam transaksi PSB dengan Pangkal dan PSB yang masih dapat dicicil lagi.
INSERT INTO `seed_demo_payment_plan` (
  `seed_key`,`kategori`,`NO_INDUK`,`KELAS`,`master_kelas_id`,`kelas_rombel_snapshot`,
  `tanggal`,`bulan`,`tahun`,`user_id`,`metode`,`uang_pangkal`,`uang_psb`,`uang_spp`,
  `uang_titipan`,`uang_komite`,`uang_du`,`uang_lain`,`nama_biaya_lain`,`total`
)
SELECT CONCAT('SEED-DEMO-PSB-', s.`NO_INDUK`), 'psb', s.`NO_INDUK`, 'PSB', s.`master_kelas_id`, 'PSB',
  CONCAT('2026-06-', LPAD(10 + MOD(CRC32(s.`NO_INDUK`), 15), 2, '0'), ' 09:30:00'), '06', '2026',
  @seed_demo_operator_admin,
  CASE WHEN MOD(CRC32(s.`NO_INDUK`), 2) = 0 THEN 'Tunai' ELSE 'VA' END,
  500000, 900000 + (MOD(CRC32(s.`NO_INDUK`), 4) * 100000), 0, 0, 0, 0, 0, NULL,
  1400000 + (MOD(CRC32(s.`NO_INDUK`), 4) * 100000)
FROM `siswa` s
JOIN `seed_demo_payment_guard` g
WHERE s.`NO_INDUK` LIKE 'PSB%' AND s.`is_active` = 1;

-- Dua belas Titipan SPP berdiri sendiri, agar kasir dapat mencoba pemakaian
-- titipan pada tagihan Februari yang masih terbuka.
INSERT INTO `seed_demo_payment_plan` (
  `seed_key`,`kategori`,`NO_INDUK`,`KELAS`,`master_kelas_id`,`kelas_rombel_snapshot`,
  `tanggal`,`bulan`,`tahun`,`user_id`,`metode`,`uang_pangkal`,`uang_psb`,`uang_spp`,
  `uang_titipan`,`uang_komite`,`uang_du`,`uang_lain`,`nama_biaya_lain`,`total`
)
SELECT CONCAT('SEED-DEMO-TITIPAN-', r.`NO_INDUK`), 'titipan', r.`NO_INDUK`, r.`KELAS`, r.`master_kelas_id`, r.`kelas_rombel_snapshot`,
  CONCAT('2027-02-', LPAD(3 + MOD(CRC32(r.`NO_INDUK`), 20), 2, '0'), ' 10:15:00'), '02', '2027',
  @seed_demo_operator_kasir, 'Tunai', 0, 0, 0,
  50000 + (MOD(CRC32(CONCAT('titipan-', r.`NO_INDUK`)), 4) * 25000), 0, 0, 0, NULL,
  50000 + (MOD(CRC32(CONCAT('titipan-', r.`NO_INDUK`)), 4) * 25000)
FROM `seed_demo_regular_rank` r
JOIN `seed_demo_payment_guard` g
WHERE r.`rank_no` BETWEEN 125 AND 136;

-- Dua belas Biaya Lain dengan tagihan serta rincian pembayaran eksplisit.
INSERT INTO `seed_demo_payment_plan` (
  `seed_key`,`kategori`,`NO_INDUK`,`KELAS`,`master_kelas_id`,`kelas_rombel_snapshot`,
  `tanggal`,`bulan`,`tahun`,`user_id`,`metode`,`uang_pangkal`,`uang_psb`,`uang_spp`,
  `uang_titipan`,`uang_komite`,`uang_du`,`uang_lain`,`nama_biaya_lain`,`total`
)
SELECT CONCAT('SEED-DEMO-LAIN-', r.`NO_INDUK`), 'lain', r.`NO_INDUK`, r.`KELAS`, r.`master_kelas_id`, r.`kelas_rombel_snapshot`,
  CONCAT('2026-08-', LPAD(5 + MOD(CRC32(r.`NO_INDUK`), 20), 2, '0'), ' 11:00:00'), '08', '2026',
  @seed_demo_operator_kasir, 'Qris', 0, 0, 0, 0, 0, 0,
  CASE MOD(r.`rank_no`, 3) WHEN 1 THEN 180000 WHEN 2 THEN 250000 ELSE 220000 END,
  CASE MOD(r.`rank_no`, 3) WHEN 1 THEN 'DEMO Buku Paket 2026' WHEN 2 THEN 'DEMO Kegiatan Semester' ELSE 'DEMO Seragam Olahraga' END,
  CASE MOD(r.`rank_no`, 3) WHEN 1 THEN 180000 WHEN 2 THEN 250000 ELSE 220000 END
FROM `seed_demo_regular_rank` r
JOIN `seed_demo_payment_guard` g
WHERE r.`rank_no` BETWEEN 1 AND 12;

-- Tagihan Biaya Lain harus ada sebelum rincian pembayaran direkam.
INSERT INTO `tagihan_biaya_lain` (
  `master_biaya_lain_id`,`no_induk`,`master_kelas_id`,`nama_snapshot`,`nominal_tagihan`,
  `kelas_rombel_snapshot`,`status`,`created_by`
)
SELECT m.`id`, p.`NO_INDUK`, p.`master_kelas_id`, p.`nama_biaya_lain`, p.`uang_lain`,
       p.`kelas_rombel_snapshot`, 'open', NULLIF(@seed_demo_actor_id, 0)
FROM `seed_demo_payment_plan` p
JOIN `master_biaya_lain` m ON m.`nama` = p.`nama_biaya_lain`
WHERE p.`kategori` = 'lain';

-- Header pembayaran: 1.000 transaksi unik, masing-masing memiliki penanda demo.
INSERT INTO `bayar` (
  `NO_INDUK`,`KELAS`,`master_kelas_id`,`kelas_rombel_snapshot`,`U_PANGKAL`,`U_PSB`,`U_SPP`,
  `U_TITIPAN_SPP`,`U_KOMITE`,`U_LAIN`,`KETERANGAN`,`TGL_BYR`,`BULAN`,`user_id`,
  `sistem_pembayaran`,`TAHUN`,`LAIN_LAIN1`,`JUMLAH1`,`th_ajaran`,`kelas_du`,
  `total_jumlah`,`payment_link_version`
)
SELECT p.`NO_INDUK`, p.`KELAS`, p.`master_kelas_id`, p.`kelas_rombel_snapshot`, p.`uang_pangkal`, p.`uang_psb`, p.`uang_spp`,
       p.`uang_titipan`, p.`uang_komite`, p.`uang_lain`, p.`seed_key`, p.`tanggal`, p.`bulan`, p.`user_id`,
       p.`metode`, p.`tahun`, p.`nama_biaya_lain`, p.`uang_lain`,
       CASE WHEN p.`uang_du` > 0 THEN '2026/2027' ELSE NULL END,
       CASE WHEN p.`uang_du` > 0 THEN p.`KELAS` ELSE NULL END,
       p.`total`, 1
FROM `seed_demo_payment_plan` p;

DROP TEMPORARY TABLE IF EXISTS `seed_demo_payment_header`;
CREATE TEMPORARY TABLE `seed_demo_payment_header` AS
SELECT b.`id` AS `bayar_id`, p.*
FROM `seed_demo_payment_plan` p
JOIN `bayar` b ON b.`KETERANGAN` = p.`seed_key`;
ALTER TABLE `seed_demo_payment_header` ADD PRIMARY KEY (`bayar_id`), ADD UNIQUE KEY `uk_seed_demo_payment_key` (`seed_key`);

-- Rincian SPP dan Komite bulanan.
INSERT INTO `bayar_spp_periode` (`bayar_id`,`no_induk`,`bulan`,`tahun`)
SELECT `bayar_id`,`NO_INDUK`,`bulan`,`tahun`
FROM `seed_demo_payment_header`
WHERE `kategori` = 'spp';

INSERT INTO `spp_alokasi_batch` (
  `no_induk`,`bayar_id`,`tanggal`,`user_id`,`gunakan_titipan`,`komite_required`,
  `uang_baru`,`titipan_digunakan`,`titipan_baru`
)
SELECT `NO_INDUK`,`bayar_id`,`tanggal`,`user_id`,0,1,`uang_spp`,0,0
FROM `seed_demo_payment_header`
WHERE `kategori` = 'spp';

INSERT INTO `spp_alokasi` (`batch_id`,`tagihan_spp_id`,`nominal_dari_bayar`,`nominal_dari_titipan`)
SELECT ab.`id`, ts.`id`, h.`uang_spp`, 0
FROM `seed_demo_payment_header` h
JOIN `spp_alokasi_batch` ab ON ab.`bayar_id` = h.`bayar_id`
JOIN `tagihan_spp` ts ON ts.`NO_INDUK` = h.`NO_INDUK` AND ts.`bulan` = h.`bulan` AND ts.`tahun` = h.`tahun`
WHERE h.`kategori` = 'spp';

INSERT INTO `bayar_komite` (`bayar_id`,`tagihan_komite_id`,`nominal`)
SELECT h.`bayar_id`, tk.`id`, h.`uang_komite`
FROM `seed_demo_payment_header` h
JOIN `tagihan_komite` tk ON tk.`NO_INDUK` = h.`NO_INDUK` AND tk.`bulan` = h.`bulan` AND tk.`tahun` = h.`tahun`
WHERE h.`kategori` = 'spp';

INSERT INTO `bayar_du` (`bayar_id`,`tagihan_daftar_ulang_id`,`no_induk`,`kelas`,`th_ajaran`,`jumlah`)
SELECT h.`bayar_id`, du.`id`, h.`NO_INDUK`, du.`kelas_snapshot`, du.`tahun_ajaran_snapshot`, h.`uang_du`
FROM `seed_demo_payment_header` h
JOIN `tagihan_daftar_ulang` du ON du.`NO_INDUK` = h.`NO_INDUK` AND du.`tahun_ajaran_snapshot` = '2026/2027'
WHERE h.`uang_du` > 0;

-- Buku besar Titipan SPP untuk dua belas setoran titipan.
INSERT INTO `spp_alokasi_batch` (
  `no_induk`,`bayar_id`,`tanggal`,`user_id`,`gunakan_titipan`,`komite_required`,
  `uang_baru`,`titipan_digunakan`,`titipan_baru`
)
SELECT `NO_INDUK`,`bayar_id`,`tanggal`,`user_id`,0,0,`uang_titipan`,0,`uang_titipan`
FROM `seed_demo_payment_header`
WHERE `kategori` = 'titipan';

INSERT INTO `titipan_spp_mutasi` (
  `no_induk`,`batch_id`,`bayar_id`,`jenis`,`nominal`,`tanggal`,`sistem_pembayaran`,`user_id`,`keterangan`
)
SELECT h.`NO_INDUK`, ab.`id`, h.`bayar_id`, 'masuk', h.`uang_titipan`, h.`tanggal`, h.`metode`, h.`user_id`, 'Seed Titipan SPP Demo'
FROM `seed_demo_payment_header` h
JOIN `spp_alokasi_batch` ab ON ab.`bayar_id` = h.`bayar_id`
WHERE h.`kategori` = 'titipan';

INSERT INTO `bayar_biaya_lain` (
  `bayar_id`,`master_biaya_lain_id`,`tagihan_biaya_lain_id`,`nama_biaya_snapshot`,
  `nominal_snapshot`,`keterangan`,`urutan`,`legacy_key`
)
SELECT h.`bayar_id`, m.`id`, t.`id`, h.`nama_biaya_lain`, h.`uang_lain`,
       'Pembayaran biaya lain demo', 1, 'seed1'
FROM `seed_demo_payment_header` h
JOIN `master_biaya_lain` m ON m.`nama` = h.`nama_biaya_lain`
JOIN `tagihan_biaya_lain` t ON t.`master_biaya_lain_id` = m.`id` AND t.`no_induk` = h.`NO_INDUK`
WHERE h.`kategori` = 'lain';

COMMIT;

-- Pemeriksaan akhir: 1.000 header dengan relasi yang konsisten.
-- Hitung temporary table satu kali agar kompatibel dengan MariaDB yang tidak
-- mengizinkan tabel temporary dibuka berulang kali dalam satu UNION.
SELECT
  SUM(`kategori` = 'spp'),
  SUM(`kategori` = 'psb'),
  SUM(`kategori` = 'titipan'),
  SUM(`kategori` = 'lain'),
  SUM(ABS(`total` - (`uang_pangkal` + `uang_psb` + `uang_spp` + `uang_titipan` + `uang_komite` + `uang_du` + `uang_lain`)) > 0.001)
INTO
  @seed_demo_count_spp,
  @seed_demo_count_psb,
  @seed_demo_count_titipan,
  @seed_demo_count_lain,
  @seed_demo_count_invalid_total
FROM `seed_demo_payment_header`;

SELECT 'pembayaran_total' AS `pemeriksaan`, COUNT(*) AS `jumlah` FROM `bayar`
UNION ALL SELECT 'pembayaran_spp_komite', @seed_demo_count_spp
UNION ALL SELECT 'pembayaran_psb', @seed_demo_count_psb
UNION ALL SELECT 'pembayaran_titipan', @seed_demo_count_titipan
UNION ALL SELECT 'pembayaran_biaya_lain', @seed_demo_count_lain
UNION ALL SELECT 'alokasi_spp', (SELECT COUNT(*) FROM `spp_alokasi`)
UNION ALL SELECT 'bayar_komite', (SELECT COUNT(*) FROM `bayar_komite`)
UNION ALL SELECT 'bayar_daftar_ulang', (SELECT COUNT(*) FROM `bayar_du`)
UNION ALL SELECT 'rincian_biaya_lain', (SELECT COUNT(*) FROM `bayar_biaya_lain`)
UNION ALL SELECT 'mutasi_titipan', (SELECT COUNT(*) FROM `titipan_spp_mutasi`)
UNION ALL SELECT 'mutasi_tabungan', (SELECT COUNT(*) FROM `transaksi_m`) + (SELECT COUNT(*) FROM `transaksi_k`)
UNION ALL SELECT 'header_total_tidak_sesuai', @seed_demo_count_invalid_total;

DROP TEMPORARY TABLE IF EXISTS `seed_demo_payment_header`;
DROP TEMPORARY TABLE IF EXISTS `seed_demo_payment_plan`;
DROP TEMPORARY TABLE IF EXISTS `seed_demo_regular_rank`;
DROP TEMPORARY TABLE IF EXISTS `seed_demo_payment_guard`;
