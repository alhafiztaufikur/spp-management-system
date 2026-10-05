-- SistemSPP: pemeriksaan db_spp sesudah impor identitas Legacy.
-- MySQL 8.4 / DBeaver, pembaruan skema PSB/nominal 5 Oktober 2026.
-- Database lokal ini sudah dimigrasi dan diimpor. Script hanya membaca.
-- Tidak mengimpor .dat, menerapkan DDL, mengaktifkan siswa, atau membuat transaksi.
-- Buka pada koneksi MySQL Laragon; jalankan seluruh script (Alt+X).
-- Pilih Execute SQL Script biasa, bukan Execute Statements in Separate Tabs.
-- Jika DBeaver meminta commit transaksi sebelumnya, selesaikan transaksi milik
-- Anda sebelum menjalankan. Jangan gabungkan script ini dengan SQL mutasi.
-- Seluruh unit dibaca dari tabel fisik, tanpa mengubah konteks unit aplikasi.

USE `db_spp`;
START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY;

-- 1. Identitas target dan versi server.
SELECT DATABASE() AS database_target, VERSION() AS versi_mysql,
       NOW() AS waktu_pemeriksaan;

-- 2. Kontrak dasar skema Legacy. Semua hasil harus OK.
SELECT pemeriksaan, IF(jumlah_masalah=0,'OK','PERIKSA') AS hasil,
       jumlah_masalah
FROM (
 SELECT 'kolom_legacy_pending' AS pemeriksaan,
        IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data'
             AND COLUMN_NAME='legacy_pending'),0,1) AS jumlah_masalah
 UNION ALL
 SELECT 'unik_unit_nis', IF(EXISTS(
   SELECT 1 FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data'
     AND INDEX_NAME='uk_siswa_unit_nis' AND NON_UNIQUE=0
   GROUP BY INDEX_NAME
   HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='unit_id,NO_INDUK'),0,1)
 UNION ALL
 SELECT 'tidak_ada_unique_nis_global',COUNT(*) FROM (
   SELECT TABLE_NAME,INDEX_NAME,
          GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS kolom
   FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0
   GROUP BY TABLE_NAME,INDEX_NAME
   HAVING LOWER(kolom) REGEXP '(^|,)no_induk(,|$)'
      AND FIND_IN_SET('unit_id',kolom)=0
 ) indeks_tidak_aman
 UNION ALL
 SELECT 'fk_nis_memeriksa_unit',COUNT(*)
 FROM information_schema.KEY_COLUMN_USAGE k
 WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='siswa_data'
   AND k.REFERENCED_COLUMN_NAME='NO_INDUK'
   AND NOT EXISTS(SELECT 1 FROM information_schema.KEY_COLUMN_USAGE u
     WHERE u.TABLE_SCHEMA=k.TABLE_SCHEMA AND u.TABLE_NAME=k.TABLE_NAME
       AND u.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND u.COLUMN_NAME='unit_id'
       AND u.REFERENCED_COLUMN_NAME='unit_id')
 UNION ALL
 SELECT 'check_status_legacy',IF(EXISTS(
   SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='siswa_data'
     AND CONSTRAINT_NAME='chk_siswa_legacy_pending' AND CONSTRAINT_TYPE='CHECK'),0,1)
 UNION ALL
 SELECT 'fk_manifest_unit_siswa',IF(EXISTS(
   SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_student_import_data'
     AND CONSTRAINT_NAME='fk_legacy_student_unit'
   GROUP BY CONSTRAINT_NAME HAVING COUNT(*)=2),0,1)
 UNION ALL
 SELECT 'jumlah_guard_relasi_legacy',ABS(
   (SELECT COUNT(*) FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'legacy_guard_%')
   -2*(SELECT COUNT(DISTINCT TABLE_NAME)
       FROM information_schema.KEY_COLUMN_USAGE
       WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='siswa_data'
         AND REFERENCED_COLUMN_NAME='NO_INDUK'))
) kontrak;

