# Audit rekening sumber Legacy, 23 Oktober 2026

Tujuan: menyediakan bukti yang dapat dijalankan dan di-screenshot di SQL Server
Management Studio mengenai identitas rekening pada data lama. Pemeriksaan ini
membaca sumber dan metadata database tujuan; tidak menjalankan impor, koreksi,
perubahan skema, atau bootstrap aplikasi.

## Cara menjalankan

Buka [script SQL Server](../sql/sqlserver_audit_rekening_legacy.sql) pada koneksi
SSMS yang memuat `sdit_mh`, `smpit_mh`, dan `smait_mh`. Instance yang diperiksa
adalah `(localdb)\SppLegacyImporter_399abf4802bf`.

1. Bagian 1 menghasilkan ringkasan ketiga unit.
2. Bagian 2 menghasilkan daftar rekening tanpa pasangan master. Default SD;
   ubah `USE [sdit_mh]` menjadi `USE [smpit_mh]` atau `USE [smait_mh]` untuk unit
   lain. Untuk screenshot, pilih dan jalankan bagian 2 beserta `USE` saja.
3. Bagian 3 menunjukkan jurnal utama dengan nomor induk kosong pada unit pilihan.
4. Bagian 4 menelusuri satu NIS terisi. Ganti `@NoInduk` sesuai hasil bagian 2.
   Master, rekening, dan jurnal menjadi tiga hasil terpisah.
5. Bagian 5 khusus rekening tambahan SD dan selalu kembali ke `sdit_mh`.

Gunakan F5 atau Execute. Ctrl+D memilih hasil berbentuk grid. Seluruh bagian
dapat dijalankan bersama, tetapi untuk screenshot satu kasus lebih mudah
menjalankan bagian yang dibutuhkan saja. Script ini untuk SQL Server, sedangkan
database aplikasi PHP memakai MySQL.

## Hasil sumber yang diperiksa

| Unit | Baris tabungan utama | NIS kosong | NIS terisi tanpa master unit yang sama | Master tunggal | Master ambigu |
| --- | ---: | ---: | ---: | ---: | ---: |
| SD | 983 | 1 | 144 | 838 | 0 |
| SMP | 1.056 | 1 | 23 | 1.032 | 0 |
| SMA | 382 | 1 | 197 | 184 | 0 |

Angka adalah jumlah baris `tabungan`, termasuk saldo nol, seluruh periode pada
database sumber yang sedang terpasang. Ini bukan jumlah siswa aktif, jumlah
orang, atau jumlah kasus yang belum diselesaikan di MySQL setelah migrasi.
`tb_total`, `tb_masuk`, `tb_keluar`, dan `tbg` tidak masuk hitungan tersebut.

Contoh SD yang telah diverifikasi:

- `tabungan.id=38`: `NO_INDUK=''`, saldo tercatat Rp10.000,00.
- `tabungan.id=98`: `NO_INDUK='1314001'`, saldo tercatat Rp8.047.000,00,
  tidak ditemukan pada `siswa.no_induk`. Penelusuran menghasilkan 0 master,
  1 rekening, dan 209 baris jurnal utama.
- `transaksi_m.id=46360`: tanggal sumber 5 Mei 2025, nomor induk kosong,
  nilai masuk Rp10.000,00. Ada pula jurnal kosong bernilai masuk nol pada
  `transaksi_m.id=196`, tanggal sumber 4 November 2011.

Rekening dan jurnal yang sama-sama kosong tidak otomatis mempunyai pemilik yang
sama, walaupun nominalnya sama. Nominal rekening adalah saldo yang tercatat,
bukan pernyataan bahwa seluruh riwayatnya telah cocok atau uangnya hilang.
Tidak ditemukannya master hanya membuktikan putusnya pasangan master dalam
snapshot ini; identitas mungkin perlu ditelusuri dari bukti lain.

## Pemahaman proyek yang relevan

- Aplikasi merupakan monolit PHP dengan `mysqli`, session, dan halaman PHP,
  memakai Dompdf dan PhpSpreadsheet untuk ekspor. Konfigurasi
  [koneksi.php](../koneksi.php) menerima environment dan fallback lokal
  `db_spp_test2`. Konfigurasi tersebut tidak diubah.
- Modulnya mencakup siswa/PSB/alumni dan penempatan kelas, master tarif,
  tagihan serta pembayaran SPP/Komite/DU/biaya lain, tabungan, otorisasi,
  laporan, akun, backup/restore, dan impor Legacy. Pemeriksaan kode pada
  pekerjaan ini dipusatkan pada identitas, pemilik, tabungan, dan impor.
