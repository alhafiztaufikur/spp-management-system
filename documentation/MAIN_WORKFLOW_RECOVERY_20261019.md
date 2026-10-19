# Pemulihan akses localhost project utama — 19 Oktober 2026

Halaman **Sistem sedang diperbarui** berasal dari pemeriksaan kesiapan workflow: source terbaru sudah dipakai, sedangkan `db_spp` belum mempunyai kolom, constraint, dan log migrasi yang diperlukan. Pemeriksaan tersebut dipertahankan; pembaruan database dipasang melalui CLI sehingga login kembali tersedia.

## Akun sesuai keputusan pengguna

Semua 10 ID akun, username, nama, password, status aktif, dan timestamp existing dipertahankan. Akun `superadmin` tetap Super Admin; sembilan akun lainnya menjadi Administrator (`admin`) dengan akses operasional lintas unit sesuai model aplikasi terbaru. Tidak membuat empat akun awal baru, menghapus akun, atau mengubah password.

CLI workflow sekarang menerima opsi tambahan berikut:

```powershell
php sql/update_workflows_20261017.php --preserve-existing-accounts --confirm-main=db_spp --backup-file="C:\laragon\backups\backup-terverifikasi.sql"
```

Pemasangan utama tetap memerlukan otorisasi pemilik dan `SPP_ALLOW_MAIN_MIGRATION=1`, backup baru di luar repository, serta konfigurasi koneksi yang sesuai. Opsi `--preserve-existing-accounts` tidak digabung dengan `--credentials`. Jalur `--credentials` existing tetap kompatibel. Opsi baru mempertahankan status akun; bila pernah dipasang, pengulangan tidak mengubah kembali akun atau membuat duplikasi. Satu Super Admin aktif dijaga constraint existing. Kebijakan berbeda pada database yang telah selesai dimigrasikan tidak diterapkan ulang otomatis.

Pemasangan rombel mempertahankan default kolom snapshot dan timestamp master existing. Master OUT ditambahkan per unit; dukungan Kelas Khusus dan indeks nama dipasang tanpa menjalankan migrasi Legacy final.

## Bukti pemasangan

Pemasangan pada `db_spp` selesai **19 Oktober 2026, 21.40 WIB**. Backup sebelum pemasangan berhasil dipulihkan pada instance MySQL terpisah dengan 50 tabel existing identik. Backup setelah pembaruan juga dipulihkan, dengan **51 tabel** identik termasuk log migrasi baru.

Perbandingan mempertahankan seluruh kolom existing pada 50 tabel, dengan pengecualian hanya role/unit akun yang disetujui. Master OUT baru tidak mengubah baris kelas lama. Kolom histori baru tersedia; pada data ini tidak ada transaksi yang memenuhi syarat backfill, sehingga tidak ada konteks tahun/kelas yang ditebak. Jumlah tetap: 2.077 siswa, 1.020 pembayaran, 108 pembayaran DU, dan saldo tabungan total Rp1.050.000. Semua **59 tabel lab copy** tetap identik; konfigurasi lab dan drafnya tidak dipasang ulang.

Pemeriksaan yang lulus:

- Migrasi pada clone beserta pengulangan: 10 akun dan password/hash/status/timestamp tetap sama, hanya satu Super Admin aktif, dan skema siap.
- 240 GET pada clone: sepuluh akun, tiga unit, dashboard, master kelas, siswa, input pembayaran, master biaya, tabungan dan laporan.
- Audit keuangan/relasi seluruh unit: tidak ada selisih; seluruh 61 hubungan foreign key yang diharapkan ditemukan.
- Localhost project utama: login HTTP 200 serta dashboard, master kelas, input pembayaran dan riwayat tabungan. Chromium 109 benar-benar dijalankan pada desktop/ponsel. Satu pengulangan navigasi browser sempat menerima 503 sementara pada riwayat tabungan; pemeriksaan HTTP dan pengulangan browser sesudahnya kembali 200. Pemeriksaan ini tidak membuktikan ketahanan beban serentak atau seluruh perangkat Windows 7.
- Syntax PHP dan `git diff --check` lulus. Tidak membuat transaksi atau siswa contoh pada database utama.

Backup, bukti fingerprint, hasil HTTP/browser dan screenshot disimpan privat di `C:\laragon\backups\spp-management-system\main_workflow_recovery_20261019`. Tidak melakukan commit/push atau pembaruan deployment.

Buka ulang `http://localhost/spp-management-system/login.php` dan gunakan username/password lama. Akun yang sebelumnya Kasir/Bendahara/Super Admin tambahan sekarang tampil sebagai Administrator sesuai persetujuan pengguna.
