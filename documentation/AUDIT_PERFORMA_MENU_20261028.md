# Audit performa menu — 28 Oktober 2026

## Ruang lingkup dan hasil

Perubahan ini mempercepat pembacaan menu tanpa mengganti aturan pembayaran,
nominal, status, urutan hasil, formulir, atau susunan laporan. Jalur publik dan
menu tetap memakai alur existing. Tidak ada penerbitan tagihan atau migrasi ulang
data keuangan dalam pekerjaan performa ini.

Matriks HTTP mencakup Dashboard, Input Pembayaran, Riwayat Pembayaran, Riwayat DU,
Otorisasi Transaksi, Data Siswa, Master Kelas/SPP/Biaya Lain/DU, Tabungan
Masuk/Keluar/Riwayat/Cetak, katalog laporan, Laporan Global, Surat Laporan, Surat
Orang Tua, manajemen peran, serta Backup/Restore. Sepuluh tampilan laporan
meliputi status, penerimaan, SPP tahunan, per item, riwayat tagihan, tabungan siswa,
saldo tabungan, setoran, kas tabungan, dan tunggakan siswa. Total 120 kombinasi
halaman/unit mencakup SD, SMP, SMA, dan Semua Unit. Ini bukan klaim pengujian
setiap kemungkinan kombinasi filter atau seluruh kapasitas server produksi.

## Perubahan teknis

- Perhitungan yang hanya diperlukan untuk penerbitan/mutasi tidak dibangun ulang
  oleh pembaca laporan. Profil baca dan profil lengkap memiliki kunci cache
  permintaan berbeda; proses refresh tetap membatalkannya.
- Periode Legacy, konversi tahun, konstanta komponen, dan hasil perencana yang
  identik digunakan ulang **dalam satu permintaan**. Tidak ada cache finansial
  dengan TTL lintas permintaan: saldo, kredit, dan pembayaran tetap dibaca baru.
- Kueri bulanan membatasi penempatan pada tahun yang diminta dan tagihan pada
  pasangan bulan/tahun kalender sebelum mengambil data. Konversi kode bulan
  mempertahankan nilai `01`–`12`, termasuk kolom bertipe ENUM.
- Pilihan tahun Legacy dan riwayat DU membatasi ekspansi JSON yang tidak relevan.
  Jalur metadata lama, kategori escaped/Unicode, pembatalan, dan kredit tetap
  didukung. Reader rekonsiliasi DU lengkap tetap tersedia dan menjadi default.
- Ringkasan tunggakan menyaring peserta/periode yang memang memenuhi syarat
  lebih awal. Baris histori tanpa bukti pokok/sisa tetap muncul pada riwayat;
  baris tersebut tidak diubah menjadi tunggakan atau tagihan nol.
- Surat Orang Tua menggunakan kembali daftar tunggakan yang sama bila filter
  kelas/pencarian efektif identik, sehingga tidak menghitungnya dua kali.
- GET baca tertentu melepas kunci sesi setelah autentikasi, token CSRF, flash,
  dan draf selesai diproses. Halaman yang membuat konteks tautan koreksi saat
  render tetap menyimpan sesi. POST dan validasi transaksi tidak dilonggarkan.
- Matriks Riwayat Tagihan mengagregasikan bulan dengan identitas, kelas, tahun,
  nominal, pembayaran, dan status yang sama sebelum membangun kelompok siswa.
  Hitungan detail tetap dipertahankan. API/model lengkap tetap menghasilkan
  baris individual. Filter multibagian, nominal pecahan, atau magnitude di luar
  rentang penjumlahan integer float yang tepat memakai pembaca lengkap lama.
- GET matriks riwayat dapat memakai maksimal dua koneksi baca pada UUID/database
  yang sama dengan konteks unit, charset, collation, SQL mode, dan zona waktu
  yang cocok. Ekspor dan CLI tetap memakai koneksi/snapshot asal. Kegagalan
  koneksi tambahan kembali ke pembacaan berurutan.

Empat indeks baca tambahan, tanpa perubahan kolom finansial:

| Tabel | Indeks | Kolom |
|---|---|---|
| `legacy_op_journals_data` | `idx_menu_journal_date` | `unit_id,source_date,owner_id` |
| `legacy_op_journals_data` | `idx_menu_journal_operator` | `unit_id,batch_id,source_operator,origin` |
| `legacy_op_payments_data` | `idx_menu_payment_operator` | `unit_id,batch_id,source_operator` |
| `legacy_op_payments_data` | `idx_menu_payment_period` | `unit_id,cancelled,source_period,batch_id` |

