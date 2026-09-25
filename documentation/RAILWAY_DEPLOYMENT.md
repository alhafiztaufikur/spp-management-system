# Deployment SistemSPP di Railway

Service web menggunakan `Dockerfile` proyek (PHP/Apache pada port `8080`) dan service MySQL Railway. Panduan ini berisi konfigurasi yang masih diperlukan; angka impor atau jumlah transaksi lama tidak dipakai sebagai patokan karena database demo telah mengalami reset/seed.

## Konfigurasi web

Hubungkan service web ke repository dan branch yang akan di-deploy. Di Variables service web, gunakan reference variables dari service MySQL:

```text
PORT=8080
SPP_DB_HOST=${{MySQL.MYSQLHOST}}
SPP_DB_PORT=${{MySQL.MYSQLPORT}}
SPP_DB_USER=${{MySQL.MYSQLUSER}}
SPP_DB_PASS=${{MySQL.MYSQLPASSWORD}}
SPP_DB_NAME=${{MySQL.MYSQLDATABASE}}
```

Sesuaikan `MySQL` bila nama service berbeda. Jangan menyimpan nilai password di repository. Setelah deploy, periksa `/health.php`, login, input pembayaran, dan laporan dengan data uji yang sah. Verifikasi commit yang aktif pada deployment sebelum menjalankan perubahan schema/database.

## Database

- Database yang **sudah berisi data** tidak boleh menerima impor `sql/schema.sql` atau `sql/bootstrap_production.php`. Schema referensi mengandung operasi destruktif; bootstrap hanya untuk database kosong.
- Sebelum migrasi atau reset, export/backup database target dan pastikan cara pemulihannya. Jika memakai TCP proxy untuk DBeaver, tutup proxy setelah pekerjaan selesai.
- Reset/seed hanya untuk database demo yang boleh dihapus; gunakan [DEMO_DATA_RESET.md](./DEMO_DATA_RESET.md). Data demo tidak boleh dipakai sebagai data operasional sekolah.
- Verifikasi schema dengan `sql/verify_schema.sql` dan bandingkan jumlah/total kas terhadap kondisi yang memang diharapkan **saat ini**, bukan terhadap angka impor historis.

Jangan memasukkan dump berisi data siswa ke Git. Pastikan pengelolaan data dan akses Railway sesuai kebijakan privasi sekolah.
