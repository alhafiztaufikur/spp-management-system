# Runbook importer identitas Legacy

## Prasyarat lokal

PHP 8.3 dengan mysqli, PDO SQLite, mbstring dan proc_open; PowerShell Windows/.NET SqlClient; SQL Server LocalDB 2019 di bawah **pengguna Windows pemilik instance**; MySQL 8.4. Runtime LocalDB shared/default tidak diubah. Target `db_spp` trial; target tes harus bernama `db_spp_audit_*`/`db_spp_test_*` dan memakai `SPP_TEST_ALLOW_MUTATION=1`.

Storage default: `C:\laragon\data\spp-legacy-import\<database>`. Dapat memakai `SPP_LEGACY_STORAGE` pada worker **dan server HTTP** secara konsisten. Jangan letakkan di repo/document root; jangan memakai path relatif atau `..`. Setup menerapkan ACL pengguna pemilik, SYSTEM dan Administrators; semua file berisi identitas pribadi harus dilindungi. Jangan commit SQLite, `.dat`, SQL dump, CSV detail atau screenshot nama siswa.

> **Pembaruan 5 Oktober:** tarif operasional tidak lagi memiliki Pangkal; potongan SPP nominal dan DIKNAS opsional. DDL komponen dilakukan dengan worker berhenti, mengikuti [runbook perubahan](PSB_NOMINAL_DATE_AUDIT_20261005.md). Raw sumber dan manifest Legacy dipertahankan.

## Migrasi sebelum worker

1. Hentikan hanya worker milik importer. Cocokkan `worker-process.json`: PID, waktu pembuatan, command line, aplikasi, database, pengguna. Jangan hentikan PID dari catatan lama tanpa pemeriksaan ulang.
2. Aktifkan maintenance SistemSPP `tmp/financial_migration.lock`; aplikasi lain Laragon tetap berjalan. Pastikan tidak ada request transaksi SistemSPP aktif sebelum dump/DDL.
3. Buat dump MySQL baru di luar repository: `--single-transaction --routines --triggers`. Catat SHA-256 dan verifikasi restore pada clone. Pertahankan dump original.
4. Audit default: `php sql/migrate_legacy_students.php`. Penerapan trial membutuhkan `SPP_ALLOW_MAIN_MIGRATION=1`, `--apply --confirm-main=db_spp --backup-file=<dump di luar repo, usia <=1 jam>`. Clone membutuhkan nama dan flag tes. Script tidak mengeksekusi DDL dari request web.
5. DDL MySQL tidak dianggap transaksi rollback. Catatan tahap menunjukkan identitas/indeks/FK, lalu guard/manifest/view. Jika gagal, **pertahankan maintenance**, jangan hidupkan worker, periksa tahap dan pulihkan database dari dump yang telah dibuktikan. Jangan menyelesaikan skema tak dikenal secara spekulatif.
6. Cocokkan seluruh nilai original per ID dan fingerprint tabel keuangan, health, FK dan integritas. Baru buka maintenance.

Contoh PowerShell (sesuaikan lokasi executable/dump yang benar):

```powershell
$env:SPP_ALLOW_MAIN_MIGRATION='1'
& 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe' sql/migrate_legacy_students.php --apply --confirm-main=db_spp '--backup-file=C:\laragon\backups\spp-management-system\pre-legacy-trial.sql'
```

Untuk instalasi kosong gunakan bootstrap existing; tahap multiunit menjalankan skema Legacy final. Jangan menjalankan installer/reset demo pada database trial berisi siswa impor.

## Menjalankan worker

