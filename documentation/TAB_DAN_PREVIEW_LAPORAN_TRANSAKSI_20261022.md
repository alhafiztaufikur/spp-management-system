# Tab desktop dan preview Laporan Transaksi

Tanggal: 22 Oktober 2026 (WIB). Pembaruan ini menggantikan keputusan menghapus tab/rekap komponen pada [pekerjaan sebelumnya](FOKUS_LAPORAN_TRANSAKSI_20261022.md). Pembaca pembayaran, empat kartu ringkasan, filter, tindakan transaksi dan desain dokumen PDF tetap digunakan.

## Perilaku

- Desktop mulai 1100 px: **Ringkasan** menampilkan rekap komponen di kiri dan **Hasil Laporan: Semua Transaksi** di kanan. **Komponen Pembayaran** dan **Daftar Transaksi** masing-masing memakai seluruh area hasil. Ringkasan menjadi pilihan awal.
- Parameter GET `panel=ringkasan|komponen|transaksi` hanya memilih tampilan; nilai asing/array kembali ke Ringkasan. Tautan GET tetap berfungsi tanpa JavaScript. Dengan JavaScript, tab mendukung panah kiri/kanan, Home/End, ARIA, refresh dan Back/Forward.
- Filter, pergantian unit, pagination, Reset, dan tautan kembali ekspor mempertahankan tab. Reset menghapus filter periode/pencarian/checkbox. `jenis_laporan` tetap diabaikan; hanya fragment `semua` yang diterima. Tabel tunggakan tidak dikembalikan.
- Di bawah 1100 px, tab dan rekap komponen disembunyikan; tabel transaksi tetap terlihat meskipun URL membawa `panel=komponen`.

## Rekap komponen dan nominal

Rekap mencakup **semua pembayaran hasil filter**, bukan halaman aktif. Pembayaran native dan Legacy dengan kategori yang sama, misalnya `spp`, dijumlahkan menjadi satu baris **SPP** tanpa akhiran sumber. Biaya dengan master berbeda tetap terpisah walaupun namanya sama. Master dapat dibuktikan dari detail pembayaran atau relasi tagihannya; tanpa master dipakai kunci sumber yang tersimpan. Item tanpa keduanya tetap terpisah per detail, bukan digabung berdasarkan nama. Label ganda memperoleh keterangan unit singkat.

Agregat dan rincian ekspor menggunakan proyeksi item numerik yang sama di `includes/transaction_report.php`. Pembayaran dipilih melalui ID transaksi dan unit; rincian dibaca dengan pencarian berindeks dari pembayaran terpilih, bukan pencocokan nama/NIS lintas unit. Rincian ekspor dibatasi 500 pembayaran per kelompok. Tidak ada query per pembayaran.

Sebagian kolom native, termasuk `U_SPP` dan `potong_spp`, masih bertipe `DOUBLE`. Proyeksi mengubah **setiap item** menjadi `DECIMAL(20,2)` sebelum penjumlahan, kemudian mengubah hasil DECIMAL ke sen integer. Potongan tetap negatif. Skema dan nilai database tidak diubah. Kartu, tabel transaksi dan agregat komponen halaman dibaca dalam snapshot konsisten yang hanya membaca data.

## Preview dan PDF

- Endpoint PDF umum menerima `output=html`. Pemeriksaan sesi, peran, unit, filter, serta model pembayaran sama dengan PDF/Excel. Respons HTML tidak menjalankan Dompdf dan tidak disimpan dalam cache browser.
- Preview desktop default berpindah ke HTML melalui `replace`, menampilkan tabel langsung seperti preview Excel, selebar layar dengan gulir halaman. Viewer PDF tertanam dan thumbnail tidak dimuat pada desktop. Preview PDF ukuran lebih kecil mempertahankan alur existing.
- Setelah iframe HTML selesai tampil dan mendapat kesempatan dilukis browser, satu permintaan latar belakang menyiapkan PDF. Tampilan tabel tetap dapat dibaca jika pembuatan PDF gagal; tersedia **Coba Lagi**.
- **Cetak** membuka file PDF server; **Download PDF** mengunduh file tersebut. Keduanya memvalidasi sumber terbaru. Jika klik terjadi selama preload lama berlangsung, tindakan menunggu lalu memvalidasi ulang. Klik ganda tidak menghasilkan dua unduhan.
- Header `X-Report-Model` mencocokkan model HTML dengan PDF. Bila sumber berubah, preview diperbarui sebelum file baru diberikan; perubahan berikutnya saat proses berlangsung meminta pengguna mencoba lagi. Isi lama dari preload tidak dipakai untuk melewati pemeriksaan akses/data.
- Cache privat existing mempertahankan isolasi pengguna/peran/database/unit, kunci model, TTL, validasi integritas dan penguncian render. `panel` tidak membedakan cache PDF/XLSX. Tidak ada API bisnis, tabel, dependensi atau layanan baru.
- PDF yang diunduh mempertahankan renderer, desain, kolom, font, A4 lanskap, penomoran dan pemrosesan bertahap existing. Excel tetap satu lembar **Transaksi Pembayaran**. **Cetak Dipilih** tetap bukti transaksi.

## Bukti pengujian

