# Ekspor XLSX dan dropdown SistemSPP

## Penggunaan

Tombol **Excel / Export Excel** membuka pratinjau. Pilih **Download Excel** untuk mengunduh `.xlsx` asli. Parameter dan cakupan unit tetap mengikuti laporan yang dibuka. Rincian tunggakan per rombel juga melewati pratinjau sebelum unduhan.

Dropdown **filter pencarian** mendukung beberapa centang. Buka kontrol, centang satu/beberapa opsi atau **Pilih Semua**, kemudian klik **Terapkan**. Klik **Tampilkan Rekap** untuk menjalankan pencarian laporan. Filter daftar yang bekerja langsung di halaman diperbarui setelah Terapkan.

**Pilih Semua** mencakup seluruh opsi aktif, termasuk opsi di luar area gulir yang sedang terlihat. Opsi nonaktif tidak dipilih. **Batal**, Escape, klik di luar, atau berpindah fokus membuang centang yang belum diterapkan. Tanpa pilihan, Terapkan dinonaktifkan. Tombol pemilih menampilkan dua nama pertama dan jumlah sisanya; seluruh opsi terpilih memakai label Semua yang sesuai. Ringkasan tertutup satu baris dengan elipsis dan nama lengkap pada tooltip/daftar.

Pemilih **unit, baris/halaman, urutan, mode tampilan, batas kalender, serta konteks penerbitan/edit dan isian akun/transaksi** tetap satu nilai dengan kotak penanda pilihan. Gunakan panah keyboard, Home/End, Enter/Space dan Escape. Pemilih unit operasional di sidebar memakai dropdown native seperti konsep awal dan tetap langsung berpindah unit.

Panel tidak mempunyai input pencarian internal. Catatan **Pilih satu opsi** atau **Bisa pilih beberapa opsi** tampil di atas daftar. Pencarian utama halaman/siswa tetap tersedia. Sidebar native menampilkan keterangan **Pilih satu unit**.

## Daftar ekspor

| Endpoint | Isi |
| --- | --- |
| `siswa/export_excel.php` | Data Siswa, termasuk NIS Diknas, kelas, tarif dan status |
| `laporan/export_excel.php` | Lembar Ringkasan, Pembayaran dan Tabungan |
| `laporan/export_global.php?template=status&format=excel` | Status Pembayaran, seluruh kategori yang tersedia |
| `laporan/export_global.php?template=penerimaan&format=excel` | Penerimaan Harian, semua kategori atau kategori tunggal |
| `laporan/export_global.php?template=spp-tahunan&format=excel` | SPP Tahun Ajaran per Kelas |
| `laporan/export_global.php?template=per-item&format=excel` | Pembayaran per Item, seluruh kategori yang tersedia |
| `laporan/export_global.php?template=tabungan-siswa&format=excel` | Transaksi Tabungan Siswa |
| `laporan/export_global.php?template=saldo-tabungan&format=excel` | Saldo Tabungan |
| `laporan/export_global.php?template=riwayat-tagihan&format=excel` | Matriks Tagihan Siswa per Komponen |
| `laporan/export_global.php?template=tunggakan-siswa&format=excel` | Rekap Tunggakan per Kelas/Rombel |
| `laporan/export_global.php?template=setoran&format=excel` | Komponen, metode, total setoran dan tanda tangan |
| `laporan/export_global.php?template=kas-tabungan&format=excel` | Arus tabungan, jumlah transaksi, total dan tanda tangan |
| `laporan/export_global.php?template=tunggakan-siswa&format=excel&view=detail&kelas=rombel:ID` | Rincian tunggakan siswa pada rombel terpilih |

Tambahkan `download=1` untuk unduhan langsung. URL dan parameter filter lain tetap berlaku. Akses mengikuti pemeriksaan sesi, peran dan unit pada endpoint asli.

## Susunan dokumen

