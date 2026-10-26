# Pelacakan biaya lain otomatis, 26 Oktober 2026

Pembaruan pembaca Master Biaya Lain, hitungan transaksi dan pengamanan hapus
dicatat pada [verifikasi master biaya](LEGACY_FEE_MASTER_USAGE_20261026.md).

Perubahan kode/alur; tidak menerapkan migrasi, backfill, perubahan skema, atau
penulisan ke database pengguna pada pekerjaan ini. Tidak ada filter, tombol,
atau menu baru. Perubahan `.gitignore` pengguna dipertahankan.

## Alur yang berlaku

Unggahan Migrasi Penuh pada GUI kini memakai pipeline operasional otomatis
secara bawaan, sama dengan runner Codex. Mode arsip lama tetap tersedia melalui
pilihan eksplisit `mode=archive`; unggahan identitas atau arsip keuangan saja
tidak diubah menjadi migrasi operasional.

Pasangan `LAIN_LAIN1–4` / `JUMLAH1–4` diproses oleh satu engine. Kode dicocokkan
dengan `lain.KODE` pada unit dan sumber yang sama, kemudian bukti pasangan struk,
keterangan eksplisit dan inferensi terikat aturan existing. Nol di depan kode
dipertahankan. Setiap hasil menyimpan status langsung, inferensi, belum
teridentifikasi, atau koreksi manual beserta referensi bukti.

- Master tujuan yang cocok dipakai kembali dalam unit yang sama. Master baru
  disiapkan otomatis melalui mekanisme existing: nonaktif, nominal nol, dan
  `legacy_tariff_pending=1`. Jumlah pembayaran tidak dijadikan tarif.
- Kode kosong/ambigu diterima dengan label Biaya Legacy belum teridentifikasi.
  Tidak ada syarat pengguna memetakan atau mengesahkan rincian tersebut.
- Nominal tidak sah atau total/rincian bertentangan ditahan otomatis per struk
  dengan kode `fee_transaction_held`. Arsip dan alasannya disimpan; pemilik dan
  struk valid lainnya tetap diproses. Tidak ada nominal pengganti yang dikarang.
- POMG pada slot rincian tetap Komite. PSB, DU, SPP, tabungan, histori lama dan
  kredit periode mendatang tetap memakai engine masing-masing.

## Identitas dan penyimpanan

Tidak ada tabel baru. Rincian tetap pada `legacy_op_payments.items`; identitas
stabil adalah SHA-256 dari **unit + hash sumber + row_key + kolom slot**.
Dua slot dengan kode sama mempunyai master yang sama, tetapi identitas detail
berbeda. Publikasi/retry tidak membuat penerimaan kedua pada `bayar` atau
`bayar_biaya_lain`, dan histori pembayaran tidak menerbitkan tagihan baru.

Metadata `migration` versi 3 menambahkan `trace_version`, `detail_key`, unit,
sumber, tabel, ID transaksi sumber jika ada, `row_key`, kolom/slot, kode asli
dan normalisasi, kategori/master tujuan, serta bukti keputusan. Resolusi ID
master mempertahankan identitas asal dan terikat fingerprint simulasi.

Rekonsiliasi yang kelak diperintahkan melalui runner memakai engine yang sama
untuk melengkapi transaksi existing. Nominal/tanggal/kondisi batal dan koreksi
pengguna tidak ditimpa. Perubahan metadata diproses dalam transaksi unit, memakai
pemeriksaan versi dan audit `fee_trace`; penerapan ulang harus nol perubahan.

Pembaca rincian/struk serta ekspor existing menggunakan referensi yang sama:
ID struk Legacy, kode biaya dan slot. Untuk format lama, pembaca dapat mengambil
referensi dari arsip sumber secara baca saja. Label rekap komponen tetap nama
komponen; referensi transaksi tidak memecah rekap menjadi kelompok per struk.

## Versi dan verifikasi

Workflow **11**, operasional **15**, otomasi **4**, billing **3**, DU **1**, pelacakan biaya lain **1**.
Worker/simulasi lama harus diperbarui sebelum penerapan berikutnya. Pembacaan
metadata versi 1–2, termasuk tahun kewajiban DU, tetap didukung.

