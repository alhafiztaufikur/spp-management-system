# Master biaya dan transaksi Legacy, 26 Oktober 2026

Relasi master pada migrasi test3 sudah tersimpan di kategori dan metadata rincian
Legacy. Halaman master sebelumnya hanya menghitung `bayar_biaya_lain`, sehingga
transaksi Legacy terlihat nol dan master terpakai masuk tab Bisa Dihapus.

## Perilaku sesudah perbaikan

- `includes/other_fee_usage.php` membaca native, Legacy operasional dan arsip lama
  berdasarkan unit + ID master. Kategori transaksi efektif menentukan pemakaian;
  metadata sumber tidak menggantikan kategori hasil koreksi pengguna.
- Transaksi menghitung struk berbeda. Jumlah rincian dan nominal menghitung slot;
  kode berulang dalam satu struk tetap mempunyai rincian terpisah.
- Angka transaksi menuju `pembayaran/lihat.php?fee_master_id=ID&all_periods=1`.
  Parameter ini konteks navigasi, tanpa kontrol filter/menu/tombol baru.
  Master wajib tersedia dalam lingkup unit yang diizinkan pada koneksi.
- Pencarian, pagination, detail, struk, konteks kembali dan ekspor mempertahankan
  master. Riwayat menampilkan nominal komponen di samping total seluruh struk;
  Excel/PDF menambahkan nominal komponen agar kedua ukuran tidak tertukar.
- Master sumber tetap nonaktif dengan tarif belum ditetapkan. Tidak ada tarif,
  tagihan atau penerimaan native baru yang dibuat dari histori pembayaran.
- Daftar dan POST hapus memakai pemeriksaan yang sama. Pemakaian aktif, batal,
  audit koreksi, kewajiban dan tagihan melindungi master. Master dikunci sebelum
  pemeriksaan hapus; penulisan referensi Legacy mengambil kunci yang selaras.
  Kegagalan pembacaan sumber tidak pernah dianggap nol pemakaian.
- Runner menyertakan `verification_fee_trace.master_usage`, membandingkan jumlah
  struk, slot dan nominal master dengan transaksi sumber pada setiap unit.

## Pengujian

Server salinan: port **3319**, UUID
`8d21f5ab-d0ab-11f1-86d7-745d2224b37e`, datadir privat berbeda dari pengguna,
event scheduler OFF. Pengujian HTTP melalui server PHP lokal port 8831.

- `tests/other_fee_master_usage_transactions_test.php`: **31 pemeriksaan**.
  Empat slot, kode berulang/nol di depan, tiga penyimpanan pembayaran, metadata
  lama, lintas unit, arsip native/Legacy batal, audit koreksi, kunci serentak,
  pemakaian kosong dan penolakan sumber yang gagal dibaca. Fixture di-rollback.
- `tests/other_fee_master_usage_http_test.py`: **48 pemeriksaan**. Master sampai
  riwayat/detail/struk/PDF/Excel; POST langsung dan penambahan pemakaian sesudah
  halaman dibuka. Fixture HTTP dibersihkan pada salinan.
- Fixture murni pelacakan biaya: **56 pemeriksaan** lulus. Suite penulisan
  `legacy_migration_automation_transactions_test.php` lulus pada database fixture
  bersih terpisah di server 3319; semua penulisan fixture di-rollback.
- Runner resmi `--mode=verify` pada salinan: SD/SMP/SMA **changes: 0**, dengan
  pemakaian **39 / 51 / 23 master** diverifikasi.
- SD Buku Ummi: **2.873 struk, 3.547 rincian, Rp67.177.500**. Excel memuat tepat
  2.873 transaksi dan jumlah kolom nominal komponen cocok. Seluruh 39 master SD
  terpakai, sehingga Bisa Dihapus menjadi **0** pada snapshot pengujian.

Artefak privat:
`C:/laragon/data/spp-migrations/db_spp_test3/20261026-fee-master`.
Tidak ada migrasi ulang, perubahan skema atau backfill pada database pengguna.
Checksum **seluruh 62 tabel** `db_spp_test3` pada port 3306 sama sebelum dan
sesudah pekerjaan. Bukti: `final-result.json` pada direktori artefak privat.
Perubahan pengguna pada `.gitignore` dan `koneksi.php` dipertahankan.