- Generator bersama: `includes/excel.php`. Model dokumen yang sama digunakan pratinjau dan XLSX.
- Warna SD hijau, SMP biru, SMA merah, Semua Unit ungu. Logo sekolah, Calibri 11, header tengah, identitas kiri, nominal kanan, nomor/jumlah tengah, garis tipis dan baris berselang.
- Nominal berupa angka dengan format rupiah. Tanggal berupa serial tanggal Excel. Identitas serta teks pengguna disimpan sebagai teks eksplisit, termasuk nol awal dan awalan `=`, `+`, `-`, `@`.
- Tabel detail mempunyai filter, pembekuan header dan header cetak berulang, termasuk hasil kosong. Tabel lebar memakai landscape/A3. Tabel sangat lebar melintasi halaman horizontal dengan kolom identitas berulang agar tulisan tetap terbaca.
- Pemisah ribuan mengikuti pengaturan regional Excel. Pratinjau aplikasi memakai format Indonesia.
- Saat mencetak melalui Excel, pilih printer yang mendukung ukuran kertas dokumen. Pengujian PDF Excel menggunakan Microsoft Print to PDF; driver printer matriks dapat mengubah ukuran kertas serta hasil render font.

## Daftar pemilih

Komponen `assets/js/dropdowns.js` dan `assets/css/dropdowns.css` dimuat melalui sidebar. Inventaris sumber `<select>`:

| Bagian | Halaman/sumber |
| --- | --- |
| Unit | `includes/sidebar.php`, pemilih cakupan dari `includes/units.php` |
| Siswa | `siswa/daftar.php`, `siswa/aktivasi_legacy.php`, termasuk pemilih khusus Kelas/Rombel |
| Master | `master_kelas.php`, `master_spp.php`, `master_daftar_ulang.php`, `master_biaya_lain.php` |
| Pembayaran | `pembayaran/form.php`, `pembayaran/edit.php`, `pembayaran/lihat.php`, `pembayaran/riwayat_daftar_ulang.php` |
| Otorisasi | `otorisasi_transaksi.php` |
| Tabungan | `tabungan/riwayat.php`, `tabungan/cetak.php` |
| Laporan | `laporan/index.php`, `laporan/template.php`, `includes/principal_letter_page.php` |
| Surat Orang Tua | `laporan/surat_orang_tua.php` |
| Administrasi | `role_management.php`, `backup_restore.php` |

### Filter dengan pilihan ganda

| Menu | Filter pilihan ganda | Tetap satu konteks |
| --- | --- | --- |
| Data Siswa | Kelas, status siswa | Kelas dan tahun pada tambah/edit/aktivasi siswa |
| Master Kelas/Rombel | Tingkat/status pada daftar, rombel asal pada pencarian siswa promosi | Tahun/tingkat sumber promosi, rombel tujuan, tambah/edit kelas |
| Master Penerbitan SPP | Rombel pada pencarian daftar siswa | Tahun ajaran dan konteks penerbitan |
| Master Daftar Ulang/Biaya Lain | Tidak mempunyai dropdown pencarian daftar; pilihan siswa yang tersedia dipertahankan | Tahun, kelas dan komponen untuk menerbitkan/mengubah tagihan |
| Riwayat Pembayaran | Tidak mempunyai dropdown filter selain ukuran halaman | Ukuran halaman; semua input tambah/edit transaksi |
| Riwayat Daftar Ulang | Kelas, tahun ajaran, status | Ukuran halaman |
| Otorisasi Transaksi | Jenis perubahan, status dalam tab Antrean/Riwayat | Tab dan ukuran halaman; hak akses riwayat tetap berlaku |
| Tabungan | Kelas/status saldo pada daftar, kelas pada cetak | Ukuran halaman, input transaksi masuk/keluar |
| Laporan Umum | Jenis laporan | Urutan, ukuran halaman, batas tanggal |
| Laporan Global | Seluruh filter yang sudah tersedia: kategori, kelas, tahun ajaran, status, status siswa, operator, metode, mutasi, status saldo, komponen; bulan tagihan pada Status Pembayaran | Batas rentang bulan/tahun Rekap per Item, tanggal awal/akhir, ukuran halaman dan mode |
| Surat Orang Tua/Kepala Sekolah | Kelas dan status siswa | Pemilihan penerima dan penyusunan pesan tetap melalui alur surat |
| Role Management/Backup | Tidak mempunyai dropdown pencarian daftar | Semua isian akun, unit dan operasi backup/restore |

