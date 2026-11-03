# Pagination dan riwayat desktop — 3 November 2026

## Perubahan

- Pagination desktop ≥1024 px memakai Sebelumnya/Selanjutnya, panah, tombol 136 × 44 px, radius 12 px, dan jarak 10 px. Halaman awal/akhir menonaktifkan arah yang tidak tersedia; hasil kosong/satu halaman tidak mempunyai pagination. Pagination daftar biasa, laporan parsial, otorisasi, kelas khusus, Legacy, dan daftar peserta JavaScript memakai perlakuan yang sama. Navigasi detail transaksi dan tahapan formulir tetap terpisah.
- Pagination ganda dan keterangan rentang transaksi pada header Riwayat Pembayaran dihapus. Label jumlah hasil pada daftar DU dihapus. Ringkasan rombel dan empat kalimat bantuan yang diminta pada Master Kelas dihapus.
- Kedua riwayat memakai pencarian identitas terbatas, maksimum 20 pilihan, debounce 250 ms, pembatalan request lama, pemeriksaan unit/role, dan fallback pencarian GET. Pilihan mencakup identitas Legacy non-siswa.
- DU tidak membangun kembali seluruh sumber untuk combobox. Rincian pembayaran dibaca untuk pemilik terpilih sebelum ekstraksi slot DU; waktu kas tetap melalui pembaca bukti waktu existing. Bukti tarif diindeks sekali per sumber/tahun/tingkat dalam request. Bukti kebijakan PSB dibaca untuk kandidat kelas awal menggunakan cache request dengan kunci subset tersendiri; pemanggil penulisan tetap mendapatkan model penuh secara default.
- Riwayat Tagihan memakai sisa setelah kredit native/Legacy, urutan PSB–Daftar Ulang–SPP–Komite, dan default tahun ajaran berjalan. Bulan setelah bulan berjalan tidak dihitung sebagai tunggakan bulanan. Filter tanggal pembuatan tetap terpisah dari periode kewajiban.
- Layar, cetak/PDF, dan Excel memakai proyeksi yang sama: unit → tahun ajaran → rombel → siswa; keterangan bulan menunggak; subtotal rombel seluruh hasil filter; total keseluruhan. Siswa lunas tetap ada. Pembatalan/cakupan PSB dan nilai belum diketahui tidak dinyatakan sebagai pembayaran lunas buatan.
- PSB sekali masuk ditempatkan sekali pada penempatan tahun pilihan, atau penempatan terbaru yang tersedia ketika semua tahun dipilih. PSB tanpa penempatan yang terbukti tetap di kelompok tahun/rombel belum tercatat. Ini konteks penyajian, bukan perubahan tahun kewajiban sumber.
- Panel hijau, label unit pada judul dokumen, deskripsi filter panjang dan catatan tanggal PSB dihapus dari keluaran terkait. Alamat memakai 14 px di pratinjau dan 10 pt pada PDF. PDF besar tetap memakai renderer fragmen existing; subtotal/grand total tidak digandakan antarfragmen.

## Pengukuran pada salinan data

Backup sumber dipulihkan pada MySQL terpisah port **58660**, UUID/datadir berbeda, event scheduler OFF. Semua **65 checksum tabel** hasil restore sama dengan sumber dan tetap sama sesudah seluruh pengujian. Tidak memasang indeks/skema dan tidak menerbitkan tagihan. `.gitignore` dan perubahan lokal sebelum pekerjaan dipertahankan.

HTTP berikut memakai 20 sampel setelah satu request pemanasan per skenario, unit SD, data dan mesin yang sama. Angka ini hasil lokal, bukan jaminan waktu pada mesin lain.

| Halaman | Median sebelum | Median sesudah | P95 sebelum | P95 sesudah | HTML sebelum → sesudah |
|---|---:|---:|---:|---:|---:|
| Riwayat Pembayaran, default | 207 ms | 194 ms | 237 ms | 214 ms | 142.233 → 43.227 byte |
| Riwayat Daftar Ulang, default | 2.664 ms | 1.435 ms | 2.944 ms | 1.578 ms | 179.247 → 49.870 byte |

Pengamatan awal sebelum pemanasan: Pembayaran 3,81 detik; DU SD 6,34 detik; DU semua unit 12,46 detik. Pengukuran tambahan akhir: Pembayaran semua periode 0,61 detik; DU semua unit **3,23 detik**. Cakupan semua unit masih lebih mahal daripada satu unit; target p95 <2 detik terbukti untuk skenario default SD yang diuji.

