# Pembaruan akses, tagihan, histori, dan cetak — 17 Oktober 2026

> Pembaruan lokal 19 Oktober 2026: akses source utama dipulihkan setelah skema workflow dipasang. Atas pilihan pengguna, semua 10 username/password dipertahankan: satu Super Admin dan sembilan Administrator. Kebijakan empat akun awal pada catatan 17 Oktober di bawah tidak diterapkan pada pemulihan ini. Lihat [hasil pemulihan terbaru](MAIN_WORKFLOW_RECOVERY_20261019.md).


## Status penerapan

**Sudah aktif pada database operasional, 17 Oktober 2026 sekitar 15.52 WIB.** Migrasi skema dan penataan akun berhasil dijalankan setelah permintaan perbaikan akses. Halaman pemeliharaan sebelumnya muncul karena kode baru sudah aktif tetapi database masih memakai skema lama; kondisi tersebut sudah diselesaikan.

Login dan Master Kelas berhasil dibuka pada SD/SMP/SMA. Input Pembayaran, Master Biaya Lain, dan Riwayat Tabungan diperiksa melalui browser tanpa error JavaScript. `includes/workflow_readiness.php` kini menyatakan skema siap dan tidak lagi memblokir aplikasi.

Akun aktif adalah `superadmin` serta `administrator1`–`administrator4`, seluruhnya lintas unit. Password `superadmin` tetap sama. Akun lainnya dinonaktifkan sesuai rencana, dengan jejak operator historis tetap tersedia. Kredensial Administrator baru berada di `C:\laragon\backups\spp-management-system\workflow_release_backup_20261017\akun-administrator.txt`; jangan menyalin isinya ke repository/webroot.

Perbandingan sebelum/sesudah migrasi dan setelah pemeriksaan browser membuktikan seluruh data finansial/master serta password Super Admin tetap sama. Sepuluh transaksi lama memperoleh konteks historis yang konsisten; data ambigu tetap tanpa konteks. Bukti penerapan berada pada `before-activation.json`, `after-activation.json`, dan `activation-verified.json` di direktori cadangan privat.

Penolakan otomatis pada upaya sebelumnya merupakan catatan historis; penerapan terbaru telah berhasil.

Cadangan operasional telah dipulihkan dan dibandingkan pada server terpisah:

- Dump: `C:\laragon\backups\spp-management-system\workflow_release_backup_20261017\operational-readonly.sql`.
- Bukti checksum/pemulihan: `verified-backup.json` di direktori yang sama.
- Bukti perbandingan baca saja: `before.json` dan `restored.json`.

Cadangan ini merupakan bukti pengujian, bukan alasan melewati kewajiban membuat cadangan baru bila usia dump sudah melebihi satu jam saat penerapan. Artefak dan kredensial QA di direktori `workflow_update_qa_20261017` tidak boleh dipakai sebagai akun operasional.

## Akun dan hak akses

| Kemampuan | Super Admin | Administrator |
|---|---|---|
| SD/SMP/SMA, siswa, master, operasional, laporan, cetak | Bisa | Bisa |
| Edit/hapus Pembayaran dan Daftar Ulang | Langsung | Mengajukan persetujuan |
| Edit/hapus Tabungan | Langsung | Langsung |
| Keputusan otorisasi dan pengelolaan akun | Bisa | Tidak |

Semua Unit hanya untuk membaca. Pilih SD, SMP, atau SMA sebelum mutasi. Administrator dapat mengajukan koreksi transaksi petugas lain; pengajuan sendiri dapat dibatalkan sebelum diputuskan. Pengajuan Tabungan lama yang masih pending tetap terkunci dan menunggu Super Admin. Tidak dibuat pengajuan Tabungan baru.

