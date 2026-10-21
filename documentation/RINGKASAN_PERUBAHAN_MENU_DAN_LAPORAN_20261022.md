# Penjelasan perubahan menu, Tabungan, dan laporan

Dokumen ini menjelaskan seluruh perubahan sejak commit `e80ba02`, termasuk pekerjaan tanggal 21–22 Oktober 2026 yang sebelumnya belum masuk Git. Tujuannya agar anggota tim dapat memahami perubahan perilaku, alasan perbaikan, cara memeriksa hasil, serta kebutuhan saat menjalankan kode di komputer lain.

## 1. Perpindahan menu dan Input Pembayaran

Sebelumnya, sejumlah halaman membaca arsip dalam jumlah besar walaupun pengguna hanya membuka satu halaman atau memilih satu siswa. Input Pembayaran juga menyiapkan rincian semua siswa sejak halaman dibuka. Sekarang pembacaan dibatasi berdasarkan unit, siswa/pemilik, periode, dan halaman yang diperlukan. Perhitungan ringkasan dipisahkan dari pengambilan rincian halaman jika memungkinkan.

Perubahan yang terlihat bagi pengguna:

- Halaman Input Pembayaran memuat identitas siswa terlebih dahulu. Rincian tagihan baru dibaca ketika siswa dipilih melalui endpoint status yang sama, dengan tambahan field respons tanpa menghapus field lama.
- Pemilihan siswa langsung memulai pemeriksaan; jeda pengetikan tetap digunakan untuk perubahan lain yang memerlukannya.
- Jika pengguna cepat berpindah dari siswa A ke B, respons A yang terlambat tidak menimpa tagihan B. Permintaan identik yang masih berjalan digabung, dan permintaan lama dibatalkan.
- Kegagalan jaringan dapat dicoba kembali. Isian setelah gagal menyimpan menunggu rincian yang benar sebelum pilihan tagihan dipulihkan.
- Font Inter dan Outfit yang sudah digunakan aplikasi disediakan secara lokal beserta lisensinya. Bentuk tulisan tetap sama, sementara perpindahan halaman tidak bergantung pada server font eksternal.

Pembaca kredit migrasi tetap mendukung pembacaan massal untuk rekap. Saldo dan tagihan tidak disimpan dalam cache lintas permintaan; jalur simpan tetap memeriksa data terbaru di server. Navigasi tetap menggunakan halaman PHP yang sudah ada.

Contoh pengukuran lokal sebelum/sesudah pada data yang sama:

| Halaman | Sebelum | Sesudah |
|---|---:|---:|
| Input Pembayaran | 969 ms | 158 ms |
| Data Siswa | 2.053 ms | 302 ms |
| Master Biaya Lain | 904 ms | 126 ms |
| Riwayat Tabungan | 1.010 ms | 491 ms |
| Laporan Transaksi | 719 ms | 234 ms |

Angka tersebut merupakan median tiga permintaan bergantian, bukan jaminan untuk komputer lain. Target seluruh halaman di bawah satu detik dan Ringkasan terlihat dalam 300 ms belum tercapai pada semua kasus. Rincian metode dan batas pengukuran tersedia pada [audit performa](AUDIT_PERFORMA_MENU_20261021.md).

## 2. Master Daftar Ulang, Biaya Lain, dan Naik Kelas

**Master Daftar Ulang:** Penerbitan Tagihan disusun menjadi judul/ringkasan di atas, persetujuan tunggakan pada baris tersendiri, kemudian tombol penerbitan dan penutupan pada baris tindakan yang sejajar. Kalimat persetujuan, konfirmasi, tarif, dan aturan penerbitan/penutupan tetap berlaku.

**Master Biaya Lain:** daftar dipisahkan menjadi tiga tab dengan jumlah data:

- **Aktif:** master yang masih aktif.
- **Nonaktif:** master yang sudah dinonaktifkan.
- **Bisa Dihapus:** subset dari kedua status yang belum dipakai pada tagihan/transaksi dan bukan master khusus pengesahan Legacy.

Master terpakai menampilkan alasan tidak dapat dihapus. Status tidak aktif tidak otomatis berarti boleh dihapus. Saat tombol Hapus dikirim, server tetap memeriksa penggunaan terbaru di dalam transaksi database. Contohnya, master yang awalnya dapat dihapus tetapi sudah dipakai petugas lain akan tetap ditolak penghapusannya.