- Pemisahan unit memakai tabel fisik `*_data`, view operasional, dan konteks
  `current_unit_id()`. [units.php](../includes/units.php) menyiapkan konteks unit.
  Primary key `siswa_data` adalah `id`; indeks unik identitasnya
  `(unit_id, NO_INDUK)`, sesuai [legacy_schema.php](../includes/legacy_schema.php).
  Struktur tersebut juga diperiksa langsung pada MySQL `db_spp_test2`.
- Tabungan native mengaitkan siswa melalui unit dan NIS. Tabungan Legacy
  operasional memakai `legacy_op_owners`, `legacy_op_accounts`, dan
  `legacy_op_journals`. FK rekening/jurnal memakai `(unit_id, owner_id)` menuju
  `(unit_id, id)` pemilik; hubungan pemilik ke siswa dapat NULL untuk non-siswa.
  Lihat [skema operasional](../includes/legacy_operational_schema.php).
  Karena itu, rekening guru/karyawan/non-siswa yang identitasnya tersedia
  tidak otomatis menjadi rekening tanpa pemilik.
- Tiga jalur importer adalah impor identitas, arsip histori keuangan, dan migrasi
  penuh/operasional. [legacy_import.php](../includes/legacy_import.php) mengelola
  antrean/staging SQLite privat di luar webroot. Worker dan
  [legacy_extract.ps1](../scripts/legacy_extract.ps1) memeriksa backup serta
  struktur sumber sebelum ekstraksi. SQL audit ini tidak memanggil alur tersebut.
- Pada SD 2026, [legacy_source.php](../includes/legacy_source.php) membaca
  `no_induk` sebagai identitas utama; `NO_INDUK1` sebagai pembanding. Pencocokan
  tidak memaksa NIS menjadi angka sehingga nol awal tetap tersedia. Pemeriksaan
  tambahan menemukan 0 rekening SD tanpa master utama yang ternyata cocok
  dengan `NO_INDUK1`.
- [lop_prepare](../includes/legacy_operational.php) dapat mempertahankan sumber
  bernomor induk tanpa master sebagai pemilik Legacy non-siswa ketika kebijakan
  `op_import_unmatched` aktif. Label pengganti tidak membuktikan nama orangnya.
  Sumber tanpa identitas dipisahkan oleh
  [aturan resolusi](../includes/legacy_operational_resolution.php) dan
  [aturan histori](../includes/legacy_operational_checks.php), bukan ditebak
  menjadi milik siswa tertentu.
- [Aturan otomasi](../includes/legacy_migration_automation.php) mempertahankan
  rekening utama ketika tersedia; rekening tambahan memerlukan pemeriksaan
  tersendiri. Pembaca [migrated_savings.php](../includes/migrated_savings.php)
  dan [migrated_history.php](../includes/migrated_history.php) menyatukan tampilan
  native/Legacy dengan unit dan ID pemilik. Penyesuaian migrasi dibedakan dari
  arus kas baru.

Nomor induk sama antarunit sudah dapat dipisahkan oleh model tujuan. Itu tidak
mengisi NIS yang kosong atau membuktikan pemilik rekening tanpa master sumber.
Pemeriksaan NIS ganda dalam masing-masing master sumber menghasilkan 0 kelompok
duplikat pada ketiga unit yang dibaca.

## Validasi dan batas

Seluruh batch script dijalankan melalui SQL Server pada instance di atas dan
menghasilkan tujuh result set tanpa error. Daftar rekening SD memuat 145 baris;
jurnal SD tanpa NIS memuat 2 baris. `tb_total` SD mempunyai 12 baris dan tidak
memiliki rekening tanpa pasangan master menurut pemeriksaan bagian 5. Perubahan
hanya menambah SQL audit serta dokumentasi ini. File lampiran pengguna tetap
dipertahankan.

`CONVERT(decimal(20,2), ...)` hanya untuk keterbacaan hasil; sumber memakai
`float` dan nilai aslinya tidak diperbarui. Kolom NULL tetap NULL. Script tidak
membuktikan jam aktual dari timestamp tengah malam, pemilik lintasunit, saldo
yang benar menurut buku fisik, atau histori yang tidak tercatat pada sumber.

## Pemeriksaan lanjutan: pola NIS angkatan 2020/2021 ke atas

[Script pemeriksaan angkatan](../sql/sqlserver_audit_angkatan_2020_keatas.sql)
memakai NIS numerik lebih dari empat karakter dengan awalan `2021`, `2122`,
`2223`, `2324`, `2425`, `2526`, atau `2627`. Awalan menjadi filter, bukan
pengesahan tahun masuk. Siswa aktif, alumni, dan arsip yang memenuhi pola sama
tetap termasuk. Baris tanpa NIS tidak diberi angkatan berdasarkan tanggal.

