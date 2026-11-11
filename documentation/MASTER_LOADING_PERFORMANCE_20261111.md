# Performa Master SPP dan Master Daftar Ulang

11 November 2026. Pembanding kode: `fc4e703`. Target localhost: `db_spp_test3`.

## Perubahan

- Master SPP mengambil rencana PSB beserta hubungan penempatannya secara kelompok.
  Pembaca langsung dan pembaca kelompok memakai pembentuk baris penerimaan yang sama.
  Pencarian rencana satu per siswa pada pemuatan halaman dihilangkan.
- Satu hasil agregasi tagihan/alokasi dipakai untuk pratinjau tarif serta penghitung
  tagihan. Snapshot cakupan Legacy dari roster dipakai kembali. Identitas pada
  tagihan yang tidak ada di roster tetap disertakan untuk perhitungan kredit.
- Profil GET SPP membatasi pemilik dan dua tahun kalender kandidat, kemudian memakai
  parser periode existing untuk menentukan tahun kewajiban yang tepat. Bukti tarif
  bulanan, pembatalan, kredit dan header sumber tetap mengikuti engine existing.
  Nama bulan, spasi, tab, newline dan nol di depan NIS tetap terbaca.
- Profil GET DU hanya membaca kandidat pembayaran DU untuk pemilik terkait.
  Rincian dengan beberapa tahun kewajiban tetap dipisahkan menurut metadata item;
  tanggal kas tidak dijadikan filter. Bukti tarif sumber seluruh tahun yang diperlukan
  untuk keputusan tahun sumber terbaru tetap tersedia.
- Pemeriksaan kolom, FK, prosedur dan trigger PSB, proyeksi dan OUT menggunakan
  katalog metadata kelompok. Seluruh kondisi validasi sebelumnya dipertahankan.
  Katalog dipakai bersama selama HTTP GET; POST dan installer CLI memeriksa ulang.
- Cache hanya hidup selama request. Profil bukti mengikat koneksi/database, unit,
  tahun, komponen dan identitas. Profil ditolak pada POST; pembaca transaksi tetap
  menggunakan model lengkap, lock, invalidasi dan pemeriksaan versi existing.

Tidak mengubah endpoint, respons publik, formulir, markup tampilan, CSS atau
JavaScript aplikasi. Tidak menambahkan indeks, migrasi, tabel ringkasan atau cache
lintas request. Daftar sumber kontrak skema menyertakan helper katalog baru.

## Benchmark HTTP akhir

Kedua versi berjalan melalui router audit yang sama pada server salinan MySQL,
PHP 8.3.33 dan MySQL 8.4.3. Setiap skenario merekam satu request pertama secara
terpisah, lalu lima pengulangan. Tabel berikut memakai median pengulangan untuk
Super Admin, tahun 2026/2027. Request pertama bukan klaim buffer database dingin;
buffer server tidak dikosongkan.

| Unit | Halaman | Server sebelum → sesudah | Lebih cepat | Query sebelum → sesudah | Memori sebelum → sesudah |
|---|---|---:|---:|---:|---:|
| SD | SPP | 1.543,7 → 562,0 ms | 63,6% | 1.056 → 105 | 106 → 48 MB |
| SD | DU | 961,8 → 189,0 ms | 80,4% | 202 → 88 | 24 → 12 MB |
| SMP | SPP | 1.747,7 → 491,8 ms | 71,9% | 832 → 105 | 116 → 46 MB |
| SMP | DU | 1.147,6 → 188,0 ms | 83,6% | 202 → 88 | 30 → 14 MB |
| SMA | SPP | 532,5 → 216,2 ms | 59,4% | 362 → 105 | 32 → 16 MB |
| SMA | DU | 402,0 → 133,6 ms | 66,8% | 202 → 88 | 10 → 8 MB |
| Semua Unit | SPP | 226,3 → 106,6 ms | 52,9% | 160 → 50 | 6 → 8 MB |
| Semua Unit | DU | 217,6 → 108,1 ms | 50,3% | 160 → 50 | 6 → 8 MB |

Pada SD, median TTFB SPP 1.565,3 → 582,7 ms dan DU 965,2 → 192,2 ms.
Jumlah query SPP turun 90,1%, melewati target 70%. Jumlah query tahun berjalan
sesudah perubahan sama pada tiga unit meskipun jumlah siswanya berbeda.
Katalog metadata menambah sekitar 2 MB pada tampilan Semua Unit yang kecil;
waktu request tetap berkurang.

