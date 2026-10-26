# Penggabungan perubahan Global, DU dan pembaruan rekan

Penggabungan 26 Oktober 2026 mempertahankan kedua garis perubahan melalui
merge commit, tanpa menulis ulang riwayat remote.

## Cakupan yang dipertahankan

Perubahan lokal pada `6b802f1` memuat pelacakan pembayaran Legacy di Global,
proyeksi Riwayat Tagihan, kesesuaian PDF/Excel, serta layanan Master DU dan
rekonstruksi DU yang sudah tersedia di workspace.

Tiga commit remote ikut dipertahankan:

- `74d34c9`: tanggal kas manual dan identitas siswa bernama sama.
- `cb656ea`: tarif SPP khusus per siswa, penerbitan langsung, pemilih DU,
  serta ringkasan transaksi Master Biaya.
- `d800d15`: penyempurnaan tampilan Laporan Global pada desktop.

Merge otomatis tidak menghasilkan konflik. Pemeriksaan identitas isi file
memastikan 44 file lokal dan 37 file remote yang tidak bertumpang tindih
dipertahankan persis pada tahap penggabungan. Satu baris kosong dengan spasi
pada pengujian browser remote kemudian dirapikan untuk pemeriksaan whitespace.

Dua file bersama diperiksa terhadap kedua versi:

- `includes/spp_billing.php`: penerbitan SPP bersama, tarif khusus per periode,
  dan perlindungan snapshot milik rekan tetap ada; penyegaran cache/fingerprint
  Legacy sesudah koreksi tarif kelas dari perubahan lokal juga tetap ada.
- `includes/legacy_billing_reconstruction.php`: rekonstruksi memakai tarif
  khusus milik rekan; perbaikan fingerprint dan rekonstruksi pembayaran DU
  dari perubahan lokal tetap dijalankan melalui engine yang sama.

Konfigurasi `koneksi.php` pada commit mengikuti remote. Salinan konfigurasi
mesin pengguna dipertahankan secara lokal dan tidak diikutkan dalam commit.
`.gitignore` dipertahankan. Cache Python hasil uji tidak dimasukkan ke Git.

## Verifikasi hasil gabungan

Pengujian mutasi memakai MySQL salinan port 58470, UUID
`beedfd77-d0f5-11f1-b1ea-0a0027000013`, datadir berbeda dari operasional.
Backup SHA-256
`ea1e0ad46a4af07e2bca9bafe630924cf4a3d3e11437c1ed72121b70f17ae2fb`
dipulihkan ulang; checksum seluruh 62 tabel cocok sebelum fixture.
Skema dan fixture tarif khusus hanya dipasang pada salinan pengujian.

Pemeriksaan sintaks 65 file PHP/JavaScript lulus. Pengujian murni tarif khusus
23, tanggal transaksi 28 dan planner DU 45 lulus, beserta pengelompokan
Riwayat Tagihan. Hasil gabungan yang sudah selesai:

| Suite | Pemeriksaan lulus |
| --- | --- |
| Model tarif SPP khusus, tiga unit | 77 |
| Penerapan tarif dan penerbitan satu tindakan | 42 |
| Tarif khusus dan ringkasan Master Biaya melalui browser/HTTP | 606 |
| Penerbitan langsung melalui dialog pada desktop | 166 |
| Tarif khusus dengan pembayaran, laporan, struk dan rekonstruksi Legacy | 11 |
| Pelacakan biaya Legacy, termasuk Aqila | 45 |
| Pembanding slot/nominal biaya dengan sumber independen | 122 |
| Layanan Master DU, Legacy dan native | 639 |
| Integrasi DU | 224 |
| Global pada browser desktop/ponsel, terang/gelap | 25 |
| Global pada layar dan PDF/XLSX asli | 169 |
| Pemilih DU pada Input/Edit Pembayaran | 161 |

Uji layanan DU awal mengharapkan hanya 83 siswa Legacy SD. Fixture tarif SPP
juga menambahkan empat siswa native aktif; penerbitan yang benar menghasilkan
87 tagihan. Pengujian diperluas untuk menghitung dan memeriksa tagihan native
tambahan pada tiap unit, tanpa mengubah logika aplikasi. Semua fixture transaksi
layanan ini di-rollback. Pengujian pemilih DU juga diperbaiki untuk menunggu
rincian pembayaran lazy selesai sebelum mengganti daftar dengan data DOM
sintetis; respons yang terlambat sebelumnya menimpa data simulasi tersebut.

Suite Global menyeluruh 3.142 pemeriksaan dan 36 pasangan ekspor yang tercatat
pada audit sebelumnya tidak diulang seluruhnya saat merge. Pengujian ulang
difokuskan pada dua file bersama, fitur tarif SPP, DU, Global dan ekspor yang
terkait. Tidak ada klaim bahwa semua kemungkinan alur aplikasi sudah diuji.

Snapshot seluruh perubahan sebelum merge, Git bundle, patch, identitas server,
dan hasil pengujian disimpan privat di luar webroot pada
`C:/laragon/backups/spp-management-system/repo_merge_20261026_143346`.

Pekerjaan penggabungan/push ini tidak menerapkan migrasi atau fixture pada
database operasional. Bukti pengujian Global sebelum merge ada pada
[audit pelacakan Global](GLOBAL_LEGACY_FEE_TRACKING_20261026.md); rincian fitur
rekan ada pada [tarif khusus SPP](STUDENT_SPP_TARIFF_20261026.md) dan
[tanggal transaksi](TRANSACTION_ENTRY_FIX_20261026.md).
