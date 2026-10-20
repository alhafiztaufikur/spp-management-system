# Audit impor dan pemulihan SistemSPP — 20 Oktober 2026

Perbaikan telah diterapkan pada **`db_spp_test2` lokal**. Pemeriksaan mencakup SD, SMP, SMA, dan Semua Unit. Konfigurasi lokal `koneksi.php` tetap menunjuk database tersebut. Pembaruan teman pada `d681e84` sudah diambil dengan fast-forward; pemeriksaan remote terakhir tidak menemukan commit tambahan.

## Temuan dan perbaikan

1. Impor awal mempunyai seluruh isi 60 tabel, tetapi hanya 37 dari 113 foreign key pada dump terpasang. Dump asli juga tidak memuat CHECK yang diwajibkan skema aplikasi terbaru. Pemeriksaan kesiapan Legacy gagal dan pembaca gabungan melewatkan histori. Dipulihkan 76 FK, dipasang 46 CHECK dari skema terbaru, dan dipasang dua tabel audit/otorisasi tabungan beserta enam FK, dua view, dan enam trigger existing. Hasil akhir: **62 tabel, 56 view, 2 routine, 254 trigger, 119 FK, 46 CHECK**; pemeriksaan tidak menemukan relasi pelanggar atau definisi yang berbeda.
2. Metadata `working_digest` tiga batch berisi SHA-256 string kosong akibat penanganan ID batch lama. Arsip sumber, hash payload, occurrence-aware row key, rekonsiliasi keuangan, dan keputusan migrasi diperiksa sebelum metadata diperbaiki. Nilai sebelum/sesudah dicatat dalam tiga entri audit `integrity_repair`.
3. Auditor kewajiban sebelumnya hanya menghitung alokasi yang langsung menunjuk kewajiban. Auditor kini memakai perhitungan bukti PSB/DU/SPP yang sama dengan sinkronisasi tagihan. Data keuangan yang sudah benar tidak diubah untuk memenuhi pemeriksaan yang salah. Audit Semua Unit mengembalikan konteks koneksi setelah memeriksa setiap batch.
4. **Laporan Umum** masih membaca tabel transaksi native. Halaman, komponen, grafik, pencarian, pagination, pilihan laporan, Excel tiga lembar, PDF, dan cetak terpilih kini memakai sumber gabungan existing. Rekening non-siswa ikut dihitung. Penyesuaian saldo tidak menjadi setoran/penarikan. Sumber Legacy diberi penanda; kas berjalan pada template Setoran/Kas Tabungan tetap dibedakan dari histori.
5. Diperbaiki rekursi laporan tabungan Semua Unit, pengulangan pencarian operator, pemindaian kategori berulang, agregasi saldo sebelum penyaringan identitas, dan pencarian operator native pada jurnal Legacy. Bukti tagihan Semua Unit dibaca sekali lalu dipartisi berdasarkan ID pemilik/unit; hasilnya identik dengan pembacaan unit secara terpisah. Rekap tabungan tanpa filter memakai proyeksi ringkas dengan jumlah dan nominal yang sama dengan histori lengkap. Rekap SPP Tahunan mengambil bukti bulanan secara bersamaan; seluruh model 782 baris sebelum/sesudah identik. Tidak ditambahkan indeks spekulatif, pembatasan hasil ekspor, penggantian renderer, atau perubahan layout.
6. Koreksi tabungan mempertahankan dua desimal pada validasi, formulir, pratinjau, payload persetujuan, SQL, dan saldo. Pengiriman berulang tetap hanya berlaku sekali; draft yang sudah dipakai dibersihkan. Nominal desimal pada Laporan Umum/PDF dipertahankan, sedangkan format bilangan bulat tetap sama.
7. Arsip referensi dapat dibuka dari database hasil pemulihan tanpa file pekerjaan dari komputer asal. Indeks row key dari keputusan publikasi versi 7/9/10 diperiksa strukturnya dan hasilnya dibandingkan persis dengan pemindaian lengkap arsip. Manifest yang tidak didukung atau tidak lengkap menggunakan pembacaan sumber penuh. Rekap keterangan histori tanpa tanggal pada Semua Unit kini menjumlahkan ketiga unit.
8. Kesiapan HTTP menolak skema impor yang belum lengkap, sehingga masalah struktur tidak lagi disajikan sebagai histori kosong. Tidak ada DDL otomatis melalui halaman web; SQL mode ketat dipertahankan.
9. Nilai pilihan operator Legacy sebelumnya diubah menjadi integer nol pada formulir laporan. Nilai kini mempertahankan ID string. Pembacaan pilihan juga memakai pembandingan byte untuk mencegah `DISTINCT`/`UNION` menghilangkan operator yang berbeda huruf besar/kecil atau spasi. Filter operator dan metode Legacy diuji dengan nominal sumber serta pilihan yang dirender pada SD, SMP, SMA, dan Semua Unit.
10. Screenshot phpMyAdmin menunjukkan batas waktu PHP 300 detik. SQL lengkap diuji melalui CLI agar pemulihan tidak bergantung pada batas tersebut. SQL mode ketat tetap aktif; pengujian kueri dan halaman akhir tidak mengulangi fatal `ONLY_FULL_GROUP_BY` yang tercatat sebelumnya.

