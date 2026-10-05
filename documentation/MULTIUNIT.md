# Operasional SD, SMP, dan SMA

## Data demo pada salinan uji

Salin database yang sudah dimigrasi ke database khusus bernama `db_spp_test_*` atau `db_spp_audit_*`. Mode `--apply` hanya bekerja pada salinan uji dengan `SPP_TEST_ALLOW_MUTATION=1` dan tidak mengubah skema. Perintah tanpa opsi penerapan hanya menampilkan jumlah data dan rencana pengisian. Mode `--apply-live` dinonaktifkan; data demo tidak boleh diisikan ke database utama.

```powershell
$env:SPP_DB_NAME='db_spp_test_demo_multiunit'
$env:SPP_TEST_ALLOW_MUTATION='1'
php sql/seed_demo_multiunit.php --as-of=2026-09-30
php sql/seed_demo_multiunit.php --apply --as-of=2026-09-30
php tests/demo_multiunit_reports_test.php 2026-09-30
```

Seeder mengisi SMP dan SMA masing-masing dengan 33 siswa reguler dan 3 PSB, tarif contoh untuk siswa `DEMO`, tagihan SPP/Komite/Daftar Ulang, serta contoh transaksi pada tanggal yang dipilih. Angka tersebut hanya untuk pengujian, bukan tarif resmi. SD tetap memakai data siswa dan keuangan lama; tabungan demo ditambahkan hanya jika seluruh data tabungan SD masih kosong. Eksekusi ulang dengan tanggal yang sama tidak menggandakan data. Gunakan salinan uji baru untuk tanggal contoh yang berbeda.

Untuk menguji jalur pratinjau HTTP, jalankan server PHP terpisah dengan `SPP_DB_NAME` yang sama, atur `SPP_HTTP_BASE` ke alamat server itu, lalu jalankan `php tests/demo_multiunit_export_http_test.php 2026-09-30`.

Gunakan seeder hanya pada clone disposable. Pengulangan pada clone dengan tanggal yang sama tetap memakai kunci tetap dan tidak menggandakan data.

Sistem memakai satu database. Setiap tabel siswa, kelas, master, tagihan, pembayaran, tabungan, dan audit memiliki `unit_id`. Nama tabel lama menjadi view yang otomatis membatasi data sesuai unit sesi. Tabel fisiknya bernama `*_data`; kode aplikasi hanya memakai view. Trigger database memeriksa kelas dan hubungan antartabel saat data ditulis.

## Akun awal

- SD: `bendahara`, `kasir1` sampai `kasir4`. Akun `admin` lama diarsipkan tanpa menghapus riwayat. Akun kasir SD lain tetap nonaktif.
- SMP: `bendahara.smp`, `kasir1.smp` sampai `kasir4.smp`. Akun `admin.smp` lama diarsipkan.
- SMA: `bendahara.sma`, `kasir1.sma` sampai `kasir4.sma`. Akun `admin.sma` lama diarsipkan.
- Super Admin: `superadmin`.

Pada instalasi baru, kata sandi akun yang dibuat oleh `sql/bootstrap_unit_accounts.php` bersifat acak dan dicatat satu kali pada berkas absolut di luar repositori. Akun Admin unit lama diarsipkan tanpa menghapus riwayat; bootstrap baru membuat akun Super Admin, Bendahara dan Kasir. PDF kredensial lama yang masih mencantumkan Admin unit tidak lagi menjadi acuan untuk akun tersebut. Serahkan kredensial aktif lewat jalur aman. Penambahan akun, termasuk Super Admin tambahan, penggantian kata sandi, serta aktivasi akun berikutnya dilakukan oleh Super Admin di Role Management, termasuk dari cakupan Semua Unit.

## Instalasi baru

