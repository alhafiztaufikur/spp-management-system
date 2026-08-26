# Kontrak Data Finansial SistemSPP

Dokumen ini menetapkan sumber kebenaran, data turunan, relasi, dan invariant yang harus dipenuhi sebelum SistemSPP dinyatakan siap diserahkan kepada client. Baseline kontrak disusun dari schema dan implementasi aktif pada 20 Agustus 2026, lalu ditinjau ulang pada 27 Agustus 2026 untuk mencerminkan relasi pembayaran, audit append-only, dan aturan input yang kini aktif. Bila dokumentasi lain berbeda, kontrak ini harus diverifikasi kembali terhadap kode dan schema sebelum dijadikan dasar perubahan.

## Prinsip dasar

- Tanggal penerimaan uang adalah `bayar.TGL_BYR`; periode kewajiban SPP adalah `bayar_spp_periode.bulan + tahun`. Keduanya tidak boleh dipertukarkan.
- Header `bayar` adalah transaksi/kuitansi. Detail DU dan Biaya Lain hanya sah bila terhubung ke header melalui `bayar_id`.
- Tarif historis SPP dan Komite berasal dari snapshot `siswa_tahun_ajaran`, bukan tarif aktif siswa saat laporan historis dibuat.
- Tagihan DU dan Biaya Lain adalah kewajiban materialized. Pembayaran tidak boleh membuat atau menebak kewajiban baru.
- Kolom `total_jumlah`, cache pembayaran awal pada `siswa`, dan `tabungan.SALDO` adalah data turunan. Nilainya harus dapat direkonsiliasi dengan ledger sumber.
- Rekonsiliasi legacy tidak boleh dijalankan otomatis oleh verifier. Verifier hanya melaporkan anomali.
- Seluruh pembandingan uang pada verifier memakai toleransi Rp0,01 karena sebagian kolom legacy masih bertipe `DOUBLE`.

## Peta sumber kebenaran

| Domain | Kewajiban / tarif resmi | Pembayaran / ledger resmi | Snapshot dan relasi | Data turunan / kompatibilitas |
| --- | --- | --- | --- | --- |
| Uang Pangkal | `siswa.PANGKAL`, `potong_pangkal`, `tot_pangkal` | `bayar.U_PANGKAL` | Header terhubung ke siswa melalui `NO_INDUK` | `siswa.PANGKAL_BAYAR` adalah cache total terbayar |
| Uang Bangunan | `siswa.BANGUNAN` | `bayar.U_BANGUNAN` | Header pembayaran | `siswa.BANGUNAN_BAYAR` adalah cache |
| Uang Seragam | `siswa.SERAGAM` | `bayar.U_SERAGAM` | Header pembayaran | `siswa.SERAGAM_BAYAR` adalah cache |
| Uang Kegiatan | `siswa.KEGIATAN` | `bayar.U_KEGIATAN` | Header pembayaran | `siswa.KEGIATAN_BAYAR` adalah cache |
| SPP | `siswa_tahun_ajaran.spp_perbulan_snapshot` | `bayar.U_SPP` | `bayar_spp_periode.bayar_id` memetakan kuitansi ke bulan/tahun | `bayar.BULAN/TAHUN` dipertahankan untuk kompatibilitas dan harus sama dengan claim |
| Komite | `siswa_tahun_ajaran.komite_snapshot` untuk histori; `siswa.POMG` untuk tahun berjalan sebelum snapshot | `bayar.U_KOMITE` | Periode pada header dan penempatan tahun ajaran | Tidak ada tabel claim khusus Komite |
| Makan | `siswa.MAKAN` | `bayar.U_MAKAN` | Header pembayaran | Kewajiban satu kali; belum mempunyai snapshot lintas tahun |
| Sorga | `siswa.SORGA` | `bayar.U_SORGA` | Header pembayaran | Kewajiban satu kali; belum mempunyai snapshot lintas tahun |
| Infaq | `siswa.INFAQ` | `bayar.U_INFAQ` | Header pembayaran | Kewajiban satu kali; belum mempunyai snapshot lintas tahun |
| Daftar Ulang | `tagihan_daftar_ulang.nominal_tagihan` | `bayar_du.jumlah` | `bayar_du.tagihan_daftar_ulang_id` dan `bayar_du.bayar_id` | `Daftar_ulang` adalah tarif kelas; field DU pada `siswa` hanya mirror/override kompatibilitas |
| Biaya Lain | `tagihan_biaya_lain.nominal_tagihan` | `bayar_biaya_lain.nominal_snapshot` | Detail terhubung ke tagihan dan header | `bayar.U_LAIN` serta empat slot `LAIN_LAIN*`/`JUMLAH*` adalah mirror legacy, bukan ledger tambahan |
| Potongan SPP | Nilai yang disetujui pada `bayar.potong_spp` | Mengurangi kas diterima, tidak mengurangi kredit kewajiban `U_SPP` | Satu header pembayaran | Harus `0 <= potong_spp <= U_SPP` |
| Tabungan | Tidak ada tagihan | Setoran manual `transaksi_m` dengan `bayar_id IS NULL`; penarikan `transaksi_k` | Keduanya terhubung ke siswa | `tabungan.SALDO` adalah cache ledger; linked saving pembayaran sudah dinonaktifkan |
| Operator | `admin.id` | `bayar.user_id`, `transaksi_m.user_id`, `transaksi_k.user_id` menyimpan ID sebagai string pada transaksi baru | Lookup ke `admin.id` dilakukan aplikasi | Nama operator nonnumerik hanya dapat diperlakukan sebagai histori legacy |
| Kelas/tarif historis | `siswa_tahun_ajaran` | Tidak ada mutasi balik dari laporan | `kelas_rombel_snapshot`, snapshot SPP, snapshot Komite | `siswa.KELAS/master_kelas_id` adalah kondisi aktif, bukan histori |

