-- ============================================
-- SistemSPP - Database Schema (Revisi Baru)
-- Database: db_spp
-- ============================================

CREATE DATABASE IF NOT EXISTS `db_spp`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `db_spp`;

SET FOREIGN_KEY_CHECKS = 0;

-- Tabel Admin (Untuk Login, tetap dipertahankan)
CREATE TABLE IF NOT EXISTS `admin` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `username`   VARCHAR(50)  NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `nama`       VARCHAR(100) NOT NULL,
  `role`       ENUM('admin','bendahara','kasir') NOT NULL DEFAULT 'admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default users: admin / bendahara / kasir
INSERT INTO `admin` (`username`, `password`, `nama`, `role`) VALUES
('admin',      MD5('admin123'),      'Administrator', 'admin'),
('bendahara',  MD5('bendahara123'),  'Bendahara TU',  'bendahara'),
('kasir',      MD5('kasir123'),      'Kasir',         'kasir'),
('kasir1',     MD5('kasir123'),      'Kasir Loket 1', 'kasir'),
('kasir2',     MD5('kasir123'),      'Kasir Loket 2', 'kasir'),
('kasir3',     MD5('kasir123'),      'Kasir Loket 3', 'kasir'),
('kasir4',     MD5('kasir123'),      'Kasir Loket 4', 'kasir')
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`), `role`=VALUES(`role`);

-- Master kelas/rombel. Data lama menggunakan placeholder per tingkat sampai
-- admin memindahkan siswa ke rombel sebenarnya (1A, 1B, dan seterusnya).
DROP TABLE IF EXISTS `master_kelas`;
CREATE TABLE `master_kelas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tingkat` TINYINT UNSIGNED NOT NULL,
  `kode_rombel` VARCHAR(10) NOT NULL,
  `is_placeholder` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_master_kelas_tingkat_rombel` (`tingkat`,`kode_rombel`),
  KEY `idx_master_kelas_active` (`is_active`,`tingkat`,`kode_rombel`),
  CONSTRAINT `chk_master_kelas_tingkat` CHECK (`tingkat` BETWEEN 0 AND 6)
) ENGINE=InnoDB;
INSERT INTO `master_kelas` (`tingkat`,`kode_rombel`,`is_placeholder`,`is_active`) VALUES
(0,'PSB',0,1),
(1,'A',0,1),(1,'B',0,1),(1,'C',0,1),(1,'D',0,1),(1,'E',0,1),(1,'F',0,1),(1,'G',0,1),(1,'H',0,1),(1,'I',0,1),(1,'J',0,1),
(2,'A',0,1),(2,'B',0,1),(2,'C',0,1),(2,'D',0,1),(2,'E',0,1),(2,'F',0,1),(2,'G',0,1),(2,'H',0,1),(2,'I',0,1),(2,'J',0,1),
(3,'A',0,1),(3,'B',0,1),(3,'C',0,1),(3,'D',0,1),(3,'E',0,1),(3,'F',0,1),(3,'G',0,1),(3,'H',0,1),(3,'I',0,1),(3,'J',0,1),
(4,'A',0,1),(4,'B',0,1),(4,'C',0,1),(4,'D',0,1),(4,'E',0,1),(4,'F',0,1),(4,'G',0,1),(4,'H',0,1),(4,'I',0,1),(4,'J',0,1),
(5,'A',0,1),(5,'B',0,1),(5,'C',0,1),(5,'D',0,1),(5,'E',0,1),(5,'F',0,1),(5,'G',0,1),(5,'H',0,1),(5,'I',0,1),(5,'J',0,1),
(6,'A',0,1),(6,'B',0,1),(6,'C',0,1),(6,'D',0,1),(6,'E',0,1),(6,'F',0,1),(6,'G',0,1),(6,'H',0,1),(6,'I',0,1),(6,'J',0,1);


