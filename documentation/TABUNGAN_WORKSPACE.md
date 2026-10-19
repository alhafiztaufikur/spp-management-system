# Alur aktif Tabungan — 17 Oktober 2026

## Filter rombel dan kas sekolah — 18 Oktober 2026

Riwayat menyediakan filter beberapa **Kelas/Rombel rekening sekarang**, termasuk custom/Out/PSB dan rombel nonaktif beranggota. Filter diterapkan sebelum daftar, ringkasan dan pagination aktif/arsip; pilihan bertahan pada detail/AJAX, tab, pagination dan kembali dari koreksi. Saldo serta filter Rekap Saldo yang terpisah tidak berubah. Penjelasan lama Filter Operator pada toolbar dihilangkan.

Dashboard dan **Rekap Kas Tabungan Harian** beserta preview/PDF/Excel mengecualikan rekening custom berdasarkan rombel sekarang, termasuk mutasi lama. Jurnal, saldo, buku dan laporan khusus Tabungan tetap mencakup custom. Aturan ini menggantikan perilaku kas sekolah sebelumnya; tidak menghapus transaksi. [Panduan lengkap](FILTER_ROMBEL_TABUNGAN_CUSTOM_20261018.md).

Super Admin dan Administrator mengoreksi/menghapus langsung melalui dialog isian dan tinjauan. Alasan wajib, saldo historis tidak boleh negatif, snapshot/version diperiksa, dan kiriman ganda ditolak. Pengajuan lama tetap terkunci sampai diputuskan Super Admin. Semua Unit dan arsip tetap baca saja. Riwayat Aktivitas tidak menyediakan kontrol keputusan.

Filter Operator tetap berarti pembuat awal, mencakup akun lama yang dinonaktifkan. Filter membatasi daftar, ringkasan, pagination, dan Cetak Laporan; tidak membatasi saldo rekening. **Cetak Buku Tabungan** selalu mencetak seluruh mutasi sah siswa pada A5 potret, tanpa sampul/lipat. URL bukti transaksi lama memeriksa akses dahulu lalu mengarahkan ke buku siswa.

Rincian fitur sebelumnya di bawah merupakan catatan evolusi; aturan aktif di atas menggantikan pengajuan Administrator dan cetak buku lipat/kwitansi Tabungan.

# Riwayat dan Cetak Buku Tabungan

## Tombol tindakan, dialog dan Operator - 17 Oktober 2026

Detail transaksi memakai tombol **Edit**, **Hapus**, dan **Riwayat Aktivitas**. Super Admin dan Administrator memakai **Edit/Hapus** langsung dengan alasan. Hanya dua role aktif tersedia. Pengajuan Tabungan lama tetap menunggu keputusan Super Admin dan belum mengubah saldo; pengajuan baru tidak digunakan. Pending mengunci perubahan baru; Semua Unit dan arsip tidak menyediakan edit/hapus.

Edit/hapus membuka satu dialog dengan tahap isian dan tinjauan sebelum pengiriman. Nominal berformat ribuan, waktu WIB, keterangan dan alasan tetap divalidasi. **Kembali** mempertahankan isian, **Batal/Escape** menutup tanpa menyimpan, fokus kembali ke tombol pembuka. Kiriman ganda ditahan dan pergantian detail AJAX membatalkan dialog transaksi lama. Riwayat Aktivitas berada pada dialog terpisah yang hanya membaca informasi.

Dropdown **Operator** menyediakan pencarian, beberapa pilihan, Semua operator dan Tidak tercatat. Operator adalah pembuat awal: bukti `keuangan_request` yang cocok berdasarkan unit/aksi/referensi menjadi sumber utama; bukti bertentangan menghasilkan Tidak tercatat. Jika tidak ada bukti permintaan, hanya ID numerik penuh `user_id` yang diterima. Nama yang mirip tidak digunakan untuk menebak akun. Akun nonaktif dan identitas historis tetap dapat dipilih; koreksi/persetujuan tidak memindahkan transaksi ke operator pengubah.

Filter diterapkan sebelum ringkasan dan pagination, pada aktif maupun arsip. Pilihan dipertahankan saat membuka detail/AJAX, berpindah halaman/tab dan kembali dari pengajuan/koreksi. Reset mengembalikan Semua operator. Saldo rekening dan saldo berjalan cetak tetap menghitung seluruh mutasi sah dari semua petugas. Ringkasan arsip diberi label Arsip dan tidak dihitung sebagai saldo aktif. **Cetak Laporan** pada tab aktif meneruskan filter yang sama ke laporan Tabungan; arsip tetap untuk pembacaan audit dan tidak menawarkan laporan transaksi aktif.