## Rekonsiliasi data

| Data | SD | SMP | SMA | Semua Unit |
|---|---:|---:|---:|---:|
| Siswa | 961 | 1.076 | 252 | 2.289 |
| Pembayaran seluruh histori | 46.852 | 60.181 | 11.211 | **118.244** |
| Pembayaran tanggal 2022–2026 | **23.309** | **21.160** | **5.209** | **49.678** |
| Pembayaran DU tanpa tanggal | 3.064 | 5.084 | 1.140 | **9.288** |
| Jurnal tabungan | 59.091 | 27.546 | 8.938 | **95.575** |
| Rekening dengan rekonsiliasi saldo | 983 | 1.055 | 380 | **2.418** |

Total pembayaran 2022–2026 sebesar **Rp45.100.701.752** cocok antara header, komponen, pembaca Riwayat Pembayaran, Laporan Umum, dan Excel lengkap 49.678 baris. Adam pada contoh pengguna mempunyai **54 pembayaran** pada Semua Tahun.

Jurnal terdiri dari 95.542 jurnal Legacy, 32 penyesuaian, dan satu transaksi baru yang memang sudah ada pada dump. Transaksi baru tersebut sebesar Rp13.000; tidak dibuat ulang selama perbaikan. Saldo awal dan jurnal historis tidak dihitung dua kali.

Seluruh isi 60 tabel awal dibandingkan berdasarkan urutan primary key dan SHA-256 setiap baris setelah dump pengguna dipulihkan melalui CLI pada MySQL terisolasi. Hasilnya cocok, bukan hanya jumlah baris. Cadangan sebelum perbaikan juga dipulihkan dan dibandingkan. Setelah penerapan, **58 tabel asli identik**, perubahan pada dua tabel hanya metadata batch dan tiga entri audit. Dua tabel tabungan yang ditambahkan kosong. Nominal pembayaran, jurnal, saldo, tarif, tanggal, dan identitas tidak ditulis ulang. Snapshot akhir database lokal tetap sama setelah pengujian halaman/ekspor.

## Status modul