Skema referensi instalasi baru disimpan sebagai payload non-SQL di `sql/schema.payload`; `sql/schema.sql` hanya menolak impor langsung, termasuk dengan `mysql --force`. Untuk instalasi baru, gunakan `sql/bootstrap_production.php` pada database **kosong**, lalu migrasi unit dan buat akun. Semua langkah yang menulis database utama memerlukan persetujuan pemilik, backup baru, konfirmasi target, serta gate migrasi pada [runbook kesiapan](READINESS_MIGRATION_RUNBOOK_20261001.md). Contoh di bawah berlaku untuk clone disposable:

```powershell
$env:SPP_DB_NAME='db_spp_audit_instalasi_baru'
$env:SPP_TEST_ALLOW_MUTATION='1'
$env:SPP_BOOTSTRAP_TARGET=$env:SPP_DB_NAME
$env:SPP_BOOTSTRAP_ADMIN_USER='admin'
# Siapkan SPP_BOOTSTRAP_ADMIN_PASSWORD secara aman di lingkungan terminal ini.
php sql/bootstrap_production.php --execute
php sql/migrate_units.php
php sql/bootstrap_unit_accounts.php 'C:\lokasi-aman\kredensial-unit.txt'
```

Jangan simpan berkas kredensial di repositori. `sql/bootstrap_production.php` memerlukan `SPP_BOOTSTRAP_TARGET` yang cocok dengan database kosong, username admin, kata sandi awal kuat, dan `--execute`. Username `admin` pada bootstrap awal adalah akun sementara; bootstrap akun unit kemudian mengarsipkannya dan membuat `superadmin`. Jalur berkas kredensial akun unit harus absolut dan berada di luar repository.

## Migrasi database berisi data

1. Hentikan penulisan selama migrasi. Cadangkan database lengkap, termasuk routine dan trigger. Verifikasi hasil cadangan dapat dipulihkan ke database pengujian.
2. Catat jumlah siswa serta jumlah dan total `bayar`, tabungan, dan tagihan SD sebelum migrasi.
3. Setelah persetujuan pemilik dan preflight pada clone, jalankan `sql/migrate_units.php --apply --confirm-main=db_spp --backup-file=<dump-baru>`, lalu `sql/bootstrap_unit_accounts.php <berkas-kredensial-baru> --apply --confirm-main=db_spp --backup-file=<dump-baru>` dengan `SPP_ALLOW_MAIN_MIGRATION=1`. Gate menuntut backup baru di luar repository; lihat [runbook kesiapan](READINESS_MIGRATION_RUNBOOK_20261001.md). Jangan gunakan installer skema baru pada database aktif.
4. Cocokkan kembali jumlah dan total SD, jumlah akun aktif per unit, dan 30 view operasional. Uji login tiap peran dan laporan Semua Unit.

Migrasi menolak tabel yang tidak sesuai atau proses migrasi yang pernah terhenti. Karena perubahan DDL MySQL tidak dapat dibatalkan dengan `ROLLBACK`, pulihkan cadangan jika proses berhenti di tengah. Simpan cadangan hingga hasil verifikasi diterima.

## Hak akses dan laporan

Kasir dan bendahara terikat pada satu unit. Super Admin memilih SD, SMP, atau SMA di sidebar untuk pekerjaan operasional; Role Management tetap tersedia dari **Semua Unit**. Dashboard dan laporan menyediakan pilihan **Semua Unit** untuk cakupan rekap. Cetak, PDF, dan Excel mengikuti cakupan rekap pada URL.

Kelas SD adalah 1–6, SMP 7–9, SMA 10–12. Kelulusan terjadi pada kelas terakhir setiap unit. Siswa yang melanjutkan ke unit baru dibuat sebagai data siswa baru dengan NIS internal unik di seluruh sekolah; NIS Diknas dapat sama di unit berbeda. Riwayat lama tetap pada unit asal.

## Pengujian salinan

Jalankan pengujian integrasi hanya pada salinan database dengan nama `db_spp_test_*`:

```powershell
$env:SPP_DB_NAME='db_spp_test_multiunit'
$env:SPP_TEST_ALLOW_MUTATION='1'
php tests/multiunit_isolation_test.php
php tests/academic_year_billing_test.php
php tests/class_graduation_history_test.php
php tests/billing_history_report_integration_test.php
```
