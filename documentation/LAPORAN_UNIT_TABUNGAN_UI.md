# Redesign Laporan Umum, Unit Operasional, dan filter Tabungan

## Perubahan

- Laporan Umum mengikuti referensi: banner dengan SVG sederhana, tautan Laporan Global, filter, empat kartu ringkasan, serta tab Ringkasan/Komponen Pembayaran/Daftar Transaksi. Ringkasan menampilkan dua tabel berdampingan pada desktop. Tablet dan ponsel memakai susunan vertikal; tabel lebar bergulir di dalam kartunya.
- Grafik kecil memakai jumlah pembayaran nyata selama tujuh hari hingga tanggal akhir laporan, dalam cakupan unit dan siswa yang dipilih. Ilustrasi besar merupakan dekorasi, ditandai `aria-hidden`.
- Ringkasan nominal tetap memakai perhitungan sebelumnya. Jumlah transaksi diberi label **Jumlah Transaksi Pembayaran**, agar tidak dikira jumlah gabungan pembayaran dan tabungan. Jenis laporan menentukan tabel hasil; pilihan beberapa jenis tetap memiliki bagian masing-masing.
- Tab dan pagination mempertahankan parameter filter. Tab juga tersedia tanpa JavaScript. Ekspor XLSX/PDF membawa ID siswa, sehingga siswa dengan NIS yang sama antarunit tetap terpisah. Cetak Dipilih menolak transaksi tanpa hak cetak pada UI dan server.
- Menu `⋯` menggunakan dialog Riwayat Aktivitas yang sudah tersedia. Tanpa JavaScript, tersedia tautan riwayat siswa.
- Sidebar Super Admin memakai empat tombol ikon, tetap memilih satu unit melalui POST/CSRF yang sudah tersedia. Warna operasional SD hijau, SMP biru, SMA merah, dan Semua Unit ungu. Warna ikon mengacu pada unit operasional, termasuk ketika rekap memiliki cakupan berbeda. Semua Unit nonaktif pada halaman transaksi. Kontrol native tetap menjadi sumber nilai dan fallback tanpa JavaScript.
- Pergantian unit juga membersihkan identitas siswa, jenis/detail tabungan, serta filter saldo dari unit sebelumnya.
- Riwayat Tabungan memperbaiki benturan `overflow`, ukuran input, kisi filter, dan posisi tombol. Saran siswa tidak terpotong oleh kartu. Panel menyesuaikan ruang atas/bawah layar, memiliki latar padat, dan daftar dapat digulir. Rekap saldo serta hak cetak tetap dipertahankan.

## Verifikasi 7 Oktober 2026

Seluruh pemeriksaan dijalankan pada salinan `db_spp_audit_authorization_20261007`.

- **48 kombinasi**: dua halaman × empat cakupan unit × dua tema × tiga ukuran layar (1600, 900, 390 piksel). Tidak ada luapan horizontal atau kesalahan JavaScript.
- Saran siswa diperiksa dengan `elementFromPoint`: opsi benar-benar berada dalam layar, tidak tertutup elemen lain, dan dapat dipilih. Identitas siswa diperiksa setelah pemilihan.
- Jumlah, nominal, identitas, dan urutan seluruh halaman pembayaran dicocokkan dengan query database. Filter beberapa jenis, hasil kosong, nilai tidak sah, tab, pemuatan ulang, pagination, serta fallback tanpa JavaScript diperiksa.
- Tombol unit, perubahan warna ikon, larangan Semua Unit pada formulir transaksi, dan kompatibilitas perubahan kontrol native diperiksa.
- Admin/Bendahara tetap dapat membuka Laporan Umum; Kasir mengikuti pengalihan akses yang sudah ada. Cetak Dipilih tanpa kepemilikan ditolak 403.
- XLSX dibaca kembali pada empat cakupan: lembar Ringkasan/Pembayaran/Tabungan, jumlah baris, NIS, dan total cocok dengan sumber untuk siswa terpilih. PDF asli berhasil pada keempat cakupan.
- Pemeriksaan sintaks PHP/JavaScript dan `git diff --check` lulus. **30 pemeriksaan integritas keuangan bersih**, dengan seluruh metrik sebelum/sesudah identik.

Pengujian tidak menambahkan mutasi keuangan. Tidak ada migrasi, perubahan database aktif, commit, atau push. Perubahan lokal sebelumnya dipertahankan.

## Menjalankan pengujian

### Perapian ukuran lanjutan

Kartu Unit Operasional dipadatkan menjadi sekitar 165 piksel pada desktop, dengan tombol unit setinggi 54 piksel. Ikon judul, badge peran, jarak, dan keterangan diperkecil tanpa mengubah alur pemilihan. Label visual Semua memakai nama aksesibel **Semua Unit**.

Banner rekap, filter, kartu ringkasan, serta tab dibuat lebih padat. Kontrol filter seragam 44 piksel; tabel transaksi mendapat proporsi lebih besar, header lebih terbaca, dan baris total komponen diberi latar penegas. Pengujian 48 kombinasi tampilan/pencarian dijalankan kembali dan lulus, termasuk tab, hak cetak, perpindahan unit, serta fallback tanpa JavaScript.

Gunakan server PHP lokal yang menunjuk ke database salinan. Siapkan direktori artefak di luar repositori. Tetapkan `SPP_DB_NAME`, `SPP_HTTP_BASE`, `SPP_QA_DIR`, dan `SPP_PLAYWRIGHT_CORE`; identitas server harus sama dengan database salinan. Endpoint identitas QA mengikuti pengaman lingkungan pengujian yang sudah tersedia (`SPP_TEST_ALLOW_MUTATION=1`), meskipun pengujian ini hanya membaca data.

```text
php tests/finance_workspace_fixture.php
node tests/finance_workspace_browser_test.js
php tests/finance_workspace_exports_test.php
php tests/readiness_integrity_audit.php
```

Fixture hanya menulis sesi PHP dan berkas artefak, tanpa mengubah tabel. Berkas `fixture.json` berisi cookie pengujian: simpan di direktori QA lokal, jangan masukkan ke Git. Pemeriksaan fisik printer dan Microsoft Excel desktop belum dilakukan pada pekerjaan tampilan ini.
