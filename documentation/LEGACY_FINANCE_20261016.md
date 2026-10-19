# Histori keuangan Legacy, saldo awal, dan tunggakan

Status 16 Oktober 2026: fitur dan migrasi disiapkan serta diuji pada salinan terisolasi. **Skema dan data keuangan belum dipasang pada `db_spp` operasional.** Paket `deployment/` tidak diperbarui. Source disinkronkan ke `ff08db4` sebelum pekerjaan; perubahan manual pengguna dari teman tetap dipertahankan.

## Alur pengguna

1. Super Admin memilih SD, SMP, atau SMA di sidebar, lalu membuka **Pengaturan → Backup & Restore → Import Legacy**.
2. Kategori **Siswa** tetap memakai alur sebelumnya. Pilih **Histori Keuangan** untuk mengunggah satu backup `.dat` dari unit yang sama. Worker memeriksa backup, memulihkan pada LocalDB khusus, menjalankan CHECKDB, lalu membaca tabel fisik dengan mode read-only.
3. Periksa jumlah baris, masalah sumber, dan pratinjau. Ketik **IMPOR LEGACY** untuk menyimpan **arsip saja**. Pembayaran, saldo, kas, dan tagihan aktif belum berubah. Tanggal kosong tidak diisi dengan tanggal buatan.
4. Buka **Arsip & Peninjauan Keuangan**. Cari NIS/nama/operator; gunakan filter backup/tabel, rincian data asli, dan CSV bila diperlukan. Semua Unit hanya dapat membaca.
5. Buka **Tinjau** pada siswa. Pasangan otomatis hanya berasal dari manifest impor Siswa pada backup dan unit yang sama. Pencocokan manual harus memilih siswa pada unit tersebut dan menyertakan alasan; periksa nama tujuan setelah disimpan. Konflik yang belum dapat dibuktikan jangan diterapkan.
6. Aktifkan siswa tujuan melalui Data Siswa sebelum penerapan. Pilih tanggal penerapan, isi referensi bukti yayasan, dan sahkan bahwa nominal masih berlaku sekarang. Tanggal snapshot 2019 tetap ditampilkan terpisah.
7. Saldo tabungan dapat dipilih hanya bila rekening unik, jurnal lengkap, arah transaksi benar, nominal tepat sampai dua desimal, dan saldo cocok persis. Rekening tujuan harus belum memiliki saldo, jurnal, atau saldo awal sebelumnya.
8. Untuk tunggakan, isi komponen asli, periode `YYYY-MM` atau `YYYY/YYYY`, nominal yang masih tersisa, bukti baris sumber, serta hasil pemeriksaan terhadap tagihan existing. Pembayaran/cache lama **bukan bukti utang otomatis**. Pilih jenis SPP/Komite/Daftar Ulang hanya jika artinya terbukti; komponen ambigu tetap memakai nama sumber. SPP dan Komite memerlukan bulan; Daftar Ulang memerlukan tahun ajaran berurutan.
9. Klik **Periksa & Konfirmasi**, periksa siswa, tanggal, nominal, dan dampaknya, lalu **Ya, Terapkan**. Konfirmasi berlaku lima menit. Perubahan peninjauan membatalkan konfirmasi lama. Jika koneksi terputus, periksa status sebelum mengulangi; kiriman ulang hasil yang sudah berhasil tidak menerapkan nominal dua kali.

Saldo awal adalah catatan tersendiri: **saldo awal + setoran baru − penarikan baru**. Buku tabungan dan laporan menampilkannya sebagai **Saldo Awal Legacy**, dengan kolom masuk/keluar nol. Saldo awal tidak menjadi penerimaan atau setoran kas pada tanggal migrasi. Sen yang valid tetap dipertahankan; data dengan presisi lebih dari dua desimal ditahan tanpa pembulatan.

Tunggakan diterbitkan sebagai **Tunggakan Legacy · komponen · periode**, menggunakan tagihan Biaya Lain. Kasir membayar/cicil lewat Input Pembayaran biasa. Struk, kepemilikan transaksi, permintaan edit/hapus, dan keputusan Super Admin tetap mengikuti alur existing. Penerimaan baru muncul saat pembayaran baru benar-benar disimpan. SPP/DU existing tidak ditulis ulang.