-- Tabel Siswa (Revisi Baru)
DROP TABLE IF EXISTS `siswa`;
CREATE TABLE `siswa` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK`        VARCHAR(10) NOT NULL UNIQUE,
  `NAMA`            VARCHAR(100) NOT NULL,
  `KELAS`           VARCHAR(10) NOT NULL,
  `master_kelas_id` INT DEFAULT NULL,
  `SPP_PERBULAN`    DECIMAL(15,2) NOT NULL DEFAULT 0,
  `potongan_spp_persen` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `PANGKAL`         DECIMAL(15,2) NOT NULL DEFAULT 0,
  `PSB`             DECIMAL(15,2) NOT NULL DEFAULT 0,
  `asal_psb`        TINYINT(1) NOT NULL DEFAULT 0,
  `POMG`            DECIMAL(15,2) NOT NULL DEFAULT 0,
  `DAFTAR_ULANG`    DECIMAL(15,2) NOT NULL DEFAULT 0,
  `NO_induk_diknas` CHAR(10) DEFAULT NULL,
  `potong_pangkal`  DECIMAL(15,2) NOT NULL DEFAULT 0,
  `tot_pangkal`     DECIMAL(15,2) NOT NULL DEFAULT 0,
  `tot_du`          DECIMAL(15,2) NOT NULL DEFAULT 0,
  `potong_du`       DECIMAL(15,2) NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_siswa_no_induk_diknas` (`NO_induk_diknas`),
  KEY `idx_siswa_status_kelas_nama` (`is_active`, `KELAS`, `NAMA`),
  KEY `idx_siswa_master_kelas` (`master_kelas_id`,`is_active`,`NAMA`),
  CONSTRAINT `fk_siswa_master_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_siswa_kelas_sd` CHECK (`KELAS` IN ('0','1','2','3','4','5','6','PSB')),
  CONSTRAINT `chk_siswa_psb` CHECK (`PSB` >= 0 AND `asal_psb` IN (0,1)),
  CONSTRAINT `chk_siswa_potongan_spp` CHECK (`potongan_spp_persen` BETWEEN 0 AND 100)
) ENGINE=InnoDB;

-- Audit perubahan master siswa
DROP TABLE IF EXISTS `siswa_audit_log`;
CREATE TABLE `siswa_audit_log` (
  `id`                BIGINT AUTO_INCREMENT PRIMARY KEY,
  `siswa_id`          INT DEFAULT NULL,
  `no_induk_snapshot` VARCHAR(10) NOT NULL,
  `aksi`              VARCHAR(30) NOT NULL,
  `before_data`       LONGTEXT DEFAULT NULL,
  `after_data`        LONGTEXT DEFAULT NULL,
  `admin_id`          INT DEFAULT NULL,
  `admin_name`        VARCHAR(100) NOT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_siswa_audit_siswa` (`siswa_id`, `created_at`),
  KEY `idx_siswa_audit_no_induk` (`no_induk_snapshot`, `created_at`),
  CONSTRAINT `fk_siswa_audit_siswa` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_siswa_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Data siswa contoh
INSERT INTO `siswa` (
  `NO_INDUK`, `NO_induk_diknas`, `NAMA`, `KELAS`, `SPP_PERBULAN`,
  `PANGKAL`, `PSB`, `asal_psb`, `POMG`, `DAFTAR_ULANG`, `tot_pangkal`, `tot_du`
) VALUES
('2024001', NULL,      'Ahmad Fauzi',      '1', 250000, 1000000, 0, 0, 100000, 1000000, 1000000, 1000000),
('2024002', NULL,      'Siti Rahayu',      '2', 260000, 1100000, 0, 0, 110000, 1100000, 1100000, 1100000),
('2024003', NULL,      'Budi Santoso',     '3', 275000, 1200000, 0, 0, 120000, 1200000, 1200000, 1200000),
('2024004', NULL,      'Dewi Lestari',     '4', 290000, 1300000, 0, 0, 130000, 1300000, 1300000, 1300000),
('2024005', NULL,      'Muhammad Rizky',   '5', 305000, 1400000, 0, 0, 140000, 1400000, 1400000, 1400000),
('2024006', NULL,      'Ayu Putri',        '6', 320000, 1500000, 0, 0, 150000, 1500000, 1500000, 1500000),
('PSB0001', 'D26PSB001','Calon Siswa PSB','PSB', 0, 500000, 3600000, 1, 0, 0, 500000, 0);

UPDATE `siswa` s
JOIN `master_kelas` mk
  ON mk.tingkat = CAST(s.KELAS AS UNSIGNED)
 AND mk.kode_rombel = ELT(MOD(CAST(s.NO_INDUK AS UNSIGNED) - 1, 4) + 1, 'A', 'B', 'C', 'D')
 AND mk.is_placeholder = 0
SET s.master_kelas_id = mk.id
WHERE s.KELAS IN ('1','2','3','4','5','6');

UPDATE `siswa` s
JOIN `master_kelas` mk ON mk.id=s.master_kelas_id
SET s.asal_psb=1
WHERE mk.tingkat=0 OR UPPER(mk.kode_rombel)='PSB';

-- Tabel Bayar (Revisi Baru)
DROP TABLE IF EXISTS `bayar`;
CREATE TABLE `bayar` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK`    VARCHAR(10) DEFAULT NULL,
  `KELAS`       VARCHAR(5) DEFAULT NULL,
  `master_kelas_id` INT DEFAULT NULL,
  `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `U_PANGKAL`   DOUBLE DEFAULT 0,
  `U_PSB`       DECIMAL(15,2) NOT NULL DEFAULT 0,
  `U_SPP`       DOUBLE DEFAULT 0,
  `U_TITIPAN_SPP` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `U_KOMITE`    DECIMAL(15,2) NOT NULL DEFAULT 0,
  `U_LAIN`      DOUBLE DEFAULT 0,
  `KETERANGAN`  VARCHAR(255) DEFAULT NULL,
  `TGL_BYR`     DATETIME DEFAULT NULL,
  `BULAN`       VARCHAR(20) DEFAULT NULL,
  `user_id`     VARCHAR(100) DEFAULT NULL,
  `sistem_pembayaran` ENUM('Tunai','VA','Qris') NOT NULL DEFAULT 'VA',
  `TAHUN`       CHAR(4) DEFAULT NULL,
  `LAIN_LAIN1`  VARCHAR(100) DEFAULT NULL,
  `LAIN_LAIN2`  VARCHAR(100) DEFAULT NULL,
  `LAIN_LAIN3`  VARCHAR(100) DEFAULT NULL,
  `LAIN_LAIN4`  VARCHAR(100) DEFAULT NULL,
  `JUMLAH1`     DOUBLE DEFAULT 0,
  `JUMLAH2`     DOUBLE DEFAULT 0,
  `JUMLAH3`     DOUBLE DEFAULT 0,
  `JUMLAH4`     DOUBLE DEFAULT 0,
  `th_ajaran`   CHAR(9) DEFAULT NULL,
  `kelas_du`    CHAR(5) DEFAULT NULL,
  `potong_spp`  DOUBLE DEFAULT 0,
  `total_jumlah` DOUBLE DEFAULT 0, -- Kolom bantu kalkulasi total pembayaran
  `payment_link_version` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `payment_batch_token` CHAR(32) DEFAULT NULL,
  `payment_batch_sequence` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `payment_batch_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_bayar_payment_batch` (`payment_batch_token`, `payment_batch_sequence`),
  KEY `idx_bayar_master_kelas_tanggal` (`master_kelas_id`,`TGL_BYR`),
  KEY `idx_bayar_tanggal_operator_metode` (`TGL_BYR`,`user_id`,`sistem_pembayaran`),
  KEY `idx_bayar_siswa_periode` (`NO_INDUK`,`TAHUN`,`BULAN`),
  CONSTRAINT `fk_bayar_master_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_siswa` FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_bayar_psb` CHECK (`U_PSB` >= 0)
) ENGINE=InnoDB;