Kontrol ditandai secara eksplisit dengan `data-filter-multiple`; hanya filter tersebut yang diubah menjadi `multiple`. `data-filter-persist` digunakan pada filter daftar yang bekerja di browser. Kontrol native tetap menjadi sumber nilai dan fallback tanpa JavaScript.

### Kontrak filter dan bagian laporan

- Parameter baru berupa array, misalnya `kategori[]=spp&kategori[]=komite`, `kelas[]=tingkat:7`, `tahun_ajaran[]=2026/2027`, dan `bulan_awal[]=09`. `*` berarti semua opsi yang tersedia dalam unit. URL lama dengan satu nilai tetap diterima.
- Server menormalisasi dan menghapus duplikasi pilihan array, serta menolak array kosong, nilai tidak tersedia dan pilihan dari unit lain dengan HTTP 400 dan pesan Periksa filter. Nilai scalar lama memakai validasi kompatibilitas yang sudah tersedia.
- Pilihan dalam satu filter memakai OR; antarfilter memakai AND. Tingkat beserta rombel yang termasuk di dalamnya tidak menggandakan baris.
- Beberapa kategori/tahun menampilkan bagian terpisah, periode, header dan subtotal. SPP/Komite pada Status menggunakan kombinasi tahun/bulan; kategori tahunan memakai tahun ajaran. PSB dan biaya sekali bayar ditampilkan satu kali. Penerimaan Harian selalu memakai tanggal transaksi sehingga tahun ajaran tidak menggandakan penerimaan.
- Rekap per Item mempertahankan matriks rentang bulan, tabel tahunan dan tanggal transaksi. Terapkan kategori memperlihatkan semua kontrol periode yang dibutuhkan pilihan tersebut.
- Pagination menghitung keseluruhan baris bagian. Header ditampilkan pada setiap bagian yang masuk halaman; subtotal mencakup seluruh hasil bagian sesuai filter, bukan hanya baris halaman tersebut.
- Layar, tautan pagination, pratinjau, PDF, XLSX dan tautan kembali membawa array yang sama. Laporan Umum tetap menggunakan lembar Ringkasan, Pembayaran dan Tabungan. Ringkasan keuangan periode tetap tersedia bersama detail jenis yang dipilih.
- Implementasi bersama: `includes/filter_choices.php`, `includes/report_multiple.php`, `includes/general_multiple.php`, dan `includes/student_filters.php`. Tidak ada migrasi database.

Pemilih Tahun Tagihan, Daftar Ulang dan Biaya Lain menggunakan panel bersama sambil mempertahankan informasi khusus dan penanganan nilai aslinya. Pengelompokan kategori memisahkan Pembayaran Inti dan Biaya Lain. Tidak ada jumlah siswa buatan.

Kontrol dalam modal dan baris yang ditambahkan dinamis ikut ditangani. Panel menggunakan lapisan popover jika tersedia; posisi tetap dibatasi layar. Panel pada dialog berada di dalam dialog agar tetap bisa menerima fokus dan klik. Nilai otomatis, opsi berubah, reset, `required`, `disabled`, nama kontrol dan event `change` tetap berfungsi. Tanpa JavaScript, kontrol asli tetap tersedia.

Kontrol `multiple` tersembunyi yang menjadi penyimpanan pilihan siswa pada Master Biaya Lain bukan dropdown pengguna; daftar pilihan siswa yang sudah ada tetap dipertahankan.

## Dependensi

