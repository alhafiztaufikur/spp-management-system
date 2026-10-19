# Penyatuan Kelas Legacy dan Master Kelas

Pada Master Kelas, kartu **Tambah Rombel** tetap tersedia. **Tambah Kelas Khusus** kini berada dalam kolom aksi yang sama; tombol putih Tambah Rombel yang berulang dihapus.

## Memakai daftar kelas

**Daftar Rombel** memuat kelas akademik dan PSB. Bagian **Kelas Khusus** memuat Alumni, siswa keluar (OUT), kelas custom, serta kelompok kegiatan/rekening yang sudah dihubungkan dari Legacy. Gunakan pencarian nama/kode sumber, jenis, status, dan pagination 25 kelas. Tabel menjadi kartu pada ponsel.

- **Alumni2025** adalah kelas operasional untuk kode sumber seperti `A2025`. `A2024` dan `B2024` dapat menunjuk satu **Alumni2024**. Pasangan tahun `A2122` tetap berarti Alumni2022; awalan NIS tidak dipakai menentukan kelulusan.
- **Asal Legacy** menyimpan kode sumber sebagai informasi penelusuran. Nama utama dan tautan kelas memakai master tujuan. Arsip dan snapshot transaksi tidak diganti.
- **Lihat Anggota** mencakup siswa serta pemilik rekening non-siswa, dibedakan jenisnya. Anggota tidak dihitung kembali karena mempunyai hubungan siswa dan Legacy sekaligus. Anggota OUT yang belum mempunyai penempatan master dibaca dari hubungan kelompok sumbernya.
- **Rekening & Transaksi** membuka seluruh kelompok sumber/batch yang terkait kelas tersebut. Filter dan halaman kelas dibawa ketika kembali.
- Kelas custom dapat diedit/nonaktif melalui mekanisme existing; Alumni dan OUT tetap dikelola sistem. Hubungan Legacy mencegah penghapusan kelas yang telah digunakan.
- Kelompok sintetis **Pemilik Legacy tanpa master siswa** hanya berada pada penelusuran sumber. Identitas tersebut tidak diubah menjadi siswa, NIS, atau kelas palsu. Rekening Uji tetap ditampung sesuai keputusan migrasi existing.

**Kelompok dari Legacy** tertutup saat halaman dibuka. Pilih **Telusuri Kelompok Legacy** untuk memuat daftar; pilih **Tutup Penelusuran Legacy** untuk kembali ke kondisi tanpa pemuatan daftar. GET, pencarian, pagination, dan navigasi kembali tetap berfungsi tanpa JavaScript. Tautan lama dengan filter kelompok tetap dapat dibuka.

## Migrasi berikutnya dan perbaikan data existing

Aturan operasional versi **8** mempertahankan kelas Alumni kanonis dan menghubungkan kelompok rekening/kegiatan ke master custom dalam transaksi penerapan existing. Master yang sudah terkait kelompok tetap dipakai, termasuk setelah namanya diperbaiki. Tarif, pembayaran, rekening, jurnal, serta bukti sumber tidak diubah oleh penyatuan kelas. Tidak ada penerbitan SPP atau kenaikan otomatis untuk kelas nonakademik.

Draf dan saldo FIX tetap tersimpan. Simulasi/konfirmasi dengan versi aturan lama perlu diperbarui sebelum penerapan; batch yang sudah selesai tidak diimpor ulang.

Untuk instalasi yang sudah memigrasikan data, CLI berikut memeriksa perubahan tanpa menulis data:

```powershell
php scripts/repair_legacy_classes.php --unit=1
```

Penerapan pada lab memerlukan backup SQL lengkap di luar folder web, database tujuan yang persis, dan ID Super Admin aktif:

```powershell
php scripts/repair_legacy_classes.php --apply --unit=1 --confirm-target=db_spp_test2 --actor=ID_SUPER_ADMIN --backup-file="C:\laragon\backups\lab-before.sql"
```