| Modul | SD | SMP | SMA | Semua Unit | Bukti yang diperiksa |
|---|---|---|---|---|---|
| Input/riwayat pembayaran | Lulus | Lulus | Lulus | Lulus | Histori siswa, jumlah, filter tahun/tanggal, pagination, detail, struk, transaksi Legacy terpilih |
| Riwayat daftar ulang | Lulus | Lulus | Lulus | Lulus | Pemilik/periode, tarif, pembayaran tanpa tanggal, sisa menurut bukti, Excel |
| Data Master | Lulus | Lulus | Lulus | Lulus | Siswa, rombel/kelas khusus/alumni, penerbitan SPP, biaya lain, DU, penghitung histori dan relasi unit |
| Tabungan | Lulus | Lulus | Lulus | Lulus | Masuk/keluar, histori, rekening non-siswa, saldo, buku dan struk PDF, Excel |
| Laporan Umum | Lulus | Lulus | Lulus | Lulus | Header/komponen/histori gabungan, filter dan pilihan laporan, tiga lembar Excel, PDF |
| Laporan Global | Lulus | Lulus | Lulus | Lulus | Seluruh 10 template, jumlah baris dan total lintas unit, PDF/Excel |
| Surat laporan | Lulus | Lulus | Lulus | Lulus | Kepala sekolah dan surat orang tua dengan tunggakan yang terbukti |

Data tanpa tanggal tetap dapat dibuka pada pilihan histori tanpa tanggal di menu daftar ulang; data tersebut tidak diberi tanggal buatan agar masuk filter kalender. Sebanyak 14 DU negatif, 7 jurnal nol, dan satu setoran Rp10.000 tanpa identitas tidak dipaksakan menjadi penerimaan/refund atau milik siswa. Semuanya tersimpan dalam arsip asli. Halaman referensi menampilkan 24 catatan SD, 11 SMP, dan 6 SMA, termasuk referensi kosong lain.

Arsip referensi untuk Super Admin pada unit yang sesuai:

- SD: `legacy_history.php?id=9a01b74b5db3b68bc2bf3da55bcef524&kind=all`
- SMP: `legacy_history.php?id=12924a6b4da734f17a343e3c830ba0cb&kind=all`
- SMA: `legacy_history.php?id=77010ef091594597a7359c2e47a09ea6&kind=all`

## Bukti pengujian

- 31 pemeriksaan model/database pada salinan hasil pemulihan; dilanjutkan **37 pemeriksaan hanya baca** pada database lokal akhir, seluruhnya lulus. Mencakup seluruh unit, rekening siswa/non-siswa, jumlah/nominal setiap template, isolasi unit, konteks audit, kesetaraan partisi bukti tagihan, proyeksi rekap tabungan, serta filter operator/metode Legacy. Penolakan CHECK negatif diuji pada salinan terisolasi.
- 121 permintaan HTTP terhadap database lokal yang sudah diperbaiki; tidak ada kegagalan. Pemeriksaan laporan juga menolak pesan gagal tersembunyi di respons HTTP 200.
- Seluruh 10 template × 4 cakupan × PDF/Excel, ditambah Laporan Umum, dua jenis surat, dan cetak Legacy terpilih. File dibuka kembali; nama dan total dibandingkan dengan model sumber. Perbaikan fixture surat memakai siswa yang benar-benar mempunyai tunggakan serta status siswa yang sesuai, bukan mengubah data agar surat bisa dicetak.
- Setelah optimasi terakhir, 121 permintaan HTTP diulang dan delapan PDF/Excel SPP Tahunan pada empat cakupan dibaca kembali; seluruhnya lulus. Snapshot akhir masih identik dengan seluruh 62 tabel dan objek skema setelah perbaikan.
- 12 pemeriksaan dokumen tabungan: buku lengkap dan struk untuk siswa serta non-siswa pada ketiga unit. Jumlah tanggal dan setiap nominal buku dibandingkan dengan seluruh entri model, tanpa memotong buku.
- **22 kasus Chrome**: pembayaran, DU, tabungan, dan laporan di empat cakupan; Adam 54 baris; tampilan ponsel/tema gelap; serta operator/metode Legacy yang tetap terpilih setelah pemuatan ulang. Tidak ada kesalahan JavaScript; tampilan hasil diperiksa secara visual.
- 48 pemeriksaan HTTP transaksi native dan 27 pemeriksaan transaksi Legacy pada instance terisolasi: pembayaran/edit/batal, setoran/penarikan, pengiriman ulang, versi usang, saldo tidak cukup, dan penolakan akses lintas unit. Tiga pengujian campuran native/Legacy memakai rollback dan memeriksa potongan serta sen.
- 15 pemeriksaan presisi nominal, 20 pemeriksaan cache termasuk lima ekspor bersamaan yang hanya merender sekali, tes buku, pengelompokan tagihan, tanggal, PDF buku kosong/panjang, lint PHP/JavaScript/Python, dan `git diff --check` lulus.
- Alat perbaikan dijalankan ulang pada salinan hasil pemulihan: tidak menambahkan constraint atau entri perbaikan metadata lagi.
- phpMyAdmin dibuka dengan sesi pengujian terpisah pada server lokal: halaman struktur `db_spp_test2` terautentikasi memberi HTTP 200 tanpa fatal error, sekitar **0,52 detik**. `health.php` pada Apache lokal juga HTTP 200 `ok`.

