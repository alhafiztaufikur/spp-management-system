# Pembayaran Legacy, Daftar Ulang, dan Tabungan Kelas Khusus

Pembaruan 20 Oktober 2026. Berlaku pada source utama dan lab `spp-management-system -Copy` dengan database `db_spp_test2`.

## Pembayaran dan otorisasi

Riwayat Pembayaran menggunakan panel yang sama untuk transaksi SistemSPP dan hasil migrasi operasional. Kartu menampilkan periode asli. Detail memuat identitas pemilik, komponen, metode, operator sumber, dan aktivitas. Kode operator Legacy tetap merupakan identitas sumber, bukan ID akun sekarang.

- **Cetak Bukti Transaksi** membuka format struk aplikasi. PDF mempertahankan dua desimal apabila nominal mempunyai pecahan. Tautan `cetak_legacy.php` lama diteruskan ke adaptor struk yang sama.
- **Edit** membuka formulir aplikasi dengan rincian sumber. Isi tanggal/periode, nominal komponen, metode pembayaran, catatan, dan alasan. Pilih **Periksa Perubahan**, kemudian **Simpan Perubahan** atau **Kirim Pengajuan**. Mengubah formulir/pratinjau belum mengubah transaksi.
- **Hapus** membuka konfirmasi server. Pengesahan membatalkan transaksi kerja dan menampilkannya pada **Transaksi Dihapus**. Arsip sumber tidak dihapus. Filter tanggal pada daftar dihapus menggunakan waktu pembatalan.
- Administrator mengajukan edit/hapus; Super Admin memberikan keputusan pada halaman **Otorisasi Transaksi**, atau melakukan perubahan langsung. Administrator dapat membatalkan pengajuannya dari detail pembayaran. Pengajuan yang sudah diputuskan tidak diterapkan ulang.
- **Riwayat Aktivitas** menampilkan operator sumber, perubahan, alasan, pemohon, dan keputusan. Tautan aktivitas juga tersedia tanpa JavaScript.

Identitas negatif pada URL dipertahankan. Perubahan memakai ledger operasional Legacy, tanpa membuat pembayaran native, kas masuk, atau rekening pengganti. Hanya kewajiban yang memiliki hubungan eksplisit diperbarui; koreksi histori tidak menerbitkan tagihan baru. Perubahan versi membuat usulan lama tidak dapat diterapkan.

## Riwayat Daftar Ulang

Halaman utama membaca tagihan operasional dan histori pembayaran Legacy secara default. Pilih sumber **Semua / SistemSPP / Legacy**, pencarian nama/identitas, kelas, tahun ajaran, operator, status, serta ukuran halaman. Filter kelas/tahun/status/operator tetap mendukung beberapa pilihan, termasuk tanpa JavaScript. Tautan lama `view=legacy` tetap menjadi pintasan sumber Legacy.

Pengelompokan memakai unit, ID pemilik internal, dan tahun ajaran. Pemilik non-siswa, alumni, serta siswa keluar tetap dapat ditemukan. **9.288 pembayaran DU tanpa tanggal** mengikuti tahun ajaran sumber; 629 pembayaran tanpa tahun tetap berlabel **Tidak tercatat**. Tanggal migrasi tidak digunakan sebagai tanggal bayar.

Nominal DU berasal dari pembayaran `bayar_du` yang telah diterima migrasi atau rincian komponen DU pada pembayaran campuran. Keputusan migrasi tentang pembayaran terpisah/referensi tetap dipertahankan; total pembayaran campuran tidak seluruhnya dianggap DU.

Referensi tarif dibaca dari `Daftar_ulang` (juga mendukung nama `du`), terikat sumber, unit, tahun, dan tingkat sumber. Tarif identik dihitung sekali. **Lunas/Belum Lunas historis** hanya dihitung jika kewajiban dan potongan historis sudah mempunyai bukti pengesahan yang sesuai, disertai tarif/periode/kelas yang lengkap. Jika bukti belum lengkap atau tarif bentrok, nominal pembayaran tetap terlihat dengan **Status belum dapat ditentukan**. Tarif dan potongan siswa saat ini tidak dianggap bukti untuk tahun historis lain.

Rekap historis ditampilkan terpisah dari pelunasan tagihan operasional. Hubungan pemilik/tahun yang sama tidak otomatis membuat histori melunasi tagihan native. Filter operator memilih pemilik/periode yang memiliki pembayaran operator tersebut; ringkasan status tetap menghitung seluruh pembayaran periode itu, sebagaimana alur operasional existing. Excel mengikuti filter dan membedakan kolom pembayaran/tagihan operasional dari histori Legacy.

## Tabungan siswa dan kelas khusus

**Tabungan Masuk/Keluar → Cari Siswa / Pemilik Rekening** menyediakan pencarian server, filter jenis pemilik dan kelas, serta 25 hasil per halaman. Klik pemilik untuk membuka saldo dan formulir yang sama. Formulir berfungsi tanpa JavaScript.

