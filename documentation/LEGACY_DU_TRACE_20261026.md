# Pelacakan DU otomatis

Aturan: workflow 12, operasional 16, otomasi 5, billing 4, perencana DU 2.
Metadata pembayaran otomasi versi 1, 2, 3, dan 4 tetap terbaca.

## Jalur yang sama

Unggahan operasional GUI, runner Codex, rekonsiliasi batch completed, serta
**Simpan Tarif & Lengkapi Tagihan** pada Master Daftar Ulang menggunakan
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

- `tests/legacy_du_planner_test.php`: 45 fixture murni, termasuk potongan,
  pembebasan, cicilan, lebih bayar, nominal belum diketahui, dan kredit tahun mendatang.
- `tests/legacy_du_integration_test.php`: 224 pemeriksaan pada salinan terisolasi;
  termasuk Raisa, koreksi tagihan, gabungan pembayaran native/Legacy,
  pembayaran ganda, pembatalan, tahun tertutup, siswa tidak aktif, bukti parsial,
  tagihan manual existing dan pengulangan nol perubahan.
- `tests/legacy_migration_automation_transactions_test.php --draft --publication --du-publication`:
  publikasi sumber baru, rollback kegagalan sebelum commit, backup/restore,
  kas lintas tanggal/tahun, rekap ganda, pelacakan biaya, dan penerapan ulang.
- `tests/legacy_du_browser_test.js`: 38 pemeriksaan Master SD/SMP/SMA,
  tombol pelengkap existing, riwayat Raisa, Input Pembayaran, Excel dan PDF.
- Regresi bulanan, Komite, biaya lain dan struk tetap dijalankan.

Pengujian penulisan hanya pada server fixture/salinan dengan penjagaan target.
Penerapan existing tetap memerlukan backup terbaru yang diuji pemulihannya,
identitas server yang cocok, transaksi per unit dan perbandingan fingerprint
arsip, identitas, kas serta tabungan. Ikuti LEGACY_MIGRATION_RUNBOOK.md.

## Master DU dan kelas awal (aturan DU 2)

`du_master_save_and_complete()` mengunci tahun, memvalidasi seluruh input nonkosong,
menyimpan tarif dan menjalankan perencana DU dalam transaksi unit yang sama.
Satu aksi **Simpan Tarif & Lengkapi Tagihan** berlaku pada Draf/Terbit tahun berjalan.
Kelas lain boleh kosong. Tahun tertutup terkunci; tunggakan lama bersifat informasi.
Tarif admin merupakan dasar operasional yang sah untuk siswa migrasi maupun native.
Potongan individual yang terbukti dipakai; tanpa penetapan individual, kebijakan
baru adalah tarif penuh. Audit tidak menyebut kebijakan ini sebagai temuan potongan
nol dari Legacy. Tagihan terbayar, pembebasan, pembatalan, koreksi dan snapshot
individual dipertahankan. Riwayat penerimaan tidak digandakan.

Perencana menerima header individual yang lengkap dan konsisten pada tahun
peralihan, meskipun tarif kelas sumber tidak tersedia. Pemetaan master sumber yang
secara eksplisit bernama DU/Daftar Ulang serta memiliki tahun/kelas DU dipakai
untuk mengategorikan rincian. Transaksi existing yang belum tepat kategorinya
mengikuti `ldu_reconstruct_payments()`; transaksi yang pernah dikoreksi dipertahankan.
Header, tarif, rincian/rekap, hash, baris dan slot tetap menjadi bukti yang terlacak.
Kelas awal 1/7/10 tidak dikecualikan. Tarif tahun lama, PSB lunas, atau jumlah kas
saja tidak boleh menjadi nominal kewajiban tahun berjalan.

Master menampilkan jumlah siswa aktif, yang memiliki/belum memiliki tagihan,
dasar tarif dan total tagihan **tersimpan**. Mengetik tarif tidak mengubah total
terbit. Nilai belum diketahui ditampilkan `-`; masukan kosong tidak menjadi nol.

Regresi tambahan wajib: `tests/du_master_service_test.php` pada salinan terisolasi
(SD/SMP/SMA, penambahan tarif pada tahun Terbit, input parsial, snapshot paid,
pembatalan, validasi atomik, laporan/kasir dan replay). Abdullah `262701104` harus
tetap 1C; nominal Rp3.500.000 pada test hanya fixture, bukan tarif database pengguna.
`tests/legacy_du_planner_test.php` menguji header kelas 1 tanpa tabel tarif, sumber
tidak cukup, pemetaan DU eksplisit, periode kas berbeda dan pelestarian koreksi.
Publikasi fixture `--du-publication` membuktikan kelas awal dari header individual
meskipun tidak ada master tarif sumber. Jalankan browser/export setelah penerapan
salinan. Backup terbaru dan uji restore berbeda server tetap wajib sebelum target.

Regresi rekategorisasi tersimpan: `tests/legacy_du_reclassification_test.php` pada
fixture UUID/port terisolasi. Slot berulang, kode nol depan, tahun DU berbeda
dengan tahun kas, pembatalan, audit koreksi, referensi master lama dan replay
wajib dipertahankan tanpa mengubah penerimaan.

Regresi Master DU saat ini:
- `tests/du_master_service_test.php`: 627 pemeriksaan salinan SD/SMP/SMA;
  kelas awal pada tahun Terbit, tarif parsial, posisi per siswa, snapshot terbayar,
  pembatalan, tahun tertutup, nominal tidak sah dan pengulangan nol perubahan.
- `tests/du_master_draft_test.php`: 9 pemeriksaan fixture terisolasi untuk tahun
  Draf, kelas lain kosong, penerbitan native, pembebasan, siswa tidak aktif dan
  audit yang membedakan tarif admin dari Legacy.
- `tests/legacy_du_reclassification_test.php`: 12 pemeriksaan rekategorisasi,
  dua slot berkode sama, tahun kewajiban, pembatalan, koreksi dan jejak master lama.
- `tests/legacy_billing_digest_test.php`: 5 pemeriksaan koreksi SPP yang sah,
  pembaruan fingerprint dan penolakan perubahan yang tidak dijelaskan.
- `tests/legacy_du_browser_test.js`: 38 pemeriksaan GUI, sumber/native yang
  memakai posisi sama, kasir, struk, PDF/Excel, penyimpanan kelas 1 dan layar kecil.

Pengujian memakai salinan/fixture, bukan penetapan tarif pada database pengguna.
