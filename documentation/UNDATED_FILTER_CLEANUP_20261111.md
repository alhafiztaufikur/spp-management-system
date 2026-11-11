# Pembersihan filter histori tanpa tanggal

## Perubahan operasional

Banner Legacy Riwayat Daftar Ulang dan kartu checkbox histori tanpa tanggal pada
Riwayat Pembayaran dihapus. Empat kartu ringkasan DU tetap tersedia. Laporan
Transaksi tidak lagi memiliki strip checkbox, termasuk Semua periode. Seluruh
template Laporan Global menggunakan formulir tanpa checkbox transaksi tanpa tanggal.

Pada desktop, tindakan Riwayat Pembayaran berada di sebelah Jenis pemilik agar
penghapusan kartu tidak menyisakan satu baris kosong. Pada ponsel, tombol tetap
mengikuti alur formulir existing. Pembungkus dan selector khusus elemen yang
dihapus juga dibersihkan.

Parameter publik `undated` dan `include_undated` diabaikan pada riwayat dan laporan
terkait, termasuk ekspor serta surat orang tua. Laporan Transaksi juga mengabaikan
`all_periods`. Tautan ekspor, pagination, token kembali baru/lama, dan tujuan
pergantian unit tidak membawa kembali parameter yang dihentikan. Tujuan unit
tetap mempertahankan parameter asli lainnya, termasuk `unit=active`.

Semua Periode pada Riwayat Pembayaran tetap membaca seluruh histori yang memenuhi
filter lain. Laporan kas dengan rentang tanggal tetap mengecualikan transaksi
tanpa tanggal. Pembacaan kredit dan pembayaran berdasarkan periode kewajiban
tetap dipertahankan; fungsi internal pembuktian periode tidak dihapus. Tanggal
yang tidak tersedia tidak diganti dengan tanggal buatan.

## Verifikasi aktual

- Pembanding source sebelum perubahan: commit `4d8977f`.
- Target localhost tetap `db_spp_test3`, port 3306, UUID
  `e6e5c8d8-b81c-11f1-9be5-745d2224b37e`.
- Salinan pengujian: `db_spp_audit_masterperf2`, port 3320, UUID
  `9192dec5-dd41-11f1-a79a-745d2224b37e`, datadir berbeda dan event scheduler OFF.
  Salinan hasil restore terverifikasi dari pekerjaan sebelumnya digunakan kembali.
- **42 skenario model HTTP** cocok sebelum/sesudah: empat halaman, kedua peran,
  SD/SMP/SMA/Semua Unit; Semua Periode; serta sembilan template Global tambahan.
  Parameter URL lama tidak mengubah model hasil atau muncul kembali pada navigasi.
- **5 skenario ekspor** lulus: Excel/PDF Laporan Transaksi serta Excel/PDF/cetak
  Laporan Global. Model dokumen sebelum/sesudah identik dengan parameter lama;
  unduhan memiliki signature XLSX/PDF yang valid. Total keseluruhan: **47 skenario**.
- **16 kombinasi browser**: empat halaman, tema terang/gelap, lebar 1440/390 px.
  Penghapusan kontrol, overflow, jarak tindakan, pencarian kosong, keyboard Enter,
  Reset, Semua Periode, detail pembayaran, dan empat kartu DU diperiksa.
  Posisi tombol desktop Riwayat Pembayaran diperiksa lagi setelah CSS final.
- **11 pemeriksaan kebijakan**: normalisasi request, serialisasi filter,
  pembuktian periode internal, token kembali lama/baru, dan isolasi unit token.
- **6 pemeriksaan bukti tanpa tanggal** pada transaksi fixture salinan yang
  di-rollback: rentang tanggal mengecualikan penerimaan, Semua Periode tetap
  membacanya, kredit SPP/Komite per periode sama, dan tanggal asal kembali utuh.
- **14 pemeriksaan localhost**: login, health, dan keempat halaman pada tiga unit.
  Tidak ada exception JavaScript atau error console dari aplikasi. Chrome juga
  meminta `http://localhost/favicon.ico` yang menghasilkan 404 di luar direktori
  aplikasi; kejadian existing tersebut dicatat terpisah pada artefak pengujian.
- PHP lint, JavaScript syntax, empat parser CSS, **506 selector**, detector
  kontrol/pembungkus yang dihentikan, serta `git diff --check` lulus.
- Checksum **67 tabel pada masing-masing server** identik sebelum/sesudah.
  Hash `koneksi.php` sama dengan salinan awal. Tidak ada migrasi, penerapan data,
  perubahan transaksi, atau rekonsiliasi Legacy pada target.

Data pembayaran Legacy salinan saat ini memiliki **nol tanggal kosong** pada
ketiga unit. Karena itu, pengujian fixture rollback tetap dijalankan agar
perlindungan histori tanpa tanggal benar-benar teruji. Tidak ada asumsi bahwa
nominal yang belum terbukti adalah nol atau lunas.

Artefak privat: `C:/laragon/backups/spp-management-system/undated_filters_20261111/`.
Model dan HTML mengandung data siswa sehingga tidak dimasukkan Git. Bukti utama:
`http-models-result.json`, `http-exports-result.json`, `browser-matrix-result.json`, `browser-result.json`,
`localhost-result.json`, dan `data-verification.json`.

## Menjalankan pemeriksaan ulang

`tests/undated_filter_policy_test.php` tidak memerlukan koneksi database.
`tests/undated_filter_evidence_test.php` memerlukan clone audit pada server berbeda,
`SPP_TEST_ALLOW_MUTATION=1`, dan UUID server melalui `SPP_FILTER_MYSQL_UUID`.
Fixture hanya hidup dalam transaksi yang selalu di-rollback.

