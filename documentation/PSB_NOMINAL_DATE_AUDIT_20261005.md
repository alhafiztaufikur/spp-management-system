# PSB, potongan nominal, dan tanggal Indonesia — 5 Oktober 2026

## Status

Regresi pada database disposable lulus dan penerapan Laragon/db_spp sudah selesai. Aplikasi kembali terbuka, health HTTP 200 ok, dan worker importer berjalan kembali dengan SQL ready. Railway tidak dimigrasikan. Data pribadi, dump, JSON pembanding, PDF dan tangkapan layar disimpan di luar Git: C:\laragon\backups\spp-management-system\payments_cleanup_20261005.

## Aturan aktif

- Pangkal tidak lagi menjadi master, input, komponen transaksi, sisa tagihan, laporan atau struk. Pembayaran lama dipindahkan ke PSB: PSB pembayaran baru = PSB lama + Pangkal lama, tanpa mengganti ID/tanggal/total kas atau alokasi komponen lain.
- Master PSB = maksimum(master PSB lama, seluruh pembayaran PSB hasil konversi). Master Pangkal tidak ditambahkan ke PSB; tunggakan Pangkal terpisah dihapus. Asal PSB, cakupan SPP PSB, kelas, penempatan dan status siswa tidak berubah.
- Siswa reguler yang memiliki PSB hasil konversi tetap dapat dibaca/diedit/dicetak; tidak mendapat asal PSB atau pembebasan SPP otomatis. PSB pada master tidak boleh diperkecil di bawah pembayaran yang ada.
- Potongan SPP adalah rupiah per bulan, bukan persen. Tarif efektif maksimum(tarif dasar − nominal potongan, 0). Nominal input baru harus nonnegatif dan tidak melebihi tarif dasar; tanpa master, hanya nol diterima. Nominal existing tetap bertahan bila master turun.
- Snapshot menyimpan potongan yang ditetapkan dan potongan aktual secara terpisah, agar tarif turun/naik tidak menghilangkan nominal yang tetap. Tagihan beralokasi/berbayar dan riwayat tahun asal terlindungi.
- DIKNAS opsional; kosong menjadi NULL, jika diisi wajib 10 digit/unik per unit dan nol awal dipertahankan. Field yang tidak dikirim saat edit tidak menghapus nomor existing.
- Tampilan tanggal DD/MM/YYYY; timestamp DD/MM/YYYY HH:mm:ss WIB. Kontrol tanggal menyediakan input Indonesia dan kalender, dengan nilai ISO tetap dikirim ke server. Database, sortir dan periode tetap ISO; zona tampilan Asia/Jakarta tidak mengikuti zona browser.
- Audit JSON historis dan raw sumber Legacy tidak ditulis ulang. Nilai sebelum/sesudah konversi disimpan berdasarkan migration/entity/ID/unit pada tabel audit migrasi. Kiriman Pangkal/persen lama ditolak, bukan diinterpretasikan sebagai nominal. Guard Pangkal dijalankan sebelum normalisasi/pembuatan pengajuan kasir; field lama bernilai nol/array sekalipun tidak boleh tersaring lalu masuk antrean. Kontrak payload baru PSB diuji terpisah tanpa penulisan data.

## Bukti pengujian

| Pemeriksaan | Bukti |
|---|---|
| Konversi clone | Seluruh baris original dibandingkan berdasarkan ID/unit; hanya perubahan PSB, penghapusan kolom Pangkal/persen, dan penambahan snapshot nominal yang disepakati |
| Kas dan Tabungan | Baseline 1.359 siswa, 1.020 pembayaran/Rp577.790.000, 12 rekening/Rp1.050.000; jumlah/total pembayaran dan seluruh fingerprint Tabungan tetap |
| Migrasi | Dump hashed/restore; persen nonzero dikonversi dengan master terbukti; tanpa master ditolak sebelum DDL; rollback baris kedua; checkpoint sesudah commit data; recovery DDL; rerun tanpa perubahan |
| Siklus | Reguler dan PSB SD/SMP/SMA sampai kelulusan; tiga identitas dari backup nyata diaktifkan manual lalu menjalani siklus; histori tahun asal/tujuan dan laporan cocok |
| Nominal/DIKNAS | Tiga unit: kosong/valid/duplikat/nol awal DIKNAS; potongan sebagian/penuh/invalid; tarif naik/turun/naik; potongan ditetapkan bertahan; snapshot berbayar tetap |
| Keuangan | Input/edit/hapus, otorisasi kasir, replay/CSRF/rollback, race pembayaran/Tabungan/Biaya Lain serta SPP/Komite/DU; pembayaran nol ditolak |
| Identitas | NIS bernol awal yang sama pada tiga unit; penerimaan, saldo, ID filter, struk, buku, Excel/PDF tidak tertukar |
| Laporan | Sepuluh template gabungan, sumber/layar/Excel/PDF biner; konversi struk tiga unit diperiksa pada HTML, ekstraksi teks PDF dan render |
| Akses | 307 request role/unit/anonim/CSRF serta perubahan role/keaktifan/unit sesi; fingerprint tidak berubah selama pemeriksaan akses |
| UI | 96 keadaan empat cakupan × dua tema × tiga viewport × empat halaman; 39 navigasi tambahan; preview nominal setelah memilih rombel dan tanggal histori nyata pada ponsel tiga unit |
| Tanggal | Kalender ketat, tahun kabisat, tanggal ambigu, ISO UTC/offset ke WIB, pergantian hari; browser memakai zona Amerika/Los_Angeles |
| Installer | Instalasi baru/multiunit, master SMP/SMA, seed pembayaran langsung, ulang tanpa duplikasi; 13 transaksi demo per SMP/SMA setelah komponen Pangkal dihapus |
| Integritas | 30 invariant bersih; 61 FK canonical lengkap/cocok; PHP/JS lint dan parser CSS; stylesheet bersama tetap sesuai acuan |

