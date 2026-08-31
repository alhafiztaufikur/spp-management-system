-- =========================================================
-- Tagihan tahunan siswa untuk komponen dari Data Siswa
-- Idempoten: aman dijalankan berkali-kali.
-- =========================================================

USE `db_spp`;

CREATE TABLE IF NOT EXISTS `tagihan_tahunan_siswa` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tahun_ajaran_id` INT NOT NULL,
  `penempatan_id` BIGINT DEFAULT NULL,
  `no_induk` VARCHAR(10) NOT NULL,
  `komponen` VARCHAR(30) NOT NULL,
  `nama_snapshot` VARCHAR(100) NOT NULL,
  `kelas_snapshot` CHAR(1) NOT NULL,
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
  CONSTRAINT `chk_tagihan_tahunan_kelas` CHECK (`kelas_snapshot` IN ('1','2','3','4','5','6'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `bayar_tahunan_siswa` (
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

INSERT IGNORE INTO `tagihan_tahunan_siswa` (
  `tahun_ajaran_id`, `penempatan_id`, `no_induk`, `komponen`, `nama_snapshot`,
  `kelas_snapshot`, `kelas_rombel_snapshot`, `tahun_ajaran_snapshot`,
  `nominal_awal`, `potongan`, `nominal_tagihan`, `status`, `created_by`
)
SELECT sta.`tahun_ajaran_id`, sta.`id`, sta.`no_induk`, fees.`komponen`, s.`NAMA`,
       sta.`kelas`, sta.`kelas_rombel_snapshot`, ta.`label`,
       fees.`nominal_awal`, fees.`potongan`, fees.`nominal_tagihan`, 'open', 'migration'
FROM `siswa_tahun_ajaran` sta
JOIN `tahun_ajaran` ta ON ta.`id` = sta.`tahun_ajaran_id`
JOIN `siswa` s ON s.`NO_INDUK` = sta.`no_induk`
JOIN (
  SELECT 'pangkal' AS komponen, sf.`NO_INDUK`, sf.`PANGKAL` AS nominal_awal, sf.`potong_pangkal` AS potongan,
         IF(sf.`tot_pangkal` > 0, sf.`tot_pangkal`, GREATEST(sf.`PANGKAL` - sf.`potong_pangkal`, 0)) AS nominal_tagihan FROM `siswa` sf
  UNION ALL SELECT 'bangunan', sf.`NO_INDUK`, sf.`BANGUNAN`, 0, sf.`BANGUNAN` FROM `siswa` sf
  UNION ALL SELECT 'seragam', sf.`NO_INDUK`, sf.`SERAGAM`, 0, sf.`SERAGAM` FROM `siswa` sf
  UNION ALL SELECT 'kegiatan', sf.`NO_INDUK`, sf.`KEGIATAN`, 0, sf.`KEGIATAN` FROM `siswa` sf
  UNION ALL SELECT 'komite', sf.`NO_INDUK`, sf.`POMG`, 0, sf.`POMG` FROM `siswa` sf
  UNION ALL SELECT 'makan', sf.`NO_INDUK`, sf.`MAKAN`, 0, sf.`MAKAN` FROM `siswa` sf
  UNION ALL SELECT 'sorga', sf.`NO_INDUK`, sf.`SORGA`, 0, sf.`SORGA` FROM `siswa` sf
  UNION ALL SELECT 'infaq', sf.`NO_INDUK`, sf.`INFAQ`, 0, sf.`INFAQ` FROM `siswa` sf
) fees ON fees.`NO_INDUK` = sta.`no_induk`
WHERE sta.`status` <> 'lulus';

INSERT IGNORE INTO `bayar_tahunan_siswa` (`bayar_id`, `tagihan_tahunan_id`, `no_induk`, `komponen`, `th_ajaran`, `jumlah`)
SELECT legacy.`bayar_id`, t.`id`, legacy.`NO_INDUK`, legacy.`komponen`, t.`tahun_ajaran_snapshot`, legacy.`jumlah`
FROM (
  SELECT b.`id` AS bayar_id, b.`NO_INDUK`, 'pangkal' AS komponen, b.`U_PANGKAL` AS jumlah,
    COALESCE(NULLIF(b.`th_ajaran`, ''), CASE
      WHEN CAST(CASE LOWER(b.`BULAN`)
        WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4
        WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8
        WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12
        ELSE b.`BULAN` END AS UNSIGNED) >= 7
      THEN CONCAT(b.`TAHUN`, '/', CAST(b.`TAHUN` AS UNSIGNED) + 1)
      ELSE CONCAT(CAST(b.`TAHUN` AS UNSIGNED) - 1, '/', b.`TAHUN`) END) AS th_ajaran
    FROM `bayar` b WHERE b.`U_PANGKAL` > 0
  UNION ALL SELECT b.`id`, b.`NO_INDUK`, 'bangunan', b.`U_BANGUNAN`, COALESCE(NULLIF(b.`th_ajaran`, ''), CASE WHEN CAST(CASE LOWER(b.`BULAN`) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE b.`BULAN` END AS UNSIGNED) >= 7 THEN CONCAT(b.`TAHUN`, '/', CAST(b.`TAHUN` AS UNSIGNED) + 1) ELSE CONCAT(CAST(b.`TAHUN` AS UNSIGNED) - 1, '/', b.`TAHUN`) END) FROM `bayar` b WHERE b.`U_BANGUNAN` > 0
  UNION ALL SELECT b.`id`, b.`NO_INDUK`, 'seragam', b.`U_SERAGAM`, COALESCE(NULLIF(b.`th_ajaran`, ''), CASE WHEN CAST(CASE LOWER(b.`BULAN`) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE b.`BULAN` END AS UNSIGNED) >= 7 THEN CONCAT(b.`TAHUN`, '/', CAST(b.`TAHUN` AS UNSIGNED) + 1) ELSE CONCAT(CAST(b.`TAHUN` AS UNSIGNED) - 1, '/', b.`TAHUN`) END) FROM `bayar` b WHERE b.`U_SERAGAM` > 0
  UNION ALL SELECT b.`id`, b.`NO_INDUK`, 'kegiatan', b.`U_KEGIATAN`, COALESCE(NULLIF(b.`th_ajaran`, ''), CASE WHEN CAST(CASE LOWER(b.`BULAN`) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE b.`BULAN` END AS UNSIGNED) >= 7 THEN CONCAT(b.`TAHUN`, '/', CAST(b.`TAHUN` AS UNSIGNED) + 1) ELSE CONCAT(CAST(b.`TAHUN` AS UNSIGNED) - 1, '/', b.`TAHUN`) END) FROM `bayar` b WHERE b.`U_KEGIATAN` > 0
  UNION ALL SELECT b.`id`, b.`NO_INDUK`, 'komite', b.`U_KOMITE`, COALESCE(NULLIF(b.`th_ajaran`, ''), CASE WHEN CAST(CASE LOWER(b.`BULAN`) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE b.`BULAN` END AS UNSIGNED) >= 7 THEN CONCAT(b.`TAHUN`, '/', CAST(b.`TAHUN` AS UNSIGNED) + 1) ELSE CONCAT(CAST(b.`TAHUN` AS UNSIGNED) - 1, '/', b.`TAHUN`) END) FROM `bayar` b WHERE b.`U_KOMITE` > 0
  UNION ALL SELECT b.`id`, b.`NO_INDUK`, 'makan', b.`U_MAKAN`, COALESCE(NULLIF(b.`th_ajaran`, ''), CASE WHEN CAST(CASE LOWER(b.`BULAN`) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE b.`BULAN` END AS UNSIGNED) >= 7 THEN CONCAT(b.`TAHUN`, '/', CAST(b.`TAHUN` AS UNSIGNED) + 1) ELSE CONCAT(CAST(b.`TAHUN` AS UNSIGNED) - 1, '/', b.`TAHUN`) END) FROM `bayar` b WHERE b.`U_MAKAN` > 0
  UNION ALL SELECT b.`id`, b.`NO_INDUK`, 'sorga', b.`U_SORGA`, COALESCE(NULLIF(b.`th_ajaran`, ''), CASE WHEN CAST(CASE LOWER(b.`BULAN`) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE b.`BULAN` END AS UNSIGNED) >= 7 THEN CONCAT(b.`TAHUN`, '/', CAST(b.`TAHUN` AS UNSIGNED) + 1) ELSE CONCAT(CAST(b.`TAHUN` AS UNSIGNED) - 1, '/', b.`TAHUN`) END) FROM `bayar` b WHERE b.`U_SORGA` > 0
  UNION ALL SELECT b.`id`, b.`NO_INDUK`, 'infaq', b.`U_INFAQ`, COALESCE(NULLIF(b.`th_ajaran`, ''), CASE WHEN CAST(CASE LOWER(b.`BULAN`) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE b.`BULAN` END AS UNSIGNED) >= 7 THEN CONCAT(b.`TAHUN`, '/', CAST(b.`TAHUN` AS UNSIGNED) + 1) ELSE CONCAT(CAST(b.`TAHUN` AS UNSIGNED) - 1, '/', b.`TAHUN`) END) FROM `bayar` b WHERE b.`U_INFAQ` > 0
) legacy
JOIN `tagihan_tahunan_siswa` t ON t.`no_induk` = legacy.`NO_INDUK`
  AND t.`komponen` = legacy.`komponen`
  AND t.`tahun_ajaran_snapshot` = legacy.`th_ajaran`
WHERE legacy.`jumlah` > 0;

UPDATE `siswa` s
LEFT JOIN (
  SELECT bts.`no_induk`,
    SUM(CASE WHEN bts.`komponen` = 'pangkal' THEN bts.`jumlah` ELSE 0 END) AS pangkal,
    SUM(CASE WHEN bts.`komponen` = 'bangunan' THEN bts.`jumlah` ELSE 0 END) AS bangunan,
    SUM(CASE WHEN bts.`komponen` = 'seragam' THEN bts.`jumlah` ELSE 0 END) AS seragam,
    SUM(CASE WHEN bts.`komponen` = 'kegiatan' THEN bts.`jumlah` ELSE 0 END) AS kegiatan
  FROM `bayar_tahunan_siswa` bts
  WHERE bts.`th_ajaran` = (
    CASE WHEN MONTH(CURDATE()) >= 7
      THEN CONCAT(YEAR(CURDATE()), '/', YEAR(CURDATE()) + 1)
      ELSE CONCAT(YEAR(CURDATE()) - 1, '/', YEAR(CURDATE()))
    END
  )
  GROUP BY bts.`no_induk`
) paid ON paid.`no_induk` = s.`NO_INDUK`
SET s.`PANGKAL_BAYAR` = COALESCE(paid.`pangkal`, 0),
    s.`BANGUNAN_BAYAR` = COALESCE(paid.`bangunan`, 0),
    s.`SERAGAM_BAYAR` = COALESCE(paid.`seragam`, 0),
    s.`KEGIATAN_BAYAR` = COALESCE(paid.`kegiatan`, 0);
