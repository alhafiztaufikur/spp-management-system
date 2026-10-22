# Fokus Laporan Transaksi dan panel Kelas/Rombel

Tanggal: 22 Oktober 2026 (WIB). Perubahan ini menggantikan konsep lima bagian Laporan Transaksi pada audit menu sebelumnya. Laporan kewajiban dan saldo tetap tersedia di Laporan Global.

Pembaruan berikutnya mengembalikan tiga tab **tampilan** dan rekap komponen pada desktop, serta memakai preview HTML sebelum PDF. Keputusan terbaru dan pengujiannya dicatat dalam [Tab desktop dan preview Laporan Transaksi](TAB_DAN_PREVIEW_LAPORAN_TRANSAKSI_20261022.md). Batas pembayaran saja, empat kartu dan proteksi Kelas/Rombel pada pekerjaan ini tetap berlaku.

## Perilaku halaman

- Kelas Khusus dan direktori Kelompok Legacy berada di panel Kelola Rombel. Keduanya, beserta pintasan tambah kelas khusus, tersembunyi saat Naik Kelas aktif.
- Anchor tujuan menentukan panel, termasuk anchor form/anggota/konfirmasi yang berada di dalam panel. Parameter `special_*`/`lg_*` juga memilih Kelola Rombel ketika tidak ada anchor panel. Anchor eksplisit Naik Kelas tetap didahulukan.
- Hapus penjelasan panjang kelulusan dari kartu konfirmasi samping. Ringkasan tujuan Alumni, konfirmasi, histori, dan layanan kelulusan tidak diubah.
- Laporan Transaksi berisi satu tabel selebar hasil: **Hasil Laporan: Semua Transaksi**. Empat kartu ringkasan tetap mengikuti periode, siswa dan unit, termasuk dua ringkasan tabungan.
- Hapus pemilih jenis laporan, tiga tab tampilan, rekap komponen di samping, dan semua tabel tunggakan. Halaman tidak membaca rincian tabungan, saldo tabungan, atau model tunggakan.
- Filter utama memakai empat kolom mulai 1100 px, dua kolom 700–1099 px, dan satu kolom di bawah 700 px. Pencarian, opsi periode, dan tindakan memakai seluruh lebar yang tersedia.
- Kartu tautan Laporan Global hanya berubah pada **warna border** menjadi aksen unit. Dimensi, ketebalan, bentuk, latar dan bayangan dipertahankan.

## Model, kompatibilitas URL, dan ekspor

`includes/transaction_report.php` mematok jenis pembayaran `semua` dan memakai pembaca native/Legacy existing. Layar, fragment, PDF dan Excel menggunakan model tersebut. Nilai kartu pembayaran berasal dari total/jumlah model yang sama; kartu tabungan hanya memakai agregat existing.

`jenis_laporan` scalar/array dan `panel` diabaikan sebelum pembuatan model maupun tautan ekspor. Urutan `sisa_terbesar`, termasuk input urutan yang tidak dikenal, kembali ke `terbaru`. Tautan baru tidak mengirim pemilih lama. `section_page[semua]` tetap didukung; fragment bagian lain mengembalikan HTTP 400. Pagination GET tetap bekerja tanpa JavaScript, sedangkan peningkatan JavaScript mempertahankan Back/Forward dan filter.

PDF umum adalah tabel pembayaran seluruh hasil filter dengan identitas, unit, kelas transaksi, periode, metode, waktu, rincian komponen dan total. Di atas 600 baris, renderer existing memproses HTML dalam kelompok 80 baris pada canvas PDF yang sama. Judul lembar tidak diduplikasi bila sama dengan judul bagian tunggal.

Lebar nomor urut mengikuti jumlah digit hasil penuh dan tetap sama antarfragmen. Kolom identitas/unit diberi ruang dan aturan tanpa pemisahan kata agar nomor empat digit, NIS, dan label SMA tetap utuh. Header memisahkan baris pada spasi. Pemeriksaan PDF akhir mencocokkan seluruh nomor 1–1.067, identitas pembayaran dan total terhadap model sumber.

Excel hanya berisi satu lembar **Transaksi Pembayaran**, dengan nominal bertipe angka dan total pembayaran. Komponen native dibaca dari snapshot transaksi/detail; komponen Legacy dari arsip yang terkait dengan ID pembayaran/unit. Pembacaan dilakukan berkelompok hingga 500 pembayaran, tanpa query per siswa/baris. Periode sumber yang tersimpan tetap ditampilkan ketika kolom bulan native kosong, tanpa merekonstruksi periode baru.

`mode=selected` pada PDF mempertahankan alur bukti transaksi existing. Tombol Cetak Dipilih dan cetak per baris tetap menjalankan pemeriksaan akses existing; perubahan ekspor umum tidak mengubahnya menjadi kumpulan struk atau memperluas hak cetak.

## Pengujian

Semua pengujian mutatif memakai instance MySQL terisolasi `db_spp_audit_global_20261021` pada port 58470 dan server HTTP loopback 58471. UUID/datadir berbeda dari operasional. Sesi, model dengan identitas siswa, PDF/XLSX, screenshot dan log disimpan privat di luar Git/webroot pada `C:/laragon/backups/spp-management-system/transaction_focus_20261022`.

Oracle model membaca langsung tabel sumber dengan pencocokan periode tersendiri dan membandingkan nominal dalam sen. Baseline berisi 118.244 pembayaran; ditambah sembilan pembayaran native QA pada tiga siswa dengan NIS sama antarunit. Fixture native memuat pecahan rupiah, snapshot PSB/SPP/Komite/DU/Biaya Lain/potongan, dan tanggal Oktober–November. Fixture tidak ditempatkan pada database pengguna.

