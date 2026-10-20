# Optimasi riwayat dan penyimpanan tabungan

Pembaruan 20 Oktober 2026 pada source utama dan lab `spp-management-system -Copy`, database lab `db_spp_test2`. Tidak ada perubahan skema, indeks, konfigurasi, draf, saldo FIX, atau data operasional. Tidak melakukan commit, push, deployment publik, maupun migrasi ulang.

## Perubahan pembacaan

- Pembaca hanya mengoptimalkan view unit biasa dengan proyeksi kolom dan pembatasan unit yang dikenali. Predikat berasal dari `current_unit_id()` pada koneksi; filter pengguna tidak menentukan hak unit. View dengan pembatasan tambahan tetap memakai jalur existing.
- Penghitung pembayaran menggunakan proyeksi kecil; rincian diambil setelah pagination. Pencarian, operator, sumber, pemilik, tanggal, ID negatif, arsip, dan penentuan halaman transaksi tetap berfungsi.
- DU mengelompokkan data sekali melalui CTE yang dipakai bersama oleh opsi tahun, ringkasan, status, pemilihan transaksi, dan pagination. Bukti tarif digabung sebagai data, menggantikan rangkaian CASE per pemilik. Rincian memakai ID pemilik; spasi akhir label kelas dinormalisasi untuk tampilan.
- Rekap rekening menjumlahkan saldo tanpa menggabungkan seluruh jurnal. Aktivitas terakhir dan jumlah jurnal hanya dilengkapi bagi 25 rekening yang tampil. Saldo dan status keuangan tidak dicache lintas permintaan.
- Pembacaan sumber tagihan juga menggunakan pembatasan unit eksplisit. Ledger, kredit Legacy, potongan, snapshot tarif, kewajiban, dan nominal tetap mengikuti aturan existing.

## Setelah menyimpan tabungan

Formulir memakai satu pengiriman aktif dan menolak submit ganda di browser; request key server tetap menjamin idempotensi. GET opsional `after_save=1` pada `tabungan/riwayat.php` membuka detail jurnal yang tepat, saldo rekening, struk, buku, dan tautan riwayat tanpa memuat dashboard rekening. URL riwayat lama tetap bekerja. Identitas yang bertentangan atau lintas unit ditolak.

Fingerprint tetap dihitung lengkap dan atomik bersama jurnal, saldo, dan audit. Pembaca melakukan streaming sesuai urutan lama, menggunakan indeks `(unit_id,id)` existing ketika tersedia. Penghitungan berulang pada hook pembayaran native digabung sekali per batch. Tidak ada antrean verifikasi asinkron atau pelonggaran pemeriksaan saldo. Sesi dilepas lebih awal pada endpoint saldo/pencarian JSON dan ekspor yang tidak lagi menulis sesi.

## Hasil pengukuran

Benchmark HTTP menggunakan snapshot yang sama pada MySQL terisolasi 58246 dan Apache terisolasi 58250. Tiap rute diukur tiga kali berurutan, kemudian lima akun berbeda secara bersamaan. P95 sampel kecil berikut adalah nilai maksimum sampel; hasil ini bukan uji saturasi produksi. Batas memori/waktu aplikasi serta konfigurasi server pengguna tidak dinaikkan.

Pengukuran awal HTTP SD: pembayaran 20.930 ms, DU 24.761 ms, tabungan 36.110 ms. Hasil akhir (milidetik):

| Unit | Halaman | Median | P95 sampel | P95 lima pembaca |
|---|---|---:|---:|---:|
| SD | Pembayaran | 719 | 721 | 958 |
| SD | DU | 1240 | 1251 | 1532 |
| SD | Tabungan | 1502 | 1525 | 1987 |
| SMP | Pembayaran | 667 | 682 | 929 |
| SMP | DU | 1174 | 1176 | 1410 |
| SMP | Tabungan | 656 | 671 | 905 |
| SMA | Pembayaran | 256 | 259 | 294 |
| SMA | DU | 308 | 318 | 357 |
| SMA | Tabungan | 297 | 302 | 364 |
| Semua Unit | Pembayaran | 802 | 805 | 1041 |
| Semua Unit | DU | 1958 | 2057 | 2363 |
| Semua Unit | Tabungan | 1337 | 1343 | 1760 |