## Rumus dan batas domain

Untuk pembayaran aman (`payment_link_version = 1`):

```text
total_jumlah =
  U_PANGKAL + U_BANGUNAN + U_SERAGAM + U_KEGIATAN
  + U_SPP + U_MAKAN + U_SORGA + U_INFAQ + U_KOMITE
  + SUM(bayar_du.jumlah)
  + SUM(bayar_biaya_lain.nominal_snapshot)
  - potong_spp
```

`U_LAIN` tidak ditambahkan lagi karena nilainya adalah mirror dari detail Biaya Lain. Semua nominal pembayaran, tagihan, jurnal, diskon, total, dan saldo harus finite serta tidak negatif. Transaksi pembayaran bernilai nol belum ditolak oleh schema dan harus ditangani sebagai temuan aplikasi terpisah.

Saldo tabungan harus memenuhi:

```text
tabungan.SALDO =
  SUM(transaksi_m.MASUK WHERE bayar_id IS NULL)
  - SUM(transaksi_k.KELUAR)
```

## Invariant audit INV-001 sampai INV-017

Kolom **Level gate** di bawah adalah tingkat pengendalian (seluruh invariant
merupakan release gate), bukan hasil eksekusi terakhir. Hasil observasi harus
diambil dari output `sql/verify_data_integrity.sql` yang menyebut database dan
waktu run. Pada salinan audit `db_spp_audit_20260820_090000`, laporan
`RECONCILIATION_REPORT.md` mencatat seluruh INV-001 sampai INV-017 `PASS` setelah
rekonsiliasi terkontrol; status baseline/queue historis tetap dipertahankan
sebagai bukti dan tidak boleh dianggap sebagai status produksi terkini.

| ID | Invariant | Level gate | Catatan legacy |
| --- | --- | --- | --- |
| INV-001 | `payment_link_version` hanya `0` atau `1`; header legacy versi `0` tidak boleh memiliki child yang diklaim aman melalui `bayar_id`. | FAIL | Header versi `0` tanpa child tetap legal dan memerlukan rekonsiliasi manual bila akan dimutasi. |
| INV-002 | `total_jumlah` pembayaran versi `1` sama dengan rumus komponen resmi. | FAIL | Header versi `0` dikecualikan karena representasi total lama belum selalu dapat dibuktikan. |
| INV-003 | Setiap `bayar.U_SPP > 0` mempunyai tepat satu claim `bayar_spp_periode`. | FAIL | Claim dapat direkonstruksi hanya melalui migrasi/reconciliation yang ditinjau; verifier tidak melakukan backfill. |
| INV-004 | Claim SPP cocok dengan header: siswa, bulan normalisasi, tahun, dan nominal SPP positif. | FAIL | Format nama bulan Indonesia dan angka 1/01 dianggap ekuivalen. |
| INV-005 | Setiap child DU yang memiliki `bayar_id` cocok dengan `bayar.NO_INDUK` dan header versi `1`. | FAIL | Child DU dengan `bayar_id IS NULL` dicatat terpisah sebagai legacy, bukan otomatis dipasangkan. |
| INV-006 | Child DU yang memiliki `tagihan_daftar_ulang_id` cocok pada siswa, kelas, dan tahun ajaran snapshot. | FAIL | Child tanpa tagihan adalah legacy yang harus direkonsiliasi sebelum laporan tagihan dipercaya. |
| INV-007 | Total pembayaran suatu tagihan DU tidak melebihi `nominal_tagihan`. | FAIL | Berlaku untuk tagihan open maupun cancelled agar histori tidak disembunyikan. |
| INV-008 | Detail Biaya Lain yang terhubung ke tagihan cocok dengan siswa header, master, dan identitas tagihan. | FAIL | Detail tanpa tagihan dapat merupakan hasil migrasi legacy dan tetap dilaporkan untuk rekonsiliasi. |
| INV-009 | Total pembayaran suatu tagihan Biaya Lain tidak melebihi `nominal_tagihan`. | FAIL | Status cancelled tidak menghapus histori pembayaran. |
| INV-010 | Cache `*_BAYAR` pada siswa sama dengan agregat komponen pembayaran awal. | FAIL | Perbedaan tidak diperbaiki otomatis karena perlu memastikan baseline legacy. |
| INV-011 | Cache saldo tabungan sama dengan ledger setoran manual dikurangi penarikan. | FAIL | Ketidaksesuaian harus menghentikan release dan direkonsiliasi dengan bukti transaksi. |
| INV-012 | Tidak ada lagi `transaksi_m` dengan `bayar_id IS NOT NULL`. | FAIL | Cleanup linked saving harus dijalankan terkontrol dan hanya setelah backup serta pemeriksaan saldo. |
| INV-013 | Seluruh nilai uang utama tidak `NULL`/negatif dan diskon SPP tidak melebihi kredit SPP. | FAIL | Schema legacy yang nullable tetap diperiksa sebagai data, meskipun kolom belum dimigrasikan. |
| INV-014 | Setiap siswa aktif mempunyai penempatan aktif dan tagihan DU open pada tahun ajaran published yang mencakup tanggal audit. | FAIL | Jika kebijakan mengizinkan tahun berjalan tanpa tagihan, invariant ini harus diubah melalui keputusan bisnis tertulis. |
| INV-015 | Identitas tagihan DU sama dengan penempatan dan tahun ajarannya; snapshot wajib terisi. | FAIL | Snapshot historis tidak mengikuti perubahan data siswa aktif. |
| INV-016 | Rentang tanggal antar tahun ajaran tidak tumpang tindih dan maksimal satu tahun published mencakup satu tanggal. | FAIL | Database belum memiliki exclusion constraint; verifier menjadi gate operasional. |
| INV-017 | Operator transaksi baru dapat dipetakan ke `admin.id`; ID numerik yatim ditolak. | FAIL | Nama operator nonnumerik hanya diterima pada histori yang benar-benar legacy dan harus terlihat pada laporan rekonsiliasi. |