-- 3. Siswa per unit. Legacy terpisah dari Aktif dan Arsip/Lulus.
SELECT CASE unit_id WHEN 1 THEN 'SD' WHEN 2 THEN 'SMP' WHEN 3 THEN 'SMA'
       ELSE CONCAT('Unit ',unit_id) END AS unit,
       COUNT(*) AS seluruh_siswa,
       SUM(legacy_pending=1) AS legacy_belum_aktif,
       SUM(legacy_pending=0 AND is_active=1) AS aktif,
       SUM(legacy_pending=0 AND is_active=0) AS arsip_lulus
FROM siswa_data GROUP BY unit_id ORDER BY unit_id;

-- 4. Manifest identitas yang sudah diterapkan, tanpa menampilkan data pribadi.
-- Baris ditahan/GK berada di staging SQLite privat, bukan tabel operasional ini.
SELECT CASE m.unit_id WHEN 1 THEN 'SD' WHEN 2 THEN 'SMP' WHEN 3 THEN 'SMA'
       ELSE CONCAT('Unit ',m.unit_id) END AS unit,
       COUNT(*) AS identitas_diterapkan,
       COUNT(DISTINCT m.source_hash) AS backup_diterapkan,
       SUM(s.legacy_pending=1) AS masih_legacy,
       SUM(s.legacy_pending=0) AS sudah_diaktifkan
FROM legacy_student_import_data m
JOIN siswa_data s ON s.unit_id=m.unit_id AND s.id=m.student_id
GROUP BY m.unit_id ORDER BY m.unit_id;

-- 5. Angka keuangan/penempatan aktual. Tidak dibandingkan dengan angka tetap:
-- aktivitas aplikasi sesudah impor dapat menambah transaksi secara sah.
SELECT 'siswa' AS metrik, (SELECT COUNT(*) FROM siswa_data) AS nilai
UNION ALL
SELECT 'penempatan' AS metrik, (SELECT COUNT(*) FROM siswa_tahun_ajaran_data) AS nilai
UNION ALL
SELECT 'tagihan_spp' AS metrik, (SELECT COUNT(*) FROM tagihan_spp_data) AS nilai
UNION ALL
SELECT 'tagihan_komite' AS metrik, (SELECT COUNT(*) FROM tagihan_komite_data) AS nilai
UNION ALL
SELECT 'tagihan_du' AS metrik, (SELECT COUNT(*) FROM tagihan_daftar_ulang_data) AS nilai
UNION ALL
SELECT 'tagihan_biaya_lain' AS metrik, (SELECT COUNT(*) FROM tagihan_biaya_lain_data) AS nilai
UNION ALL
SELECT 'pembayaran' AS metrik, (SELECT COUNT(*) FROM bayar_data) AS nilai
UNION ALL
SELECT 'total_kas_pembayaran' AS metrik, (SELECT COALESCE(SUM(total_jumlah),0) FROM bayar_data) AS nilai
UNION ALL
SELECT 'alokasi_spp' AS metrik, (SELECT COUNT(*) FROM spp_alokasi_data) AS nilai
UNION ALL
SELECT 'pembayaran_komite' AS metrik, (SELECT COUNT(*) FROM bayar_komite_data) AS nilai
UNION ALL
SELECT 'pembayaran_du' AS metrik, (SELECT COUNT(*) FROM bayar_du_data) AS nilai
UNION ALL
SELECT 'tabungan' AS metrik, (SELECT COUNT(*) FROM tabungan_data) AS nilai
UNION ALL
SELECT 'saldo_tabungan' AS metrik, (SELECT COALESCE(SUM(SALDO),0) FROM tabungan_data) AS nilai
UNION ALL
SELECT 'jurnal_tabungan_masuk' AS metrik, (SELECT COUNT(*) FROM transaksi_m_data) AS nilai
UNION ALL
SELECT 'jurnal_tabungan_keluar' AS metrik, (SELECT COUNT(*) FROM transaksi_k_data) AS nilai;

-- 6. Integritas: seluruh 30 baris harus OK dan jumlah_masalah = 0.
SELECT pemeriksaan, IF(jumlah_masalah=0,'OK','PERIKSA') AS hasil,
       jumlah_masalah
