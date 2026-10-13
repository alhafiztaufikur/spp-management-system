# Backup database lokal setiap hari

Pencadangan mengambil seluruh database `db_spp` (SD, SMP, SMA), terlepas dari unit operasional. Jadwal utama **18.00 Asia/Jakarta (WIB)**. Backup manual memakai antrean dan penguncian yang sama. Tidak ada penghapusan cadangan otomatis, dan Restore melalui web tetap nonaktif.

**Status lokal 13 Oktober 2026:** task Windows aktif dan cadangan **SQL biasa (`.sql`)** sudah dibuat melalui task sebenarnya. Pekerjaan `e3c690811c6335a267448807e7fd4633` dimulai pukul 10.30.35 WIB dan selesai **Terverifikasi** pukul 10.30.55 WIB, berukuran **10.940.780 byte**. `LastTaskResult=0`, layanan siap, tanpa peringatan aktif. Jadwal harian berikutnya **13 Oktober 2026 pukul 18.00 WIB**.

Cadangan baru berupa berkas `.sql` yang dapat dibuka sebagai teks atau dipulihkan melalui klien MySQL oleh administrator. Cadangan `.sql.gz` yang dibuat sebelumnya tetap dipertahankan dan dapat diverifikasi ulang; unduhan web membacanya dengan pemeriksaan integritas lalu mengirim hasil sebagai `.sql`. Tidak diperlukan konversi atau penghapusan arsip lama secara manual.

## Lokasi privat dan hak akses

- Konfigurasi: `C:\laragon\data\spp-backup\db_spp\config.json`.
- Akun dump: `source-client.cnf` di direktori konfigurasi, dengan password acak. Akun `spp_backup_local@127.0.0.1` memiliki izin mengekspor objek `db_spp`, tanpa `INSERT`/`UPDATE`/`DELETE` pada tabel. MySQL mensyaratkan `TRIGGER`/`EVENT` untuk mengekspor objek tersebut dan `SHOW_ROUTINE` untuk membaca definisi routine; `EXECUTE` dibatasi pada fungsi konteks unit jika tersedia.
- Cadangan dan hasil verifikasi: `C:\laragon\backups\spp-management-system\daily\db_spp`. Berkas SQL baru berada di `jobs\<id>\backup.sql` bersama manifest dan laporan verifikasi; nama unduhan memakai waktu pencadangan, `SistemSPP_<tanggal>_<waktu>_<id-singkat>.sql`. Arsip lama tidak dipindahkan atau dihapus.
- ACL direktori hanya mengizinkan akun Windows pemilik aplikasi, SYSTEM, dan Administrators. Dump, konfigurasi, password, log privat, dan direktori verifikasi tidak masuk Git/webroot.

Task menggunakan akun Windows pemilik aplikasi melalui **S4U**, sehingga berjalan ketika pengguna tidak login tanpa menyimpan password Windows. Aplikasi web dan runner harus berjalan di komputer lokal dengan akun yang berhak mengakses direktori privat. Identitas server sumber dipatok melalui UUID dan datadir; perubahan identitas memerlukan pemeriksaan administratif.

## Pemasangan dan pemeriksaan

Sebelum pemasangan, jalankan pengujian pada salinan database dan pastikan executable PHP/MySQL sesuai instalasi lokal. Pemasang ditujukan untuk MySQL 8.4 dan tidak mengubah skema/tabel aplikasi. Penyediaan akun MySQL membutuhkan administrator database; gunakan opsi privat `-AdminDefaultsFile` atau environment lokal `SPP_DB_USER`/`SPP_DB_PASS`. Jangan menulis password dalam command line atau Git.

Jalankan PowerShell sebagai akun pemilik aplikasi; hak Administrator Windows mungkin diperlukan **sekali** untuk mendaftarkan task S4U/Boot. Tidak ada fallback ke SYSTEM atau task yang hanya berjalan ketika login.

```powershell
# Menyiapkan konfigurasi, ACL, dan akun MySQL ekspor.
.\scripts\install_database_backup.ps1 -ConfirmMain

# Setelah tes lulus: mendaftarkan task dan menjalankannya melalui Task Scheduler.
.\scripts\install_database_backup.ps1 -ConfirmMain -RegisterTask -RunNow

Get-ScheduledTask -TaskName 'SistemSPP Database Backup'
Get-ScheduledTaskInfo -TaskName 'SistemSPP Database Backup'
```

Parameter `-PhpExecutable`, `-MysqlBin`, `-StorageRoot`, `-ConfigFile`, dan `-TaskName` tersedia bila lokasi berbeda. Jalankan ulang dengan parameter yang sama. Pemasang memeriksa manifest dan kepemilikan akun/task melalui SID Windows; nama singkat seperti `lakch` dan nama lengkap `KOMPUTER\lakch` dapat menunjuk pemilik yang sama. Task bernama sama atau akun yang tidak dapat dibuktikan milik pemasang tidak diambil alih. Jika Windows menolak pendaftaran, ulangi sebagai Administrator dengan **akun pemilik yang sama**. Konfigurasi privat yang sudah dibuat tetap dipertahankan.