Satu pembandingan browser desktop sebelum/sesudah:

| Halaman | DOM siap sebelum → sesudah | SQL sebelum → sesudah | Durasi SQL sebelum → sesudah |
|---|---:|---:|---:|
| Pembayaran | 1.181 → 504 ms | 79 → 75 | 385 → 277 ms |
| DU | 3.271 → 1.800 ms | 112 → 112 | 2.165 → 1.002 ms |

Jumlah query DU tetap, tetapi pembacaan sumber dan pemrosesan bukti lebih kecil. Sampel browser berbeda dari 20 sampel HTTP di atas.

## Verifikasi

- `tests/billing_history_presentation_test.php`: periode, kredit/sisa, nilai belum diketahui, PSB sekali masuk, pembatalan, tahun/rombel, kesamaan rangkuman, fragmentasi subtotal, URL pagination dan perlindungan tombol Legacy biasa.
- `tests/billing_history_grouping_test.php`: regresi pengelompokan existing tetap lulus.
- `tests/history_read_projection_parity_test.php`: 27 pemeriksaan baca saja pada salinan. Halaman DU, ringkasan, urutan/waktu rincian dan keputusan cakupan PSB sama dengan model sebelum perubahan pada SD/SMP/SMA/semua unit. Laporan normal dan rangkuman menghasilkan tabel yang identik pada tiga unit.
- `tests/pagination_desktop_browser_test.js`: 1280/1366/1920 px; panah terlihat; ukuran tombol; menu terkait dan menu daftar tambahan; halaman pertama/tengah/terakhir/kosong; filter/ukuran halaman; fallback tanpa JavaScript; tanpa error JavaScript atau overflow halaman.
- Endpoint pencarian: delapan skenario mencakup sumber native/Legacy, batas 20 hasil, isolasi unit, 401 tanpa sesi, 400 parameter tidak valid, dan 409 unit berubah.
- Excel: seluruh nominal komponen dan total cocok dengan proyeksi untuk 50 siswa kelas 4, dua subtotal rombel. PDF memuat identitas/tahun/periode yang sama, alamat 10 pt, dan tidak memuat catatan/panel yang dihapus. Ekspor besar: 70 halaman, 18 subtotal rombel, satu grand total.
- Pemeriksaan sintaks PHP pada seluruh file tugas, pemeriksaan JavaScript, dan `git diff --check` lulus.

Artefak privat: `C:/laragon/backups/spp-management-system/pagination_desktop_20261103_055250/`. Berisi backup/hash dan bukti restore, checksum sebelum/sesudah, model pembanding, pengukuran HTTP/SQL/browser, screenshot, PDF dan Excel pengujian. Sesi pengujian hanya untuk server salinan.

## Perapian lanjutan pagination dan tabel

- Ukuran pagination desktop di atas diganti menjadi **120 × 38 px**, font 13 px, ikon 14 px, radius 10 px, jarak 8 px, dan margin vertikal 12 px melalui stylesheet bersama. Warna, status nonaktif, dan URL navigasi tetap.
- Setiap siswa kembali memakai satu baris tabel. Class `billing-matrix-amount` dipindahkan dari sel ke span nominal. Tabel memakai lebar minimum 1180 px dan kolom yang dapat berkembang; pengguliran tetap di dalam tabel. Aturan matriks umum yang memaksakan layout tetap serta kolom identitas melekat dinonaktifkan khusus tabel ini pada desktop agar kolom tidak saling menimpa saat digulir.
- No/No Induk rata tengah horizontal dan vertikal; nama/keterangan rata kiri; nominal rata kanan tanpa membungkus. Perataan identitas juga diperiksa pada workbook biasa dan global. Tahun ajaran, kelompok rombel, subtotal, dan keterangan tagihan dipertahankan.
- PSB tanpa komponen tercatat memakai fallback penyajian `Rp 0` pada layar/cetak/PDF dan angka 0 pada Excel. Nilai PSB yang belum diketahui tetap ditandai belum diketahui. Fallback tidak menambah kewajiban atau mengubah model keuangan.
- Uji browser lulus pada **1280, 1366, 1920 px**: ukuran tombol, halaman tengah/terakhir/kosong, fallback tanpa JavaScript, jumlah sel, seluruh sel tetap `table-cell`, kesamaan posisi vertikal, perataan identitas, tidak ada sel bertumpuk/menimpa, serta tanpa overflow halaman. Screenshot area data diambil pada posisi gulir kiri/kanan, termasuk nama panjang, keterangan beberapa bulan, dan nominal `Rp 99.999.999.999.999` yang disimulasikan hanya pada DOM browser.
- Regresi proyeksi dan pengelompokan lulus. Uji terpisah mencakup PSB kosong, nominal tercatat, PSB belum diketahui, serta model tidak berubah setelah render/ekspor. Excel aktual memuat 50 siswa dan dua rombel dengan seluruh nominal/subtotal identik; 261 kemunculan nominal pada PDF empat halaman juga identik dengan keluaran sebelum perapian. Timestamp pembuatan dokumen dibandingkan terpisah.
- Restore/identitas server salinan diverifikasi kembali. Seluruh **65 checksum tabel dasar** sebelum/sesudah pengujian tetap sama. Tidak mengubah sumber nilai, filter, perhitungan, skema, atau transaksi. Pemeriksaan sintaks PHP/JavaScript dan `git diff --check` lulus.

