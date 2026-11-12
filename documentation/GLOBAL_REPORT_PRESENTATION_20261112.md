# Penyeragaman tombol desktop dan laporan global

Perubahan ini hanya memformat tampilan. Tidak ada skema, API publik, migrasi,
penetapan tagihan, atau perubahan saldo/transaksi.

`includes/global_report_presentation.php` membuat salinan model sebelum matriks
dan pengukuran lebar. Label bulanan per-item hanya disederhanakan pada cetakan;
status pada layar dan nilai sel Excel tetap dipertahankan. SPP tahunan menyimpan
nominal bulanan yang ditampilkan sendiri sebagai angka Excel; nilai tidak
diketahui tetap null/“—”. Pemisahan bagian per tahun tetap menghitung subtotal
dari bagian tersebut.

Kode operator Legacy dibaca dari token identitas asal khusus laporan transaksi
tabungan. Formatter Legacy bersama, catatan asli, jurnal penyesuaian dan semua
nilai saldo tetap utuh. Kolom yang disembunyikan hanya dikeluarkan dari daftar
kolom presentasi; total dihitung dari model lengkap terlebih dahulu.

Tombol memakai `assets/css/desktop_actions.css`, mulai 1024 px. Cascade layer
menjaga radius 8 px ketika halaman memiliki aturan lokal `!important`. Pratinjau
mandiri juga memuat aturan yang sama. Avatar dan input checkbox/radio tidak
dinormalisasi. Cakupan badge diperluas dalam
[penyesuaian tombol dan label desktop](DESKTOP_COMPONENT_SHAPES_20261112.md).

Ringkasan lima laporan menggunakan blok Total Rupiah rata kanan, maksimal 480 px
di layar/95 mm pada PDF. Judul dan tabel PDF dipertahankan bersama. Workbook
memakai blok kanan sesuai lebar kolom fisik, angka bertipe numerik, dan font
ringkasan tersendiri.

## Verifikasi

- `tests/global_report_presentation_test.php`: cakupan keluaran, data sumber,
  null/angka, kode operator, pemisahan tahun, dan ringkasan Excel.
- `tests/global_report_presentation_clone_test.php`: tujuh laporan × empat unit,
  perbandingan jumlah baris, nominal, subtotal/total, dan checksum tabel keuangan.
  Memerlukan salinan audit atau manifest server terpisah yang diverifikasi oleh
  `tests/http_form_scope.php`.
- `tests/global_report_presentation_browser_test.js`: inventaris tombol, dialog,
  desktop 1280/1440/1920, palet unit, terang/gelap, guard filter, PDF dan XLSX.
- `tests/global_report_presentation_native_excel_test.ps1`: membuka hasil pada
  instance Excel tersembunyi milik pengujian, hanya baca, lalu mencetak PDF.
- `tests/global_report_presentation_pdf_test.py`: label, frasa satu baris, batas
  halaman, ukuran/posisi judul ringkasan dan keberadaan nominal pada halaman sama.

Artefak pengujian ditempatkan di direktori backup privat di luar web root.
Regresi sebelumnya untuk laporan, riwayat tagihan dan Excel tetap dijalankan.

Hasil 12 November 2026: rekonsiliasi 28 kombinasi unit/laporan lulus; checksum
tabel siswa/pembayaran/tabungan yang diperiksa tetap sama. Browser lulus 91
inventaris tombol, tiga lebar desktop, empat palet dan dua tema. Excel asli
membuka ketujuh workbook hanya baca. Pemeriksaan 14 PDF (180 halaman), fixture
270 baris, gabungan Semua Unit serta laporan kosong lulus. Aturan desktop juga
dimuat oleh halaman progres restore yang tidak memakai stylesheet utama.

## Penyamaan seluruh ringkasan desktop — 13 November 2026

Kesepuluh halaman Laporan Global memakai tabel Total Rupiah dua kolom pada
desktop mulai 1024 px. Lima laporan yang sebelumnya sudah memakai tabel menjadi
acuan. PSB dan Status Pembayaran mengganti kartu akhir dengan tabel yang sama;
label, urutan, dan nominalnya tetap mengikuti agregat laporan existing.

Rekap Setoran menampilkan Total Setoran; Rekap Kas Tabungan menampilkan Total
Tabungan Masuk, Total Tabungan Keluar, dan Mutasi Bersih. Ringkasan metode serta
jumlah transaksi tetap terpisah. Riwayat Tagihan memakai agregat sisa kewajiban
dari proyeksi riwayat, bukan total pokok tagihan atau total halaman yang terlihat.
Jika tidak ada nilai grand total yang diketahui, nominal ditampilkan “—”.

Renderer `global_report_totals_html()` digunakan kembali melalui jalur khusus
layar. Daftar `global_report_compact_totals()` yang mengendalikan ekspor tidak
diperluas. CSS menyembunyikan tampilan lama hanya pada media screen mulai 1024 px;
mobile dan cetakan halaman memakai markup sebelumnya. File dan jalur ekspor
PDF/Excel tidak diubah oleh penyesuaian ini.

- `tests/global_report_desktop_totals_test.php` memeriksa batas ekspor, format
  nominal besar/negatif/pecahan/nol/null, escaping label, dan data sumber tetap utuh.
- `tests/global_report_desktop_totals_browser_test.js --baseline` menyimpan nilai
  tabel serta tampilan mobile/cetak sebelum perubahan pada server salinan
  terverifikasi. Jalankan ulang tanpa argumen pada salinan yang sama untuk
  membandingkan hasil dan memeriksa semua laporan, palet, tema, ukuran desktop,
  pagination, laporan kosong, gabungan unit, dan label panjang.
- Artefak baseline, hasil pemeriksaan, dan tangkapan layar disimpan melalui
  `SPP_UI_OUTPUT` di luar web root. Animasi antarmuka diselesaikan sebelum
  pengukuran agar perubahan media tidak menghasilkan ukuran sementara.

Hasil pemeriksaan desktop: 240 kombinasi laporan/unit/lebar/tema lulus;
40 perbandingan isi tabel, 25 pergantian halaman, 160 perbandingan mobile,
dan 40 perbandingan media cetak terhadap tampilan sebelumnya lulus. Regresi
model 28 kombinasi juga lulus dan checksum tujuh tabel keuangan/siswa tetap sama.
Pengujian tambahan lima laporan gabungan tanpa batas kelas (PSB, Status dengan
empat komponen, Riwayat Tagihan, Setoran, dan Kas Tabungan) lulus. Kelima laporan
berhasil diunduh sebagai PDF dan XLSX; dokumen A4 terbaca dan struktur workbook
valid. Sepuluh laporan kosong dan fixture label panjang/nominal besar juga lulus.