| Pemeriksaan | Hasil |
| --- | --- |
| Model pembayaran, empat unit, periode bertanggal/tanpa tanggal/lampau/kosong/semua periode, batas halaman, urutan, identitas antarunit, komponen dan periode sumber | 3.639 pemeriksaan lulus |
| HTTP, URL lama scalar/array, fragment terlarang, halaman kedua, pencarian siswa, urutan, PDF dan XLSX dibaca kembali | 35.976 pemeriksaan lulus |
| Semua periode dengan filter siswa/NIS lintas Oktober–November, termasuk ekspor dan kartu tabungan nol | 31 pemeriksaan lulus; bukti `http-all-periods-result.json` |
| Chrome, empat palet unit, dua tema, tujuh lebar viewport, Back/Forward, keyboard tab, refresh, anchor, pagination kelas, tanpa JavaScript dan pembersihan URL navigasi | 203 pemeriksaan lulus, tanpa kesalahan skrip |
| Edge, matriks tampilan/navigasi yang sama serta pintasan tambah tersembunyi pada Naik Kelas | 203 pemeriksaan lulus, tanpa kesalahan skrip |
| Tambah/edit/hapus kelas khusus dengan parameter kenaikan kelas tersisa, redirect, anchor konfirmasi dan pagination anggota | Lulus; kelas QA yang dibuat kembali dihapus |
| Regresi pembaca laporan/koreksi Legacy existing pada clone | 164 pemeriksaan lulus; mutasi layanan di-rollback |
| Regresi presisi Excel existing | Lulus: angka sen dan format dua desimal dibaca kembali |
| PHP lint, pemeriksaan sintaks JavaScript dan `git diff --check` | Lulus |
| Data dan skema pengguna | Sidik 62 tabel dan seluruh objek skema sebelum/sesudah identik; `koneksi.php` tidak berubah |

Rentang 1–22 Oktober 2026 menghasilkan jumlah berikut setelah penambahan fixture QA:

| Cakupan | Bertanggal | Sertakan tanpa tanggal sesuai periode | Hasil kosong |
| --- | ---: | ---: | ---: |
| SD | 242 | 563 | 0 |
| SMP | 196 | 390 | 0 |
| SMA | 46 | 114 | 0 |
| Semua Unit | 484 | 1.067 | 0 |

Jumlah dan nominal Semua Unit sama dengan penjumlahan tiga unit. PDF bertahap diuji pada 1.067 baris; seluruh identitas/total dibaca kembali. Semua halaman contoh PDF SMA serta halaman awal/tengah/akhir laporan besar diperiksa melalui render Poppler. Excel diperiksa dengan pembaca workbook terpisah. Ekspor penuh 118 ribu pembayaran sekaligus dan cetak fisik printer belum menjadi pengujian ini.

Tes yang dapat diulang:

- `tests/transaction_focus_model_test.php`: oracle sumber dan fixture native pada clone yang dipatok.
- `tests/transaction_focus_http_test.py <direktori-qa>`: layar dan pembacaan ulang PDF/XLSX; opsi `--all-periods-only` menjalankan tambahan kasus Semua Periode saja.
- `tests/transaction_focus_pdf_layout_test.py <direktori-qa>`: identitas dan nomor urut utuh pada PDF kecil/besar. Tetapkan `SPP_PDFTOTEXT` dan opsional `SPP_PDFTOPPM`; `--existing` memeriksa unduhan sebelumnya.
- `tests/transaction_focus_browser_test.js`: `SPP_GLOBAL_QA_DIR`, `SPP_PLAYWRIGHT_CORE`, dan opsional `SPP_BROWSER_EXECUTABLE`/`SPP_BROWSER_NAME`.
- `tests/transaction_focus_master_actions_test.js`: CRUD dan redirect kelas khusus di clone; membutuhkan endpoint identitas pengujian yang hanya tersedia pada server dengan opt-in isolasi.

Sebelum menjalankan tes CLI mutatif, tetapkan `SPP_TEST_ALLOW_MUTATION=1` dan seluruh `SPP_DB_*` secara eksplisit ke clone. Fixture/sesi HTTP menggunakan direktori privat yang sama. Jangan mengarahkan pengujian ke database pengguna. Browser yang digunakan adalah Chrome dan Edge terpasang; browser versi lama dan printer fisik tidak diuji.

## Rekomendasi backup, tanpa implementasi

Pencatatan perubahan setiap transaksi memungkinkan melalui binary log yang sudah aktif pada MySQL lokal. Rekomendasi lanjutan adalah **backup penuh terjadwal ditambah pengarsipan binary log berkelanjutan ke penyimpanan terpisah**. Pemulihan memuat backup dasar dan menerapkan perubahan setelah titik awalnya. Mekanisme ini dijelaskan pada [panduan pemulihan MySQL ke titik waktu tertentu](https://dev.mysql.com/doc/refman/8.4/en/point-in-time-recovery-binlog.html).

Implementasi berikutnya perlu memasangkan posisi log dengan snapshot backup dasar, menjaga retensi seluruh rantai pemulihan, memantau kegagalan pengarsipan dan melakukan latihan restore. Backup terverifikasi sebelumnya tetap disimpan. Binary log pada disk server yang sama belum menjadi cadangan terpisah; potensi kehilangan data mengikuti keterlambatan pengarsipan. Pekerjaan ini tidak memasang pengarsip, mengubah konfigurasi/jadwal, atau mengklaim backup existing sudah siap untuk pemulihan tersebut.
