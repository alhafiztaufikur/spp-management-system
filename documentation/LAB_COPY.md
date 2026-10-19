# Lab copy untuk mencoba migrasi Legacy

**Lab copy** adalah instalasi uji di `C:\laragon\www\spp-management-system-Copy`, terhubung ke database **db_spp_test2**. Nama folder dan database tetap agar tautan, sesi, antrean, dan konfigurasi privat yang sudah ada tidak terputus.

Buka **http://localhost/spp-management-system-Copy/login.php**. Gunakan akun Super Admin uji yang sudah tersedia; username **superadmin**, dengan password uji sebelumnya. Kredensial tidak disimpan dalam panduan ini.

## Mulai mencoba

1. Pastikan Laragon (Apache dan MySQL) berjalan.
2. Setelah restart Windows, jalankan `MULAI_IMPORTER_COPY.cmd` di folder lab copy. Launcher mempertahankan worker yang sudah hidup dan tidak membuat worker kedua.
3. Pilih SD, SMP, atau SMA. Buka **Pengaturan → Import Legacy** dan pilih mode yang ingin diuji.
4. Pekerjaan yang sudah tersedia dapat dilanjutkan. Sesudah pembaruan source/aturan, periksa ulang dan jalankan simulasi terbaru sebelum konfirmasi final.
5. Backup `.dat`, `.bak`, dan tanpa ekstensi didukung, maksimal 100 MiB. Unggah sumber sesuai unit.

Migrasi Operasional memerlukan unit tujuan yang kosong dari siswa/transaksi. Jangan menghapus data untuk melewati pemeriksaan. Jika sudah pernah menerapkan migrasi, lanjutkan pekerjaan yang sama atau siapkan database uji baru secara terpisah.

## Pembaruan 19 Oktober 2026

- Source terbaru disalin dari project utama beserta perubahan lokal yang belum di-commit. Konfigurasi lab, sesi, antrean, deployment, dan berkas privat dipertahankan.
- Skema CLI terbaru dipasang hanya pada **db_spp_test2**, termasuk Alumni, tarif belum ditetapkan, dan jurnal penyesuaian saldo FIX. Akun aplikasi tetap dibatasi; pemasangan DDL menggunakan koneksi administratif khusus target lab.
- Isi tabel existing tetap dipertahankan. Satu pekerjaan impor berstatus siap ditinjau tetap tersedia. Tidak ada file Legacy diterapkan otomatis dalam penyiapan ini.
- Worker memakai source terbaru. Instance SQL Server dan penyimpanan importer terpisah dari utama.
- Backup sebelum dan sesudah pembaruan diuji pemulihannya pada instance MySQL terisolasi. Lokasi cadangan lab: `C:\laragon\backups\spp-management-system-Copy\daily\db_spp_test2`.
- Database utama **db_spp** tidak dipasangi skema atau dimigrasikan pada pekerjaan ini. Akun aplikasi lab tidak dapat membaca database utama.

Konfigurasi privat tetap di luar source: `C:\laragon\data\spp-testing\db_spp_test2`. Jangan menyalin kredensial lab ke server yayasan atau memasukkannya ke Git. Jadwal backup Windows instalasi utama tidak diubah. Lab copy memakai database yang sama antarpercobaan; memperbarui source tidak mengosongkan data uji.

## Persetujuan massal

Source lab memakai aturan operasional versi 5. Pada pekerjaan lama, muat ulang halaman, pilih **Setujui Semua Data**, lalu **Setujui & Simulasikan**. Untuk selisih tabungan tambahan, pilih rekening utama dan isi **Jumlah Sebenarnya**; nominal menggantikan saldo akhir utama satu kali. [Panduan](LEGACY_BULK_REVIEW_20261019.md). Memperbarui lab tidak otomatis menyetujui atau menerapkan pekerjaan impor pengguna.
