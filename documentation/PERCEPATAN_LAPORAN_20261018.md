# Perapihan buku dan percepatan laporan

## Koreksi: format keluaran dikembalikan — 18 Oktober 2026

Renderer canvas dan perubahan format workbook dari optimasi sebelumnya **dibatalkan atas permintaan pemilik**. Global, Rekap Transaksi, dan Otorisasi kembali menggunakan renderer/HTML/CSS asli dari `report_speed_20261018_201823/source-before.zip`. Font, ukuran, susunan identitas, kolom, kepala tabel, garis, subtotal, pembagian halaman dan workbook mengikuti format sebelum optimasi. Pengaturan font/posisi bold rata tengah buku PLQ-20 yang diminta terpisah tetap dipertahankan.

Yang tetap aktif hanya pemeriksaan data terbaru, snapshot, cache privat berdasarkan isi, pelepasan session lock, pengukuran durasi dan penghilangan suffix username Global. Versi cache dinaikkan agar file dari renderer yang dibatalkan tidak terpakai. Perubahan gaya Excel massal juga dikembalikan ke pembentuk workbook asli. Kecepatan pembuatan baru kembali mengikuti renderer asli; pemakaian ulang file tetap tersedia jika isi terbaru identik. Audit kecepatan selanjutnya harus mempertahankan hasil cetak existing.

Cadangan sebelum pemulihan: `C:/laragon/backups/spp-management-system/report_output_restore_20261018_214953/before-restoration.zip`. Regresi `report_output_restore_test.php` dan `report_output_restore_readback_test.py` membandingkan PDF asli dengan hasil endpoint sekarang untuk sembilan jenis pada tiga palet, termasuk teks/font/warna/koordinat, ukuran kertas, jumlah halaman dan elemen tabel. Pembentuk workbook dan sumber layout dibandingkan langsung dengan cadangan asli.

Hasil pemulihan: 89 pemeriksaan sumber/HTTP/cache dan 96.172 pemeriksaan PDF asli lulus. Regresi tambahan: 30 layout/PDF, 37 HTTP/model/akses, 4.185 PDF/Excel/buku dan 20 cache/konkurensi lulus. Pengujian hanya membaca database terpisah; fingerprint operasional diperiksa kembali sebelum penyelesaian.

**Bagian renderer dan pengukuran di bawah adalah riwayat eksperimen yang telah dibatalkan, bukan spesifikasi atau klaim kecepatan versi aktif.** Arsip kode eksperimen ada di direktori cadangan privat; tidak menjadi jalur cetak aplikasi.

## Perilaku cetak dan unduhan

Setiap permintaan Cetak/Download membaca model terbaru dalam snapshot konsisten setelah pemeriksaan sesi, peran, dan unit. File PDF/XLSX hanya dipakai kembali jika model, filter lengkap, urutan, cakupan unit, identitas/peran pengguna, format, serta versi renderer sama. Waktu klik tidak masuk fingerprint. Transaksi baru, koreksi, penghapusan, perubahan siswa/filter, atau perubahan renderer menghasilkan fingerprint berbeda jika memengaruhi keluaran.

Preview Otorisasi/Rekap memanggil endpoint lagi pada setiap Cetak/Download, memperbarui dokumen bila berubah, dan menyediakan loading/Coba Lagi. Blob lama hanya untuk penampil yang sudah terbuka. Tombol toolbar aplikasi menjadi jalur pemeriksaan data terbaru; tombol bawaan penampil PDF bekerja pada file yang sedang dibuka.

Cache privat default berada di direktori temporary sistem operasi `spp-report-cache-HASH_APLIKASI`, di luar webroot. `SPP_REPORT_CACHE_DIR` dapat menunjuk direktori privat lain. Tidak ada URL publik untuk mengambil artefak langsung: semua akses tetap melalui endpoint berotorisasi dan pembacaan model terbaru. TTL 15 menit, maksimal 32 artefak per pengguna/database; pembersihan berlangsung saat penyimpanan berikutnya. File diverifikasi ukuran, SHA-256, dan format. Penulisan memakai file sementara/rename dan penguncian per kelompok fingerprint untuk menggabungkan klik bersamaan. Penyimpanan tidak tersedia tidak menghalangi pembuatan file langsung.

`includes/report_artifact.php` menangani reuse/retensi/stream. `Server-Timing` mencatat validasi/filter, SQL (`query`), pembentukan model (`model`), persiapan layout, render PDF atau workbook/serialisasi XLSX, dan penulisan artefak. `includes/report_metrics.php` mengukur prepare/execute/query native mysqli hanya pada empat endpoint ekspor; koneksi halaman lain tetap native. Waktu pengambilan/pemetaan hasil menjadi bagian model. Waktu render Dompdf mencakup layout internalnya.

## Renderer dan kompatibilitas

Sembilan Laporan Global tetap memakai query/filter, urutan, kolom, status, subtotal dan total existing. Matriks PDF menggunakan canvas dengan koordinat dan pembungkusan teks terukur; lebar kolom, kelompok cetak, ukuran teks dan hasil pembungkusan dipakai kembali. Identitas diulang di setiap kelompok, kepala tabel di setiap halaman. Semua baris dipertahankan, termasuk teks panjang, nol, negatif dan NIS berawalan nol. Tidak ada batas 500 baris.

