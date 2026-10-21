# Perapihan desktop Koreksi Tabungan dan laporan — 22 Oktober 2026

Pekerjaan ini melanjutkan perubahan menu sebelumnya dan mengikuti permintaan terbaru untuk desktop. Perhitungan, pembaca transaksi, hak akses, formulir penyimpanan, pagination, dan konfigurasi `koneksi.php` dipertahankan. Default database tetap `db_spp_test2`. Tidak ada migrasi skema atau penulisan data pengguna.

## Perubahan tampilan

| Area | Hasil |
|---|---|
| Koreksi Tabungan Legacy | Pemilik dan Tanggal/waktu WIB sejajar, dengan tinggi kontrol yang sama. Tanggal tampil `DD/MM/YYYY`, kalender memakai nama bulan Indonesia dan pilihan tahun, serta jam terpisah dalam format 24 jam. |
| Koreksi Tabungan biasa | Pemilih tanggal yang sama tersedia di dialog koreksi. Kalender tampil di atas dialog, Escape menutup kalender tanpa menutup formulir, dan tetap bekerja setelah detail diganti melalui AJAX. |
| Ketepatan tanggal | Isian tetap dikirim melalui field ISO existing. Tanggal tanpa bukti jam tetap memiliki presisi tanggal; jam kosong tidak dianggap sebagai bukti tengah malam. Tanggal/jam tidak valid ditolak, dan validasi server existing tetap berlaku. |
| Daftar Transaksi | Header dan isi memakai ukuran kolom yang sama. Nama dapat membungkus, nominal rata kanan, tanggal dan waktu tersusun dua baris, identitas serta tombol cetak tetap terlihat saat tabel digulir. Kelima jenis laporan memakai susunan yang konsisten. NIS Diknas/unit pada tabel tunggakan menjadi keterangan di bawah No. Induk; datanya tetap tersedia. |
| PDF Laporan Transaksi | Judul, header kolom, isi, dan subtotal rata tengah secara horizontal; isi sel rata tengah secara vertikal. Lebar kolom disesuaikan untuk nama, kelas, nominal dan tanggal/jam. Model data, renderer Dompdf, header berulang, dan pemecahan dokumen besar tetap digunakan. |
| Empat rekap Global | Setoran Kas Harian, Kas Tabungan Harian, Transaksi Tabungan dan Penerimaan Harian mengikuti gambar referensi: judul/pemilih unit di kiri dan filter tiga kolom di kanan. Tinggi kontrol seragam, pencarian serta tombol tersusun dalam panel filter. |
| Semua periode | Checkbox existing pada Rekap Transaksi Tabungan tampil sebagai sakelar dengan penanda aktif dan fokus keyboard. Nama, nilai, dan perilaku filter tetap. |

Aturan susunan desktop untuk tabel dan empat rekap dibatasi pada lebar minimal 1.100 px. Template Global lainnya tidak diberi susunan baru. Koreksi pembayaran Legacy tidak memakai pemilih tanggal Tabungan ini. Tautan cetak, ekspor Excel, batas koreksi tanggal, dan pemeriksaan saldo tidak diubah.

## Pengujian

Seluruh pengujian mutasi memakai database salinan `db_spp_audit_recap_20261022` melalui instance PHP terpisah. Deposit uji dihapus melalui layanan existing dan saldo awal diperiksa kembali. Jurnal Legacy yang diubah saat uji HTTP dikembalikan ke nominal semula. Jejak audit uji hanya berada pada salinan tersebut.

| Pemeriksaan | Hasil |
|---|---|
| Regresi model existing | 164 pemeriksaan lulus. |
| Regresi HTTP existing | 107 pemeriksaan lulus: koreksi Administrator, nominal dalam sen, replay, akses pembayaran, CSRF, unit, dan saldo dipulihkan. |
| Browser desktop | Chrome pada 1.366, 1.654 dan 1.920 px, tema terang/gelap: empat susunan filter, kontrol seragam, lima bagian tabel, header/isi sejajar, dan identitas sticky tanpa tumpang tindih. Tidak ada error JavaScript. |
| Tanggal koreksi Legacy | Pemilihan bulan/tahun/hari, format Indonesia, jam 24 jam, tanggal tidak sah, jam tidak sah, presisi tanpa jam, dan payload pratinjau ISO diperiksa. |
| Dialog koreksi biasa | Kalender pada lapisan dialog, Escape, pratinjau tanpa perubahan timestamp, inisialisasi setelah AJAX, serta pemulihan saldo lulus. |
| Pembacaan ulang PDF | SD: 301 baris/84 halaman; Semua Unit: 564 baris/117 halaman; penyertaan tanpa tanggal: 622 baris/117 halaman. Semua field setiap baris, lima bagian, urutan dan subtotal cocok dengan model pembanding independen dari audit sebelumnya. |
| Posisi teks PDF | Koordinat PDF sampel memverifikasi pusat header/identitas/nama/kelas/nominal dan rata tengah vertikal pada sel satu/dua baris, dengan toleransi kurang dari 1 pt. Sampel mencakup nominal pecahan dan tanggal tanpa bukti jam. |
| Pemeriksaan source | Lint PHP/JavaScript dan `git diff --check` lulus. Pembaca serta pembentuk model Excel di `general_multiple.php` identik dengan baseline pekerjaan ini; hanya renderer HTML yang berubah. Hash `koneksi.php` identik dengan awal pekerjaan. |

Waktu pembuatan tiga PDF pada pengujian lokal sekitar 9,75 / 15,40 / 12,56 detik. Ini satu pengambilan per dokumen, bukan pengukuran p95 atau klaim peningkatan performa. Pekerjaan terbaru ini berfokus pada tampilan; pengukuran navigasi sebelumnya tersedia pada [audit menu dan rekap](AUDIT_REKAP_DAN_MENU_20261022.md).

## Bukti lokal

Bukti memuat data uji dan disimpan di luar repository:

`C:\laragon\backups\spp-management-system\desktop_refinements_20261022`

- `browser-results.json`, `table-alignment-results.json`, `native-browser-results.json`, dan `pdf-results.json` mencatat hasil pengujian browser/PDF. Pengujian tabel memeriksa arah teks yang diminta secara eksplisit serta kesamaan posisi header/isi.
- `artifacts/correction-calendar-desktop.png` dan `native-correction-desktop.png` memperlihatkan kalender serta dialog koreksi.
- `artifacts/global-{setoran,kas-tabungan,tabungan-siswa,penerimaan}-{light,dark}.png` memperlihatkan empat rekap sesuai referensi.
- `artifacts/transactions-{light,dark}.png` memperlihatkan kelima bagian Daftar Transaksi.
- `artifacts/centered-pdf-detail.png`, `pdf-centering-sample.png`, dan `centered-{sd,all,undated}.pdf` berisi hasil PDF yang dibaca kembali.
- `artifacts/before-*.png` dan `baseline/` menyimpan keadaan sebelum perubahan terbaru.

Pengujian baru berada pada `tests/desktop_transaction_browser_test.js`, `tests/desktop_native_correction_browser_test.js`, `tests/desktop_pdf_fixture.php`, dan `tests/desktop_pdf_readback.py`. Fixture/kredensial/sesi, PDF berisi identitas siswa, dan screenshot tidak dimasukkan ke Git.
