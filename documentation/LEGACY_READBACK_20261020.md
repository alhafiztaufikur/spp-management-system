# Pembacaan hasil migrasi SD, SMP, SMA — 20 Oktober 2026

Perbaikan dipasang pada source utama dan lab copy. Data migrasi tidak diimpor atau difinalisasi ulang. Tidak ada perubahan skema, akun, konfigurasi, draf, saldo FIX, commit, push, atau deployment.

## Perubahan

Penghitung sebelumnya membaca gabungan pembayaran, sedangkan daftar hanya membaca `bayar`. Lapisan baca sekarang menyatukan jumlah, daftar, pencarian, operator, detail, dan pagination SQL. Identitas memakai unit, ID transaksi, serta ID siswa/pemilik rekening; rekening non-siswa tetap dibedakan dari siswa. ID negatif Legacy tetap kompatibel.

- Riwayat Pembayaran tetap dimulai dari bulan berjalan. **Semua Periode** mencakup transaksi tanpa tanggal; **Histori Legacy tanpa tanggal** menampilkan catatan tanpa tanggal saja. Ringkasan menunjukkan histori di luar periode.
- Histori Daftar Ulang Legacy terpisah dari tagihan operasional. Tautan transaksi memilih halaman yang benar. Konteks kembali dari histori DU, direktori rekening, Data Siswa, dan koreksi dibentuk server.
- Data Siswa mengambil jumlah histori secara berkelompok. Total catatan disertai tautan terpisah ke pembayaran dan jurnal tabungan. Daftar dan Excel memakai cakupan serta jumlah yang sama; Alumni/OUT memakai arsip jika status belum ditentukan pengguna.
- Riwayat Tabungan memakai satu proyeksi untuk jumlah, daftar, operator, filter dan ekspor. Rekap rekening dicari dan dipaginasi server, 25 pemilik per halaman, dengan aktivitas terakhir dari jurnal gabungan. Buku menerima `owner_id` untuk rekening non-siswa; identitas yang bertentangan ditolak.
- Jurnal lama, transaksi baru dan penyesuaian dibedakan. Penyesuaian mempunyai nominal tersendiri; tidak menambah total setoran/penarikan atau kas baru. Saldo peralihan diteruskan sekali pada buku.
- Ekspor **Excel - Semua Hasil** memuat seluruh hasil filter melalui penulisan bertahap. **Cetak Halaman Ini** menghasilkan PDF halaman yang sedang ditampilkan. Tanggal kosong tetap **Tidak tercatat**, terpisah dari transaksi bertanggal dalam urutan hasil; tidak diberi tanggal buatan.
- Laporan tabungan mempertahankan pemilik internal dan cakupan semua periode. Pada Semua Unit, saldo berjalan dihitung per unit sebelum digabung. Laporan penerimaan historis mempertahankan nominal sumber; dashboard/setoran kas tidak mengakui impor sebagai penerimaan baru.
- Pembacaan OUT/PSB impor lama yang kolom kelasnya kosong menggunakan klasifikasi pemilik/kelompok tersimpan. Ini hanya proyeksi baca, bukan pemindahan siswa. Arsip dan snapshot sumber tetap asli.

## Kompatibilitas lab

Lab memakai versi aplikasi lebih lama. Halaman/API yang berbeda dipertahankan pada basisnya; helper baca dan patch terkait dipasang secara terbatas. API histori siswa lab tidak mengakses kolom konteks pembayaran versi baru. Konteks tahun ajaran yang tidak tersedia ditampilkan sebagai **Konteks belum tercatat**. Tata cetak buku lab tetap memakai tata letaknya sendiri.

Daftar pemasangan dan SHA-256 sebelum/sesudah tersimpan di `C:\laragon\backups\spp-management-system\legacy_readback_20261020\installation.json`. File lama dicadangkan pada direktori bukti yang sama. Hanya file aplikasi terkait disalin; `koneksi.php`, bootstrap, akun, unggahan, antrean dan data pengguna tidak diganti.

## Hasil penerimaan

| Pemeriksaan | Hasil |
| --- | --- |
| 1–20 Oktober 2026, SD | 240 pembayaran, termasuk satu pemilik non-siswa |
| 1–20 Oktober 2026, SMP | 194 pembayaran |
| 1–20 Oktober 2026, SMA | 44 pembayaran |
| Semua Periode | 118.244 pembayaran: SD 46.852, SMP 60.181, SMA 11.211 |
| Tanpa tanggal | 9.288 pembayaran: SD 3.064, SMP 5.084, SMA 1.140 |
| Excel semua unit, dibaca ulang | 118.244 baris; Rp105.802.515.504,00; 9.288 tanggal tidak tercatat |
| Rekening | Seluruh 2.418 rekening direkonsiliasi dengan jurnal, penyesuaian dan saldo peralihan |
| Backend | 54 pemeriksaan pada source utama dan 54 pada basis lab terpasang di lingkungan terisolasi |
| Browser | 20 kasus Chromium 109.0.5412.0/Chrome, tanpa kesalahan JavaScript; 7 pemeriksaan tambahan pada lab terpasang |
| HTTP lab terpasang | Isi 12 halaman diperiksa pada ketiga unit, bukan hanya status HTTP |

Pengujian mencakup ID negatif, tautan lintas halaman, pencarian/operator, sumber native/Legacy/campuran, arsip, pemilik non-siswa, batas unit, tanpa login, CSRF, tanpa JavaScript, keyboard, desktop/ponsel, tablet, kedua tema, palet ketiga unit dan reflow setara pembesaran 200% (viewport 410 CSS px dengan DPR 2). Screenshot tersimpan bersama bukti. PDF dan XLSX dibaca ulang untuk memeriksa nama, identitas, tanggal dan nominal. Ekspor laporan penerimaan SMA Oktober cocok pada Rp25.970.000; ekspor tabungan ketiga unit memakai pemilik yang tepat dan nominal penyesuaian tersendiri.

Mutasi hanya dilakukan pada MySQL privat port 58246 hasil restore backup: transaksi baru, retry, preview/batal, koreksi, konflik versi dan rollback. Tidak dilakukan mutasi pengujian pada database pengguna. Bukti tersedia sebagai `backend-final.json`, `backend-lab-final.json`, `browser-final.json`, `installed-browser.json`, `installed-lab-http.json`, `export-readback.json`, `savings-export-results.json`, `mutation-results.json`, `rohis-export.json`, `native-source-http.json`, `native-lab-mixed-http.json`, dan `final-navigation.json`. Ekspor Rohis Mhi mempertahankan saldo akhir Rp0 dengan penyesuaian −Rp0,13 terpisah.

Fingerprint data seluruh **51 tabel database utama dan 59 tabel lab** dibandingkan sebelum/sesudah: nihil perubahan. Hash konfigurasi dan draf dilindungi; heartbeat worker lab berubah secara normal karena worker existing tetap berjalan. Layanan pengujian privat dihentikan setelah pemeriksaan; layanan aplikasi/worker pengguna dipertahankan.

## Batas pemeriksaan

PDF langsung dari daftar mencetak satu halaman yang dipilih; gunakan Excel untuk seluruh hasil filter atau laporan existing untuk format laporan penuh. Pengujian cetak memeriksa file dan render PDF, belum mencetak melalui printer fisik. Reflow 200% diemulasikan; pintasan zoom browser Ctrl+Plus tidak tersedia dalam Chromium 109 headless. Tidak dilakukan uji beban multi-pengguna atau pengiriman ke deployment. Nominal histori tidak dianggap otomatis sebagai pelunasan SPP, Komite, biaya lain atau tunggakan yang belum disahkan.