Artefak revisi: `C:/laragon/backups/spp-management-system/pagination_desktop_20261103_055250/compact_revision_064747/` (salinan file sebelum perapian, screenshot desktop/PDF, hasil browser, PDF/Excel, serta checksum sebelum/sesudah).

## Jarak, perataan baris, dan cetak Riwayat Tagihan

- Judul tahun/rombel diberi inset horizontal 24 px. Total keseluruhan memakai padding 16 × 24 px; pagination bawah diberi jarak kanan 24 px dan bawah 20 px. Pesan hasil kosong memakai padding 24 px. Perubahan jarak ini khusus Riwayat Tagihan; ukuran tombol pagination bersama tetap 120 × 38 px.
- Seluruh isi baris siswa rata tengah secara vertikal pada desktop dan keluaran cetak. Nama tetap rata kiri, No/No Induk rata tengah, dan nominal rata kanan. Baris dengan keterangan beberapa baris, termasuk Atsilah Athifa Putri A, tidak lagi tampak menggantung di atas.
- Cari Siswa dipindahkan ke baris yang sama dengan Siswa/Halaman, memakai perbandingan lebar 2:1 untuk pencarian dan pemilih ukuran halaman. ID, callback pencarian, parameter filter, dan fungsi submit tetap. Pembungkus baris tidak mengubah susunan mobile.
- Audit PDF sebelum perubahan menunjukkan isi tabel sekitar 6,8 pt serta judul/header rombel 4B di halaman sebelumnya tanpa data siswa. Sesuai pilihan pengguna, isi tabel kini **10 pt**. Lebar No/NIS/Nama cetak diatur ulang agar nama lebih terbaca; nominal tidak membungkus.
- Identitas tahun/rombel berada di dalam `thead` keluaran bersama sehingga ikut diulang di setiap halaman. Rombel berikutnya dimulai pada halaman baru. Baris siswa terakhir tidak dipisah dari subtotal. PDF dari tombol Cetak memakai jalur PDF existing yang sama; tidak mengganti renderer atau perhitungan.
- Uji browser 1280/1366/1920 px lulus untuk jarak, pencarian sejajar, semua sel `table-cell`, perataan vertikal, nominal besar, nama panjang, hasil kosong, filter/pagination, serta tidak ada overflow halaman. Screenshot mencakup filter, pencarian, area data kiri/kanan, total/pagination, dan hasil kosong.
- PDF aktual melalui `format=print` memuat **50 siswa, 2 rombel, 7 halaman**, dibanding 4 halaman sebelum pembesaran font. Setiap halaman tabel mempunyai identitas rombel, header kolom, dan data siswa; tidak ada judul/header tanpa data. Semua identitas tampil tepat sekali, seluruh 261 kemunculan nominal sama dengan PDF pembanding, dan batas teks tetap di dalam halaman. Excel mempunyai seluruh baris, keterangan, nominal, dan subtotal yang identik dengan pembanding (timestamp pembuatan dikecualikan).
- Regresi proyeksi/pengelompokan, sintaks PHP/JavaScript, dan `git diff --check` lulus. Identitas/restore salinan diperiksa kembali; 65 checksum tabel dasar sama sebelum/sesudah. `koneksi.php`, `.gitignore`, dan data keuangan tidak berubah.

