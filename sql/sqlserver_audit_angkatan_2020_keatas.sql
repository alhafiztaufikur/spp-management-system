-- Audit sumber SQL Server berdasarkan pola NIS angkatan 2020/2021-2026/2027.
-- Awalan: 2021,2122,2223,2324,2425,2526,2627; NIS numerik > 4 karakter.
-- Pola NIS adalah filter pemeriksaan, bukan bukti pasti tahun masuk siswa.
-- Data tanpa NIS tidak dapat diberi angkatan lewat filter ini.
-- Hanya membaca sumber. Tidak menjalankan aturan/finalisasi migrasi MySQL.
-- Ganti USE menjadi [smpit_mh] atau [smait_mh], lalu jalankan seluruh script.
-- Jumlah temuan antarbagian dapat tumpang tindih; jangan dijumlahkan sebagai orang.
SET NOCOUNT ON;
USE [sdit_mh];
GO

-- 1. CAKUPAN DATA DAN PASANGAN MASTER SISWA DALAM UNIT YANG SAMA.
-- baris_sumber: jumlah seluruh baris dalam cakupan pemeriksaan.
-- jumlah_nis_unik: jumlah nomor induk unik; ini hanya ringkasan data.
-- Contoh: 5 pembayaran milik 2 NIS -> baris_sumber=5, jumlah_nis_unik=2.
-- baris_tanpa_master: baris yang NIS-nya tidak ditemukan pada master unit ini.
;WITH sumber AS (
    SELECT N'siswa' AS tabel, LTRIM(RTRIM(no_induk)) AS nis FROM dbo.siswa
    UNION ALL SELECT N'tabungan', LTRIM(RTRIM(NO_INDUK)) FROM dbo.tabungan
    UNION ALL SELECT N'transaksi_m', LTRIM(RTRIM(NO_INDUK)) FROM dbo.transaksi_m
    UNION ALL SELECT N'transaksi_k', LTRIM(RTRIM(NO_INDUK)) FROM dbo.transaksi_k
    UNION ALL SELECT N'bayar', LTRIM(RTRIM(NO_INDUK)) FROM dbo.bayar
    UNION ALL SELECT N'bayar_du', LTRIM(RTRIM(no_induk)) FROM dbo.bayar_du
), master_nis AS (
    SELECT DISTINCT UPPER(LTRIM(RTRIM(no_induk))) AS nis FROM dbo.siswa
)
SELECT DB_NAME() AS database_sumber, r.tabel,
       COUNT_BIG(*) AS baris_sumber,
       COUNT(DISTINCT r.nis) AS jumlah_nis_unik,
       SUM(CASE WHEN s.nis IS NULL THEN 1 ELSE 0 END) AS baris_tanpa_master
FROM sumber r
LEFT JOIN master_nis s ON s.nis = UPPER(r.nis)
WHERE LEN(r.nis) > 4 AND r.nis NOT LIKE N'%[^0-9]%'
  AND LEFT(r.nis,4) IN (N'2021',N'2122',N'2223',N'2324',N'2425',N'2526',N'2627')
GROUP BY r.tabel
ORDER BY r.tabel;
GO

-- 2. PEMBAYARAN YANG NIS-NYA TIDAK DITEMUKAN PADA MASTER UNIT INI.
-- Rincian diperlihatkan karena U_LAIN bisa NULL sementara JUMLAH1..4 terisi.
SELECT DB_NAME() AS database_sumber, b.id AS id_bayar,
       b.NO_INDUK AS nis_sumber, b.KELAS AS kelas_sumber, b.TGL_BYR,
       b.U_PANGKAL, b.U_SPP, b.U_KEGIATAN, b.U_LAIN,
       b.LAIN_LAIN1, b.JUMLAH1, b.LAIN_LAIN2, b.JUMLAH2,
       b.LAIN_LAIN3, b.JUMLAH3, b.LAIN_LAIN4, b.JUMLAH4,
       N'Nomor induk pembayaran tidak ditemukan pada master unit ini' AS temuan
FROM dbo.bayar b
CROSS APPLY (SELECT LTRIM(RTRIM(b.NO_INDUK)) AS nis) n
WHERE LEN(n.nis) > 4 AND n.nis NOT LIKE N'%[^0-9]%'
  AND LEFT(n.nis,4) IN (N'2021',N'2122',N'2223',N'2324',N'2425',N'2526',N'2627')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.siswa s
      WHERE UPPER(LTRIM(RTRIM(s.no_induk))) = UPPER(n.nis)
  )
ORDER BY b.TGL_BYR, b.id;
GO

-- 3. RINCIAN BIAYA POSITIF YANG KODENYA KOSONG.
-- Ini menunjukkan identitas jenis biaya yang tidak tercatat di slot sumber.
-- Belum merupakan penilaian apakah kasus sudah diselesaikan sesudah migrasi.
SELECT DB_NAME() AS database_sumber, b.id AS id_bayar,
       b.NO_INDUK AS nis_sumber, b.TGL_BYR, v.slot,
       v.kode AS kode_asli, CONVERT(decimal(20,2), v.nominal) AS nominal_sumber,
       b.KETERANGAN,
       N'Nominal rincian terisi, kode jenis biaya kosong' AS temuan
FROM dbo.bayar b
CROSS APPLY (SELECT LTRIM(RTRIM(b.NO_INDUK)) AS nis) n
CROSS APPLY (VALUES
    (1,b.LAIN_LAIN1,b.JUMLAH1), (2,b.LAIN_LAIN2,b.JUMLAH2),
    (3,b.LAIN_LAIN3,b.JUMLAH3), (4,b.LAIN_LAIN4,b.JUMLAH4)
) v(slot,kode,nominal)
WHERE LEN(n.nis) > 4 AND n.nis NOT LIKE N'%[^0-9]%'
  AND LEFT(n.nis,4) IN (N'2021',N'2122',N'2223',N'2324',N'2425',N'2526',N'2627')
  AND v.nominal > 0 AND NULLIF(LTRIM(RTRIM(v.kode)), N'') IS NULL