## Performa

Pengukuran memakai PHP 8.1.10 dan MySQL 8.0.30 lokal. Rincian waktu per permintaan, ukuran file, jumlah halaman, dan Server-Timing berada pada `performance-complete.json`, `performance-full-history.json`, `document-results.json`, `document-final-spp.json`, `savings-documents.json`, dan `http-results.json` di direktori bukti. Pengukuran akhir dilakukan terpisah setelah server database pengujian dihentikan.

Pengambilan 5.522 jurnal turun dari sekitar 8,45 detik menjadi 0,28 detik. Model laporan siswa yang sama turun dari sekitar 17,62 detik menjadi 1,17 detik. Arsip referensi lengkap turun menjadi sekitar **0,12–0,33 detik**, dengan hasil yang sama persis seperti pembacaan sumber lengkap sebelumnya. Excel pembayaran lengkap 49.678 baris terbaca kembali dalam sekitar 6,6 detik pada pengujian HTTP; tidak dibatasi ke halaman pertama.

Pengukuran akhir terpisah berada pada `performance-complete.json` dan `performance-full-history.json`:

| Cakupan pengukuran | Waktu |
|---|---:|
| 20 halaman umum: pembayaran, DU, siswa, tabungan, Laporan Umum pada empat cakupan | 0,167–2,757 detik |
| Status Pembayaran Semua Unit, dua pengukuran | 2,525–2,742 detik |
| SPP Tahunan Semua Unit, dua pengukuran | **0,992–1,056 detik** |
| Transaksi Tabungan Semua Unit, dua pengukuran | 2,751–2,801 detik |
| Surat kepala sekolah / daftar surat orang tua Semua Unit | 1,800–2,107 detik |
| Excel saldo SD, kurang dari 1.000 baris | 1,083 detik |
| PDF saldo SD lengkap, **33 halaman**, render pertama | **10,203 detik** |
| PDF yang sama, permintaan berikutnya dengan model tetap diperiksa ulang | **0,668 detik** |
| Excel seluruh pembayaran, **118.244 baris** | **10,334 detik** |
| Excel seluruh jurnal tabungan, **95.575 baris** | **11,075 detik** |

Target halaman umum ≤3 detik tercapai pada pengukuran ini. PDF 33 halaman pada render pertama masih sedikit di atas target ekspor biasa ≤10 detik: sekitar 9,42 detik dipakai renderer existing. Cache mempercepat permintaan berikutnya dengan memeriksa ulang model, hak akses, cakupan unit, hash berkas, dan masa berlaku. Renderer maupun layout dipertahankan; waktu pada komputer lain atau beban serentak dapat berbeda.

