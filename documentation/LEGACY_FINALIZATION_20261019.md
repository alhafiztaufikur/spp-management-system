# Finalisasi migrasi dan perbaikan memori

## Urutan penggunaan

1. Tunggu status **Simulasi selesai**. Ringkasan ini belum memasukkan data ke tabel operasional.
2. Pada kartu **Selesaikan Migrasi**, pilih **Finalisasi Migrasi**. Tombol tersedia pada semua bagian peninjauan, dekat ringkasan hasil.
3. Periksa unit, sumber, jumlah siswa/pembayaran, saldo, dan data yang masih tertahan. Pilih **Mulai Migrasi Sekarang** untuk mengantrekan penerapan. Kembali atau Escape membatalkan konfirmasi tanpa mengubah draf.
4. Tunggu **Finalisasi Migrasi Sedang Berjalan**. Worker memeriksa ulang sumber dan tujuan, membuat backup teruji, menerapkan data secara atomik, lalu membuat backup dan verifikasi akhir.
5. Setelah **Migrasi Selesai — Data Sudah Masuk**, buka Data Siswa, Riwayat Pembayaran, atau Rekening & Transaksi. Jika verifikasi belum selesai, gunakan **Verifikasi Ulang** pada pekerjaan yang sama.

Pada lab copy, tujuan tetap `db_spp_test2`; database utama tidak ikut dimigrasikan. Jika tombol meminta **Perbarui Pemeriksaan untuk Finalisasi**, jalankan sekali agar hasil lama mendapatkan metadata konfirmasi terbaru. Draf dan saldo FIX tetap tersimpan.

### Menunggu akses aplikasi

Status **Menunggu Akses Aplikasi** berarti masih ada halaman yang memegang akses baca. Worker menunggu otomatis dan melanjutkan setelah halaman selesai; tidak perlu unggah ulang atau klik finalisasi berulang. Jika akses masih sibuk setelah satu interval tunggu, pekerjaan kembali ke antrean otomatis. Konfirmasi dan pemeriksaan sumber tetap berlaku; jika konfirmasi telah kedaluwarsa, jalankan pemeriksaan dan konfirmasi baru.

Perbaikan ini menggantikan kegagalan langsung **Tunggu permintaan aplikasi selesai.** yang dapat terjadi ketika worker mengambil pekerjaan sebelum respons tombol finalisasi selesai dikirim.

## Penyebab dan perubahan teknis

Konfirmasi sebelumnya memanggil simulasi lengkap dari proses HTTP. Penyusunan JSON fingerprint seluruh rencana menambah salinan besar dalam memori dan dapat melampaui batas PHP 512 MiB pada SD171026.

- Konfirmasi operasional sekarang membaca ringkasan simulasi tersimpan dan memeriksa versi, aturan, digest, hash backup/ekstraksi, master, serta fingerprint tujuan. Tidak membentuk ulang seluruh pembayaran/jurnal di halaman web.
- Pemeriksaan lengkap tetap berjalan di worker sebelum penerapan dan diulang dengan penguncian dalam transaksi final. Sumber/master/tujuan yang berubah meminta simulasi baru.
- Fingerprint JSON dihitung bertahap dengan hasil byte-identik terhadap JSON sebelumnya; tidak mengalokasikan satu string JSON seluruh rencana. Fingerprint tabel membaca satu baris PHP pada satu waktu.
- Tidak ditambahkan batas jumlah siswa/transaksi. Batas memori global PHP tidak dihapus; perbaikan menghilangkan alokasi besar yang tidak perlu. Tidak diperlukan skema MySQL baru.
- API dan formulir server tetap kompatibel; antrean penerapan, pencegahan klik ganda, backup, dan verifikasi akhir dipertahankan.

## Pengujian

Pengujian mencakup digest JSON identik, dokumen logis lebih dari 256 MiB dengan batas PHP 64 MiB, konfirmasi ringan, perubahan sumber/master/digest, tombol utama, pembatalan, JavaScript aktif/nonaktif, Chrome dan Chromium 109 pada layar 360/1366 px. Penerapan lengkap diuji pada MySQL terisolasi dengan backup/pemulihan dan retry. Windows 7 fisik belum diuji.

Hasil aktual SD171026 pada lab copy: draf versi 8 tetap utuh, 46.852 pembayaran, 0 penghambat, serta konfirmasi final berhasil dibuka melalui HTTP tanpa fatal error. Pengaturan, 40 usulan master dan saldo FIX tidak berubah. Konfirmasi diuji lalu dibatalkan; penerapan final database pengguna belum dijalankan.

Pembaruan lab copy hanya memperbarui source dan simulasi. Penerapan final pada database pengguna dilakukan melalui tombol finalisasi oleh pengguna.