Semua halaman umum pada benchmark akhir memenuhi 3 detik; filter berat memenuhi 5 detik. Halaman terakhir seluruh pembayaran, pencarian, operator, halaman DU jauh, dan rekap seluruh periode diuji terpisah. Angka mentah, jumlah kueri, memori, dan pengukuran fase simpan tersedia pada JSON pendamping.

Simpan sampai detail pada pengujian terakhir: non-siswa **17.877 -> 1.325 ms**, siswa **11.412 -> 185 ms**. Lima simpan non-siswa bersamaan tetap mengantre: maksimum **5.813 ms** pada rekening sama dan **5.737 ms** pada rekening berbeda dalam batch yang sama. Batas 3 detik untuk lima penulis belum tercapai karena hash lengkap wajib diserialkan; tidak ada kehilangan atau penggandaan transaksi. Lima simpan siswa maksimum 211 ms. Fase simpan tunggal non-siswa sekitar 1,1-1,2 detik, sebagian besar untuk fingerprint.

Pengukuran baca-saja pada lab aktual dicatat terpisah: pembayaran SD/SMP sekitar 2,2 detik, DU sekitar 3,9 detik, riwayat tabungan SD sekitar 4,6 detik. Detail langsung rekening non-siswa 223 ms. Sebagian halaman lab belum memenuhi target 3 detik; hasil uji terisolasi tidak dianggap jaminan waktu pada database/server pengguna.

## Pengujian dan bukti pemasangan

- 56 penerimaan pembaca; 6 kompatibilitas native tanpa Legacy; 38 transaksi/otorisasi; 20 kasus tepi; 5 kewajiban terikat; 18 tarif; 23 kredit SPP. Pengujian mutasi memakai server terisolasi dan mencakup rollback, retry, versi berubah, saldo tidak cukup, penarikan bersamaan, serta pemisahan unit.
- 21 skenario Chromium 109.0.5412.0: desktop/ponsel, kedua tema, palet unit, keyboard, tanpa JavaScript, serta simpan ganda yang menghasilkan satu POST. Screenshot diperiksa. PDF struk dan Excel pembayaran, DU, serta tabungan ketiga unit dibaca kembali, termasuk nominal pecahan.
- Proyeksi lengkap sebelum/sesudah cocok untuk 118.244 pembayaran, 95.575 jurnal, seluruh rekap rekening, dan hash kerja ketiga unit. Pengecualian perbandingan hanya spasi akhir label kelas DU. Seluruh 9.288 DU tanpa tanggal dan 2.418 rekening tetap terbaca.
- Backup SQL diverifikasi dengan pemulihan pada server terisolasi. 31 file terkait dipasang ke lab; file yang diganti dicadangkan dan hash file utama/lab dicatat. Konfigurasi, akun, unggahan, draf, antrean, dan sesi pengguna dipertahankan.
- Fingerprint 51 tabel `db_spp` dan 60 tabel `db_spp_test2` identik. Skema dibandingkan terpisah sebelum/sesudah pemasangan. Pengujian lab aktual hanya GET; tidak ada transaksi contoh dibuat di data pengguna. Layanan MySQL/Apache khusus pengujian telah dihentikan; sesi pengujian sementara dibersihkan tanpa menghapus sesi pengguna.

Bukti lokal: `C:/laragon/backups/spp-management-system/history_performance_20261020`. Pengujian khusus membutuhkan server terisolasi, snapshot, serta sesi uji; jangan mengarahkan skrip mutasi ke server pengguna. Tidak dilakukan uji printer fisik atau saturasi puluhan pengguna.

## Catatan integritas existing

Manifest tiga batch yang telah selesai sudah menyimpan hash konten kosong sebelum pekerjaan ini. Ditemukan konversi ID batch CHAR menjadi integer pada pembaruan manifest; konversi tersebut diperbaiki pada source. Algoritma hash baru tetap identik dengan hasil pembaca lama atas isi sebenarnya. Metadata lama tidak ditulis ulang karena pekerjaan ini mempertahankan database pengguna. Pemeriksaan/perbaikan metadata existing perlu menjadi tindakan terpisah; temuan ini tidak mengubah nominal atau hasil pembacaan histori.