Implementasi executable untuk invariant tersebut berada di `sql/verify_data_integrity.sql`. Semua query di file itu read-only dan menghasilkan `PASS` atau `FAIL`; status `FAIL` pada suatu run adalah release blocker sampai dianalisis dan diberi keputusan tertulis. Jangan menyalin angka baseline pada tabel rekonsiliasi sebagai hasil run baru.

## Perilaku legacy yang dipertahankan

- Pembayaran versi `0` tidak boleh diedit atau dihapus aplikasi dan tidak dicocokkan otomatis berdasarkan NIS, tanggal, bulan, kelas, atau tahun ajaran.
- Child DU/detail Biaya Lain tanpa relasi tagihan tetap dapat tampil sebagai histori, tetapi tidak boleh dipakai untuk menyimpulkan saldo tagihan modern tanpa rekonsiliasi.
- Nama operator legacy boleh tetap berupa teks. Transaksi versi `1` harus menyimpan ID admin yang dapat dipetakan.
- `BULAN` pada header lama dapat berisi nama Indonesia, angka tanpa nol, atau kode dua digit. Claim SPP selalu memakai kode dua digit.
- `laporan/rekap_kelas.php` adalah referensi tampilan yang dilindungi. Audit boleh membandingkan hasilnya dengan sumber data resmi, tetapi tidak boleh mengusulkan penghapusan file tersebut.

## Keputusan yang belum dapat dikunci dari schema

1. Apakah tahun ajaran `closed` menutup perubahan tarif saja atau juga melarang penerimaan tunggakan baru. Implementasi saat ini masih dapat membaca tagihan open pada tahun closed.
2. Apakah Makan, Sorga, dan Infaq benar-benar kewajiban sekali selama siswa terdaftar atau perlu snapshot per tahun ajaran.
3. Apakah cache biaya awal boleh mempunyai saldo pembuka sebelum SistemSPP. Bila iya, diperlukan tabel baseline eksplisit; tanpa itu INV-010 menganggap agregat `bayar` sebagai sumber penuh.
4. Apakah transaksi tabungan perlu keterangan yang tersimpan dan idempotency key untuk mencegah submit ganda. Schema saat ini tidak menyediakan keduanya.
5. Kapan migrasi `DOUBLE` ke `DECIMAL` dapat dilakukan tanpa memutus kompatibilitas data lama.

## Cara menjalankan verifier

Jalankan hanya setelah memilih database yang benar dan menggunakan akun read-only bila tersedia:

```powershell
Get-Content sql\verify_data_integrity.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root --table
```

Verifier tidak memperbaiki data. Simpan output sebagai bukti audit, buat backup sebelum proses rekonsiliasi terpisah, dan jalankan ulang sampai seluruh invariant berstatus `PASS`.