Simulasi memuat `summary.fee_trace`. Runner menambahkan tahap `other_fee_trace`
dan `verification_fee_trace`: jumlah/nominal rincian, tautan master, usulan
master, inferensi, rincian belum teridentifikasi, kelengkapan jejak, kondisi
batal dan transaksi tertahan. Verifikasi juga memeriksa identitas slot, jumlah
rincian terhadap total struk, serta keberadaan master dalam unit yang benar.

## Pengujian yang dijalankan

- **53 pemeriksaan fixture murni** pada `tests/legacy_fee_trace_test.php`: empat
  slot, kode berulang, nol di depan, pemisahan sumber/unit, kode kosong/ambigu,
  master belum tersedia, total/rincian, metadata/rantai sumber, POMG, koreksi,
  pembatalan, fingerprint, penerapan ulang, kompatibilitas versi, dan pilihan
  bawaan unggahan GUI.
- Suite otomasi migrasi, operasional murni, bulk, pemeriksaan otomatis, proyeksi
  pembayaran, POMG, serta **15 pemeriksaan perencana bulanan** lulus tanpa
  menghubungkan database pengguna.
- PHP lint dan pemeriksaan whitespace dijalankan pada perubahan.

Pengujian penulisan ditambahkan pada
`tests/legacy_migration_automation_transactions_test.php`, tetapi **belum
dijalankan** pada pekerjaan ini. Pengujian tersebut wajib lulus pada instance
terisolasi yang UUID/port/targetnya diverifikasi sebelum penerapan data:
pengayaan existing, pembuatan master nonaktif, slot terpisah, audit, pembacaan
arsip, pengulangan nol perubahan dan kas/native detail tidak bertambah. Seluruh
fixture ditutup dengan rollback. Jangan menjalankannya pada database pengguna.

Untuk migrasi berikutnya ikuti [runbook](LEGACY_MIGRATION_RUNBOOK.md), termasuk
backup terverifikasi dan semua tahap PSB, biaya lain, SPP–Komite serta tabungan.

## Validasi lanjutan pada migrasi test3

Pengujian penulisan yang belum dijalankan pada pekerjaan awal di atas kemudian
**lulus** pada instance terisolasi port 3319, UUID
`8d21f5ab-d0ab-11f1-86d7-745d2224b37e`, database fixture terpisah.
Mode `--draft --publication` juga lulus: publikasi nyata, gangguan sebelum commit,
rollback, backup/restore, metadata tersimpan dan pengulangan tanpa duplikasi.
Fixture tidak dijalankan pada database pengguna port 3306.

Regresi menjadi **56 pemeriksaan biaya**, **16 perencana bulanan**, dan
**30 integrasi bulanan**. Pemeriksaan HTTP memakai batas memori aplikasi 512 MiB;
Data Siswa, Master SPP, Input Pembayaran, rincian/struk, tabungan, preview,
PDF dan Excel lulus pada salinan tiga unit. Semua rincian dapat ditelusuri;
kode belum teridentifikasi tetap berstatus demikian.

Perbaikan yang ditemukan melalui uji penulisan: hitung affected rows pada
statement sebelum ditutup; gunakan identitas proposal biaya yang sama tanpa
perbedaan kapitalisasi; kanonisasi objek bukti JSON bertingkat untuk fingerprint;
segarkan cache setelah batch selesai dan pertahankan publikasi ulang sebagai
operasi tanpa perubahan. Cache tagihan hanya menyimpan komponen bulanan yang
dibutuhkan perencana; metadata struk lengkap tetap pada penyimpanan asal.

Operasional 14 menahan selisih saldo belum terbukti pada pekerjaan baru tanpa
jurnal penyeimbang otomatis. Batch/koreksi lama tetap memakai keputusannya.
Backup verifier menyimpan checksum seluruh tabel serta snapshot terlindungi
pada `verification.json.migration_proof`, dengan proses audit CLI 1536 MiB.

Pelacakan DU sumber baru dan existing mengikuti [panduan DU](LEGACY_DU_TRACE_20261026.md). Metadata otomasi 1, 2, dan 3 tetap terbaca; pelacakan biaya lain versi 1 tetap dipertahankan.
