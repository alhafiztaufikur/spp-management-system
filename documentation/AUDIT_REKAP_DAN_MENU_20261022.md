# Perapihan menu dan audit kelengkapan rekap — 22 Oktober 2026

Perubahan melanjutkan pekerjaan 21 Oktober. Konfigurasi `koneksi.php` tetap identik dengan awal pekerjaan ini dan default tetap `db_spp_test2`. Tidak ada migrasi skema, penerbitan tagihan, impor ulang, penggantian transaksi, atau penulisan data pengguna pada tahap ini.

## Perubahan yang diterapkan

| Area | Hasil |
|---|---|
| Penerbitan DU | Judul/ringkasan di atas, persetujuan pada baris sendiri, tombol penerbitan dan penutupan sejajar; vertikal pada ponsel. Formulir dan konfirmasi tetap. |
| Tabungan | Informasi jumlah di kiri dan pagination rata kanan, termasuk panel sempit, riwayat, dan daftar cetak. Label Alumni memakai kelas/tahun yang tersedia, tanpa mencocokkan nama pemilik. |
| Periksa Koreksi | Identitas, formulir dua kolom, nominal Indonesia, alasan, dan pratinjau sebelum/sesudah dirapikan. Nominal diproses sebagai string desimal kanonik dengan maksimal dua desimal dan nol untuk arah yang tidak digunakan. Isian gagal tetap tersedia. |
| Administrator | Jurnal tabungan operasional Legacy dapat dikoreksi langsung dengan pratinjau dan audit. Pemeriksaan peran aktif, unit, CSRF, versi, kedaluwarsa, replay, perubahan saldo, dan saldo berjalan tetap berlaku. Pemindahan pemilik/pembatalan Legacy dibatasi Super Admin; akses pembayaran dan impor tidak diperluas. Tabungan native tetap memakai layanan koreksi existing. |
| Master Biaya Lain | Tab Aktif, Nonaktif, Bisa Dihapus beserta jumlah. Tab terakhir merupakan subset master belum terpakai dan bukan master khusus Legacy. Alasan penguncian terlihat; penghapusan memeriksa ulang relasi dengan kunci transaksi. |
| Naik Kelas | Dropdown hanya memperbarui tujuan. Peserta dimuat melalui Tampilkan Peserta; konteks yang belum diterapkan menonaktifkan proses daftar lama. Kontrol tujuan disamakan, header peserta solid, pencarian dan menu tindakan dirapikan. |
| Laporan Transaksi | Lima bagian selalu tampil saat Semua dipilih. Ukuran kolom, header, identitas sticky, aksi cetak, nama, nominal dan tanggal dirapikan. Gulir dibatasi pada tabel. |
| Laporan Global | Setoran Kas Harian, Kas Tabungan Harian, Transaksi Tabungan dan Penerimaan Harian memakai judul/unit di atas serta filter 3/2/1 kolom desktop/tablet/ponsel. Pilihan jamak dan nilai filter tetap. |

Pagination tiap bagian memakai GET `fragment=section`, `section`, dan `section_page[jenis]`. JSON mengembalikan HTML, identitas bagian, halaman, ukuran halaman, jumlah baris/halaman, dan fingerprint filter. Jalur ini memeriksa akses yang sama dan hanya membangun bagian yang diminta. Ukuran halaman dibatasi 10/25/50. Tautan GET tetap berfungsi tanpa JavaScript.

JavaScript mengganti satu bagian, menyimpan posisi gulir, menggabungkan permintaan identik, membatalkan permintaan lama, dan mengabaikan respons kedaluwarsa. Kegagalan mempertahankan tabel beserta tombol Coba lagi. Back/Forward mengikuti keadaan URL. Pilihan cetak pada halaman yang diganti direset; tabel lain dipertahankan. Gulir tidak meminta data baru.

Pembaca bulanan kini membatasi JSON/item Legacy sejak query berdasarkan periode yang diperlukan. Pembaca massal tetap tersedia. Pengaturan unit berulang pada kredit DU dihindari ketika unit koneksi sudah benar; pemulihan konteks Semua Unit tetap dalam `finally`.

## Rekonsiliasi dan batas bukti

