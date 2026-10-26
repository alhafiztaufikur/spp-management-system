# Audit awal tagihan SPP sesudah migrasi, 24 Oktober 2026

Pemeriksaan baca saja pada `db_spp_test2`, sumber SQL Server yang telah terpasang,
dan fungsi pembaca Laporan Global. Tidak mengubah kode aplikasi, konfigurasi,
tagihan, pembayaran, penempatan, atau keputusan migrasi. Temuan ini menyangkut
kelengkapan kewajiban sesudah migrasi; audit tiga kategori anomali sumber
sebelumnya tidak menilai kelengkapan seluruh tagihan historis.

## Penyebab awal tagihan November 2026

`lful_next_period()` pada `includes/legacy_full_workflow.php` memilih bulan
sesudah `cutoff`. Migrasi operasional pada `includes/legacy_operational.php`
menyimpan hasilnya ke `legacy_full_students.settings.start_period`, kemudian
menerbitkan SPP siswa aktif mulai bulan yang sama.

Tanggal peralihan yang tercatat:

| Unit | Cutoff | Awal tagihan operasional |
| --- | --- | --- |
| SD | 19 Oktober 2026 11:00 WIB | November 2026 |
| SMP | 19 Oktober 2026 22:39 WIB | November 2026 |
| SMA | 20 Oktober 2026 00:09 WIB | November 2026 |

Seluruh `start_period` siswa hasil migrasi pada database ini bernilai `2026-11`.
Penerbitan aktual hanya mencakup siswa yang memenuhi syarat, dengan periode
November 2026 sampai Juni 2027:

| Unit | Siswa dengan tagihan SPP | Jumlah tagihan bulanan |
| --- | ---: | ---: |
| SD | 414 | 3.312 |
| SMP | 301 | 2.408 |
| SMA | 67 | 536 |

Awal ini merupakan batas peralihan pencatatan, bukan hasil pencarian bulan
pembayaran terakhir masing-masing siswa. `spp_publish_students()` juga
melewati periode yang lebih awal dari `settings.start_period`.

## Histori pembayaran tetap tersedia dan dikreditkan

Pembayaran Legacy menyimpan periode sumber, komponen, nominal, dan identitas
pemilik. Pembaca `lb_spp_credit()` dan `spp_open_bills()` memperhitungkan kredit
Legacy untuk tagihan yang periodenya sesuai. Laporan Global menambahkan
pembayaran Legacy menurut bulan kewajiban, terpisah dari tanggal kas.

| Unit | Siswa sudah membayar November pada sumber | Bulan tagihan November-Juni dengan kredit Legacy |
| --- | ---: | ---: |
| SD | 25 | 96 |
| SMP | 18 | 51 |
| SMA | 2 | 4 |

Total 151 pasangan siswa/bulan tersebut mempunyai nominal tagihan yang sama
dengan kredit sumber; hasil penghitungan sisanya nol. Angka siswa pada kolom
kedua juga dibandingkan dengan pembayaran `U_SPP>0`, `BULAN=11`, `TAHUN=2026`
di ketiga sumber SQL Server. Kredit tersebut bukan penerimaan kas baru.

## Hasil pembacaan Laporan Global

Fungsi aktual `report_monthly_evidence()` dijalankan untuk kategori SPP, siswa
aktif, dan bulan Juli-November 2026, dengan transaksi MySQL `READ ONLY` dan
konteks unit masing-masing. Aplikasi web/antrean tidak dipanggil.

| Unit | Baris laporan | Oktober: Ada Pembayaran | Oktober: Belum Diketahui | November: Lunas |
| --- | ---: | ---: | ---: | ---: |
| SD | 427 | 346 | 81 | 25 |
| SMP | 303 | 262 | 41 | 18 |
| SMA | 67 | 60 | 7 | 2 |

Baris laporan adalah kelompok siswa/kelas/tahun sesuai pembaca laporan;
jumlahnya bukan selalu jumlah siswa yang mempunyai tagihan November. Seluruh
baris pada cakupan tersebut mempunyai penanda `_incomplete=true` karena
terdapat pokok/sisa historis yang belum diketahui.

Contoh SMP NIS `262707023` pada model laporan:

- Oktober: pembayaran Rp620.000; pokok tagihan NULL; sisa NULL; status
  **Ada Pembayaran**.
- November: pokok Rp620.000; pembayaran Rp620.000; sisa Rp0; status **Lunas**.
- Untuk Juli-November, total tagihan yang diketahui Rp620.000, total pembayaran
  Rp2.480.000, dan tunggakan NULL. Total tersebut memiliki cakupan bukti yang
  berbeda sehingga tidak boleh dibaca sebagai posisi tagihan tahunan lengkap.

Tidak ada baris pada `legacy_full_coverage_data` maupun `legacy_debts_data`
saat pemeriksaan. Kewajiban SPP pada `legacy_op_obligations_data` hanya mencakup
November 2026 dan setelahnya. Ringkasan migrasi menyimpan
`obligations_unreviewed` SD 414, SMP 301, SMA 67; angka ringkasan ini adalah
catatan saat migrasi, bukan penghitungan status peninjauan terbaru per siswa.

## Rekonsiliasi yang diperlukan

Riwayat `U_SPP`, `BULAN`, `TAHUN`, unit, dan NIS dapat dipakai untuk membuktikan
pembayaran per bulan. Menentukan kewajiban dan sisa bulan yang tidak mempunyai
pembayaran memerlukan bukti tambahan tentang status siswa/penempatan, tarif
yang berlaku, potongan, pembebasan, serta saldo tunggakan pada peralihan.
Pembayaran terakhir saja tidak cukup untuk mendeteksi bulan yang terlewat atau
pembayaran di muka.

Sumber juga mempunyai tabel pembanding `rekap_belum_spp` dan `temp_belum_spp`.
`rekap_belum_spp` berisi tahun `2026`: SD 65 baris, SMP 103, SMA 23. Cakupan
siswa, arti nilai bulanan, tahun ajaran, tanggal cetak, dan konsistensinya
dengan pembayaran perlu diverifikasi sebelum dijadikan kewajiban. Keberadaan
tabel tersebut belum membuktikan rekap lengkap dan benar bagi seluruh siswa.

Hasil yang diperlukan adalah posisi per siswa/per bulan: kewajiban yang
terbukti, pembayaran yang telah terjadi, sisa, atau status bukti belum lengkap.
Laporan tahunan harus menjelaskan bagian yang belum direkonsiliasi. Histori
pembayaran tetap dihitung sekali dan tidak dibuat ulang menjadi penerimaan.
