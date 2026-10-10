# Riwayat dan Cetak Buku Tabungan

## Tampilan dan alur

Riwayat menampilkan total masuk/keluar dan jumlah transaksi dari seluruh hasil filter, bukan halaman yang tampil. Daftar dan detail memakai pasangan jenis transaksi (`masuk`/`keluar`) dan ID, karena kedua tabel dapat mempunyai ID yang sama. Nomor tampilan `TB-M-{unit}-{id}` atau `TB-K-{unit}-{id}` adalah referensi deterministik, bukan nomor baru dalam database.

Rekap saldo per siswa tetap berada di bawah daftar transaksi, dengan filter kelas/status dan pagination yang tersedia. Baris tersembunyi tetap tersembunyi pada tata letak ponsel. Operator yang tidak dapat diidentifikasi tidak ditebak dari nama; IP/perangkat dan waktu input terpisah tidak ditampilkan karena belum tersimpan.

Tabungan Masuk/Keluar memakai panel pemilih siswa dan formulir terpisah. Pemeriksaan saldo tetap harus berhasil sebelum pengiriman; respons siswa sebelumnya diabaikan. Penarikan sebesar saldo diperbolehkan. Bingkai merah dan modal muncul untuk nominal berlebih. Tombol simpan dinonaktifkan setelah pengiriman valid; database tetap menggunakan kunci permintaan dan penguncian saldo yang sudah ada.

Setelah commit berhasil, sesi menyimpan `savings_print_prompt` berisi jenis, ID, dan unit. Riwayat mengonsumsinya sekali dan menawarkan **Cetak Buku Tabungan**. Pilih Ya, cetak buku untuk membuka pratinjau buku lengkap siswa; Tidak, nanti menutup tawaran. Cetak ulang tidak menulis data keuangan.

## Endpoint dan kepemilikan

- GET `tabungan/detail.php?jenis=masuk|keluar&id=ID` mengembalikan `ok`, `transaction`, dan HTML detail. `transaction.can_print` dihitung server. Respons tidak disimpan di cache.
- GET `tabungan/cetak_struk.php?jenis=masuk|keluar&id=ID&output=preview|pdf` menampilkan buku lengkap melalui URL cetak transaksi yang lama. Keluaran bawaan adalah pratinjau. Identitas siswa/unit ditentukan server dari transaksi; parameter siswa/unit tambahan tidak mengganti identitas tersebut. Tautan PDF tetap memakai endpoint ini dan memeriksa ulang hak cetak.
- Permintaan anonim/akun nonaktif menghasilkan 401, format salah 400, transaksi di luar cakupan 404, metode selain GET 405, dan cetak tanpa kepemilikan 403.

Pemilik ditentukan dari `keuangan_request` dengan unit, aksi, dan referensi yang cocok. Jika bukti tersebut tidak ada, ID numerik penuh pada `user_id` dapat digunakan. Bukti bertentangan atau teks nama lama tidak memberikan kepemilikan. Super Admin mencetak seluruh transaksi yang berada dalam cakupannya; Admin/Kasir/Bendahara mencetak transaksi sendiri. Transaksi akun lain tetap dapat dilihat. Aturan kepemilikan berlaku untuk jalur cetak dari transaksi, baik pratinjau maupun PDF; akses lewat menu Cetak Tabungan mengikuti aturan sebelumnya.

Tombol pada daftar/detail transaksi kini berlabel **Cetak Buku Tabungan**. Keluaran sama dengan menu Cetak Tabungan: sampul, identitas siswa/sekolah, dan seluruh baris mutasi. Filter tanggal Riwayat tidak membatasi isi buku; saldo pada setiap baris dihitung dari urutan seluruh mutasi, bukan saldo saat ini yang disalin ke semua baris.

## Buku tabungan