Master khusus tidak bisa diterbitkan massal, dinonaktifkan, dihapus, atau diubah tarifnya melalui Master Biaya Lain. Nominal master internal hanyalah penanda; UI menampilkan **Per pengesahan**, sedangkan nominal tagihan berasal dari pengesahan per siswa. Kewajiban yang sama tidak dapat diterapkan lagi melalui backup berbeda, termasuk setelah tagihannya dibatalkan.

Pembatalan saldo awal hanya tersedia sebelum ada jurnal lanjutan dan saat saldo cache masih cocok. Pembatalan mempertahankan catatan/audit dan mengembalikan saldo ke nol; tidak menghapus histori. Pembatalan tunggakan mempertahankan tagihan dengan status dibatalkan; adanya pembayaran menahan seluruh pembatalan. Pembayaran yang salah harus melalui otorisasi existing terlebih dahulu.

## Pemasangan oleh administrator, nanti

Pekerjaan ini **tidak menjalankan langkah berikut pada operasional**.

1. Buat dan uji cadangan lengkap terbaru. Catat lokasi SQL dan checksum. Jadwalkan waktu pemeliharaan tanpa transaksi pengguna, serta hentikan worker Legacy yang lama menggunakan identitas prosesnya sendiri.
2. Pasang source terbaru. Pastikan PHP 8.3, MySQL yang didukung aplikasi, ekstensi SQLite/mysqli/mbstring, dan LocalDB importer existing tersedia. Jangan menghapus runtime SQL Server importer.
3. Aktifkan penanda pemeliharaan existing `tmp/financial_migration.lock`. Jalankan CLI dari root proyek memakai koneksi aplikasi yang sudah diverifikasi:

   ```powershell
   & 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe' scripts/migrate_legacy_finance.php --apply --confirm-maintenance
   ```

   Penanda dan argumen konfirmasi wajib untuk operasional. CLI memakai advisory lock, dapat dijalankan ulang, dan hanya memasang struktur. Tidak mengunggah sumber, membuat saldo, atau menerbitkan utang. DDL MySQL tidak atomik; bila gagal, pertahankan pemeliharaan, periksa log, dan ulangi migrasi setelah penyebabnya diperbaiki. Jangan menghapus tabel parsial secara sembarang.

4. Periksa `scripts/migrate_legacy_finance.php` tanpa `--apply`, jalankan health/audit integritas, lalu mulai ulang worker dari source baru melalui `scripts/start_legacy_worker.ps1`. Antrean lama tetap kategori Siswa. Kategori keuangan tidak menerima unggahan bila heartbeat worker belum menyatakan dukungan keuangan; worker versi lama tidak boleh dipakai.
5. Akhiri pemeliharaan setelah pemeriksaan lulus. Periksa kategori baru dan uji satu sumber pada salinan terlebih dahulu. Pengesahan saldo/tunggakan nyata tetap keputusan yayasan per siswa.
6. Buat cadangan terverifikasi baru setelah pemasangan dan setelah penerapan nyata. Cadangan SQL mencakup delapan tabel baru, view, trigger, FK, dan audit. Simpan juga `.dat`, manifest/checkpoint SQLite privat, serta bukti yayasan secara aman di luar webroot/Git.

Jika skema belum terpasang/lengkap, halaman keuangan menyatakan **belum siap**. Tidak ada DDL HTTP; Import Siswa dan modul normal tetap bekerja. Jangan menyalin variabel koneksi QA ke operasional.

## Pemulihan dan diagnosis

- Pulihkan **seluruh database** dari backup yang sudah diverifikasi, mengikuti panduan Backup & Restore existing. Jangan memulihkan tabel saldo atau tagihan secara terpisah, mengisi jurnal kas untuk menutup selisih, atau menonaktifkan FK/trigger agar penerapan lolos.
- Uji pemulihan dahulu pada instance terisolasi memakai nama database asli `db_spp`, karena view/routine dapat merujuk nama tersebut. Pemeriksaan backup existing membaca seluruh tabel fisik, FK/CHECK, health, integritas saldo, serta jumlah/fingerprint arsip baru.
- Setelah pemulihan, sesi dan worker mengikuti generation gate existing. Mulai ulang worker dan tinjau antrean; pekerjaan arsip yang sudah committed dapat diulang tanpa penggandaan. Ekstraksi terputus ditahan/gagal untuk pemeriksaan dan unggah ulang, bukan diteruskan sebagai sukses parsial.
- **Ditahan** tidak boleh diselesaikan dengan mengedit nominal arsip. Cocokkan bukti asli: identitas, rekening ganda, jurnal kosong/arah salah, tanggal kosong, selisih saldo, presisi, rekening tujuan, atau kewajiban existing. Koreksi membutuhkan proses pengesahan yang dapat ditelusuri; tidak ada pembulatan/koreksi sumber otomatis.
- Log ekstraksi dan antrean berada di penyimpanan Legacy privat existing, di bawah direktori database. API hanya mengembalikan pesan aman, bukan password, path penyimpanan, atau pesan SQL internal.