Dari aplikasi, sebagai pengguna Windows pemilik LocalDB:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/start_legacy_worker.ps1
```

Ini memakai pengecualian kebijakan hanya untuk proses tersebut, tidak mengubah kebijakan Windows global. Script mempersiapkan instance khusus yang namanya berasal dari storage, memeriksa manifest, dan menjalankan PHP tersembunyi dengan log privat. Jalankan lagi untuk verifikasi: proses yang sama dipertahankan. Tidak membuat service atau task global. Setelah Windows restart/logoff, jalankan kembali script ini. Jika hendak menjadwalkan startup, gunakan akun/working directory/storage yang sama dan uji sendiri; penjadwalan belum dipasang oleh pekerjaan ini.

Worker melayani satu pekerjaan berat. Heartbeat/health memeriksa instance dan ruang >=1 GiB; UI nonaktif bila prasyarat tidak siap. `--once` tersedia untuk simulasi CLI. Lock mencegah dua worker pada storage/database yang sama. Jangan hapus lock/SQLite untuk memaksa penerapan.

## Penggunaan

1. Super Admin membuka **Backup & Restore → Import Legacy**.
2. Pilih SD/SMP/SMA di sidebar dan unit sumber yang sama. Semua Unit hanya baca. Kategori hanya **Siswa** tersedia.
3. Pilih satu `.dat` 1 byte–100 MiB. Upload mengirim byte nyata; tahapan source/check/extract/validate berasal dari worker. SQL mentah tidak dijalankan.
4. Tinjau jumlah diterima/ditahan/GK/already_imported dan unduh CSV masalah. Pratinjau 100 baris bukan keseluruhan sumber.
5. Ketik `IMPOR LEGACY` untuk penerapan identitas. Menutup modal tidak membatalkan job; buka kembali dari tombol status. Cancel tersedia sampai penerapan dimulai.
6. Cari siswa di Data Siswa/filter Legacy. Aktivasi satu per satu dengan kelas/rombel/tahun/tarif terkonfirmasi. Setelah aktivasi, terbitkan kewajiban melalui master; SPP publication menyiapkan pasangan Komite. Jangan mengartikan tarif/saldo sumber sebagai uang masuk/tunggakan.

Backup berbeda dengan NIS yang sudah ada tidak menimpa. Reimport hash/baris yang sama tidak menggandakan. Baris tertahan membutuhkan pemeriksaan, bukan edit staging diam-diam.

## Pemulihan dan cleanup

- Parent worker berhenti saat ekstraksi: restart memeriksa kepemilikan proses/DB, menghentikan hanya extractor milik job, lalu membersihkan source DB dan menandai gagal. Unggah ulang setelah meninjau log privat.
- Berhenti saat penerapan: transaksi MySQL belum commit akan rollback; jika commit terjadi sebelum checkpoint SQLite, manifest membuat replay idempotent. Jangan menghapus manifest agar job diputar ulang.
- Hash berubah, profil salah, enkripsi/backup pendamping/rusak, identitas bentrok atau akun dicabut: gagal/ditahan, tanpa siswa parsial. API tidak memaparkan kredensial/path internal.
- Storage raw/queue/log dipertahankan untuk penelusuran. Retensi otomatis belum tersedia. Batasi akses dan kapasitas; jangan hapus sumber job aktif. Database source sementara normalnya sudah DROP setelah ekstraksi.
- Cleanup tes hanya setelah hasil tersimpan, cocokkan manifest clone dan nama instance importer. Hentikan HTTP/worker milik clone, DROP clone dan stop/delete **instance clone khusus**. Pertahankan default LocalDB, runtime shared, backup original, dan worker/storage trial yang dibutuhkan.
- Restore seluruh MySQL dari backup adalah prosedur CLI administratif dengan maintenance dan worker berhenti; tombol Restore web tetap nonaktif. Setelah restore lama, periksa skema Legacy dan pindahkan/mulai ulang worker hanya setelah migrasi dan rekonsiliasi.

## Pemeriksaan akhir

`health.php` harus `ok`; `tests/readiness_integrity_audit.php` read-only dan `sql/audit_foreign_keys.php` bersih. Bandingkan fingerprint rekening/jurnal Tabungan serta seluruh pembayaran/alokasi original. Rujuk [audit backend](LEGACY_IMPORT_BACKEND_20261003.md) untuk angka aktual dan batas pengujian.

### Pemeriksaan database lokal melalui DBeaver

Untuk `db_spp` yang sudah dimigrasi, buka [script pemeriksaan DBeaver](../sql/dbeaver_verify_legacy_db_spp.sql) pada koneksi **MySQL Laragon**, lalu jalankan seluruh script melalui Execute SQL Script. Script menetapkan `USE db_spp`, membaca tabel fisik seluruh unit dalam transaksi read-only, kemudian menutup transaksi. Selesaikan transaksi lain milik Anda sebelum menjalankannya. Jika terhenti karena error, jalankan `ROLLBACK;` pada tab yang sama.

Hasil meliputi identitas server/database, tujuh pemeriksaan kontrak skema Legacy, siswa dan manifest per unit, angka pembayaran/Tabungan, serta 26 pemeriksaan integritas. Semua baris pemeriksaan harus `OK` dan `jumlah_masalah=0`. Angka operasional dibaca aktual; tidak dipaksa sama dengan baseline historis karena aplikasi dapat menerima transaksi berikutnya.

Script diuji pada MySQL lokal dan seluruh 33 pemeriksaan lulus. Ini adalah pemeriksaan database yang sudah terpasang; migrasi DDL tetap menggunakan prosedur CLI/backup/maintenance di atas dan impor `.dat` memakai worker. Hasil baris ditahan/GK berada pada staging privat importer, tidak dibuat menjadi siswa dalam SQL ini. Pengujian tidak mencakup pengoperasian antarmuka DBeaver secara langsung.