Artefak: `C:/laragon/backups/spp-management-system/pagination_desktop_20261103_055250/spacing_print_071152/`. Berisi salinan file sebelum perubahan, hasil browser, screenshot, PDF/Excel, hasil pemeriksaan PDF, serta checksum sebelum/sesudah.

## Judul polos, catatan ringkas, dan font cetak 9 pt

- Judul rombel pada pratinjau bersama dan cetak memakai teks gelap di atas putih, tanpa pita hijau. Baris konteks diberi class tersendiri dan aturan latar yang mengatasi gaya header umum. Identitas tahun/rombel tetap berulang pada halaman lanjutan; header kolom memakai palet existing.
- Semua tahun kalender dan tahun ajaran pada deskripsi periode dalam Keterangan Tagihan dihilangkan sesuai pilihan pengguna. Contoh: `SPP (Okt, Nov) + Komite (Nov) + Daftar Ulang`. Bulan tetap berurutan, catatan tidak meninggalkan kurung kosong, dan tanggal/periode asli pada bukti tetap utuh. Tahun ajaran di judul, PSB sekali masuk, status lunas, dan nilai belum diketahui tetap tersedia.
- Judul kolom total pada renderer layar/cetak/pratinjau dan workbook menjadi **TOTAL**. Label total keseluruhan dipertahankan. Font tabel cetak/PDF menjadi **9 pt**, judul rombel **10 pt**, padding **4 pt vertikal × 3 pt horizontal**, line-height **1,3**. Font desktop dan alamat kop tetap.
- `tests/billing_history_plain_browser_test.js` lulus pada 1280/1366/1920 px, mencakup layar daftar, pratinjau PDF dan Excel, computed style latar putih/teks gelap, catatan tanpa tahun, kolom TOTAL, font/padding print, dan screenshot kiri/kanan. Pemeriksaan sintaks, regresi proyeksi/pengelompokan, dan `git diff --check` lulus. Fixture mencakup periode Desember/Januari, DU tahunan, kredit/sisa, lunas, dan nilai belum diketahui.
- PDF kelas 4 melalui tombol Cetak memuat **50 siswa, 2 rombel, 6 halaman** (sebelumnya 7 halaman dengan font 10 pt). Seluruh 261 kemunculan nominal dan identitas sama dengan pembanding. PDF tingkat 1 memuat **83 siswa, 9 halaman**. Keduanya memiliki header/konteks pada setiap halaman tabel, semua siswa tepat sekali, ukuran isi aktual 9 pt/judul 10 pt, serta tanpa judul tanpa data atau teks keluar batas halaman.
- Excel kelas 4 dibandingkan per sel: semua identitas, komponen, subtotal, dan total tetap sama; perubahan hanya header TOTAL serta 45 catatan yang diringkas, di luar timestamp pembuatan. Sumber nilai, filter, transaksi, dan skema tidak diubah. Identitas restore salinan diverifikasi; checksum 65 tabel dasar serta hash `koneksi.php`/`.gitignore` tetap sama sebelum/sesudah pengujian.

Artefak revisi: `C:/laragon/backups/spp-management-system/pagination_desktop_20261103_055250/plain_report_073010/` (salinan sebelum perubahan, screenshot tiga ukuran desktop/pratinjau, PDF kelas 1/4, Excel, hasil pemeriksaan ekspor dan checksum).

## Tabel cetak sesuai referensi dan penghapusan catatan PSB

