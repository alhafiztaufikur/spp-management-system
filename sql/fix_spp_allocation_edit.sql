-- Memungkinkan administrator mengedit pembayaran SPP tanpa menghapus histori
-- batch lama yang sudah berstatus reversed. Aman dijalankan ulang.
SET @has_old_spp_batch_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'spp_alokasi_batch'
    AND INDEX_NAME = 'uk_spp_alokasi_batch_bayar'
);
SET @drop_old_spp_batch_index := IF(
  @has_old_spp_batch_index > 0,
  'ALTER TABLE `spp_alokasi_batch` DROP INDEX `uk_spp_alokasi_batch_bayar`',
  'SELECT 1'
);
PREPARE spp_batch_stmt FROM @drop_old_spp_batch_index;
EXECUTE spp_batch_stmt;
DEALLOCATE PREPARE spp_batch_stmt;

SET @has_new_spp_batch_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'spp_alokasi_batch'
    AND INDEX_NAME = 'idx_spp_alokasi_batch_bayar_status'
);
SET @add_new_spp_batch_index := IF(
  @has_new_spp_batch_index = 0,
  'ALTER TABLE `spp_alokasi_batch` ADD KEY `idx_spp_alokasi_batch_bayar_status` (`bayar_id`, `status`)',
  'SELECT 1'
);
PREPARE spp_batch_stmt FROM @add_new_spp_batch_index;
EXECUTE spp_batch_stmt;
DEALLOCATE PREPARE spp_batch_stmt;