Parameter GET `operator[]` mendukung array maupun scalar. Formulir memakai `return_operator[]` untuk pemulihan filter. `GET tabungan/aktivitas.php?jenis=masuk|keluar&id=ID` mengembalikan `{ok, reference, student, html}` setelah pemeriksaan sesi, peran, identitas dan unit. HTML hanya berisi aktivitas, tanpa formulir mutasi/keputusan. Anonim 401, akses tidak diizinkan 403, identitas salah 400, transaksi di luar unit 404 dan metode bukan GET 405. Tindakan koreksi tetap menggunakan endpoint/payload existing. Tidak ada migrasi database.

## Dialog dan slip Tabungan - 17 Oktober 2026

Koreksi/hapus memakai dialog aplikasi dengan siswa, referensi, ringkasan dan alasan. Batal/Escape tidak menyimpan; tombol konfirmasi hanya mengirim sekali. Nominal memakai titik ribuan (`90.000`), diproses sebagai rupiah bulat. Pengajuan Administrator/Kasir tetap menunggu Super Admin.

Bukti satu transaksi kini mengikuti konsep slip SPP: kop/alamat di tengah, identitas dua kolom, rincian mutasi, saldo sesudahnya, total, terbilang, keterangan dan tanda tangan. PDF/browser/PNG memakai pembentuk bersama. Buku Tabungan tidak berubah.


## Perapihan bukti dan detail - 16 Oktober 2026

Bukti transaksi menampilkan logo/identitas sekolah, referensi dan waktu WIB, identitas siswa, nominal dan saldo sesudah transaksi, keterangan serta tanda tangan petugas. Pratinjau, PDF dan PNG memakai isi yang sama. Ukuran Letter separo tetap mengikuti pengaturan printer existing.

Riwayat Tabungan mengelompokkan tombol cetak, panel koreksi, panel penghapusan, serta linimasa aktivitas. Panel formulir dapat dibuka/tutup; formulir menampilkan alasan dan penjelasan dampak saldo. Aktivitas terbaru muncul dahulu dengan petugas, waktu, alasan dan nilai sebelum/sesudah. Hak akses, pemeriksaan saldo dan proses persetujuan tetap mengikuti kebijakan aktif.


## Tampilan dan alur

Riwayat menampilkan total masuk/keluar dan jumlah transaksi dari seluruh hasil filter, bukan halaman yang tampil. Daftar dan detail memakai pasangan jenis transaksi (`masuk`/`keluar`) dan ID, karena kedua tabel dapat mempunyai ID yang sama. Nomor tampilan `TB-M-{unit}-{id}` atau `TB-K-{unit}-{id}` adalah referensi deterministik, bukan nomor baru dalam database.

Rekap saldo per siswa tetap berada di bawah daftar transaksi, dengan filter kelas/status dan pagination yang tersedia. Baris tersembunyi tetap tersembunyi pada tata letak ponsel. Operator yang tidak dapat diidentifikasi tidak ditebak dari nama; IP/perangkat dan waktu input terpisah tidak ditampilkan karena belum tersimpan.

Tabungan Masuk/Keluar memakai panel pemilih siswa dan formulir terpisah. Pemeriksaan saldo tetap harus berhasil sebelum pengiriman; respons siswa sebelumnya diabaikan. Penarikan sebesar saldo diperbolehkan. Bingkai merah dan modal muncul untuk nominal berlebih. Tombol simpan dinonaktifkan setelah pengiriman valid; database tetap menggunakan kunci permintaan dan penguncian saldo yang sudah ada.

Setelah commit berhasil, sesi menyimpan `savings_print_prompt` berisi jenis, ID, dan unit. Riwayat mengonsumsinya sekali dan menawarkan **Cetak Bukti Transaksi**. Pilih Ya untuk membuka bukti mutasi yang baru disimpan; Tidak, nanti menutup tawaran. Cetak ulang tidak menulis data keuangan.

## Endpoint dan kepemilikan

- GET `tabungan/detail.php?jenis=masuk|keluar&id=ID` mengembalikan `ok`, `transaction`, dan HTML detail. `transaction.can_print` dihitung server. Respons tidak disimpan di cache.
- GET `tabungan/cetak_struk.php?jenis=masuk|keluar&id=ID&output=preview|pdf` menampilkan bukti satu transaksi melalui pembentuk slip bersama. Keluaran bawaan adalah pratinjau. Identitas siswa/unit ditentukan server dari transaksi; parameter siswa/unit tambahan tidak mengganti identitas tersebut. Tautan PDF tetap memakai endpoint ini dan memeriksa ulang hak cetak.
- Permintaan anonim/akun nonaktif menghasilkan 401, format salah 400, transaksi di luar cakupan 404, metode selain GET 405, dan cetak tanpa kepemilikan 403.