- Cetak/pratinjau PDF Riwayat Tagihan memakai renderer khusus `includes/billing_history_print.php` dan stylesheet terpisah. Renderer menerima proyeksi existing tanpa mengubah nilai, bukti periode, urutan kelompok, atau deskripsi. Tabel daftar, stylesheet layar, dan renderer Excel tidak diubah.
- Times New Roman normal/tebal didaftarkan dari font Windows yang tersedia lalu disematkan pada PDF. Pratinjau menerima font yang sama melalui font-face tertanam. Jika tidak tersedia, kedua keluaran memakai DejaVu Serif bawaan. Isi/header tabel 9 pt, judul kelas 10 pt, padding 2 pt vertikal × 3 pt horizontal, line-height 1,3. Kop/metadata/footer tetap memakai font existing. Binary font sistem tidak ditambahkan ke repository.
- Tabel hitam putih tanpa zebra atau garis vertikal; garis horizontal membatasi header/subtotal. Kelas menjadi baris `KELAS: ...`, diikuti siswa, lalu `JUMLAH:`. Nominal tetap memakai Rp. Rombel menyambung pada halaman yang sama; tabel baru dibuat per unit/tahun, dengan konteks dan header berulang. Nomor berlanjut sepanjang hasil filter, termasuk fragmen PDF. Subtotal tetap memakai seluruh kelompok dan hanya dicetak pada akhir kelompok.
- Nilai belum diketahui tetap dinyatakan belum diketahui, termasuk subtotal campuran. Bagian angka nominal tidak membungkus; status pelengkap dapat membungkus agar tidak keluar kolom. Pemisahan halaman menjaga judul kelas dengan siswa pertama, serta subtotal dengan siswa terakhir.
- Paragraf penjelasan JUMLAH pada cetak PSB dan gaya `psb-note` dihapus. Pemeriksaan PDF sebelum/sesudah memastikan 82 identitas PSB dan seluruh angka tetap sama; perubahan ruang dapat memindahkan baris antarhalaman. Tidak mengubah model PSB atau Excel PSB.
- Sintaks PHP/JavaScript, `git diff --check`, regresi proyeksi/pengelompokan dan `tests/billing_history_print_test.php` lulus. Pengujian meliputi pengelompokan unit/tahun, nomor berlanjut, nominal/identitas, escaping, nilai belum diketahui, hasil kosong, dan penutupan subtotal pada fragmen akhir.
- Browser 1280/1366/1920 px lulus untuk daftar, pratinjau cetak/Excel, font/padding, warna putih, tanpa garis vertikal, catatan tanpa tahun, dan penghapusan catatan PSB. Perbandingan HTML daftar dan dokumen pratinjau Excel sebelum/sesudah identik setelah normalisasi waktu pembuatan.
- PDF nyata kelas 4: 50 siswa, 2 rombel, 3 halaman; tingkat 1: 83 siswa, 3 rombel, 5 halaman. Identitas dan seluruh nominal sama dengan pembanding. Times New Roman normal/tebal tersemat, isi aktual 9 pt, tidak ada teks melewati batas halaman.
- Laporan semua unit/semua tahun dengan tingkat 4/8/11: **1.997 baris, 121 halaman**, SD/SMP/SMA, tahun 2014/2015–2026/2027. Nomor berurutan, konteks/header ada pada setiap halaman, tidak ada judul/subtotal tanpa siswa atau teks keluar area cetak. Pengujian ini mempertahankan histori lama dan nilai belum diketahui.
- Fixture terpisah `tests/billing_history_print_render_test.php`: 501 siswa sintetis, 7 fragmen, 26 halaman, 4 subtotal dan satu grand total. Semua identitas tepat sekali/berurutan; nama panjang, nominal besar, komponen tambahan, tahun/unit berbeda, dan nilai belum diketahui tetap terbaca. Fixture tidak membuka koneksi data aplikasi.
- Cache artefak memasukkan renderer/stylesheet baru serta keluarga font terpilih agar unduhan tidak memakai layout lama. Identitas restore salinan diverifikasi; 65 checksum tabel dasar, hash konfigurasi/`.gitignore`, model presentasi, stylesheet daftar, serta renderer Excel tetap sama sebelum/sesudah.

Artefak: `C:/laragon/backups/spp-management-system/pagination_desktop_20261103_055250/reference_table_081534/`. Berisi PDF/screenshot nyata, fixture berlabel, pembanding daftar/Excel/PSB, hasil browser/ekspor, serta checksum.

## Pagination dan keluaran seluruh Laporan Global

