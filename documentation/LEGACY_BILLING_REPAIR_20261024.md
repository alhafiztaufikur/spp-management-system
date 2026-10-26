# Perbaikan PSB dan SPP–Komite, 24 Oktober 2026

Implementasi mengikuti [prosedur migrasi](LEGACY_MIGRATION_RUNBOOK.md).
Aturan workflow 9, operasional 12, otomasi 2, dan perencana bulanan 2.

## Perilaku aplikasi

- PSB memakai satu posisi pokok, potongan, pembayaran, dan sisa pada Data Siswa,
  kasir, kewajiban Legacy, serta Laporan Global. Header pembayaran kumulatif
  tidak dijumlahkan kembali dengan rincian yang sudah tercakup.
- Nilai master nol dari impor dilengkapi hanya dari bukti sumber yang konsisten.
  Koreksi pengguna dipertahankan. Siswa reguler tidak harus dipindah ke kelas
  PSB untuk menyimpan nominal yang sah.
- `U_SORGA` menjadi Komite/POMG di SD, SMP, dan SMA. Rekategorisasi mempertahankan
  nominal, tanggal kas, periode dan jumlah penerimaan. Pembayaran yang mempunyai
  audit koreksi tidak ditimpa; masuk peninjauan.
- Perencana bulanan menggunakan periode pembayaran, tarif, potongan dan kelas
  sumber. Tarif stabil mengisi celah yang terbukti; tarif tahun sekarang tidak
  dipakai untuk seluruh histori. Kredit bulan mendatang tetap pada periodenya.
- Cutover November disimpan sebagai metadata. Master Penerbitan SPP dapat
  melengkapi Oktober dan periode lain yang sah, mempertahankan tagihan existing,
  serta menyiapkan pasangan Komite. Mengubah awal Komite tidak menghapus histori.
- Bukti yang belum cukup masuk peninjauan Legacy existing. Rekening tabungan
  saja tidak otomatis ditagih. Penetapan eksplisit melalui CRUD tetap tersedia.
- Pelunasan SPP–Komite dan urutan periode tetap diperiksa oleh kasir. Pembayaran
  baru memakai tanggal kas aktual; kredit Legacy bukan penerimaan baru.

## Kasus Raisa, SMP 252607034

| Komponen | Posisi yang diuji |
| --- | ---: |
| PSB pokok | Rp14.295.000 |
| Potongan PSB | Rp6.900.000 |
| PSB bersih | Rp7.395.000 |
| PSB sudah dibayar | Rp7.395.000 |
| Sisa PSB | Rp0 |
| SPP Oktober 2026 | Rp327.500 |
| Komite Oktober 2026 | Rp15.000 |

Kelas tetap **8A**. Juli–September 2026 terbaca lunas menurut periode sumber.
Tarif SPP 2025/2026 Rp297.500 tetap terpisah dari tarif 2026/2027 Rp327.500.

Alur GUI: **Data Siswa → Edit** untuk nilai master/awal Komite; **Master
Penerbitan SPP → Mulai tagihan → Terbitkan SPP** untuk melengkapi periode;
kemudian **Input Pembayaran**. Tidak ada tombol atau menu migrasi baru.

## Bukti pengujian

Server salinan memakai port 58248 dan 58249, UUID serta datadir berbeda dari
target 3306. Artefak privat disimpan di
`C:/laragon/backups/spp-management-system/legacy_monthly_20261024`.

- 15 pemeriksaan perencana murni, termasuk potongan/pembebasan; suite operasional
  dan otomasi Legacy lulus.
- 30 pemeriksaan integrasi: PSB, laporan/kasir, Juli–Oktober, perubahan tarif,
  tagihan historis eksplisit, rekening nonakademik,
  penetapan CRUD tanpa larangan label guru, NIS antarunit, kredit mendatang,
  koreksi/pembatalan, pasangan Komite, dan penolakan pembayaran ganda.
- 7 pemeriksaan HTTP GUI/API/ekspor; 4 pemeriksaan transaksi HTTP aktual pada
  salinan: penerimaan Rp342.500, retry tanpa duplikasi, status lunas, lalu
  penghapusan melalui alur existing yang mengembalikan posisi semula.
- 35 pemeriksaan skema backup dan 123 pemeriksaan kebijakan backup lulus.
- Migrasi sumber SMP baru selesai dengan backup sebelum dan sesudah yang
  berhasil dipulihkan dan diverifikasi. Batch uji
  `d26ba3402e638a0c81f2cd943d7ffba0`; backup sebelum
  `a35656f8d438ea0d73908132aad56246`, sesudah
  `12fe1bb20d6c1e975449a9b07cca5bf3`.
- Seluruh 1.076 posisi PSB dan rencana bulanan siswa SMP setara antara sumber
  baru dan rekonsiliasi existing. Dari 45.614 posisi bulanan, 45.582 mempunyai
  posisi native/kredit yang sama, termasuk 2.204 yang sama-sama belum memiliki
  tagihan native. Sebanyak 32 tagihan existing pada dua siswa dipertahankan:
  kelas bukti pembayaran bertentangan dengan penempatan tahun berjalan, sehingga
  migrasi baru menahan pembentukan tagihan tersebut untuk peninjauan. Perbedaan
  ini dicatat, bukan diselesaikan dengan menimpa tagihan atau mengarang kelas.