Migrasi mempertahankan akun `superadmin` dan passwordnya, membuat/menggunakan kembali `administrator1` sampai `administrator4`, serta menonaktifkan akun lainnya termasuk Super Admin tambahan. ID, nama, jejak operator, dan snapshot peran lama tidak dihapus. Role tersimpan menjadi `super_admin` atau `admin`, akses kedua role global. Empat Administrator adalah akun awal, bukan batas maksimal. Constraint database membatasi satu Super Admin aktif.

Super Admin membuat Administrator dan mengatur password/status melalui Role Management tanpa memilih unit. Kredensial awal hasil migrasi disimpan pada berkas privat di luar repository dan webroot. Pengulangan migrasi mempertahankan ID/password dan tidak membuat akun ganda. Akun yang dinonaktifkan kehilangan akses pada permintaan berikutnya, termasuk sesi yang sudah terbuka.

## Potongan PSB dan pendaftaran yang keliru

Di Data Siswa gunakan **Potongan PSB (Rp)** dan **Total PSB Setelah Potongan**. Kewajiban bersih adalah PSB dasar dikurangi potongan satu kali. Form pembayaran, validasi simpan, laporan, dan kwitansi menggunakan nilai bersih yang sama. Potongan tidak boleh negatif, melebihi PSB dasar, atau membuat kewajiban lebih kecil daripada pembayaran sah. Perubahan dicatat dalam audit siswa.

Potongan SPP lama tetap tersimpan dan mengikuti aturan sebelumnya; tidak dikonversi menjadi PSB. Form siswa tidak menyediakan input potongan SPP bulanan baru. PSB discount baru dimulai dari nol.

**Pembaruan 18 Oktober 2026:** panel dan endpoint Koreksi Pendaftaran PSB sudah dihentikan. Untuk salah pemilihan rombel, buka Edit Data Siswa, pilih PSB, atur nominal PSB, lalu Simpan. Tidak ada pemeriksaan kelayakan berdasarkan pembayaran/pengajuan/kenaikan. Penempatan reguler aktif ditutup, sementara seluruh tagihan, pembayaran, dan saldo lama dipertahankan. Tidak ada pembatalan tagihan otomatis. [Panduan terbaru](ROMBEL_PEMBAYARAN_20261018.md).

## Histori pada Input Pembayaran

Bawaan **Tahun aktif** mengikuti penempatan siswa saat ini. Kenaikan 1A ke 2A membuka tampilan tahun baru; perpindahan 1A ke 1B dalam tahun yang sama tetap mempertahankan histori tahun itu. Pilih tahun sebelumnya, **Semua Tahun**, atau **Riwayat Lama—Konteks Belum Tercatat** bila diperlukan. Seluruh transaksi tetap tersedia di Riwayat Pembayaran dan laporan.

Konteks adalah tahun/kelas ketika uang diterima. Pembayaran tunggakan kelas 1 setelah siswa berada di kelas 2 tercatat dalam histori kelas 2, sedangkan rincian kewajibannya tetap menyebut periode lama. Konteks tidak berubah karena koreksi transaksi atau kenaikan berikutnya.

Transaksi lama hanya dipetakan bila peristiwa pembuatan dan satu penempatan historis cocok. Data ambigu dibiarkan tanpa konteks, bukan ditebak dari kelas terkini. Tidak ada batas 50: seluruh transaksi pada cakupan terpilih ditampilkan dalam tabel bergulir, dengan pengambilan rincian berkelompok.

Permintaan lama dibatalkan saat siswa/cakupan berganti. Hasil hanya diterima bila identitas siswa dan unit masih cocok. Memuat, kosong, sesi habis, unit tidak sesuai, dan kegagalan jaringan/server dibedakan; **Coba Lagi** tersedia untuk kegagalan yang dapat diulang. Bunyi notifikasi client sebelumnya belum diketahui; penyebab spesifik laporan lama tetap belum dapat dinyatakan teridentifikasi tanpa reproduksi.

