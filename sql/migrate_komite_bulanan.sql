-- Jalankan pada salinan database terlebih dahulu. Data Komite tahunan demo
-- dihapus; komponen lain dan header transaksi tetap dipertahankan.
START TRANSACTION;

ALTER TABLE siswa_tahun_ajaran ADD COLUMN komite_mulai_bulan CHAR(2) NOT NULL DEFAULT '07' AFTER komite_snapshot;
ALTER TABLE spp_alokasi_batch ADD COLUMN komite_required TINYINT(1) NOT NULL DEFAULT 0 AFTER gunakan_titipan;

CREATE TABLE tagihan_komite (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tahun_ajaran_id INT NOT NULL, penempatan_id BIGINT NOT NULL,
  no_induk VARCHAR(10) NOT NULL, kelas_rombel_snapshot VARCHAR(30) DEFAULT NULL,
  bulan CHAR(2) NOT NULL, tahun CHAR(4) NOT NULL,
  nominal_tagihan DECIMAL(15,2) NOT NULL DEFAULT 0,
  status ENUM('open','cancelled') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tagihan_komite_periode (no_induk,tahun,bulan),
  KEY idx_tagihan_komite_tahun (tahun_ajaran_id,status),
  CONSTRAINT fk_tagihan_komite_tahun FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_tagihan_komite_penempatan FOREIGN KEY (penempatan_id) REFERENCES siswa_tahun_ajaran(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_tagihan_komite_siswa FOREIGN KEY (no_induk) REFERENCES siswa(NO_INDUK) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_tagihan_komite_nominal CHECK (nominal_tagihan >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE bayar_komite (
  bayar_id INT NOT NULL PRIMARY KEY, tagihan_komite_id BIGINT NOT NULL,
  nominal DECIMAL(15,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bayar_komite_tagihan (tagihan_komite_id),
  CONSTRAINT fk_bayar_komite_bayar FOREIGN KEY (bayar_id) REFERENCES bayar(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_bayar_komite_tagihan FOREIGN KEY (tagihan_komite_id) REFERENCES tagihan_komite(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_bayar_komite_nominal CHECK (nominal > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- MySQL/MariaDB DDL mengakhiri transaksi secara implisit. Backup wajib dibuat
-- sebelum migrasi database aktif; jangan menganggap ROLLBACK memulihkan DDL.
DELETE FROM bayar_tahunan_siswa WHERE komponen='komite';
DELETE FROM tagihan_tahunan_siswa WHERE komponen='komite';
UPDATE bayar SET total_jumlah=total_jumlah-U_KOMITE,U_KOMITE=0 WHERE U_KOMITE<>0;

-- Apabila data pindahan lama punya awal SPP yang jelas, pakai bulan tersebut.
UPDATE siswa_tahun_ajaran sta
JOIN (SELECT penempatan_id,MIN(CONCAT(tahun,bulan)) AS periode FROM tagihan_spp GROUP BY penempatan_id) first_spp ON first_spp.penempatan_id=sta.id
SET sta.komite_mulai_bulan=RIGHT(first_spp.periode,2);

INSERT INTO tagihan_komite (tahun_ajaran_id,penempatan_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan)
SELECT sta.tahun_ajaran_id,sta.id,sta.no_induk,sta.kelas_rombel_snapshot,
       LPAD(m.bulan,2,'0'),IF(m.bulan>=7,LEFT(ta.label,4),RIGHT(ta.label,4)),s.POMG
FROM siswa_tahun_ajaran sta
JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id
JOIN siswa s ON s.NO_INDUK=sta.no_induk
JOIN (SELECT 1 bulan UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12) m
WHERE sta.kelas IN ('1','2','3','4','5','6')
  AND IF(m.bulan>=7,m.bulan-7,m.bulan+5)>=IF(CAST(sta.komite_mulai_bulan AS UNSIGNED)>=7,CAST(sta.komite_mulai_bulan AS UNSIGNED)-7,CAST(sta.komite_mulai_bulan AS UNSIGNED)+5);

COMMIT;