-- Pemetaan periode per transaksi SPP. Satu siswa hanya boleh memiliki satu
-- transaksi SPP penuh untuk bulan dan tahun yang sama.
DROP TABLE IF EXISTS `bayar_spp_periode`;
CREATE TABLE `bayar_spp_periode` (
  `bayar_id` INT NOT NULL PRIMARY KEY,
  `no_induk` VARCHAR(10) NOT NULL,
  `bulan` CHAR(2) NOT NULL,
  `tahun` CHAR(4) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_bayar_spp_siswa_periode` (`no_induk`, `tahun`, `bulan`),
  CONSTRAINT `fk_bayar_spp_periode_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_spp_periode_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Penerbitan SPP terpisah dari status penerbitan Daftar Ulang.
CREATE TABLE `master_spp_tahun` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tahun_ajaran_id` INT NOT NULL,
  `status` ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL, `closed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_master_spp_tahun` (`tahun_ajaran_id`),
  CONSTRAINT `fk_master_spp_tahun_ajaran` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE `master_spp_tarif` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `master_spp_tahun_id` INT NOT NULL,
  `tingkat` TINYINT UNSIGNED NOT NULL, `nominal_dasar` DECIMAL(15,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_master_spp_tarif_tingkat` (`master_spp_tahun_id`,`tingkat`),
  CONSTRAINT `fk_master_spp_tarif_tahun` FOREIGN KEY (`master_spp_tahun_id`) REFERENCES `master_spp_tahun` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_master_spp_tingkat` CHECK (`tingkat` BETWEEN 1 AND 6),
  CONSTRAINT `chk_master_spp_nominal` CHECK (`nominal_dasar` > 0)
) ENGINE=InnoDB;
CREATE TABLE `tagihan_spp` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `master_spp_tahun_id` INT NOT NULL, `tahun_ajaran_id` INT NOT NULL, `penempatan_id` BIGINT NOT NULL,
  `no_induk` VARCHAR(10) NOT NULL, `tingkat_snapshot` TINYINT UNSIGNED NOT NULL,
  `master_kelas_id` INT DEFAULT NULL, `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `bulan` CHAR(2) NOT NULL, `tahun` CHAR(4) NOT NULL,
  `tarif_dasar_snapshot` DECIMAL(15,2) NOT NULL,
  `potongan_persen_snapshot` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `potongan_nominal_snapshot` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `nominal_tagihan` DECIMAL(15,2) NOT NULL,
  `status` ENUM('open','cancelled','covered_psb','waived') NOT NULL DEFAULT 'open',
  `cancel_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tagihan_spp_siswa_periode` (`no_induk`,`tahun`,`bulan`),
  KEY `idx_tagihan_spp_tahun_status` (`tahun_ajaran_id`,`status`,`tingkat_snapshot`),
  KEY `idx_tagihan_spp_penempatan` (`penempatan_id`),
  CONSTRAINT `fk_tagihan_spp_master_tahun` FOREIGN KEY (`master_spp_tahun_id`) REFERENCES `master_spp_tahun` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_spp_tahun` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_spp_penempatan` FOREIGN KEY (`penempatan_id`) REFERENCES `siswa_tahun_ajaran` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_spp_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_spp_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_tagihan_spp_bulan` CHECK (CAST(`bulan` AS UNSIGNED) BETWEEN 1 AND 12),
  CONSTRAINT `chk_tagihan_spp_nominal` CHECK (`tarif_dasar_snapshot` >= 0 AND `potongan_persen_snapshot` BETWEEN 0 AND 100 AND `nominal_tagihan` >= 0)
) ENGINE=InnoDB;
CREATE TABLE `spp_alokasi_batch` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `no_induk` VARCHAR(10) NOT NULL, `bayar_id` INT DEFAULT NULL,
  `tanggal` DATETIME NOT NULL, `user_id` VARCHAR(100) DEFAULT NULL,
  `gunakan_titipan` TINYINT(1) NOT NULL DEFAULT 0,
  `uang_baru` DECIMAL(15,2) NOT NULL DEFAULT 0, `titipan_digunakan` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `titipan_baru` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('active','reversed') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_spp_alokasi_batch_bayar_status` (`bayar_id`,`status`), KEY `idx_spp_alokasi_batch_siswa` (`no_induk`,`tanggal`),
  CONSTRAINT `fk_spp_alokasi_batch_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_spp_alokasi_batch_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_spp_alokasi_batch_nominal` CHECK (`uang_baru` >= 0 AND `titipan_digunakan` >= 0 AND `titipan_baru` >= 0)
) ENGINE=InnoDB;
CREATE TABLE `spp_alokasi` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `batch_id` BIGINT NOT NULL, `tagihan_spp_id` BIGINT NOT NULL,
  `nominal_dari_bayar` DECIMAL(15,2) NOT NULL DEFAULT 0, `nominal_dari_titipan` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_spp_alokasi_batch_tagihan` (`batch_id`,`tagihan_spp_id`), KEY `idx_spp_alokasi_tagihan` (`tagihan_spp_id`),
  CONSTRAINT `fk_spp_alokasi_batch` FOREIGN KEY (`batch_id`) REFERENCES `spp_alokasi_batch` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_spp_alokasi_tagihan` FOREIGN KEY (`tagihan_spp_id`) REFERENCES `tagihan_spp` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_spp_alokasi_nominal` CHECK (`nominal_dari_bayar` >= 0 AND `nominal_dari_titipan` >= 0 AND (`nominal_dari_bayar` + `nominal_dari_titipan`) > 0)
) ENGINE=InnoDB;
CREATE TABLE `titipan_spp_mutasi` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `no_induk` VARCHAR(10) NOT NULL,
  `batch_id` BIGINT DEFAULT NULL, `bayar_id` INT DEFAULT NULL,
  `jenis` ENUM('masuk','pakai','pengembalian','koreksi_masuk','koreksi_keluar') NOT NULL,
  `nominal` DECIMAL(15,2) NOT NULL, `tanggal` DATETIME NOT NULL,
  `sistem_pembayaran` ENUM('Tunai','VA','Qris') DEFAULT NULL,
  `user_id` VARCHAR(100) DEFAULT NULL, `keterangan` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_titipan_spp_siswa_tanggal` (`no_induk`,`tanggal`,`id`), KEY `idx_titipan_spp_batch` (`batch_id`),
  CONSTRAINT `fk_titipan_spp_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_titipan_spp_batch` FOREIGN KEY (`batch_id`) REFERENCES `spp_alokasi_batch` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_titipan_spp_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_titipan_spp_nominal` CHECK (`nominal` > 0)
) ENGINE=InnoDB;
CREATE TABLE `spp_audit_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `master_spp_tahun_id` INT DEFAULT NULL, `no_induk` VARCHAR(10) DEFAULT NULL,
  `aksi` VARCHAR(40) NOT NULL, `before_data` LONGTEXT DEFAULT NULL, `after_data` LONGTEXT DEFAULT NULL,
  `affected_count` INT NOT NULL DEFAULT 0, `admin_id` INT DEFAULT NULL, `admin_name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_spp_audit_tahun` (`master_spp_tahun_id`,`created_at`), KEY `idx_spp_audit_siswa` (`no_induk`,`created_at`),
  CONSTRAINT `fk_spp_audit_tahun` FOREIGN KEY (`master_spp_tahun_id`) REFERENCES `master_spp_tahun` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_spp_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Master jenis biaya lain