API GET `pembayaran/history_siswa.php` mempertahankan `{ok, period, rows}`. `scope` menerima `active`, `all`, `unknown`, atau label tahun `YYYY/YYYY`; metadata `scopes`/konteks ditambahkan. `no_induk`, `student_id`, dan `unit_id` menjaga identitas. Bulan baru mengikuti WIB, pilihan manual/draft/edit tidak ditimpa.

## Biaya Lain Berulang dan Tidak Berulang

- **Berulang:** master aktif langsung muncul pada Input Pembayaran. Tarif Referensi adalah nominal wajib master; Nominal Dibayar harus tepat tarif, tanpa cicilan/kelebihan. Siswa dapat membayar master yang sama berulang kali. Tidak terbentuk sisa tagihan.
- **Tidak Berulang:** terbitkan melalui panel Master Biaya Lain kepada seluruh siswa aktif, rombel, atau siswa tertentu dalam unit. Dialog menampilkan sasaran dan nilai sebelum konfirmasi. Satu master menghasilkan satu kewajiban sepanjang riwayat siswa dan dapat dicicil. Penerbitan ulang melewati kewajiban yang sudah ada.

Master existing tetap Berulang. Perubahan jenis tidak mengonversi pembayaran atau tagihan lama. Pembelian Berulang tidak melunasi kewajiban Tidak Berulang dari master yang sama. Satu master tidak boleh muncul dua kali dalam satu transaksi, termasuk kombinasi baris opsional dan tagihan; buat transaksi terpisah untuk pembelian berikutnya.

Dropdown memisahkan **Biaya Berulang** dan **Tagihan Tidak Berulang/Tagihan Lama**. Total/Sudah Dibayar/Sisa hanya berlaku untuk tagihan. Master nonaktif tidak tersedia untuk pembelian baru; snapshot historis tetap terbaca. Hak unit, jenis master, roster sasaran, status aktif, duplikasi, versi, dan sisa diperiksa ulang server, termasuk saat persetujuan koreksi Pembayaran.

API `pembayaran/biaya_lain_siswa.php` tetap `{ok, rows}`: `master:<id>` untuk Berulang (`optional=true`), ID numerik untuk tagihan (`optional=false`). Pembayaran opsional memakai referensi tagihan NULL. Laporan memisahkan pembayaran opsional, pembayaran tagihan, dan sisa. Lihat [panduan Biaya Lain](BIAYA_LAIN_OPSIONAL.md).

## SPP dan peringatan kewajiban

Status membedakan Belum Terbit, Tercakup PSB, Potongan Penuh, Lunas, Dibatalkan, dan Masih Terutang. Kebijakan SPP tahun pertama siswa asal PSB tetap berlaku. Pemeriksaan baca saja terhadap Apis (SD, NIS 1444443) menemukan 12 tagihan `covered_psb` senilai nol; ini ditampilkan sebagai **Tercakup PSB**, bukan diterbitkan ulang sebagai kewajiban tambahan.

Setelah siswa dipilih, dialog merangkum sisa PSB/DU/Biaya Tidak Berulang serta SPP/Komite bulan sebelumnya. Tindakan menuju periode tunggakan membantu memilih bulan lama. Ringkasan PSB/DU/Biaya Lain bersifat informasi; aturan pasangan SPP/Komite, urutan tunggakan, dan pembayaran lebih awal tetap divalidasi server. Pengetikan, respons lama, pemulihan draft, dan edit tidak mengulang popup pemilihan siswa.

## Kenaikan kelas dan kartu riwayat

Mode kenaikan standar tetap tersedia. **Kenaikan Custom** memilih rombel reguler mana pun dalam unit yang sama, termasuk tinggal atau loncat tingkat; tahun tujuan selalu satu tahun berikutnya. PSB tidak menjadi tujuan custom. Kelulusan berlaku pada tingkat terakhir dalam mode yang sesuai. Tarif mengikuti master tahun/tingkat tujuan, tanpa menulis ulang penempatan/pembayaran lama.