| Cakupan/temuan sumber | SD | SMP | SMA |
| --- | ---: | ---: | ---: |
| Baris siswa sesuai pola | 585 | 828 | 237 |
| Baris pembayaran `bayar` sesuai pola | 19.943 | 24.020 | 6.062 |
| Rekening utama sesuai pola | 426 | 393 | 97 |
| Rekening utama tanpa pasangan master | 0 | 0 | 0 |
| Pembayaran tanpa pasangan master | 1 | 3 | 1 |
| Rincian positif dengan kode biaya kosong | 2 | 1 | 0 |
| Rekening dengan selisih header/jurnal minimal Rp0,005 | 1 | 0 | 0 |
| Pembayaran dengan `U_LAIN` kosong/nol dan rincian positif | 2.275 | 3.361 | 867 |
| Pembayaran dengan kolom `jam` kosong | 19.560 | 23.749 | 6.003 |
| Pembayaran `bayar` dengan `TGL_BYR` kosong | 0 | 0 | 0 |

Temuan tidak saling eksklusif dan satuannya berbeda: baris pembayaran, slot
rincian, atau rekening. Lima pembayaran tanpa master tetap mempunyai nominal
positif pada komponen/rincian sumber, sehingga bukan sekadar baris tanpa uang.

Contoh yang dapat di-screenshot:

- **Pembayaran tanpa master:** SD `bayar.id=38991`, NIS `202107062`, tanggal
  12 Mei 2020, rincian pertama berkode `0018` sebesar Rp300.000.
  SMP mempunyai NIS `202107114`, `202107049`, dan `262707`; SMA `202107028`.
  Pemeriksaan ini belum menentukan apakah penyebabnya kesalahan ketik,
  perubahan identitas, atau kelengkapan master.
- **Jenis biaya kosong:** SD `bayar.id=42598`, NIS `202107050`, 10 April 2021,
  slot pertama Rp3.000; SD `bayar.id=49901`, NIS `212207015`, 16 September
  2022, slot kedua Rp17.000. SMP `bayar.id=4681`, NIS `202107056`, 21 Maret
  2023, slot pertama Rp17.000. Ketiganya mempunyai kode kosong dan keterangan
  NULL pada sumber utama.
- **Selisih saldo sumber:** SD `tabungan.id=888`, NIS `252601093`, saldo
  header Rp1.340.000, total bersih 91 jurnal Rp1.320.000, selisih Rp20.000.
  Perhitungan memakai seluruh jurnal utama untuk NIS tersebut, tanpa memotong
  tanggal mulai 2020 dan tanpa menambahkan saldo rekening tambahan.
- **Pola total/rincian:** SD `bayar.id=69888`, NIS `242501042`, tanggal
  15 Oktober 2026, `U_LAIN=NULL` tetapi kode `0009` pada rincian pertama
  bernominal Rp300.000. Importer sudah mempunyai aturan membaca rincian;
  kolom total yang kosong tidak berarti pembayaran nol.

Jam kosong merupakan keterbatasan pencatatan sumber. Tanggal pembayaran
`bayar` pada cakupan ini tersedia. Rekap `bayar_du` tetap berbeda: struktur
ketiga unit tidak memiliki kolom tanggal transaksi. Ada 1.152 baris rekap DU
SD, 1.619 SMP, dan 309 SMA pada pola NIS yang sama; seluruh nominal rekap
tersebut tidak NULL dan tidak negatif. Rekap tidak dijumlahkan sebagai uang
masuk tambahan di atas pembayaran asal.

Pemeriksaan tambahan jurnal utama pada cakupan ini tidak menemukan tanggal
kosong, jurnal nol, arah/nominal negatif atau dua arah sekaligus, maupun
pecahan nominal di atas dua desimal yang melebihi toleransi Rp0,000001.
Pencocokan rincian biaya positif dengan master `lain` menemukan tiga slot
berkode kosong di atas; seluruh slot lain mempunyai satu nama master.
Ini tidak menilai kebenaran historis setiap nama/nominal.

Seluruh enam result set script angkatan diuji untuk masing-masing dari tiga
database sumber tanpa error. Script default SD, dan `USE` dapat diganti untuk
SMP/SMA. Bagian 2 menampilkan pembayaran tanpa master, bagian 3 rincian tanpa
kode, bagian 4 selisih saldo, serta bagian 5-6 kelengkapan jam dan pola
total/rincian. Data MySQL tidak diubah atau dipakai untuk mengklaim bahwa temuan
sumber tersebut masih menjadi pengecualian setelah migrasi.