Jalankan `composer install` setelah mengambil perubahan. `composer.lock` mengunci PhpSpreadsheet **1.30.7**, patch keamanan dalam keluarga 1.30. Batas platform proyek tetap PHP 8.0.30. Persyaratan PHP pustaka dapat dilihat pada [manifest resmi](https://raw.githubusercontent.com/PHPOffice/PhpSpreadsheet/1.30.7/composer.json).

Jika pustaka belum terpasang, endpoint unduhan menjawab HTTP 503 dengan petunjuk menjalankan Composer. Berkas XLSX diselesaikan dahulu sebelum header unduhan dikirim.

## Pengujian

Semua pengujian HTTP menggunakan server loopback yang diarahkan ke salinan `db_spp_audit_*`. Jangan menjalankan fixture mutasi pada database aktif. Berkas contoh, PDF Excel dan tangkapan layar disimpan di folder sementara di luar web/Git.

| Pemeriksaan | Skrip |
| --- | --- |
| Tipe sel, nol awal, rumus literal, nilai negatif/nol, status dua baris, logo, warna, filter/freeze | `tests/excel_document_test.php` |
| Unduhan 12 ekspor × 4 cakupan, pratinjau, halaman dropdown, keyboard, tiga ukuran dan dua tema | `tests/excel_dropdown_browser_test.js` |
| Semua baris/kolom/total dari 10 laporan × 4 cakupan; lembar dan total Laporan Umum | `tests/excel_reports_readback_test.php` |
| Semua kategori tersedia, hasil kosong, rincian tunggakan per rombel | `tests/excel_export_matrix_http_test.php` |
| Kelas/Tahun/Daftar Ulang, tambah/hapus baris, nilai otomatis, reset, disabled, lunas, edit dan dialog | `tests/dropdown_dynamic_browser_test.js` |
| Rekonsiliasi Laporan Global dengan layar/Excel/PDF | `tests/all_units_exports_http_test.php --reports-only` |
| Nama siswa menyerupai rumus pada endpoint nyata | `tests/student_excel_formula_guard_test.php` |
| Beberapa kategori/tahun/bulan, OR/AND, URL lama, batas unit, layar/preview/PDF/XLSX dan readback | `tests/multiple_filters_http_test.php` |
| Checkbox, Pilih Semua, Terapkan/Batal, Escape/klik luar, kontrol dinamis, fallback native, empat cakupan dan dua tema | `tests/multiple_dropdowns_browser_test.js` |
| Identitas hasil dibandingkan SQL sumber, semua halaman, OR/AND, nominal/periode Daftar Ulang dan saldo | `tests/dropdown_filter_results_http_test.php` |
| Seluruh panel tanpa pencarian, catatan mode, posisi/lebar, enam kombinasi ukuran/tema, hasil saldo dan parameter berindeks | `tests/dropdown_audit_browser_test.js` |
| Integritas keuangan baca saja | `tests/readiness_integrity_audit.php` |

Variabel pengujian: `SPP_DB_NAME`, `SPP_HTTP_BASE` (PHP), `SPP_TEST_BASE_URL` (browser), `SPP_TEST_ALLOW_MUTATION=1` untuk pengaman salinan, `SPP_XLSX_TEST_OUTPUT`, serta lokasi `SPP_PLAYWRIGHT_CORE` dan `SPP_TEST_ADMIN_PASSWORD_FILE`. `SPP_PDFTOTEXT` diperlukan untuk rekonsiliasi PDF. `SPP_XLSX_UNITS` dapat membatasi matriks kategori saat melanjutkan pengujian. Jangan menaruh kredensial pengujian di repositori.

Tidak ada migrasi skema, perubahan data keuangan aktif, commit atau push pada pekerjaan ini.

### Hasil pemeriksaan 6 Oktober 2026

- 184 kasus ekspor kategori/hasil kosong/rincian rombel lulus: SD 55, SMP 31, SMA 31, Semua Unit 67.
- 48 unduhan melalui browser lulus. Isi 10 laporan pada empat cakupan dibaca kembali dan cocok dengan sumber, termasuk tipe sel dan total; Laporan Umum mempunyai tiga lembar yang benar.
- Dropdown diperiksa pada desktop, tablet, ponsel, kedua tema, keyboard, dialog, kalender, tambah/edit pembayaran, pilihan nonaktif dan reset. Tidak ditemukan error JavaScript pada cakupan pengujian tersebut.
- Sepuluh Laporan Global cocok antara sumber, layar, XLSX dan PDF. Contoh XLSX dibuka serta dirender melalui Microsoft Excel; tabel kas, tabel tahunan lebar, teks panjang, nol awal, nominal negatif dan tanggal diperiksa secara visual.
- Sintaks PHP/JavaScript dan validasi Composer lulus. Audit langsung API Packagist atas 15 paket terkunci tidak menemukan versi terdampak advisori keamanan.
- Audit baca saja `db_spp` lulus; jumlah pembayaran 1.021, total kas Rp577.545.000 dan saldo tabungan Rp1.150.000 tetap sama dengan sebelum pengujian.

### Pengujian lanjutan pilihan ganda

- 144 kasus HTTP laporan lulus pada SD, SMP, SMA dan Semua Unit. Pemeriksaan tambahan mencakup filter operator/metode, URL lama, input tidak sah, kelas lintas unit dan tingkat/rombel yang tumpang tindih.
- XLSX dibaca kembali untuk mencocokkan seluruh bagian, header, baris, nilai angka/tanggal dan subtotal dengan model hasil filter. PDF bagian gabungan dan Laporan Umum berhasil dibuat, termasuk hasil kosong.
- Pengujian browser seluruh menu dan 10 Laporan Global lulus pada empat cakupan. Desktop/tablet/ponsel, mode terang/gelap, Pilih Semua saat pencarian, indeterminate, Terapkan/Batal, Escape/klik luar dan fallback tanpa JavaScript diperiksa.
- Pengujian dilakukan pada salinan database; tidak ada perubahan data keuangan aktif, migrasi, commit atau push.

### Audit dropdown 7 Oktober 2026

- Pencarian internal dihapus dari seluruh komponen native yang diperindah dan pemilih khusus. Pencarian halaman, siswa, dan penerima surat tetap tersedia.
- Catatan jenis pilihan berada di dalam panel. Ringkasan tertutup satu baris; nama lengkap tersedia di daftar dan tooltip. Label Semua memakai nama yang terbaca, bukan nama parameter.
- Panel Kelas/Rombel memakai koordinat viewport dengan prioritas terhadap CSS lama; fokus dan perubahan posisi tombol tidak lagi membuat panel terlepas. Tata letak Data Siswa, Riwayat Daftar Ulang, Master Kelas/Rombel, dan Riwayat Tabungan memakai pembungkus kontrol sebagai sel kisi.
- 37 kasus membandingkan identitas hasil dengan SQL sumber, seluruh pagination, OR/AND, pilihan tumpang tindih, nominal/periode Daftar Ulang, dan sumber saldo. Parameter tidak sah serta kelas lintas unit ditolak.
- 144 kasus HTTP laporan lulus, termasuk pratinjau, PDF, dan pembacaan kembali XLSX. Pengujian checkbox, Terapkan/Batal, Pilih Semua, Escape/klik luar, reset, pilihan dinamis, dan fallback native lulus.
- Seluruh halaman pada inventaris dan sepuluh Laporan Global diperiksa pada empat cakupan unit. Empat halaman prioritas diperiksa pada 1440/768/390 piksel dalam mode terang/gelap; hasil saldo dibandingkan SQL sumber sampai seluruh halaman, termasuk reload/reset dan pembersihan parameter array berindeks.
- Pemilih Kelas/Tahun/Daftar Ulang/Biaya Lain, aktivasi Legacy, tarif kelas otomatis, tambah/hapus baris, pembayaran edit, kalender, dan modal lulus pengujian tanpa menyimpan transaksi.
- Sintaks 40 berkas PHP dan 6 berkas JavaScript lulus. Seluruh 30 pemeriksaan integritas lulus pada salinan dan database aktif. Pada salinan, 1.020 pembayaran, total kas Rp577.445.000, dan saldo tabungan Rp1.150.000 tetap sama sebelum/sesudah pengujian. Database aktif hanya diperiksa secara baca saja.
- Tidak ada migrasi, commit, push, atau perubahan data keuangan aktif.

Audit browser dapat dilanjutkan per unit melalui `SPP_DROPDOWN_AUDIT_UNITS=1,2,3,0`. `SPP_DROPDOWN_SKIP_INVENTORY=1` menjalankan ulang pemeriksaan tata letak dan hasil saldo jika inventaris telah lulus. Jalankan pemeriksaan HTTP sumber terlebih dahulu untuk menghasilkan fixture identitas di folder keluaran luar repositori.
