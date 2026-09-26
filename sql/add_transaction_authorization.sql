USE `db_spp`;

CREATE TABLE IF NOT EXISTS `transaksi_otorisasi` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bayar_id` INT DEFAULT NULL,
  `transaction_reference` VARCHAR(30) NOT NULL,
  `no_induk_snapshot` VARCHAR(10) DEFAULT NULL,
  `student_name_snapshot` VARCHAR(100) NOT NULL,
  `action` ENUM('edit','hapus') NOT NULL,
  `status` ENUM('pending','approved','rejected','cancelled','failed') NOT NULL DEFAULT 'pending',
  `before_snapshot` LONGTEXT NOT NULL,
  `snapshot_hash` CHAR(64) NOT NULL,
  `proposed_payload` LONGTEXT DEFAULT NULL,
  `request_reason` VARCHAR(500) NOT NULL,
  `requested_by` INT NOT NULL,
  `decided_by` INT DEFAULT NULL,
  `decision_note` VARCHAR(1000) DEFAULT NULL,
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at` DATETIME DEFAULT NULL,
  `applied_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_transaksi_otorisasi_payment_status` (`bayar_id`,`status`),
  KEY `idx_transaksi_otorisasi_status_time` (`status`,`requested_at`),
  KEY `idx_transaksi_otorisasi_requester` (`requested_by`,`requested_at`),
  CONSTRAINT `fk_transaksi_otorisasi_bayar` FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaksi_otorisasi_requester` FOREIGN KEY (`requested_by`) REFERENCES `admin` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transaksi_otorisasi_decider` FOREIGN KEY (`decided_by`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