**Naik Kelas:** mengganti sistem, tahun ajaran, atau tingkat asal tidak lagi langsung memuat peserta. Pengguna harus menekan **Tampilkan Peserta**. Jika konteks diganti setelah daftar muncul, daftar lama ditandai belum sesuai dan tidak dapat diproses sampai tombol ditekan kembali. Tinggi/lebar kontrol tujuan diselaraskan, pencarian dirapikan, header tabel diberi latar solid, serta menu Detail Penempatan/Buka Data Siswa dijaga agar tidak keluar dari area layar. Aturan PSB, reguler, custom, dan kelulusan tetap menggunakan layanan yang sama.

## 3. Tabungan Masuk/Keluar dan Riwayat

- Konsep dua panel pemilihan rekening dan formulir dipertahankan. Kartu saldo, jarak antarisian, rekening terpilih, dan tombol tindakan diperjelas.
- Informasi jumlah data berada di kiri, sedangkan navigasi halaman rata kanan pada daftar rekening, riwayat, dan daftar Cetak Tabungan. Dalam panel sempit, navigasi pindah ke baris berikutnya dan tetap rata kanan.
- Nominal tampil sebagai `100.000` atau `100.000,50`. Pengetikan, tempel angka, dan pemulihan isian gagal mengikuti parser server yang sudah ada; nilai ambigu tidak diam-diam diubah menjadi nominal lain.
- Penarikan dengan saldo kurang menampilkan popup berisi saldo tersedia dan nominal penarikan. Isian tetap tersedia. Pemeriksaan server tetap menentukan hasil, termasuk ketika saldo berubah sesudah formulir dibuka.
- Tombol Riwayat dikelompokkan menjadi filter, transaksi masuk/keluar, serta ekspor/cetak agar tidak memanjang dalam satu baris.
- Cetak dari daftar/detail Riwayat membuka buku berisi seluruh mutasi pemilik, memakai renderer yang sama dengan menu Cetak Tabungan. Endpoint struk lama tetap tersedia untuk pemanggil lain.
- Identitas Alumni dan tahun ditampilkan hanya jika ada bukti pada data. Rekening dan histori tetap memakai ID siswa/pemilik serta unit, bukan kecocokan nama.

## 4. Koreksi Tabungan dan hak Administrator

Administrator dapat mengoreksi tabungan biasa dan jurnal tabungan operasional Legacy secara langsung, dengan alasan, pratinjau dampak, dan jejak audit. Perluasan hak pada jurnal Legacy terbatas pada nominal, tanggal/waktu yang diizinkan, serta catatan. Pemindahan pemilik dan pembatalan Legacy tetap khusus Super Admin. Hak koreksi pembayaran dan pengelolaan impor tidak diperluas.

Saat menyimpan, server tetap memeriksa peran aktif, unit, CSRF, versi data, kedaluwarsa pratinjau, kiriman ulang, saldo berjalan, dan kemungkinan saldo negatif. Perubahan saldo oleh petugas lain tidak dilewati hanya karena pengguna sudah melihat pratinjau.

Tampilan koreksi memakai identitas pemilik yang jelas, nominal Indonesia, alasan, serta ringkasan sebelum/sesudah. Pada penyempurnaan desktop terakhir:

- Kontrol Pemilik dan Tanggal/waktu WIB memiliki posisi dan tinggi yang sejajar.
- Tanggal memakai `DD/MM/YYYY`, nama bulan Indonesia, pilihan tahun, dan kalender yang mudah digunakan.
- Jam dipisahkan dalam format 24 jam dengan penanda WIB.
- Field server tetap menerima timestamp ISO. Jam kosong pada sumber yang hanya memiliki tanggal tetap berarti **jam tidak tercatat**, bukan bukti transaksi pukul 00:00:00.
- Kalender pada dialog koreksi biasa tetap berada di atas dialog, dapat ditutup dengan Escape, dan kembali terpasang setelah rincian riwayat dimuat melalui AJAX. Isian juga dipertahankan setelah penolakan server.

## 5. Laporan Transaksi

Saat Semua jenis dipilih, halaman selalu menampilkan lima bagian: **Semua Transaksi, SPP Belum Lunas, Komite Belum Lunas, Daftar Ulang Belum Lunas, dan Biaya Lain Belum Lunas**. Bagian kosong tetap terlihat dengan penjelasan.

