# Persiapan Deployment Railway

Status 2026-09-22: MySQL Railway sudah online dengan volume persisten dan database lokal `db_spp` telah diimpor ke database `railway`. Service produksi memakai Dockerfile proyek, reference variables ke MySQL, dan domain Railway sudah aktif. Setelah menjalankan perubahan data, lakukan pemeriksaan `/health.php`, login administrator, serta satu transaksi uji yang sah sebelum aplikasi dipakai operasional.

## Hasil impor database lokal

- Impor terlebih dahulu lolos uji pada database disposable, kemudian diterapkan ke `railway`. Database lokal tidak diubah.
- Verifikasi setelah impor: 30 tabel, 61 foreign key, 152 siswa, 218 transaksi pembayaran, dan total `bayar.total_jumlah` Rp84.817.500; jumlah baris setiap tabel cocok dengan lokal.
- Identifier tabel `Daftar_ulang` disesuaikan kapitalisasinya pada salinan dump karena MySQL Linux membedakan huruf besar/kecil pada nama tabel. Database lokal tetap menggunakan nama lamanya.
- Salinan dump sumber dan dump impor tersimpan di direktori temp lokal `spp-railway-import-fe59aa6f035640a5a2b290e12ff77a13` untuk pemulihan sementara; jangan masukkan dump berisi data siswa ke Git.
- TCP proxy MySQL yang dibuat khusus untuk impor sudah dihapus; tidak ada proxy publik tersisa. Koneksi internal `sistemspp-prod` ke MySQL tetap memakai reference variables.
- Schema lokal memiliki tujuh CHECK constraint referensi yang belum ada pada database sumber; impor mempertahankan kondisi sumber tersebut. Periksa sebelum aplikasi dipakai sebagai produksi sungguhan.

## Yang sudah disiapkan di repository

- `Dockerfile` memakai PHP 8.2 + Apache pada port `8080`, ekstensi `mysqli`, `gd`, `mbstring`, `zip`, dan Composer production install.
- `.dockerignore` tidak membawa backup SQL, dokumentasi, maupun tes ke image. Hanya `sql/schema.sql` dan `sql/bootstrap_production.php` yang disertakan untuk bootstrap privat.
- Apache menolak akses web langsung ke `includes`, `vendor`, `docker`, `sql`, dan berkas konfigurasi. Konfigurasi PHP mematikan tampilan error, melindungi cookie sesi, dan memakai zona waktu Jakarta.
- `koneksi.php` membaca `SPP_DB_HOST`, `SPP_DB_PORT`, `SPP_DB_USER`, `SPP_DB_PASS`, dan `SPP_DB_NAME`. Error koneksi tidak ditampilkan ke pengunjung.
- `/health.php` mengembalikan `ok` hanya setelah database, tabel inti, dan administrator awal tersedia.
- `sql/bootstrap_production.php` hanya menerima database kosong, target yang disebut eksplisit, dan password admin kuat. Ia membentuk tabel dan Master Kelas dari schema referensi tanpa memasukkan siswa, transaksi, atau akun demo.

## Langkah tersisa sebelum membuka domain

1. Database sudah berisi data lokal. **Jangan jalankan bootstrap atau impor `sql/schema.sql`** pada database ini. Pastikan backup MySQL Railway diaktifkan dan kebijakan pemulihannya diuji. [Panduan Railway MySQL](https://docs.railway.com/databases/mysql).
2. Konfigurasi koneksi pada `sistemspp-prod` sudah terpasang memakai *reference variables* dari service MySQL:

   ```text
   PORT=8080
   SPP_DB_HOST=${{MySQL.MYSQLHOST}}
   SPP_DB_PORT=${{MySQL.MYSQLPORT}}
   SPP_DB_USER=${{MySQL.MYSQLUSER}}
   SPP_DB_PASS=${{MySQL.MYSQLPASSWORD}}
   SPP_DB_NAME=${{MySQL.MYSQLDATABASE}}
   ```

   Sesuaikan `MySQL` jika nama service database berbeda. Jangan menyalin nilai password database ke repository. [Panduan reference variables](https://docs.railway.com/variables#referencing-another-services-variable).
3. Periksa `/health.php` pada service web, login administrator, serta form/laporan dengan data uji yang sah. Pastikan healthcheck memakai `/health.php`. Ganti kredensial dan data demo sebelum dipakai sekolah.
4. Pastikan perubahan kode terbaru benar-benar ter-deploy sebelum perubahan schema atau data dijalankan. Gunakan satu jalur deploy yang konsisten (repository yang sudah terhubung atau Railway CLI); jangan membuat service web baru untuk setiap deploy.

Jangan mengimpor `sql/schema.sql` langsung ke database produksi: file itu berisi akun dan siswa demo serta perintah `DROP TABLE`. Jangan menjalankan bootstrap pada database yang berisi data; script akan menolak database non-kosong. Pastikan pemakaian data siswa di Railway telah disetujui sesuai kebijakan privasi sekolah.

DDL MySQL tidak dapat di-rollback secara menyeluruh. Jika bootstrap gagal di tengah proses, jangan ulangi pada database parsial; buat database kosong baru atau pulihkan dari backup setelah penyebabnya diperbaiki.
