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
  `session_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `password_reset_required` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Telemetri pembatasan login. Bucket account/source/pair disimpan sebagai HMAC;
-- password, username, dan alamat source tidak disimpan dalam bentuk plaintext.
CREATE TABLE IF NOT EXISTS `login_rate_limit` (
  `bucket_hash`       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  `failure_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `window_started_at` DATETIME NOT NULL,
  `blocked_until`     DATETIME DEFAULT NULL,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_login_rate_limit_cleanup` (`updated_at`),
  KEY `idx_login_rate_limit_blocked` (`blocked_until`)
) ENGINE=InnoDB;

-- Klaim idempotency ikut commit/rollback bersama mutasi finansial. Token yang
-- sudah committed tidak dapat dipakai ulang, termasuk dengan payload berbeda.
CREATE TABLE IF NOT EXISTS `mutation_request` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`          VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_key`    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_admin_id` INT DEFAULT NULL,
  `created_at`     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mutation_request_scope_key` (`scope`, `request_key`),
  KEY `idx_mutation_request_actor_time` (`actor_admin_id`, `created_at`),
  KEY `idx_mutation_request_created_at` (`created_at`)
) ENGINE=InnoDB;

-- actor_admin_id adalah snapshot tanpa FK agar penghapusan akun tidak membuka
-- kembali token historis.

-- Jejak append-only untuk perubahan finansial dan akun. Password, token,
-- cookie, dan secret tidak boleh ditulis ke payload JSON.
CREATE TABLE IF NOT EXISTS `audit_event` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type`          VARCHAR(50) NOT NULL,
  `entity_type`         VARCHAR(40) NOT NULL,
  `entity_id`           VARCHAR(64) DEFAULT NULL,
  `action`              VARCHAR(40) NOT NULL,
  `actor_admin_id`      INT DEFAULT NULL,
  `actor_name_snapshot` VARCHAR(100) NOT NULL,
  `request_id`          CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason`              VARCHAR(255) DEFAULT NULL,
  `before_data`         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `after_data`          LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `metadata`            LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at`          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_audit_event_entity` (`entity_type`, `entity_id`, `created_at`),
  KEY `idx_audit_event_actor` (`actor_admin_id`, `created_at`),
  KEY `idx_audit_event_type_time` (`event_type`, `created_at`),
  CONSTRAINT `chk_audit_event_before_json`
    CHECK (`before_data` IS NULL OR JSON_VALID(`before_data`)),
  CONSTRAINT `chk_audit_event_after_json`
    CHECK (`after_data` IS NULL OR JSON_VALID(`after_data`)),
  CONSTRAINT `chk_audit_event_metadata_json`
    CHECK (`metadata` IS NULL OR JSON_VALID(`metadata`))
) ENGINE=InnoDB;

-- actor_admin_id adalah snapshot historis tanpa FK. Menghapus akun tidak boleh
-- mengubah event audit lama; nama/username/role juga disimpan sebagai snapshot.

DELIMITER $$
CREATE OR REPLACE TRIGGER `trg_audit_event_no_update`
BEFORE UPDATE ON `audit_event`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_event is append-only';
END$$

CREATE OR REPLACE TRIGGER `trg_audit_event_no_delete`
BEFORE DELETE ON `audit_event`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_event is append-only';
END$$
DELIMITER ;

-- Tidak ada akun/password default bersama. Akun administrator pertama wajib
-- diprovisikan secara out-of-band dengan password_hash() sebelum aplikasi aktif.

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
  CONSTRAINT `chk_master_kelas_tingkat` CHECK (`tingkat` BETWEEN 1 AND 6)
) ENGINE=InnoDB;
INSERT INTO `master_kelas` (`tingkat`,`kode_rombel`,`is_placeholder`,`is_active`) VALUES
(1,'BELUM',1,1),(2,'BELUM',1,1),(3,'BELUM',1,1),(4,'BELUM',1,1),(5,'BELUM',1,1),(6,'BELUM',1,1);


-- Tabel Siswa (Revisi Baru)
DROP TABLE IF EXISTS `siswa`;
CREATE TABLE `siswa` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK`        VARCHAR(10) NOT NULL UNIQUE,
  `NAMA`            VARCHAR(100) NOT NULL,
  `KELAS`           CHAR(1) NOT NULL,
  `master_kelas_id` INT DEFAULT NULL,
  `SPP_PERBULAN`    DECIMAL(15,2) NOT NULL DEFAULT 0,
  `PANGKAL`         DECIMAL(15,2) NOT NULL DEFAULT 0,
  `BANGUNAN`        DECIMAL(15,2) NOT NULL DEFAULT 0,
  `SERAGAM`         DECIMAL(15,2) NOT NULL DEFAULT 0,
  `KEGIATAN`        DECIMAL(15,2) NOT NULL DEFAULT 0,
  `MAKAN`           DECIMAL(15,2) NOT NULL DEFAULT 0,
  `SORGA`           DECIMAL(15,2) NOT NULL DEFAULT 0,
  `INFAQ`           DECIMAL(15,2) NOT NULL DEFAULT 0,
  `PANGKAL_BAYAR`   DECIMAL(15,2) NOT NULL DEFAULT 0,
  `BANGUNAN_BAYAR`  DECIMAL(15,2) NOT NULL DEFAULT 0,
  `SERAGAM_BAYAR`   DECIMAL(15,2) NOT NULL DEFAULT 0,
  `KEGIATAN_BAYAR`  DECIMAL(15,2) NOT NULL DEFAULT 0,
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
  CONSTRAINT `chk_siswa_kelas_sd` CHECK (`KELAS` IN ('1','2','3','4','5','6'))
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
  `PANGKAL`, `BANGUNAN`, `SERAGAM`, `KEGIATAN`, `MAKAN`, `SORGA`, `INFAQ`,
  `POMG`, `DAFTAR_ULANG`, `tot_pangkal`, `tot_du`
) VALUES
('2024001', NULL,      'Ahmad Fauzi',             '1', 250000, 1000000, 1500000, 500000, 300000,      0,     0,     0,      0, 1000000, 1000000, 1000000),
('2024002', NULL,      'Siti Rahayu',             '2', 250000, 1000000, 1500000, 500000, 300000,      0,     0,     0,      0, 1100000, 1000000, 1100000),
('2024003', NULL,      'Budi Santoso',            '3', 275000, 1000000, 1500000, 500000, 300000,      0,     0,     0,      0, 1200000, 1000000, 1200000),
('2024004', NULL,      'Dewi Lestari',            '4', 275000, 1000000, 1500000, 500000, 300000,      0,     0,     0,      0, 1300000, 1000000, 1300000),
('2024005', NULL,      'Muhammad Rizky',          '5', 300000, 1000000, 1500000, 500000, 300000,      0,     0,     0,      0, 1400000, 1000000, 1400000),
('2024006', NULL,      'Ayu Putri',               '6', 300000, 1000000, 1500000, 500000, 300000, 200000, 70000, 40000, 150000, 1500000, 1000000, 1500000),
('2026101', 'D260101', 'Alya Nabila Demo',        '1', 250000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000, 100000, 1000000, 1000000, 1000000),
('2026102', 'D260102', 'Rafi Pratama Demo',       '1', 250000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000, 100000, 1000000, 1000000, 1000000),
('2026103', 'D260103', 'Kirana Putri Demo',       '2', 260000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000, 110000, 1100000, 1100000, 1100000),
('2026104', 'D260104', 'Bagas Saputra Demo',      '2', 260000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000, 110000, 1100000, 1100000, 1100000),
('2026105', 'D260105', 'Naya Ramadhani Demo',     '3', 275000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000, 120000, 1200000, 1200000, 1200000),
('2026106', 'D260106', 'Dimas Arya Demo',         '3', 275000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000, 120000, 1200000, 1200000, 1200000),
('2026107', 'D260107', 'Salsa Azzahra Demo',      '4', 290000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000, 130000, 1300000, 1300000, 1300000),
('2026108', 'D260108', 'Fadli Maulana Demo',      '4', 290000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000, 130000, 1300000, 1300000, 1300000),
('2026109', 'D260109', 'Citra Maharani Demo',     '5', 305000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000, 140000, 1400000, 1400000, 1400000),
('2026110', 'D260110', 'Farhan Hafizh Demo',      '6', 320000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000, 150000, 1500000, 1500000, 1500000),
('2026111', 'D260111', 'Andika Pratama Demo',     '1', 250000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000, 100000, 1000000, 1000000, 1000000),
('2026112', 'D260112', 'Mira Aulia Demo',         '1', 250000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000, 100000, 1000000, 1000000, 1000000),
('2026113', 'D260113', 'Rizky Ramadhan Demo',     '2', 260000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000, 110000, 1100000, 1100000, 1100000),
('2026114', 'D260114', 'Tiara Safitri Demo',      '2', 260000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000, 110000, 1100000, 1100000, 1100000),
('2026115', 'D260115', 'Gilang Saputra Demo',     '3', 275000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000, 120000, 1200000, 1200000, 1200000),
('2026116', 'D260116', 'Putri Amelia Demo',       '3', 275000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000, 120000, 1200000, 1200000, 1200000),
('2026117', 'D260117', 'Raka Firmansyah Demo',    '4', 290000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000, 130000, 1300000, 1300000, 1300000),
('2026118', 'D260118', 'Zahra Nuraini Demo',      '4', 290000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000, 130000, 1300000, 1300000, 1300000),
('2026119', 'D260119', 'Hafiz Alfarizi Demo',     '5', 305000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000, 140000, 1400000, 1400000, 1400000),
('2026120', 'D260120', 'Laras Puspita Demo',      '5', 305000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000, 140000, 1400000, 1400000, 1400000),
('2026121', 'D260121', 'Naufal Akbar Demo',       '5', 305000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000, 140000, 1400000, 1400000, 1400000),
('2026122', 'D260122', 'Sabrina Fitri Demo',      '6', 320000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000, 150000, 1500000, 1500000, 1500000),
('2026123', 'D260123', 'Arkan Maulana Demo',      '6', 320000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000, 150000, 1500000, 1500000, 1500000),
('2026124', 'D260124', 'Nadya Khairunnisa Demo',  '6', 320000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000, 150000, 1500000, 1500000, 1500000);