Sebelumnya, pagination menggabungkan kategori sehingga bagian tertentu baru muncul jauh di halaman belakang. Sekarang setiap bagian mempunyai pagination sendiri dengan pilihan 10/25/50 baris. Mengganti halaman DU tidak mengganti halaman pembayaran atau kategori lain, tidak memuat ulang seluruh halaman, serta mempertahankan filter, ringkasan, dan posisi gulir. Back/Forward mengikuti URL. Tautan GET tetap bekerja tanpa JavaScript. Jika jaringan gagal, tabel lama tetap tampil dengan tombol coba lagi; menggulir tabel tidak meminta data baru.

Pilihan cetak pada tabel yang berganti halaman direset, sedangkan pilihan tabel lain dipertahankan. Cetak pilihan tetap mengikuti dukungan endpoint yang sudah ada. Cetak per transaksi biasa dan Legacy memakai jalur serta pemeriksaan akses masing-masing.

Perbaikan susunan tabel dan ekspor:

- Header dan isi menggunakan ukuran kolom yang sama. Nama dapat membungkus dan rata kiri; nominal rata kanan; identitas, kelas, tanggal dan tindakan diselaraskan menurut jenis isinya.
- Header/identitas serta tombol cetak tetap terlihat saat tabel digulir, dengan latar solid. Gulir dibatasi pada pembungkus tabel.
- NIS Diknas dan unit, bila tersedia, ditampilkan sebagai keterangan identitas; tidak dihapus dari model data atau ekspor.
- Pilihan **Nominal terkecil** ditambahkan dengan urutan menaik dan pemecah urutan yang stabil. Pilihan lama tetap tersedia.
- PDF dan Excel mengambil seluruh hasil sesuai filter, bukan hanya halaman tabel yang sedang terlihat. Filter periode, siswa, unit, jenis, dan urutan tetap terbawa.
- Pada PDF, judul, header, seluruh isi sel dan subtotal rata tengah, dengan isi sel juga berada di tengah secara vertikal. Lebar nama, kelas, nominal dan tanggal/jam disesuaikan; renderer dokumen tetap sama.

Jalur teknis pagination bagian menggunakan `laporan/index.php?fragment=section`, `section`, dan `section_page[jenis]`. Respons JSON hanya membangun bagian yang diminta dan menyertakan identitas filter untuk mencegah respons lama mengganti hasil terbaru.

## 6. Laporan Global dan kelengkapan bukti

Empat rekap yang dirapikan adalah **Rekap Setoran Kas Harian, Rekap Kas Tabungan Harian, Rekap Transaksi Tabungan, dan Rekap Penerimaan Harian**. Tampilan desktop terakhir mengikuti referensi: judul/pemilih unit di kiri dan filter tiga kolom di kanan, dengan tinggi kontrol seragam, pencarian dan tombol tersusun. Semua Periode pada Rekap Transaksi Tabungan tampil sebagai sakelar tanpa mengganti nilai atau perilaku filter. Template Global lainnya tetap memakai susunan sebelumnya.

Audit pembacaan mencocokkan transaksi biasa, migrasi yang disahkan, alokasi pembayaran aktif, pembatalan, kredit historis, jurnal, dan saldo untuk SD/SMP/SMA/Semua Unit, termasuk Alumni dan pemilik non-siswa.

Temuan penting agar angka laporan tidak disalahartikan:

- Angka nol SPP/Komite Oktober tidak otomatis berarti lunas: pada data audit, tagihan biasa bulan tersebut belum tersedia. Laporan membedakan lunas/tercakup, belum diterbitkan, tidak ditagihkan, dibatalkan, cicilan, dan bukti belum lengkap.
- Sebanyak **9.288 pembayaran tanpa tanggal** juga tidak mempunyai tanggal pada arsip asli. Data tetap dapat diperiksa melalui filter existing; tidak diberi tanggal impor, hari ini, atau tanggal perkiraan.
- Sebanyak **793 jam pembayaran** memiliki bukti sumber dan dibaca dengan pencocokan unit, sumber, dan row key. Sebanyak **95.542 jurnal Legacy** tidak mempunyai bukti jam sumber; padding tengah malam bukan jam transaksi yang diketahui.
- Koreksi waktu yang disahkan mendapat prioritas. Penyertaan transaksi tanpa tanggal mengikuti periode kewajiban yang terbukti; Semua Periode memungkinkan pemeriksaan histori lebih luas.
- Pokok historis yang tidak diketahui tetap tidak diketahui, bukan diganti tarif sekarang. Pembayaran historis tetap terbaca tanpa membuat transaksi atau tagihan pengganti.