- Fixture sumber baru mempertahankan pemetaan eksplisit `U_KEGIATAN` lama.
  Klasifikasi DU otomatis diuji terpisah memakai sumber lengkap: kredit DU
  Raisa tahun 2026/2027 Rp4.310.000. Rekap `bayar_du` tidak menjadi kas kedua.
- Peninjauan existing menampilkan pengecualian bulanan tersimpan. Preview yang
  hanya memuat header siswa tidak mengarang peringatan pembayaran hilang.

`fresh-final-verify.json` mencatat nol perubahan dan nol trigger lama tertunda.
Seluruh hasil, termasuk jumlah/nominal per komponen dan periode, disimpan dalam
JSON privat; dokumen ini tidak memuat data pribadi semua siswa.

## Backup target

Backup penuh `before.sql`, 457.179.362 byte, dipulihkan pada server terpisah.
SHA-256:
`3357499c2ce570e45f8571c2dc820e37f2733e3e6926c4fe84d725b7525d5cd5`.
Seluruh checksum tabel dan fingerprint arsip, identitas, kas, rekening serta
jurnal hasil restore cocok. Runner memeriksa kembali bukti ini di bawah kunci
akses aplikasi sebelum apply; sumber yang berubah memerlukan backup baru.

## Penerapan existing

Runner `--mode=apply` pada **db_spp_test2, port 3306** selesai dengan exit 0.
UUID target `e6e5c8d8-b81c-11f1-9be5-745d2224b37e`. Dua trigger cakupan lama
diselaraskan setelah bukti backup diperiksa. Data diterapkan dalam transaksi
terpisah per unit dan audit disimpan pada tabel existing.

| Unit | Master PSB dilengkapi | Pembayaran direkategori Komite | Tagihan bulanan ditambah | Awal Komite diselaraskan | Kewajiban Legacy ditambah | Baris pengecualian |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| SD | 938 | 31.574 | 46.577 | 398 | 52.867 | 2.056 |
| SMP | 1.046 | 39.452 | 38.594 | 299 | 43.327 | 2.361 |
| SMA | 242 | 7.577 | 7.102 | 66 | 8.154 | 498 |

Tagihan bulanan adalah baris **SPP dan Komite**, bukan jumlah siswa. Tagihan
meliputi posisi sudah dibayar serta periode mendatang yang terbukti; seluruh
baris tersebut bukan tunggakan baru. Pengecualian meliputi bukti kelas, tarif,
masa kewajiban, atau koreksi yang dipertahankan dan dapat bertumpang tindih.

`live-apply.json` menyimpan rincian hasil dan total bulanan per unit. Verifikasi
komponen memisahkan pokok/sisa kewajiban dari jumlah penerimaan per komponen dan
periode; jumlah penerimaan tidak dijumlahkan kembali ke pokok atau kredit PSB.
Agregat komponen penerimaan telah dicocokkan dengan total kas sumber pada uji
migrasi baru.

`live-verify.json` selesai dengan exit 0: **changes 0** pada SD, SMP, dan SMA,
serta **schema_triggers_pending 0**. `live-check.json` mengonfirmasi nilai Raisa
di atas pada target, kesamaan PSB laporan/kasir, dan fingerprint global arsip,
identitas, penerimaan, rekening serta jurnal yang identik dengan backup semula.

Arsip job existing ditemukan pada root privat `spp-legacy-import-test2`,
sedangkan konfigurasi GUI standar membaca `spp-legacy-import/db_spp_test2`.
Tiga job yang cocok dengan hash/unit batch completed disalin ke root standar,
beserta 390.911 baris SQLite, draf persiapan dan file sumber/katalog. Antrean
tujuan sebelumnya kosong dan sudah dibackup. Seluruh salinan file dan draf
diverifikasi; arsip asli dipertahankan. Ini memulihkan akses peninjauan existing,
tanpa menjalankan impor keuangan kedua kali.
Pembaca GUI standar berhasil memuat ketiga job beserta seluruh pengecualian
bulanan tersimpan (SD 2.056, SMP 2.361, SMA 498). Pemeriksaan ini menjalankan
MySQL dalam transaksi baca saja. Server salinan dan server HTTP uji telah
dimatikan; server aplikasi port 3306 tetap berjalan.

## Operasi berikutnya

Gunakan `scripts/legacy_migration_runner.php`; default `preview`. Sumber baru
dan batch existing menjalankan engine yang sama. Panduan masuk ada di
`AGENTS.md`; keberhasilan impor identitas/pembayaran tidak menggantikan tahap
PSB, SPP–Komite, kredit mendatang, tabungan dan verifikasi.

Pengecualian adalah baris temuan, dapat berulang per orang/periode/komponen.
Jangan menjumlahkannya sebagai jumlah siswa, menyebutnya seluruh masalah data,
atau menafsirkan bukti yang belum lengkap sebagai nol/lunas.