Prototipe indeks fungsional JSON tidak dipakai optimizer dan telah dilepas.
Hasil akhir hanya empat indeks di atas. Kompatibilitas skema backup menoleransi
hanya deklarasi indeks opsional yang persis dikenal; perubahan kolom, indeks
yang bertentangan, dan isi literal SQL tetap tidak diloloskan.

## Bukti bahwa output tetap sama

Baseline kode adalah commit `333eb1a9d01f676f291eada3f2674df8ccde0727`.
Perbandingan dilakukan terhadap checkout privat kode tersebut dengan data dan
vendor yang sama pada server salinan.

| Pemeriksaan | Hasil |
|---|---|
| Model laporan, DU, tunggakan dan detail siswa | 104 kontrak identik pada empat cakupan unit |
| Matriks riwayat, urutan, filter/status, hitungan dan total | 256 pemeriksaan kelompok identik |
| Pembaca bukti transaksi DU lama/baru | 4 kontrak identik, 12.920 baris pada Semua Unit |
| Metadata kategori escaped, Unicode dan variasi huruf | 24 pemeriksaan lulus |
| HTML: teks, kontrol, tautan, dan urutan | 98 perbandingan identik |
| Browser desktop 1440/light dan ponsel 390/dark | 60 pemeriksaan halaman/dropdown, tanpa galat JavaScript |
| PDF dan Excel | 44 pasangan dokumen sebelum/sesudah cocok; termasuk teks/halaman PDF dan nilai, tipe, format, merge Excel |
| Sesi tautan koreksi/pembatalan | 4 konteks hasil render tersimpan dan dapat dibaca kembali |
| Indeks, idempotensi dan kompatibilitas backup | 14 pemeriksaan lulus |
| Nominal pecahan pada matriks riwayat | Fallback pembaca lengkap lulus pada fixture transaksi yang di-rollback |

Regresi tambahan pada server terisolasi meliputi DU–PSB (30 kebijakan dan 66
transaksi), DU mendatang (114 pemeriksaan dan 123 dengan pergantian kalender),
GUI DU mendatang (26) beserta pembaca Legacy-nya (83), Biaya Lain (79 integrasi,
57 siklus HTTP, dan konkurensi satu pembayaran/satu tagihan), billing digest (5),
model performa existing (265), sortir billing (1.400), presisi tabungan (15),
serta 16 pemeriksaan GUI/layanan tabungan: setoran, penarikan, kelebihan tarik,
replay, koreksi, pembatalan, dan kecocokan buku/saldo. Transaksi uji tidak
dijalankan pada database utama.

Dua tes lama belum lulus pada baseline maupun kode sesudah perubahan:
`savings_optional_integration` memakai asumsi akun/peran fixture lama;
`legacy_savings_precision_fix` berhenti pada `Precision policy failed` pada
keduanya. Keduanya dicatat sebagai keterbatasan suite lama; saldo/aturan tidak
diubah untuk memaksa tes lulus. Pengujian tabungan current di atas lulus.

## Pengamanan database dan penerapan

Target adalah `db_spp`, `127.0.0.1:3306`, UUID
`86e982af-c6db-11f1-9998-00e04ccb017f`. Backup sebelum pemasangan indeks memakai
runner existing, termasuk routines, triggers, events, dan storage privat. Backup
job `660dfb6120de0b51b19f0cbdd411e337` telah diuji pemulihannya pada server
terisolasi; SHA-256 SQL:
`d8eecada135b61be6c2cc48e9011da124af5a0a10f2012a9cdb74ff8bb6faaea`.

Snapshot akhir job `784845b7da0e32e0f06075e9e5779086` juga berhasil dipulihkan
dan cocok seluruh checksum/fingerprint, SHA-256:
`c0a6252a8431c9e73b3e50affcb38d3894f1a1acc9c2f99d3a4b653d93f84ad3`.
UUID server verifikasi `91f915e6-d27d-11f1-8e9a-00e04ccb017f`, berbeda dari target.
Snapshot ini kemudian dipulihkan pada salinan benchmark port 3319; seluruh
checksum tabel dan fingerprint finansial identik. Pengulangan installer resmi
pada salinan tersebut menghasilkan **index changes=0**, checksum seluruh tabel
identik, dan SHA seluruh kolom pembayaran Legacy tidak berubah. Pemeriksaan ini
diulang lagi setelah seluruh benchmark selesai dan kembali lulus; pembacaan
berulang tidak mengubah isi tabel salinan.