- Area pagination sepuluh jenis laporan diaudit. Delapan daftar yang memakai pagination mendapatkan padding horizontal 24 px, atas 16 px, bawah 20 px, melalui aturan bersama khusus workspace Global pada desktop >=1024 px. Tombol 120 x 38 px/gap 8 px tetap. Setoran Kas Harian dan Kas Tabungan Harian tetap tanpa pagination.
- Riwayat Tagihan: nominal tabel, subtotal, dan total keseluruhan cetak/PDF tanpa Rp; pemisah ribuan dan pecahan tetap. Komponen yang tidak tercatat memakai nol pada layar/ekspor, sedangkan nilai belum terbukti dan subtotal campuran tetap menyebutkan belum diketahui. Catatan PSB menjadi `PSB`; bukti periode tidak berubah. Header diberi jarak lebih longgar. Footer panjang dihapus dan nomor halaman di kanan.
- Delapan keluaran lain memakai renderer opt-in `includes/global_report_print.php` dan CSS terpisah: Times New Roman normal/tebal tertanam pada PDF, hitam putih, tanpa zebra/badge/garis vertikal. Header dan total memakai garis horizontal. Seluruh kolom dicetak dalam satu lebar A4 dengan orientasi/font berdasarkan pengukuran isi, termasuk nominal subtotal. Bulan header disingkat; tanggal/tahun sumber utuh. Font tabel dimulai 9 pt. Tabel layar daftar dan konsep PSB dipertahankan.
- Excel memakai font serif, warna hitam putih, A4, fit-to-width 1/fit-to-height 0, dan header berulang termasuk baris nama kolom. Lebar kolom dihitung dalam ukuran fisik berdasarkan isi dan glyph font Normal workbook; tinggi baris mengikuti pembungkusan akhir. Angka tetap numerik dan NIS tetap teks. Penyesuaian kecil pada sel numerik/tanggal mencegah Excel menampilkan `###`, termasuk subtotal panjang; nama/keterangan tetap membungkus. Tidak mengubah nilai tanggal atau nominal untuk mengatasi masalah tampilan.
- Setoran Kas Harian/Kas Tabungan Harian/Transaksi Tabungan: label Unit dan rangkuman status/periode di judul dihapus dari keluaran. Peringatan saldo di judul Transaksi Tabungan dihapus; keadaan belum diketahui pada data tetap. Label Arus Tabungan menjadi Tabungan. Alamat Transaksi Tabungan 14 px pada pratinjau dan 10 pt pada cetak/PDF/Excel. Nomor halaman di kanan; kop, metadata pembuatan, petugas, identitas unit dalam data semua-unit, serta tanda tangan tetap tersedia.

Pembuktian pada server salinan port MySQL 58660/PHP 58662, restore dan UUID/datadir diverifikasi:

- PDF dan XLSX pembanding sepuluh jenis laporan disimpan sebelum perubahan. Semua sel identitas/keuangan/status dibandingkan dengan keluaran akhir; perbedaan dibatasi perubahan presentasi yang diminta. Seluruh kemunculan nominal PDF cocok dengan PDF pembanding. Semua 65 checksum tabel dasar dan tujuh hash sumber terlindungi tetap sama, termasuk konfigurasi, `.gitignore`, engine/reader laporan, serta model matriks/PSB.
- Browser 1280/1366/1920 px: sepuluh jenis laporan, daftar dan pratinjau cetak/Excel, padding/tombol, kondisi awal/tengah/akhir/kosong, ukuran halaman, filter URL, latar putih, font serif, dan overflow halaman. Screenshot disimpan per jenis laporan dan ukuran desktop.
- Sebelas workbook dibuka read-only di instance Microsoft Excel yang tidak terlihat, lalu diekspor menjadi PDF aktual. Termasuk delapan jenis laporan, beberapa kategori sekaligus, semua unit, dan hasil kosong. Berkas sumber tidak disimpan ulang. A4, satu halaman horizontal, tinggi otomatis, dan baris header berulang diperiksa langsung melalui Excel. Pemetaan otomatis A4 ke Letter dinonaktifkan hanya pada instance pengujian agar ukuran PDF verifikasi benar-benar A4.
- PDF hasil Excel dibandingkan dengan sel workbook: seluruh nominal, identitas, dan tanda negatif/pecahan cocok; tidak ada `###` atau teks melewati batas kertas. Font SPP 12 bulan terukur sekitar **6,4 pt pada PDF aplikasi** dan **6 pt pada cetak Excel**; sebagian subtotal panjang menyesuaikan sedikit. Semua 19 kolom PDF/20 kolom Excel tetap tersedia, termasuk NIS Diknas pada Excel. Kombinasi beberapa kategori dapat memakai font lebih kecil sesuai prioritas satu lebar A4.
- Sampel kelas 4, jumlah halaman PDF sebelum -> sesudah: Status 5 -> 2, Penerimaan 9 -> 3, SPP Tahunan 18 -> 4, per Item 7 -> 5, Transaksi Tabungan 18 -> 6, Saldo Tabungan 3 -> 2, Setoran 2 -> 1, Kas Tabungan 1 -> 1. Riwayat Tagihan dan PSB tetap 3/2 halaman. Perubahan jumlah halaman tidak menghilangkan kolom atau data.
- Riwayat Tagihan semua unit/semua tahun pada tingkat 4/8/11: 62 halaman, tahun 2019/2020–2026/2027, 50 subtotal kelompok dan satu total keseluruhan. Hasil kosong, beberapa rombel, keterangan panjang, serta status belum diketahui turut diperiksa.
- Fixture PDF besar terpisah: 501 siswa Riwayat Tagihan/7 fragmen dan matriks 270 baris x 20 kolom/4 fragmen. Identitas tepat sekali, subtotal/total tidak ganda, header bulanan berulang, nominal besar/pecahan/negatif dan nilai belum diketahui tetap. Fixture tidak membuka koneksi data aplikasi.
- Regresi proyeksi/pengelompokan/PSB, renderer cetak, A4/Excel bertipe, empat unit Excel umum, presisi pecahan, cache artefak, sintaks PHP/JavaScript dan `git diff --check` lulus. Pengujian berkas PSB/ekspor lain yang membutuhkan fixture khusus tetap menggunakan jalur pembanding HTTP/readback di atas, bukan menjalankannya terhadap basis data operasional.

