# Backup database lokal setiap hari

Pencadangan mengambil seluruh database `db_spp` (SD, SMP, SMA), terlepas dari unit operasional. Super Admin mengatur **Jam Backup (WIB)** dan **Simpan Cadangan Selama (hari)** melalui halaman **Backup & Restore**. Backup manual dan pemulihan memakai worker privat, dengan pemeriksaan identitas database serta penguncian proses.

## Memulihkan melalui halaman aplikasi

1. Masuk sebagai **Super Admin aktif**. Pilih SQL hasil unduhan cadangan **Terverifikasi** pada riwayat PC ini, maksimal 100 MiB. File harus cocok persis dengan checksum cadangan yang masih tersedia; SQL asing, berkas yang diedit, dan cadangan dengan skema berbeda ditolak. Impor dari PC lain tetap memakai prosedur administratif terkontrol.
2. Tekan **Periksa File & Tinjau Pemulihan**. File diunggah ke penyimpanan privat dan dipulihkan pada MySQL terisolasi. Struktur, fingerprint seluruh tabel, relasi, kesehatan aplikasi, keuangan dan cakupan unit dicocokkan dengan bukti cadangan. Database operasional belum diganti pada tahap ini. Hasil pemeriksaan berlaku satu jam.
3. Setelah **Terverifikasi**, centang persetujuan dan ketik **PULIHKAN DATABASE**. Tombol **Pulihkan Database** baru aktif setelah kedua syarat dipenuhi. Pemulihan mengganti seluruh data siswa, pembayaran, Tabungan, akun dan pengaturan SD/SMP/SMA. Gunakan akun/kata sandi yang tersimpan pada tanggal cadangan setelah selesai.
4. Worker menghentikan sementara akses aplikasi, menunggu permintaan yang sedang berjalan selesai, lalu membuat **backup pengaman Manual yang terverifikasi**. Jika tahap ini gagal, database tidak diganti. Akun ekspor tetap berizin baca; pemulihan memakai izin koneksi aplikasi yang diperiksa ulang. Akun MySQL aplikasi harus memiliki izin DDL/impor pada database tujuan; server dengan event terjadwal memerlukan event scheduler OFF secara administratif sebelum pemulihan.
5. Setelah impor, snapshot hasil dipulihkan dan diaudit lagi pada instance terisolasi; seluruh nilai harus cocok. Jika impor/audit gagal, worker mengembalikan backup pengaman dan memeriksanya. Backup pengaman tetap tersedia pada Riwayat Backup dan tidak ikut rotasi otomatis.
6. Halaman **Lihat Progres Pemulihan** (`restore_status.php`) tetap tersedia selama pemeliharaan, termasuk setelah refresh. Proses tidak bergantung pada tab browser atau task harian. Setelah selesai atau backup pengaman berhasil dikembalikan, masuk kembali; seluruh sesi lama dinyatakan tidak berlaku.

Jika proses terputus setelah penggantian dimulai, penanda pemeliharaan tetap menutup akses database. Polling melanjutkan pemulihan dari backup pengaman, bukan mengulangi impor pilihan secara membabi buta. Status **Pemulihan Pengaman Tertunda** menyediakan **Lanjutkan Pemulihan Pengaman** untuk mencoba kembali setelah gangguan diperbaiki. Jangan menghapus penanda pemeliharaan secara manual. Jika sesi pemilik tidak lagi tersedia, pengelola dapat menjalankan `scripts/database_restore_runner.php --config=<konfigurasi-privat> --job=<id-pemulihan>` dari CLI pada akun Windows pemilik; runner tetap memeriksa identitas/jurnal/backup pengaman. Worker Legacy berhenti setelah generasi database berubah dan harus dimulai ulang setelah antreannya ditinjau.

Unggahan menggunakan batas PHP `upload_max_filesize` dan `post_max_size` selain batas aplikasi 100 MiB. Data SQL dan kredensial sementara tidak disimpan di webroot/Git atau argumen proses. Kredensial impor dibuat hanya sesudah konfirmasi, dihapus setelah pekerjaan terminal, dan dipertahankan privat bila pemulihan pengaman masih memerlukannya. Menutup dialog hanya menutup tampilan; proses yang berjalan tetap dilanjutkan. Restore membutuhkan JavaScript; backup manual dan pengaturan tetap mendukung formulir native.

