# Riwayat, Struk, dan Buku Tabungan

## Tampilan dan alur

Riwayat menampilkan total masuk/keluar dan jumlah transaksi dari seluruh hasil filter, bukan halaman yang tampil. Daftar dan detail memakai pasangan jenis transaksi (`masuk`/`keluar`) dan ID, karena kedua tabel dapat mempunyai ID yang sama. Nomor tampilan `TB-M-{unit}-{id}` atau `TB-K-{unit}-{id}` adalah referensi deterministik, bukan nomor baru dalam database.

Rekap saldo per siswa tetap berada di bawah daftar transaksi, dengan filter kelas/status dan pagination yang tersedia. Baris tersembunyi tetap tersembunyi pada tata letak ponsel. Operator yang tidak dapat diidentifikasi tidak ditebak dari nama; IP/perangkat dan waktu input terpisah tidak ditampilkan karena belum tersimpan.

Tabungan Masuk/Keluar memakai panel pemilih siswa dan formulir terpisah. Pemeriksaan saldo tetap harus berhasil sebelum pengiriman; respons siswa sebelumnya diabaikan. Penarikan sebesar saldo diperbolehkan. Bingkai merah dan modal muncul untuk nominal berlebih. Tombol simpan dinonaktifkan setelah pengiriman valid; database tetap menggunakan kunci permintaan dan penguncian saldo yang sudah ada.

Setelah commit berhasil, sesi menyimpan `savings_print_prompt` berisi jenis, ID, dan unit. Riwayat mengonsumsinya sekali dan menawarkan cetak. Cetak ulang tidak menulis data keuangan.

## Endpoint dan kepemilikan

- GET `tabungan/detail.php?jenis=masuk|keluar&id=ID` mengembalikan `ok`, `transaction`, dan HTML detail. `transaction.can_print` dihitung server. Respons tidak disimpan di cache.
- GET `tabungan/cetak_struk.php?jenis=masuk|keluar&id=ID` menampilkan struk HTML siap cetak A5 lanskap, mengikuti ukuran struk pembayaran.
- Permintaan anonim/akun nonaktif menghasilkan 401, format salah 400, transaksi di luar cakupan 404, metode selain GET 405, dan cetak tanpa kepemilikan 403.

Pemilik ditentukan dari `keuangan_request` dengan unit, aksi, dan referensi yang cocok. Jika bukti tersebut tidak ada, ID numerik penuh pada `user_id` dapat digunakan. Bukti bertentangan atau teks nama lama tidak memberikan kepemilikan. Super Admin mencetak seluruh transaksi yang berada dalam cakupannya; Admin/Kasir/Bendahara mencetak transaksi sendiri. Transaksi akun lain tetap dapat dilihat. Aturan ini khusus struk; akses buku mengikuti aturan sebelumnya.

Struk berisi identitas siswa/sekolah, referensi, tanggal WIB, nominal, terbilang, keterangan, operator, dan tanda tangan. Saldo saat ini tidak ditampilkan sebagai saldo historis.

## Buku tabungan

URL `tabungan/cetak_buku.php` tetap menerima NIS/identitas siswa dan keluaran pratinjau/PDF. Renderer bersama di `includes/savings_book_render.php` menerima model yang sama untuk setiap keluaran.

Buku akhir A6 lanskap: kertas A5 potret, skala 100%, dua sisi dengan pembalikan sisi pendek. Halaman disusun atas/bawah, dilipat horizontal, dan dibuka ke atas; sampul belakang diputar agar terbaca saat buku ditutup. Jumlah halaman selalu kelipatan empat. Cobalah satu lembar terlebih dahulu karena pengaturan duplex dan arah pemasukan kertas berbeda antarprinter.

Sampul SIMPEL memakai identitas sekolah sesuai unit dan kalimat motivasi tanpa atribusi hadis. Tabel berisi No, Tanggal, Tabungan Masuk/Keluar, Jumlah, dan Paraf. Jumlah berarti saldo berjalan. Nomor berlanjut antarhalaman; paraf/baris tambahan kosong. Ketidakcocokan saldo tersimpan dengan seluruh mutasi tetap memblokir pembuatan buku dengan 409.

## Hasil pemeriksaan

Pengujian HTTP dan browser memakai `db_spp_audit_authorization_20261007`, bukan database aktif. Tes mencakup penyimpanan, CSRF, rollback penarikan berlebih, penarikan sebesar saldo, eksekusi berulang, seluruh peran, kepemilikan tidak diketahui, akun nonaktif, unit lain, total/identitas/pagination terhadap database, detail tanpa JavaScript, kedua tema, dan ukuran desktop/tablet/ponsel.

Tes PDF memeriksa buku kosong, 55 mutasi, nomor lintas halaman, nama panjang, karakter khusus, NIS berawalan nol, nominal besar, jumlah lembar, dan kecocokan saldo. Pemeriksaan visual/ekstraksi PDF dilakukan pada hasil renderer; cetak fisik pada printer belum dilakukan.

Pengujian otomatis: `tests/savings_workspace_http_test.php`, `tests/savings_workspace_browser_test.js`, `tests/savings_book_test.php`, dan `tests/savings_book_pdf_test.php`. Tes mutasi HTTP memerlukan flag `SPP_TEST_ALLOW_MUTATION=1`, database `db_spp_audit_*`, serta server loopback yang terverifikasi; fixture transaksi/saldo/status akun dipulihkan setelah tes. Artefak dan sesi browser disimpan di direktori QA di luar repositori.

Tidak ada migrasi database, perubahan perhitungan keuangan aktif, commit, atau push.