Rincian sumber, rekonsiliasi, selisih arsip yang dijelaskan, dan batas bukti tersedia pada [audit rekap](AUDIT_REKAP_DAN_MENU_20261022.md).

## 7. Konfigurasi dan indeks saat kode dijalankan di komputer lain

`koneksi.php` mempertahankan pilihan pengguna: default `db_spp_test2`, termasuk pengaman pemeliharaannya. Variabel lingkungan `SPP_DB_NAME`, host, port, user, dan password tetap dapat digunakan. Commit tidak membawa isi database, dump, sesi login, atau kredensial QA. Periksa database tujuan di lingkungan masing-masing sebelum menjalankan aplikasi atau skrip indeks.

Pekerjaan performa menyertakan empat indeks tambahan:

| Tabel | Kolom indeks |
|---|---|
| `legacy_op_journals_data` | `unit_id, source_date, owner_id` |
| `legacy_op_journals_data` | `unit_id, batch_id, source_operator, origin` |
| `legacy_op_payments_data` | `unit_id, batch_id, source_operator` |
| `legacy_op_payments_data` | `unit_id, cancelled, source_period, batch_id` |

Indeks tersebut sudah diterapkan pada database lokal pengguna pada tahap performa 21 Oktober. Untuk lingkungan lain, `php scripts/migrate_menu_performance.php` hanya memeriksa. Setelah database tujuan dan backup diverifikasi, opsi `--apply` menambahkan indeks yang belum tersedia; pengulangan mengenali indeks yang sudah sesuai. Skrip tidak dijalankan otomatis saat halaman dibuka dan tidak menulis ulang saldo/tagihan/transaksi. Tahap perapihan 22 Oktober tidak menambahkan migrasi skema baru. Melakukan pull kode tidak menyalin isi database lokal pengguna.

## 8. Pengujian dan hal yang masih perlu diketahui

Pengujian simpan/koreksi/penghapusan dilakukan pada database salinan dengan pengaman repository. Bukti terperinci dicatat pada tiga dokumen berikut; jumlah pemeriksaan merupakan hasil suite masing-masing dan tidak dijumlahkan menjadi jumlah kasus unik:

- [Audit performa menu](AUDIT_PERFORMA_MENU_20261021.md): 265 pemeriksaan model, 100 HTTP, kesetaraan laporan, 42.462 baris Riwayat Tagihan beserta urutannya, browser dan dokumen.
- [Audit rekap/menu](AUDIT_REKAP_DAN_MENU_20261022.md): 164 pemeriksaan model, 107 HTTP, 3.264 pemeriksaan rekonsiliasi Global pada baseline/hasil, browser pagination, serta 14 PDF/XLSX yang dibaca kembali.
- [Perapihan desktop](PERAPIHAN_DESKTOP_TRANSAKSI_20261022.md): regresi 164 model/107 HTTP, tiga lebar desktop dan dua tema, koreksi biasa/Legacy, arah teks serta posisi kolom, dan PDF lengkap SD/Semua Unit/penyertaan tanpa tanggal sebanyak 301/564/622 baris.

Skrip pengujian serta sumber font/lisensinya disertakan dalam commit. Screenshot, PDF berisi identitas siswa, dump, dan sesi pengujian disimpan privat di luar repository. Suite lama `payment_process_integration_test.php` belum lulus utuh karena asumsi fixture/formulir edit lamanya tidak sesuai konteks akses existing; hasil tersebut tidak dihitung sebagai pengujian lulus.

Target p95 seluruh fungsi di bawah satu detik belum terpenuhi: pagination DU Semua Unit terukur sekitar 1,17 detik dan rekap Transaksi Tabungan sekitar 1,23 detik pada audit terakhir. Pembuatan PDF besar diukur terpisah dari navigasi. Tidak ada janji waktu tunggu nol. Aturan nominal, saldo, tagihan, akses, dan bukti historis tetap menjadi dasar hasil aplikasi.
