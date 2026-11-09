# SPP dan Komite sebelum penempatan PSB

Siswa PSB dapat membayar SPP dan Komite kelas awal (SD 1, SMP 7, SMA 10).
Uang PSB tetap terpisah dari SPP. Kebijakan DU kelas awal tercakup PSB tetap
berlaku; cakupan SPP historis yang sudah tersimpan tidak dihapus.

## Penggunaan

Pada Komponen Biaya di Master Siswa, isi tarif SPP dan Komite per bulan. Tahun
tujuan tampil pada kolom yang sama: pendaftaran PSB 2026/2027 memakai 2027/2028.
Keduanya wajib diisi pada pendaftaran baru; kosong berbeda dari angka nol.
Nominal tidak sah ditolak oleh server dan ditampilkan melalui modal pembayaran
existing. Formulir gagal tetap menyimpan isian. Tarif mendukung dua desimal.

Penyimpanan menyiapkan dua belas bulan Juli–Juni dalam satu transaksi bersama
identitas siswa, tarif individual, dan audit. PSB lama harus disiapkan secara
eksplisit melalui Edit Siswa; pemasangan skema tidak menerbitkan tagihan.
Pendaftaran langsung pada kelas awal memakai tahun penempatan resminya.

Master SPP tidak lagi meminta tarif dasar kelas awal. Tarif per siswa dan status
tagihannya tetap dapat dibaca/diedit di sana, termasuk sebelum masuk rombel.
Penerbitan PSB tidak mengunci pengisian tarif kelas lanjutan. Perubahan identitas
di Master Siswa tidak meratakan tarif individual yang sebelumnya dibedakan per
bulan. Bulan dengan pembayaran atau kredit Legacy dipertahankan.

Di Input Pembayaran, siswa PSB yang memiliki rencana masuk diarahkan ke periode
terutang pertama. Pilihan manual, draft, serta formulir edit dipertahankan.
Pembayaran tetap satu bulan per transaksi, tepat sebesar sisa tagihan; SPP dan
Komite dibayar berpasangan bila keduanya terutang. Mekanisme kredit, koreksi,
otorisasi, dan pencegahan pengiriman pembayaran ganda tetap digunakan.

## Hubungan data dan laporan

`psb_spp_plans_data` mengikat unit, student ID, tahun tujuan, tingkat, tarif,
aktor, dan revisi. Tahun pendaftaran/tujuan tidak bergeser saat kalender berubah.
Tagihan native SPP, Komite, dan tarif individual memakai `rencana_masuk_id`;
`penempatan_id` boleh kosong hanya dengan dasar rencana yang sesuai. Tidak ada
penempatan akademik sementara ataupun kas baru untuk memindahkan pembayaran.

Penempatan melalui Master Kelas atau Edit Siswa mengikat ID tagihan yang sama
ke rombel resmi. Tingkat dan tahun harus cocok. Kenaikan berikutnya tidak
memindahkan rencana tahun pertama, uang, atau alokasi historis.

Penerimaan/setoran mengikuti tanggal kas dan konteks transaksi PSB asli.
Laporan SPP tahunan/per item mengikuti periode kewajiban dan rombel setelah
penempatan. Pembaca Komite menerima dasar rencana tanpa mewajibkan penempatan.
HTML, PDF, dan Excel tetap menggunakan model laporan bersama. Riwayat tagihan
yang berfungsi sebagai matriks tunggakan tetap mengikuti batas periode/as-of
existing; kewajiban mendatang tidak otomatis menjadi tunggakan berjalan.
Gunakan tahun tujuan pada rekap SPP/per item untuk melihat pembayaran awal.

## Pemasangan

```powershell
php scripts/migrate_psb_spp_schema.php --database=db_spp_test3 --port=3306
php scripts/migrate_psb_spp_schema.php --database=db_spp_test3 --port=3306 --mode=apply --backup-proof=C:/private/backup-proof.json
```

Preview adalah default. Apply memeriksa bukti backup lengkap beserta checksum
seluruh tabel dan snapshot terlindungi, lalu mengambil kunci akses aplikasi.
Prasyarat tarif individual, DU mendatang, cakupan DU–PSB, dan Biaya Lain dipasang
melalui fungsi installer resminya, tanpa rekonsiliasi atau backfill transaksi.
Installer dapat dilanjutkan setelah gangguan dan replay lengkap bernilai nol.
GET hanya memeriksa kesiapan. Instalasi baru memakai `sql/migrate_units.php`.
Installer perbaikan impor dan verifikasi relasi mengenali dasar rencana masuk.

Backup harus dipulihkan pada server dengan UUID, port, dan datadir berbeda,
event scheduler OFF. Simpan backup/bukti/hasil uji di luar webroot. Bila data
berubah setelah backup diverifikasi, buat dan uji restore backup baru.

## Pengujian