DROP TABLE IF EXISTS `master_biaya_lain`;
CREATE TABLE `master_biaya_lain` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `nama`       VARCHAR(100) NOT NULL UNIQUE,
  `nominal`    DECIMAL(15,2) NOT NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `chk_master_biaya_lain_nominal` CHECK (`nominal` > 0)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS `tagihan_biaya_lain_audit_log`;
DROP TABLE IF EXISTS `tagihan_biaya_lain`;
CREATE TABLE `tagihan_biaya_lain` (
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
  UNIQUE KEY `uk_tagihan_biaya_lain_siswa_master` (`master_biaya_lain_id`,`no_induk`),
  KEY `idx_tagihan_biaya_lain_status` (`master_biaya_lain_id`,`status`,`master_kelas_id`),
  KEY `idx_tagihan_biaya_lain_siswa` (`no_induk`,`status`),
  CONSTRAINT `fk_tagihan_biaya_lain_master` FOREIGN KEY (`master_biaya_lain_id`) REFERENCES `master_biaya_lain`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_biaya_lain_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_biaya_lain_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_biaya_lain_admin` FOREIGN KEY (`created_by`) REFERENCES `admin`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_tagihan_biaya_lain_nominal` CHECK (`nominal_tagihan` > 0)
) ENGINE=InnoDB;
CREATE TABLE `tagihan_biaya_lain_audit_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `master_biaya_lain_id` INT DEFAULT NULL,
  `aksi` VARCHAR(40) NOT NULL, `target` VARCHAR(40) NOT NULL, `target_value` VARCHAR(100) DEFAULT NULL,
  `affected_count` INT NOT NULL DEFAULT 0, `total_nominal` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `admin_id` INT DEFAULT NULL, `admin_name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tagihan_biaya_lain_audit` (`master_biaya_lain_id`,`created_at`),
  CONSTRAINT `fk_tagihan_biaya_lain_audit_master` FOREIGN KEY (`master_biaya_lain_id`) REFERENCES `master_biaya_lain`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_biaya_lain_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Detail biaya lain per transaksi. Nama dan nominal disimpan sebagai snapshot
-- agar perubahan master tidak mengubah riwayat transaksi.
DROP TABLE IF EXISTS `bayar_biaya_lain`;
CREATE TABLE `bayar_biaya_lain` (
  `id`                         INT AUTO_INCREMENT PRIMARY KEY,
  `bayar_id`                   INT NOT NULL,
  `master_biaya_lain_id`       INT DEFAULT NULL,
  `tagihan_biaya_lain_id`      BIGINT DEFAULT NULL,
  `nama_biaya_snapshot`        VARCHAR(100) NOT NULL,
  `nominal_snapshot`           DECIMAL(15,2) NOT NULL,
  `keterangan`                 VARCHAR(255) DEFAULT NULL,
  `urutan`                     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `legacy_key`                 VARCHAR(10) DEFAULT NULL,
  `created_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_bayar_biaya_lain_legacy` (`bayar_id`, `legacy_key`),
  KEY `idx_bayar_biaya_lain_master` (`master_biaya_lain_id`),
  KEY `idx_bayar_biaya_lain_tagihan` (`tagihan_biaya_lain_id`),
  CONSTRAINT `fk_bayar_biaya_lain_bayar`
    FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_biaya_lain_master`
    FOREIGN KEY (`master_biaya_lain_id`) REFERENCES `master_biaya_lain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_biaya_lain_tagihan`
    FOREIGN KEY (`tagihan_biaya_lain_id`) REFERENCES `tagihan_biaya_lain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Tahun ajaran, penempatan, master, dan tagihan Daftar Ulang
DROP TABLE IF EXISTS `bayar_du`;
DROP TABLE IF EXISTS `daftar_ulang_audit_log`;
DROP TABLE IF EXISTS `tagihan_daftar_ulang`;
DROP TABLE IF EXISTS `Daftar_ulang`;
DROP TABLE IF EXISTS `siswa_tahun_ajaran`;
DROP TABLE IF EXISTS `tahun_ajaran`;
CREATE TABLE `tahun_ajaran` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `label` CHAR(9) NOT NULL,
  `tanggal_mulai` DATE NOT NULL, `tanggal_selesai` DATE NOT NULL,
  `status` ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL, `closed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tahun_ajaran_label` (`label`),
  CONSTRAINT `chk_tahun_ajaran_dates` CHECK (`tanggal_selesai` > `tanggal_mulai`)
) ENGINE=InnoDB;
CREATE TABLE `siswa_tahun_ajaran` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `tahun_ajaran_id` INT NOT NULL,
  `no_induk` VARCHAR(10) NOT NULL, `kelas` VARCHAR(10) NOT NULL,
  `master_kelas_id` INT DEFAULT NULL, `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `spp_perbulan_snapshot` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `spp_covered_by_psb` TINYINT(1) NOT NULL DEFAULT 0,
  `komite_snapshot` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('aktif','pindah','lulus') NOT NULL DEFAULT 'aktif',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_siswa_tahun_ajaran` (`tahun_ajaran_id`,`no_induk`),
  KEY `idx_penempatan_kelas_status` (`tahun_ajaran_id`,`kelas`,`status`),
  KEY `idx_penempatan_siswa` (`no_induk`,`tahun_ajaran_id`),
  KEY `idx_penempatan_master_kelas` (`tahun_ajaran_id`,`master_kelas_id`,`status`),
  CONSTRAINT `fk_penempatan_tahun_ajaran` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_penempatan_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_penempatan_master_kelas` FOREIGN KEY (`master_kelas_id`) REFERENCES `master_kelas`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_penempatan_kelas_sd` CHECK (`kelas` IN ('0','1','2','3','4','5','6','PSB')),
  CONSTRAINT `chk_penempatan_psb_spp` CHECK (`spp_covered_by_psb` IN (0,1) AND (`spp_covered_by_psb` = 0 OR `spp_perbulan_snapshot` = 0))
) ENGINE=InnoDB;
CREATE TABLE `Daftar_ulang` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `tahun_ajaran_id` INT DEFAULT NULL,
  `th_ajaran` CHAR(9) DEFAULT NULL, `kelas` CHAR(1) DEFAULT NULL,
  `Jumlah` DECIMAL(18,2) DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_daftar_ulang_period_class` (`th_ajaran`,`kelas`),
  CONSTRAINT `fk_master_du_tahun` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE `tagihan_daftar_ulang` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `tahun_ajaran_id` INT NOT NULL,
  `penempatan_id` BIGINT NOT NULL, `master_daftar_ulang_id` INT DEFAULT NULL,
  `no_induk` VARCHAR(10) NOT NULL, `kelas_snapshot` VARCHAR(10) NOT NULL,
  `tahun_ajaran_snapshot` CHAR(9) NOT NULL, `nominal_awal` DECIMAL(18,2) NOT NULL,
  `nominal_tagihan` DECIMAL(18,2) NOT NULL, `status` ENUM('open','cancelled') NOT NULL DEFAULT 'open',
  `cancel_reason` VARCHAR(255) DEFAULT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tagihan_du_siswa_tahun` (`tahun_ajaran_id`,`no_induk`),
  KEY `idx_tagihan_du_status` (`tahun_ajaran_id`,`kelas_snapshot`,`status`),
  KEY `idx_tagihan_du_penempatan` (`penempatan_id`),
  KEY `idx_tagihan_du_master` (`master_daftar_ulang_id`),
  CONSTRAINT `fk_tagihan_du_tahun` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_du_penempatan` FOREIGN KEY (`penempatan_id`) REFERENCES `siswa_tahun_ajaran`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_du_master` FOREIGN KEY (`master_daftar_ulang_id`) REFERENCES `Daftar_ulang`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_du_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_tagihan_du_nominal` CHECK (`nominal_awal` >= 0 AND `nominal_tagihan` >= 0),
  CONSTRAINT `chk_tagihan_du_kelas` CHECK (`kelas_snapshot` IN ('0','1','2','3','4','5','6','PSB'))
) ENGINE=InnoDB;
CREATE TABLE `daftar_ulang_audit_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `tahun_ajaran_id` INT DEFAULT NULL, `master_id` INT DEFAULT NULL,
  `aksi` VARCHAR(40) NOT NULL, `before_data` LONGTEXT DEFAULT NULL, `after_data` LONGTEXT DEFAULT NULL,
  `affected_count` INT NOT NULL DEFAULT 0, `admin_id` INT DEFAULT NULL, `admin_name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_du_audit_year` (`tahun_ajaran_id`,`created_at`),
  KEY `idx_du_audit_master` (`master_id`,`created_at`),
  CONSTRAINT `fk_du_audit_year` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_du_audit_master` FOREIGN KEY (`master_id`) REFERENCES `Daftar_ulang`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_du_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE `bayar_du` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `bayar_id` INT DEFAULT NULL,
  `tagihan_daftar_ulang_id` BIGINT DEFAULT NULL, `no_induk` VARCHAR(50) DEFAULT NULL,
  `kelas` VARCHAR(5) DEFAULT NULL, `th_ajaran` CHAR(9) DEFAULT NULL,
  `jumlah` DECIMAL(18,2) DEFAULT 0,
  UNIQUE KEY `uk_bayar_du_bayar_id` (`bayar_id`), KEY `idx_bayar_du_tagihan` (`tagihan_daftar_ulang_id`),
  FOREIGN KEY (`no_induk`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_du_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_du_tagihan` FOREIGN KEY (`tagihan_daftar_ulang_id`) REFERENCES `tagihan_daftar_ulang`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS `bayar_tahunan_siswa`;
DROP TABLE IF EXISTS `tagihan_tahunan_siswa`;
CREATE TABLE `tagihan_tahunan_siswa` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tahun_ajaran_id` INT NOT NULL,
  `penempatan_id` BIGINT DEFAULT NULL,
  `no_induk` VARCHAR(10) NOT NULL,
  `komponen` VARCHAR(30) NOT NULL,
  `nama_snapshot` VARCHAR(100) NOT NULL,
  `kelas_snapshot` VARCHAR(10) NOT NULL,
  `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `tahun_ajaran_snapshot` CHAR(9) NOT NULL,
  `nominal_awal` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `potongan` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `nominal_tagihan` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('open','cancelled') NOT NULL DEFAULT 'open',
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tagihan_tahunan_siswa` (`tahun_ajaran_id`,`no_induk`,`komponen`),
  KEY `idx_tagihan_tahunan_penempatan` (`penempatan_id`,`komponen`),
  KEY `idx_tagihan_tahunan_siswa_status` (`no_induk`,`tahun_ajaran_id`,`status`),
  KEY `idx_tagihan_tahunan_component` (`tahun_ajaran_id`,`komponen`,`status`),
  CONSTRAINT `fk_tagihan_tahunan_tahun` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_tahunan_penempatan` FOREIGN KEY (`penempatan_id`) REFERENCES `siswa_tahun_ajaran`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_tahunan_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_tagihan_tahunan_nominal` CHECK (`nominal_awal` >= 0 AND `potongan` >= 0 AND `nominal_tagihan` >= 0),
  CONSTRAINT `chk_tagihan_tahunan_kelas` CHECK (`kelas_snapshot` IN ('0','1','2','3','4','5','6','PSB'))
) ENGINE=InnoDB;

