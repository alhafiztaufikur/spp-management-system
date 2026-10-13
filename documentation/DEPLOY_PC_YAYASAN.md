# Cara Memasang SistemSPP di PC Server Yayasan

Panduan ini untuk **pemasangan baru di PC Windows**, dengan akses dari jaringan lokal yayasan. Database awal tidak berisi siswa atau transaksi contoh. Disusun berdasarkan kode proyek pada 13 Oktober 2026.

1. **Siapkan PC server.**

   Instal Laragon, PHP 8.3, MySQL 8.4, dan Composer. Samakan waktu Windows dengan WIB dan matikan sleep otomatis agar server tetap tersedia. Versi PHP dan MySQL ini sesuai lingkungan yang sudah diuji pada proyek.

2. **Salin aplikasi dan pasang kebutuhannya.**

   Letakkan source code lengkap di `C:\laragon\www\spp-management-system`. Pastikan kode yang disalin sudah mencakup fitur backup terbaru. Buka terminal pada folder tersebut, lalu jalankan:

   ```powershell
   composer install --no-dev
   composer check-platform-reqs --no-dev
   ```

   Selesaikan kekurangan ekstensi PHP yang dilaporkan. Pastikan juga PDO SQLite aktif karena dipakai oleh layanan backup.

3. **Siapkan database baru dan akun awal.**

   Jika memakai paket dari folder `deployment`, ikuti `BACA_DULU.md` di dalam paket: impor `db_spp_clean.sql` pada MySQL PC server baru dan gunakan `AKUN_AWAL_PRIVAT.txt`. Database paket sudah memiliki struktur final dan akun baru, sehingga tahap bootstrap/migrasi berikut tidak perlu dijalankan lagi.

   Jika tidak memakai paket tersebut, gunakan jalur instalasi baru berikut.

   Buat database kosong bernama `db_spp`. Penyiapan database oleh pengelola server mengikuti urutan berikut: buat struktur awal melalui `sql/bootstrap_production.php`, cadangkan hasilnya, jalankan `sql/migrate_units.php`, lalu buat akun melalui `sql/bootstrap_unit_accounts.php`.

   Gunakan username sementara `admin` pada tahap pertama. Tahap terakhir membuat akun `superadmin` dan akun petugas unit, dengan kata sandi acak yang disimpan dalam berkas privat. Parameter perintah, konfirmasi target, dan syarat backup mengikuti [panduan instalasi database](MULTIUNIT.md#instalasi-baru) serta [panduan migrasi](READINESS_MIGRATION_RUNBOOK_20261001.md).

   Jangan mengimpor `sql/schema.sql` langsung. Backup dari halaman Backup & Restore berisi seluruh data database sumber, sehingga bukan paket database kosong.

4. **Hubungkan aplikasi ke database.**

   Siapkan akun MySQL khusus aplikasi dan atur host, nama database, username, password, serta port melalui konfigurasi `SPP_DB_*`. Pastikan konfigurasi terbaca oleh Apache dan proses PHP di terminal. Simpan kredensial di luar source code. Akun administrator MySQL dipakai untuk pemasangan dan penyiapan akun backup.

5. **Atur layanan dan akses jaringan.**

   Atur Apache dan MySQL agar berjalan otomatis setelah PC dinyalakan, termasuk sebelum pengguna login Windows. Pemasangan Laragon saja belum menyiapkan layanan server untuk kondisi tersebut.

   Tetapkan IP server yang tetap dan alamat aplikasi untuk komputer petugas. Batasi firewall pada akses web dari jaringan yayasan; MySQL cukup menerima koneksi lokal. Siapkan HTTPS dan batasi akses browser ke folder internal seperti `.git`, `sql`, `tests`, `scripts`, `includes`, serta `vendor`. Matikan tampilan daftar isi folder dan pesan error teknis di browser.

6. **Lengkapi pengaturan sekolah.**

   Buka aplikasi dan login sebagai Super Admin. Periksa identitas sekolah, kelas/rombel, tahun ajaran, serta tarif untuk SD, SMP, dan SMA. Atur akun petugas dan serahkan kata sandi melalui jalur yang aman. Periksa nominal sebelum menerbitkan tagihan.

7. **Aktifkan backup harian pada PC server.**

   Buka PowerShell sebagai Administrator menggunakan akun Windows pengelola aplikasi. Dari folder proyek, jalankan:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\install_database_backup.ps1 -ConfirmMain -RegisterTask -RunNow
   ```

   Pemasang memakai runtime dari konfigurasi existing atau mendeteksi PHP aktif Apache/PATH dan executable MySQL utama. Jika lokasi tidak dapat ditentukan, isi `-PhpExecutable` dan `-MysqlBin` mengikuti [panduan backup](DAILY_DATABASE_BACKUP.md#pemasangan-dan-pemeriksaan). MySQL yang didukung: seri 8.0 mulai 8.0.30 dan seri 8.4. Pastikan PDO SQLite aktif pada PHP Apache maupun CLI. Jika Windows menolak pendaftaran task, jalankan perintah sekali dari PowerShell Administrator dengan akun pemilik yang sama. Siapkan kredensial administrator MySQL melalui berkas opsi privat atau environment sesuai panduan tersebut.

   Jangan menyalin konfigurasi privat atau task backup dari PC pengembangan. Installer membuatnya kembali sesuai identitas PC server. Pastikan backup pertama **Terverifikasi**. Pada **Backup & Restore > Pengaturan Backup Harian**, pilih jam WIB dan penyimpanan 1–365 hari, lalu simpan; periksa **Jadwal Berikutnya**. Nilai awal formulir 18.00 WIB/90 hari; sebelum disimpan cadangan lama tetap dipertahankan. Cadangan manual tidak ikut rotasi. Simpan juga salinan backup pada media terpisah.

8. **Siapkan impor data lama jika diperlukan.**

   Untuk impor berkas `.dat`, pasang SQL Server LocalDB 2019 dan jalankan worker sesuai [panduan Import Legacy](LEGACY_IMPORT_RUNBOOK_20261003.md). Worker ini perlu dijalankan kembali setelah restart atau logoff. Jika tidak memakai Import Legacy, langkah ini dapat dilewati.

9. **Lakukan pemeriksaan sebelum mulai digunakan.**

   Pastikan `health.php` menampilkan `ok` dan pemeriksaan integritas database lulus. Uji login setiap peran, akses dari komputer petugas, pembayaran, Tabungan, laporan, serta cetak/PDF/Excel pada salinan database uji. Uji backup dan pemulihannya pada salinan tersebut.

   Restart PC server. Periksa kembali akses aplikasi tanpa membuka Laragon secara manual, layanan backup, dan worker Legacy jika digunakan. Setelah seluruh pemeriksaan lulus, mulai masukkan atau impor data sekolah pada database operasional.

**Catatan:** pemasangan pada PC yayasan tetap perlu diperiksa langsung di komputer tersebut. Jangan menjalankan ulang installer database atau reset demo pada database yang sudah berisi data operasional.