- `tests/psb_spp_admission_test.php`: transaksi tiga unit, NIS sama, tarif, nol,
  validasi basis/periode, pairing, replay, stale form, pembayaran, rombel,
  pergantian tahun, kenaikan kelas, laporan, serta cakupan DU. Fixture rollback.
- `tests/psb_spp_browser_test.js`: Super Admin/Administrator, modal, draft,
  validasi POST, pembayaran native, pengiriman ulang, kedua jalur penempatan,
  desktop/ponsel, tema dan Semua Unit. Hanya server audit terisolasi.
- `tests/payment_decimal_format_test.js`: format Rupiah dan round-trip desimal.

Hasil penerapan aktual, pengecualian pengujian existing, dan perbandingan data
dicatat terpisah setelah verifikasi akhir; keberadaan test bukan klaim kelulusan.

## Hasil penerapan 9 November 2026

- Target `db_spp_test3`, MySQL 3306. Apache localhost diarahkan ke target yang
  sama; perubahan pengguna pada `koneksi.php` dan berkas lokal dipertahankan.
- Dump lengkap 622.069.855 byte dipulihkan ke MySQL 3319 dengan UUID/datadir
  berbeda dan event scheduler OFF. Checksum seluruh 62 tabel asal dan snapshot
  terlindungi cocok sebelum pengujian. SHA-256 dump:
  `3cd7585b36c3c6a6a86f3fe9bc1773cb31f9f030db5f789b57c365a90d68630b`.
- Installer melaporkan 38 langkah fitur/prasyarat; preview akhir tidak mempunyai
  langkah tertunda dan apply ulang menghasilkan **0 perubahan**. Tidak ada
  backfill. Tabel rencana masuk dan tarif individual operasional tetap kosong.
- Perbandingan seluruh 66 tabel terhadap salinan sebelum pengujian menunjukkan
  semua baris identik pada kolom asal, termasuk 2.289 identitas, 390.911 baris
  arsip mentah, 108.956 penerimaan Legacy, dan 93.751 jurnal Legacy. Kolom metadata
  baru dipasang dengan defaultnya; tidak ada nominal, kas, identitas atau saldo
  lama yang diubah. Akun/password existing dipertahankan dan login dibuktikan.
- 142 pemeriksaan siklus PSB, 16 format/desimal, 23 nominal tarif, 30 kebijakan DU,
  45 perencana DU, 16 perencana bulanan, 22 pemakaian master Biaya Lain lulus.
  Regresi penerbitan reguler, Komite, dan laporan belum terbit/pembatalan lulus.
- Enam siklus browser (dua peran × tiga unit) berhasil sampai pembayaran,
  pengiriman ulang, kedua jalur penempatan dan pemeriksaan tampilan. Empat
  pemeriksaan Semua Unit lulus terpisah. Asersi fieldset pada runner diperbaiki
  untuk membaca properti native `disabled` (API browser `isDisabled()` tidak
  menguji fieldset). Tidak ada perubahan izin aplikasi untuk meloloskan tes ini.
- 18 pemeriksaan unduhan PDF/Excel, struk, viewport/tema dan keyboard modal,
  serta 5 pemeriksaan identitas/nilai/format desimal Excel lulus. Animasi modal
  ditunggu selesai sebelum screenshot. Struk mempertahankan dua desimal bila
  diperlukan; nominal bulat tetap tanpa tambahan desimal.
- Regresi DU pada skema bersih dengan kolasi yang sama dengan target lulus:
  **66** pemeriksaan cakupan PSB dan **123** pemeriksaan DU mendatang, termasuk
  rollover, koreksi/pembatalan, replay, kredit dan pengikatan setelah kenaikan.
  Instalasi baru/multiunit beserta seeder dan pengulangan tanpa duplikasi lulus.
- 20 pemeriksaan HTTP localhost lulus: login benar/salah, dashboard, seluruh
  master, Input/Riwayat Pembayaran, Laporan Global, Tabungan, akun, health, dan
  logout dengan CSRF. Pemeriksaan sintaks berkas berubah dan detector source UI
  lulus; pemeriksaan visual dilakukan pada hasil render desktop/ponsel dua tema.

Pengecualian: tes DU lama pada dump existing mengasumsikan master tahun berjalan
belum ada, dan rekonstruksi Legacy menyeluruh melampaui batas memori default.
Regresi diulang pada fixture bersih terisolasi tanpa mengubah/menghapus data target.
Pembaca gabungan DU pada instalasi bersih berkolasi Unicode masih menemukan
perbedaan kolasi dengan tabel Legacy general di `registration_combined.php`;
kondisi tersebut tidak terjadi pada target berkolasi general yang diuji. Tidak
ada konversi kolasi/data operasional dalam penerapan ini. Pemeriksaan fresh schema
dan seeder lulus, tetapi hasil itu bukan klaim seluruh pembaca fresh Unicode lulus.

Backup, bukti restore, pembandingan, hasil ekspor dan screenshot tersimpan di
`C:/laragon/backups/spp-management-system/psb_spp_20261109`, di luar webroot.
