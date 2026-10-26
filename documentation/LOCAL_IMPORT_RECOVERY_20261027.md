# Pemulihan struktur impor lokal, 27 Oktober 2026

`main` lokal cocok dengan `origin/main` pada `9c07b81` setelah fetch. Perubahan
teman `74d34c9` (tanggal pembayaran/nama siswa), `cb656ea` (Input Pembayaran dan
Master SPP), serta `d800d15` (Laporan Global) sudah menjadi ancestor `HEAD`.
Cabang safety lama Agustus dan audit lama tetap dipertahankan sebagai cabang
terpisah; keduanya bukan pembaruan aplikasi setelah main Oktober.

## Penyebab halaman pemeliharaan

Database `db_spp` port 3306 memiliki kolom workflow, view, trigger dan penanda
migrasi, tetapi tahap struktur impor phpMyAdmin belum lengkap. Sebelum perbaikan
hanya ada 18 primary key, 16 unique constraint, tanpa foreign key, CHECK maupun
AUTO_INCREMENT. Gerbang `workflow_schema_ready()` menolak struktur Legacy ini
dan mengembalikan HTTP 503 pada login.

Pratinjau menemukan 206 indeks, 54 kolom AUTO_INCREMENT, serta 165 constraint
yang perlu dipulihkan. Tidak ditemukan pelanggaran atau definisi bertentangan.
Struktur kolom dicocokkan dengan ekspor 26 Oktober yang dilampirkan pemilik.
Ekspor tersebut dipakai sebagai bukti struktur dan counter identitas; baris
datanya tidak diimpor ulang ke database lokal.

## Pemulihan resmi

`scripts/repair_imported_database.php` kini memeriksa indeks, primary/unique key
dan AUTO_INCREMENT sebelum memulihkan FK/CHECK lewat kontrak yang sudah ada.
Kontrak tambahan `sql/import_repair_structure.json` hanya memuat struktur,
tanpa data sekolah atau counter dari snapshot. Duplikat, kolom tidak cocok,
serta nama indeks dengan definisi berbeda menyebabkan preflight gagal.
Counter existing tidak diubah; counter yang hilang dapat memakai bukti JSON
privat dari ekspor sumber melalui `--identity-counters=PRIVATE_JSON`. Nilai
minimum tetap MAX(id)+1. Semua indeks induk dipasang sebelum FK.

Tetapkan `SPP_DB_NAME=db_spp` secara eksplisit, lalu gunakan
`--check --expect-db=db_spp` terlebih dahulu. `--apply` tetap membutuhkan
backup privat dan mode pemeliharaan, atau server uji terpisah sesuai pengaman
existing. Ini pemulihan struktur, bukan impor ulang atau rekonsiliasi kewajiban.
Migrasi data baru tetap mengikuti `LEGACY_MIGRATION_RUNBOOK.md` dan runner resmi.

Backup sebelum perbaikan:
`C:/laragon/backups/spp-management-system/login_recovery_20261027_001051/before_repair.sql`
(626.563.638 byte), SHA-256
`BD7E2515386FA739594DB110A77ECCEE9E0149121C318D96B3D216A379999197`.
Restore diuji di MySQL terpisah port 58367, UUID dan datadir berbeda, event
scheduler OFF. Jumlah, fingerprint SHA-256 baris dan CHECKSUM seluruh 63 tabel
sama dengan sumber sebelum perbaikan. Laporan privat dan log ada di direktori
backup yang sama.

Regresi `tests/import_repair_structure_test.php` memakai database disposable:
menolak duplikat dan definisi yang bertentangan, menerima unique nullable,
memasang key sebelum FK, mempertahankan baris/counter yang terbukti, dan
memastikan penerapan kedua tidak mengubah apa pun.

## Hasil penerapan

Perbaikan sudah diterapkan pada target lokal yang UUID-nya cocok dengan backup.
Struktur akhir memiliki 63 primary key, 73 unique constraint, 119 foreign key,
46 CHECK, dan 54 kolom AUTO_INCREMENT. Semua gerbang workflow/Legacy/komponen
keuangan siap; audit FK tidak menemukan relasi hilang, berbeda atau unresolved.
Pengaturan kolasi dan zona waktu koneksi CLI disamakan dengan aplikasi untuk
menghindari error kolasi saat memverifikasi batch Legacy.

Penerapan kedua pada salinan dan utama sama-sama menghasilkan
`missing=0`, `missing_indexes=0`, `missing_identity=0`, tanpa pelanggaran,
mismatch, atau perbaikan manifest. Jumlah baris, SHA-256 seluruh baris dan
CHECKSUM seluruh 63 tabel utama identik dengan sebelum perbaikan. Akun,
2.293 siswa, 109.079 transaksi Legacy, arsip mentah, tagihan, koreksi dan
tabungan dipertahankan. Perubahan struktur ini tidak menyatakan semua bukti
migrasi bisnis lengkap atau mengganti hasil verifikasi pipeline Legacy.

Audit integritas akhir pada salinan dan utama sama-sama lulus 33 pemeriksaan
tanpa kegagalan. Marker pemeliharaan milik pekerjaan ini sudah dihapus setelah
verifikasi; HTTP lokal `login.php` kembali 200 dengan formulir username/password,
dan `health.php` kembali 200 `ok`. Server HTTP/MySQL sementara milik pengujian
sudah dihentikan. Backup, bukti restore, hasil fingerprint, dan log tetap disimpan
di direktori privat di atas. Tidak ada perubahan `.gitignore` atau kredensial akun.