CREATE TABLE `bayar_tahunan_siswa` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `bayar_id` INT NOT NULL,
  `tagihan_tahunan_id` BIGINT NOT NULL,
  `no_induk` VARCHAR(10) NOT NULL,
  `komponen` VARCHAR(30) NOT NULL,
  `th_ajaran` CHAR(9) NOT NULL,
  `jumlah` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_bayar_tahunan_bayar_component` (`bayar_id`,`komponen`),
  KEY `idx_bayar_tahunan_tagihan` (`tagihan_tahunan_id`),
  KEY `idx_bayar_tahunan_siswa` (`no_induk`,`th_ajaran`,`komponen`),
  CONSTRAINT `fk_bayar_tahunan_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_tahunan_tagihan` FOREIGN KEY (`tagihan_tahunan_id`) REFERENCES `tagihan_tahunan_siswa`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_tahunan_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_bayar_tahunan_jumlah` CHECK (`jumlah` >= 0)
) ENGINE=InnoDB;

INSERT INTO `tahun_ajaran` (`label`, `tanggal_mulai`, `tanggal_selesai`, `status`, `published_at`) VALUES
('2026/2027', '2026-07-01', '2027-06-30', 'published', NOW());

INSERT INTO `Daftar_ulang` (`tahun_ajaran_id`, `th_ajaran`, `kelas`, `Jumlah`)
SELECT ta.id, ta.label, kelas_data.kelas, kelas_data.jumlah
FROM tahun_ajaran ta
JOIN (
  SELECT '1' AS kelas, 1000000 AS jumlah UNION ALL
  SELECT '2', 1100000 UNION ALL
  SELECT '3', 1200000 UNION ALL
  SELECT '4', 1300000 UNION ALL
  SELECT '5', 1400000 UNION ALL
  SELECT '6', 1500000
) kelas_data
WHERE ta.label = '2026/2027';