## Struktur dan proteksi

Delapan tabel fisik: `legacy_finance_sources_data`, `legacy_finance_rows_data`, `legacy_finance_maps_data`, `legacy_finance_reviews_data`, `legacy_savings_openings_data`, `legacy_debt_masters_data`, `legacy_debts_data`, dan `legacy_finance_audit_data`. Masing-masing memiliki view dengan cakupan unit; relasi siswa/master/tagihan mempertahankan unit. Nominal penerapan memakai `DECIMAL(15,2)`.

Identitas baris = SHA-256 dari unit, hash backup, tabel, hash isi canonical, dan nomor kemunculan. Baris identik yang benar-benar berulang tetap memiliki kunci berbeda. Spasi tepi dan huruf pada NIS diselaraskan pada kunci pencarian generated `source_identity`; NIS dan isi baris asli tetap utuh dalam arsip. Manifest identitas siswa memakai urutan sumber yang sama dengan importer lama.

Publikasi seluruh arsip berjalan dalam satu transaksi setelah jumlah per tabel, checksum JSONL, hash backup, dan fingerprint pratinjau cocok. Penerapan pilihan satu siswa juga satu transaksi: kunci akun pemberi izin, review, siswa, rekening, dan tagihan; pemeriksaan jurnal/pembayaran memakai current read. Transaksi kasir existing mengunci siswa yang sama. Versi review, token CSRF, konfirmasi server, unit, dan akun Super Admin aktif diperiksa ulang. Arsip dan saldo awal dilindungi trigger dari pengubahan/penghapusan langsung melalui aplikasi.

