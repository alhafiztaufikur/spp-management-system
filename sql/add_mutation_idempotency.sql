-- =========================================================
-- Kunci idempotency untuk mutasi finansial dari form web.
-- Tidak melakukan backfill atau menebak histori lama.
-- Idempoten: aman dijalankan ulang pada database yang sama.
-- =========================================================

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

-- actor_admin_id sengaja tanpa FK. Baris ini adalah bukti klaim historis;
-- penghapusan akun tidak boleh membuka kembali token transaksi lama.