Artefak revisi: `C:/laragon/backups/spp-management-system/pagination_desktop_20261103_055250/global_outputs_092233/`.

- `baseline/`, `before/`: keluaran dan sumber pembanding sebelum perubahan.
- `final/`: PDF/XLSX aplikasi, PDF cetak aktual Excel (`*-excel.pdf`), screenshot halaman awal/tengah/akhir, kasus beberapa kategori/semua unit/semua tahun/kosong.
- `browser-results.json`, `export-verification.json`, `native-excel-results.json`, `native-excel-pdf-verification.json`, `edge-verification.json`: hasil pemeriksaan.
- `checksums-before.json`, `checksums-after.json`, `protected-files-before.json`, `final-integrity.json`: bukti integritas.
- File sumber perhitungan, API publik, skema, filter, hak akses, engine kewajiban/kredit dan transaksi tidak diubah. Perubahan lokal sebelum pekerjaan dipertahankan.

## Perapian empat menu desktop dan keluaran laporan

- DU menggunakan renderer navigasi bersama `registration_history_nav.php`: Riwayat Aktif, Riwayat Dihapus, Histori Legacy, dengan tinggi minimal 44 px, font 14 px, padding/radius sama dan tab aktif hijau. Paragraf arsip yang diminta dihapus tanpa elemen kosong. Legacy menetapkan `source=legacy`, Aktif `source=all`; halaman/seleksi direset, alias kelas/tahun diterjemahkan dan status yang tidak kompatibel tidak dibawa ke arsip. Pembaca/rekening/status keuangan existing tidak diubah.
- Master Kelas/Rombel hanya menawarkan Per Kelas/Tabel. Atribut pembukaan otomatis kelompok pertama dihapus dari HTML. JavaScript menutup seluruh kelompok saat Per Kelas dipilih; Tabel tetap menampilkan baris existing. Preferensi lama Kartu jatuh ke Per Kelas tertutup, preferensi Tabel tetap berlaku. Formulir pengelolaan dan kenaikan kelas tidak berubah.
- D.ULANG diterapkan pada header Riwayat Tagihan saja, melalui pemformat label berdasarkan kunci komponen. Label sumber/filter, Keterangan Tagihan, dan Daftar Ulang pada laporan lain tetap utuh. TOTAL dan Keterangan memakai padding 12 px pada desktop/6 pt pada cetak, termasuk subtotal. Excel menggunakan perataan dan indent yang sepadan tanpa kolom tambahan.
- Tahun ajaran di bawah judul utama cetak/PDF Tagihan dihapus; Dibuat/Petugas tetap. Pada Excel, blok tahun/metadata atas dihapus dari workbook, isi pratinjau dan toolbar pratinjau; baris 6/7 menjadi tinggi nol. Konteks tahun ajaran per kelompok tetap, begitu juga kop, identitas, nominal dan subtotal.
- Panel filter tanggal Penerimaan pada desktop diberi lebar 460 px dan ruang cukup untuk dua input tanggal lengkap serta tombol kalender; penempatan floating tetap mengikuti viewport. Hidden value dan perilaku Terapkan tidak berubah.
- Penerimaan cetak/PDF/Excel tidak menampilkan Unit/status/periode di judul, alamat 14 px pada pratinjau/10 pt pada cetak dan Excel, serta nominal tanpa Rp pada tabel/subtotal/total. Metadata Dibuat/Petugas dan identitas unit dalam data semua-unit tetap. Flag penyajian mata uang hanya memengaruhi Penerimaan; nilai numerik, pecahan, negatif dan status belum diketahui tidak dikonversi.
- Audit pembanding membuktikan PDF Penerimaan besar mencetak **dua** teks Halaman pada setiap halaman: penghitung CSS tidak berhasil dinonaktifkan karena kalah spesifisitas dari stylesheet. Penonaktifan pada jalur fragmen kini memakai prioritas yang benar; canvas memberi satu nomor final di kanan. PDF biasa tetap memakai penghitung CSS. Perbaikan tidak mengubah pemecahan data/subtotal.

