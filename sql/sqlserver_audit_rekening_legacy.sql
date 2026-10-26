-- Audit sumber Legacy untuk SQL Server Management Studio (SSMS).
-- Jalankan pada instance yang memuat sdit_mh, smpit_mh, dan smait_mh.
-- Hanya membaca: tidak memperbaiki rekening atau menerapkan migrasi.
-- Bagian 1 membaca ketiga unit; bagian 2-5 mengikuti USE pada bagian 2.
-- Jumlah rekening adalah jumlah BARIS tabungan, bukan jumlah orang.
-- tb_total/tbg tidak termasuk rekening utama dan tidak dijumlahkan ke sana.
-- Pemadanan memakai unit + NIS teks; nol di depan tetap dipertahankan.

SET NOCOUNT ON;
USE [master];
GO

-- 1. RINGKASAN REKENING UTAMA KETIGA UNIT.
-- NIS kosong dipisahkan dari NIS terisi yang tidak mempunyai pasangan master.
;WITH siswa_sumber AS (
    SELECT N'SD' AS unit,
           NULLIF(UPPER(LTRIM(RTRIM(no_induk))), N'')
               COLLATE SQL_Latin1_General_CP1_CI_AS AS nis
    FROM [sdit_mh].dbo.siswa
    UNION ALL
    SELECT N'SMP', NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'')
               COLLATE SQL_Latin1_General_CP1_CI_AS
    FROM [smpit_mh].dbo.siswa
    UNION ALL
    SELECT N'SMA', NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'')
               COLLATE SQL_Latin1_General_CP1_CI_AS
    FROM [smait_mh].dbo.siswa
), master_per_nis AS (
    SELECT unit, nis, COUNT_BIG(*) AS jumlah_master
    FROM siswa_sumber
    WHERE nis IS NOT NULL
    GROUP BY unit, nis
), rekening_sumber AS (
    SELECT N'SD' AS unit,
           NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'')
               COLLATE SQL_Latin1_General_CP1_CI_AS AS nis
    FROM [sdit_mh].dbo.tabungan
    UNION ALL
    SELECT N'SMP', NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'')
               COLLATE SQL_Latin1_General_CP1_CI_AS
    FROM [smpit_mh].dbo.tabungan
    UNION ALL
    SELECT N'SMA', NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'')
               COLLATE SQL_Latin1_General_CP1_CI_AS
    FROM [smait_mh].dbo.tabungan
)
SELECT r.unit,
       COUNT_BIG(*) AS total_baris_rekening,
       SUM(CASE WHEN r.nis IS NULL THEN 1 ELSE 0 END) AS nomor_induk_kosong,
       SUM(CASE WHEN r.nis IS NOT NULL AND m.nis IS NULL
                THEN 1 ELSE 0 END) AS nis_terisi_tanpa_master,
       SUM(CASE WHEN m.jumlah_master = 1 THEN 1 ELSE 0 END) AS master_tunggal,
       SUM(CASE WHEN m.jumlah_master > 1 THEN 1 ELSE 0 END) AS master_ambigu
FROM rekening_sumber r
LEFT JOIN master_per_nis m ON m.unit = r.unit AND m.nis = r.nis
GROUP BY r.unit
ORDER BY CASE r.unit WHEN N'SD' THEN 1 WHEN N'SMP' THEN 2 ELSE 3 END;
GO

-- 2. DAFTAR REKENING TANPA PASANGAN MASTER, COCOK UNTUK SCREENSHOT.
-- Ganti USE menjadi [smpit_mh] atau [smait_mh] untuk memeriksa unit lain.
-- SD memakai siswa.no_induk sebagai identitas utama, bukan NO_INDUK1.
-- NULL pada nama_di_master berarti pasangan master tidak ditemukan.
USE [sdit_mh];
GO

SELECT DB_NAME() AS database_sumber,
       t.id AS id_rekening,
       t.NO_INDUK AS nomor_induk_asli,
       s.NAMA AS nama_di_master,
       CONVERT(decimal(20,2), t.SALDO) AS saldo_tercatat,
       CASE WHEN NULLIF(LTRIM(RTRIM(t.NO_INDUK)), N'') IS NULL
            THEN N'Nomor induk kosong'
            ELSE N'Nomor induk tidak ditemukan di master siswa unit ini'
       END AS temuan
FROM dbo.tabungan t
LEFT JOIN dbo.siswa s
    ON NULLIF(UPPER(LTRIM(RTRIM(s.no_induk))), N'')
     = NULLIF(UPPER(LTRIM(RTRIM(t.NO_INDUK))), N'')
WHERE s.id IS NULL
ORDER BY CASE WHEN NULLIF(LTRIM(RTRIM(t.NO_INDUK)), N'') IS NULL
              THEN 0 ELSE 1 END,
         t.SALDO DESC, t.id;
GO