Untuk HTTP, jalankan dua PHP server loopback dengan
`tests/undated_filters_router.php`: source sebelum/sesudah, database salinan yang
sama, serta sesi privat delapan kombinasi peran/unit. Router hanya menerima GET
pada rute yang diuji dan membungkus pembacaan dalam transaksi READ ONLY. Set
`SPP_FILTER_AUDIT_ROOT` ke direktori privat di luar web root,
`SPP_FILTER_WEBROOT`, `SPP_FILTER_VARIANT=before|after`, dan variabel database.
Direktori `before`, `after`, `sessions` serta `sessions.json` harus tersedia.

Jalankan `tests/undated_filters_http_test.js` dengan URL masing-masing server pada
`SPP_FILTER_BEFORE_URL`/`SPP_FILTER_AFTER_URL`. Bagian `models` dan `exports` dapat
dijalankan terpisah lewat `SPP_FILTER_HTTP_SECTION`; default menjalankan keduanya.
Browser memakai URL sesudah perubahan dan modul Playwright melalui
`SPP_PLAYWRIGHT_PATH`. Detector statis memakai parser eksternal lewat
`SPP_CSS_TOOLS`, tanpa memasang dependensi ke project.

## Delivery Gate antislop

Mode During berasal dari pilihan pengguna dalam sesi. Design Read: formulir
operasional sekolah dengan identitas SistemSPP dan palet unit existing.
ENERGY 1 / RHYTHM 1 / MOTION 1 dipertahankan. Fokus tetap pada filter dan tindakan
Tampilkan Rekap; ruang dipadatkan dengan menghapus pembungkus, bukan menggantinya
dengan dekorasi atau paragraf baru. Gate berikut berlaku pada lingkup perubahan.

| Aturan | Hasil dan bukti |
|---|---|
| R-02 | PASS: tidak menambahkan teks UI atau tanda baca baru. |
| R-03 | PASS: 16 kombinasi browser tanpa overflow dokumen. |
| R-17 | PASS: empat ringkasan DU tetap berasal dari model database yang sama. |
| R-18 | PASS: tidak menambah testimoni atau identitas fiktif. |
| R-23 | PASS: tidak membuat aset, statistik, atau navigasi baru. |
| R-24 | PASS: tautan existing dipertahankan; parameter lama dibersihkan. |
| R-25 | PASS: warna, font, dan kontras kontrol existing tidak diubah. |
| R-26 | PASS: submit, Reset, Semua Periode, dan detail berhasil di browser. |
| R-27 | PASS: pencarian tanpa hasil tetap menampilkan empty state existing. |
| R-28 | PASS: tidak menambah FAQ. |
| R-32 | PASS: fokus tombol dan aktivasi Enter diuji pada 16 kombinasi. |
| R-33 | PASS: perubahan ditulis langsung di source PHP/CSS. |
| R-34 | PASS: tema terang dan gelap diperiksa pada desktop/ponsel. |
| R-35 | PASS: aplikasi dijalankan, interaksi terkait diuji, dan localhost diverifikasi. |
| R-36 | PASS: hasil di atas berasal dari artefak aktual, tanpa klaim bebas bug. |
| R-37 | PASS: arah berasal dari plan pengguna; konsep dan identitas dipertahankan. |
| R-38 | PASS: data tampilan tetap berasal dari pembaca existing. |
| R-39 | PASS: native control dan penamaan tindakan dipertahankan; input dihapus dari DOM. |
| R-40 | PASS: tidak menambah media, script, atau klaim performa. |
| R-01 | PASS: tidak menambah gradient/glow; palet existing tetap untuk identitas unit. |
| R-04 | PASS: tidak menambah ikon. |
| R-06 | PASS: tipografi operasional existing dipertahankan. |
| R-07 | PASS: tidak menambah background grid. |
| R-08 | PASS: tidak menambah panah dekoratif. |
| R-09 | PASS: tidak menambah badge. |
| R-10 | PASS: tidak menambah efek kaca. |
| R-12 | PASS: tidak menambah shadow. |
| R-13 | PASS: tidak menambah glow. |
| R-14 | PASS: empat kartu DU tetap dipakai untuk membandingkan ringkasan keuangan. |
| R-19 | PASS: tidak menambah animasi. |
| R-22 | PASS: tidak menambah ilustrasi. |
| Liveliness | PASS: dials dinyatakan, fokus tindakan dan palet unit tetap; ruang bekas banner dihapus. |
| C-1 | PASS: setiap penghapusan mengikuti daftar kontrol pada plan pengguna. |
| C-2 | PASS: tindakan tersisa memiliki perilaku existing yang diuji. |
| C-3 | PASS: pembungkus tanpa fungsi ikut dihapus. |
| C-4 | PASS: desktop, ponsel, keyboard, dan dua tema diperiksa. |
| C-5 | PASS: tidak menambah klaim, transaksi, atau saldo buatan. |
| R-05 | PASS: struktur formulir mengikuti konten existing; baris kosong tidak dipertahankan. |
| R-11 | PASS: radius kontrol existing tidak diubah. |
| R-15 | PASS: label tindakan existing yang spesifik dipertahankan. |
| R-16 | PASS: tidak menambah copy promosi. |
| R-20 | PASS: identitas SistemSPP dan konteks sekolah tetap. |
| R-21 | PASS: pemilihan tema existing tetap bekerja. |
| R-29 | PASS: tidak menambah warna. |
| R-30 | PASS: tidak mengganti desain dengan pola produk lain. |
| R-31 | PASS: satu perubahan tata letak menyatukan tindakan dan Jenis pemilik agar lebih rapat. |
