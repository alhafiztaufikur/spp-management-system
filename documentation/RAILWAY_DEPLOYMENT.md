# Persiapan Deployment Railway

Status 2026-09-19: project privat `SistemSPP` dan service kosong `sistemspp-web` sudah dibuat di Railway. Service belum terhubung ke GitHub, belum memiliki database, deployment, atau domain publik. Ini disengaja agar schema demo dan akun seed tidak terbuka.

## Yang sudah disiapkan di repository

- `Dockerfile` memakai PHP 8.2 + Apache pada port `8080`, ekstensi `mysqli`, `gd`, `mbstring`, `zip`, dan Composer production install.
- `.dockerignore` tidak membawa backup SQL, dokumentasi, maupun tes ke image. Hanya `sql/schema.sql` dan `sql/bootstrap_production.php` yang disertakan untuk bootstrap privat.
- Apache menolak akses web langsung ke `includes`, `vendor`, `docker`, `sql`, dan berkas konfigurasi. Konfigurasi PHP mematikan tampilan error, melindungi cookie sesi, dan memakai zona waktu Jakarta.
- `koneksi.php` membaca `SPP_DB_HOST`, `SPP_DB_PORT`, `SPP_DB_USER`, `SPP_DB_PASS`, dan `SPP_DB_NAME`. Error koneksi tidak ditampilkan ke pengunjung.
- `/health.php` mengembalikan `ok` hanya setelah database, tabel inti, dan administrator awal tersedia.
- `sql/bootstrap_production.php` hanya menerima database kosong, target yang disebut eksplisit, dan password admin kuat. Ia membentuk tabel dan Master Kelas dari schema referensi tanpa memasukkan siswa, transaksi, atau akun demo.

## Langkah aman sebelum membuka domain

1. Tambahkan **MySQL** dari template database resmi Railway pada project yang sama. Jangan memakai generic image MySQL tanpa volume persisten. Aktifkan backup database. [Panduan Railway MySQL](https://docs.railway.com/databases/mysql).
2. Hubungkan service `sistemspp-web` ke repo GitHub `alhafiztaufikur/spp-management-system`, branch `main`, menggunakan Dockerfile di root. Service boleh di-deploy secara privat terlebih dahulu, tanpa domain publik dan tanpa healthcheck.
3. Set variable service berikut memakai *reference variables* dari service MySQL (nama service pada contoh adalah `MySQL`):

   ```text
   PORT=8080
   SPP_DB_HOST=${{MySQL.MYSQLHOST}}
   SPP_DB_PORT=${{MySQL.MYSQLPORT}}
   SPP_DB_USER=${{MySQL.MYSQLUSER}}
   SPP_DB_PASS=${{MySQL.MYSQLPASSWORD}}
   SPP_DB_NAME=${{MySQL.MYSQLDATABASE}}
   ```

   Sesuaikan `MySQL` jika nama service database berbeda. Jangan menyalin nilai password database ke repository. [Panduan reference variables](https://docs.railway.com/variables#referencing-another-services-variable).
4. Pada container web privat yang sudah berjalan, jalankan `php sql/bootstrap_production.php --execute` **sekali** dengan `SPP_BOOTSTRAP_TARGET` sama persis dengan `SPP_DB_NAME`, serta `SPP_BOOTSTRAP_ADMIN_USER` dan `SPP_BOOTSTRAP_ADMIN_PASSWORD` yang kuat sebagai environment sementara. Jangan menaruh password admin dalam commit, log, atau argumen perintah. [Panduan Railway SSH](https://docs.railway.com/cli/ssh).
5. Periksa `/health.php`, login administrator, serta form/laporan dengan data uji yang sah. Setelah lolos, atur healthcheck `/health.php`, lalu baru aktifkan domain publik. Ganti atau hapus data uji sebelum dipakai sekolah.

Jangan mengimpor `sql/schema.sql` langsung ke database produksi: file itu berisi akun dan siswa demo serta perintah `DROP TABLE`. Jangan menjalankan bootstrap pada database yang berisi data; script akan menolak database non-kosong. Bila data lokal akan dipindahkan ke Railway, proses migrasi data dan persetujuan privasi harus diputuskan terpisah dari setup ini.

DDL MySQL tidak dapat di-rollback secara menyeluruh. Jika bootstrap gagal di tengah proses, jangan ulangi pada database parsial; buat database kosong baru atau pulihkan dari backup setelah penyebabnya diperbaiki.