INSERT INTO `siswa_tahun_ajaran` (`tahun_ajaran_id`, `no_induk`, `kelas`, `master_kelas_id`, `kelas_rombel_snapshot`, `spp_perbulan_snapshot`, `komite_snapshot`, `status`)
SELECT ta.id, s.NO_INDUK, s.KELAS, s.master_kelas_id,
       CASE WHEN mk.tingkat = 0 THEN 'PSB' ELSE CONCAT(mk.tingkat, UPPER(mk.kode_rombel)) END,
       s.SPP_PERBULAN, s.POMG, 'aktif'
FROM siswa s
JOIN tahun_ajaran ta ON ta.label = '2026/2027'
LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
WHERE COALESCE(mk.tingkat,CAST(s.KELAS AS UNSIGNED))>0;

INSERT INTO `tagihan_daftar_ulang` (
  `tahun_ajaran_id`, `penempatan_id`, `master_daftar_ulang_id`,
  `no_induk`, `kelas_snapshot`, `tahun_ajaran_snapshot`, `nominal_awal`, `nominal_tagihan`
)
SELECT ta.id, sta.id, du.id, s.NO_INDUK, s.KELAS, ta.label,
       COALESCE(NULLIF(s.DAFTAR_ULANG, 0), du.Jumlah, 0),
       COALESCE(NULLIF(s.tot_du, 0), NULLIF(s.DAFTAR_ULANG - s.potong_du, 0), du.Jumlah, 0)