## Membuat backup manual

Klik **Buat Backup Sekarang** untuk menjalankan backup manual di latar belakang. Tombol tersedia setelah konfigurasi, PHP CLI dan PowerShell siap; **task harian Windows tidak diperlukan untuk backup manual**. Halaman langsung menerima pekerjaan lalu memperbarui tahapan **Menunggu → Mencadangkan → Memverifikasi → Terverifikasi/Gagal**. Setelah Terverifikasi, pilih **Unduh** untuk mengambil SQL.

Menutup atau memuat ulang halaman tidak menghentikan pekerjaan. Pengiriman ulang memakai ID pekerjaan yang sama dan tidak membuat dua snapshot. Jika proses lain memegang penguncian backup, worker manual menunggu di latar belakang tanpa menahan permintaan web. Proses yang terputus dapat diperiksa kembali melalui tombol; kegagalan pemicu menampilkan pesan dan kunci permintaan berikutnya agar dapat dicoba kembali. Formulir native tanpa JavaScript tetap bekerja melalui POST/Redirect/GET.

Backup manual memakai verifikasi pemulihan, checksum, CSRF dan hak Super Admin yang sama. Mode CLI privat `--job=<id>` menjalankan pekerjaan yang sudah diterima, tanpa membuat jadwal baru atau mengubah jam/retensi. Launcher membuka PHP CLI sebagai akun pemilik aplikasi dengan jendela tersembunyi; tidak meminta Administrator atau menyimpan password Windows. Backup manual tidak memperbarui heartbeat task harian dan tidak mengaktifkan rotasi cadangan otomatis. Registrasi task Windows tetap diperlukan untuk backup otomatis saat tidak ada pengguna membuka aplikasi.

## Mengatur jam dan penyimpanan

1. Buka **Backup & Restore > Pengaturan Backup Harian** sebagai Super Admin aktif.
2. Pilih jam `HH:mm` dalam WIB dan lama penyimpanan **1–365 hari**, lalu tekan **Simpan Pengaturan**. Formulir awal berisi **18.00 WIB** dan **90 hari**. Sebelum penyimpanan pertama, jadwal lama tetap 18.00 WIB dan cadangan lama belum dibersihkan.
3. Periksa keterangan pengaturan aktif dan **Jadwal Berikutnya**. Pengaturan berlaku untuk seluruh unit, tersimpan setelah refresh/restart, dan dapat disimpan juga tanpa JavaScript.

Jam baru berlaku pada waktu jadwal berikutnya setelah disimpan, tanpa membuat backup langsung. Pekerjaan yang sudah diterima tetap selesai. Jika tanggal jadwal tersebut sudah mempunyai backup otomatis terverifikasi, jadwal berikutnya bergeser ke hari berikutnya; perubahan jam tidak menggandakan backup harian. Perubahan interval saja tidak menggeser jam yang telah disimpan. Bila petugas lain menyimpan pengaturan lebih dahulu, muat ulang halaman sebelum menyimpan kembali.

Cadangan **Terjadwal/Susulan** yang melewati umur penyimpanan dibersihkan hanya sesudah **backup otomatis baru berhasil diverifikasi**. Batas umur dihitung dari waktu pembuatan berkas pekerjaan, bukan tanggal jadwal yang terlewat, dengan WIB. Cadangan otomatis lama ikut aturan baru; cadangan **Manual** tetap disimpan. Memperpendek interval tidak langsung menghapus berkas. Contoh: interval 90 hari membersihkan cadangan otomatis yang usianya lebih dari 90 hari setelah pengganti terverifikasi tersedia.

Sistem membuat cadangan baru dahulu, kemudian menghapus berkas SQL/gzip/cache kedaluwarsa yang identitas serta checksum-nya cocok. Riwayat dan laporan verifikasi tetap ada dengan status **Kedaluwarsa** tanpa tautan unduh; tautan lama memberikan pesan kedaluwarsa. Unduhan atau verifikasi yang masih memakai cadangan menunda pembersihan. Proses yang terputus dilanjutkan dengan penguncian yang sama. Tanpa keberhasilan otomatis baru, pemeriksaan antrean tidak terus memajukan batas pembersihan. Cadangan gagal, log diagnosis, serta cadangan manual tetap memerlukan pengelolaan administratif bila ruang disk menipis.

