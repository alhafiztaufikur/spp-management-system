# Jenis Biaya Lain dan Riwayat Operator Pembayaran

## Penggunaan

- Form pembayaran dan edit memakai kartu Biaya Lain dua baris. Pilih Jenis, periksa Total/Sudah Dibayar/Sisa, isi Bayar dan Keterangan bila diperlukan. Nama panjang membungkus; petunjuk penerbitan tagihan berada di bawah pemilih.
- Riwayat Pembayaran menampilkan pembuat dan aktivitas terakhir. **Riwayat Aktivitas** membuka urutan operator, waktu WIB, pengajuan/keputusan, catatan dan perubahan transaksi.
- **Transaksi Dihapus** adalah arsip kondisi sebelum penghapusan. Tanggal filter di tab ini adalah tanggal penghapusan. Arsip tidak masuk rekap keuangan aktif dan tidak menyediakan pemulihan transaksi.
- Identitas yang tidak didukung bukti data lama ditampilkan sebagai **Tidak tercatat**. Catatan rekonstruksi ditandai pada dialog. Pengajuan dan pemberi keputusan dicatat sebagai aktivitas terpisah.
- Akses mengikuti hak Riwayat Pembayaran yang sudah ada. SD/SMP/SMA hanya membaca unit aktif; Super Admin pada Semua Unit dapat membaca gabungan. Aktivitas tetap tersedia meskipun data sumber kemudian dihapus.

## Migrasi dan pemasangan

`php sql/add_payment_activity.php` hanya memeriksa kesiapan. Migrasi menambahkan tabel/view jurnal dan merekonstruksi bukti yang tersedia; tidak mengubah siswa, pembayaran, tagihan, saldo, atau akun.

Uji pada salinan `db_spp_audit_*` atau `db_spp_test_*` dengan `SPP_TEST_ALLOW_MUTATION=1`, lalu jalankan `php sql/add_payment_activity.php --apply` dua kali. Jurnal tidak boleh berubah pada eksekusi kedua. `tests/payment_activity_migration_test.php` memeriksa seluruh data tabel lama tetap identik.

Penerapan database utama memakai pengaman `sql/readiness_migration_guard.php`: cadangan baru di luar repositori, `SPP_ALLOW_MAIN_MIGRATION=1`, `--apply`, `--confirm-main=db_spp`, dan `--backup-file=<lokasi cadangan>`. Tutup akses HTTP sementara memakai mekanisme `tmp/financial_migration.lock` yang sudah ada. Hapus penanda setelah pemeriksaan integritas dan kesiapan berhasil. Jangan menerapkan migrasi lewat permintaan web.

Instalasi baru melalui `bootstrap_production.php` dilanjutkan dengan `migrate_units.php`; tahap multiunit juga memasang jurnal. Skema pembayaran yang sudah ada tidak berubah.

## Antarmuka dan pengujian

- `GET pembayaran/aktivitas.php?id=<ID transaksi>` mengembalikan JSON ringkasan dan aktivitas yang boleh dibaca sesi/unit saat ini; 401 untuk tanpa sesi, 403 untuk peran tidak berhak, 404 untuk transaksi tidak tersedia pada unit tersebut.
- Edit mengirim `activity_request_key` untuk mencegah penerapan ulang form yang sama. Kunci tidak menggantikan pemeriksaan CSRF, otorisasi atau penguncian transaksi.
- Jurnal bersifat tambahan saja: tidak mempunyai relasi cascade ke pembayaran, siswa atau akun; trigger menolak perubahan, penghapusan dan penyisipan lintas unit. Catatan dan operasi keuangan terkait berada dalam transaksi database yang sama.
- Uji mutasi hanya pada clone: `tests/payment_activity_test.php`, `tests/payment_activity_fixture.php`, `tests/payment_activity_browser_test.js`, dan pengujian pembayaran yang sudah tersedia. Fixture tidak dapat dijalankan lewat HTTP atau pada database utama.
- Pengujian browser memerlukan Chrome, Playwright Core di luar repositori, serta variabel `SPP_TEST_BASE_URL`, `SPP_PLAYWRIGHT_CORE`, `SPP_TEST_ADMIN_PASSWORD_FILE`, `SPP_UI_IDS_FILE` dan opsional `SPP_UI_OUTPUT`. Password uji dan tangkapan layar tidak perlu dimasukkan ke Git.