FROM siswa s
JOIN tahun_ajaran ta ON ta.label = '2026/2027'
JOIN siswa_tahun_ajaran sta ON sta.tahun_ajaran_id = ta.id AND sta.no_induk = s.NO_INDUK
LEFT JOIN Daftar_ulang du ON du.tahun_ajaran_id = ta.id AND du.kelas = s.KELAS;

INSERT INTO `tagihan_tahunan_siswa` (
  `tahun_ajaran_id`, `penempatan_id`, `no_induk`, `komponen`, `nama_snapshot`,
  `kelas_snapshot`, `kelas_rombel_snapshot`, `tahun_ajaran_snapshot`,
  `nominal_awal`, `potongan`, `nominal_tagihan`, `status`, `created_by`
)
SELECT ta.id, sta.id, s.NO_INDUK, fees.komponen, s.NAMA, sta.kelas,
       sta.kelas_rombel_snapshot, ta.label, fees.nominal_awal, fees.potongan,
       fees.nominal_tagihan, 'open', 'schema'
FROM siswa s
JOIN tahun_ajaran ta ON ta.label = '2026/2027'
JOIN siswa_tahun_ajaran sta ON sta.tahun_ajaran_id = ta.id AND sta.no_induk = s.NO_INDUK
JOIN (
  SELECT 'komite' AS komponen, sf.`NO_INDUK`, sf.`POMG` AS nominal_awal, 0 AS potongan, sf.`POMG` AS nominal_tagihan FROM `siswa` sf
) fees ON fees.NO_INDUK = s.NO_INDUK;

