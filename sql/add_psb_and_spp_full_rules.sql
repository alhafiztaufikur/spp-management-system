-- Jalankan setelah backup database. Script ini idempoten untuk database demo/dev.
USE `db_spp`;

ALTER TABLE master_kelas DROP CONSTRAINT IF EXISTS chk_master_kelas_tingkat;
ALTER TABLE master_kelas ADD CONSTRAINT chk_master_kelas_tingkat CHECK (tingkat BETWEEN 0 AND 6);
INSERT IGNORE INTO master_kelas (tingkat, kode_rombel, is_placeholder, is_active) VALUES (0, 'PSB', 0, 1);

ALTER TABLE siswa MODIFY KELAS VARCHAR(10) NOT NULL;
ALTER TABLE siswa DROP CONSTRAINT IF EXISTS chk_siswa_kelas_sd;
ALTER TABLE siswa ADD CONSTRAINT chk_siswa_kelas_sd CHECK (KELAS IN ('0','1','2','3','4','5','6','PSB'));

ALTER TABLE siswa_tahun_ajaran MODIFY kelas VARCHAR(10) NOT NULL;
ALTER TABLE siswa_tahun_ajaran DROP CONSTRAINT IF EXISTS chk_penempatan_kelas_sd;
ALTER TABLE siswa_tahun_ajaran ADD CONSTRAINT chk_penempatan_kelas_sd CHECK (kelas IN ('0','1','2','3','4','5','6','PSB'));
ALTER TABLE tagihan_daftar_ulang MODIFY kelas_snapshot VARCHAR(10) NOT NULL;
ALTER TABLE tagihan_daftar_ulang DROP CONSTRAINT IF EXISTS chk_tagihan_du_kelas;
ALTER TABLE tagihan_daftar_ulang ADD CONSTRAINT chk_tagihan_du_kelas CHECK (kelas_snapshot IN ('0','1','2','3','4','5','6','PSB'));

ALTER TABLE tagihan_tahunan_siswa MODIFY kelas_snapshot VARCHAR(10) NOT NULL;
ALTER TABLE tagihan_tahunan_siswa DROP CONSTRAINT IF EXISTS chk_tagihan_tahunan_kelas;
ALTER TABLE tagihan_tahunan_siswa ADD CONSTRAINT chk_tagihan_tahunan_kelas CHECK (kelas_snapshot IN ('0','1','2','3','4','5','6','PSB'));

DROP TEMPORARY TABLE IF EXISTS tmp_spp_full_canonical;
CREATE TEMPORARY TABLE tmp_spp_full_canonical AS
SELECT b.NO_INDUK,
       LPAD(CAST(CASE LOWER(b.BULAN)
         WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4
         WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8
         WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12
         ELSE CAST(b.BULAN AS UNSIGNED) END AS CHAR), 2, '0') AS bulan,
       CAST(b.TAHUN AS CHAR) AS tahun,
       MAX(b.id) AS canonical_id,
       MAX(s.SPP_PERBULAN) AS tarif
FROM bayar b
JOIN siswa s ON s.NO_INDUK=b.NO_INDUK
WHERE b.U_SPP > 0
GROUP BY b.NO_INDUK, bulan, tahun;

UPDATE bayar b
JOIN tmp_spp_full_canonical c ON c.canonical_id=b.id
SET b.total_jumlah=GREATEST(0, b.total_jumlah + (c.tarif - b.U_SPP)),
    b.U_SPP=c.tarif,
    b.KETERANGAN=LEFT(TRIM(CONCAT(COALESCE(NULLIF(b.KETERANGAN,''),''), ' SPP_FULL_REPAIR')),255)
WHERE ABS(b.U_SPP - c.tarif) > 0.001;

UPDATE bayar b
JOIN tmp_spp_full_canonical c ON c.NO_INDUK=b.NO_INDUK
  AND c.tahun=CAST(b.TAHUN AS CHAR)
  AND c.bulan=LPAD(CAST(CASE LOWER(b.BULAN)
    WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4
    WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8
    WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12
    ELSE CAST(b.BULAN AS UNSIGNED) END AS CHAR), 2, '0')
  AND c.canonical_id<>b.id
SET b.total_jumlah=GREATEST(0, b.total_jumlah - b.U_SPP),
    b.U_SPP=0,
    b.KETERANGAN=LEFT(TRIM(CONCAT(COALESCE(NULLIF(b.KETERANGAN,''),''), ' SPP_DUPLICATE_REPAIR')),255)
WHERE b.U_SPP > 0;

DELETE bsp FROM bayar_spp_periode bsp
JOIN bayar b ON b.id=bsp.bayar_id
LEFT JOIN tmp_spp_full_canonical c ON c.canonical_id=bsp.bayar_id
WHERE b.U_SPP <= 0 OR c.canonical_id IS NULL;

INSERT IGNORE INTO bayar_spp_periode (bayar_id, no_induk, bulan, tahun)
SELECT canonical_id, NO_INDUK, bulan, tahun FROM tmp_spp_full_canonical;

CREATE UNIQUE INDEX IF NOT EXISTS uk_bayar_spp_siswa_periode ON bayar_spp_periode (no_induk, tahun, bulan);
ALTER TABLE bayar_spp_periode DROP INDEX IF EXISTS idx_bayar_spp_siswa_periode;

DROP TEMPORARY TABLE IF EXISTS tmp_spp_full_canonical;
