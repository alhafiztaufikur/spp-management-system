# Konteks Proyek SistemSPP

SistemSPP adalah aplikasi administrasi pembayaran sekolah berbasis PHP, JavaScript, dan MySQL (`mysqli`). Dokumen ini merangkum alur aktif. Untuk rincian teknis, kode dan schema adalah sumber kebenaran; [AI_CHANGELOG.md](./AI_CHANGELOG.md) adalah arsip perubahan, bukan panduan operasional.

## Lingkungan

- Pengembangan lokal saat ini memakai Laragon di `C:\laragon\www\spp-management-system` dan database `db_spp`.
- Konfigurasi koneksi berada di `koneksi.php`. Di Railway, koneksi memakai variabel `SPP_DB_*`; lihat [panduan deployment](./RAILWAY_DEPLOYMENT.md).
- `sql/schema.sql` hanya untuk database baru/kosong dan tidak boleh diimpor ke database berisi data.
- Untuk reset dan pengisian data **demo**, ikuti [DEMO_DATA_RESET.md](./DEMO_DATA_RESET.md). Jangan terapkan reset pada data sekolah sungguhan.

## Alur pembayaran aktif

- Master Siswa menyimpan Pangkal dan PSB sebagai kewajiban sekali bayar. Pembayaran keduanya dapat dicicil sesuai sisa tagihan.
- Master Penerbitan SPP membuat tagihan bulanan Juli–Juni berdasarkan penempatan siswa yang tersimpan. Kasir memilih **Bulan Tagihan SPP & Komite** dan **Tahun Tagihan**. Satu transaksi SPP hanya melunasi satu bulan; tunggakan SPP lebih tua diperiksa dahulu.
- Komite adalah tagihan bulanan dari tarif `siswa.POMG`. Ketika SPP suatu bulan dibayar, Komite bulan yang sama harus sudah lunas atau ikut dilunasi. Komite dapat dibayar sendiri.
- **Tanggal Bayar** mencatat hari uang diterima, bukan periode tagihan. Nominal SPP yang belum cukup untuk satu bulan atau melebihi sisa tagihan dicatat melalui tindakan terpisah **Catat Titipan SPP**. Penggunaan titipan memerlukan konfirmasi dan tidak menambah penerimaan kas baru.
- Daftar Ulang memakai tagihan tahunan. Pembayaran dapat diarahkan ke tunggakan tahun sebelumnya berdasarkan ID tagihan yang dipilih; tahun dan kelas pada pembayaran berasal dari snapshot tagihan.
- Biaya Lain memakai tagihan yang diterbitkan dari master. Tabungan masuk/keluar adalah jurnal terpisah, bukan komponen penerimaan pembayaran sekolah.
- Riwayat kelas memakai `siswa_tahun_ajaran`; laporan dan struk membaca snapshot/tagihan terkait agar perubahan tarif atau kelas berikutnya tidak menulis ulang histori.

## Hak akses

| Aksi | Admin | Kasir | Bendahara |
| --- | :---: | :---: | :---: |
| Input, lihat, cetak pembayaran | Ya | Ya | Tidak |
| Edit/hapus pembayaran | Ya | Tidak | Tidak |
| Kelola Data Siswa, Kelas/Rombel, SPP, Biaya Lain, Daftar Ulang | Ya | Ya | Tidak |
| Kelola akun/role | Ya | Tidak | Tidak |
| Laporan Global | Ya | Ya | Ya |

Guard backend berada di `includes/auth.php`. Hak akses harus diperiksa pada endpoint mutasi, bukan hanya dengan menyembunyikan tombol.

## Lokasi kode utama

- `pembayaran/`: input, histori, edit/hapus, dan struk transaksi.
- `siswa/`: Data Siswa dan riwayat kelas.
- `master_spp.php`, `master_kelas.php`, `master_biaya_lain.php`, `master_daftar_ulang.php`: pengelolaan master.
- `includes/reports.php`, `laporan/`: query, tampilan, cetak/PDF, dan ekspor laporan.
- `sql/schema.sql`, `sql/verify_schema.sql`: schema referensi dan pemeriksaannya.
- `tests/`: pengujian regresi dan integrasi.

Sebelum mengubah data atau menjalankan SQL destruktif, periksa database target, buat backup, dan baca hasil verifikasi. Jangan memasukkan dump data siswa, kredensial, atau secret ke Git.
