-- Audit hanya baca untuk SQL Server Management Studio (SSMS).
-- Default SMA, sesuai contoh. Ganti USE menjadi [sdit_mh] atau [smpit_mh].
-- Jalankan seluruh script (Ctrl+A, F5), sehingga USE di bawah ikut dijalankan.
-- Seluruh periode disertakan: NIS kosong/tidak baku tidak bisa difilter angkatan.
-- Nol di depan NIS dipertahankan. Pemadanan hanya di dalam database unit ini.
-- Tidak ditemukan di siswa belum membuktikan pemilik sebenarnya tidak ada.
-- Tidak ada UPDATE/DELETE/INSERT, penerapan migrasi, atau perubahan saldo.
USE [smait_mh];
GO
SET NOCOUNT ON;
GO

-- 1. REKENING TANPA PASANGAN MASTER SISWA, DENGAN JUMLAH JURNALNYA.
-- Satu baris hasil = satu baris tabungan. Saldo rekening tidak dijumlahkan
-- dengan nominal jurnal. Rekening dan jurnal dengan NIS kosong tidak dipasangkan.
;WITH master_nis AS (
    SELECT DISTINCT NULLIF(UPPER(LTRIM(RTRIM(no_induk))), N'') AS nis
    FROM dbo.siswa
), jurnal AS (
    SELECT NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'') AS nis,
           TANGGAL
    FROM dbo.transaksi_m
    UNION ALL
    SELECT NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N''), TANGGAL
    FROM dbo.transaksi_k
), jumlah_jurnal AS (
    SELECT nis, COUNT_BIG(*) AS jumlah_catatan,
           MIN(TANGGAL) AS tanggal_pertama,
           MAX(TANGGAL) AS tanggal_terakhir
    FROM jurnal
    WHERE nis IS NOT NULL
    GROUP BY nis
)
SELECT DB_NAME() AS database_sumber,
       N'tabungan' AS tabel_sumber,
       t.id AS id_rekening,
       t.NO_INDUK AS nomor_induk_asli,
       n.nis AS nomor_induk_pencocokan,
       CONVERT(decimal(20,2), t.SALDO) AS saldo_tercatat,
       CASE WHEN n.nis IS NULL THEN CAST(NULL AS bigint)
            ELSE COALESCE(j.jumlah_catatan, 0) END AS jumlah_jurnal,
       j.tanggal_pertama, j.tanggal_terakhir,
       CASE WHEN n.nis IS NULL THEN N'Nomor induk rekening kosong'
            ELSE N'Nomor induk rekening tidak ditemukan pada master siswa'
       END AS temuan
FROM dbo.tabungan t
CROSS APPLY (SELECT NULLIF(UPPER(LTRIM(RTRIM(t.NO_INDUK))), N'') AS nis) n
LEFT JOIN jumlah_jurnal j ON j.nis = n.nis
LEFT JOIN master_nis m ON m.nis = n.nis
WHERE m.nis IS NULL
ORDER BY CASE WHEN n.nis IS NULL THEN 0 ELSE 1 END, t.SALDO DESC, t.id
OPTION (MAX_GRANT_PERCENT = 5);
GO

-- 2. CATATAN/JURNAL TANPA PASANGAN MASTER, TERMASUK TANPA REKENING.
-- Ini juga menangkap NIS terisi yang tidak ada di siswa, bukan hanya NIS kosong.
-- Setiap catatan tetap satu baris. transaksi_k tidak mempunyai kolom id.
;WITH master_nis AS (
    SELECT DISTINCT NULLIF(UPPER(LTRIM(RTRIM(no_induk))), N'') AS nis
    FROM dbo.siswa
), rekening_nis AS (
    SELECT DISTINCT NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'') AS nis
    FROM dbo.tabungan
), jurnal AS (
    SELECT N'transaksi_m' AS tabel_sumber, id AS id_jurnal,
           NO_INDUK, TANGGAL, MASUK, KELUAR, user_id
    FROM dbo.transaksi_m
    UNION ALL
    SELECT N'transaksi_k', CAST(NULL AS int),
           NO_INDUK, TANGGAL, MASUK, KELUAR, user_id
    FROM dbo.transaksi_k
)
SELECT DB_NAME() AS database_sumber,
       j.tabel_sumber, j.id_jurnal,
       j.NO_INDUK AS nomor_induk_asli,
       n.nis AS nomor_induk_pencocokan,
       j.TANGGAL AS tanggal_sumber,
       CONVERT(decimal(20,2), j.MASUK) AS masuk_tercatat,
       CONVERT(decimal(20,2), j.KELUAR) AS keluar_tercatat,
       j.user_id AS operator_sumber,
       CASE WHEN n.nis IS NULL THEN CAST(NULL AS int)
            WHEN r.nis IS NOT NULL THEN 1 ELSE 0
       END AS ada_rekening_berdasarkan_nis,
       CASE WHEN n.nis IS NULL THEN N'Nomor induk jurnal kosong'
            ELSE N'Nomor induk jurnal tidak ditemukan pada master siswa'
       END AS temuan
FROM jurnal j
CROSS APPLY (SELECT NULLIF(UPPER(LTRIM(RTRIM(j.NO_INDUK))), N'') AS nis) n
LEFT JOIN master_nis m ON m.nis = n.nis
LEFT JOIN rekening_nis r ON r.nis = n.nis
WHERE m.nis IS NULL
ORDER BY CASE WHEN n.nis IS NULL THEN 0 ELSE 1 END,
         n.nis, j.TANGGAL, j.tabel_sumber, j.id_jurnal
OPTION (MAX_GRANT_PERCENT = 5);
GO

-- Interpretasi:
-- * jumlah_jurnal = NULL: NIS rekening kosong, jurnal tidak bisa dipasangkan.
-- * jumlah_jurnal = 0: tidak ada baris jurnal utama dengan NIS terisi tersebut;
--   bukan pengesahan saldo nol atau histori lengkap.
-- * ada_rekening_berdasarkan_nis = 0: jurnal mempunyai NIS terisi tetapi tidak
--   ditemukan pada tabungan di unit ini. NULL berarti NIS kosong, bukan satu orang.
-- * NULL pada nominal/tanggal tetap NULL; tidak diganti tanggal impor atau nol.
-- * Jurnal kosong dan rekening kosong tidak otomatis milik orang yang sama.
-- * Hasil pertama dan kedua saling terkait; jangan dijumlahkan sebagai orang/saldo.
-- * tb_total/tb_masuk/tb_keluar/tbg tidak digabung dengan rekening/jurnal utama.
--   Pemeriksaan rekening tambahan SD tersedia pada sqlserver_audit_rekening_legacy.sql.
-- * Ini memeriksa sumber SQL Server, bukan status pemilik setelah migrasi ke MySQL.