Pembandingan independen menggunakan item sumber dalam sen, alokasi aktif, pembatalan, kredit historis, jurnal, dan saldo authoritative. Identitas dipadankan menurut unit, ID siswa/pemilik, sumber dan row key. Data seluruh periode pada Laporan Transaksi dicocokkan dengan jumlah serta nominal sumber; sembilan template Global dan seluruh kategorinya diperiksa untuk SD/SMP/SMA/Semua Unit.

| Pemeriksaan | SD | SMP | SMA | Total |
|---|---:|---:|---:|---:|
| Pembayaran operasional historis | 46.852 | 60.181 | 11.211 | 118.244 |
| Tanpa tanggal, juga kosong pada sumber asli | 3.064 | 5.084 | 1.140 | 9.288 |
| Jurnal Legacy bertanggal, jam sumber tidak tersedia | 59.073 | 27.532 | 8.937 | 95.542 |
| DU masih memiliki sisa terbukti, Oktober | 60 | 20 | 5 | 85 |
| Sisa DU | Rp116.740.300 | Rp47.055.000 | Rp17.800.000 | Rp181.595.300 |

Angka nol SPP/Komite Oktober **tidak membuktikan lunas**: tagihan native bulan tersebut tidak tersedia. Pembayaran historis yang periodenya terbukti tetap dibaca. Catatan tiap bagian membedakan belum diterbitkan, tanpa tagihan komponen, tidak ditagihkan, dibatalkan, lunas/tercakup, cicilan dan bukti belum lengkap. Pokok historis yang tidak diketahui tetap `null`, tidak diganti tarif sekarang.

Seluruh 9.288 pembayaran tanpa tanggal juga tidak mempunyai tanggal pada arsip asli. Sebanyak 793 jam pembayaran yang mempunyai bukti sumber tetap dibaca dengan pencocokan unit/sumber/row key. Arsip jurnal utama tidak memiliki kolom jam; padding tengah malam ditampilkan sebagai **Jam tidak tercatat**. Jam koreksi yang disahkan mendapat prioritas, termasuk tengah malam yang secara eksplisit diketahui. Tidak ada waktu impor/hari ini yang dipakai sebagai pengganti.

Checkbox tanpa tanggal menggunakan irisan periode kewajiban yang terbukti. Semua Periode memungkinkan penelusuran seluruh histori. Keadaan yang sama diterapkan pada total, tabel, dan ekspor.

Inventaris arsip juga membedakan baris referensi dari transaksi sah. Ada 14 baris DU bernilai negatif yang menurut kebijakan migrasi existing merupakan **Anomali DU**, bukan penerimaan/pengembalian/tagihan. Delapan baris jurnal utama tidak menjadi jurnal aktif: tujuh bernilai nol dan satu Rp10.000 tanpa identitas pemilik. Baris tersebut tetap tersimpan sebagai referensi; tidak dibuatkan rekening atau transaksi pengganti. Sebanyak 95.542 jurnal Legacy cocok melalui row key dengan 86.860 baris `transaksi_m`, 8.679 `transaksi_k`, dan tiga `tb_masuk` yang sudah disahkan melalui aturan migrasi existing. Tidak ada row key jurnal operasional Legacy yang kehilangan arsip sumber. Rincian row key dan alasan ada pada bukti privat `source-evidence.json`; pemeriksaan ini tidak mengesahkan identitas yang belum ada buktinya.

Saldo 2.418 rekening dan total hasil Global cocok dengan oracle sumber independen. Penyesuaian migrasi dan transaksi baru yang memang sudah ada pada dump tetap dibedakan dari kas historis. Bukti asal dan pengesahan harus dibaca bersama; hitungan baris mentah saja tidak cukup untuk menyatakan transaksi hilang.

## Pengujian

Semua mutasi dijalankan pada salinan `db_spp_audit_recap_20261022`, MySQL lokal port 3306, dengan opt-in pengaman repository. Server pembanding memakai port 58511 dan server hasil port 58512, keduanya menunjuk salinan yang sama. Baseline merupakan salinan workspace sebelum perubahan tahap ini, termasuk pekerjaan sebelumnya. Sesi dan artefak berada di luar web root.