Kedua Excel seluruh histori dibuka kembali sampai baris terakhir. Jumlah baris, nominal pembayaran, setoran, penarikan, dan penyesuaian dicocokkan per SD/SMP/SMA dengan agregasi sumber independen menggunakan desimal tepat. Nilai sen seperti saldo/jurnal SMP tidak dibulatkan menjadi rupiah penuh. Tidak ada pemotongan ke halaman yang sedang ditampilkan. Total pembayaran seluruh histori sebesar **Rp105.802.515.504**.

## SQL tervalidasi, cadangan, dan pemulihan

Direktori privat di luar web root:

`C:\laragon\backups\spp-management-system\import_audit_20261020`

File utama:

- `db_spp_test2-validated.sql`: SQL lengkap setelah perbaikan, termasuk routine, trigger, view, FK, dan CHECK. **Sudah dipulihkan pada MySQL terisolasi dan seluruh 62 hash tabel serta seluruh objek skema cocok dengan database lokal.**
- `pre-apply.sql` / `before.sql`: database sebelum perbaikan; cadangan sebelum perbaikan sudah diuji pemulihannya.
- `source-before.zip`, `koneksi-before.php`, `local-before.patch`: kode dan konfigurasi sebelum pengambilan pembaruan/perbaikan.
- `source-validated.zip` dan `artifact-manifest.json`: salinan kode akhir dan checksum artefak.
- `repair-live.log`, `integrity-live.log`, `final-check.log`, `live-change-proof.json`, snapshot, hasil tes, dan folder `artifacts`: bukti rinci. File berisi data sekolah disimpan privat, tidak dimasukkan ke repositori.

Dump asli pengguna di Downloads dipertahankan. Gunakan SQL tervalidasi untuk pemulihan lengkap berikutnya melalui CLI, sehingga tidak bergantung pada batas waktu impor phpMyAdmin. Tidak perlu mengimpor ulang pada database lokal yang sekarang sudah diperbaiki.

Pemeriksaan ulang hanya baca:

```powershell
$env:SPP_DB_HOST='127.0.0.1'
$env:SPP_DB_PORT='3306'
$env:SPP_DB_NAME='db_spp_test2'
& 'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe' scripts/repair_imported_database.php --check --expect-db=db_spp_test2
& 'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe' tests/readiness_integrity_audit.php
```

Penerapan CLI memerlukan `--apply --expect-db=db_spp_test2 --confirm-maintenance --backup-file=...`, cadangan privat, dan file `tmp/financial_migration.lock`; alat juga mengunci akses database aplikasi agar permintaan aktif selesai terlebih dahulu. Pemeriksaan data/constraint dilakukan sebelum DDL, pemasangan bersifat aditif dan dapat diulang, serta perbaikan metadata memakai transaksi dan audit. Jangan menjalankan migrasi data ulang sebagai pengganti perbaikan skema.

Untuk rollback penuh: hentikan input, cadangkan keadaan terbaru terlebih dahulu, aktifkan pemeliharaan, lalu pulihkan cadangan yang dipilih melalui CLI pada database tujuan yang telah diperiksa. Contoh perintah pemulihan untuk administrator:

```powershell
& 'C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe' --host=127.0.0.1 --port=3306 --user=root db_spp_test2 --execute='SOURCE C:/laragon/backups/spp-management-system/import_audit_20261020/pre-apply.sql'
```

Pemulihan tersebut mengembalikan keadaan pada waktu cadangan, termasuk mengganti transaksi sesudahnya. Cocokkan kode dengan versi cadangan: ekstrak `source-before.zip` ke folder pemeriksaan terpisah dan gunakan konfigurasi `koneksi-before.php`; jangan menimpa perubahan lokal lain tanpa meninjaunya. Jalankan kembali pemeriksaan skema, rekonsiliasi, dan halaman sebelum mengakhiri pemeliharaan.

Pengujian ini tidak mencakup printer fisik, beban banyak pengguna serentak, atau server lain. Status “Lulus” merujuk cakupan dan bukti di atas; pemeriksaan berbasis bukti tetap diperlukan ketika menambah migrasi atau mengubah data di luar alur aplikasi.
