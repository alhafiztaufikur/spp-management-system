# Menyiapkan skema Legacy melalui terminal

Skema adalah struktur penyimpanan untuk Migrasi Operasional Legacy: kelompok dan Alumni, rekening, pembayaran, jurnal, dasar tarif, data tertahan, serta audit. Pembaruan terbaru juga mendukung master biaya nonaktif dengan tarif belum ditetapkan dan penyesuaian saldo FIX.

Pemasangan skema **tidak mengimpor backup Legacy**, tidak mengosongkan database, dan tidak membuat database baru. Kesiapan skema berbeda dari kesiapan migrasi: unit tujuan Migrasi Penuh tetap harus kosong dari siswa dan transaksi.

## 1. Periksa terlebih dahulu

Buka PowerShell, lalu jalankan:

```powershell
Set-Location 'C:\laragon\www\spp-management-system'
$sppPhp = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $sppPhp scripts/migrate_legacy_operational.php --check --expect-db=db_spp
```

Perintah ini hanya membaca kesiapan. Pastikan nama **Database tujuan** benar. Bila belum lengkap, lanjutkan pemasangan di bawah pada waktu pemeliharaan. `--expect-db` hanya mencocokkan nama database; tidak mengganti koneksi. Koneksi tetap mengikuti konfigurasi aplikasi/variabel `SPP_DB_*`. Jangan memasukkan password ke perintah atau Git.

Untuk melihat bantuan tanpa perlu koneksi database:

```powershell
& $sppPhp scripts/migrate_legacy_operational.php --help
```

## 2. Pasang pada database tujuan yang sudah ditentukan

1. Buat backup database tujuan melalui layanan backup dan pastikan statusnya **Terverifikasi**. Simpan juga backup sumber Legacy. Jangan mulai pemasangan bila backup gagal.
2. Hentikan worker importer dan proses CLI lain yang menulis ke database tersebut; pastikan tidak ada impor, restore, atau transaksi yang sedang berjalan. Gunakan source terbaru untuk aplikasi dan worker.
3. Untuk instalasi utama `db_spp`, aktifkan pemeliharaan dengan penanda berikut. Jika penanda sudah ada, selesaikan pemeliharaan sebelumnya terlebih dahulu; jangan menimpanya.

   ```powershell
   if (Test-Path -LiteralPath '.\tmp\financial_migration.lock') {
       throw 'Penanda pemeliharaan sudah ada. Periksa pekerjaan sebelumnya.'
   }
   New-Item -ItemType File -Path '.\tmp\financial_migration.lock' -ErrorAction Stop
   ```

4. Jalankan pemasangan:

   ```powershell
   & $sppPhp scripts/migrate_legacy_operational.php --apply --confirm-maintenance --expect-db=db_spp
   if ($LASTEXITCODE -ne 0) { throw 'Pemasangan gagal. Pertahankan pemeliharaan dan periksa pesan kegagalan.' }
   & $sppPhp scripts/migrate_legacy_operational.php --check --expect-db=db_spp
   ```

5. Pastikan hasil pemeriksaan menyatakan **Migrasi operasional siap**. Periksa layanan aplikasi dan mulai ulang worker importer sesuai [runbook](LEGACY_IMPORT_RUNBOOK_20261003.md). Sesudah semuanya siap, hapus hanya penanda yang dibuat pada langkah 3:

   ```powershell
   Remove-Item -LiteralPath '.\tmp\financial_migration.lock'
   ```

6. Buka kembali halaman Import Legacy. Periksa ulang/simulasikan draf lama dengan aturan terbaru sebelum konfirmasi penerapan.

Langkah di atas khusus konfigurasi utama `db_spp`. Untuk server atau database uji lain, administrator harus menentukan konfigurasi koneksi dan menghentikan akses ke instalasi tujuan tersebut terlebih dahulu. Penanda utama tidak menghentikan aplikasi di folder Copy. Panduan ini tidak menyalin source atau memasang skema ke Copy.

## Jika pemasangan gagal

- **Database tidak sesuai:** periksa konfigurasi koneksi. Jangan mengganti nama target hanya untuk melewati pemeriksaan.
- **Pemeliharaan diperlukan:** pastikan backup sudah terverifikasi, worker berhenti, serta penanda dan `--confirm-maintenance` tersedia.
- **Pemasangan lain sedang berjalan:** tunggu proses sebelumnya selesai, lalu periksa ulang.
- **ALTER/constraint/izin gagal:** simpan pesan kegagalan dan pertahankan pemeliharaan. DDL MySQL tidak dibatalkan seluruhnya sebagai satu transaksi. Setelah penyebab diperbaiki, installer dapat dijalankan ulang untuk melengkapi skema. Bila harus memulihkan, gunakan prosedur pemulihan administratif dari backup terverifikasi.

## Status persiapan 19 Oktober 2026

Skrip dan petunjuk tersedia pada source utama. Bantuan, validasi opsi, serta pemeriksaan target diuji. Persiapan ini belum menjalankan `--apply` pada database utama atau Copy; data pengguna dan paket deployment dipertahankan. Uji pemasangan berulang pada instance terisolasi sebelumnya dicatat dalam [hasil QA](LEGACY_REVIEW_QA_20261019.md).

Status lanjutan: skema terbaru sudah dipasang khusus **lab copy / db_spp_test2** pada pembaruan lab; database utama tetap belum dipasangi skema operasional. Lihat [panduan lab](LAB_COPY.md) dan [persetujuan massal](LEGACY_BULK_REVIEW_20261019.md). Persetujuan massal versi 5 tidak memerlukan perubahan skema tambahan.
