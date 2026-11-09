# SPP–Komite kelas berikutnya sebelum penempatan

## Alur operasional

Pada Master SPP, pilih tepat tahun ajaran berikutnya dan gunakan **Simpan Tarif &
Lengkapi Tagihan**. Konfirmasi existing menjelaskan bahwa seluruh siswa yang
memenuhi syarat disiapkan, tanpa bergantung pada pilihan baris/filter/pagination.
Tagihan Juli–Juni dapat disiapkan walaupun tahun berjalan belum lunas. Input
Pembayaran tetap menolak pelompatan bulan dan mewajibkan pasangan SPP–Komite
ketika keduanya terutang. Siswa reguler tidak otomatis dialihkan ke periode depan.

Penempatan tujuan dipakai bila sudah tersedia; selain itu sumbernya penempatan
tahun berjalan dan proyeksi satu tingkat dalam unit yang sama. Tidak ada rombel
sementara. Siswa kelas akhir, nonakademik, nonaktif dan identitas belum
operasional tidak diproyeksikan. PSB tetap melalui rencana masuk existing.

SPP memakai tarif Master SPP tahun tujuan atau tarif individual periode tujuan.
Tarif khusus dan cakupan bebas SPP tahun sebelumnya tidak disalin. Komite tetap
berasal dari POMG di Master Siswa, tanpa master tarif tahunan baru. Snapshot
tagihan melindungi bulan yang telah dibayar/memiliki kredit. Mengubah Komite di
Master Siswa menyelaraskan tagihan mendatang yang masih boleh diubah.

## Integritas dan pembaca

Tiga tabel native SPP, Komite dan tarif individual mendapat `penempatan_asal_id`
beserta FK ke penempatan. Trigger memeriksa unit, siswa, satu tingkat/satu tahun,
periode Juli–Juni, serta konsistensi penempatan tujuan. Dasar PSB dan proyeksi
tidak boleh dicampur. Penempatan dan periode tujuan tidak digeser kalender.

Penerbitan berjalan dalam transaksi unit. Data yang tidak valid membatalkan
permintaan dengan alasan; tidak dilewati diam-diam. Menyimpan ulang melengkapi
periode yang hilang. Ringkasan memeriksa seluruh pasangan periode, bukan hanya
keberadaan satu tagihan. Siswa yang baru memenuhi syarat disiapkan pada jalur
tulis penempatan bila tarif tahun berikutnya sudah diterbitkan.

Kenaikan dan sinkronisasi Edit Siswa mengikat ID tagihan yang sama sebelum
sinkronisasi reguler. Tarif terbayar, alokasi, tanggal kas dan konteks kelas saat
penerimaan dipertahankan. Perbedaan tingkat menjadi konflik, bukan pemindahan
uang otomatis. Pembatalan/koreksi tetap menjaga urutan dan pasangan pembayaran.

Laporan SPP/Komite mengikuti periode kewajiban dan rombel tujuan setelah
penempatan. Penerimaan mempertahankan kelas saat kas diterima. Tagihan mendatang
tidak ditambahkan ke tunggakan berjalan. Riwayat Tagihan yang dibatasi sampai
hari ini tetap menampilkan bulan mendatang yang telah lunas, dengan keterangan
**Pembayaran di muka** pada konteksnya. Tagihan mendatang belum lunas tetap di
laporan kewajiban tahun tujuan; kredit parsial tidak menaikkan matriks tunggakan
berjalan. Bukti kewajiban yang tidak diketahui tetap null/belum diketahui.

## Pemasangan

Target: `db_spp_test3`, MySQL 3306, UUID
`e6e5c8d8-b81c-11f1-9be5-745d2224b37e`. Konfigurasi lokal pengguna tidak diubah.

```powershell
php scripts/migrate_spp_advance_schema.php --database=db_spp_test3 --port=3306
php scripts/migrate_spp_advance_schema.php --database=db_spp_test3 --port=3306 --mode=apply --backup-proof=C:/private/backup-proof.json
```

Preview default tidak menulis skema. Apply memeriksa backup/restore dan keadaan
aktual seluruh tabel, mengunci akses aplikasi, memasang kolom/FK/view/trigger,
serta memeriksa snapshot terlindungi. Readiness menolak skema parsial; GET tidak
melakukan DDL. Instalasi baru dan kontrak repair memakai skema yang sama.
Pemasangan tidak menerbitkan kewajiban atau merekonsiliasi Legacy otomatis.

