# Pelacakan pembayaran Legacy pada Laporan Global

Perbaikan pembaca laporan, 26 Oktober 2026. Tidak menjalankan migrasi,
backfill, penerbitan tagihan atau impor ulang pada database pengguna.
Tanggal awal tetap hari ini; tidak ada tombol, filter, menu atau kolom baru.

## Penyebab dan perilaku

Pembayaran Eskul Robotik Aqila SD `252601095` sebesar Rp450.000 pada
2 Oktober 2026 sudah berkategori `biaya_lain:20`. Pembaca transaksi Global
mengenalinya ketika tanggal tersebut tercakup. Rekap per Item sebelumnya
mengambil status tahun ajaran lalu mengganti pembayaran dengan agregat
tanggal terpilih, sehingga bukti pembayaran berubah menjadi Belum Diketahui.

Pembaca biaya kini menggabungkan pembayaran native tanpa tagihan, arsip
Migrasi Penuh, serta transaksi operasional Legacy yang aktif. Kunci kategori
efektif dan unit menentukan master; nama sama atau metadata lama tidak
menggantikan koreksi pengguna. Setiap slot dijumlahkan sekali dalam sen.
Pembacaan bertahap dan cache selama permintaan GET menghindari pemuatan
arsip mentah dan pengulangan pembacaan untuk setiap kategori.

- Rentang yang mencakup 2 Oktober menampilkan pembayaran tanpa tagihan
  Rp450.000 dan status **Ada Pembayaran**.
- Tanggal di luar pembayaran tetap menampilkan nominal periode yang sesuai,
  dengan status **Ada Pembayaran di Luar Periode**. Status ini memakai pilihan
  Ada Pembayaran yang sudah tersedia, tanpa menambah pilihan filter.
- Tagihan dan sisa yang belum terbukti tetap null, ditampilkan tanda pisah.
  Bukti penerimaan tidak dijadikan bukti lunas atau tarif baru.
- Sisa tagihan native menggunakan seluruh alokasi sah; pembatasan tanggal
  pembayaran tidak membuat kembali tunggakan yang telah dibayar. Histori
  tanpa alokasi tidak membayar ulang tagihan saldo awal Legacy.
- Rekap Status dan per Item mempertahankan kelas transaksi historis, termasuk
  pencarian/filter rombel setelah siswa berpindah kelas. Identitas antarunit
  tidak digabungkan.

Pembaca periode kewajiban juga mempertahankan kompatibilitas U_SORGA lama
sebagai Komite dan membaca tahun DU eksplisit pada rincian meskipun periode
header struk kosong. Tanggal kas tidak dipakai menggantikan periode kewajiban.

Riwayat Tagihan sebelumnya hanya memproyeksikan pembayaran tanpa tagihan
untuk SPP, Komite dan DU. Biaya Lain Legacy sekarang turut tampil dengan
pembayaran aktual, periode sumber dan tanggal penerbitan yang tidak diketahui.
Filter tanggal penerbitan tidak menganggap tanggal kas sebagai tanggal tagihan.
Pokok/sisa yang belum diketahui tetap null setelah pengelompokan siswa/komponen.

Sel komponen existing pada layar, PDF dan Excel memperlihatkan pokok,
pembayaran, serta sisa untuk bukti pembayaran tanpa tagihan. Susunan kolom
tetap. Nilai pembayaran tidak disamarkan sebagai pokok tagihan; Excel
mempertahankan angka biasa sebagai numerik dan bukti campuran sebagai teks
beberapa baris dalam sel yang sama.

## Pengujian

Server pengujian dipulihkan dari backup terverifikasi job
`d4904c2751981b32490587c00cc4dfc4`, SHA-256
`ea1e0ad46a4af07e2bca9bafe630924cf4a3d3e11437c1ed72121b70f17ae2fb`.
MySQL salinan memakai port 58470, UUID
`beedfd77-d0f5-11f1-b1ea-0a0027000013`, datadir privat berbeda,
event scheduler OFF. Checksum seluruh 62 tabel salinan cocok dengan bukti
backup sebelum fixture. HTTP QA memakai loopback port 58471 dan sesi privat.

Suite khusus:

- `tests/global_legacy_fee_tracking_test.php`: Aqila, pencarian, periode,
  ekspor model, metadata lama, slot berulang, koreksi kategori, pembatalan,
  kelas historis, tanggal/periode kosong, arsip lama, saldo awal dan native.
- `tests/global_legacy_fee_reconciliation_test.php`: pembanding independen
  tabel sumber/JSON per kategori, jumlah slot dan nominal dalam sen.
- `tests/global_legacy_fee_exports_test.py`: halaman dan file PDF/XLSX asli,
  pencarian nama/NIS, status/rentang tanggal serta Semua Unit.
- `tests/global_legacy_fee_browser_test.js`: kontrol pencarian existing,
  status, desktop/ponsel, terang/gelap dan galat JavaScript.