Pemasang juga memeriksa konfigurasi task yang ada. Task milik pemasang dengan jadwal dan pengamanan yang benar digunakan kembali; pemasangan ulang lokal dengan `-ConfirmMain -RegisterTask -RunNow` telah selesai dengan kode `0` tanpa UAC tambahan. Task yang hilang atau jadwal/pengamanannya tidak sesuai tetap memerlukan pendaftaran/perbaikan sebagai Administrator; pemasang tidak menurunkan hak akses atau mengabaikan konflik kepemilikan.

Jurnal penyediaan disimpan sebelum akun/task dibuat sehingga instalasi terputus dapat dilanjutkan. Akun existing hanya dilanjutkan bila sumber, pemilik dan autentikasi cocok dengan jurnal privat; akun tanpa bukti kepemilikan tidak diambil alih. Jangan menghapus jurnal atau berkas opsi untuk mencoba melewati pemeriksaan.

Periksa hasil pertama di halaman **Backup & Restore**: status harus **Terverifikasi**, bukan sekadar file berhasil dibuat. Periksa task mempunyai trigger harian 18.00 dengan zona +07:00, antrean setiap menit, startup dengan jeda satu menit, `StartWhenAvailable`, dan kebijakan `IgnoreNew`. `NextRunTime` dapat menunjuk pemeriksaan antrean menit berikutnya; jadwal pencadangan utama ditampilkan terpisah di aplikasi.

## Cara kerja dan kondisi tertunda

Runner menggunakan waktu WIB, snapshot InnoDB konsisten, penguncian proses tunggal, checksum SHA-256, dan publikasi atomik. Backup tidak dijalankan selama maintenance. Perubahan definisi objek selama dump menyebabkan cadangan ditolak.

Jika komputer/MySQL tidak tersedia pukul 18.00, runner membuat satu cadangan keadaan terbaru sebagai **Susulan** setelah layanan kembali. Waktu sebenarnya dan jadwal yang terlewat dicatat; data tidak diberi waktu seolah dicadangkan kemarin. Kegagalan sementara dicoba kembali setiap 15 menit. Klik manual berulang tidak membuat pekerjaan ganda.

Manifest mencatat `schedule_window` (jadwal awal/akhir dan jumlah jadwal yang terlewat), bukan membuat arsip palsu untuk setiap tanggal. Fingerprint tabel dihitung bertahap dengan urutan primary key; tabel masa depan tanpa primary key memakai pengurutan eksternal terbatas. Data besar tidak dimuat sekaligus ke memori PHP.

Tahapan: **Menunggu → Mencadangkan → Memverifikasi → Terverifikasi/Gagal**. Hasil yang gagal tetap tersedia untuk diagnosis privat; unduhan web hanya tersedia untuk hasil terverifikasi dengan checksum yang sesuai. Cadangan baik sebelumnya tidak ditimpa atau dihapus.

Ruang di bawah 10 GiB menghasilkan peringatan. Jika tidak mencukupi untuk dump dan pemulihan terisolasi, pekerjaan baru dihentikan. Tambahkan ruang atau pindahkan arsip secara administratif setelah memeriksa integritasnya; sistem tidak menghapus cadangan otomatis.

## Pemulihan pengujian dan diagnosis

Setiap dump diuji pada proses `mysqld` terpisah, port loopback acak, datadir privat baru, password acak, dan event scheduler nonaktif. Nama database tetap sama seperti sumber agar view lintas referensi tidak membutuhkan penggantian SQL. UUID/datadir harus berbeda dari sumber sebelum SQL diimpor.

Laporan pekerjaan memuat definisi objek, jumlah data, ringkasan keuangan, hasil pemeriksaan integritas, dan fingerprint hasil pemulihan. Status Terverifikasi membutuhkan audit lulus dan pembersihan instance berhasil. Log rinci hanya dibaca administrator melalui direktori privat pekerjaan.

Jika proses terputus dan instance verifikasi tertinggal, runner melakukan pemulihan aman dari manifest. Pemulihan manual menggunakan direktori pekerjaan yang benar:

```powershell
.\scripts\verify_backup_instance.ps1 `
  -ConfigFile 'C:\laragon\data\spp-backup\db_spp\config.json' `
  -JobDirectory 'C:\laragon\backups\spp-management-system\daily\db_spp\jobs\<id-32-heksadesimal>' `
  -Recover
```

Alat hanya menghentikan proses yang cocok dengan PID, waktu pembuatan, executable, dan datadir di manifest. Jika kepemilikan tidak cocok, proses/data dipertahankan. Jangan menghentikan semua `mysqld`, menghapus datadir Laragon, atau menghapus manifest sebelum pemeriksaan administratif.

Untuk menguji ulang arsip terverifikasi tanpa menyentuh database operasional:

```powershell
& 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe' `
  .\scripts\database_backup_runner.php `
  --config=C:\laragon\data\spp-backup\db_spp\config.json `
  --verify --id=<id-32-heksadesimal>
