# Pelacakan DU otomatis

Aturan: workflow 11, operasional 15, otomasi 4, billing 3, perencana DU 1.
Metadata pembayaran otomasi versi 1, 2, dan 3 tetap terbaca.

## Jalur yang sama

Unggahan operasional GUI, runner Codex, rekonsiliasi batch completed, serta
**Lengkapi Tagihan yang Belum Ada** pada Master Daftar Ulang menggunakan
`ldu_reconcile()`. Preview menjalankan engine yang sama dalam rollback.
Tanggal kas tidak mengubah tahun kewajiban; periode DU pada item pembayaran
menjadi dasar kredit. Rekap `bayar_du` tidak menjadi kas tambahan.

`legacy_du_planner.php` membuktikan tarif unik sumber menurut tahun dan kelas,
potongan individual eksplisit, serta konsistensi nilai header. `legacy_du.php`
menggabungkan bukti itu dengan siswa/penempatan aktif, tagihan existing,
pembayaran native dan Legacy. Keputusan per unit + siswa + tahun mencatat
pokok, potongan, tagihan bersih, pembayaran, sisa, status, dan jejak asal.

Penerbitan otomatis hanya untuk tahun berjalan dan siswa aktif. Tahun draf
boleh diterbitkan ketika bukti sebagian siswa lengkap, tanpa menunggu seluruh
kelas. Tahun tertutup, siswa lulus, rekening tabungan saja, pembatalan,
penagihan Legacy yang sudah ada dan koreksi pengguna dipertahankan.
Tagihan existing menjadi otoritas pokok; sumber tidak menimpanya.

Bukti tidak lengkap disimpan otomatis sebagai `du_evidence`, tanpa pemetaan
wajib atau persetujuan per siswa. Nominal dan sisa tetap belum diketahui;
kelas tanpa tagihan terbukti ditampilkan dengan tanda `-` pada Master DU.
Pengecualian tidak menghambat siswa lain dan tidak diubah menjadi nol/lunas.

## Relasi dan pembaca

Engine menggunakan Daftar_ulang, tagihan_daftar_ulang, siswa_tahun_ajaran,
legacy_op_obligations, arsip sumber dan audit yang sudah tersedia. Tidak ada
perubahan skema. Pembayaran tetap dalam legacy_op_payments; tidak disalin
menjadi bayar/bayar_du native.

Metadata ringkas kewajiban menghubungkan master, tagihan, penempatan dan hash
rincian. Audit `du_trace` menyimpan transaksi sumber, ID, hash backup, row_key,
kolom, periode, nominal, tarif sumber, serta rekap yang cocok. Urutan rincian
deterministik. Koreksi/pembatalan resmi memperbarui kredit dan jejak audit,
tanpa menghapus histori sumber.

Kasir memakai du_find_bill_by_id dan kredit tahun yang sama. Ringkasan
kewajiban menggunakan pembaca itu juga. Riwayat DU mengelompokkan setiap
rincian menurut tahun DU, termasuk transaksi dengan tanggal kas berbeda.
Master DU menghitung pembayaran native + kredit Legacy. Filter sumber riwayat memilih pemilik/periode; status tetap memperhitungkan seluruh pembayaran sah. Laporan dan ekspor
memakai posisi kewajiban yang sama. Identitas selalu dipisahkan per unit.

Verifikasi DU di runner membandingkan semua posisi terbukti pada kasir,
riwayat dan laporan serta memeriksa master dan digest jejak sumber. Sebelum
commit, batch belum dipublikasikan sehingga pembaca publik tetap menyembunyikannya;
pemeriksaan kas/tagihan/master tetap berjalan. Worker mengulang verifikasi
pembaca lengkap setelah publikasi, sebelum menyatakan job imported.

## Pengujian

- `tests/legacy_du_planner_test.php`: 21 fixture murni, termasuk potongan,
  pembebasan, cicilan, lebih bayar, nominal belum diketahui, dan kredit tahun mendatang.
- `tests/legacy_du_integration_test.php`: 224 pemeriksaan pada salinan terisolasi;
  termasuk Raisa, koreksi tagihan, gabungan pembayaran native/Legacy,
  pembayaran ganda, pembatalan, tahun tertutup, siswa tidak aktif, bukti parsial,
  tagihan manual existing dan pengulangan nol perubahan.
- `tests/legacy_migration_automation_transactions_test.php --draft --publication --du-publication`:
  publikasi sumber baru, rollback kegagalan sebelum commit, backup/restore,
  kas lintas tanggal/tahun, rekap ganda, pelacakan biaya, dan penerapan ulang.
- `tests/legacy_du_browser_test.js`: 31 pemeriksaan Master SD/SMP/SMA,
  tombol pelengkap existing, riwayat Raisa, Input Pembayaran, Excel dan PDF.
- Regresi bulanan, Komite, biaya lain dan struk tetap dijalankan.

Pengujian penulisan hanya pada server fixture/salinan dengan penjagaan target.
Penerapan existing tetap memerlukan backup terbaru yang diuji pemulihannya,
identitas server yang cocok, transaksi per unit dan perbandingan fingerprint
arsip, identitas, kas serta tabungan. Ikuti LEGACY_MIGRATION_RUNBOOK.md.