Regresi existing memakai suite Global, native, coverage, koreksi, bulanan,
Komite, DU, pengelompokan tagihan dan matriks. Fixture coverage secara eksplisit
menyiapkan kondisi tanpa tagihan native di dalam rollback; snapshot baru sudah
memiliki tagihan Oktober hasil rekonstruksi sehingga fixture lama tidak lagi
mewakili kondisi yang hendak diuji.

Suite matriks lama `global_report_matrix_test.php` menolak database bernama
`db_spp_test3` dan membutuhkan baseline audit lamanya. Guard itu dipertahankan;
pemeriksaan matriks pada pekerjaan ini memakai suite pelacakan khusus,
halaman serta pembacaan ulang PDF/XLSX dari instance terisolasi di atas.

Hasil suite yang selesai:

| Pemeriksaan | Hasil |
| --- | --- |
| Pelacakan biaya Legacy dan fixture campuran | 45 pemeriksaan lulus |
| Rekonsiliasi biaya dengan rincian sumber independen | 122 pemeriksaan lulus, 113 kategori/master |
| Rekonsiliasi seluruh kategori dan sembilan template Global | 3.142 pemeriksaan lulus, termasuk total gabungan Semua Unit |
| Native, coverage dan koreksi Global | 68, 15 dan 15 pemeriksaan lulus |
| SPP–Komite, PSB dan kredit periode mendatang | 30 pemeriksaan integrasi bulanan lulus |
| Struk/kredit Komite | 1.768 pemeriksaan lulus, 1.731 siswa |
| DU | 45 pemeriksaan planner dan 224 integrasi lulus |
| Pengelompokan Riwayat Tagihan | Lulus |
| Sembilan template, SD/SMP/SMA/Semua Unit melalui HTTP | 225 pemeriksaan lulus, 36 pasangan PDF/XLSX |
| Kasus Aqila pada layar dan PDF/XLSX | 169 pemeriksaan lulus |
| Pembacaan ulang nominal/identitas PDF dan Excel | 349 pemeriksaan lulus |
| Browser desktop/ponsel, terang/gelap | 25 pemeriksaan lulus |

Pembanding biaya memeriksa 24.944 slot positif yang sudah memiliki kategori
master: SD 9.438 slot/39 kategori dengan 131.039.505.100 sen; SMP 13.592
slot/51 kategori dengan 317.401.050.000 sen; SMA 1.914 slot/23 kategori dengan
58.948.260.000 sen. Jumlah slot dan nominal cocok dengan sumber, termasuk
agregat Semua Unit. Biaya belum teridentifikasi tetap memakai kategori
historinya sendiri, bukan dipaksakan masuk salah satu master tersebut.

PDF kasus tanggal tepat, di luar periode dan Riwayat Tagihan dirender lalu
diperiksa secara visual. Screenshot ponsel dan desktop juga diperiksa.
Lint PHP, sintaks JavaScript/Python dan pemeriksaan whitespace perubahan lulus.

Fixture mutasi transaksi hanya pada salinan dan ditutup dengan rollback.
Akun/sesi HTTP QA dibuat hanya pada salinan. Setelah pengujian, layanan HTTP
58471 dan MySQL 58470 dihentikan setelah verifikasi UUID, datadir dan port.
Seluruh dump, sesi, identitas, PDF/Excel, screenshot serta laporan pengujian berada di
luar Git/webroot pada:

`C:/laragon/data/spp-qa/global_audit_20261021_tracking_20261026`.

Pemeriksaan pengguna dilakukan dalam transaksi READ ONLY pada identitas server
yang telah diperiksa. Kasus Aqila cocok dengan hasil salinan. Perbandingan
62 checksum tabel sebelum/sesudah sesi mencatat perubahan pada tujuh tabel DU:
`daftar_ulang_data`, `tagihan_daftar_ulang_data`, `legacy_op_obligations_data`,
`legacy_op_pending_data`, `legacy_op_audit_data`, `daftar_ulang_audit_log_data`,
dan `legacy_full_batches_data`. Pembaruan DU terpisah juga berlangsung di
workspace ini; pekerjaan Global tidak menerapkan data pada pengguna. Seluruh
tabel arsip mentah, identitas, kas, rekening dan jurnal tetap sama. Karena itu
tidak dinyatakan bahwa seluruh 62 tabel pengguna identik selama sesi.

Pengukuran ini tidak membuktikan cetakan fisik atau kecepatan produksi.
Riwayat yang tidak memiliki bukti pokok/periode tetap dinyatakan belum diketahui;
perbaikan hanya menyatakan pembayaran yang memang dapat dibuktikan.

Pembersihan cache Python hasil pengujian ditolak pemeriksaan persetujuan
otomatis dengan alasan `blocked by policy`; cache dibiarkan, tanpa mencoba
penghapusan melalui cara lain.
