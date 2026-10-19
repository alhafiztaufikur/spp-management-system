# Penyempurnaan cetak dan tampilan pembayaran

Pembaruan ini tidak memerlukan migrasi database dan tidak mengubah transaksi operasional, aturan nominal, maupun hak akses.

## Struk

`includes/receipt_print.php` dan `assets/css/receipt_print.css` menjadi sumber bersama preview, PDF teks, PNG, dan cetak tahunan. Identitas menggunakan baris tabel dengan titik dua sejajar pada kedua kolom. Nama panjang dapat membungkus; NIS Diknas kosong tidak membuat baris kosong.

Sisa Pembayaran hanya berisi **Sisa PSB : nominal** dan **Sisa DU : nominal**. Saldo tidak tersedia tetap berupa tanda pisah. Sisa Komite dihilangkan dari struk; perhitungan dan laporan Komite tetap berlaku. Terbilang, Sistem Pembayaran, Jam Bayar, tanggal Bekasi, dan Bagian Keuangan memiliki jarak tersendiri. Nama penanda tangan dikapitalisasi saat dirender, tanpa mengubah akun.

Kertas tetap Letter separo (215,9 × 139,7 mm). Struk normal satu halaman; rincian panjang diteruskan dengan kepala dokumen berulang. Pengaturan metrik font Dompdf dipertahankan agar ukuran font tidak membesar secara implisit.

## Histori pada Input Pembayaran

Toolbar Tahun Ajaran/Penempatan terpisah dari kartu histori dan hanya menyediakan tombol Filter. Pencarian histori dihapus sesuai penyempurnaan terbaru. Filter metode/komponen diterapkan dengan **Terapkan** atau dibersihkan dengan **Reset**; filter direset ketika siswa berganti. Badge metode memakai teks tanpa ikon, dan label Riwayat Lama tidak memakai tanda garis.

Keenam kolom bisa diurutkan. Tanggal, periode, dan nominal menggunakan nilai asli dari server; ID menjadi pembeda jika nilainya sama. Bawaan adalah tanggal terbaru. Seluruh hasil ditampilkan dalam area bergulir dengan kepala tabel melekat. Footer menyebut jumlah hasil; **tidak ada Export atau pagination**. Pagination halaman Riwayat Pembayaran dan Riwayat Daftar Ulang tetap berlaku seperti sebelumnya.

Menu **⋮** hanya memuat **Detail** dan **Cetak** sesuai hak akses. Detail menggunakan endpoint existing dengan `readonly=1`, yang menonaktifkan kontrol mutasi termasuk pembatalan pengajuan. Cetak membuka transaksi asli. Menu mendukung keyboard, Escape, dan pengembalian fokus. Permintaan lama dibatalkan; respons siswa/unit yang tidak sesuai tidak dirender. Loading, keadaan kosong, error, dan Coba Lagi tetap tersedia.

Respons `pembayaran/history_siswa.php` mempertahankan field tampilan existing dan menambahkan `id`, `unit_id`, `tanggal_raw`, `period_key`, `total_raw`, `components`, `can_detail`, `can_print`, `detail_url`, serta `print_url`. Metadata siswa/unit juga disertakan pada respons. Tidak ada batas jumlah transaksi.

Popup kewajiban memakai gutter sejajar pada kelompok, baris, dan total. Isi panjang dapat digulir dalam viewport. Detail baca saja memuat CSS komponen Riwayat Pembayaran sehingga identitas tampil per baris, rincian nominal rata kanan, dan informasi operator/sistem berbentuk kartu. Kolom Keterangan Biaya Lain setinggi 48 px, setara pemilih Jenis.

## Akun dan bukti Daftar Ulang

Muat Ulang berada di kiri baris tindakan formulir akun, dengan tautan yang mempertahankan filter. Buat Akun Petugas berada di kanan. Akun Anda tetap penanda noninteraktif dengan ukuran setara tombol Hapus Akun.

