# Riwayat otorisasi, surat orang tua, dan validasi tabungan

## Penggunaan

- **Otorisasi Transaksi → Antrean**: periksa pengajuan yang masih menunggu. **Riwayat** menampilkan transaksi yang mempunyai pengajuan atau perubahan langsung, termasuk transaksi terhapus. Detail membedakan usulan, keputusan, dan perubahan yang diterapkan. Filter status mencari transaksi dengan pengajuan berstatus tersebut; ringkasan tetap menunjukkan aktivitas terbaru transaksi.
- **Surat Orang Tua → Cetak**: pilih penerima, lalu isi pesan masing-masing siswa di halaman Susun Surat. Draf tersimpan otomatis selama dua jam sejak dibuat dan dapat disimpan melalui tombol Simpan Draf. Buka Pratinjau Surat, periksa PDF, kemudian Cetak atau Unduh PDF. Pesan kosong mempertahankan surat standar. Pesan tidak mengubah surat kepala sekolah.
- **Tabungan Masuk/Keluar**: tunggu saldo selesai diperiksa. Nominal penarikan yang melebihi saldo ditandai merah beserta selisihnya; Simpan menampilkan peringatan aplikasi. Penarikan sebesar saldo diperbolehkan. Server tetap memeriksa saldo aktual dalam transaksi database.

## Penyimpanan dan akses

Tidak ada skema baru. Riwayat membaca jurnal pembayaran serta bukti pengajuan lama; tidak menulis ulang atau menghapus riwayat. Kasir hanya dapat membaca transaksi yang pernah diajukannya. Bendahara hanya membaca. Unit tetap diperiksa di server.

Draf surat berada dalam sesi pengguna, terpisah per token dan identitas `unit|NIS`. Draf memuat salinan data saat disusun, sehingga pratinjau dan unduhan menggunakan isi surat yang sama. Untuk memperoleh tagihan terbaru, buat proses penyusunan baru. Pesan berupa teks biasa, maksimal 2.000 karakter per siswa. Menyimpan draf melalui POST memerlukan CSRF; pembacaan PDF memerlukan token sesi dan unit yang sesuai. Draf tidak disimpan permanen di database.

## Pengujian

Gunakan salinan `db_spp_audit_*`, `SPP_TEST_ALLOW_MUTATION=1`, dan server lokal yang menunjuk salinan tersebut. Jangan menjalankan fixture pada database aktif.

- `tests/transaction_workflows_test.php`: filter riwayat/unit, validasi draf, escaping, masa berlaku, dan surat kepala sekolah.
- `tests/transaction_workflows_browser_test.js`: akses operator, pemilihan penerima, draf per siswa, PDF, tampilan responsif/tema, dan modal tabungan.
- `tests/savings_validation_browser_test.js`: saldo belum siap, respons lama, validasi nominal, penolakan server, rollback, dan pengiriman berulang.
- Regresi: `tests/payment_activity_browser_test.js`, `tests/payment_activity_test.php`, `tests/report_letters_test.php`, serta `tests/readiness_integrity_audit.php`.