-- Tabel Tabungan
DROP TABLE IF EXISTS `tabungan`;
CREATE TABLE `tabungan` (
  `id`       INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK` VARCHAR(10) NOT NULL UNIQUE,
  `SALDO`    DOUBLE DEFAULT 0,
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_tabungan_saldo_nonnegative` CHECK (`SALDO` >= 0)
) ENGINE=InnoDB;

-- Tabel Transaksi Masuk
DROP TABLE IF EXISTS `transaksi_m`;
CREATE TABLE `transaksi_m` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `bayar_id`  INT DEFAULT NULL,
  `NO_INDUK`  VARCHAR(10) DEFAULT NULL,
  `TANGGAL`   DATETIME DEFAULT NULL,
  `MASUK`     DOUBLE DEFAULT 0,
  `KELUAR`    DOUBLE DEFAULT 0,
  `user_id`   VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY `uk_transaksi_m_bayar_id` (`bayar_id`),
  KEY `idx_transaksi_m_tanggal_user` (`TANGGAL`,`user_id`),
  KEY `idx_transaksi_m_siswa_tanggal` (`NO_INDUK`,`TANGGAL`),
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transaksi_m_bayar`
    FOREIGN KEY (`bayar_id`) REFERENCES `bayar`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Tabel Transaksi Keluar
DROP TABLE IF EXISTS `transaksi_k`;
CREATE TABLE `transaksi_k` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK`  VARCHAR(10) DEFAULT NULL,
  `TANGGAL`   DATETIME DEFAULT NULL,
  `MASUK`     DOUBLE DEFAULT 0,
  `KELUAR`    DOUBLE DEFAULT 0,
  `user_id`   VARCHAR(100) DEFAULT NULL,
  KEY `idx_transaksi_k_tanggal_user` (`TANGGAL`,`user_id`),
  KEY `idx_transaksi_k_siswa_tanggal` (`NO_INDUK`,`TANGGAL`),
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Hapus tabel lama jika ada
DROP TABLE IF EXISTS `pembayaran`;

SET FOREIGN_KEY_CHECKS = 1;