- `recap_refinement_model_test.php`: **164** pemeriksaan lulus, termasuk lima bagian, halaman mandiri, kesetaraan pembaca periode/bulk dalam sen, sumber semua unit, alumni/non-siswa, Administrator/Super Admin, versi, replay, saldo berubah setelah pratinjau dan penolakan saldo negatif.
- `recap_refinement_http_test.php`: **107** pemeriksaan lulus melalui formulir nyata, termasuk simpan/pemulihan nominal, CSRF, unit, izin pembayaran, koreksi native dan penolakan hapus master yang mulai digunakan setelah halaman dibuka.
- Oracle Global independen: **3.264** pemeriksaan lulus pada baseline dan hasil, termasuk saldo setiap rekening, jumlah dan nominal per kategori serta Semua Unit. Wrapper privat hanya mengganti pengaman port lama dengan identitas clone ini dan path include; seluruh assertion sumber dipertahankan.
- Regresi existing: 265 pemeriksaan model performa menu; 68 native Global, 15 coverage, 15 koreksi dan 13 pembayaran November nyata; 36 kasus waktu; 15 presisi tabungan; buku tabungan dan typed Excel empat unit lulus.
- Browser Chrome: 1440/900/390 px, tema terang/gelap, tidak ada overflow halaman atau kesalahan JavaScript. Pemuatan peserta melalui tombol, pagination tanpa navigasi dokumen, Back, respons terlambat, gagal/coba lagi, dan gulir tanpa fetch lulus. Pemeriksaan tambahan mencakup header solid, menu dalam viewport/menutup saat pemicu terpotong, identitas Alumni, fallback GET tanpa JavaScript serta palet SMP/SMA pada ponsel.
- Baca kembali **14 dokumen** PDF/XLSX. Laporan Transaksi memuat 301 baris pembayaran/tunggakan SD, 564 Semua Unit, dan 622 SD dengan tanpa tanggal; kelima bagian tetap ada. Global memuat 12 baris ringkasan setoran, 5 kas tabungan, 229 penerimaan, dan 512 transaksi tabungan sesuai model ekspor. Identitas, nilai numerik, subtotal dan penanda waktu diperiksa kembali. Halaman tabel aktif tidak membatasi ekspor.

PDF besar memakai Dompdf dan canvas existing secara bertahap, dengan header berulang, nomor berlanjut, serta subtotal terakhir per bagian. Excel mempertahankan nilai numerik dan menampilkan pecahan dua desimal jika diperlukan, termasuk detail, subtotal dan pratinjau. Uji baca kembali khusus `100.000,13` lulus pada workbook biasa dan Global. Renderer Global dan aturan buku tidak diganti. Pemeriksaan sintaks akhir mencakup 67 file PHP dan 9 JavaScript tanpa kesalahan; `git diff --check` lulus.

## Performa

Pengukuran HTTP loopback berpasangan memakai data, sesi dan lingkungan yang sama, tanpa ekspor/browser berjalan bersamaan. Navigasi diulang lima kali; setiap bagian SD dan Semua Unit diulang 20 kali. p95 memakai nearest rank. Kunjungan pertama pada matriks bukan pengujian cold boot MySQL/OS. Angka berikut dibulatkan ke milidetik; detail SQL, jumlah query, ukuran HTML, dan setiap sampel terdapat pada `performance-final.json`.

| Navigasi SD | Median baseline | Median hasil | p95 hasil, n=5 |
|---|---:|---:|---:|
| Laporan Transaksi, Semua | 638 ms | 813 ms | 991 ms |
| Master DU | 517 ms | 503 ms | 584 ms |
| Master Biaya Lain | 135 ms | 124 ms | 160 ms |
| Naik Kelas/peserta | 474 ms | 481 ms | 516 ms |
| Tabungan Masuk | 101 ms | 101 ms | 134 ms |
| Riwayat Tabungan | 359 ms | 383 ms | 461 ms |
| Daftar Cetak Tabungan | 560 ms | 523 ms | 611 ms |
| Global Setoran | 657 ms | 604 ms | 607 ms |
| Global Kas Tabungan | 304 ms | 259 ms | 304 ms |
| Global Transaksi Tabungan | 1.130 ms | 1.082 ms | 1.230 ms |
| Global Penerimaan | 597 ms | 604 ms | 654 ms |