Pilih koneksi lab yang benar sebelum menjalankan. Opsi unit memakai 1=SD, 2=SMP, 3=SMA; tanpa opsi unit, ketiganya diperiksa. Penerapan dilakukan atomik per unit dan dicatat pada audit kelas. Perintah menolak penerapan ke database utama `db_spp`. CLI ini tidak memasang skema atau menjalankan migrasi akun/workflow; dependensi Kelas Khusus/Legacy harus siap terlebih dahulu. Backup harus diuji pemulihannya tersendiri, karena pemeriksaan file pada CLI bukan bukti pemulihan.

Hanya alumni yang penempatannya tidak sesuai dan masih memenuhi kondisi arsip yang diperbaiki. Penempatan akademik atau aktivasi yang dilakukan kemudian tidak dibatalkan. Keputusan tahun lulus pada manifest yang telah disahkan tetap didahulukan. Bila keputusan antar-batch bertentangan, perbaikan unit dibatalkan untuk diperiksa. Penerapan ulang setelah berhasil tidak membuat kelas, anggota, atau audit ganda.

## Hasil pemasangan 19 Oktober 2026

- Source utama dan patch terkait di lab copy diperbarui. Tidak menjalankan migrasi final ulang, commit/push, atau memperbarui deployment.
- **267 alumni lab sudah benar**, sehingga tidak ada siswa dipindahkan ulang. Kode kelompok sumber tetap tersimpan, dengan nama kelas Alumni sebagai judul utama.
- Siapkan **10 master nonakademik**, hubungkan **10 kelompok existing**, serta tambahkan **10 catatan audit**. Termasuk Guru & Karyawan (125 pemilik), BTQ, TABGJ, OUT, dan Rekening Uji. Tidak menambah siswa, pemilik rekening, transaksi, atau jurnal.
- Hanya tiga tabel lab berubah: master kelas, hubungan kelompok Legacy ke master, dan audit. Baris master/audit existing tidak diubah; pada kelompok existing hanya `master_kelas_id` yang berubah. Seluruh tabel siswa, transaksi, saldo, tagihan, manifest batch, dan histori identik. Seluruh **50 tabel utama** tetap identik.
- Backup sebelum pemasangan dipulihkan pada instance terisolasi dengan fingerprint **59 tabel** identik. Delapan file konfigurasi/runtime yang dilindungi serta dua manifest draf/sumber yang diperiksa tetap identik. Worker lab dimuat ulang pada aturan versi 8 tanpa menjalankan antrean migrasi final.
- Uji model/HTTP: pemeriksaan tanpa mutasi, pemakaian ulang master existing dengan ejaan berbeda, alumni salah penempatan, penggabungan tahun sama, OUT/PSB, anggota non-siswa, perlindungan hapus, rollback, retry, dan pembatasan pemilik menurut kelas.
- Fixture penerapan penuh terisolasi lulus: dua kode alumni menuju satu master, master rekening custom, rollback sebelum commit, retry, saldo FIX, penerimaan kas, serta empat backup terverifikasi. Enam sumber SD/SMP/SMA 2019/2026 diperiksa kembali; jumlah pembayaran/nominal dan bukti sumber tetap konsisten.
- Penelusuran SQL pada instance terisolasi membuktikan **0 kueri daftar kelompok Legacy saat tertutup**, dan 2 kueri daftar/penghitungan saat dibuka.
- 24 kasus Chrome 155/Chromium 109.0.5412.0: desktop/tablet/ponsel, terang/gelap, JavaScript/tanpa JavaScript, filter alumni, pagination anggota, buka/tutup sumber, serta navigasi kembali. Pemeriksaan baca tambahan pada lab tidak membuat data contoh.
- Pengujian fisik Windows 7 tidak dilakukan. Pemeriksaan palet/viewport setara zoom dicatat dalam bukti privat; bukan jaminan seluruh kombinasi zoom native/perangkat.

Bukti privat: `C:\laragon\backups\spp-management-system\class_unification_20261019` (backup, fingerprint, audit perubahan, trace kueri, hasil browser dan screenshot).