- Siswa memakai ledger native; rekening non-siswa memakai ledger rekening existing. Tidak membuat siswa atau NIS palsu.
- Rekening yang sudah siap dapat digunakan oleh alumni/OUT dan anggota kelas khusus nonaktif. Rekening tertahan atau saldo yang belum selesai direkonsiliasi tetap tidak dapat digunakan.
- Identitas `student_id`/NIS lama tetap diterima; `owner_id` tersedia bagi non-siswa. Parameter yang menunjuk dua pemilik berbeda ditolak.
- Nominal seperti `1000`, `1.000`, `1000,13`, dan `1.000,13` didukung. Saldo diperiksa ulang di server; penarikan melebihi saldo ditolak. Pengiriman ulang formulir tidak mencatat transaksi dua kali.
- Setelah simpan, aplikasi membuka transaksi yang baru dibuat. Tersedia **Cetak Bukti Transaksi**, riwayat, dan buku rekening pemilik yang benar.

Kas Tabungan/dashboard/laporan menggabungkan transaksi baru siswa dan non-siswa, dengan rincian jenis pemilik. Jurnal historis, saldo peralihan, dan penyesuaian migrasi tidak menjadi kas baru. Pada kolom paraf buku sempit, `Alih` berarti saldo peralihan dan `FIX` berarti penyesuaian; nominal dan urutan tidak berubah.

## Pemasangan

Metadata opsional dipasang melalui `scripts/migrate_legacy_transactions.php`, bukan melalui web. Installer menambahkan `transaction_source`, `legacy_payment_id`, constraint sumber, serta indeks pencarian; FK `bayar_id` native tetap dipertahankan. Database tanpa metadata ini tetap dapat membaca/mengelola transaksi native.

```powershell
php scripts/migrate_legacy_transactions.php --check --expect-db=db_spp_test2
php scripts/migrate_legacy_transactions.php --apply --expect-db=db_spp_test2 --backup=C:/lokasi/backup-terverifikasi.sql
```

Koneksi mengikuti konfigurasi instalasi yang menjalankan CLI. `--expect-db` memeriksa target dan tidak mengganti koneksi. Cadangkan serta verifikasi pemulihan database terlebih dahulu. DDL MySQL tidak merupakan satu transaksi; installer dapat dijalankan ulang setelah kegagalan. Jangan menjalankan migrasi akun/workflow atau migrasi ulang Legacy untuk memasang fitur ini.

Pemasangan aktual lab memiliki backup SQL dan file, serta manifest hash di `C:/laragon/backups/spp-management-system/legacy_unification_20261020`. Konfigurasi, sesi terpisah, akun/password, draf, saldo FIX, arsip dan antrean tidak ditimpa. Tidak ada perubahan database utama, finalisasi ulang, commit, push, atau deployment.

## Bukti pemeriksaan dan batasnya

- Snapshot awal: 118.244 pembayaran, 9.288 DU tanpa tanggal, dan 2.418 rekening. Regresi pembacaan mencakup jumlah/daftar/detail/pagination SD, SMP, SMA, Semua Unit dan rekonsiliasi buku.
- **54 pemeriksaan regresi pembacaan**, **38 pemeriksaan transaksi**, **20 kasus khusus**, dan **5 pemeriksaan kewajiban terhubung** lulus. Pengujian mutasi pada MySQL terisolasi memeriksa edit pecahan, pengajuan/keputusan/pembatalan, versi berubah, retry, soft delete, kas non-siswa, rekening belum siap, kelas khusus nonaktif, dan rollback dengan kegagalan audit yang sengaja disuntikkan.
- **7 pemeriksaan HTTP transaksi** dan **9 pemeriksaan halaman lab tanpa mutasi** lulus. Instalasi tanpa metadata Legacy juga berhasil membaca 144 kelompok DU native.
- **56 pemeriksaan browser dan 6 pemeriksaan UI akhir** lulus. Chromium **109.0.5412.0** dijalankan untuk desktop 1366 px, tablet 820 px, ponsel 390 px, kedua tema dan ketiga palet unit, keyboard, serta konfirmasi tanpa JavaScript. Screenshot diperiksa; tidak ada overflow horizontal. Tidak mengklaim pengujian cetak fisik PLQ-20 atau zoom browser sebenarnya.
- Struk tiga unit, struk pecahan, struk setoran non-siswa dan buku rekening berhasil dibuat sebagai PDF serta dibaca/render ulang. Excel DU tiga unit diperiksa isi/jumlah kelompok dan nominalnya (3.018 SD, 2.824 SMP, 1.095 SMA). Struk tambahan DU tanpa tanggal mempertahankan periode sumber dan menulis tanggal/jam **Tidak tercatat**. Pengujian mutasi tidak dilakukan pada database pengguna.
- Fingerprint seluruh kolom/baris awal **51 tabel utama dan 60 tabel lab** identik setelah pemasangan; dua kolom metadata baru pada tabel otorisasi lab merupakan satu-satunya penambahan kolom. Bukti lengkap, backup SQL terverifikasi lewat pemulihan terisolasi, log pemasangan, hasil uji, dan screenshot disimpan di folder audit tersebut. Manifest mencatat 41 file aplikasi terpasang dan hash sebelum/sesudah. Ringkasan tanpa data pribadi: [LEGACY_UNIFICATION_20261020.json](LEGACY_UNIFICATION_20261020.json). Layanan HTTP/MySQL pengujian dihentikan setelah verifikasi.

Master tarif/potongan historis yang belum terbukti tetap menghasilkan status belum dapat ditentukan; ini tidak menyembunyikan pembayarannya dan tidak menambah tagihan. Data `legacy_full_payments` lama masih didukung oleh adaptor baca lama; dukungan perubahan/otorisasi baru ditujukan pada `legacy_op_payments`, yaitu seluruh 118.244 pembayaran hasil migrasi pada snapshot ini.