| Pagination bagian, n=20 | Median SD | p95 SD | Median Semua Unit | p95 Semua Unit |
|---|---:|---:|---:|---:|
| Semua Transaksi | 150 ms | 171 ms | 147 ms | 168 ms |
| SPP | 196 ms | 255 ms | 285 ms | 365 ms |
| Komite | 194 ms | 363 ms | 252 ms | 304 ms |
| DU | 481 ms | 541 ms | 1.076 ms | 1.167 ms |
| Biaya Lain | 94 ms | 115 ms | 95 ms | 115 ms |

Semua bagian pada satu unit mencapai target p95 ≤1 detik. DU Semua Unit masih melampaui target, sekitar **1,17 detik**, karena membangun kredit/bukti tiga unit; SQL sekitar 0,10–0,15 detik dan sisanya terutama pembentukan bukti PHP. Global Transaksi Tabungan masih sekitar **1,23 detik p95** karena kebutuhan jurnal/saldo berjalan. Tidak ada klaim waktu tunggu nol atau percepatan seragam pada setiap halaman.

Laporan awal sekarang menampilkan lima bagian serta pemeriksaan bukti, sedangkan baseline halaman pertama belum memuat semua bagian tersebut. Median awal bertambah 638→813 ms, sementara p95 matriks ini 1.027→991 ms dan jumlah query turun 422→137. Karena n=5, angka p95 navigasi hanya indikator lokal; statistik 20 sampel bagian lebih representatif untuk pagination. Respons fragment browser terukur 219 ms pada skenario pembayaran. Pengaturan unit berulang DU turun, dengan hasil kredit dan urutan tetap lolos pengujian kesetaraan.

Pembuatan dokumen diukur terpisah dari pagination. Uji baca kembali mencakup periode Oktober dan pilihan tanpa tanggal; ekspor seluruh 118.244 pembayaran beserta seluruh jurnal sekaligus tidak diuji ulang sebagai satu PDF pada tahap ini. Tidak ada pembatasan baris hasil ekspor yang ditambahkan.

## Bukti lokal

Direktori privat: `C:\laragon\backups\spp-management-system\recap_refinements_20261022`.

- `source-integrity.json`: seluruh isi 36 tabel berdata identik; definisi 62 tabel beserta view/routine/trigger pada dump identik setelah whitespace dump diabaikan. `koneksi.php` identik dengan baseline tahap ini.
- `source-evidence.json`, `artifacts/oracle-{baseline,after}/reconciliation.json`: inventaris sumber, angka per bagian dan rekonsiliasi independen.
- `performance-final.json`, `browser-results.json`, `export-results.json`: pengukuran serta hasil verifikasi.
- Screenshot pada `artifacts/`: `publishing-desktop.png`, `participants-desktop.png`, `correction-admin.png`, `fee-groups.png`, `five-sections-desktop.png`, serta matriks `laporan-template.php-*-light/dark-1440/900/390.png` dan tampilan Tabungan.

Data siswa, dump, sesi dan dokumen hasil uji disimpan privat. Screenshot hasil desktop, tablet, ponsel, dan tema tersedia di direktori tersebut untuk diperiksa lokal.

Kedua server PHP uji 58511/58512 sudah dihentikan setelah verifikasi; layanan Laragon pengguna tidak dihentikan. Clone, baseline dan bukti privat tetap tersedia untuk reproduksi.

Contoh screenshot: [Penerbitan DU](<C:/laragon/backups/spp-management-system/recap_refinements_20261022/artifacts/publishing-desktop.png>), [koreksi Administrator](<C:/laragon/backups/spp-management-system/recap_refinements_20261022/artifacts/correction-admin.png>), [Alumni Tabungan](<C:/laragon/backups/spp-management-system/recap_refinements_20261022/artifacts/alumni-savings-desktop.png>), [tabel sticky](<C:/laragon/backups/spp-management-system/recap_refinements_20261022/artifacts/report-sticky-desktop.png>).
