# Migrasi Legacy ke db_spp_test3 — 26 Oktober 2026

Migrasi SD, SMP, SMA selesai melalui runner resmi pada
`127.0.0.1:3306/db_spp_test3`, UUID
`e6e5c8d8-b81c-11f1-9be5-745d2224b37e`. Aktor Super Admin ID 1 dipertahankan.
Metadata peralihan memakai snapshot backup masing-masing unit, 17 Oktober 2026.
Transaksi setelah snapshot yang tidak ada di backup tidak dianggap tercakup.

| Unit | Siswa master, termasuk alumni/PSB | Transaksi pembayaran | Penerimaan Legacy, seluruh histori | Rekening valid | Saldo rekening valid |
|---|---:|---:|---:|---:|---:|
| SD | 961 | 43.788 | Rp30.087.981.252 | 977 | Rp578.952.957,60 |
| SMP | 1.076 | 55.097 | Rp43.368.252.800 | 1.041 | Rp179.952.000,10 |
| SMA | 252 | 10.071 | Rp8.731.122.200 | 379 | Rp115.108.300 |

Total 108.956 transaksi, Rp82.187.356.252. Penerimaan tetap pada penyimpanan
Legacy; tabel pembayaran native tidak diisi ulang. Histori sebelum 2020 tetap
diarsipkan. Semua tahap identitas, PSB/DU, komponen, biaya lain, SPP–Komite,
kredit periode mendatang, rekening dan jurnal dijalankan.

## Validasi

- Backup awal dan backup sebelum/sesudah tiap unit dipulihkan pada server
  terisolasi; definisi objek, integritas, checksum seluruh tabel dan fingerprint
  terlindungi diperiksa. Backup akhir memiliki bukti restore terpisah.
- 56 pemeriksaan biaya, 16 perencana bulanan, pengujian otomasi/bulk/POMG,
  penulisan dan publikasi fixture, gangguan/rollback, retry serta 30 integrasi
  bulanan lulus. Fixture hanya pada instance terisolasi port 3319.
- Hasil tiga unit pada target setara salinan. GUI/HTTP, rincian/struk, tabungan,
  laporan penerimaan serta SPP tahunan, PDF dan Excel lulus pada batas 512 MiB.
  Bagian belum terbukti ditandai belum diketahui.
- Verify/apply/verify target menghasilkan `changes=0` untuk semua unit.
  Checksum seluruh tabel serta fingerprint identitas, arsip, kas dan tabungan
  tidak berubah saat pengulangan.
- Raisa SMP `252607034` tetap 8A. PSB gross Rp14.295.000, potongan Rp6.900.000,
  net/paid Rp7.395.000, sisa Rp0. Juli–September dibaca lunas dari sumber;
  Oktober SPP Rp327.500 dan Komite Rp15.000.

## Pengecualian sumber

24.297 rincian biaya mempunyai jejak unit/sumber/transaksi/baris/slot;
24.238 tertaut ke master, termasuk 13 hasil inferensi. Sebanyak 59 rincian
belum teridentifikasi tetap diterima sebagai histori, tanpa pemetaan wajib.
Tidak ada struk biaya ditahan karena pertentangan nominal pada sumber ini.

21 rekening dan dua pemilik tetap ditahan berdasarkan bukti yang belum cukup.
Tidak dibuat jurnal penyeimbang otomatis. Terdapat 4.915 catatan bukti
tagihan/periode pada 283 identitas lintas unit, bukan 4.915 siswa. Mayoritas
berkaitan dengan kelas/penempatan historis; sisanya tarif/periode belum tunggal
atau masa kewajiban belum terbukti. Tidak dijadikan tunggakan perkiraan.

7.345 rekap DU cocok dengan pembayaran asal, tanpa penerimaan kedua. 1.943
rekap belum berpasangan, 14 anomali DU dan referensi tanpa identitas tetap
diarsipkan dengan keputusan sumbernya. Angka antarjenis temuan dapat terkait
pemilik yang sama dan tidak boleh dijumlahkan sebagai orang.

## Artefak privat

Laporan rinci berada di luar webroot:
`C:/laragon/data/spp-migrations/db_spp_test3/20261026-01/HASIL_MIGRASI.md`.
Direktori yang sama menyimpan manifest/hash sumber, log pengujian, bukti restore,
hasil pengulangan, rincian biaya, nominal per komponen/periode, pengecualian,
keputusan referensi, serta hasil HTTP dan ekspor.

Backup akhir terverifikasi:
`C:/laragon/backups/spp-management-system/daily/db_spp_test3/jobs/fcc6078ce8e7c26025ff874b94d7bd64/backup.sql`.
SHA-256: `75765b01bdf0f80815982627a8afecbc69ffa7236e1abd33e1bf7efac8bcac65`.
GUI/CLI memakai root arsip yang sama:
`C:/laragon/data/spp-legacy-import/db_spp_test3`.

Versi akhir: workflow 10, operasional 14, otomasi 3, bulanan 2, pelacakan biaya 1.
Worker target memakai versi tersebut dan SQL LocalDB sehat. `.gitignore` serta
`koneksi.php` pengguna dipertahankan.
