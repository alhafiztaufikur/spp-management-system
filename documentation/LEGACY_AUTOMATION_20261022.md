# Otomatisasi migrasi Legacy untuk pekerjaan baru

Aturan operasional versi 11 memperkenalkan `automation_version: 1` pada pekerjaan
migrasi otomatis yang baru disiapkan. Konfigurasi pekerjaan lama tidak dinaikkan
versinya otomatis. Pembaruan ini tidak menjalankan impor, pemasangan skema,
perbaikan data lama, atau pembaruan saldo pada database aktif.

## Perilaku yang berubah

- `bayar` menjadi sumber kejadian pembayaran. `bayar_du` disimpan sebagai rekap
  terpisah, bukan penerimaan tambahan. Rekonsiliasi memakai identitas, backup,
  tahun ajaran, kelas daftar ulang, dan jumlah `U_KEGIATAN`. Satu nilai rekap yang
  berulang tidak dijumlahkan menjadi pembayaran baru. Rekap yang belum cocok
  tetap tersedia sebagai referensi tanpa menetapkan kelunasan atau tunggakan.
- Kuitansi campuran tetap satu. Metadata rincian menyimpan periode SPP dan tahun
  ajaran DU terpisah. Pembaca kredit DU, pengikatan kewajiban, dan koreksi Legacy
  mempertahankan periode tersebut. Pembayaran tanpa metadata tetap memakai
  perilaku lama; format cetak kuitansi tidak diubah.
- Rincian `LAIN_LAIN1..4` / `JUMLAH1..4` dihubungkan otomatis dengan nama master
  sumber. Master tujuan yang sesuai digunakan kembali. Master baru tetap
  nonaktif dan bertarif belum ditetapkan; nilai pembayaran bukan tarif baru.
- Kode bermasalah diperiksa melalui keterangan eksplisit dan kuitansi pembanding
  dengan konteks lengkap yang sama. Inferensi pola memerlukan satu kandidat,
  nominal tepat sama, jarak kode maksimal satu karakter, minimal tiga siswa
  pembanding, tingkat dan tahun ajaran sama, serta jarak tanggal maksimal 45 hari.
  Kandidat pesaing membatalkan inferensi. Kode kosong tidak ditebak dari nominal.
- Nama “Lain Test” dan master gabungan dua nama tidak dibuat otomatis oleh aturan
  baru. Jenis yang belum diketahui tetap histori dengan nominal aslinya.
- Rekening utama menjadi pilihan default. Catatan tambahan tidak menggantikan
  saldo utama hanya karena jurnal tambahannya konsisten. Salinan identik dan
  snapshot jurnal yang lebih awal tidak dihitung lagi. Rekening tambahan yang
  tunggal dan konsisten dipromosikan hanya jika rekening utama tidak tersedia.
- Selisih saldo header utama dan jurnal mengikuti kebijakan saldo akhir sumber
  yang sudah ada, dengan penyesuaian migrasi dan alasan yang terlihat. Ini tidak
  membuktikan adanya setoran/penarikan yang hilang. Toleransi residu presisi dan
  keputusan saldo yang telah disahkan tetap dipertahankan.

Data sejak 1 Januari 2020, angkatan mulai 2020/2021, dan siswa aktif diberi
prioritas. Keterbatasan data lama ditampung per catatan agar data lain yang siap
tetap dapat dipindahkan. Saldo selalu dihitung dari seluruh riwayat, termasuk
jurnal sebelum 2020. Tanggal, pemilik, tarif dan kewajiban tidak direka.

## Bukti dan batas kompatibilitas

Setiap rincian baru menyimpan metadata `migration`: versi, periode, kode asal,
kunci baris sumber, status (`direct`, `inferred`, `unresolved`), aturan, dan
prioritas. Metadata ikut dilindungi fingerprint publikasi. Bukti sumber mentah
tetap utuh. Pemetaan manual yang eksplisit tidak diganti pemetaan biaya otomatis.

Ringkasan migrasi menampilkan pencocokan langsung, inferensi, jenis belum
diketahui, rekap DU, dan sumber saldo. Rekap DU dapat ditelusuri melalui daftar
referensi sebelum maupun setelah publikasi, termasuk setelah arsip dipulihkan.
Catatan tambahan yang tidak valid tetap dicatat sebagai pengecualian, tanpa
mengganti rekening utama yang valid. Koreksi pemilik atau klasifikasi yang
diizinkan tetap mempertahankan metadata asal; metadata dari formulir diabaikan.

Perubahan di luar importer hanya kompatibilitas pembacaan dan pelestarian
metadata hasil migrasi baru. Halaman Master Biaya Lain, penghitung pemakaiannya,
transaksi operasional baru, otorisasi, tata letak kuitansi, dan skema tidak diubah.
Worker yang masih berjalan dengan kode lama perlu dimulai ulang sebelum
menggunakan aturan baru. Tidak ada restart worker aktif dilakukan oleh pengujian.

## Pengujian

Pengujian murni tidak membuka koneksi database:

```powershell
php tests/legacy_migration_automation_test.php
php tests/legacy_operational_resolution_test.php
php tests/legacy_operational_bulk_test.php
```

Audit sumber mengambil snapshot SQL dengan transaksi **READ ONLY**, tanpa
memuat bootstrap aplikasi, membuat draf, atau menerapkan migrasi:

```powershell
php -d memory_limit=768M tests/legacy_migration_automation_sources_test.php --archive-readonly --database=NAMA_DATABASE_ARSIP
```

Hasil pada tiga arsip 2026 yang diperiksa: 108.956 pembayaran asal terbawa satu
kali; 24.225 rincian berkode unik dan 13 rincian inferensi, sedangkan 59 rincian
tetap belum teridentifikasi. Tiga saldo utama SD yang sebelumnya dipilih dari
catatan tambahan tetap memakai Rp0, Rp90.000, dan Rp135.000. Selisih sumber
Rp20.000 pada rekening angkatan 2025 tetap dicatat sebagai penyesuaian terbuka.
Jumlah pencocokan ini bukan persentase kebenaran seluruh data sumber.

`tests/legacy_migration_automation_transactions_test.php` menolak port 3306 dan
mewajibkan UUID server uji. Jalankan dengan environment database audit pada
instance terisolasi. Tes memeriksa pembaca PHP/SQL, metadata koreksi, pengikatan
kewajiban DU campuran, master nonaktif, dan rollback seluruh fixture transaksi.
Opsi `--draft` menguji antrean, persiapan, persetujuan massal, simulasi, dan
konfirmasi sebenarnya pada penyimpanan importer privat, tanpa menjalankan apply.
Opsi tambahan `--publication` menerapkan fixture hanya pada instance uji yang
UUID-nya telah diperiksa, dengan konfigurasi backup privat yang menunjuk target
uji yang sama. Pengujian ini memeriksa rollback sebelum commit, publikasi hasil,
metadata tersimpan, penelusuran rekap setelah publikasi, dan pengulangan tanpa
duplikasi maupun perubahan fingerprint. Gunakan database uji kosong; hasil
fixture yang terbit tetap berada di database uji tersebut.
