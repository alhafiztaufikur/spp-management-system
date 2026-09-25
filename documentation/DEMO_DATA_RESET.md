# Reset Baseline Demo

Gunakan reset ini hanya untuk database demo lokal atau Railway yang sudah dipastikan tidak berisi data sekolah sebenarnya.

Skrip reset menghapus siswa, pembayaran, tagihan, tabungan, titipan SPP, dan audit terkait. Akun operator, Master Kelas, Master Biaya Lain, serta struktur database tidak dihapus.

## Urutan eksekusi

1. Export/backup database target terlebih dahulu.
2. Pilih database target di DBeaver. Untuk Railway, buat TCP proxy sementara dan gunakan database `railway`.
3. Buka `sql/reset_demo_students_and_finance.sql`.
4. Ubah satu baris berikut sebelum menjalankan seluruh skrip:

   ```sql
   SET @spp_reset_confirmation := 'RESET_DEMO_2026';
   ```

5. Pastikan hasil akhir reset menunjukkan `siswa_setelah`, `pembayaran_setelah`, `mutasi_tabungan_setelah`, dan `tagihan_utama_setelah` semuanya `0`.
6. Jalankan `sql/seed_students_psb.sql` pada database yang sama.
7. Pastikan hasil seeder: 150 siswa aktif, 144 reguler, 6 PSB, 30 siswa dengan sisa Pangkal, 1.728 tagihan SPP, 1.728 tagihan Komite, 144 tagihan Daftar Ulang, dan seluruh pembayaran/mutasi/titipan bernilai `0`.
8. Untuk mengisi data laporan demo, buka `sql/seed_demo_payments.sql`, ubah token menjadi `SEED_PAYMENT_DEMO_2026`, lalu jalankan pada database yang sama. Hasilnya tepat 1.000 pembayaran tanpa mutasi Tabungan.
9. Seeder transaksi hanya boleh dijalankan ketika jumlah pembayaran masih `0`. Jika pernah dipakai atau ada transaksi baru, jalankan reset dan seeder siswa lagi terlebih dahulu.
10. Tutup TCP proxy Railway setelah verifikasi selesai.

`repair_one_time_fees.sql` tidak diperlukan setelah reset baseline ini. Skrip tersebut hanya untuk memperbaiki database lama tanpa menghapus datanya.