ORDER BY b.TGL_BYR, b.id, v.slot;
GO

-- 4. SALDO HEADER BERBEDA DARI SELURUH JURNAL UTAMA.
-- Jangan membatasi jurnal mulai 2020: saldo harus memakai seluruh riwayat NIS.
-- decimal(28,6) dan toleransi Rp0,005 dipakai untuk diagnosis nominal,
-- bukan untuk mengesahkan saldo atau menggantikan parser uang importer.
;WITH jurnal AS (
    SELECT LTRIM(RTRIM(NO_INDUK)) AS nis,
           CONVERT(decimal(28,6), COALESCE(MASUK,0)) AS masuk,
           CONVERT(decimal(28,6), COALESCE(KELUAR,0)) AS keluar
    FROM dbo.transaksi_m
    UNION ALL
    SELECT LTRIM(RTRIM(NO_INDUK)),
           CONVERT(decimal(28,6), COALESCE(MASUK,0)),
           CONVERT(decimal(28,6), COALESCE(KELUAR,0))
    FROM dbo.transaksi_k
), saldo_jurnal AS (
    SELECT nis, COUNT_BIG(*) AS jumlah_jurnal, SUM(masuk-keluar) AS saldo
    FROM jurnal GROUP BY nis
)
SELECT DB_NAME() AS database_sumber, t.id AS id_rekening, t.NO_INDUK,
       CONVERT(decimal(20,2), t.SALDO) AS saldo_header,
       CONVERT(decimal(20,2), COALESCE(j.saldo,0)) AS saldo_dari_jurnal,
       CONVERT(decimal(20,2), t.SALDO-COALESCE(j.saldo,0)) AS selisih,
       COALESCE(j.jumlah_jurnal,0) AS jumlah_jurnal
FROM dbo.tabungan t
CROSS APPLY (SELECT LTRIM(RTRIM(t.NO_INDUK)) AS nis) n
LEFT JOIN saldo_jurnal j ON j.nis=n.nis
WHERE LEN(n.nis) > 4 AND n.nis NOT LIKE N'%[^0-9]%'
  AND LEFT(n.nis,4) IN (N'2021',N'2122',N'2223',N'2324',N'2425',N'2526',N'2627')
  AND ABS(t.SALDO-COALESCE(j.saldo,0)) >= 0.005
ORDER BY ABS(t.SALDO-COALESCE(j.saldo,0)) DESC, t.id;
GO

-- 5. POLA PENCATATAN PEMBAYARAN: TANGGAL, JAM, DAN TOTAL/RINCIAN.
-- U_LAIN kosong/nol dengan rincian positif adalah pola sumber yang ditangani
-- importer lewat rincian. Jangan menganggap pembayaran tersebut bernilai nol.
SELECT DB_NAME() AS database_sumber, COUNT_BIG(*) AS total_pembayaran,
       SUM(CASE WHEN b.TGL_BYR IS NULL THEN 1 ELSE 0 END) AS tanggal_kosong,
       SUM(CASE WHEN NULLIF(LTRIM(RTRIM(b.jam)), N'') IS NULL
                THEN 1 ELSE 0 END) AS jam_kosong,
       SUM(CASE WHEN COALESCE(b.U_LAIN,0)=0
                 AND COALESCE(b.JUMLAH1,0)+COALESCE(b.JUMLAH2,0)
                    +COALESCE(b.JUMLAH3,0)+COALESCE(b.JUMLAH4,0)>0
                THEN 1 ELSE 0 END) AS total_lain_kosong_nol_rincian_ada
FROM dbo.bayar b
CROSS APPLY (SELECT LTRIM(RTRIM(b.NO_INDUK)) AS nis) n
WHERE LEN(n.nis) > 4 AND n.nis NOT LIKE N'%[^0-9]%'
  AND LEFT(n.nis,4) IN (N'2021',N'2122',N'2223',N'2324',N'2425',N'2526',N'2627');
GO

-- 6. CONTOH POLA TOTAL/RINCIAN, MAKSIMAL 20 BARIS TERBARU.
SELECT TOP (20) DB_NAME() AS database_sumber, b.id AS id_bayar,
       b.NO_INDUK AS nis_sumber, b.TGL_BYR, b.jam, b.U_LAIN,
       b.LAIN_LAIN1, b.JUMLAH1, b.LAIN_LAIN2, b.JUMLAH2,
       b.LAIN_LAIN3, b.JUMLAH3, b.LAIN_LAIN4, b.JUMLAH4
FROM dbo.bayar b
CROSS APPLY (SELECT LTRIM(RTRIM(b.NO_INDUK)) AS nis) n
WHERE LEN(n.nis) > 4 AND n.nis NOT LIKE N'%[^0-9]%'
  AND LEFT(n.nis,4) IN (N'2021',N'2122',N'2223',N'2324',N'2425',N'2526',N'2627')
  AND COALESCE(b.U_LAIN,0)=0
  AND COALESCE(b.JUMLAH1,0)+COALESCE(b.JUMLAH2,0)
     +COALESCE(b.JUMLAH3,0)+COALESCE(b.JUMLAH4,0)>0
ORDER BY b.TGL_BYR DESC, b.id DESC;
GO

-- Catatan: bayar_du adalah rekap terpisah, tanpa kolom tanggal transaksi.
-- Jangan menjumlahkan bayar_du lagi sebagai penerimaan di atas bayar.
-- Semua angka di sini menjelaskan sumber, bukan daftar pending MySQL saat ini.