Rekap PDF dengan lebih dari 200 baris detail memakai canvas dari model Excel bertipe yang sama. Otorisasi dengan lebih dari 50 transaksi juga memakai canvas setelah uji 350 transaksi menemukan render DOM sekitar 13,5–21,2 detik; keluaran canvas sekitar 0,51 detik. Ringkasan, kronologi, metadata pengajuan/rekonstruksi, alasan, dan pasangan Sebelum/Sesudah/Usulan tetap lengkap. Laporan kecil, surat kepala sekolah, dan tanda tangan tetap memakai format/renderer yang sesuai. Rekap Excel menerapkan format/posisi per kolom/rentang, mempertahankan angka/tanggal numerik, NIS teks, header gabungan, lebar, subtotal, dan pengaturan cetak. URL serta parameter lama tetap berlaku. Kunci sesi dilepas sebelum pekerjaan ekspor panjang.

Laporan Global tidak menampilkan suffix `(@username)` pada pilihan operator, layar, PDF, maupun Excel. Nama petugas dan ID/filter/pencocokan operator historis tetap tersedia. Snapshot audit tidak dihapus.

## Buku PLQ-20

Semua kepala/isi panduan, uji kalibrasi, dan PDF produksi rata tengah horizontal dengan DejaVu Sans bold. Pengukuran lebar memakai font bold agar tanggal/nominal muat. Posisi vertikal, koordinat grid, ukuran/tinggi baris, rentang, saldo berjalan dan pengaturan browser dipertahankan. Kepala kelompok Tabungan mempunyai padding simetris. PDF produksi tetap hanya isi tanpa garis/header/identitas.

Kartu panduan mempunyai padding 24 px desktop/16 px ponsel; jarak penjelasan ke iframe 14 px dan iframe ke tombol 16 px. Panduan dan produksi memakai koordinat teks identik. [Pengaturan fisik EPSON PLQ-20](CETAK_PLQ20_DAN_LAPORAN_20261018.md) tetap perlu dicocokkan melalui cetak uji pengguna.

## Pengukuran dan pengujian

Cadangan sumber sebelum perubahan: `C:/laragon/backups/spp-management-system/report_speed_20261018_201823/source-before.zip`. Artefak dan metrik privat berada pada direktori yang sama. Database uji memakai UUID terpisah, port 64421, PHP loopback 8893; mutasi pengujian tidak menyentuh database operasional.

Lima pengulangan pada perangkat/model yang sama menghasilkan median berikut (aplikasi/render sampai file siap, tanpa waktu mekanis printer):

| Kasus | Sebelum | Sesudah, file baru |
|---|---:|---:|
| Matriks 171 × 156, empat kelompok | 5,93 detik | 0,43 detik (92,8% lebih cepat) |
| Matriks yang sama, seluruh 38 kelompok | — | 1,62 detik |
| Excel matriks yang sama | — | 2,36 detik |
| Rekap Excel, seluruh komponen | 5,69 detik | 0,82–1,53 detik pada dua rangkaian lima uji |
| Otorisasi seluruh riwayat pada database uji | — | 0,97–1,10 detik pada dua rangkaian lima uji |

Laporan Global standar pada data uji siap kurang dari satu detik; reuse tetap membaca model terbaru. Kasus lebih besar Semua Unit dengan beberapa tahun, semua komponen dan bulan membutuhkan sekitar 8 detik PDF/Excel, dengan reuse sekitar 1,4 detik karena pembacaan model lintas banyak bagian masih berjalan. Target standar tidak dianggap jaminan untuk semua ukuran data/perangkat. Hasil lengkap lima permintaan baru/reuse tersedia pada `request-benchmark.json`.

Verifikasi mencakup sembilan jenis × SD/SMP/SMA/Semua Unit, kesamaan nominal PDF/Excel dengan model existing, transaksi baru/koreksi/arsip, perubahan setelah preview, isolasi unit/akun/peran, file rusak/kedaluwarsa, kegagalan penyimpanan, lima klik bersamaan (satu render), retensi 32, serta PDF dapat diekstrak dan tidak terpotong. Uji sintetis 684 × 156 selesai sekitar 5,84 detik/124 MB; seluruh identitas hadir pada 38 kelompok. Alasan panjang dilanjutkan utuh. Workbook sebelum/sesudah optimasi mempertahankan 6.214 tipe/nilai sel.

Pengujian browser mencakup tiga palet, dua tema, desktop/tablet/ponsel, jarak panduan, tampilan PDF asli, kegagalan/retry, dan Print/Download setelah data berubah. Test utama: `report_speed_benchmark.php`, `report_speed_request_benchmark.php`, `report_speed_http_test.php`, `report_speed_layout_test.php`, `report_speed_browser_test.js`, `report_speed_output_test.py`, `report_cache_test.php`, serta regresi PLQ-20/Excel/Otorisasi.

Tidak ada migrasi, indeks baru, atau perubahan perhitungan/tagihan/jurnal/saldo/hak akses. Pemeriksaan sebelum/sesudah menunjukkan 37 fingerprint tabel identik: 1.040 transaksi pembayaran berjumlah Rp 582.975.000 dan 18 rekening tabungan dengan saldo Rp 3.650.000 tetap sama. Kalibrasi fisik PLQ-20 tetap memerlukan cetak uji pengguna.
