# Kepala Sekolah pada surat orang tua

Nama tersimpan per unit dan berlaku untuk seluruh pengguna. Pilihan, tambah,
edit, serta hapus tersedia pada panel Kepala Sekolah di daftar surat. Nama baru
langsung terpilih. Penghapusan nama aktif wajib disertai pengganti; tambahkan
pengganti terlebih dahulu bila hanya ada satu nama. Penghapusan bersifat logis.
Semua Unit menampilkan ringkasan baca saja, menggunakan kepala sekolah masing-masing
siswa ketika membuat draf gabungan.

Nama sekolah dan kepala sekolah disalin ke draf saat draf dibuat. Perubahan daftar
tidak mengubah draf yang sudah terbuka. Pratinjau, PDF, dan PNG memakai draf yang
sama. Draf lama tanpa snapshot mempertahankan kop dan garis tanda tangan lama.

## Pemasangan

Gunakan server salinan dengan UUID, port, dan datadir berbeda serta event scheduler
OFF. Buat dump lengkap dengan routines, triggers, events, dan SHA-256; verifikasi
restore dengan `lmr_snapshot()` dan `lmr_database_checksums()`. Format bukti restore
mengikuti `LEGACY_MIGRATION_RUNBOOK.md`. Installer memeriksa ulang identitas target
dan bukti backup sebelum perubahan pertama; installer tidak merekonsiliasi keuangan.

```powershell
php scripts/migrate_parent_letter_principals.php --database=db_spp --port=3306
php scripts/migrate_parent_letter_principals.php --database=db_spp --port=3306 --mode=apply --backup-proof=C:/private/backup-proof.json
php scripts/migrate_parent_letter_principals.php --database=db_spp --port=3306 --mode=apply
```

Default adalah preview. Replay harus menghasilkan `schema_changes: 0`,
`seeded_units: []`, dan `ready: true`. Seed hanya menginisialisasi unit yang belum
memiliki pengaturan; tidak mengembalikan nama default yang sudah diedit/dihapus.
Alur instalasi baru melalui `sql/migrate_units.php` memasang skema yang sama.
GET halaman/API hanya membaca. Bila skema belum tersedia, daftar siswa tetap
terlihat, tetapi pembuatan surat baru ditahan sampai pemasangan selesai.

## Verifikasi

Jalankan `tests/parent_letter_principals_test.php` pada server salinan dengan
`SPP_TEST_ALLOW_MUTATION=1` dan manifest `SPP_TEST_ISOLATED_INSTANCE` yang valid.
Uji browser memakai `tests/parent_letter_principals_browser_test.js`; sediakan
`SPP_TEST_BASE_URL`, `SPP_TEST_ADMIN_PASSWORD_FILE`, dan `SPP_UI_OUTPUT` privat.
Uji mencakup unit, CRUD, snapshot, replay, konflik, CSRF, serta desktop 1280/1440/1920
pada tema terang dan gelap. Periksa hasil PDF/PNG dan laporan di folder keluaran.

## Hasil verifikasi 12 November 2026

- **Fungsi PASS:** uji layanan dan browser mencakup CRUD, nama duplikat/tidak valid,
  pengganti wajib, nama terakhir, konflik versi, CSRF, dan pemisahan unit. Pilihan
  siswa tetap tercentang setelah operasi; cetak ditahan saat penyimpanan berlangsung.
- **Desktop PASS:** 18 kombinasi unit/lebar/tema diperiksa tanpa overflow horizontal
  atau kesalahan JavaScript. Enam tampilan dialog diperiksa berada di tengah layar;
  Escape menutup dialog. Kontras teks dropdown dan tombol panel minimal 4,5:1.
- **Cetak PASS:** 27 pemeriksaan baca ulang PDF/PNG mencakup kop/nama tiap unit,
  draf lama setelah edit, unduhan identik dengan pratinjau, serta PNG tunggal dan
  ZIP gabungan yang cocok dengan rasterisasi PDF independen. Nama 150 karakter
  tetap terbaca di dalam kertas; surat panjang dapat berlanjut ke halaman berikutnya.
- **Kompatibilitas PASS:** format surat lama dan surat laporan kepala sekolah
  tetap dapat dirender. Instalasi kosong/multiunit memasang struktur dan default
  yang sama, dengan replay tanpa perubahan.
- **Penerapan PASS:** dump lengkap dipulihkan pada server berbeda; snapshot dan
  checksum 67 tabel cocok. Pemasangan di database aplikasi mempertahankan seluruh
  data existing. Replay menghasilkan nol perubahan; baca ulang mengonfirmasi ketiga
  kepala sekolah default dan status aplikasi siap, dengan HTTP health 200.

Bukti backup, hasil installer, laporan verifikasi, serta tangkapan desktop disimpan
di direktori privat `C:/laragon/backups/spp-management-system/parent_letter_principals_20261112/`.