```

Perintah memakai lock yang sama dengan backup, memeriksa checksum berkas SQL atau arsip gzip lama beserta SQL di dalamnya, memulihkan instance baru, dan memperbarui laporan verifikasi privat. Kegagalan pengujian ulang mempertahankan cadangan sebelumnya; unduhan `.sql` tetap memeriksa integritas cadangan sumber. Berkas pekerjaan gagal tidak dapat diunduh. Untuk kegagalan verifikasi, tinjau log privat, perbaiki penyebabnya, lalu buat backup baru; retry otomatis hanya untuk kegagalan sumber/dump/ruang dan proses terputus.

## Bukti pengujian 13 Oktober 2026

- Model: 24 kasus zona/waktu, batas 18.00/pergantian tanggal, susulan beberapa hari, retry 15 menit, deduplikasi dan batas ruang; guard instance operasional ditolak.
- API: 108 permintaan, empat peran/cakupan, anonim/nonaktif, CSRF, bentuk parameter/ID/path, pengiriman ulang serta unduhan SQL/kompatibilitas gzip lama dengan checksum rusak. Fingerprint data salinan tetap.
- Browser: 24 kombinasi desktop/tablet/ponsel, terang/gelap dan empat unit; tombol Semua Unit, keyboard, retry jaringan, tahapan, pengiriman ganda, pencarian/filter dan formulir tanpa JavaScript. Screenshot desktop/ponsel diperiksa.
- Runner nyata: akun ekspor dengan grant minimum, gzip dipulihkan, 36 tabel fisik/31 view/2 routine/91 trigger, 64 FK/19 CHECK, health dan audit keuangan tiga unit lulus. Kegagalan disk/dump/skema/verifikasi, MySQL tidak tersedia, lock proses, gzip terpotong, laporan lama dan pemulihan proses terputus diuji pada instance terpisah. Dua belas checksum tabel keuangan sumber uji tetap sama.
- Fingerprint bertahap: pemulihan penuh serta tes baca sintetis 0/9.000/135.000 baris lulus, termasuk penggabungan beberapa putaran dan pembersihan workspace.
- Format SQL lanjutan: snapshot/pemulihan SQL baru, cache unduhan yang diperiksa checksum serta verifikasi ulang gzip lama lulus pada instance terisolasi. Normalisasi definisi skema lulus 35 kasus serta perbandingan inventaris sumber/pemulihan yang ditangkap; pemeriksaan identitas/jadwal pemasang lulus 34 kasus.
- Task aktual: cadangan SQL `e3c690811c6335a267448807e7fd4633` lulus pemulihan terisolasi, mulai 13 Oktober pukul 10.30.35 dan selesai 10.30.55 WIB, 10.940.780 byte. Jenis **Susulan** untuk jadwal 12 Oktober pukul 18.00 WIB; waktu pembuatan tetap memakai waktu sebenarnya. Task mengembalikan kode `0`, layanan siap dan peringatan kosong, dengan jadwal berikutnya 13 Oktober pukul 18.00 WIB. Trigger harian 18.00 WIB, pemeriksaan antrean `PT1M`, startup berjeda `PT1M`, S4U dan hak terbatas telah diperiksa pada task yang terpasang.
- Diagnosis awal: tiga percobaan gzip yang gagal tetap disimpan. Perbedaan representasi definisi skema pada pemeriksaan sumber/pemulihan telah dinormalisasi; berkas yang gagal tidak dinyatakan Terverifikasi atau ditawarkan untuk diunduh.
- Operasional: fingerprint seluruh tabel `db_spp` sebelum/sesudah penyiapan akun identik. Tidak ada migrasi aplikasi; pencadangan dan verifikasi tidak menulis data siswa, tagihan atau keuangan.

Semua dump, password, screenshot, log rinci dan hasil uji berada di direktori QA privat di luar Git/webroot. Tes mutasi wajib memakai database `db_spp_audit_*` atau manifest instance terisolasi yang mematok UUID/datadir/port berbeda dari operasional. Jangan menetapkan opt-in mutasi untuk koneksi operasional.

## Latihan pemulihan dan batas perlindungan

Simpan salinan cadangan terverifikasi di media lain bila dibutuhkan perlindungan terhadap kerusakan/kehilangan disk. Penyimpanan pada disk komputer yang sama belum melindungi dari kejadian tersebut.

Lakukan latihan pemulihan ke instance terisolasi secara berkala dan setelah perubahan skema. Untuk pemulihan operasional, administrator harus memilih cadangan terverifikasi, memastikan checksum, membuat cadangan kondisi terkini, menghentikan transaksi melalui maintenance, memulihkan dalam lingkungan terkontrol, lalu memeriksa jumlah data/integritas seluruh unit sebelum membuka layanan. Restore web sengaja tidak tersedia; panduan ini bukan izin menimpa database operasional tanpa keputusan administratif.