UPDATE `siswa` s JOIN `master_kelas` mk ON mk.tingkat=CAST(s.KELAS AS UNSIGNED) AND mk.is_placeholder=1
SET s.master_kelas_id=mk.id;

-- Tabel Bayar (Revisi Baru)
DROP TABLE IF EXISTS `bayar`;
CREATE TABLE `bayar` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK`    VARCHAR(10) DEFAULT NULL,
  `KELAS`       VARCHAR(5) DEFAULT NULL,
  `master_kelas_id` INT DEFAULT NULL,
  `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `U_PANGKAL`   DOUBLE DEFAULT 0,
  `U_BANGUNAN`  DOUBLE DEFAULT 0,
  `U_SERAGAM`   DOUBLE DEFAULT 0,
  `U_KEGIATAN`  DOUBLE DEFAULT 0,
  `U_SPP`       DOUBLE DEFAULT 0,
  `U_MAKAN`     DOUBLE DEFAULT 0,
  `U_SORGA`     DOUBLE DEFAULT 0,
  `U_INFAQ`     DOUBLE DEFAULT 0,
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
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Pemetaan periode per transaksi SPP. Satu periode boleh memiliki beberapa
-- transaksi cicilan, sementara bayar_id tetap unik per transaksi.
DROP TABLE IF EXISTS `bayar_spp_periode`;
CREATE TABLE `bayar_spp_periode` (
  `bayar_id` INT NOT NULL PRIMARY KEY,
  `no_induk` VARCHAR(10) NOT NULL,
  `bulan` CHAR(2) NOT NULL,
  `tahun` CHAR(4) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_bayar_spp_siswa_periode` (`no_induk`, `tahun`, `bulan`),
  CONSTRAINT `fk_bayar_spp_periode_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_spp_periode_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
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
  `no_induk` VARCHAR(10) NOT NULL, `kelas` CHAR(1) NOT NULL,
  `master_kelas_id` INT DEFAULT NULL, `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `spp_perbulan_snapshot` DECIMAL(15,2) NOT NULL DEFAULT 0,
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
  CONSTRAINT `chk_penempatan_kelas_sd` CHECK (`kelas` IN ('1','2','3','4','5','6'))
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
  `no_induk` VARCHAR(10) NOT NULL, `kelas_snapshot` CHAR(1) NOT NULL,
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
  CONSTRAINT `chk_tagihan_du_kelas` CHECK (`kelas_snapshot` IN ('1','2','3','4','5','6'))
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
SELECT ta.id, s.NO_INDUK, s.KELAS, s.master_kelas_id, CONCAT('Kelas ',s.KELAS,' (Belum Ditentukan)'), s.SPP_PERBULAN, s.POMG, 'aktif'
FROM siswa s
JOIN tahun_ajaran ta ON ta.label = '2026/2027';

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

-- Tabel Tabungan
DROP TABLE IF EXISTS `tabungan`;
CREATE TABLE `tabungan` (
  `id`       INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK` VARCHAR(10) NOT NULL UNIQUE,
  `SALDO`    DOUBLE DEFAULT 0,
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
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