Database utama menerima transaksi tabungan masuk baru saat audit berlangsung.
Karena itu pengaman backup lama menolak penerapan ulang pada target yang sudah
berubah. Penolakan ini dipertahankan; database utama tidak dipulihkan ke snapshot
lama dan transaksi baru tidak ditimpa. Audit baca-saja terakhir pada target
lulus seluruh pemeriksaan relasi/nominal: 2.293 siswa, 4.918 penempatan, 575
tagihan DU, 207 cakupan PSB (83 SD, 103 SMP, 21 SMA), 6 pembayaran native, dan
1.268 rekening. Angka ini adalah snapshot
saat audit, bukan penguncian database terhadap transaksi pengguna berikutnya.

Buffer pool MySQL lokal ditingkatkan dari 128 MiB ke 1 GiB pada mesin RAM 16 GiB
dan disimpan melalui `SET PERSIST`. Ini perubahan konfigurasi server lokal,
bukan bagian yang otomatis ikut Git. Untuk mengembalikan hanya pengaturan ini:

```sql
RESET PERSIST IF EXISTS innodb_buffer_pool_size;
SET GLOBAL innodb_buffer_pool_size=134217728;
```

`koneksi.php` dan `.gitignore` tetap sama; hash masing-masing:

```text
1261762cd37f668aad6acee250d2fb41511a82a627bac7b3ae9781caae76e100
be4aef476832a5e1b3fe477ad82a38131e7b62e9c0af350236cb797f2d263cc7
```

Worker existing dimulai ulang dengan kode terbaru; heartbeat siap pada versi
workflow 15, operasional 19, otomasi 5, billing 7, DU 4, bulanan 2, biaya lain 1.
Audit integritas baca-saja target dan kesiapan indeks lulus lagi sesudah benchmark.
Layanan HTTP/MySQL khusus pengujian sudah dihentikan; database utama, Apache,
dan worker operasional dipertahankan. Datadir serta bukti pengujian tidak dihapus.
Pengaturan MySQL lokal, backup, dan artefak privat tidak disertakan dalam commit repo.

## Menjaga performa setelah update SQL/kode

Data INSERT/UPDATE otomatis memelihara indeks MySQL. Restore melalui GUI/runner
existing memverifikasi isi backup terlebih dahulu, kemudian memasang empat
indeks yang belum tersedia. Tidak ada ALTER TABLE pada GET halaman.

Untuk impor manual yang mengganti tabel, jalankan pemeriksaan berikut memakai
PHP/config database tujuan yang benar:

```powershell
php scripts/migrate_menu_performance.php --strict
```

Default hanya membaca rencana. Jika indeks hilang, buat dan verifikasi restore
backup baru dengan alur existing, lalu gunakan bukti yang masih cocok:

```powershell
php scripts/migrate_menu_performance.php --apply --strict --backup-proof=C:/lokasi-privat/bukti-backup.json
```

Installer menolak backup/identitas/fingerprint yang tidak cocok. Jangan menghapus
pengaman ketika transaksi baru sudah masuk; buat bukti baru pada waktu
pemeliharaan yang sesuai. Pengujian mutasi selalu membutuhkan manifest server
salinan dengan UUID, port, database, dan identitas HTTP yang diperiksa.

Hasil pengujian ini tidak menjamin waktu muat tetap konstan untuk pertumbuhan
data tanpa batas, SQL manual yang mengubah struktur, atau pembaruan kode yang
mengembalikan kueri mahal. Ulangi matriks baca, kontrak output, dan pengukuran
setelah perubahan signifikan. Tidak ada pelonggaran validasi finansial untuk
mengejar angka performa.

## Pengukuran dan batas interpretasi

Pengukuran memakai HTTP lokal, PHP 8.1.10, MySQL 8.0.30 dan memory limit HTTP
512 MiB. CLI perbandingan model lengkap memakai batas lebih besar dan tidak
dianggap bukti penggunaan memori halaman. Lima pembaca bersamaan memakai lima
sesi autentikasi terpisah dari aktor Super Admin yang sama, bukan lima akun.
P95 dihitung dengan nearest rank; tiga sampel matriks hanya menunjukkan variasi
kecil, sedangkan rute utama dan konkurensi diulang 30 kali. Ukuran badan HTTP
dan waktu menyelesaikan respons ikut dihitung.

Pengukuran awal satu kunjungan untuk SD dengan konfigurasi lama: Riwayat DU 17,50 detik,
Master DU 13,62 detik, rekap status 11,58 detik, SPP tahunan 9,20 detik, riwayat
tagihan 18,18 detik, dan tabungan siswa 13,30 detik. Ini **satu sampel awal**,
bukan p95 dan bukan eksperimen terkontrol yang memisahkan dampak tuning server.

Hasil HTTP SD setelah optimasi, masing-masing 30 sampel (milidetik):