Pemilik ditentukan dari `keuangan_request` dengan unit, aksi, dan referensi yang cocok. Jika bukti tersebut tidak ada, ID numerik penuh pada `user_id` dapat digunakan. Bukti bertentangan atau teks nama lama tidak memberikan kepemilikan. Super Admin/Admin/Kasir mencetak transaksi aktif dalam cakupannya; Bendahara mencetak transaksi sendiri. Transaksi akun lain tetap dapat dilihat. Aturan kepemilikan berlaku untuk jalur cetak dari transaksi, baik pratinjau maupun PDF; akses lewat menu Cetak Tabungan mengikuti aturan sebelumnya.

Tombol pada daftar/detail transaksi berlabel **Cetak Bukti Transaksi** dan mencetak satu mutasi. **Cetak Buku Tabungan** tetap terpisah untuk seluruh jurnal siswa. Filter tanggal/operator Riwayat tidak membatasi isi buku atau mengubah saldo historis.

## Buku tabungan aktif - EPSON PLQ-20, 18 Oktober 2026

Cetak menggunakan buku A5 terbuka dengan lipatan horizontal yang sudah bergaris. PDF produksi hanya mengisi No, Tanggal, Masuk/Keluar dan Jumlah (saldo berjalan); Paraf kosong. Preview memperlihatkan garis panduan yang tidak ikut dicetak. Tidak ada sampul atau penyusunan duplex. Ketentuan ini menggantikan format buku sebelumnya.

Pengguna memilih rentang transaksi, halaman atas/bawah dan baris awal untuk melanjutkan buku. Nomor/saldo tetap berasal dari seluruh jurnal, termasuk mutasi custom; pencetakan tidak menandai atau mengubah transaksi. Kalibrasi milimeter tersedia dengan uji cetak, simpan browser per unit dan reset. Gunakan driver EPSON PLQ-20 ESC/P2, Passbook On, Binding Horizontal, A5 potret dan skala 100%. Preset harus dicocokkan melalui uji fisik sebelum dinyatakan tepat pada buku pengguna.

`cetak_buku.php` tetap menerima NIS/identitas siswa dan preview/PDF; tambahan posisi/layout divalidasi server. `cetak_struk.php` memeriksa identitas serta hak transaksi lalu mengarahkan ke buku. Ketidakcocokan saldo tersimpan dan jurnal tetap diblokir dengan 409. Rincian endpoint, ukuran dan pengujian tersedia di [panduan PLQ-20](CETAK_PLQ20_DAN_LAPORAN_20261018.md).

## Hasil pemeriksaan

Pengujian HTTP dan browser memakai `db_spp_audit_authorization_20261007`, bukan database aktif. Tes mencakup penyimpanan, CSRF, rollback penarikan berlebih, penarikan sebesar saldo, eksekusi berulang, seluruh peran, kepemilikan tidak diketahui, akun nonaktif, unit lain, total/identitas/pagination terhadap database, detail tanpa JavaScript, kedua tema, dan ukuran desktop/tablet/ponsel.

Tes PDF memeriksa buku kosong, 55 mutasi, nomor lintas halaman, nama panjang, karakter khusus, NIS berawalan nol, nominal besar, jumlah lembar, dan kecocokan saldo. Pemeriksaan visual/ekstraksi PDF dilakukan pada hasil renderer; cetak fisik pada printer belum dilakukan.

Pengujian otomatis: `tests/savings_workspace_http_test.php`, `tests/savings_workspace_browser_test.js`, `tests/savings_book_test.php`, dan `tests/savings_book_pdf_test.php`. Tes mutasi HTTP memerlukan flag `SPP_TEST_ALLOW_MUTATION=1`, database `db_spp_audit_*`, serta server loopback yang terverifikasi; fixture transaksi/saldo/status akun dipulihkan setelah tes. Artefak dan sesi browser disimpan di direktori QA di luar repositori.

Tidak ada migrasi database, perubahan perhitungan keuangan aktif, commit, atau push.

## Verifikasi penyatuan cetak ? 10 Oktober 2026

Database uji `db_spp_audit_operator_pdf_20261010` dipakai untuk transaksi nyata tiga unit, saldo Rp500.000 ? Rp600.000 ? Rp500.000 ? Rp400.000 pada empat baris, replay, CSRF, penarikan berlebih/penuh, kepemilikan, identitas tidak tercatat, akun nonaktif, isolasi unit, format keluaran, manipulasi parameter siswa, dan ketidakcocokan saldo. PDF kedua jalur dibandingkan melalui ekstraksi teks dan raster seluruh halaman: isi dan tata letak identik. Fixture dipulihkan; database utama tidak ditulis.

Browser memeriksa 60 keadaan Tabungan (tiga ukuran layar, dua tema, empat cakupan), detail/AJAX, tautan buku/pratinjau/PDF dan dialog yang muncul sekali. Regresi laporan/pencarian memeriksa 48 keadaan serta ukuran font aktual; seluruh tes lulus. Cetak fisik pada printer belum dilakukan.
