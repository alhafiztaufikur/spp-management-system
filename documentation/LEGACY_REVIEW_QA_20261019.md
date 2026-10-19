# QA peninjauan Migrasi Operasional ? 19 Oktober 2026

Pekerjaan hanya mengubah source utama. Percobaan menggunakan instance MySQL privat di port tersendiri dan SQL Server LocalDB khusus pengujian. Database utama, folder Copy, dan database pengujian pengguna tidak digunakan sebagai tujuan mutasi.

## Hasil

| Pemeriksaan | Hasil |
| --- | --- |
| Backup terbaru melalui worker | SD 1.133, SMP 1.762, SMA 397 siswa; fingerprint/jumlah sumber cocok dengan audit sebelumnya |
| Regresi backup 2019 melalui worker | SD 369, SMP 787, SMA 131 siswa; fingerprint/jumlah tetap cocok |
| Nama komponen | Master lain dibaca per unit; kode dengan nol depan dipertahankan; slot berkode sama memakai satu usulan tanpa menghapus rincian |
| Pengecualian sumber SD | 0016 memiliki dua nama; 022 ditemukan sekali dengan Rp10.000; identitas test tersedia untuk pengesahan rekening uji |
| Alumni | A2025/B2025 menjadi satu Alumni2025 setelah disahkan; 30 siswa tetap nonaktif; kelas tidak masuk input akademik atau aktivasi rombel massal |
| Saldo FIX | Nilai naik/turun/nol, bukti, nominal negatif, presisi dan rekening ganda diuji; sumber tidak ditimpa |
| Jurnal dikoreksi sebelum FIX | Modal dan simulasi memakai jurnal efektif yang sama; jurnal Rp900 dan FIX Rp975 menghasilkan penyesuaian Rp75 |
| Penerapan | Draf/simulasi tidak membuat master atau siswa; rollback membatalkan seluruh publikasi; retry tidak menggandakan rekening/jurnal/penyesuaian |
| Master tanpa tarif | Nol hanya untuk master pending nonaktif; aktivasi melalui POST dan database ditolak; mengisi tarif positif menghapus penanda pending |
| Sesudah migrasi pada salinan | Penarikan siswa/rekening uji, koreksi jurnal/FIX, penolakan saldo negatif, buku gabungan serta pemisahan kas lulus |
| Laporan | Pratinjau tabungan, PDF dan Excel berhasil; penyesuaian tampil dengan arus masuk/keluar nol dan dampak saldo terpisah |
| Akses | Super Admin aktif, Admin/Kasir/Bendahara ditolak untuk peninjauan, anonim/nonaktif, lintas unit, Semua Unit baca, CSRF, versi/konfirmasi kedaluwarsa dan kiriman ulang diuji |
| Backup | Backup sebelum/sesudah penerapan benar-benar dipulihkan dan diverifikasi pada instance terisolasi, termasuk tabel/kolom baru dan audit penyesuaian |
| Health | Audit integritas seluruh unit pada instance uji lulus |
| Database pengguna | Fingerprint isi seluruh 50 tabel utama dan 59 tabel db_spp_test2 identik sebelum/sesudah pekerjaan |

## Tampilan

- 96 kasus pada palet SD dan 96 kasus pada palet SMA: tiga panel, lebar 360/390/768/1366 px, terang/gelap, JavaScript aktif/nonaktif, pada Chrome 155.0.8059.80 dan Chromium 109.0.5412.0.
- Tambahan 24 kasus memeriksa palet SD/SMP/SMA yang benar-benar berasal dari konteks aplikasi, kedua tema dan kedua browser, dengan/tanpa JavaScript. Total **216 kasus**.
- Pagination 25 + halaman berikutnya, pencarian sasaran, textarea, checkbox, overflow, keyboard modal/Escape, pembatalan dan pemulihan isian diuji. Daftar ponsel digulir di dalam panel agar halaman tidak memanjang tanpa batas.
- Pada Chromium 109 tanpa JavaScript, pengesahan checkbox/modal juga diuji lewat keyboard native; jalur formulir tetap menggunakan server.
- Windows 7 fisik tidak tersedia untuk pengujian. Tidak memasang atau menghapus runtime browser bersama/importer.

## Menjalankan kembali

Gunakan lingkungan terisolasi yang identitas MySQL-nya cocok dengan manifest pengujian privat. Guard pengujian menolak database utama atau Copy. Backup dan antrean importer juga harus menunjuk penyimpanan uji privat.

Urutan fixture: pasang skema CLI, jalankan legacy_review_transactions_test.php, lalu legacy_review_ui_fixture.php untuk unit 1 dan 3. Jalankan tes browser sebelum menerapkan kedua draf UI. Tes HTTP dapat memakai --sma. Tes after/read menguji transaksi baru dan master pada fixture unit 2; tes corrected_bank memeriksa draf unit 3; tes audit menerapkan kelompok siap dari draf unit 1 dan membuktikan kelas alumni yang belum disahkan tidak dibuat.

Tes sumber memerlukan keenam backup contoh dan hasil audit pembanding privat. Jalankan worker untuk pemeriksaan sumber; tidak menerapkan backup nyata. Skrip pengujian dan panduan tidak berisi password, SQL pengguna, sesi, atau backup. Bukti rinci/ekspor/screenshot tetap berada pada direktori QA privat di luar Git.

## Batas penerapan

Skema dan sumber nyata **belum diterapkan pada db_spp atau Copy**. Pada server tujuan, jalankan installer operasional melalui CLI dalam pemeliharaan setelah backup terverifikasi, lalu restart worker (aturan operasional 4 / Migrasi Penuh 6) dan periksa/simulasikan ulang.

Saldo FIX menyelesaikan selisih nominal dengan bukti; identitas ambigu, rekening ganda, dan jurnal invalid tetap perlu ditinjau. Kesiapan membaca backup tidak berarti semua pengecualian sumber sudah selesai. Tidak melakukan commit/push atau pembaruan deployment.
