# Pembaruan Rombel, Pembayaran, dan Cetak — 18 Oktober 2026

## Rombel dan tahun ajaran

Penyempurnaan terbaru: [Daftar Peserta Naik Kelas](DAFTAR_PESERTA_NAIK_KELAS_20261018.md) tersedia melalui tombol buka/tutup, dengan detail pemantauan dan ekspor Excel/PDF. Checkbox pemantauan terpisah dari checkbox proses. Tahun tujuan mendatang tetap boleh dibaca dan belum dapat dinaikkan lagi.

Master Kelas menampilkan tahun yang sudah memiliki peserta, termasuk tahun tujuan draft. Bagian **Peserta per Tahun Ajaran** menampilkan seluruh penempatan, terpisah dari peserta yang layak dinaikkan. **Cari siswa di semua tahun** menemukan nama/NIS/NIS Diknas serta menyediakan tautan menuju tahun yang sesuai. Tahun mendatang dapat dibaca tetapi belum bisa diproses lagi.

Siswa Demo 001 tidak hilang: penempatan 1A berada pada 2026/2027 dengan status pindah, dan 2A pada 2027/2028 dengan status aktif. Daftar sebelumnya hanya membuka tahun asal. Tidak diperlukan perbaikan data siswa tersebut.

Perubahan tingkat reguler melalui Data Siswa memakai layanan kenaikan yang sama: penempatan berikutnya dibuat tepat satu tahun setelah asal. Perpindahan rombel setingkat mengikuti tahun penempatan aktif. Rombel operasional dan hitungan anggota mengikuti data aktif; snapshot pembayaran/tagihan lama tetap dipertahankan. Siswa tidak dapat dinaikkan dua kali dalam satu siklus.

Untuk salah memasukkan kelas reguler, pilih **PSB** langsung pada Edit Data Siswa dan Simpan. Panel/endpoint Koreksi Pendaftaran PSB dihentikan. Perubahan tidak memeriksa kelayakan berdasarkan pembayaran, pengajuan, atau kenaikan sebelumnya. Penempatan reguler aktif ditutup, asal PSB ditandai, dan nominal PSB dapat diisi pada formulir biasa. Tagihan/transaksi/saldo lama tidak dibatalkan atau dihapus otomatis. Autentikasi, unit, dan validasi nominal tetap berlaku.

## Out dan Custom

- **Out** selalu berada sebelum PSB. Memilihnya menandai siswa keluar/nonaktif dan menutup penempatan aktif. Filter Out otomatis membuka arsip bila status belum ditentukan. Pemulihan melalui Edit dengan rombel tujuan; tombol Pulihkan umum tidak mengaktifkan Out tanpa tujuan.
- **Tambah Rombel Custom** menerima nama bebas maksimal 100 karakter, unik dalam unit, tanpa tingkat. Contoh: Tabungan Guru. Tambah Rombel reguler dan template A–J tetap tersedia.
- Pemilik rekening Custom dicatat melalui Data Siswa, lalu menggunakan Input Setoran/Penarikan dan buku tabungan yang sama. Custom tidak mengikuti kenaikan, hitungan siswa akademik, atau penerbitan otomatis SPP/DU/Komite/Biaya Lain. Data keuangan sebelumnya tetap tersedia.

## Pembayaran dan cetak

Biaya Berulang harus dibayar tepat tarif master. Buku Paket Rp125.000 menolak Rp110.000 maupun Rp130.000. Popup menjelaskan tarif dan input serta mengembalikan fokus ke kolom nominal. Server juga menolak POST langsung dan membatalkan transaksi gabungan secara utuh. Tarif acuan disimpan pada rincian baru; data lama yang nominalnya tidak berubah tetap sah. Perubahan tarif setelah formulir/pengajuan dibuka memerlukan pemuatan/pengajuan ulang. Tagihan Tidak Berulang tetap dapat dicicil.

Ringkasan kewajiban dikelompokkan menurut komponen dengan nominal rata kanan dan total. Histori siswa mempertahankan seluruh transaksi serta filter tahun, dengan header tabel melekat dan penanda metode. Riwayat Daftar Ulang menyediakan tombol cetak tiap transaksi/cicilan pada kartu dan detail. Bukti transaksi gabungan mencetak seluruh komponen aslinya.

Struk menggunakan label ringkas tanpa “terkini”, tambahan TA, atau bulan berulang pada komponen. Periode utama dan tanggal transaksi tetap tersedia. Jam Bayar berada di bawah Sistem Pembayaran. Saldo yang tidak tersedia ditampilkan sebagai tanda pisah, bukan nol. Preview, PDF, PNG, dan struk tahunan memakai template bersama pada Letter separo; rincian panjang dapat berlanjut ke halaman berikutnya.

## Migrasi dan verifikasi

Migrasi CLI `sql/update_rombel_payment_20261017.php` menambah jenis/nama rombel, tingkat nullable, Out per unit, tarif acuan, serta kapasitas snapshot nama 100 karakter. View dan trigger tetap membatasi unit. Migrasi dapat diulang; tidak mengonversi transaksi lama. Alur migrasi workflow juga memanggil migrasi ini untuk instalasi berikutnya.

Backup sebelum penerapan dan bukti pemulihan berada di `C:/laragon/backups/spp-management-system/rombel_payment_20261017`, di luar webroot. Isi 39 tabel berhasil dibandingkan dengan restore pada instance terpisah. Setelah migrasi, kolom lama pada 37 tabel selain master rombel/log migrasi tetap cocok. Pengujian finansial hanya memakai instance terpisah berport 64421 dengan UUID/datadir berbeda; tidak membuat transaksi uji pada database operasional.

Pengujian: `tests/rombel_payment_http_test.php` (309 pemeriksaan), `tests/rombel_payment_authorization_test.php` (42 pemeriksaan), `tests/rombel_payment_browser_test.js` (436 pemeriksaan), `tests/rombel_payment_output_browser_test.js`, `tests/rombel_payment_report_test.php` (9 pemeriksaan), `tests/rombel_payment_output_readback_test.py` (19 pemeriksaan), serta `tests/receipt_print_test.php`. Bukti hasil dan keluaran disimpan pada direktori privat yang sama. Uji cetakan fisik pada printer sekolah tetap diperlukan untuk memastikan kecocokan pengumpan kertas.