Pengaturan dan catatan perubahannya berada dalam SQLite privat, terpisah dari database aplikasi. Tidak ada migrasi tabel keuangan. Formulir tidak mengubah kredensial, lokasi penyimpanan, ataupun database operasional.

**Catatan verifikasi instalasi sebelumnya, 13 Oktober 2026:** task Windows aktif dan cadangan **SQL biasa (`.sql`)** dibuat melalui task sebenarnya. Pekerjaan `e3c690811c6335a267448807e7fd4633` dimulai pukul 10.30.35 WIB dan selesai **Terverifikasi** pukul 10.30.55 WIB, berukuran **10.940.780 byte**. `LastTaskResult=0`, layanan siap, tanpa peringatan aktif; jadwal saat itu 18.00 WIB. Catatan ini bukan bukti pemasangan pada PC lain; periksa layanan dan hasil backup pada PC tujuan.

Cadangan baru berupa berkas `.sql` yang dapat dibuka sebagai teks atau dipulihkan melalui klien MySQL oleh administrator. Cadangan `.sql.gz` sebelumnya tetap kompatibel selama belum kedaluwarsa; unduhan web memeriksa integritas lalu mengirim hasil sebagai `.sql`. Tidak diperlukan konversi arsip lama secara manual.

**Pemeriksaan localhost pada PC saat ini:** PDO SQLite diaktifkan pada PHP Apache/CLI dan konfigurasi privat dipasang. Input jam, retensi serta tombol Simpan Pengaturan sudah aktif. Backup CLI `8e633febc6c7822fd11feb8c28588e64` selesai Terverifikasi pada 13 Oktober pukul 13.22.42 WIB, SQL 10.961.702 byte; unduhan HTTP sesuai checksum dan 36 tabel aplikasi tetap identik. Task Windows **belum terdaftar** karena pendaftaran tanpa administrator ditolak dan konfirmasi UAC dibatalkan. Selesaikan langkah pendaftaran di bawah melalui PowerShell Administrator, dengan akun pemilik yang sama. Eksekusi CLI ini bukan bukti jadwal harian aktif.

## Lokasi privat dan hak akses

- Konfigurasi: `C:\laragon\data\spp-backup\db_spp\config.json`.
- Akun dump: `source-client.cnf` di direktori konfigurasi, dengan password acak. Akun `spp_backup_local@127.0.0.1` memiliki izin mengekspor objek `db_spp`, tanpa `INSERT`/`UPDATE`/`DELETE` pada tabel. MySQL mensyaratkan `TRIGGER`/`EVENT` untuk mengekspor objek tersebut dan `SHOW_ROUTINE` untuk membaca definisi routine; `EXECUTE` dibatasi pada fungsi konteks unit jika tersedia.
- Cadangan dan hasil verifikasi: `C:\laragon\backups\spp-management-system\daily\db_spp`. Berkas SQL baru berada di `jobs\<id>\backup.sql` bersama manifest dan laporan verifikasi; nama unduhan memakai waktu pencadangan, `SistemSPP_<tanggal>_<waktu>_<id-singkat>.sql`. Hanya payload otomatis kedaluwarsa yang dibersihkan; direktori, riwayat, dan metadata dipertahankan.
- ACL direktori hanya mengizinkan akun Windows pemilik aplikasi, SYSTEM, dan Administrators. Dump, konfigurasi, password, log privat, dan direktori verifikasi tidak masuk Git/webroot.

Task menggunakan akun Windows pemilik aplikasi melalui **S4U**, sehingga berjalan ketika pengguna tidak login tanpa menyimpan password Windows. Aplikasi web dan runner harus berjalan di komputer lokal dengan akun yang berhak mengakses direktori privat. Identitas server sumber dipatok melalui UUID dan datadir; perubahan identitas memerlukan pemeriksaan administratif.