Backup lengkap disimpan di luar webroot pada
`C:/laragon/backups/spp-management-system/spp_advance_20261110/before.sql`;
ukuran 622.650.303 byte, SHA-256
`fbf8d37b34af427d3431878269a371f5909b25c24ab2d8f2d6291974a02b03b3`.
Restore terisolasi menggunakan port 3319, UUID
`dd012ef3-d7b4-11f1-b78c-745d2224b37e`, datadir berbeda, event scheduler OFF.
Checksum 66 tabel dan snapshot sumber/restore identik sebelum pengujian.

## Verifikasi dan batas pengujian

- Siklus akhir: 248 pemeriksaan dua peran × tiga unit; tahun simulasi dipakai
  untuk urutan pembayaran, tarif, laporan, konflik dan pengikatan.
- Regresi PSB 142, DU–PSB 66 dan DU mendatang 123 pemeriksaan lulus.
- Penerbitan SPP reguler, Komite, laporan belum terbit/pembatalan, instalasi baru
  multiunit/seeder/replay, serta 22 pemeriksaan pemakaian Master Biaya Lain lulus.
- Pemeriksaan murni: nominal tarif 23, kebijakan DU 30, perencana DU 45,
  perencana bulanan 16, format pembayaran 16, presisi Tabungan 15; urutan/status
  SPP, draft/modal pembayaran dan proyeksi Riwayat Tagihan existing lulus.
- Tes Tabungan lama `savings_refinement_test.php` sudah tidak cocok dengan
  kontrak parser saat ini (menangkap RuntimeException, sementara penolakan
  nominal memakai DomainException, serta asumsi nominal lama). Parser Tabungan
  tidak diubah dalam fitur ini; tes presisi aktual lulus. Tes Biaya Lain yang
  memerlukan data migrasi dijalankan pada restore, bukan scratch kosong.
- GUI memakai tahun operasional, karena dropdown tahun existing mempunyai
  rentang pilihan. Pengukuran viewport dilakukan setelah transisi layout selesai.
- Enam siklus GUI lulus (150 pemeriksaan), ditambah 5 pemeriksaan Semua Unit.
  Link pengujian hasil laporan diperbaiki dari halaman katalog ke `template.php`;
  18 pemeriksaan tambahan membuktikan baris siswa dan nominal SPP/Komite serta
  keterangan pembayaran di muka pada hasil laporan sebenarnya di kedua peran
  dan tiga unit. Struk mempertahankan konteks asal setelah penempatan.
- Enam unduhan PDF/Excel memiliki format sebenarnya; 4 pemeriksaan identitas
  dan nominal pada Excel serta keterangan pembayaran di muka pada ekspor
  Riwayat Tagihan lulus. Sepuluh pemeriksaan khusus cutoff/riwayat lulus,
  termasuk penanda pembayaran mendatang pada pembaca agregat/HTML.
- Parser CSS dan seluruh selector pada stylesheet terkait lulus; CSS existing
  tidak berubah. Snapshot CSS lama mengacu commit desain lama `7647608` dan
  tidak cocok dengan desain existing; tidak digunakan untuk mengembalikan
  perubahan desain pengguna. Render desktop/ponsel dan dua tema diperiksa.

## Hasil penerapan target

Installer pada `db_spp_test3` menerapkan 17 langkah skema, tanpa backfill dan
tanpa penerbitan kewajiban. Apply ulang menghasilkan **0 perubahan**, pending
kosong. Dibandingkan restore pristine, seluruh **66 tabel identik pada kolom
asal**, dengan 0 baris berbeda/hilang. Identitas, arsip, koreksi, kas, kredit dan
Tabungan dipertahankan. Kredensial existing tetap cocok; health localhost `ok`.
Sebanyak 20 pemeriksaan HTTP localhost lulus, termasuk login benar/salah,
master, pembayaran, riwayat, Tabungan, akun dan logout CSRF.
Dua pembaca hasil laporan SPP/Komite pada localhost dengan data Legacy existing
juga lulus. Layanan PHP dan MySQL uji terisolasi dihentikan setelah verifikasi;
Apache dan MySQL localhost operasional tetap berjalan.

Backup, bukti restore, perbandingan data, hasil GUI, screenshot dan ekspor berada
di direktori privat `C:/laragon/backups/spp-management-system/spp_advance_20261110`.
Konfigurasi lokal `koneksi.php`, `.gitignore`, dump dan cache pengguna dipertahankan.

Gerbang visual: kontrol existing dipakai tanpa penambahan menu/aset/CSS; modal
konfirmasi diuji dengan keyboard, desktop/ponsel dan dua tema. Tidak ada exception
JavaScript pada enam siklus GUI. Pemeriksaan sintaks PHP/JavaScript dan whitespace
lulus. Batas instalasi Unicode yang sudah dicatat pada dokumentasi PSB sebelumnya
tidak diklaim terselesaikan oleh perubahan ini.
