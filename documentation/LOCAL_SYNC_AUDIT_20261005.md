# Pembaruan lokal 5 Oktober 2026

## Kode

- `main` diperbarui dari `dd4b3c9` ke `34af82e` melalui fast-forward: tiga commit (`2bb074b`, `ba8f5f7`, `34af82e`), 104 berkas.
- Pangkal dipensiunkan, pembayaran historisnya dibaca sebagai PSB; potongan SPP memakai nominal rupiah dan tanggal ditampilkan dalam format Indonesia/WIB. NIS Diknas opsional dengan validasi 10 digit jika diisi.
- Perubahan lokal pada `backup_restore.php`, `includes/legacy_schema.php`, serta sumber dan PDF alur kerja dipertahankan. Panduan 50 halaman diperbarui sesuai aturan terbaru.
- Setelah pembaruan ditemukan dua header dokumen berurutan pada Laporan Global, template laporan, Surat Orang Tua, Master SPP, dan Aktivasi Legacy. Header digabung menjadi satu dengan stylesheet utama dan kontrol tanggal tetap dimuat.
- Tidak dilakukan commit atau push.

## Database

Database lokal memakai MySQL 8.0.30. Journal `psb_nominal_20261005` sudah berstatus `complete` sejak 05/10/2026 13:51:27; kolom baru telah tersedia. Konversi pembayaran tidak dijalankan ulang.

Namun seluruh CHECK hilang pada database yang diperiksa, sehingga health HTTP 503 meskipun nilai transaksi lolos audit. Sesudah membuat dump baru dan menguji restore pada clone khusus, skrip resmi `sql/restore_multiunit_checks.php` memulihkan 18 CHECK. CHECK status Legacy dipulihkan dengan definisi yang sama seperti migrasi Legacy. Kedua perubahan hanya menambahkan batas validasi, tanpa mengubah baris data.

Backup privat: `C:\laragon\backups\spp-management-system\remote_sync_20261005_230207\db_spp_before_checks.sql` (8.788.807 byte).

SHA-256: `1FE4091558C8F579DA9C02342B9FC76DE9F1B2F512E7B25B5BA5624058417A36`.

Fingerprint isi seluruh 35 tabel fisik cocok sebelum, setelah pemulihan CHECK, dan setelah pemeriksaan HTTP. Baseline tetap 1.359 siswa, 1.020 pembayaran/Rp577.790.000, 12 rekening tabungan/Rp1.050.000, dan 210 penempatan. Tidak dilakukan penghapusan atau rekonsiliasi transaksi pada pekerjaan ini.

## Pemeriksaan

- Health HTTP 200 `ok`; preflight komponen keuangan `ready=1`.
- Seluruh 18 CHECK keuangan, CHECK Legacy dan trigger validasi tingkat terpasang; tidak ada baris melanggar.
- 30 invariant integritas dan tujuh kontrak Legacy pada skrip DBeaver menghasilkan OK.
- Audit 61 FK canonical lengkap dan cocok.
- Pemeriksaan sintaks 79 berkas PHP yang diperbarui dan seluruh JS yang diperbarui lulus; lima perbaikan header diperiksa kembali.
- Tes format tanggal, penolakan payload Pangkal lama sebelum otorisasi kasir, serta draft/pesan form pembayaran lulus.
- 148 request HTTP terautentikasi baca saja lulus pada SD, SMP, SMA, dan Semua Unit: Dashboard, Data Siswa, Master SPP, riwayat pembayaran/tabungan, katalog laporan, Surat Orang Tua, sepuluh template laporan/surat, dan pratinjau PDF/Excel. Ini pemeriksaan respons/struktur HTML, bukan klaim pengujian visual seluruh viewport atau unduhan biner.
- PDF panduan tetap 50 halaman tanpa halaman pendek yang mengindikasikan sisa judul; contoh halaman diperiksa secara visual.
- Fetch terakhir menunjukkan `HEAD` dan `origin/main` identik (`0` ahead, `0` behind).

Dump, fingerprint dan artefak pemeriksaan disimpan di luar web/Git. Clone khusus dan sesi pemeriksaan milik pekerjaan ini dibersihkan setelah verifikasi.

## Sentralisasi akun

Role Management sekarang hanya menerima pembuatan akun Super Admin, Bendahara, atau Kasir. Admin unit tidak ditawarkan di formulir maupun diterima oleh endpoint POST. Super Admin dapat mengelola akun dari cakupan Semua Unit; halaman lain tetap mengikuti pembatasan penulisan Semua Unit. Akun Admin unit yang lama tidak ditampilkan di daftar pengelolaan dan tidak dapat diaktifkan ulang dari UI atau endpoint.

Pada `db_spp`, tiga akun Admin unit SD/SMP/SMA ditandai nonaktif tanpa menghapus baris atau relasi audit. Sebelum perubahan dibuat dump privat `C:\laragon\backups\spp-management-system\role_centralization_20261005_232052\db_spp_before_role.sql` (8.790.636 byte; SHA-256 `E3CDBFC85A836543E13BFCBA32B5B9DBA4F40A335C63906A516D0379D055CD06`). Salinan dump dipulihkan dan dipakai untuk uji HTTP: Super Admin dari empat cakupan, pembuatan Kasir SMP, penolakan pembuatan Admin unit, penolakan akses Admin unit, serta penolakan aktivasi ulang. Bootstrap pada salinan menghasilkan nol Admin unit aktif. Uji pembatasan Semua Unit dan CSRF lulus. Health `ok` dan 30 invariant keuangan tetap bersih sesudah pengarsipan.

Manual penggunaan dan panduan alur kerja diperbarui, lalu kedua PDF dibuat ulang. Tidak ada commit atau push.

### Koreksi cakupan daftar Role Management

Pilihan unit pada sidebar kini menentukan daftar akun dan cakupan tindakan. Pada SD/SMP/SMA hanya akun unit itu yang ditampilkan; pilihan Super Admin (Semua Unit) menampilkan seluruh akun yang dapat dikelola. Filter unit lama melalui query URL diabaikan. Pembuatan akun unit lain, reset kata sandi, dan perubahan status lintas unit ditolak oleh server ketika satu unit tertentu sedang dipilih. Pemeriksaan HTTP baca saja pada database utama mencocokkan 6 akun SD, 5 SMP, 5 SMA, dan 17 pada Semua Unit. Pada salinan database, kiriman lintas unit dengan CSRF sah ditolak dan uji cakupan Semua Unit tetap lulus. Panduan PDF dibuat ulang sesuai alur ini.