## Pemasangan dan pemeriksaan

Sebelum pemasangan, jalankan pengujian pada salinan database dan pastikan executable PHP/MySQL sesuai instalasi lokal. Pemasang mendukung MySQL seri 8.0 mulai 8.0.30 dan seri 8.4, tanpa mengubah skema/tabel aplikasi. Aktifkan PDO SQLite pada PHP Apache/CLI dan muat ulang Apache bila ekstensi baru diaktifkan. Penyediaan akun MySQL membutuhkan administrator database; gunakan opsi privat `-AdminDefaultsFile` atau environment lokal `SPP_DB_USER`/`SPP_DB_PASS`. Jangan menulis password dalam command line atau Git.

Jalankan PowerShell sebagai akun pemilik aplikasi; hak Administrator Windows mungkin diperlukan **sekali** untuk mendaftarkan task S4U/Boot. Jika muncul Access is denied, buka PowerShell sebagai Administrator menggunakan akun pemilik yang sama, lalu jalankan kembali perintah pendaftaran. Tidak ada fallback ke SYSTEM atau task yang hanya berjalan ketika login.

```powershell
# Menyiapkan konfigurasi, ACL, dan akun MySQL ekspor.
.\scripts\install_database_backup.ps1 -ConfirmMain

# Setelah tes lulus: mendaftarkan task dan menjalankannya melalui Task Scheduler.
.\scripts\install_database_backup.ps1 -ConfirmMain -RegisterTask -RunNow

Get-ScheduledTask -TaskName 'SistemSPP Database Backup'
Get-ScheduledTaskInfo -TaskName 'SistemSPP Database Backup'
```

Parameter `-PhpExecutable`, `-MysqlBin`, `-StorageRoot`, `-ConfigFile`, dan `-TaskName` tersedia bila lokasi berbeda. Jika runtime tidak diisi, pemasang memakai konfigurasi existing, PHP aktif dari Apache/PATH, serta executable proses MySQL utama yang tunggal; lokasi ambigu ditolak agar dapat diisi secara eksplisit. Tidak ada asumsi folder versi PHP/MySQL milik PC pengembang. Jalankan ulang dengan parameter yang sama. Pemasang memeriksa manifest dan kepemilikan akun/task melalui SID Windows; nama singkat seperti `lakch` dan nama lengkap `KOMPUTER\lakch` dapat menunjuk pemilik yang sama. Task bernama sama atau akun yang tidak dapat dibuktikan milik pemasang tidak diambil alih. Jika Windows menolak pendaftaran, ulangi sebagai Administrator dengan **akun pemilik yang sama**. Konfigurasi privat yang sudah dibuat tetap dipertahankan.

Pemasang juga memeriksa konfigurasi task yang ada. Task milik pemasang dengan jadwal dan pengamanan yang benar digunakan kembali; pemasangan ulang lokal dengan `-ConfirmMain -RegisterTask -RunNow` telah selesai dengan kode `0` tanpa UAC tambahan. Task yang hilang atau jadwal/pengamanannya tidak sesuai tetap memerlukan pendaftaran/perbaikan sebagai Administrator; pemasang tidak menurunkan hak akses atau mengabaikan konflik kepemilikan.

Jurnal penyediaan disimpan sebelum akun/task dibuat sehingga instalasi terputus dapat dilanjutkan. Akun existing hanya dilanjutkan bila sumber, pemilik dan autentikasi cocok dengan jurnal privat; akun tanpa bukti kepemilikan tidak diambil alih. Jangan menghapus jurnal atau berkas opsi untuk mencoba melewati pemeriksaan.

Periksa hasil pertama di halaman **Backup & Restore**: status harus **Terverifikasi**, bukan sekadar file berhasil dibuat. Task memakai pemeriksaan antrean setiap menit, startup berjeda satu menit, `StartWhenAvailable`, dan `IgnoreNew`. Jam pencadangan ditentukan aplikasi dalam WIB, bukan `NextRunTime` task. Task lama milik pemasang dengan trigger tambahan 18.00 WIB dapat dipakai kembali; trigger itu hanya memanggil pemeriksaan antrean dan tidak memaksa backup pukul 18.00. Penggantian jam lewat UI tidak memerlukan hak Administrator Windows atau pendaftaran ulang task.

