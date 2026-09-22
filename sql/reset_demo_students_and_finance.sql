-- =========================================================
-- RESET DATA DEMO SISWA DAN KEUANGAN SISTEMSPP
-- =========================================================
-- DESTRUKTIF: hapus seluruh siswa, pembayaran, tabungan, tagihan,
-- alokasi, titipan, dan audit terkait. Akun dan data master dipertahankan.
--
-- 1. Buat backup terlebih dahulu.
-- 2. Pilih database target di DBeaver (script ini tidak memakai USE).
-- 3. Ganti nilai di bawah menjadi RESET_DEMO_2026 sebelum menjalankan.
--    Jika tidak sama persis, semua DELETE menjadi no-op.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @spp_reset_confirmation := 'RESET_DEMO_2026';

DROP TEMPORARY TABLE IF EXISTS `spp_reset_guard`;
CREATE TEMPORARY TABLE `spp_reset_guard` AS
SELECT 1 AS `allowed`
WHERE @spp_reset_confirmation = 'RESET_DEMO_2026';

SELECT
  CASE WHEN EXISTS(SELECT 1 FROM `spp_reset_guard`)
    THEN 'KONFIRMASI DITERIMA: reset demo akan dijalankan.'
    ELSE 'RESET DIBATALKAN: isi @spp_reset_confirmation dengan RESET_DEMO_2026.'
  END AS `status_reset`;

SELECT
  (SELECT COUNT(*) FROM `siswa`) AS `siswa_sebelum`,
  (SELECT COUNT(*) FROM `bayar`) AS `pembayaran_sebelum`,
  (SELECT COUNT(*) FROM `transaksi_m`) + (SELECT COUNT(*) FROM `transaksi_k`) AS `mutasi_tabungan_sebelum`,
  (SELECT COUNT(*) FROM `tagihan_spp`) + (SELECT COUNT(*) FROM `tagihan_komite`) + (SELECT COUNT(*) FROM `tagihan_daftar_ulang`) AS `tagihan_utama_sebelum`;

START TRANSACTION;

-- Rincian pembayaran dan mutasi harus dihapus sebelum transaksi utama.
DELETE t FROM `transaksi_m` t JOIN `spp_reset_guard` g;
DELETE t FROM `transaksi_k` t JOIN `spp_reset_guard` g;
DELETE t FROM `bayar_biaya_lain` t JOIN `spp_reset_guard` g;
DELETE t FROM `bayar_tahunan_siswa` t JOIN `spp_reset_guard` g;
DELETE t FROM `bayar_du` t JOIN `spp_reset_guard` g;
DELETE t FROM `bayar_komite` t JOIN `spp_reset_guard` g;
DELETE t FROM `bayar_spp_periode` t JOIN `spp_reset_guard` g;
DELETE t FROM `titipan_spp_mutasi` t JOIN `spp_reset_guard` g;
DELETE t FROM `spp_alokasi` t JOIN `spp_reset_guard` g;
DELETE t FROM `spp_alokasi_batch` t JOIN `spp_reset_guard` g;
DELETE t FROM `bayar` t JOIN `spp_reset_guard` g;

-- Tagihan, penempatan, saldo, dan audit yang memiliki konteks siswa dihapus.
DELETE t FROM `tagihan_biaya_lain_audit_log` t JOIN `spp_reset_guard` g;
DELETE t FROM `tagihan_biaya_lain` t JOIN `spp_reset_guard` g;
DELETE t FROM `daftar_ulang_audit_log` t JOIN `spp_reset_guard` g;
DELETE t FROM `tagihan_daftar_ulang` t JOIN `spp_reset_guard` g;
DELETE t FROM `tagihan_komite` t JOIN `spp_reset_guard` g;
DELETE t FROM `tagihan_spp` t JOIN `spp_reset_guard` g;
DELETE t FROM `tagihan_tahunan_siswa` t JOIN `spp_reset_guard` g;
DELETE t FROM `spp_audit_log` t JOIN `spp_reset_guard` g;
DELETE t FROM `tabungan` t JOIN `spp_reset_guard` g;
DELETE t FROM `siswa_audit_log` t JOIN `spp_reset_guard` g;
DELETE t FROM `siswa_tahun_ajaran` t JOIN `spp_reset_guard` g;
DELETE t FROM `siswa` t JOIN `spp_reset_guard` g;

COMMIT;

SELECT
  (SELECT COUNT(*) FROM `siswa`) AS `siswa_setelah`,
  (SELECT COUNT(*) FROM `bayar`) AS `pembayaran_setelah`,
  (SELECT COUNT(*) FROM `transaksi_m`) + (SELECT COUNT(*) FROM `transaksi_k`) AS `mutasi_tabungan_setelah`,
  (SELECT COUNT(*) FROM `tagihan_spp`) + (SELECT COUNT(*) FROM `tagihan_komite`) + (SELECT COUNT(*) FROM `tagihan_daftar_ulang`) AS `tagihan_utama_setelah`,
  (SELECT COUNT(*) FROM `admin`) AS `akun_dipertahankan`,
  (SELECT COUNT(*) FROM `master_kelas`) AS `kelas_dipertahankan`,
  (SELECT COUNT(*) FROM `master_biaya_lain`) AS `master_biaya_lain_dipertahankan`;

DROP TEMPORARY TABLE IF EXISTS `spp_reset_guard`;