| Halaman/aksi baca | Median | P95 |
|---|---:|---:|
| Master SPP | 1.860 | 1.906 |
| Master DU | 3.389 | 3.447 |
| Riwayat DU | 2.901 | 3.199 |
| Katalog Laporan Global | 64 | 71 |
| Rekap Status | 1.402 | 1.475 |
| Riwayat Tagihan | 3.821 | 3.898 |
| Tabungan Siswa | 1.947 | 1.969 |
| Detail SPP siswa pada Input Pembayaran | 303 | 311 |

Ini waktu respons HTTP lengkap, bukan waktu seluruh interaksi/screenshot
browser. Pemeriksaan browser mencakup pembukaan kontrol sehingga durasinya
tidak disamakan dengan tabel HTTP. Riwayat DU Semua Unit memiliki median 6.245
ms dan p95 6.399 ms; Riwayat Tagihan Semua Unit median 8.118 ms dan p95 8.616
ms; katalog Laporan Global Semua Unit median 65 ms dan p95 70 ms.
Artinya target navigasi dua detik belum terpenuhi untuk setiap halaman
berat, meskipun beban pembaca sudah berkurang. Angka mentah dan hasil matriks
tersimpan pada artefak privat, termasuk hasil yang lebih lambat.

<!-- FINAL_LOAD_RESULTS -->
### Lima sesi dan perpindahan menu

Masing-masing kondisi berikut diulang 30 kali. Lima sesi memakai HTTP worker
terpisah pada server salinan yang sama.

| Kondisi | Median HTTP (ms) | P95 HTTP (ms) |
|---|---:|---:|
| 1/laporan/template.php [status] | 1639 | 1770 |
| 2/master_daftar_ulang.php | 2822 | 3115 |
| 3/pembayaran/form.php | 119 | 144 |
| 0/laporan/global.php | 85 | 100 |
| 1/tabungan/cetak.php | 763 | 857 |
| same-session/catalogue-during-report | 70 | 89 |
| same-session/report | 3783 | 3944 |

### Pembacaan fixture native 5×

Semua 120 kombinasi halaman/unit selesai dengan HTTP 200 tanpa galat laporan
pada memory limit HTTP 512 MiB. Pengukuran ini **satu probe per kombinasi**,
bukan p95 statistik. Lima pembacaan paling lambat:

| Cakupan/halaman | Waktu HTTP satu probe (ms) |
|---|---:|
| 0/laporan/template.php [riwayat-tagihan] | 14238 |
| 0/laporan/template.php [tunggakan-siswa] | 11912 |
| 0/laporan/surat_orang_tua.php | 11764 |
| 0/laporan/template.php [per-item] | 7984 |
| 0/laporan/template.php [spp-tahunan] | 7978 |

Matriks current lengkap memiliki 120 kombinasi × 3 sampel; rute utama 15 × 30,
lima sesi 5 × 30, dan dua pembacaan sesi yang sama 2 × 30. P95 lintas unit
Rekap Status adalah 5906 ms.
Target dua detik untuk seluruh navigasi dan lima detik untuk seluruh rekap
current belum tercapai secara menyeluruh. Tidak ada data yang dipotong atau
validasi transaksi yang dihilangkan untuk menutupi hasil ini.
<!-- /FINAL_LOAD_RESULTS -->

Fixture pertumbuhan menggandakan data native 5 kali: 11.465 siswa, 24.590
penempatan, 262.490 SPP, 260.225 Komite, dan 2.875 DU. Arsip Legacy serta
tabungan tidak digandakan. Pembaca matriks menghindari materialisasi ratusan ribu
baris bulanan pada PHP 512 MiB, tetapi riwayat penuh lintas unit pada fixture ini
masih berat. Tidak semua ambang target tercapai. Ekspor PDF seluruh histori,
filter multibagian dan fallback nominal pecahan juga tetap dapat memerlukan
waktu lebih lama; hasil finansial diutamakan daripada pemotongan data.

## Artefak privat

Seluruh backup, data pribadi, respons HTTP, screenshot, fixture, log mentah dan
hasil dokumen disimpan di luar webroot:
`C:/laragon/data/spp-performance/menu_speed_20261027/`.

File penting: `finish-validation.log`, `finish-index-replay.log`,
`finish-target-integrity.log`, `contracts-period-final.log`,
`history-rollup-unit*-statuses.log`, `html-results.json`, `browser-results.json`,
`document-results.json`, `history-document-results.json`,
`final-current-*.json`, `growth-final-matrix.json`,
`post-benchmark-clone-replay.log`, `post-benchmark-target-integrity.log`,
`owned-tests-cleaned.json`, `worker-heartbeat-final.json`,
`canonical-restore-verified.json`, `mysql-cache-tuning.json`, dan bukti backup.
Data pribadi siswa tidak dimasukkan ke repo.
