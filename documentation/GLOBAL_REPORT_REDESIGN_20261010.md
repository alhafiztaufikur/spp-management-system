# Laporan Global — tampilan dan penggunaan

> Catatan historis: susunan katalog/header/filter dan tabel terpisah dalam dokumen ini digantikan pada 11 Oktober 2026. Lihat [Laporan Global matriks horizontal](GLOBAL_REPORT_MATRIX_20261011.md) untuk perilaku yang berlaku.

Pembaruan 10 Oktober 2026 mencakup katalog, sembilan template, pratinjau, cetak, PDF, dan Excel. Data, perhitungan, parameter URL, akses transaksi, dan skema database tetap mengikuti sistem yang ada. Surat ke Kepala Sekolah tetap memakai halaman dan alur khusus Surat Laporan.

## Menggunakan laporan

1. Buka **Laporan Global**, pilih **Unit Laporan** (SD, SMP, SMA, atau Semua Unit), lalu pilih kartu laporan. Super Admin, Admin, Kasir, dan Bendahara mempunyai pilihan cakupan baca ini; unit operasional tetap.
2. Atur kelompok **Filter Akademik/Periode**, **Filter Siswa**, **Filter Transaksi**, dan **Pengaturan Tampilan** yang tersedia. Kelompok tanpa kontrol tidak tampil. Filter periode per item tetap mengikuti kategori bulanan, tahunan, atau tanggal transaksi.
3. Pada dropdown checkbox, pilih satu atau beberapa opsi, lalu **Terapkan**. Tekan **Tampilkan Rekap** untuk menerapkan formulir. Kartu ringkasan di header mengikuti hasil filter yang sudah diterapkan, bukan pilihan sementara di dropdown.
4. Baca kategori, periode, identitas siswa, nominal, dan status. Pilihan **SPP + Komite** tetap menghasilkan dua bagian. Tiga kategori atau lebih juga tetap terpisah. Nomor baris dan pagination berlaku untuk gabungan hasil; sebuah halaman bisa memuat akhir kategori pertama dan awal kategori berikutnya. Subtotal tetap mengikuti seluruh hasil bagian, bukan hanya baris pada halaman itu.
5. Gunakan **Reset** untuk kembali ke filter awal pada unit laporan yang sama. Pencarian, pilihan siswa, dan pagination tetap memakai mekanisme sebelumnya.
6. Pilih **Cetak**, **PDF**, atau **Excel**, periksa pratinjau, lalu cetak/unduh. Ekspor mengambil seluruh hasil filter, termasuk halaman yang belum dibuka. Tabel lebar dapat digulir mendatar dalam area tabel.

Ringkasan kas tetap merangkum komponen, metode/jumlah transaksi, dan nilai kas. Matriks SPP serta Riwayat Tagihan tetap mempertahankan kolom dan makna sebelumnya. Catatan tanggal Uang PSB dan informasi rekonsiliasi tetap tersedia.

## Tampilan

Header memuat ikon, judul laporan, pemilih unit, dan maksimal empat kartu filter. Kontrol memakai tiga kolom desktop, dua tablet, dan satu ponsel, dengan tinggi 44px. Tombol formulir berada dalam alur halaman sehingga tidak menutupi topbar saat digulir. Dropdown memakai komponen checkbox aplikasi yang sama; pesan pencarian siswa kosong tidak menghalangi tombol formulir.

Tabel satu dan beberapa kategori memakai renderer sel yang sama: header berwarna unit, garis kolom, baris berselang, badge NIS/kelas/status, serta nominal rata kanan. Identitas Diknas dan rincian matriks dipertahankan. Tema terang/gelap dan empat palette tetap tersedia. Teks/tabel 14px, label/tombol 13px, keterangan 12px, judul bagian 18px, dan judul utama 26–28px.

Dokumen cetak tetap berlandaskan kertas putih. Bingkai pratinjau mengikuti tema dan unit, sedangkan PDF/Excel memakai warna unit, identitas sekolah, metadata, susunan bagian, kolom, nilai, tanda tangan, dan orientasi yang berlaku (termasuk A3 lanskap Riwayat Tagihan). Nilai Excel tetap berupa angka/tanggal/teks bertipe, bukan HTML badge.

## Batas implementasi

- CSS baru hanya dimuat pada katalog dan sembilan halaman Global melalui `global-report-workspace`.
- `global_report_ui.php` menyatukan ikon, ringkasan filter terapan, dan tampilan sel. `report_multiple.php` hanya berubah pada renderer bagian; pembentukan/query laporan tetap.
- Helper Excel dan bingkai pratinjau memakai opsi `presentation=global`. Ekspor Global mengaktifkannya; modul lain dan Surat Kepala Sekolah tetap memakai tampilan sebelumnya.
- Tidak ada migrasi database, parameter/API baru, perubahan rumus, atau perubahan hak transaksi.

