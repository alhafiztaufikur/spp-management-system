-- Master penerbitan SPP, tagihan bulanan, alokasi, dan titipan SPP.
-- Jalankan setelah schema akademik/PSB tersedia.

ALTER TABLE `siswa`
  ADD COLUMN IF NOT EXISTS `potongan_spp_persen` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `SPP_PERBULAN`;

ALTER TABLE `bayar`
  ADD COLUMN IF NOT EXISTS `U_TITIPAN_SPP` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `U_SPP`;

CREATE TABLE IF NOT EXISTS `master_spp_tahun` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tahun_ajaran_id` INT NOT NULL,
  `status` ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_master_spp_tahun` (`tahun_ajaran_id`),
  CONSTRAINT `fk_master_spp_tahun_ajaran` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `master_spp_tarif` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `master_spp_tahun_id` INT NOT NULL,
  `tingkat` TINYINT UNSIGNED NOT NULL,
  `nominal_dasar` DECIMAL(15,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_master_spp_tarif_tingkat` (`master_spp_tahun_id`,`tingkat`),
  CONSTRAINT `fk_master_spp_tarif_tahun` FOREIGN KEY (`master_spp_tahun_id`) REFERENCES `master_spp_tahun` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_master_spp_tingkat` CHECK (`tingkat` BETWEEN 1 AND 6),
  CONSTRAINT `chk_master_spp_nominal` CHECK (`nominal_dasar` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tagihan_spp` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `master_spp_tahun_id` INT NOT NULL,
  `tahun_ajaran_id` INT NOT NULL,
  `penempatan_id` BIGINT NOT NULL,
  `no_induk` VARCHAR(10) NOT NULL,
  `tingkat_snapshot` TINYINT UNSIGNED NOT NULL,
  `master_kelas_id` INT DEFAULT NULL,
  `kelas_rombel_snapshot` VARCHAR(30) DEFAULT NULL,
  `bulan` CHAR(2) NOT NULL,
  `tahun` CHAR(4) NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `spp_alokasi_batch` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `no_induk` VARCHAR(10) NOT NULL,
  `bayar_id` INT DEFAULT NULL,
  `tanggal` DATETIME NOT NULL,
  `user_id` VARCHAR(100) DEFAULT NULL,
  `gunakan_titipan` TINYINT(1) NOT NULL DEFAULT 0,
  `uang_baru` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `titipan_digunakan` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `titipan_baru` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('active','reversed') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_spp_alokasi_batch_bayar` (`bayar_id`),
  KEY `idx_spp_alokasi_batch_siswa` (`no_induk`,`tanggal`),
  CONSTRAINT `fk_spp_alokasi_batch_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_spp_alokasi_batch_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_spp_alokasi_batch_nominal` CHECK (`uang_baru` >= 0 AND `titipan_digunakan` >= 0 AND `titipan_baru` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `spp_alokasi` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `batch_id` BIGINT NOT NULL,
  `tagihan_spp_id` BIGINT NOT NULL,
  `nominal_dari_bayar` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `nominal_dari_titipan` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_spp_alokasi_batch_tagihan` (`batch_id`,`tagihan_spp_id`),
  KEY `idx_spp_alokasi_tagihan` (`tagihan_spp_id`),
  CONSTRAINT `fk_spp_alokasi_batch` FOREIGN KEY (`batch_id`) REFERENCES `spp_alokasi_batch` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_spp_alokasi_tagihan` FOREIGN KEY (`tagihan_spp_id`) REFERENCES `tagihan_spp` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_spp_alokasi_nominal` CHECK (`nominal_dari_bayar` >= 0 AND `nominal_dari_titipan` >= 0 AND (`nominal_dari_bayar` + `nominal_dari_titipan`) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `titipan_spp_mutasi` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `no_induk` VARCHAR(10) NOT NULL,
  `batch_id` BIGINT DEFAULT NULL,
  `bayar_id` INT DEFAULT NULL,
  `jenis` ENUM('masuk','pakai','pengembalian','koreksi_masuk','koreksi_keluar') NOT NULL,
  `nominal` DECIMAL(15,2) NOT NULL,
  `tanggal` DATETIME NOT NULL,
  `sistem_pembayaran` ENUM('Tunai','VA','Qris') DEFAULT NULL,
  `user_id` VARCHAR(100) DEFAULT NULL,
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_titipan_spp_siswa_tanggal` (`no_induk`,`tanggal`,`id`),
  KEY `idx_titipan_spp_batch` (`batch_id`),
  CONSTRAINT `fk_titipan_spp_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_titipan_spp_batch` FOREIGN KEY (`batch_id`) REFERENCES `spp_alokasi_batch` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_titipan_spp_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_titipan_spp_nominal` CHECK (`nominal` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `spp_audit_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `master_spp_tahun_id` INT DEFAULT NULL,
  `no_induk` VARCHAR(10) DEFAULT NULL,
  `aksi` VARCHAR(40) NOT NULL,
  `before_data` LONGTEXT DEFAULT NULL,
  `after_data` LONGTEXT DEFAULT NULL,
  `affected_count` INT NOT NULL DEFAULT 0,
  `admin_id` INT DEFAULT NULL,
  `admin_name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_spp_audit_tahun` (`master_spp_tahun_id`,`created_at`),
  KEY `idx_spp_audit_siswa` (`no_induk`,`created_at`),
  CONSTRAINT `fk_spp_audit_tahun` FOREIGN KEY (`master_spp_tahun_id`) REFERENCES `master_spp_tahun` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_spp_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
