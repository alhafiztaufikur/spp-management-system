-- =========================================================
-- Verifikasi schema SistemSPP (read-only)
-- Jalankan setelah schema baru atau seluruh migrasi upgrade.
-- =========================================================

USE `db_spp`;

SELECT requirement,
       IF(is_present = 1, 'OK', 'MISSING') AS status
FROM (
  SELECT 'table.bayar' AS requirement,
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
         ) AS is_present
  UNION ALL
  SELECT 'table.bayar_du',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
         )
  UNION ALL
  SELECT 'table.transaksi_m',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
         )
  UNION ALL
  SELECT 'table.master_biaya_lain',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'master_biaya_lain'
         )
  UNION ALL
  SELECT 'table.Daftar_ulang',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'daftar_ulang'
         )
  UNION ALL
  SELECT 'table.bayar_biaya_lain',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_biaya_lain'
         )
  UNION ALL
  SELECT 'table.siswa_audit_log',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa_audit_log'
         )
  UNION ALL
  SELECT 'admin.role',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin'
             AND COLUMN_NAME = 'role'
         )
  UNION ALL
  SELECT 'siswa.is_active',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
             AND COLUMN_NAME = 'is_active'
         )
  UNION ALL
  SELECT 'siswa.MAKAN',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
             AND COLUMN_NAME = 'MAKAN'
         )
  UNION ALL
  SELECT 'siswa.SORGA',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
             AND COLUMN_NAME = 'SORGA'
         )
  UNION ALL
  SELECT 'siswa.INFAQ',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
             AND COLUMN_NAME = 'INFAQ'
         )
  UNION ALL
  SELECT 'bayar.U_KOMITE',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
             AND COLUMN_NAME = 'U_KOMITE'
         )
  UNION ALL
  SELECT 'bayar.sistem_pembayaran',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
             AND COLUMN_NAME = 'sistem_pembayaran'
         )
  UNION ALL
  SELECT 'bayar.payment_link_version' AS requirement,
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
             AND COLUMN_NAME = 'payment_link_version'
         ) AS is_present
  UNION ALL
  SELECT 'bayar.payment_batch_token',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
             AND COLUMN_NAME = 'payment_batch_token'
         )
  UNION ALL
  SELECT 'table.tahun_ajaran', EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tahun_ajaran')
  UNION ALL
  SELECT 'table.siswa_tahun_ajaran', EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_tahun_ajaran')
  UNION ALL
  SELECT 'table.tagihan_daftar_ulang', EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tagihan_daftar_ulang')
  UNION ALL
  SELECT 'table.daftar_ulang_audit_log', EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='daftar_ulang_audit_log')
  UNION ALL
  SELECT 'bayar_du.tagihan_daftar_ulang_id', EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar_du' AND COLUMN_NAME='tagihan_daftar_ulang_id')
  UNION ALL
  SELECT 'bayar_spp_periode',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_spp_periode'
         )
  UNION ALL
  SELECT 'idx_bayar_spp_siswa_periode',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_spp_periode'
             AND INDEX_NAME = 'idx_bayar_spp_siswa_periode' AND NON_UNIQUE = 1
         )
  UNION ALL
  SELECT 'uk_siswa_no_induk_diknas', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa'
      AND INDEX_NAME='uk_siswa_no_induk_diknas' AND NON_UNIQUE=0
  )
  UNION ALL
  SELECT 'bayar.KETERANGAN_255', EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar'
      AND COLUMN_NAME='KETERANGAN' AND CHARACTER_MAXIMUM_LENGTH >= 255
  )
  UNION ALL
  SELECT 'bayar.LAIN_LAIN1_100', EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar'
      AND COLUMN_NAME='LAIN_LAIN1' AND CHARACTER_MAXIMUM_LENGTH >= 100
  )
  UNION ALL
  SELECT 'bayar.LAIN_LAIN2_100', EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar'
      AND COLUMN_NAME='LAIN_LAIN2' AND CHARACTER_MAXIMUM_LENGTH >= 100
  )
  UNION ALL
  SELECT 'bayar.LAIN_LAIN3_100', EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar'
      AND COLUMN_NAME='LAIN_LAIN3' AND CHARACTER_MAXIMUM_LENGTH >= 100
  )
  UNION ALL
  SELECT 'bayar.LAIN_LAIN4_100', EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar'
      AND COLUMN_NAME='LAIN_LAIN4' AND CHARACTER_MAXIMUM_LENGTH >= 100
  )
  UNION ALL
  SELECT 'bayar.user_id_100', EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar'
      AND COLUMN_NAME='user_id' AND CHARACTER_MAXIMUM_LENGTH >= 100
  )
  UNION ALL
  SELECT 'transaksi_m.user_id_100', EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transaksi_m'
      AND COLUMN_NAME='user_id' AND CHARACTER_MAXIMUM_LENGTH >= 100
  )
  UNION ALL
  SELECT 'transaksi_k.user_id_100', EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transaksi_k'
      AND COLUMN_NAME='user_id' AND CHARACTER_MAXIMUM_LENGTH >= 100
  )
  UNION ALL
  SELECT 'bayar_du.bayar_id',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
             AND COLUMN_NAME = 'bayar_id'
         )
  UNION ALL
  SELECT 'transaksi_m.bayar_id',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
             AND COLUMN_NAME = 'bayar_id'
         )
  UNION ALL
  SELECT 'uk_bayar_du_bayar_id',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
             AND INDEX_NAME = 'uk_bayar_du_bayar_id' AND NON_UNIQUE = 0
         )
  UNION ALL
  SELECT 'uk_transaksi_m_bayar_id',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
             AND INDEX_NAME = 'uk_transaksi_m_bayar_id' AND NON_UNIQUE = 0
         )
  UNION ALL
  SELECT 'uk_daftar_ulang_period_class',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'daftar_ulang'
             AND INDEX_NAME = 'uk_daftar_ulang_period_class' AND NON_UNIQUE = 0
         )
  UNION ALL
  SELECT 'uk_siswa_tahun_ajaran', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='siswa_tahun_ajaran' AND INDEX_NAME='uk_siswa_tahun_ajaran' AND NON_UNIQUE=0
  )
  UNION ALL
  SELECT 'uk_tahun_ajaran_label', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='tahun_ajaran' AND INDEX_NAME='uk_tahun_ajaran_label' AND NON_UNIQUE=0
  )
  UNION ALL
  SELECT 'idx_penempatan_siswa', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='siswa_tahun_ajaran' AND INDEX_NAME='idx_penempatan_siswa'
  )
  UNION ALL
  SELECT 'idx_tagihan_du_penempatan', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='tagihan_daftar_ulang' AND INDEX_NAME='idx_tagihan_du_penempatan'
  )
  UNION ALL
  SELECT 'idx_tagihan_du_master', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='tagihan_daftar_ulang' AND INDEX_NAME='idx_tagihan_du_master'
  )
  UNION ALL
  SELECT 'idx_du_audit_year', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='daftar_ulang_audit_log' AND INDEX_NAME='idx_du_audit_year'
  )
  UNION ALL
  SELECT 'idx_du_audit_master', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='daftar_ulang_audit_log' AND INDEX_NAME='idx_du_audit_master'
  )
  UNION ALL
  SELECT 'uk_tagihan_du_siswa_tahun', EXISTS(
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='tagihan_daftar_ulang' AND INDEX_NAME='uk_tagihan_du_siswa_tahun' AND NON_UNIQUE=0
  )
  UNION ALL
  SELECT 'chk_tahun_ajaran_dates', EXISTS(
    SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
      AND CONSTRAINT_NAME='chk_tahun_ajaran_dates'
  )
  UNION ALL
  SELECT 'chk_penempatan_kelas_sd', EXISTS(
    SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
      AND CONSTRAINT_NAME='chk_penempatan_kelas_sd'
  )
  UNION ALL
  SELECT 'chk_tagihan_du_nominal', EXISTS(
    SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
      AND CONSTRAINT_NAME='chk_tagihan_du_nominal'
  )
  UNION ALL
  SELECT 'chk_tagihan_du_kelas', EXISTS(
    SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
      AND CONSTRAINT_NAME='chk_tagihan_du_kelas'
  )
  UNION ALL
  SELECT 'chk_tabungan_saldo_nonnegative', EXISTS(
    SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
      AND CONSTRAINT_NAME='chk_tabungan_saldo_nonnegative'
  )
  UNION ALL
  SELECT 'fk_bayar_du_tagihan', EXISTS(
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
      AND TABLE_NAME='bayar_du' AND CONSTRAINT_NAME='fk_bayar_du_tagihan' AND CONSTRAINT_TYPE='FOREIGN KEY'
  )
  UNION ALL
  SELECT 'table.master_kelas', EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_kelas')
  UNION ALL
  SELECT 'siswa.master_kelas_id', EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa' AND COLUMN_NAME='master_kelas_id')
  UNION ALL
  SELECT 'siswa_tahun_ajaran.kelas_rombel_snapshot', EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_tahun_ajaran' AND COLUMN_NAME='kelas_rombel_snapshot')
  UNION ALL
  SELECT 'siswa_tahun_ajaran.spp_perbulan_snapshot', EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_tahun_ajaran' AND COLUMN_NAME='spp_perbulan_snapshot')
  UNION ALL
  SELECT 'siswa_tahun_ajaran.komite_snapshot', EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_tahun_ajaran' AND COLUMN_NAME='komite_snapshot')
  UNION ALL
  SELECT 'bayar.kelas_rombel_snapshot', EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar' AND COLUMN_NAME='kelas_rombel_snapshot')
  UNION ALL
  SELECT 'table.tagihan_biaya_lain', EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tagihan_biaya_lain')
  UNION ALL
  SELECT 'table.tagihan_biaya_lain_audit_log', EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tagihan_biaya_lain_audit_log')
  UNION ALL
  SELECT 'bayar_biaya_lain.tagihan_biaya_lain_id', EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar_biaya_lain' AND COLUMN_NAME='tagihan_biaya_lain_id')
  UNION ALL
  SELECT 'uk_tagihan_biaya_lain_siswa_master', EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tagihan_biaya_lain' AND INDEX_NAME='uk_tagihan_biaya_lain_siswa_master' AND NON_UNIQUE=0)
  UNION ALL
  SELECT 'fk_bayar_biaya_lain_tagihan', EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='bayar_biaya_lain' AND CONSTRAINT_NAME='fk_bayar_biaya_lain_tagihan' AND CONSTRAINT_TYPE='FOREIGN KEY')
  UNION ALL
  SELECT 'idx_siswa_status_kelas_nama',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
             AND INDEX_NAME = 'idx_siswa_status_kelas_nama'
         )
  UNION ALL
  SELECT 'fk_bayar_du_bayar',
         EXISTS(
           SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
           JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA = TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA
            AND rc.CONSTRAINT_NAME = TABLE_CONSTRAINTS.CONSTRAINT_NAME
           WHERE TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_CONSTRAINTS.TABLE_NAME = 'bayar_du'
             AND TABLE_CONSTRAINTS.CONSTRAINT_NAME = 'fk_bayar_du_bayar'
             AND TABLE_CONSTRAINTS.CONSTRAINT_TYPE = 'FOREIGN KEY'
             AND rc.DELETE_RULE = 'CASCADE' AND rc.UPDATE_RULE = 'CASCADE'
         )
  UNION ALL
  SELECT 'fk_transaksi_m_bayar',
         EXISTS(
           SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
           JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA = TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA
            AND rc.CONSTRAINT_NAME = TABLE_CONSTRAINTS.CONSTRAINT_NAME
           WHERE TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_CONSTRAINTS.TABLE_NAME = 'transaksi_m'
             AND TABLE_CONSTRAINTS.CONSTRAINT_NAME = 'fk_transaksi_m_bayar'
             AND TABLE_CONSTRAINTS.CONSTRAINT_TYPE = 'FOREIGN KEY'
             AND rc.DELETE_RULE = 'CASCADE' AND rc.UPDATE_RULE = 'CASCADE'
         )
) AS requirements
ORDER BY requirement;

SELECT id, NO_INDUK, TGL_BYR, payment_link_version
FROM bayar
WHERE payment_link_version NOT IN (0, 1)
ORDER BY id;