-- 3. JURNAL UTAMA YANG NOMOR INDUKNYA KOSONG.
-- Setiap baris tetap terpisah. Identitas kosong tidak membuktikan satu pemilik.
-- Jangan mengaitkannya otomatis dengan rekening kosong pada bagian 2.
-- transaksi_k tidak memiliki kolom id; id_jurnal NULL memang disengaja.
;WITH jurnal AS (
    SELECT N'transaksi_m' AS tabel_sumber, id AS id_jurnal,
           NO_INDUK, TANGGAL, MASUK, KELUAR, user_id
    FROM dbo.transaksi_m
    WHERE NULLIF(LTRIM(RTRIM(NO_INDUK)), N'') IS NULL
    UNION ALL
    SELECT N'transaksi_k', CAST(NULL AS int),
           NO_INDUK, TANGGAL, MASUK, KELUAR, user_id
    FROM dbo.transaksi_k
    WHERE NULLIF(LTRIM(RTRIM(NO_INDUK)), N'') IS NULL
)
SELECT DB_NAME() AS database_sumber, tabel_sumber, id_jurnal,
       NO_INDUK AS nomor_induk_asli, TANGGAL AS tanggal_sumber,
       CONVERT(decimal(20,2), MASUK) AS masuk_tercatat,
       CONVERT(decimal(20,2), KELUAR) AS keluar_tercatat,
       user_id AS operator_sumber,
       N'Nomor induk kosong pada jurnal sumber' AS temuan
FROM jurnal
ORDER BY TANGGAL, tabel_sumber, id_jurnal;
GO

-- 4. TELUSURI SATU NIS TERISI: MASTER, REKENING, LALU JURNAL.
-- Contoh SD yang terverifikasi: 1314001, rekening id 98.
-- Ganti nilainya untuk kasus lain. Parameter harus terisi.
-- Rekening dan jurnal disajikan terpisah agar saldo tidak terhitung ganda.
DECLARE @NoInduk nvarchar(255) = N'1314001';
SET @NoInduk = NULLIF(UPPER(LTRIM(RTRIM(@NoInduk))), N'');

SELECT DB_NAME() AS database_sumber, id AS id_master,
       no_induk AS nomor_induk_asli, NAMA, KELAS
FROM dbo.siswa
WHERE NULLIF(UPPER(LTRIM(RTRIM(no_induk))), N'') = @NoInduk;

SELECT DB_NAME() AS database_sumber, id AS id_rekening,
       NO_INDUK AS nomor_induk_asli,
       CONVERT(decimal(20,2), SALDO) AS saldo_tercatat
FROM dbo.tabungan
WHERE NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'') = @NoInduk
ORDER BY id;

;WITH jurnal AS (
    SELECT N'transaksi_m' AS tabel_sumber, id AS id_jurnal,
           NO_INDUK, TANGGAL, MASUK, KELUAR, user_id
    FROM dbo.transaksi_m
    WHERE NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'') = @NoInduk
    UNION ALL
    SELECT N'transaksi_k', CAST(NULL AS int),
           NO_INDUK, TANGGAL, MASUK, KELUAR, user_id
    FROM dbo.transaksi_k
    WHERE NULLIF(UPPER(LTRIM(RTRIM(NO_INDUK))), N'') = @NoInduk
)
SELECT DB_NAME() AS database_sumber, tabel_sumber, id_jurnal,
       NO_INDUK AS nomor_induk_asli, TANGGAL AS tanggal_sumber,
       CONVERT(decimal(20,2), MASUK) AS masuk_tercatat,
       CONVERT(decimal(20,2), KELUAR) AS keluar_tercatat,
       user_id AS operator_sumber
FROM jurnal
ORDER BY TANGGAL, tabel_sumber, id_jurnal;
GO

-- 5. KHUSUS SD: REKENING TAMBAHAN TANPA PASANGAN MASTER.
-- Hasil ini terpisah dari tabungan utama pada bagian 1-2.
-- Jalankan bagian ini pada sdit_mh, karena profil SMP/SMA tidak memakai tb_total.
USE [sdit_mh];
GO

SELECT DB_NAME() AS database_sumber, N'tb_total' AS tabel_sumber,
       t.id AS id_rekening_tambahan, t.NO_INDUK AS nomor_induk_asli,
       CONVERT(decimal(20,2), t.SALDO) AS saldo_tambahan_tercatat,
       CASE WHEN NULLIF(LTRIM(RTRIM(t.NO_INDUK)), N'') IS NULL
            THEN N'Nomor induk kosong'
            ELSE N'Nomor induk tidak ditemukan di master siswa SD'
       END AS temuan
FROM dbo.tb_total t
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.siswa s
    WHERE NULLIF(UPPER(LTRIM(RTRIM(s.no_induk))), N'')
        = NULLIF(UPPER(LTRIM(RTRIM(t.NO_INDUK))), N'')
)
ORDER BY t.SALDO DESC, t.id;
GO

-- Catatan interpretasi:
-- * Tanpa pasangan master belum membuktikan uang hilang atau orangnya tidak ada.
-- * GK/TABGJ atau pemilik non-siswa yang identitasnya tersedia bukan kasus kosong.
-- * Semua periode sumber disertakan, termasuk rekening bersaldo nol.
-- * CONVERT decimal hanya merapikan tampilan; nilai float sumber tidak diubah.
-- * Jam 00:00:00 tidak membuktikan jam transaksi sebenarnya.
-- * SQL ini memeriksa sumber lama, bukan saldo operasional MySQL db_spp_test2.
