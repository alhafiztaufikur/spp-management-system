# Catatan pembaruan — 13 November 2026

## Surat ke Orang Tua

- Tambah pilihan Kepala Sekolah per unit, lengkap dengan tambah, edit, hapus,
  dan penyimpanan pilihan aktif untuk seluruh pengguna.
- Pilihan Semua Unit menampilkan ringkasan kepala sekolah masing-masing unit.
- Nama sekolah dan kepala sekolah disalin saat draf dibuat. Mengubah pilihan
  tidak mengubah surat yang sudah dibuat; buat draf baru untuk memakai nama baru.
- Nama kepala sekolah tampil pada tanda tangan surat; pratinjau, PDF, dan PNG
  menggunakan data draf yang sama.
- Panel Kepala Sekolah memakai gradasi sesuai unit. Keterangan tambahan yang
  diminta dihapus sudah diringkas, termasuk angka penghitung pada judul halaman
  Surat Laporan dan Laporan Global.

## Tombol dan label desktop

Tombol aktif/nonaktif, pagination, pilihan unit, serta label informasi memakai
sudut 8 px pada layar mulai 1024 px. Cakupan label meliputi NIS, kelas, unit,
status, peran, jumlah data, dan jam. Avatar, logo, indikator kecil, radio,
checkbox, dan bagian mekanis switch tetap mengikuti bentuknya.

## Laporan Global

- Seluruh sepuluh laporan pada halaman desktop menggunakan ringkasan akhir
  **Total Rupiah** berupa tabel dua kolom, rata kanan, maksimal 480 px. Label
  berada di kiri dan nominal tebal di kanan. Total mengikuti seluruh hasil
  filter dan tetap sama saat berpindah halaman.
- PSB dan Status Pembayaran mengganti kartu total desktop dengan tabel tersebut.
  Riwayat Tagihan menampilkan Total Tunggakan Keseluruhan; rekap kas memakai
  total setoran atau total masuk, keluar, dan mutasi bersih yang sudah tersedia.
- Ringkas keterangan total, judul kolom, label bulanan, dan nama komponen biaya
  sesuai laporan. Kolom Total Tagihan, Total Bayar, dan Saldo Berjalan dijaga
  dalam satu baris pada keluaran yang ditentukan.
- Pada laporan transaksi tabungan, operator Legacy menampilkan kode asalnya;
  kolom Keterangan dan Penyesuaian disembunyikan dari presentasi. Data dan
  perhitungan penyesuaian tetap dipertahankan.
- Kartu pemberitahuan filter per item dihapus. Pengamanan ekspor tetap menahan
  filter yang belum diterapkan dan mengarahkan fokus ke Tampilkan Rekap.

Penyamaan seluruh tabel total yang terbaru hanya berlaku pada **desktop**;
mobile, PDF, cetakan, dan Excel mempertahankan format dari pembaruan sebelumnya.
Perapian laporan sebelumnya tetap mengikuti batas keluarannya masing-masing:
lihat [rincian presentasi laporan](GLOBAL_REPORT_PRESENTATION_20261112.md).
Tidak ada perubahan perhitungan tunggakan atau saldo pada penyesuaian tampilan.
Nilai yang belum diketahui tetap tidak dianggap nol/lunas.

## Perbaikan kompatibilitas

- Penjagaan transaksi siswa OUT menggunakan kolasi kolom NIS siswa agar tetap
  cocok ketika kolasi database berbeda.
- Proses restore dan verifikasi backup mempertahankan komentar SQL saat impor.

## Petunjuk setelah mengambil pembaruan

Kode Kepala Sekolah memerlukan skema tambahan pada database existing. Jalankan
installer **preview** dahulu pada target yang benar:

```powershell
php scripts/migrate_parent_letter_principals.php --database=db_spp --port=3306
```

Pemasangan pertama memerlukan backup dengan restore terverifikasi. Ikuti
[panduan pemasangan Kepala Sekolah](PARENT_LETTER_PRINCIPALS.md) untuk mode apply,
bukti backup, dan pemeriksaan replay tanpa perubahan. Jangan menganggap `git pull`
sudah memasang skema database. Instalasi bersih menggunakan alur instalasi yang
sudah diperbarui.

## Pengujian yang sudah dilakukan

- Kepala Sekolah: CRUD, CSRF, konflik perubahan, pemisahan unit, snapshot draf,
  instalasi ulang, serta keluaran PDF/PNG pada server salinan terverifikasi.
- Ringkasan desktop: 240 kombinasi laporan/unit/lebar/tema, 40 perbandingan isi
  tabel, 25 pergantian halaman, 160 perbandingan mobile, dan 40 perbandingan
  media cetak terhadap tampilan sebelumnya lulus.
- Regresi model 28 kombinasi lulus; checksum tujuh tabel siswa/keuangan tetap sama.
- Lima laporan gabungan antarunit berhasil diunduh sebagai PDF (118 halaman A4)
  dan XLSX. Uji laporan kosong, label panjang, nominal besar/negatif/pecahan/nol,
  dan nilai belum diketahui juga lulus.

Rincian pemeriksaan bentuk komponen tersedia pada
[catatan tombol dan label desktop](DESKTOP_COMPONENT_SHAPES_20261112.md).