Rujukan teknis: [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [Microsoft: pemulihan database dari sumber lain dan CHECKDB](https://learn.microsoft.com/en-us/sql/relational-databases/backup-restore/complete-database-restores-full-recovery-model?view=sql-server-ver15).

## Hasil rekonsiliasi backup asli

Hash ketiga `.dat` cocok persis dengan audit 3 Oktober. Snapshot sumber bertanggal **15 Februari 2019**; zona waktunya tidak ditebak. Seluruh jurnal tabungan yang diekstrak tidak melampaui tanggal snapshot. Dua puluh tabel diperiksa per unit. Tabel pendukung/cache (`temp_*`, `SPP_BELUM`, `rekap_belum_spp`, tarif/komponen) berstatus referensi; tidak ditambahkan sebagai transaksi tambahan.

| Data sumber | SD | SMP | SMA |
|---|---:|---:|---:|
| Siswa | 369 | 787 | 131 |
| Pembayaran | 8.876 | 22.175 | 1.972 |
| Pembayaran DU | 1.067 | 1.317 | 731 |
| Rekening tabungan | 427 | 537 | 256 |
| Jurnal masuk | 21.247 | 13.534 | 6.046 |
| Jurnal keluar | 1.280 | 2.606 | 959 |
| Semua baris arsip termasuk referensi | 34.676 | 43.599 | 11.133 |
| Identitas sumber dengan rekonsiliasi tabungan matematis lulus | 422 | 521 | 251 |

Total arsip **89.408 baris**. Angka rekonsiliasi matematis **bukan jumlah siswa yang boleh langsung diterapkan**: pasangan identitas, aktivasi, rekening tujuan, tanggal, dan bukti keabsahan nominal saat ini tetap wajib. Alasan ditahan dapat tumpang tindih. Rekening dengan identitas kosong/ganda, nominal tidak tepat, atau arah jurnal salah tidak diubah otomatis. Pemeriksaan ketat menemukan 3/12/1 identitas berselisih saldo yang dapat dihitung tepat; satu kasus SMP dengan presisi berlebih ditahan terpisah. Audit lama memakai toleransi float sehingga jumlahnya tidak identik dengan pemeriksaan sen tanpa pembulatan ini.

Fingerprint himpunan baris:

- SD: `974c88e92cc5f33d31b12a76ef674d6cbbe0726a7a068f3c847d9c4a0bcdf836`
- SMP: `5b977e87207504c11e33cb657f12b6330640a0a24b7a0399139e0a78b6f1048c`
- SMA: `7114650d0bbb929815494960017107aa5c3f25db3ed4ca2e30bdaf782c9569db`

Ringkasan mesin tanpa identitas pribadi tersimpan pada `finance-reconciliation.json` di folder QA privat. Rincian sumber, SQL, cookie pengujian, dan screenshot tetap di luar Git.

## Verifikasi yang benar-benar dijalankan

- `legacy_finance_real_backups_test.php`: restore/CHECKDB/ekstraksi ketiga `.dat`, hash audit, jumlah 20 tabel, fingerprint, restaging, reimport, dan keuangan aktif tetap sama setelah publikasi arsip.
- `legacy_finance_test.php`: nominal tepat, rekening ganda/selisih/presisi, arah jurnal dan tanggal kosong, siswa pending, lintas unit, versi lama, konfirmasi, idempotensi, rollback seluruh pilihan, larangan edit sumber/master/saldo, buku/laporan tanpa kas masuk, pembatalan saldo/tunggakan belum dibayar, serta duplikasi kewajiban lintas backup.
- `legacy_finance_http_test.php`: anonim/tiga peran non-Super Admin/akun dicabut, empat cakupan baca, CSRF, parameter array/path, konfirmasi server kedaluwarsa/retry, setoran dan penarikan nyata, cicilan/pelunasan Tunggakan Legacy, struk, pengajuan/keputusan edit-hapus pembayaran oleh kasir dan Super Admin, CSV, serta konfirmasi server tanpa JavaScript.
- `legacy_finance_race_test.php`: dua proses nyata berebut kunci siswa; penerapan menunggu transaksi kasir lalu menolak rekening yang sudah berubah. Tagihan baru setelah peninjauan juga menahan penerapan dan me-rollback saldo awal.
- `legacy_finance_browser_test.js`: **60 kasus layout/palette**, Chrome **155.0.8059.80**, Edge **155.0.4283.45**, Chromium **109.0.5412.0** asli; lebar 360/390/768/1024/1366/1920, terang/gelap, empat palette unit, fokus keyboard/Escape/modal, dan formulir tanpa JavaScript. Unggah SD `.dat` sungguhan melalui UI sampai pratinjau masalah, konfirmasi worker, dan publikasi idempotent juga lulus. Screenshot ditinjau. Browser berjalan pada Windows host saat ini; **Windows 7 fisik belum diuji**.
- `database_backup_runner_test.php`: backup SQL penuh dengan seluruh tabel baru, restore pada instance sementara terpisah, fingerprint/financial/FK/CHECK/health, lock lintas proses, disk/dump/schema/verifikasi gagal, retry, checksum/gzip lama, dan proses terputus. Instance MySQL operasional tidak menjadi target restore.
- Buku tabungan existing, canonicalisasi skema backup, audit integritas, sintaks PHP/JavaScript/PowerShell dan diff diperiksa. Fingerprint **36 tabel fisik operasional identik** sebelum/sesudah pekerjaan.

Seluruh pengujian mutasi memakai instance privat salinan `db_spp` dengan UUID/datadir/port berbeda, dibuktikan manifest fixture. Tidak memakai `db_spp` Laragon sebagai target mutasi. Runtime Chromium 109 portabel pengujian ditempatkan dalam folder QA privat, bukan AppData; runtime SQL Server yang digunakan importer dipertahankan. Tidak ada commit/push pada tahap ini.

Proses PHP/worker/MySQL QA sudah dihentikan, dan instance LocalDB QA milik pekerjaan ini sudah dihapus setelah dipastikan tidak berisi database pengguna. Penghapusan folder browser portabel oleh agen ditolak pemeriksaan otomatis dengan alasan `blocked by policy`; folder `C:\laragon\backups\spp-management-system\legacy_finance_qa_20261016b\browser109` masih ada. Folder tersebut berisi ZIP dan runtime browser pengujian, bukan database atau source aplikasi. Tidak ada browser tambahan yang dipasang ke AppData. SQL cadangan, manifest dan hasil QA privat dipertahankan untuk diagnosis.
