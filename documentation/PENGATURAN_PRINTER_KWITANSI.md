# Pengaturan printer kwitansi

Kwitansi pembayaran tunggal, tahunan, dan PDF transaksi terpilih memakai ukuran akhir **215,9 × 139,7 mm (Letter separo)**. Slip menggunakan teks serif hitam sekitar 11 pt dengan kop dua baris seperti contoh sekolah. Preview/PDF/PNG memakai sumber dokumen yang sama; cetak langsung/PDF menggunakan teks, bukan gambar tangkapan layar. Pengaturan berikut dilakukan sekali pada setiap PC yang mencetak.

1. Pasang driver resmi printer. Untuk EPSON LX-310, gunakan driver LX-310 yang sesuai versi Windows.
2. Buka **Printing Preferences** printer. Buat ukuran khusus **Letter separo** dengan lebar 215,9 mm dan tinggi 139,7 mm. Jika driver meminta ukuran potret, masukkan 139,7 × 215,9 mm lalu pilih **Landscape/Lanskap** agar ukuran akhirnya sama.
3. Simpan sebagai profil **Kwitansi SistemSPP**. Gunakan sumber kertas continuous/tractor bila memakai kertas bersambung. Atur skala **100% / Actual size**.
4. Jika printer juga dipakai untuk laporan, pertahankan profil **Laporan A4**. Bila driver tidak menyediakan profil, pengelola PC dapat membuat antrean printer kedua bernama **Kwitansi SistemSPP** pada port printer yang sama, dengan ukuran khusus tersebut sebagai default. Jangan mengubah seluruh keluaran menjadi Letter separo.
5. Buka kwitansi aplikasi. Pada dialog cetak Chrome/Edge, pilih printer/profil kwitansi, satu halaman per lembar, skala 100%, serta matikan **Headers and footers/Header dan footer**. Jangan memakai Fit/Shrink yang mengecilkan teks.
6. Cetak satu contoh dan ukur hasilnya. Pastikan nama, nominal, total, serta tanda tangan tidak terpotong. Atur posisi awal kertas dan kualitas/density pada driver atau printer jika cetakan masih pucat.

Browser menyimpan sebagian pilihan cetak, tetapi pilihan dapat berubah setelah pembaruan driver, browser, atau perpindahan printer. Aplikasi menetapkan ukuran dokumen dan tidak dapat memaksa setelan perangkat. Bila ukuran tidak muncul, periksa dukungan ukuran khusus pada driver. Jangan menganggap pengaturan pada satu PC otomatis berlaku pada PC lainnya.

Laporan dan surat tetap memakai A4 sesuai orientasinya. Semua cetak Tabungan memakai **kertas kosong A5 potret**, skala **100% / Actual size**. Tabel berisi No., Tanggal, Masuk, Keluar, Saldo, dan Paraf, tanpa sampul, halaman terbalik, susunan lipat, atau baris kosong tambahan. Semua tombol **Cetak Buku Tabungan** membuka seluruh mutasi siswa, terlepas dari filter Operator/tanggal pada riwayat. Cetak dari tombol PDF/printer PDF, bukan tangkapan layar iframe.



**Unduh PNG** tersedia pada bukti pembayaran tunggal/tahunan. Satu halaman menghasilkan PNG 2550 × 1650 piksel; beberapa halaman menghasilkan ZIP dengan nomor halaman berurutan. Tunggu pesan selesai dan periksa unduhan browser. Gambar dapat dibagikan; untuk mencetak pada ukuran fisik yang tepat, tetap pilih profil Letter separo dan skala 100%. PDF/cetak browser tetap tersedia.

Rujukan pengelolaan ukuran kertas Chrome: [PrintingPaperSizeDefault](https://chromeenterprise.google/intl/en_uk/policies/printing-paper-size-default/). Kebijakan perangkat tersebut bukan pengaturan driver yang dapat dipaksakan oleh halaman web.

Lihat [penyempurnaan cetak dan histori Input Pembayaran](PENYEMPURNAAN_CETAK_PEMBAYARAN_20261018.md) untuk aturan terbaru jarak struk, Sisa PSB/DU, nama penanda tangan kapital, dan histori tanpa Export/pagination.

**Pembaruan cetak Tabungan 18 Oktober 2026:** ketentuan kertas kosong/tabel lengkap di atas digantikan oleh buku bergaris A5 terbuka pada **EPSON PLQ-20 ESC/P2**. Printer hanya mengisi transaksi. Gunakan Passbook On, Binding Horizontal, A5 potret dan Actual size 100%; atur rentang transaksi, halaman/baris awal serta kalibrasi melalui preview. Garis panduan tidak ikut PDF produksi. Lihat [panduan kalibrasi PLQ-20](CETAK_PLQ20_DAN_LAPORAN_20261018.md). Struk pembayaran tetap Letter separo.