Pengujian pada salinan MySQL 58660/PHP 58662 dengan restore/UUID/datadir terverifikasi:

- `desktop_four_presentation_test.php` lulus untuk perpindahan filter/tab DU, label Tagihan yang terisolasi, metadata Excel, tipe angka/NIS, negatif/pecahan dan nilai belum diketahui. Regresi proyeksi Tagihan, renderer cetak serta formatter A4/Excel juga lulus.
- `desktop_four_browser_test.js` lulus pada **1280/1366/1920 px**: ketiga tab DU, kesamaan computed style, panel tanggal tanpa pemotongan/scroll horizontal, dua mode rombel, seluruh kelompok tertutup, pemulihan preferensi lama, jarak kolom, judul/metadata dan pratinjau Excel/PDF. Screenshot disimpan per lebar. Navigasi DU dengan filter tahun/kelas/pencarian/ukuran halaman juga diuji melalui klik tautan nyata tanpa JavaScript; status khusus direset dengan benar dan tampilan tidak menghasilkan kesalahan validasi. Rombel tanpa JavaScript tetap tertutup.
- PDF/XLSX sebelum dan sesudah dibandingkan: seluruh sel identitas/keuangan/status serta semua nominal PDF sama, di luar label/metadata yang diminta. Sampel Tagihan tingkat 1 tetap 5 halaman; Penerimaan tingkat 1 tetap 5 halaman. Penerimaan besar berubah 18 menjadi 20 halaman karena penyesuaian ruang/font; seluruh nominal tetap dan nomor halaman tepat sekali di setiap halaman. Tampilan semua-unit SD/SMP/SMA serta hasil kosong juga lulus pemeriksaan batas kertas dan footer.
- **Enam workbook** dibuka read-only di Microsoft Excel dan dicetak ke PDF aktual A4: Tagihan, Penerimaan kecil/besar/semua-unit, serta kedua laporan kosong. Semua nominal cocok dengan PDF aplikasi; tidak ada `###`, teks keluar batas kertas atau nomor halaman ganda. Workbook sumber tidak disimpan ulang. Header/konteks halaman lanjutan tetap, sementara metadata yang diminta hilang.
- Fixture terpisah 501 siswa Tagihan dan matriks 270 baris/20 kolom lulus: identitas tepat sekali, pemecahan dokumen tidak menggandakan data, nilai besar/pecahan/negatif serta status belum diketahui tetap.
- Sintaks PHP/JavaScript dan `git diff --check` lulus. Checksum **65 tabel** tetap sama. Sembilan hash sumber terlindungi tetap sama, termasuk `koneksi.php`, `.gitignore`, engine/reader laporan dan kedua pembaca DU. Tidak ada perubahan skema, API publik, hak akses, perhitungan atau transaksi.

Artefak: `C:/laragon/backups/spp-management-system/pagination_desktop_20261103_055250/desktop_four_124057/`.

`before/` dan `baseline/` menyimpan sumber/keluaran pembanding. `final/` menyimpan PDF/XLSX aplikasi, PDF cetak Excel (`*-excel.pdf`), screenshot halaman awal/tengah/akhir dan kasus semua-unit/kosong. Screenshot desktop, hasil browser/navigasi, pemeriksaan ekspor/Excel dan checksum disimpan di direktori artefak tersebut.
