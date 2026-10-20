# Pemulihan impor terputus — 21 Oktober 2026

Repo disinkronkan ke `5bcf321` dengan fast-forward. Perubahan lokal `koneksi.php` yang menunjuk `db_spp_test2` dipertahankan.

## Penyebab

Impor melalui phpMyAdmin berhenti karena batas waktu, bukan karena kesalahan sintaks trigger yang terlihat pada layar. Database tujuan baru mempunyai 32 tabel, 55 trigger, dan dua routine; `unit_sekolah` serta banyak tabel berikutnya belum terbentuk.

Dump pengguna `Downloads/db_spp_test2.sql` berukuran 467.635.922 byte, SHA-256 `9182a94480c547c6fe5de33feddab40ffcf463325acbeddd453d2e52c799e11d`. Salinan bernama `db_spp_test2 (1).sql` berbeda hanya pada waktu ekspor di header. Tidak memakai backup data sekolah lain.

## Penanganan

- Cadangkan database parsial dan konfigurasi sebelum pemulihan. Cocokkan tabel yang sudah masuk serta 369.621 baris awal arsip terhadap sumber lengkap 390.911 baris.
- Pulihkan dump melalui MySQL CLI pada instance terisolasi. Jalankan preflight dan alat `scripts/repair_imported_database.php` existing untuk melengkapi 46 CHECK dan modul audit/otorisasi tabungan. Tidak ditemukan pelanggaran relasi atau benturan definisi.
- Perbaiki tiga metadata hash batch kosong melalui pemeriksaan arsip dan rekonsiliasi existing, dengan tiga entri audit. Sebanyak 58 tabel sumber identik; perubahan dua tabel sumber hanya metadata batch/audit. Dua tabel modul baru tetap kosong. Nominal, saldo, transaksi, identitas, akun, dan password tidak ditulis ulang oleh rekonsiliasi.
- Ekspor SQL lengkap dari hasil yang tervalidasi, lalu pulihkan hanya `db_spp_test2` melalui CLI, dengan pemeliharaan dan kunci akses pada source utama serta lab. Pembanding memakai normalisasi DDL aplikasi existing untuk penulisan charset yang setara. Percobaan awal pembanding yang terlalu ketat memicu rollback; seluruh data parsial terbukti pulih sebelum percobaan berikutnya.
- Bandingkan seluruh hash tabel dan struktur hasil pemulihan. Rotasi generasi sesi mengikuti mekanisme restore existing; pengguna perlu login kembali. Database `db_spp` diperiksa terpisah dan tidak diubah.

## Bukti dan penggunaan berikutnya

Cadangan parsial, SQL lengkap `validated.sql`, checksum, snapshot, log pemulihan, serta hasil pemeriksaan disimpan privat di:

`C:\laragon\backups\spp-management-system\import_timeout_20261021`

Setelah pemulihan berhasil, buka ulang aplikasi dan login. Jangan mengirim ulang dump yang sama melalui tombol resume phpMyAdmin pada database yang sudah lengkap. Untuk dump besar berikutnya, gunakan prosedur restore CLI/worker dengan backup dan pemeriksaan target; tidak perlu menaikkan batas waktu atau memori aplikasi.

Sinkronisasi repo dan pemulihan ini tidak melakukan commit, push, deployment, atau migrasi operasional Legacy ulang. Konfigurasi database lokal tetap dipertahankan.

## Hasil akhir

- Database lengkap: **62 tabel, 56 view, 254 trigger, dan dua routine**. Seluruh hash tabel cocok dengan hasil pemulihan terisolasi; struktur dibandingkan secara kanonis.
- **118.244 pembayaran** (SD 46.852, SMP 60.181, SMA 11.211), **9.288 DU tanpa tanggal**, dan **2.418 rekening** tetap terbaca.
- **37 pemeriksaan model/laporan hanya baca** serta **28 pemeriksaan HTTP** pada source utama dan lab lulus. Pengujian mencakup Semua Unit, SD, SMP, SMA, halaman login, health check, pembayaran, DU, dan tabungan. Periode 1?20 Oktober 2026 menampilkan 240/194/44 pembayaran.
- Seluruh 62 tabel dan skema identik sebelum/sesudah pengujian HTTP. Seluruh 51 tabel dan skema `db_spp` tetap identik dengan baseline.
- 36 uji waktu pembayaran, 15 uji presisi tabungan, dan 35 uji pembanding skema lulus. Tidak melakukan pengujian mutasi pada database pengguna.
- Konfigurasi tetap sama; delapan sesi HTTP pengujian dibersihkan. Server MySQL terisolasi dihentikan, dan kedua aplikasi keluar dari pemeliharaan. SQL asli dan cadangan dipertahankan.

Pengujian akhir ini memeriksa isi respons dan model; tidak mengulangi audit visual seluruh halaman atau uji beban aplikasi.