URL `tabungan/cetak_buku.php` tetap menerima NIS/identitas siswa dan keluaran pratinjau/PDF. Kedua jalur cetak memakai pemuat/pratinjau/PDF bersama di `includes/savings_book_print.php` dan renderer `includes/savings_book_render.php`. Saldo tersimpan dan jurnal dibaca dalam satu snapshot; jurnal selalu dibatasi NIS serta unit siswa.

Buku akhir A6 lanskap: kertas A5 potret, skala 100%, dua sisi dengan pembalikan sisi pendek. Halaman disusun atas/bawah, dilipat horizontal, dan dibuka ke atas; sampul belakang diputar agar terbaca saat buku ditutup. Jumlah halaman selalu kelipatan empat. Cobalah satu lembar terlebih dahulu karena pengaturan duplex dan arah pemasukan kertas berbeda antarprinter.

Sampul SIMPEL memakai identitas sekolah sesuai unit dan kalimat motivasi tanpa atribusi hadis. Tabel berisi No, Tanggal, Tabungan Masuk/Keluar, Jumlah, dan Paraf. Jumlah berarti saldo berjalan. Setoran/penarikan baru menambah satu baris, bukan menimpa baris sebelumnya: Rp500.000 + setoran Rp100.000 menghasilkan Rp600.000; Rp500.000 - penarikan Rp100.000 menghasilkan Rp400.000. Nomor berlanjut antarhalaman; paraf/baris tambahan kosong. Ketidakcocokan saldo tersimpan dengan seluruh mutasi tetap memblokir pembuatan buku dengan 409.

## Hasil pemeriksaan

Pengujian HTTP dan browser memakai `db_spp_audit_authorization_20261007`, bukan database aktif. Tes mencakup penyimpanan, CSRF, rollback penarikan berlebih, penarikan sebesar saldo, eksekusi berulang, seluruh peran, kepemilikan tidak diketahui, akun nonaktif, unit lain, total/identitas/pagination terhadap database, detail tanpa JavaScript, kedua tema, dan ukuran desktop/tablet/ponsel.

Tes PDF memeriksa buku kosong, 55 mutasi, nomor lintas halaman, nama panjang, karakter khusus, NIS berawalan nol, nominal besar, jumlah lembar, dan kecocokan saldo. Pemeriksaan visual/ekstraksi PDF dilakukan pada hasil renderer; cetak fisik pada printer belum dilakukan.

Pengujian otomatis: `tests/savings_workspace_http_test.php`, `tests/savings_workspace_browser_test.js`, `tests/savings_book_test.php`, dan `tests/savings_book_pdf_test.php`. Tes mutasi HTTP memerlukan flag `SPP_TEST_ALLOW_MUTATION=1`, database `db_spp_audit_*`, serta server loopback yang terverifikasi; fixture transaksi/saldo/status akun dipulihkan setelah tes. Artefak dan sesi browser disimpan di direktori QA di luar repositori.

Tidak ada migrasi database, perubahan perhitungan keuangan aktif, commit, atau push.

## Verifikasi penyatuan cetak ? 10 Oktober 2026

Database uji `db_spp_audit_operator_pdf_20261010` dipakai untuk transaksi nyata tiga unit, saldo Rp500.000 ? Rp600.000 ? Rp500.000 ? Rp400.000 pada empat baris, replay, CSRF, penarikan berlebih/penuh, kepemilikan, identitas tidak tercatat, akun nonaktif, isolasi unit, format keluaran, manipulasi parameter siswa, dan ketidakcocokan saldo. PDF kedua jalur dibandingkan melalui ekstraksi teks dan raster seluruh halaman: isi dan tata letak identik. Fixture dipulihkan; database utama tidak ditulis.

Browser memeriksa 60 keadaan Tabungan (tiga ukuran layar, dua tema, empat cakupan), detail/AJAX, tautan buku/pratinjau/PDF dan dialog yang muncul sekali. Regresi laporan/pencarian memeriksa 48 keadaan serta ukuran font aktual; seluruh tes lulus. Cetak fisik pada printer belum dilakukan.