FROM (
SELECT 'duplikat_nis_diknas_per_unit' AS pemeriksaan, (SELECT COUNT(*) FROM (
        SELECT unit_id,NO_induk_diknas FROM siswa_data
        WHERE NO_induk_diknas IS NOT NULL AND TRIM(NO_induk_diknas)<>''
        GROUP BY unit_id,NO_induk_diknas HAVING COUNT(*)>1
    ) x) AS jumlah_masalah
UNION ALL
SELECT 'duplikat_penempatan' AS pemeriksaan, (SELECT COUNT(*) FROM (
        SELECT unit_id,tahun_ajaran_id,no_induk FROM siswa_tahun_ajaran_data
        GROUP BY unit_id,tahun_ajaran_id,no_induk HAVING COUNT(*)>1
    ) x) AS jumlah_masalah
UNION ALL
SELECT 'penempatan_tanpa_siswa_atau_tahun' AS pemeriksaan, (SELECT COUNT(*) FROM siswa_tahun_ajaran_data p
        LEFT JOIN siswa_data s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id
        LEFT JOIN tahun_ajaran_data y ON y.id=p.tahun_ajaran_id
        WHERE s.id IS NULL OR y.id IS NULL OR s.unit_id<>p.unit_id OR y.unit_id<>p.unit_id) AS jumlah_masalah
UNION ALL
SELECT 'pembayaran_tanpa_siswa' AS pemeriksaan, (SELECT COUNT(*) FROM bayar_data b
        LEFT JOIN siswa_data s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id
        WHERE b.NO_INDUK IS NOT NULL AND (s.id IS NULL OR s.unit_id<>b.unit_id)) AS jumlah_masalah
UNION ALL
SELECT 'total_header_tidak_cocok' AS pemeriksaan, (SELECT COUNT(*) FROM bayar_data b
        LEFT JOIN (SELECT bayar_id,SUM(jumlah) total_du FROM bayar_du_data GROUP BY bayar_id) d ON d.bayar_id=b.id
        WHERE ABS(COALESCE(b.total_jumlah,0)-(
            COALESCE(b.U_PSB,0)+COALESCE(b.U_SPP,0)
            +COALESCE(b.U_KOMITE,0)
            +COALESCE(b.U_LAIN,0)+COALESCE(d.total_du,0)
            -COALESCE(b.potong_spp,0)
        ))>0.01) AS jumlah_masalah
UNION ALL
SELECT 'tagihan_spp_salah_relasi' AS pemeriksaan, (SELECT COUNT(*) FROM tagihan_spp_data t
        LEFT JOIN siswa_tahun_ajaran_data p ON p.id=t.penempatan_id
        LEFT JOIN tahun_ajaran_data y ON y.id=t.tahun_ajaran_id
        WHERE p.id IS NULL OR y.id IS NULL OR p.no_induk<>t.no_induk
          OR p.tahun_ajaran_id<>t.tahun_ajaran_id OR p.unit_id<>t.unit_id OR y.unit_id<>t.unit_id) AS jumlah_masalah
UNION ALL
SELECT 'tagihan_komite_salah_relasi' AS pemeriksaan, (SELECT COUNT(*) FROM tagihan_komite_data t
        LEFT JOIN siswa_tahun_ajaran_data p ON p.id=t.penempatan_id
        WHERE p.id IS NULL OR p.no_induk<>t.no_induk
          OR p.tahun_ajaran_id<>t.tahun_ajaran_id OR p.unit_id<>t.unit_id) AS jumlah_masalah
UNION ALL
SELECT 'tagihan_du_salah_relasi' AS pemeriksaan, (SELECT COUNT(*) FROM tagihan_daftar_ulang_data t
        LEFT JOIN siswa_tahun_ajaran_data p ON p.id=t.penempatan_id
        WHERE p.id IS NULL OR p.no_induk<>t.no_induk
          OR p.tahun_ajaran_id<>t.tahun_ajaran_id OR p.unit_id<>t.unit_id) AS jumlah_masalah
UNION ALL
SELECT 'alokasi_spp_salah_relasi' AS pemeriksaan, (SELECT COUNT(*) FROM spp_alokasi_data a
        LEFT JOIN spp_alokasi_batch_data b ON b.id=a.batch_id
        LEFT JOIN tagihan_spp_data t ON t.id=a.tagihan_spp_id
        WHERE b.id IS NULL OR t.id IS NULL OR b.no_induk<>t.no_induk
          OR a.unit_id<>b.unit_id OR a.unit_id<>t.unit_id) AS jumlah_masalah
UNION ALL
SELECT 'spp_melebihi_tagihan' AS pemeriksaan, (SELECT COUNT(*) FROM (
        SELECT t.id FROM tagihan_spp_data t
        LEFT JOIN spp_alokasi_data a ON a.tagihan_spp_id=t.id
        LEFT JOIN spp_alokasi_batch_data b ON b.id=a.batch_id
        GROUP BY t.id,t.nominal_tagihan
        HAVING COALESCE(SUM(CASE WHEN b.status='active'
            THEN a.nominal_dari_bayar ELSE 0 END),0)>t.nominal_tagihan+0.01
    ) x) AS jumlah_masalah
UNION ALL
SELECT 'komite_melebihi_tagihan' AS pemeriksaan, (SELECT COUNT(*) FROM (
        SELECT t.id FROM tagihan_komite_data t LEFT JOIN bayar_komite_data p ON p.tagihan_komite_id=t.id
        GROUP BY t.id,t.nominal_tagihan HAVING COALESCE(SUM(p.nominal),0)>t.nominal_tagihan+0.01
    ) x) AS jumlah_masalah
UNION ALL
SELECT 'du_melebihi_tagihan' AS pemeriksaan, (SELECT COUNT(*) FROM (
        SELECT t.id FROM tagihan_daftar_ulang_data t LEFT JOIN bayar_du_data p ON p.tagihan_daftar_ulang_id=t.id
        GROUP BY t.id,t.nominal_tagihan HAVING COALESCE(SUM(p.jumlah),0)>t.nominal_tagihan+0.01
    ) x) AS jumlah_masalah
UNION ALL
SELECT 'biaya_lain_melebihi_tagihan' AS pemeriksaan, (SELECT COUNT(*) FROM (
        SELECT t.id FROM tagihan_biaya_lain_data t LEFT JOIN bayar_biaya_lain_data p ON p.tagihan_biaya_lain_id=t.id
        GROUP BY t.id,t.nominal_tagihan HAVING COALESCE(SUM(p.nominal_snapshot),0)>t.nominal_tagihan+0.01
    ) x) AS jumlah_masalah
UNION ALL
SELECT 'saldo_tabungan_tidak_cocok_jurnal' AS pemeriksaan, (SELECT COUNT(*) FROM tabungan_data t
        LEFT JOIN (SELECT unit_id,NO_INDUK,SUM(MASUK) total FROM transaksi_m_data GROUP BY unit_id,NO_INDUK) m ON m.NO_INDUK=t.NO_INDUK AND m.unit_id=t.unit_id
        LEFT JOIN (SELECT unit_id,NO_INDUK,SUM(KELUAR) total FROM transaksi_k_data GROUP BY unit_id,NO_INDUK) k ON k.NO_INDUK=t.NO_INDUK AND k.unit_id=t.unit_id
        WHERE ABS(COALESCE(t.SALDO,0)-COALESCE(m.total,0)+COALESCE(k.total,0))>0.01
           OR COALESCE(t.SALDO,0)<-0.01) AS jumlah_masalah
UNION ALL
SELECT 'duplikat_nis_per_unit' AS pemeriksaan, (SELECT COUNT(*) FROM (SELECT unit_id,NO_INDUK FROM siswa_data GROUP BY unit_id,NO_INDUK HAVING COUNT(*)>1) x) AS jumlah_masalah
UNION ALL
SELECT 'legacy_status_tidak_valid' AS pemeriksaan, (SELECT COUNT(*) FROM siswa_data WHERE legacy_pending=1 AND (is_active<>0 OR KELAS<>'LEGACY' OR master_kelas_id IS NOT NULL)) AS jumlah_masalah
UNION ALL
SELECT 'legacy_metadata_tidak_cocok' AS pemeriksaan, (SELECT COUNT(*) FROM legacy_student_import_data m LEFT JOIN siswa_data s ON s.id=m.student_id AND s.unit_id=m.unit_id WHERE s.id IS NULL) AS jumlah_masalah
UNION ALL
SELECT 'legacy_tanpa_manifest' AS pemeriksaan, (SELECT COUNT(*) FROM siswa_data s LEFT JOIN legacy_student_import_data m ON m.student_id=s.id AND m.unit_id=s.unit_id WHERE s.legacy_pending=1 AND m.id IS NULL) AS jumlah_masalah
UNION ALL
SELECT 'legacy_memiliki_siswa_tahun_ajaran' AS pemeriksaan, (SELECT COUNT(*) FROM siswa_tahun_ajaran_data c JOIN siswa_data s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.no_induk WHERE s.legacy_pending=1) AS jumlah_masalah
UNION ALL
SELECT 'legacy_memiliki_bayar' AS pemeriksaan, (SELECT COUNT(*) FROM bayar_data c JOIN siswa_data s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.NO_INDUK WHERE s.legacy_pending=1) AS jumlah_masalah
UNION ALL
SELECT 'legacy_memiliki_tabungan' AS pemeriksaan, (SELECT COUNT(*) FROM tabungan_data c JOIN siswa_data s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.NO_INDUK WHERE s.legacy_pending=1) AS jumlah_masalah
UNION ALL
SELECT 'legacy_memiliki_tagihan_spp' AS pemeriksaan, (SELECT COUNT(*) FROM tagihan_spp_data c JOIN siswa_data s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.no_induk WHERE s.legacy_pending=1) AS jumlah_masalah
UNION ALL
SELECT 'legacy_memiliki_tagihan_komite' AS pemeriksaan, (SELECT COUNT(*) FROM tagihan_komite_data c JOIN siswa_data s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.no_induk WHERE s.legacy_pending=1) AS jumlah_masalah
UNION ALL
SELECT 'legacy_memiliki_tagihan_daftar_ulang' AS pemeriksaan, (SELECT COUNT(*) FROM tagihan_daftar_ulang_data c JOIN siswa_data s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.no_induk WHERE s.legacy_pending=1) AS jumlah_masalah
UNION ALL
SELECT 'legacy_memiliki_tagihan_biaya_lain' AS pemeriksaan, (SELECT COUNT(*) FROM tagihan_biaya_lain_data c JOIN siswa_data s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.no_induk WHERE s.legacy_pending=1) AS jumlah_masalah
UNION ALL
SELECT 'legacy_memiliki_tagihan_tahunan_siswa' AS pemeriksaan, (SELECT COUNT(*) FROM tagihan_tahunan_siswa_data c JOIN siswa_data s ON s.unit_id=c.unit_id AND s.NO_INDUK=c.no_induk WHERE s.legacy_pending=1) AS jumlah_masalah

UNION ALL
SELECT 'psb_melebihi_master', (SELECT COUNT(*) FROM (SELECT b.unit_id,b.NO_INDUK,SUM(b.U_PSB) paid,s.PSB FROM bayar_data b JOIN siswa_data s ON s.unit_id=b.unit_id AND s.NO_INDUK=b.NO_INDUK GROUP BY b.unit_id,b.NO_INDUK,s.PSB HAVING SUM(b.U_PSB)>s.PSB+0.01) x)
UNION ALL
SELECT 'potongan_nominal_negatif', (SELECT COUNT(*) FROM siswa_data WHERE potongan_spp_nominal<0)
UNION ALL
SELECT 'snapshot_potongan_nominal_tidak_valid', (SELECT COUNT(*) FROM tagihan_spp_data WHERE potongan_nominal_ditetapkan_snapshot<0 OR potongan_nominal_snapshot<0 OR potongan_nominal_snapshot>tarif_dasar_snapshot+0.01 OR potongan_nominal_snapshot>potongan_nominal_ditetapkan_snapshot+0.01)
UNION ALL
SELECT 'migrasi_komponen_belum_selesai', (SELECT COUNT(*) FROM financial_component_migration WHERE stage<>'complete')
) integritas;

COMMIT;
-- Selesai. Jika script terhenti karena error, jalankan ROLLBACK; pada tab ini.
-- Jika ada PERIKSA, simpan hasil dan audit penyebabnya sebelum mengubah data.
