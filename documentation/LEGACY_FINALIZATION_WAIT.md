# Halaman tunggu finalisasi migrasi — 20 Oktober 2026

Pesan polos “SistemSPP sedang memulihkan database” sebelumnya dipakai untuk setiap kegagalan memperoleh akses bersama, termasuk saat worker Legacy memegang akses eksklusif untuk finalisasi. Halaman/status importer juga tertahan oleh penguncian yang sama sehingga pengguna mengira proses gagal.

## Perubahan

- Pemulihan yang memiliki penanda pemeliharaan tetap disebut **Pemulihan database sedang berjalan**. Penguncian finalisasi Legacy ditampilkan sebagai **Finalisasi migrasi sedang berjalan** dengan komponen dan tema SistemSPP.
- Halaman GET memeriksa kembali setiap 10 detik memakai refresh browser, termasuk tanpa JavaScript. Tersedia tombol Periksa Status Lagi dan arahan untuk tidak mengirim finalisasi berulang.
- POST yang tertahan tidak diputar ulang otomatis. Pengguna diminta membuka kembali halaman dan memeriksa hasil sebelum mengirim ulang. Tautan dibatasi ke URL lokal dan di-escape.
- Respons API tetap HTTP 503 selama akses ditahan, dengan JSON `error`, `code` (`migration_in_progress` atau `database_restoring`) dan `retry_after: 10`. Tidak ada rincian siswa, nominal, atau kredensial pada respons tunggu.
- Penguncian, pemeriksaan akses, transaksi, dan proses verifikasi backup tetap berlaku. Halaman tunggu tidak membuka MySQL, menghapus penanda, melepaskan penguncian worker, atau memulai migrasi ulang.

## Hasil migrasi SMP yang sedang berjalan saat perbaikan

Worker yang dimulai pengguna tidak dihentikan atau diulang. Pekerjaan `12924a6b4da734f17a343e3c830ba0cb` menyelesaikan verifikasi backup akhir dan berstatus **imported / Migrasi Selesai**. Batch operasional berstatus **completed** pada **20 Oktober 2026 00:00:16 WIB**:

- 60.181 pembayaran dan 27.546 jurnal operasional tersimpan.
- Saldo peralihan/berjalan Rohis Mhi `220817` adalah Rp0.
- Backup sebelum `e2d1c1ce838656aee0af534209f155e7` dan sesudah `35a2177049c01372f44d8adc8df9cc3c` terverifikasi.
- Login project utama dan lab copy kembali HTTP 200, tanpa pesan penguncian.

Perbaikan source utama dan lab hanya memasang tampilan respons tunggu; tidak menjalankan perintah SQL perubahan data. Perubahan data SMP selama pekerjaan berasal dari finalisasi yang sebelumnya sudah dimulai pengguna. Konfigurasi, draf, worker pengguna, database utama, dan arsip dipertahankan; tanpa commit/push/deployment.

## Pengujian

`tests/database_access_wait_test.php` memegang penguncian eksklusif pada target beridentitas audit yang unik dan menjalankan permintaan pada proses PHP terpisah. Diuji: penolakan akses tetap berlaku, judul migrasi/pemulihan tepat, API JSON, GET auto-refresh, POST tidak diputar ulang, URL lokal/escaping, aset pada URL bertingkat, serta akses pulih setelah penguncian dilepas. `tests/legacy_application_wait_test.php` tetap lulus.

Sebanyak 16 pemeriksaan HTML hasil render lulus pada Chrome 155 dan Chromium 109.0.5412.0: ponsel 390/desktop 1366, terang/gelap, zoom 100%/200%, tanpa luapan horizontal dan fokus keyboard. Screenshot ponsel diperiksa. Uji browser ini menggunakan HTML lokal; cabang HTTP 503 nyata diuji lewat controller CLI dengan penguncian sungguhan. GET localhost utama/lab setelah migrasi selesai diuji langsung dan kembali HTTP 200. Penguncian lab yang sudah selesai tidak dibuat ulang untuk pengujian.

Bukti privat: `C:\laragon\backups\spp-management-system\migration_wait_20261020`, termasuk salinan file sebelumnya, hasil browser/screenshot, hasil pemeriksaan batch selesai, dan respons localhost.
