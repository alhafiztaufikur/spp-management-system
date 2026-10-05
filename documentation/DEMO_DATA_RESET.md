# Reset Baseline Demo

> **Pembaruan 5 Oktober 2026:** definisi seed/reset historis yang masih memakai Pangkal/persen telah dipensiunkan dan menolak eksekusi. Seeder multiunit terbaru dan instalasi kosong diuji pada skema PSB/potongan nominal. Instruksi historis di bawah tidak boleh digunakan untuk mereset database trial; lihat [audit perubahan](PSB_NOMINAL_DATE_AUDIT_20261005.md).

Reset ini hanya untuk **clone disposable** bernama `db_spp_audit_*` atau `db_spp_test_*`. Skrip menghapus siswa, pembayaran, tagihan, tabungan dan audit terkait. Akun operator, Master Kelas, Master Biaya Lain, dan struktur database dipertahankan. Jangan gunakan pada database sekolah, termasuk `db_spp` atau database Railway yang aktif.

Sebelum menjalankan, buat clone dari backup yang sesuai dan pastikan nama target pada koneksi. Jalankan dari CLI dengan `SPP_DB_NAME` eksplisit dan `SPP_TEST_ALLOW_MUTATION=1`:

```powershell
$env:SPP_DB_NAME = 'db_spp_test_demo_reset_contoh'
$env:SPP_TEST_ALLOW_MUTATION = '1'
php sql/run_legacy_sql.php --script=reset_demo_students_and_finance.sql --apply --confirm-script=reset_demo_students_and_finance.sql
php sql/run_legacy_sql.php --script=seed_students_psb.sql --apply --confirm-script=seed_students_psb.sql
```

Periksa hasil: 150 siswa aktif, 144 reguler, 6 PSB, 30 siswa dengan sisa Pangkal, 1.728 tagihan SPP, 1.728 tagihan Komite, 144 tagihan Daftar Ulang, dan nol pembayaran dan mutasi. Untuk data laporan demo, jalankan hanya jika pembayaran masih nol:

```powershell
php sql/run_legacy_sql.php --script=seed_demo_payments.sql --apply --confirm-script=seed_demo_payments.sql
```

Hasilnya harus 988 pembayaran tanpa mutasi Tabungan. Jika clone pernah menerima transaksi baru, buat ulang clone lalu ulangi urutan dari awal. Jalur `sql/*.sql` lama sengaja menolak impor langsung; jangan impor berkas `sql/definitions/` secara manual. Daftar dan kategori semua skrip lama ada di [operasi SQL legacy](LEGACY_SQL_OPERATIONS.md).