Seluruh **64 skenario / 768 request** lulus: dua peran × empat cakupan unit ×
empat tahun × dua halaman × enam request × dua versi. Tahun yang diuji:
2026/2027, 2027/2028, 2025/2026 dan tahun kosong 2188/2189. Seluruh median
server membaik; perbaikan terkecil 37,2%. Tidak ada skenario kontrol melewati
ambang perlambatan 10%. Data model, seluruh versi pratinjau tarif, serta HTML
cocok persis setelah hanya menormalkan timestamp versi berkas aset.

Waktu mengambil dan mengurai cursor arsip tercatat pada fase `billing_payments`.
Angka waktu SQL sendiri tidak mencakup seluruh konsumsi cursor streaming;
penilaian penerimaan memakai waktu server keseluruhan, TTFB dan waktu respons.
Angka benchmark adalah hasil mesin/salinan ini, bukan jaminan waktu tetap pada
semua kondisi perangkat, buffer dan beban pengguna.

## Hasil verifikasi

| Pemeriksaan | Hasil |
|---|---|
| Pembaca lengkap dibanding profil master | 128.222 pemeriksaan bukti bulanan, kredit SPP/DU, periode, cakupan, POST dan kontrak skema lulus |
| Pratinjau GET ke writer POST | 114 pemeriksaan dua peran/tiga unit: baris PSB, tarif nol, versi sama, simpan sah, versi kedaluwarsa ditolak dan pembatalan manual terlindungi |
| Tahun tertutup | Model dan HTML dibanding pada transaksi native yang dibatalkan kembali, kedua halaman/peran dan tiga unit; kontrol kembali membuka tahun tetap tersedia |
| SPP mendatang | 248 pemeriksaan siklus, tarif individu, urutan, pembayaran, laporan dan kenaikan lulus; rollback |
| PSB SPP | 142 pemeriksaan pembayaran, penempatan, rollover, laporan dan isolasi unit lulus; rollback |
| OUT | 240 pemeriksaan periode, kembali aktif, kredit dan perlindungan finansial lulus; rollback |
| DU–PSB dan DU mendatang | 66 dan 123 pemeriksaan transaksi lulus, termasuk penolakan tahun tertutup; rollback |
| SPP/Komite reguler | Penerbitan ulang, pembayaran bulanan, pasangan, nominal dan snapshot lulus |
| Tarif historis | Perubahan tarif setelah kenaikan tidak mengubah tahun asal; lulus dengan sesi aktor Super Admin yang sah |
| Penempatan PSB | Tiga unit, NIS sama/nol di depan, parsial, replay dan Semua Unit baca saja lulus |
| Tarif nominal | 23 kasus tarif akhir dan pemeriksaan konsistensi komponen lulus |
| Instalasi baru | Skema baru, bootstrap multiunit, seed pembayaran dan penerapan ulang tanpa duplikasi lulus |
| Browser | 24 pasangan viewport: dua halaman, tiga unit, dua tema, 1440/390 px; tab, keyboard, pencarian, dialog/Escape, hash lama, overflow dan console lulus |
| Localhost | Login existing, health HTTP 200, kedua master pada tiga unit dan console: 9 pemeriksaan lulus |
| Sintaks | Seluruh PHP yang berubah, dua skrip JavaScript tes, JSON kontrak dan `git diff --check` lulus |

Gate pelestarian UI **PASS**: template HTML kedua halaman tetap identik dengan
kode pembanding setelah normalisasi line ending. SHA-256 bagian template:

- SPP: `70660772a4470d1d697a690275d62b673381e44048cbb22802581fcc176b33d9`.
- DU: `414c6edb30aaa0ed0ca904366614226ac26094c3f10c3a2cf25baf7f2f8bd793`.

