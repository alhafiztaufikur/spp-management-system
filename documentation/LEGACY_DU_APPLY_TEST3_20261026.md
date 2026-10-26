# Hasil pelacakan DU db_spp_test3 - 26 Oktober 2026

Sudah diterapkan dan diverifikasi pada MySQL 127.0.0.1:3306, database `db_spp_test3`,
UUID `e6e5c8d8-b81c-11f1-9be5-745d2224b37e`, aktor Super Admin ID 1. Rekonsiliasi memakai
arsip tiga batch completed melalui `scripts/legacy_migration_runner.php`; tidak ada impor ulang kas atau identitas.

Aturan workflow 11, operasional 15, otomasi 4, billing 3, DU 1; perencana bulanan 2 dan pelacakan biaya lain 1.

## Hasil DU 2026/2027

Angka tagihan/pembayaran berikut hanya untuk kewajiban yang terbukti. Nilai yang belum diketahui tidak ditambahkan sebagai nol.

| Unit | Tagihan | Lunas | Bersisa | Belum terbukti | Tagihan bersih | Terbayar | Sisa |
|---|---:|---:|---:|---:|---:|---:|---:|
| SD | 331 | 270 | 61 | 83 | Rp 1.362.165.000 | Rp 1.241.134.700 | Rp 121.030.300 |
| SMP | 198 | 178 | 20 | 103 | Rp 878.805.000 | Rp 831.750.000 | Rp 47.055.000 |
| SMA | 46 | 41 | 5 | 21 | Rp 221.950.000 | Rp 204.150.000 | Rp 17.800.000 |

Dibentuk 9 master dan 575 tagihan siswa, termasuk 489 lunas dan 86 bersisa. Sebanyak 1.015 rincian DU ditelusuri ke transaksi sumber. Tidak ada pembayaran DU tahun berjalan pada 207 posisi yang belum terbukti.

Raisa SMP `252607034`: DU Rp4.310.000, pembayaran Rp4.310.000, sisa Rp0, kelas tetap 8A. Posisi sama pada tagihan siswa, kasir, riwayat, Master DU dan laporan/ekspor.

## Pengecualian otomatis

207 posisi (SD 83, SMP 103, SMA 21) belum memiliki bukti lengkap tarif/kelas/tahun/potongan. Tidak dibuatkan tunggakan dan tidak dinyatakan nol atau lunas. Pengecualian `du_evidence` disimpan otomatis; tidak menunggu pemetaan pengguna dan tidak menghambat siswa lain. Histori lama, siswa lulus serta pengecualian Legacy lain tetap dipertahankan.

## Perubahan yang disengaja dan perlindungan

Tagihan, master, tahun sasaran, hubungan sumber dan audit dilengkapi. Tahun draf diterbitkan melalui fungsi resmi tanpa mewajibkan seluruh kelas lengkap. Pembayaran Legacy tetap pada penyimpanannya; rekap bayar_du hanya menjadi bukti rekonsiliasi.

Checksum berubah hanya pada tabel berikut:
- `daftar_ulang_audit_log_data`
- `daftar_ulang_data`
- `legacy_full_batches_data`
- `legacy_op_audit_data`
- `legacy_op_obligations_data`
- `legacy_op_pending_data`
- `tagihan_daftar_ulang_data`
- `tahun_ajaran_data`

Fingerprint arsip mentah, identitas, seluruh kas, tabungan/rekening dan jurnal sama dengan sebelum apply. Checksum tabel pembayaran native maupun Legacy tetap sama. Perubahan Master Kelas yang terdeteksi sebelum apply ditangkap oleh backup terbaru dan dipertahankan. Tidak ada perubahan skema, menu, tombol atau filter baru.

## Pengujian dan verifikasi

- 21 fixture murni perencana DU; 224 pemeriksaan integrasi pada salinan terisolasi.
- Publikasi sumber baru melalui suite transaksi otomasi: rollback sebelum commit, backup/restore, DU lintas tanggal kas, rekap ganda, jejak biaya, dan replay lulus.
- 31 pemeriksaan browser: Master SD/SMP/SMA, pelengkap tagihan existing, Riwayat DU, Input Pembayaran, Excel serta PDF; filter sumber mempertahankan seluruh kredit sah.
- Seluruh 575 posisi dibandingkan pada kasir, riwayat dan laporan; dua ekspor Excel Raisa memuat tagihan = bayar 4.310.000, sisa 0.
- Regresi SPP bulanan, Komite, biaya lain dan struk lulus; pemeriksaan sintaks 22 file PHP lulus.
- Mode verify target melaporkan 0 perubahan per unit; penerapan ulang sungguhan juga 0 perubahan per unit.
- Setelah replay, checksum seluruh 62 tabel tetap sama dengan backup hasil akhir.

## Sumber dan batch

- SD: batch `73c783908503d46f60b341afcc372fd7`, snapshot `2026-10-17T08:29:15.0000000`, SHA-256 `143a4c95d0cf78bded1087a87a3ffb098cd0691c24c5f53960c2dc8fd11251f7`.
- SMP: batch `07494dfeca22c5ce684e3b4c2fbd6911`, snapshot `2026-10-17T08:37:42.0000000`, SHA-256 `61a115c21dd1f672ecd86b08885618b81e79b52fab4d57ffdf93e787add80b7f`.
- SMA: batch `f1f987593b54aa49ea5c036fa045e8f2`, snapshot `2026-10-17T08:38:35.0000000`, SHA-256 `0b729e56d83fb6cacea11fa826fceedf74021daeebe0ed068f18facdce12aaac`.

## Backup terverifikasi dan laporan privat

- Sebelum penerapan: job `981214989dd16c99a71c230bb198edae`; berkas `C:\laragon\backups\spp-management-system\daily\db_spp_test3\jobs\981214989dd16c99a71c230bb198edae/backup.sql`; SHA-256 `92921a1041e16a2b716872efdc1acc2012b4f48dede534b9f2dbad22a8d50dd4`; UUID restore `93b687cb-d0dd-11f1-9743-745d2224b37e`, port 60180.
- Hasil akhir sebelum replay: job `d4904c2751981b32490587c00cc4dfc4`; berkas `C:\laragon\backups\spp-management-system\daily\db_spp_test3\jobs\d4904c2751981b32490587c00cc4dfc4/backup.sql`; SHA-256 `ea1e0ad46a4af07e2bca9bafe630924cf4a3d3e11437c1ed72121b70f17ae2fb`; UUID restore `0e05b579-d0e0-11f1-8429-745d2224b37e`, port 61632.

Kedua backup dipulihkan pada server terisolasi dengan UUID, port dan datadir berbeda; seluruh checksum tabel dan fingerprint yang dilindungi cocok.

Root artefak privat: `C:/laragon/data/spp-migrations/db_spp_test3/20261026-du`.

Berkas utama: `main-apply-final.json`, `main-verify.json`, `main-replay.json`, `final-main-check.json`, `final-data-report.json`, `du-source-target-traces.json`, `export-evidence.json`, hasil browser/PDF/Excel serta log pengujian. Backup yang verifikasinya terputus dipertahankan sebagai gagal dan tidak dipakai sebagai bukti apply.

Panduan kode dan migrasi berikutnya: [LEGACY_DU_TRACE_20261026.md](LEGACY_DU_TRACE_20261026.md).