## Cara kerja dan kondisi tertunda

Runner menggunakan waktu WIB, snapshot InnoDB konsisten, penguncian proses tunggal, checksum SHA-256, dan publikasi atomik. Backup tidak dijalankan selama maintenance. Perubahan definisi objek selama dump menyebabkan cadangan ditolak.

Jika komputer/MySQL tidak tersedia saat jadwal yang dipilih, runner membuat satu cadangan keadaan terbaru sebagai **Susulan** setelah layanan kembali. Waktu sebenarnya dan jadwal yang terlewat dicatat; data tidak diberi waktu seolah dicadangkan kemarin. Kegagalan sementara dicoba kembali setiap 15 menit. Klik manual berulang tidak membuat pekerjaan ganda.

Manifest mencatat `schedule_window` (jadwal awal/akhir dan jumlah jadwal yang terlewat), bukan membuat arsip palsu untuk setiap tanggal. Fingerprint tabel dihitung bertahap dengan urutan primary key; tabel masa depan tanpa primary key memakai pengurutan eksternal terbatas. Data besar tidak dimuat sekaligus ke memori PHP.

Tahapan: **Menunggu → Mencadangkan → Memverifikasi → Terverifikasi/Gagal**. Cadangan otomatis yang kemudian dirotasi menjadi **Kedaluwarsa**. Hasil gagal tetap tersedia untuk diagnosis privat; unduhan hanya untuk hasil terverifikasi dengan checksum sesuai. Kegagalan backup baru tidak menghapus cadangan baik sebelumnya.

Ruang di bawah 10 GiB menghasilkan peringatan. Jika tidak mencukupi untuk dump dan pemulihan terisolasi, pekerjaan baru dihentikan; sistem tidak menghapus cadangan lama dahulu untuk memaksakan backup baru. Tambahkan ruang atau pindahkan arsip secara administratif setelah memeriksa integritasnya. Rotasi hanya berlaku setelah pengganti otomatis terverifikasi tersedia.

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

Pengaturan dan rotasi: 118 pemeriksaan kebijakan lulus, termasuk jam 00.00/18.00/23.59, tahun kabisat/pergantian tahun, batas 1/90/365 hari, pengubahan bersamaan melalui dua proses SQLite, pengecualian manual, gzip/cache, checksum, junction dan kelanjutan pembersihan. Sebanyak 169 permintaan API memeriksa empat peran/cakupan, CSRF/validasi/konflik versi, formulir native, integritas unduhan serta status kedaluwarsa. Runner kebijakan baru benar-benar membuat dan memulihkan SQL pada MySQL terisolasi: kegagalan dump mempertahankan cadangan lama, keberhasilan otomatis baru merotasi cadangan otomatis lama, manual tetap utuh, dan checksum tabel sumber tidak berubah. Regresi runner sebelumnya juga lulus. Browser menguji pengaturan nyata/persistensi/polling/konflik/pengiriman ganda/retry/keyboard/tanpa JavaScript serta 24 kombinasi unit/tema/layar; regresi controller backup lama dan 24 tampilannya lulus. Sebanyak 36 pemeriksaan identitas/pemasang lulus tanpa mendaftarkan task. Manual PDF 36 halaman dibaca kembali dan diperiksa visual. Pengujian ini tidak membuktikan task sudah terpasang pada setiap PC tujuan.

Catatan pengujian implementasi backup sebelumnya dipertahankan berikut ini:

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

Lakukan latihan pemulihan ke instance terisolasi secara berkala dan setelah perubahan skema. Pemulihan web menjalankan pemeriksaan dan backup pengaman di atas hanya setelah konfirmasi Super Admin. Untuk pemulihan administratif dari instalasi lain atau skema berbeda, administrator harus memeriksa kompatibilitas/checksum, membuat cadangan kondisi terkini, menghentikan transaksi melalui maintenance, lalu mengaudit seluruh unit sebelum membuka layanan. Jangan menjalankan migrasi, impor CLI lain, atau perubahan database langsung bersamaan dengan pemulihan web.
