# Rekonsiliasi Uang Pangkal dan PSB

Gunakan [repair_one_time_fees.sql](../sql/repair_one_time_fees.sql) untuk database yang sudah memakai struktur Pangkal/PSB saat ini.

Script ini aman untuk data operasional: tidak menghapus transaksi maupun siswa. Ia hanya menyamakan `tot_pangkal` dengan nominal Pangkal dan potongannya, lalu melengkapi Pangkal enam data demo PSB standar yang sebelumnya keliru terset Rp0. Baris yang histori pembayarannya lebih besar daripada nominal master tidak diubah; hasil pemeriksaan akhir akan menandainya untuk koreksi manual.

Langkah di DBeaver melalui Railway TCP proxy:

1. Buat backup atau export database Railway terlebih dahulu.
2. Buat TCP proxy MySQL sementara di Railway, lalu hubungkan DBeaver memakai host, port, user, password, dan database dari proxy tersebut.
3. Pilih database `railway` pada koneksi DBeaver.
4. Buka dan jalankan seluruh [repair_one_time_fees.sql](../sql/repair_one_time_fees.sql).
5. Pastikan hasil dua pemeriksaan pertama bernilai `0`. Baris ketiga hanya menunjukkan jumlah siswa aktif yang masih mempunyai sisa Pangkal.
6. Tutup kembali TCP proxy MySQL setelah selesai.

Untuk lingkungan demo/development, gunakan satu-satunya seeder standar [seed_students_psb.sql](../sql/seed_students_psb.sql). Seeder tersebut kini membuat calon siswa PSB dengan Pangkal Rp1.000.000 dan PSB Rp3.600.000 sehingga keduanya dapat dicicil. Seeder demo lama yang memakai struktur tagihan terdahulu telah dihapus. Jangan menjalankan seeder demo pada data sekolah yang sebenarnya.