Dialog konfirmasi menggantikan konfirmasi browser dan menampilkan siswa, asal/tujuan, tahun, serta dampak. Validasi versi/penempatan dan kiriman sekali mencegah proses ganda. Escape/Batal/fokus keyboard bekerja. Kartu Riwayat Pembayaran/Daftar Ulang memakai latar yang menyatu hingga area tombol cetak, tanpa pita putih; indikator pilihan/fokus dipertahankan.

## Cetak pembayaran dan Tabungan

Slip pembayaran memakai Letter separo 215,9 × 139,7 mm, teks serif hitam sekitar 11 pt, kop dua baris, identitas dua kolom, pembayaran kiri dan sisa/biaya lain kanan. **Sisa PSB** dan **Sisa DU** selalu ditampilkan menggunakan sisa terkini dengan label sederhana tanpa akhiran tahun/bulan; kewajiban yang belum diterbitkan dijelaskan. SPP tidak diulang sebagai Biaya Lain. Tabungan Wajib pada template tidak membentuk komponen keuangan baru.

Preview, PDF, dan PNG memakai isi yang sama. Cetak/PDF berupa teks dengan font; PNG tetap pilihan unduhan. Dua belas Struk dan pemecahan rincian panjang tetap tersedia.

Semua cetak Tabungan Masuk/Keluar/Riwayat membuka seluruh buku siswa pada **A5 potret**. Identitas ringkas dan tabel No., Tanggal, Masuk, Keluar, Saldo, Paraf dicetak tanpa sampul/lipat/halaman terbalik/baris kosong tambahan. Header tabel berulang dan baris tidak terpotong. Saldo mencakup seluruh mutasi sah, terlepas dari filter riwayat. Endpoint bukti lama memeriksa akses lalu mengarahkan ke buku penuh. Gunakan 100%/Actual size; lihat [pengaturan printer](PENGATURAN_PRINTER_KWITANSI.md).

Ketajaman diverifikasi dari teks/font PDF dan resolusi PNG. Kecocokan cetakan fisik masih memerlukan uji pada printer/kertas sekolah.

## Rujukan penerapan ulang atau instalasi lain

Penerapan pada PC ini sudah selesai. Prosedur berikut disimpan sebagai rujukan pengulangan idempoten atau instalasi lain; pengelola harus memeriksa target database, cadangan, dan lokasi kredensial sebelum menjalankannya.

1. Hentikan pekerjaan operasional, periksa koneksi menuju database yang benar, buat dump baru di luar webroot, dan verifikasi pemulihannya pada instance terpisah. Gunakan mekanisme [backup terverifikasi](DAILY_DATABASE_BACKUP.md). Dump yang diberikan kepada guard harus berusia paling lama satu jam.
2. Simpan bukti baca saja dengan `php scripts/workflow_state_read.php` ke berkas privat sebelum migrasi. Jangan mengarahkan perintah ke instance QA yang masih tersimpan dalam environment.
3. Jalankan migrasi dari direktori proyek menggunakan PHP/MySQL instalasi lokal. Ganti dua path contoh di bawah dengan lokasi privat yang benar. Direktori kredensial harus sudah ada, dan berkas kredensial harus baru pada penerapan pertama.

```powershell
$env:SPP_DB_HOST = '127.0.0.1'
$env:SPP_DB_PORT = '3306'
$env:SPP_DB_NAME = 'db_spp'
# SPP_DB_USER/SPP_DB_PASS mengikuti konfigurasi lokal yang berwenang;
# jangan menyimpan password dalam repository atau argumen perintah.
$env:SPP_ALLOW_MAIN_MIGRATION = '1'
$releaseBackup = 'C:\laragon\backups\spp-management-system\RELEASE_BARU\backup.sql'
$privateCredentials = 'C:\laragon\backups\spp-management-system\RELEASE_BARU\akun-administrator.txt'
php sql/update_workflows_20261017.php --confirm-main=db_spp "--backup-file=$releaseBackup" "--credentials=$privateCredentials"
```