## Verifikasi

Semua pengujian database dijalankan pada clone audit terpisah. Artefak, baseline, dan sesi uji disimpan di direktori privat di luar repositori.

| Pemeriksaan | Hasil |
| --- | --- |
| Pembanding sebelum/sesudah | 56 kombinasi laporan/filter/unit identik: baris, urutan, kolom, nilai, subtotal, total, dan model Excel |
| Excel langsung | 56 workbook ditulis/dibaca kembali; seluruh sel bertipe, tanggal, bagian, dan subtotal cocok |
| Excel endpoint | 48 unduhan (9 template + 3 pilihan multi per cakupan) dibaca kembali; identitas sekolah, nilai, subtotal, dan warna header cocok |
| PDF | 53 dokumen / 127 halaman dibaca kembali; termasuk laporan penuh beberapa kategori dan matriks SPP. Urutan siswa, status, nominal, total, unit, orientasi, dan batas halaman sesuai |
| Pratinjau/cetak | 144 respons pratinjau PDF, cetak, dan Excel diperiksa; isi frame serta badge diperiksa di browser |
| Browser | 240 keadaan katalog/9 template × 4 cakupan × 2 tema × 3 ukuran layar (1600/900/390px); tanpa overflow halaman atau error JavaScript |
| Interaksi | Satu/dua/tiga kategori, beberapa tahun/bulan/kelas, batas pagination dua bagian, hasil kosong, pencarian/reset, periode bulanan/tahunan/transaksi, keyboard dan dropdown dalam viewport lulus |
| Akses/cakupan | Model empat peran dan 243 permintaan HTTP lulus; sesi operasional serta fingerprint seluruh tabel tetap |
| Regresi periode | Daftar Ulang per tahun, kondisi kontrol per item, SPP belum terbit/dibatalkan pada tiga unit lulus |
| Sintaks/dokumentasi | PHP/JavaScript serta whitespace diperiksa; manual HTML dan PDF 35 halaman diperbarui/dibaca kembali |

Cetak fisik pada printer belum dilakukan; verifikasi cetak menggunakan dokumen dan pratinjau browser.

### Menjalankan ulang

Gunakan database demo **clone** dengan nama `db_spp_audit_*`, direktori privat `SPP_QA_DIR` dengan subdirektori `sessions`, `SPP_TEST_ALLOW_MUTATION=1`, dan server PHP loopback dengan `SPP_HTTP_BASE`. Server dan CLI sesi harus memakai `session.save_path` yang sama. Tes browser menggunakan `SPP_PLAYWRIGHT_CORE` dan Chrome; pembacaan PDF menggunakan PyMuPDF.

1. Siapkan sesi lewat `php -d session.save_path=<direktori>/sessions tests/global_report_redesign_sessions.php`. Skrip hanya tersedia melalui CLI pada clone yang tervalidasi; tidak mengubah akun/kata sandi.
2. `tests/global_report_redesign_test.php` membandingkan `baseline.json` privat dan membaca 56 workbook. Baseline implementasi ini diambil sebelum redesign. Untuk perubahan berikutnya, tangkap baseline sebelum mengedit dengan `SPP_GLOBAL_CAPTURE_BASELINE=1`; lepaskan flag sebelum pemeriksaan. `--models-only` memeriksa model tanpa menulis workbook.
3. Jalankan `node tests/global_report_redesign_browser_test.js`, `php tests/global_report_redesign_exports_test.php`, lalu `python tests/global_report_redesign_pdf_test.py` dengan `SPP_REDESIGN_PDF_HTTP=1` untuk laporan penuh.
4. Jalankan regresi `global_report_scope_model_test.php`, `global_report_scope_http_test.php --access-only` (variabel `SPP_TEST_BASE_URL` dan lokasi sesi sama), `report_du_per_item_year_test.php`, `report_per_item_period_ui_test.php komite`, `report_per_item_period_ui_test.php daftar_ulang`, dan `report_spp_unbilled_status_test.php` pada clone.

Tes browser memakai distribusi demo audit (144 siswa SD) untuk memeriksa perpindahan kategori pada halaman keenam. Sesuaikan fixture bila menjalankan terhadap clone dengan distribusi berbeda. Jangan menaruh baseline, workbook, PDF hasil data, atau sesi QA di repositori. Setelah selesai, hapus sesi QA dan hentikan server loopback.