Tidak ada perubahan berkas aset aplikasi. Screenshot direkam sebelum interaksi
yang memindahkan fokus/scroll; jam dibekukan dan motion dinonaktifkan hanya pada
harness. Tautan anchor lama diuji terpisah dari screenshot posisi atas.
20 pasangan identik byte; empat pasangan hanya berbeda pada rasterisasi tepi:
delta maksimum aktual 17/255 dan paling banyak sembilan piksel di atas delta 2.
Gate mengizinkan paling banyak 0,001% piksel di atas delta 2, dengan delta maksimum
32. Tidak ada perubahan geometri atau konten yang ditoleransi oleh pembandingan HTML.

Probe localhost SD memakai tiga request per halaman: SPP 829–1.353 ms, DU
184–203 ms, semuanya HTTP 200. Probe ini terpisah dari benchmark dua versi;
tidak dipakai sebagai angka pembanding sebelum/sesudah.

## Perlindungan data dan penerapan

Backup lengkap target: 624.056.297 byte, SHA-256
`f62482bc6b57e23971e6150ffdfc7f92330454898c60b1712ab590bb6f82377a`.
Identitas sumber: port 3306, UUID `e6e5c8d8-b81c-11f1-9be5-745d2224b37e`.
Backup dipulihkan ke server 3320 dengan UUID/datadir berbeda dan scheduler OFF.
Seluruh 67 checksum tabel dan snapshot terlindungi cocok sebelum fixture.

Salinan pertama memakai default kolasi berbeda. Masalah itu terdeteksi pada
pengujian tulis; pengujian rilis diulang pada `db_spp_audit_masterperf2` dengan
kolasi database dan trigger sama seperti target, `utf8mb4_general_ci`.
Server utama dan event scheduler utamanya tidak diubah.

Sesudah pengujian, seluruh **67 checksum tabel target tetap identik** dengan
sebelum pekerjaan. Pada salinan, 66 tabel selain admin serta seluruh baris admin
asal tetap identik. Satu akun Administrator fixture hanya ditambahkan pada salinan
untuk menguji peran kedua. Identitas siswa, transaksi, kredit, arsip, saldo dan
jurnal Tabungan tetap sama. Tidak menjalankan impor atau rekonsiliasi Legacy.

Tes arsip CLI memakai `memory_limit=1536M`; konfigurasi memori aplikasi tidak
diubah. Konfigurasi lokal `koneksi.php`, `.gitignore`, dump dan cache pengguna
dipertahankan dan tidak disertakan dalam commit perubahan ini.

## Menjalankan ulang

Gunakan restore terverifikasi pada server berbeda dengan scheduler OFF. Simpan
dump, kredensial, sesi dan hasil di direktori privat di luar webroot.

1. Siapkan dua checkout sumber sebelum/sesudah dengan vendor existing, mengarah
   ke salinan yang sama melalui `SPP_DB_NAME`, `SPP_DB_HOST`, `SPP_DB_PORT`.
2. Set `SPP_TEST_ALLOW_MUTATION=1` dan `SPP_PERF_AUDIT_ROOT`, lalu jalankan
   `php tests/master_loading_fixture.php`. Akun fixture tidak memiliki password
   yang dicetak atau dibagikan; sesi privat dipakai untuk benchmark.
3. Jalankan dua PHP development server pada loopback memakai
   `tests/master_loading_router.php`, `session.save_path` ke subdirektori `sessions`,
   `SPP_REQUEST_METRICS=1`, serta `SPP_PERF_WEBROOT` dan
   `SPP_PERF_VARIANT=before|after`. Router menolak POST/endpoint lain dan
   membungkus halaman dalam transaksi baca saja.
4. Jalankan `node tests/master_loading_http_test.js`. URL default 8131/8132 dapat
   diganti melalui `SPP_PERF_BEFORE_URL`/`SPP_PERF_AFTER_URL`.
5. Untuk browser, set `SPP_PLAYWRIGHT_PATH` lalu jalankan
   `node tests/master_loading_browser_test.js`. Tes model/versi juga tersedia pada
   `tests/master_read_model_test.php` dan `tests/master_preview_write_test.php`.

Artefak aktual privat: `C:/laragon/backups/spp-management-system/masterperf_20261111/`.
`benchmark.json` memuat request pertama dan median server/TTFB/respons/query/memori
per skenario; `browser-result.json`, `closed-read-result.json`,
`restore-exact-proof.json`, `data-verification.json` dan log masing-masing tes
menyimpan bukti pendukung. HTML/model mentah mengandung data siswa dan tidak
dimasukkan Git.