4. Periksa keluaran sukses, catatan `workflow_migration_log`, dua role, satu Super Admin aktif, empat akun awal lintas unit, dan bandingkan penerimaan/saldo serta digest password Super Admin dengan bukti sebelum. Backfill hanya menambah konteks dan mempertahankan timestamp asli; jangan membandingkan hash seluruh baris transaksi tanpa mengecualikan kolom konteks baru.
5. Masuk kembali dengan Super Admin, uji Administrator pada ketiga unit, lalu serahkan berkas kredensial melalui kanal privat. Pengaman HTTP terbuka otomatis. Hapus environment migrasi setelah selesai.

Migrasi additive membuat jenis master, potongan PSB, konteks histori, status penempatan dibatalkan, refresh view unit, serta penyederhanaan role/constraint. DDL MySQL tidak seluruhnya transaksional; pengulangan aman melanjutkan tahap yang tersisa. Jangan memulihkan database secara otomatis di atas transaksi baru. Jika gagal, pertahankan pemeliharaan, tinjau log privat, lalu ulangi tahap idempoten atau lakukan pemulihan terkontrol dari cadangan terverifikasi.

Instalasi baru memakai payload skema terbaru, bootstrap Super Admin, migrasi multiunit, kemudian migrasi workflow ini. Bootstrap akun lama menjadi entrypoint kompatibilitas menuju proses workflow; tidak lagi membagi akun berdasarkan unit.

## Bukti pengujian

Seluruh mutasi dilakukan pada MySQL terpisah dengan UUID, port, dan datadir diperiksa; bukan pada transaksi operasional.

| Pemeriksaan | Hasil |
|---|---|
| `workflow_update_http_test.php` | 170 pemeriksaan |
| `workflow_update_security_test.php` | 61 pemeriksaan; diulang setelah pembersihan login/akses |
| `workflow_update_report_test.php` | 76 pemeriksaan, 18 ekspor PDF/Excel |
| `other_fee_publish_replay_http_test.php` | 75 pemeriksaan |
| `workflow_update_browser_test.js` | 518 per browser: Chrome, Edge, Chromium 109 |
| `workflow_update_dialog_browser_test.js` | 23 total: Chrome dan Chromium 109 |
| `workflow_update_output_readback_test.py` | 449 pemeriksaan: 79 halaman PDF, 9 XLSX, 9 PNG, 3 ZIP tahunan |
| Regresi cetak/buku/SPP | Slip 1 halaman, 12 Struk, rincian panjang; buku kosong/55 mutasi; urutan/status SPP |

Cakupan meliputi kedua role, akun nonaktif, POST langsung/CSRF/Semua Unit, pending Tabungan lama, saldo negatif, konteks 505 transaksi, tunggakan setelah kenaikan, perubahan rombel, potongan PSB, koreksi pendaftaran, penerbitan sekali/cicilan/replay, laporan, gabungan PSB/DU/biaya lain, tiga palet, terang/gelap, dan desktop/tablet/ponsel. Migrasi dan penataan akun diuji idempoten pada clone. Pengaman skema diuji: skema lama HTTP 503 dengan kode `schema_update_required`; setelah penerapan operasional, login dan halaman aplikasi kembali HTTP 200. Keempat Administrator lulus login dan penolakan akses Role Management/Otorisasi; Master Kelas lulus HTTP 200 pada ketiga unit.

Pemeriksaan akhir sintaks lulus untuk 369 berkas PHP dan 94 JavaScript; `git diff --check` lulus. Layanan PHP/MySQL QA telah ditutup dengan pemeriksaan kepemilikan instance. Dump, fingerprint, dan artefak pengujian privat tetap disimpan.