Riwayat Pembayaran dan Daftar Ulang memakai kelas tombol cetak yang sama: printer, latar netral, border tipis, dan posisi kanan bawah. Setiap cicilan memiliki tautan transaksi asli. Keterangan nomor/tanggal di atas tombol dihapus; identitas transaksi tetap ada dalam detail dan label aksesibilitas tombol. Warna pilihan kartu Daftar Ulang menyambung sampai footer cetak. Detail AJAX menggunakan gaya yang sama. Transaksi gabungan tetap dicetak lengkap.

## Sasaran Biaya Lain

Pilih Siswa/Pilih Rombel memakai checkbox. Siswa menampilkan nama, NIS, dan rombel; rombel menampilkan label serta jumlah siswa sasaran. **Pilih Semua Hasil** menambahkan semua hasil pencarian ke pilihan yang sudah ada. Pencarian tidak menghapus pilihan tersembunyi, dan **Kosongkan Pilihan** menghapus seluruh pilihan. Pergantian jenis sasaran mengosongkan pilihan sebelumnya.

Kontrak POST tetap `target`, `target_ids[]`, `expected_master`, dan `expected_student_ids[]`. Pilihan tersembunyi disertakan sebagai input hidden tanpa duplikasi. Pratinjau/konfirmasi, pemeriksaan versi, cakupan unit, perubahan sasaran, dan penerbitan sekali tetap dijalankan server. Pembatalan konfirmasi mempertahankan pilihan dan membuka kembali formulir. Tinjau Penerbitan memakai radius 10 px seperti Tambah Biaya.

## Verifikasi

- `payment_ui_polish_http_test.php`: metadata kompatibel, detail baca saja, parameter salah, isolasi siswa/unit, dan 508 transaksi tanpa pembatasan. Fixture stres dihapus kembali dan total clone diperiksa.
- `payment_ui_polish_browser_test.js`: desktop/tablet/ponsel, SD/SMP/SMA, tema terang/gelap, keyboard menu, Detail/Cetak, filter/pengurutan 507 baris, Coba Lagi, checkbox, dan pembatalan konfirmasi.
- `other_fee_publish_replay_http_test.php`: formulir penerbitan lama/replay ditolak tanpa perubahan katalog/tagihan/pembayaran.
- `receipt_print_test.php` serta pengujian keluaran Chrome/Edge: PDF teks, PNG utuh, 12 struk, dan rincian panjang.

Pengujian database hanya dijalankan pada instance terpisah yang diverifikasi melalui UUID, datadir, port, dan identitas HTTP. Cadangan sumber dan artefak privat tersedia di `C:/laragon/backups/spp-management-system/ui_polish_20261018`.

Hasil verifikasi: 574 pemeriksaan matriks browser, 40 pemeriksaan terfokus termasuk kontrak POST konfirmasi dan pergantian siswa cepat, 54 pemeriksaan HTTP, 75 pemeriksaan replay penerbitan, serta 23 pemeriksaan baca balik PDF/PNG. Detail DU melalui AJAX dan PDF struk dari Laporan juga diperiksa. Tidak ada error JavaScript pada matriks browser.

Endpoint cetak tahunan asli juga diperiksa dengan 12 transaksi fixture pada clone: PDF menghasilkan 12 halaman lengkap. Fixture dihapus kembali dan jumlah/total pembayaran clone cocok sebelum dan sesudah pengujian.

Verifikasi perapihan lanjutan: 747 pemeriksaan browser lulus pada SD/SMP/SMA, dua tema, dan tiga ukuran layar. Pemeriksaan mencakup layout detail aktual (CSS grid dan identitas per baris), gutter total popup, tinggi Jenis/Keterangan, penghapusan pencarian/ikon/caption, warna footer kartu, filter/sort, retry, serta respons siswa terlambat. Artefak privat: `C:/laragon/backups/spp-management-system/payment_layout_20261018`.