Pengujian menggunakan instance MySQL milik QA pada port **58470**, UUID `a5d985cc-cccd-11f1-a8d3-0a0027000012`, dan HTTP loopback **58471**. Baseline berisi 118.244 pembayaran migrasi serta sembilan pembayaran native QA. Semua mutasi memakai clone; perubahan satu sen pada fixture cache dikembalikan, sedangkan fixture master bernama sama di-rollback.

| Pemeriksaan | Hasil |
| --- | --- |
| Model pembayaran existing: sumber independen, nominal sen, empat unit, periode, identitas dan snapshot ekspor | 3.639 lulus |
| Oracle item langsung dari arsip/native, kategori gabungan, potongan, nama sama/master berbeda, serta seluruh 118.253 pembayaran | 8.466 lulus |
| HTTP dan pembacaan ulang PDF/XLSX pada empat unit, bertanggal/tanpa tanggal/kosong, semua periode, URL lama dan Cetak Dipilih | 36.016 lulus |
| Preview HTML dibanding baseline ekspor: identitas, urutan, nominal dan seluruh teks rincian komponen; cache lintas tab | 9.379 lulus |
| Chrome dan Edge: tab, tiga lebar desktop, tema, empat palet unit, keyboard, riwayat, filter/unit, tanpa JavaScript, HTML sebelum PDF, unduh/cetak, perubahan saat/setelah preload, klik ganda dan retry | Chrome 134 dan Edge 133 pemeriksaan lulus, tanpa kesalahan JavaScript |
| Regresi browser panel rombel dan laporan existing, termasuk tujuh ukuran viewport | 203 lulus |
| Cache privat: integritas, kedaluwarsa, isolasi, fallback dan lima proses bersamaan | 20 lulus; lima proses menghasilkan satu render |
| PDF kecil/besar dibaca kembali dan dirender Poppler | 46 baris/5 halaman dan 1.067 baris/127 halaman; seluruh nomor, identitas dan total cocok |
| PHP lint, sintaks JavaScript dan pemeriksaan patch | Lulus |

Artefak, sesi dan data identitas pengujian disimpan privat di luar Git/webroot: `C:/laragon/backups/spp-management-system/transaction_tabs_20261022`. Tidak menyertakan data siswa dalam dokumentasi ini.

### Pengukuran

Pengukuran loopback lokal memisahkan waktu tampil tabel dari waktu pembuatan file. Untuk **240 transaksi SD, 1–21 Oktober 2026**, median tiga respons HTML adalah **0,223 detik**, PDF pertama dengan cache kosong **4,017 detik**, dan median tiga permintaan PDF berikutnya **0,251 detik** dengan cache HIT. Waktu PDF pertama didominasi Dompdf; desain dokumen tidak dikurangi untuk mempercepatnya.

Pada Semua Unit, respons HTML **484 baris** membutuhkan sekitar **0,280 detik**, sedangkan **1.067 baris** sekitar **0,512 detik**. Pengujian ekspor awal mencatat PDF dingin 1.067 baris sekitar **32,96 detik**; pengguna kini dapat membaca tabel selama file disiapkan. Agregasi komponen seluruh **118.253 pembayaran**, tanpa membuat ekspor, diukur terpisah sekitar **4,403 detik**.

Angka ini sampel lokal, bukan jaminan waktu produksi atau p95. Ekspor sekaligus seluruh 118 ribu pembayaran dan printer fisik belum diuji. PDF besar tetap memerlukan waktu untuk dibentuk. Pengujian browser memakai Chrome/Edge terpasang; mobile hanya diperiksa agar perilaku existing tetap berfungsi.

### Menjalankan ulang

Gunakan clone dan sesi privat yang dipatok, bukan database pengguna. Tetapkan `SPP_DB_*`, `SPP_TEST_ALLOW_MUTATION=1`, dan `SPP_GLOBAL_QA_DIR` secara eksplisit sebelum tes CLI terkait database.

- `tests/transaction_focus_model_test.php` dan `tests/transaction_tabs_model_test.php`: rekonsiliasi pembayaran/item dan agregat seluruh histori.
- `tests/transaction_focus_http_test.py <direktori-qa>` dan `tests/transaction_preview_http_test.py <direktori-qa>`: ekspor nyata, HTML dan pengukuran cache kosong/terisi.
- `tests/transaction_tabs_browser_test.js`: membutuhkan `SPP_PLAYWRIGHT_CORE`, `SPP_PHP`, dan opsional `SPP_BROWSER_EXECUTABLE`/`SPP_BROWSER_NAME`. Mutasi cache hanya melalui fixture clone yang diverifikasi.
- `tests/transaction_focus_pdf_layout_test.py <direktori-qa> --existing`: tetapkan `SPP_PDFTOTEXT` dan opsional `SPP_PDFTOPPM` untuk pemeriksaan layout.
- `tests/report_cache_test.php`: cache tanpa database; membutuhkan `SPP_QA_DIR` privat.

Konfigurasi lokal `koneksi.php`, data pengguna, layanan backup, migrasi dan perubahan lokal sebelumnya dipertahankan. Sidik seluruh **62 tabel dan objek skema pengguna sebelum/sesudah identik**, dicatat pada bukti QA privat.
