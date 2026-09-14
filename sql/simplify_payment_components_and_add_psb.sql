-- Penyederhanaan komponen pembayaran dan paket PSB satu kali.
-- Destruktif sesuai keputusan bisnis: transaksi yang memuat komponen lama dihapus utuh.

ALTER TABLE `siswa`
  ADD COLUMN `PSB` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `PANGKAL`,
  ADD COLUMN `asal_psb` TINYINT(1) NOT NULL DEFAULT 0 AFTER `PSB`;

ALTER TABLE `siswa_tahun_ajaran`
  ADD COLUMN `spp_covered_by_psb` TINYINT(1) NOT NULL DEFAULT 0 AFTER `spp_perbulan_snapshot`;

ALTER TABLE `bayar`
  ADD COLUMN `U_PSB` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `U_PANGKAL`;

UPDATE `siswa` s
LEFT JOIN `master_kelas` mk ON mk.id=s.master_kelas_id
SET s.asal_psb=1
WHERE COALESCE(mk.tingkat,CAST(s.KELAS AS UNSIGNED))=0
   OR UPPER(COALESCE(mk.kode_rombel,''))='PSB'
   OR UPPER(s.KELAS)='PSB';

-- Header dihapus seluruhnya; detail SPP/DU/biaya lain/tabungan tertaut ikut terhapus oleh FK cascade.
DELETE FROM `bayar`
WHERE COALESCE(`U_BANGUNAN`,0)>0
   OR COALESCE(`U_SERAGAM`,0)>0
   OR COALESCE(`U_KEGIATAN`,0)>0
   OR COALESCE(`U_MAKAN`,0)>0
   OR COALESCE(`U_SORGA`,0)>0
   OR COALESCE(`U_INFAQ`,0)>0;

DELETE FROM `bayar_tahunan_siswa`
WHERE `komponen` IN ('pangkal','bangunan','seragam','kegiatan','makan','sorga','infaq');

DELETE FROM `tagihan_tahunan_siswa`
WHERE `komponen` IN ('pangkal','bangunan','seragam','kegiatan','makan','sorga','infaq');

-- Total header hanya berasal dari komponen aktif serta rincian yang masih bertahan.
UPDATE `bayar` b
LEFT JOIN (
  SELECT bayar_id,SUM(nominal_snapshot) total_lain
  FROM bayar_biaya_lain GROUP BY bayar_id
) lain ON lain.bayar_id=b.id
LEFT JOIN (
  SELECT bayar_id,SUM(jumlah) total_du
  FROM bayar_du GROUP BY bayar_id
) du ON du.bayar_id=b.id
SET b.total_jumlah=
  COALESCE(b.U_PANGKAL,0)+COALESCE(b.U_PSB,0)+COALESCE(b.U_SPP,0)+COALESCE(b.U_KOMITE,0)
  +COALESCE(lain.total_lain,0)+COALESCE(du.total_du,0)-COALESCE(b.potong_spp,0);

-- Pastikan saldo tabungan kembali sama dengan jurnal yang bertahan.
INSERT INTO `tabungan` (`NO_INDUK`,`SALDO`)
SELECT s.NO_INDUK,GREATEST(COALESCE(m.total_masuk,0)-COALESCE(k.total_keluar,0),0)
FROM siswa s
LEFT JOIN (SELECT NO_INDUK,SUM(MASUK) total_masuk FROM transaksi_m GROUP BY NO_INDUK) m ON m.NO_INDUK=s.NO_INDUK
LEFT JOIN (SELECT NO_INDUK,SUM(KELUAR) total_keluar FROM transaksi_k GROUP BY NO_INDUK) k ON k.NO_INDUK=s.NO_INDUK
ON DUPLICATE KEY UPDATE SALDO=VALUES(SALDO);

ALTER TABLE `siswa`
  DROP COLUMN `BANGUNAN`,
  DROP COLUMN `SERAGAM`,
  DROP COLUMN `KEGIATAN`,
  DROP COLUMN `MAKAN`,
  DROP COLUMN `SORGA`,
  DROP COLUMN `INFAQ`,
  DROP COLUMN `PANGKAL_BAYAR`,
  DROP COLUMN `BANGUNAN_BAYAR`,
  DROP COLUMN `SERAGAM_BAYAR`,
  DROP COLUMN `KEGIATAN_BAYAR`;

ALTER TABLE `bayar`
  DROP COLUMN `U_BANGUNAN`,
  DROP COLUMN `U_SERAGAM`,
  DROP COLUMN `U_KEGIATAN`,
  DROP COLUMN `U_MAKAN`,
  DROP COLUMN `U_SORGA`,
  DROP COLUMN `U_INFAQ`;

ALTER TABLE `siswa`
  ADD CONSTRAINT `chk_siswa_psb` CHECK (`PSB` >= 0 AND `asal_psb` IN (0,1));

ALTER TABLE `bayar`
  ADD CONSTRAINT `chk_bayar_psb` CHECK (`U_PSB` >= 0);

ALTER TABLE `siswa_tahun_ajaran`
  ADD CONSTRAINT `chk_penempatan_psb_spp`
  CHECK (`spp_covered_by_psb` IN (0,1) AND (`spp_covered_by_psb` = 0 OR `spp_perbulan_snapshot` = 0));
