# Cetak buku EPSON PLQ-20 dan penyempurnaan laporan

## Buku tabungan yang sudah bergaris

Profil **EPSON PLQ-20 ESC/P2** memakai buku terbuka A5 potret, 148 × 210 mm, lipatan horizontal. Aplikasi mencetak melalui PDF dan driver Windows. Pilih printer EPSON, Passbook **On**, Binding **Horizontal**, ukuran A5, serta skala **100% / Actual size**. Penyesuaian skala otomatis akan menggeser posisi baris.

Preview memperlihatkan kotak **No | Tanggal | Tabungan: Masuk/Keluar | Jumlah | Paraf**. Garis, kepala tabel, dan garis lipatan hanya panduan; PDF produksi hanya berisi nomor, tanggal, nominal masuk/keluar, dan saldo berjalan. Paraf dan arah mutasi yang tidak dipakai kosong. Saldo nol tetap ditulis `0`. Identitas siswa dan saldo akhir tersedia di layar, bukan di area cetak.

1. Pilih siswa pada Cetak Tabungan dan periksa nama/NIS/kelas.
2. Tentukan nomor transaksi pertama/terakhir, halaman atas/bawah, dan baris kosong pertama pada buku.
3. Buka Pengaturan Kalibrasi. Cetak **Uji Kalibrasi** pada kertas percobaan sebelum memakai buku.
4. Sesuaikan posisi kiri/atas kedua bagian, tinggi/jumlah baris, posisi teks, serta lebar setiap kolom. Terapkan Preview untuk memeriksa posisi terbaru.
5. Simpan Pengaturan untuk browser dan unit ini. Reset mengembalikan preset percobaan.
6. Buka PDF untuk Dicetak. Untuk beberapa lembar, masukkan halaman buku berikutnya sesuai urutan keluaran printer; cetak ulang tidak mengubah jurnal atau menandai transaksi otomatis.

Preset bukan ukuran pasti setiap buku: kiri 8 mm; awal tabel atas 6 mm; kepala tabel 10 mm; awal baris bawah 110 mm; 14 baris per bagian; tinggi baris 6 mm; posisi teks 1,4 mm dari atas baris. Lebar kolom 7/17/34/34/26/14 mm, total 132 mm.

Validasi server membatasi kiri/kanan minimal 3 mm, akhir bagian atas paling jauh 100 mm, awal bagian bawah minimal 109,7 mm, serta akhir halaman paling jauh 201 mm. Teks juga harus muat pada tinggi/lebar kotak; nominal tidak dipotong. Ukuran/jumlah baris, rentang transaksi, dan posisi awal yang salah menghasilkan HTTP 400.

Kecocokan fisik **belum dinyatakan 100% sebelum cetak uji pada buku dan PLQ-20 pengguna**. Posisi awal printer dan driver turut memengaruhi hasil. Panduan resmi: [pengaturan Passbook/Binding](https://support2.epson.net/manuals/english/sidm/plq_20/guide/html/cntrl_3.htm), [area cetak](https://support2.epson.net/manuals/english/sidm/plq_20/guide/html/apspe_2.htm), [penyesuaian posisi awal](https://support2.epson.net/manuals/english/sidm/plq_20/guide/html/paper_4.htm).

## Kontrak endpoint dan data

GET `tabungan/cetak_buku.php` mempertahankan `nis`, `student_id`, dan `output=preview|pdf`; tambahan `output=guide` menghasilkan PDF panduan. Parameter baru:

- `entry_from`, `entry_to`: nomor pada seluruh jurnal, inklusif. Buku kosong memakai `0`/`0`.
- `start_side=top|bottom`, `start_row`: posisi awal; default atas/baris 1.
- `calibration=0|1`: uji angka contoh, tanpa menulis rekening.
- `layout[...]`: `x_top`, `y_top`, `x_bottom`, `y_bottom`, `header`, `row_height`, `rows_top`, `rows_bottom`, `text_offset`, `w_no`, `w_date`, `w_in`, `w_out`, `w_balance`, `w_sign`.

Nilai default lama tetap membuka seluruh jurnal. Nomor dan saldo rentang parsial tetap memakai jurnal lengkap. Pembacaan saldo/jurnal berada dalam snapshot yang sama; ketidakcocokan saldo tetap HTTP 409. Unit, identitas siswa, dan hak akses tetap diperiksa. URL cetak transaksi lama tetap memeriksa hak transaksi sebelum mengarahkan ke buku.

Pengaturan browser disimpan pada `spp_plq20_layout_UNIT`, hanya geometri, tanpa identitas siswa atau data transaksi. Tidak ada migrasi atau penyimpanan kalibrasi di database.

## Peserta dan laporan

Daftar peserta memakai judul 20 px, isi/nama 14 px, label/tombol 13 px, badge 12 px. Informasi siswa tertahan memakai nama/judul 16 px dan isi 14 px. Chevron SVG berbidang membulat menggantikan simbol teks. Pencarian, ekspor, pagination, pemantauan/pemrosesan, serta pengaman kenaikan tetap berlaku.

Otorisasi dan Rekap Transaksi memakai preview PDF asli A4 lanskap. Setiap Cetak/Download memeriksa endpoint terbaru dan memperbarui preview bila isi berubah; reuse file hanya jika fingerprint model terbaru sama. Cetak membuka penampil PDF siap cetak. Tersedia status pemuatan, Coba Lagi, serta tautan PDF jika penampil browser tidak tersedia. [Percepatan laporan dan buku bold rata tengah](PERCEPATAN_LAPORAN_20261018.md) menjadi acuan perilaku terbaru. Preview lain tetap memakai jalur existing.

PDF Otorisasi menyembunyikan username serta keterangan Pencarian/Total, Jenis/Status dan paragraf penjelasan filter. Nama, peran, waktu, alasan, pengajuan/status dan kronologi tetap ada. Perubahan memakai kolom Informasi/Sebelum/Sesudah atau Usulan. Teks panjang dilanjutkan dalam baris terukur, bukan dipotong. Snapshot dan pencarian tetap mencakup username.

Rekap menggunakan model bertipe yang sama dengan Excel, tanpa mengubah query/total/urutan. Nomor sempit, identitas/keterangan lebih luas, nominal rata kanan, subtotal jelas, dan kepala tabel diulang. `output=inline` ditambahkan pada jalur Rekap multi-filter; `output=pdf` tetap mengunduh.

## Verifikasi

Cadangan sumber dan artefak privat berada di `C:/laragon/backups/spp-management-system/plq20_reports_20261018_190712`. Pengujian HTTP menggunakan instance terpisah port 64421 dan PHP loopback 8893, dengan UUID berbeda dari database operasional.

Tes meliputi geometri/rentang/margin, buku kosong/nominal besar/pecahan/saldo nol, tiga unit, kedua tema, desktop/tablet/ponsel, keyboard, penyimpanan/reset kalibrasi, PDF produksi/panduan/uji, gagal-muat/Coba Lagi, akses unit, peran/CSRF, isi audit panjang, serta pembacaan balik PDF dan Excel. Uji sintetis 350 baris menghasilkan 18 halaman pada konfigurasi awal; jumlah halaman mengikuti isi, bukan batas tetap. Pengujian fisik PLQ-20 tetap dilakukan pengguna.

Pengujian otomatis: `plq20_reports_test.php`, `plq20_reports_http_test.php`, `plq20_reports_browser_test.js`, `plq20_reports_output_test.py`, serta regresi peserta, kategori Otorisasi, dan buku existing.
