-- Pulihkan CHECK dari schema referensi setelah impor dump phpMyAdmin
-- XAMPP/MariaDB ke MySQL 8.4. Jalankan hanya setelah backup dan setelah
-- memastikan dump sumber tidak memiliki constraint CHECK sendiri.
-- Ditujukan untuk dump demo db_spp tanggal 24 September 2026.
-- Pilih database db_spp sebelum menjalankan; tidak memakai USE.

ALTER TABLE `master_kelas`
  ADD CONSTRAINT `chk_master_kelas_tingkat` CHECK (`tingkat` BETWEEN 0 AND 6);

ALTER TABLE `siswa`
  ADD CONSTRAINT `chk_siswa_kelas_sd` CHECK (`KELAS` IN ('0','1','2','3','4','5','6','PSB')),
  ADD CONSTRAINT `chk_siswa_psb` CHECK (`PSB` >= 0 AND `asal_psb` IN (0,1)),
  ADD CONSTRAINT `chk_siswa_potongan_spp` CHECK (`potongan_spp_persen` BETWEEN 0 AND 100);

ALTER TABLE `bayar`
  ADD CONSTRAINT `chk_bayar_psb` CHECK (`U_PSB` >= 0);

ALTER TABLE `master_spp_tarif`
  ADD CONSTRAINT `chk_master_spp_tingkat` CHECK (`tingkat` BETWEEN 1 AND 6),
  ADD CONSTRAINT `chk_master_spp_nominal` CHECK (`nominal_dasar` > 0);

ALTER TABLE `tagihan_spp`
  ADD CONSTRAINT `chk_tagihan_spp_bulan` CHECK (CAST(`bulan` AS UNSIGNED) BETWEEN 1 AND 12),
  ADD CONSTRAINT `chk_tagihan_spp_nominal` CHECK (`tarif_dasar_snapshot` >= 0 AND `potongan_persen_snapshot` BETWEEN 0 AND 100 AND `nominal_tagihan` >= 0);

ALTER TABLE `spp_alokasi_batch`
  ADD CONSTRAINT `chk_spp_alokasi_batch_nominal` CHECK (`uang_baru` >= 0 AND `titipan_digunakan` >= 0 AND `titipan_baru` >= 0);

ALTER TABLE `spp_alokasi`
  ADD CONSTRAINT `chk_spp_alokasi_nominal` CHECK (`nominal_dari_bayar` >= 0 AND `nominal_dari_titipan` >= 0 AND (`nominal_dari_bayar` + `nominal_dari_titipan`) > 0);

ALTER TABLE `titipan_spp_mutasi`
  ADD CONSTRAINT `chk_titipan_spp_nominal` CHECK (`nominal` > 0);

ALTER TABLE `master_biaya_lain`
  ADD CONSTRAINT `chk_master_biaya_lain_nominal` CHECK (`nominal` > 0);

ALTER TABLE `tagihan_biaya_lain`
  ADD CONSTRAINT `chk_tagihan_biaya_lain_nominal` CHECK (`nominal_tagihan` > 0);

ALTER TABLE `tahun_ajaran`
  ADD CONSTRAINT `chk_tahun_ajaran_dates` CHECK (`tanggal_selesai` > `tanggal_mulai`);

ALTER TABLE `siswa_tahun_ajaran`
  ADD CONSTRAINT `chk_penempatan_kelas_sd` CHECK (`kelas` IN ('0','1','2','3','4','5','6','PSB')),
  ADD CONSTRAINT `chk_penempatan_psb_spp` CHECK (`spp_covered_by_psb` IN (0,1) AND (`spp_covered_by_psb` = 0 OR `spp_perbulan_snapshot` = 0));

ALTER TABLE `tagihan_komite`
  ADD CONSTRAINT `chk_tagihan_komite_nominal` CHECK (`nominal_tagihan` >= 0);

ALTER TABLE `bayar_komite`
  ADD CONSTRAINT `chk_bayar_komite_nominal` CHECK (`nominal` > 0);

ALTER TABLE `tagihan_daftar_ulang`
  ADD CONSTRAINT `chk_tagihan_du_nominal` CHECK (`nominal_awal` >= 0 AND `nominal_tagihan` >= 0),
  ADD CONSTRAINT `chk_tagihan_du_kelas` CHECK (`kelas_snapshot` IN ('0','1','2','3','4','5','6','PSB'));

ALTER TABLE `tagihan_tahunan_siswa`
  ADD CONSTRAINT `chk_tagihan_tahunan_nominal` CHECK (`nominal_awal` >= 0 AND `potongan` >= 0 AND `nominal_tagihan` >= 0),
  ADD CONSTRAINT `chk_tagihan_tahunan_kelas` CHECK (`kelas_snapshot` IN ('0','1','2','3','4','5','6','PSB'));

ALTER TABLE `bayar_tahunan_siswa`
  ADD CONSTRAINT `chk_bayar_tahunan_jumlah` CHECK (`jumlah` >= 0);

ALTER TABLE `tabungan`
  ADD CONSTRAINT `chk_tabungan_saldo_nonnegative` CHECK (`SALDO` >= 0);

SELECT COUNT(*) AS `jumlah_check`
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK';