Bukti kegagalan fixture dipisahkan: percobaan awal memakai timestamp update otomatis sebelum migrator diperbaiki untuk mempertahankannya; fixture periode 2038 ditolak batas pembayaran 10 tahun, kemudian menggunakan 2034; fixture lama masih mengirim Pangkal nol/aktor SD pada unit lain dan diperbarui sesuai kontrak. Pemeriksaan gambar menemukan tanggal histori diproses dua kali; rendering diperbaiki dan diuji ulang. Pengujian tema memakai key spp_theme yang benar dan mengassert tema aktual.

Definisi SQL mutatif lama yang merekonstruksi Pangkal/persen dipensiunkan dengan SIGNAL. Gunakan migrasi CLI terbaru dan seeder multiunit yang telah diuji; jangan menjalankan payload historis pada skema baru. Tidak ada reset data trial pada pekerjaan ini.

## Runbook penerapan dan pemulihan

1. Pastikan target db_spp lokal, bukan service Railway. Hentikan hanya worker importer yang PID/waktu/command/app/user sesuai manifest; pasang tmp/financial_migration.lock.
2. Dump baru di luar repository, termasuk routines/triggers, hash SHA-256, restore ke clone milik audit. Baseline diambil ulang setelah maintenance; nilai per ID dan fingerprint diperiksa.
3. Migrator sql/migrate_financial_components.php default hanya preflight. Apply menggunakan gate CLI existing: SPP_ALLOW_MAIN_MIGRATION=1, --apply --confirm-main=db_spp --backup-file=<dump baru>. Worker heartbeat harus berhenti; advisory lock mencegah migrasi serentak.
4. DDL dipisahkan dari transaksi konversi. Journal merekam prepared → data_done → complete. Jangan memperlakukan DDL sebagai rollback transaksi.
5. Jika gagal, pertahankan maintenance/worker berhenti. Recovery hanya pada checkpoint yang dikenali; untuk skema tak dikenal, pulihkan dump teruji dan verifikasi sebelum membuka aplikasi.
6. Setelah perbandingan original, health, 30 integritas dan FK lulus, buka maintenance dan mulai worker memakai runbook Legacy. Tidak ada SQL manual pengguna.
7. Simpan bukti privat dan backup. Hentikan/hapus hanya server/clone milik audit; layanan Laragon lainnya dan storage importer dipertahankan.

## Hasil penerapan lokal

- Kode implementasi `2bb074b` dan koreksi fixture `ba8f5f7` dipindahkan ke main tanpa konflik; origin/main terbaru masih `dd4b3c9` sebelum pengiriman. Perubahan lokal Railway dan catatan DBeaver milik pengguna dipertahankan.
- Backup final: `C:\laragon\backups\spp-management-system\payments_cleanup_20261005\preapply-final.sql`, SHA-256 `3BE3AB31E1ADBF1E8A486D7E7B0A936B94438F9102F52CFA41C1D5497D860D56`. Restore dan konversi clone final baru lulus sebelum main diubah.
- Journal `psb_nominal_20261005` complete pada 05/10/2026 13:51:27 WIB. Arsip before/after: 1.020 pembayaran, 1.359 siswa, dan 2.520 tagihan SPP. Kolom operasional Pangkal/persen tersisa nol; snapshot/audit JSON historis tetap ada.
- Main tetap **1.359 siswa, 1.020 pembayaran/Rp577.790.000**, Tabungan **12 rekening/Rp1.050.000**, penempatan **210**. Total PSB kini **Rp49.650.000 = Rp19.950.000 PSB lama + Rp29.700.000 Pangkal lama**, bukan penerimaan tambahan.
- Seluruh tabel original main cocok dengan clone final terverifikasi. Hanya tiga tabel original berubah sesuai konversi (`siswa_data`, `bayar_data`, `tagihan_spp_data`); semua tabel original lain, termasuk pembayaran/alokasi komponen lain dan tiga tabel Tabungan, memiliki fingerprint sebelum/sesudah identik. Semua ID/tanggal/nominal selain konversi disepakati dipertahankan.
- Health HTTP 200 ok, 30 invariant nol, 61 FK canonical lengkap/cocok. Worker sebelumnya sudah berhenti; startup helper menghidupkannya kembali pada instance/storage importer yang sama dan heartbeat SQL ready terverifikasi.
- Script DBeaver lokal diselaraskan dengan skema baru: tujuh kontrak Legacy + 30 invariant, seluruh 37 pemeriksaan OK. Catatan khusus Railway milik pengguna tetap ada. Tidak ada langkah SQL manual yang diperlukan untuk penerapan ini.
- Kesiapan deployment Railway dan impor histori keuangan Legacy tidak termasuk kesimpulan ini. Backup dan artefak privat dipertahankan. Tambahan 48 keadaan UI utama baca saja (empat cakupan/dua tema/tiga viewport) lulus; fingerprint seluruh tabel tetap identik setelah GET/UI dan restart worker. Dua server HTTP latihan dan tiga clone bernama khusus audit dihapus/dihentikan setelah kepemilikan diperiksa; sesi baca audit sendiri dihancurkan. Laragon, worker dan storage importer utama tetap tersedia. Tidak ada penolakan cleanup pada tahap penutupan.

