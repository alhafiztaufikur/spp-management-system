-- =========================================================
-- SUPERSEDED
-- =========================================================
-- Migrasi ini dulu dipakai untuk mengizinkan cicilan SPP pada bulan yang sama.
-- Aturan terbaru: SPP wajib dibayar penuh dan satu siswa hanya boleh memiliki
-- satu transaksi SPP untuk bulan/tahun yang sama.
--
-- Struktur dan rekonsiliasi aturan terbaru ditangani oleh:
-- sql/add_psb_and_spp_full_rules.sql
--
-- File ini sengaja dibuat no-op agar runner migrasi lama tidak membalik aturan
-- SPP kembali menjadi cicilan.

USE `db_spp`;
