# Konteks Proyek SistemSPP

> **Status 5 Oktober 2026:** PSB mencakup Pangkal; komponen Pangkal dan persen operasional telah dihapus pada db_spp lokal. Potongan SPP nominal, DIKNAS opsional, tampilan tanggal DD/MM/YYYY dan timestamp WIB. Pembayaran lama digabung ke PSB tanpa menambah kas: 1.359 siswa, 1.020 pembayaran/Rp577.790.000; Tabungan 12/Rp1.050.000 tetap utuh. Health OK dan 30 integritas bersih; worker importer berjalan kembali. [Audit/migrasi/pemulihan](PSB_NOMINAL_DATE_AUDIT_20261005.md) menjadi status terbaru; catatan tanggal sebelumnya adalah bukti historis. Railway tidak dimigrasikan.

> **Status terkini setelah impor Legacy 3 Oktober 2026:** migrasi unit/NIS dan status Legacy sudah terpasang pada trial `db_spp`, implementasi `62dd273` pada main. Total 1.359 siswa (222 existing + 1.137 pending Legacy). Tepat setelah impor, pembayaran 1.018/Rp577.145.000 dan seluruh nilai existing tetap identik. Setelah dua pembayaran operasional berikutnya, total terbaru 1.020 pembayaran/Rp577.790.000; perubahan tarif SD berikutnya tercatat terpisah di audit. Pembayaran/alokasi original dan Tabungan 12 rekening/Rp1.050.000 tetap utuh. Worker LocalDB privat aktif; mulai lagi manual setelah restart Windows. Aktivasi oleh admin/kasir unit membuat hanya satu penempatan awal, tanpa tagihan otomatis; master SPP menyiapkan pasangan Komite saat penerbitan. Histori/alumni/keuangan legacy tidak ditebak atau diimpor. [Audit backend](LEGACY_IMPORT_BACKEND_20261003.md) dan [runbook](LEGACY_IMPORT_RUNBOOK_20261003.md) memuat hasil/batas trial.


SistemSPP adalah aplikasi administrasi pembayaran sekolah berbasis PHP, JavaScript, dan MySQL (`mysqli`). Dokumen ini merangkum alur aktif. Untuk rincian teknis, kode dan schema adalah sumber kebenaran; [AI_CHANGELOG.md](./AI_CHANGELOG.md) adalah arsip perubahan, bukan panduan operasional.

> **Baseline historis 2 Oktober 2026 setelah penghapusan Titipan SPP:** migrasi lokal `db_spp` sudah diterapkan, dengan 18 CHECK, dua trigger tarif dan 61 FK. Utama: 222 siswa, 1.018 pembayaran, Rp577.145.000 penerimaan. Tabungan tetap 12 rekening/Rp1.050.000; fingerprint rekening dan kedua jurnal tidak berubah. [Audit kesiapan](./READINESS_AUDIT_20261001.md) tetap menilai **siap terbatas dengan syarat** untuk Laragon pada cakupan yang diuji. Seluruh commit audit sampai `6a95cc0` sudah digabung ke `main` tanpa konflik; health dan integritas diperiksa ulang setelah penggabungan. Deployment target dinilai terpisah. Prosedur backup/pemulihan ada di [runbook](./READINESS_MIGRATION_RUNBOOK_20261001.md).

## Riwayat dan laporan ? 10 Oktober 2026

Riwayat Pembayaran dan Riwayat Daftar Ulang menyediakan filter Operator dengan pencarian dan checkbox beberapa petugas. Filter mengikuti pembuat awal, diterapkan lewat Tampilkan Rekap, serta dipertahankan pada pagination/detail/kembali dari edit. Daftar Ulang memisahkan nominal cicilan operator terpilih dari saldo dan status lunas yang tetap menghitung semua pembayaran siswa. Laporan Umum kini bernama **Laporan Transaksi**.

Super Admin dapat memilih transaksi lintas halaman pada Riwayat Otorisasi dan memakai **Cetak Terpilih** atau **Cetak Semua Hasil Filter**. Pilihan terikat filter/unit; pratinjau dan unduhan memakai token sesi tervalidasi dua jam. POST ekspor pada Semua Unit hanya membaca laporan dan menyimpan pilihan sesi. Hak mutasi transaksi dan akses PDF per peran tetap mengikuti kebijakan yang ada. Tidak diperlukan migrasi. Rincian: [Riwayat Pembayaran](RIWAYAT_PEMBAYARAN.md), [Daftar Ulang](RIWAYAT_DAFTAR_ULANG_UI.md), dan [Otorisasi](OTORISASI_TRANSAKSI.md).

Laporan Transaksi memakai font lebih ringkas khusus halaman laporan (teks 15px, tabel 14px, label 13px, keterangan 12px). Cetak setelah setoran/penarikan dan tombol riwayat/detail Tabungan kini membuka buku lengkap yang sama dengan menu Cetak Tabungan, dengan saldo berjalan dan baris terpisah untuk setiap mutasi. URL cetak transaksi lama tetap memeriksa kepemilikan pada pratinjau dan PDF. Tidak ada migrasi. Rincian: [Tabungan](TABUNGAN_WORKSPACE.md).

## Lingkungan

- **Pemulihan UI 2 Oktober 2026:** desain komponen aktif dikembalikan sesuai `7647608` setelah regresi CSS penghapusan titipan. Tema SD/SMP/SMA, terang/gelap dan responsif dibuktikan lewat 576 perbandingan screenshot serta tes kontrol browser; seluruh aturan bisnis terbaru tetap berlaku. Lihat [audit UI](UI_RECOVERY_AUDIT_20261002.md). Versi stylesheet PHP memakai `filemtime` agar perubahan tidak tertahan cache browser.
- Pengembangan lokal saat ini memakai Laragon di `C:\laragon\www\spp-management-system` dan database `db_spp`.
- Konfigurasi koneksi berada di `koneksi.php`. Di Railway, koneksi memakai variabel `SPP_DB_*`; lihat [panduan deployment](./RAILWAY_DEPLOYMENT.md).
- Skema instalasi baru berada pada payload non-SQL `sql/schema.payload`, dibaca oleh `sql/schema_source.php`. `sql/schema.sql` hanya menolak impor langsung, termasuk ketika klien memakai `mysql --force`. Gunakan `sql/bootstrap_production.php` untuk database kosong; jangan pakai payload ini sebagai migrasi data lama.
- Untuk reset dan pengisian data **demo**, ikuti [DEMO_DATA_RESET.md](./DEMO_DATA_RESET.md). Jangan terapkan reset pada data sekolah sungguhan.

## Kenaikan kelas fleksibel

Master Kelas memakai tahun dan tingkat asal yang dipilih. Tidak ada kewajiban luluskan kelas tertinggi terlebih dahulu. Peserta mengikuti penempatan aktif/latest pada tahun asal; target tepat satu tingkat/tahun berikutnya dan unit sama. Source diproses satu kali (pindah/lulus), kelas aktif segera berubah. Tahun mendatang belum menjadi source sampai berjalan; Legacy/PSB/arsip dan riwayat yang bertentangan ditahan.

Tarif target mengikuti master tahun/kelas dan nominal potongan; jika belum siap, nilai informasi/snapshot target nol, bukan perkiraan tagihan. Master menerbitkan SPP/DU, Komite mengikuti perilaku existing. Tidak menulis ulang source atau paid snapshots. [Audit perubahan](FLEXIBLE_PROMOTION_AUDIT_20261005.md) menjadi rujukan pengujian dan status penerapan.

## Alur pembayaran aktif

- Data Siswa tidak memakai mode Advanced: NIS Diknas, biaya, dan potongan langsung dapat diisi dengan validasi serta perlindungan histori yang sama. Tarif dasar Master SPP terbit terkunci saat halaman dibuka; Super Admin/Admin/Kasir dapat memakai Edit Tarif dan mengonfirmasi perubahan pada tagihan belum dibayar. Tagihan berbayar, dibatalkan, dan ditanggung PSB tetap utuh. Tahun tertutup harus dibuka kembali. Simpan tarif draft dan penerbitan pertama juga meminta konfirmasi; formulir lama ditolak melalui versi tarif. [Verifikasi revisi 10 Oktober 2026](STUDENT_TARIFF_CORRECTION_20261010.md).
- Master Siswa menyimpan PSB sebagai kewajiban sekali bayar, termasuk Pangkal. Komponen Pangkal terpisah dipensiunkan; PSB dapat dicicil sesuai sisa tagihan. Potongan SPP memakai nominal rupiah per bulan dan DIKNAS opsional.
- Master Penerbitan SPP membuat tagihan bulanan Juli–Juni berdasarkan penempatan siswa yang tersimpan. Kasir memilih **Bulan Tagihan SPP & Komite** dan **Tahun Tagihan**. Satu transaksi SPP hanya melunasi satu bulan; tunggakan SPP lebih tua diperiksa dahulu.
- Komite adalah tagihan bulanan dari tarif `siswa.POMG`. Ketika SPP suatu bulan dibayar, Komite bulan yang sama harus sudah lunas atau ikut dilunasi. Jika SPP dan Komite periode yang sama sama-sama masih terutang, keduanya wajib dilunasi bersama. Komite dapat dibayar sendiri jika SPP belum terbit, nol/ditanggung PSB/potongan penuh/dibatalkan, atau telah lunas.
- **Tanggal Bayar** mencatat hari uang diterima, bukan periode tagihan. SPP wajib tepat sesuai sisa satu tagihan bulanan; nominal kurang/lebih ditolak. Tidak ada Titipan SPP atau saldo uang pembayaran. Pembayaran tahun tujuan dapat dilakukan lebih awal setelah kenaikan resmi membentuk penempatan tujuan dan tagihan diterbitkan; tunggakan lebih tua tetap didahulukan dan konfirmasi periode mendatang tetap muncul. Tidak ada penempatan rencana atau perkiraan kelas tujuan.
- Daftar Ulang memakai tagihan tahunan. Dropdown tahun selalu tersedia di form input dan edit; tanda `!` muncul bila siswa memiliki tunggakan tahun ajaran sebelumnya. Tagihan tahun berjalan yang masih bersisa menjadi pilihan awal; jika sudah lunas, tunggakan lama tertua yang belum lunas dipilih. Pembayaran dapat dicicil sampai sisa tagihan, sedangkan tahun dan kelas pada transaksi berasal dari snapshot tagihan yang dipilih (bukan bulan SPP pada form). Baseline demo 2026/2027 tidak membuat tagihan Daftar Ulang tahun sebelumnya.
- Biaya Lain memakai tagihan yang diterbitkan dari master. Tabungan masuk/keluar adalah jurnal terpisah, bukan komponen penerimaan pembayaran sekolah.
- Riwayat kelas memakai `siswa_tahun_ajaran`; laporan dan struk membaca snapshot/tagihan terkait agar perubahan tarif atau kelas berikutnya tidak menulis ulang histori.
- NIS internal menjadi penghubung riwayat dan tagihan. Setelah siswa dibuat, form Data Siswa tidak mengubah NIS; koreksi NIS historis memerlukan prosedur migrasi relasi tersendiri.
- Surat Laporan ke Kepala Sekolah menampilkan total tunggakan per rombel dan total pilihan, tanpa nama atau NIS siswa. Cakupan dapat dipilih untuk satu rombel, seluruh rombel dalam satu tingkat kelas, atau seluruh kelas; status siswa Aktif/Arsip/Semua tetap dapat dipilih. PDF dan Excel memakai rekap yang sama.

## Hak akses

Laporan Global mempunyai cakupan baca tersendiri: Super Admin, Admin, Kasir, dan Bendahara dapat memilih SD/SMP/SMA/Semua Unit pada katalog, sembilan template, dan ekspornya. Pilihan unit laporan tidak mengubah unit operasional atau hak transaksi. Identitas/kelas/operator dan metadata ekspor mengikuti unit laporan; filter yang bergantung pada unit dibersihkan ketika cakupan diganti. Dashboard, surat, dan modul lainnya tetap mengikuti aturan akses sebelumnya. [Verifikasi 10 Oktober 2026](GLOBAL_REPORT_SCOPE_20261010.md).

### Cakupan Super Admin — 3 Oktober 2026

Sidebar menyimpan SD/SMP/SMA atau **Semua Unit** dalam sesi. Semua Unit hanya untuk baca data, riwayat, laporan, surat, dan pengaturan; baris/rombel/ekspor menyertakan unit. Master tahunan gabungan menampilkan tarif per unit/tahun. Struk, buku Tabungan dan surat individual memakai sekolah pemilik data.

Pada input/edit pembayaran, Tabungan Masuk/Keluar dan otorisasi, opsi Semua Unit tidak tersedia. Sesi gabungan menampilkan **Pilih unit untuk transaksi**, tanpa memilih unit otomatis atau memuat form. Pilih SD/SMP/SMA terlebih dahulu. Seluruh mutasi siswa/master/penerbitan/kenaikan/pengaturan juga wajib satu unit. Guard role di server menolak tulis dalam sesi gabungan (409) dan permintaan transaksi dengan cakupan gabungan (422), termasuk query/POST; pengaman CSRF dan kepemilikan tetap berlaku. Akun selain Super Admin selalu memakai unit akunnya. Pergantian cakupan membersihkan pilihan terkait unit dan mempertahankan tanggal.

Pemilih cakupan Dashboard/laporan memperbarui sesi melalui POST dengan CSRF. URL laporan lama `unit=all` tetap dapat dibuka sebagai cakupan baca. Palette super yang sudah tersedia dipakai pada gabungan; stylesheet hasil pemulihan tidak berubah. Tidak diperlukan migrasi. Lihat [bukti fitur Semua Unit](ALL_UNITS_AUDIT_20261003.md).

| Aksi | Admin | Kasir | Bendahara |
| --- | :---: | :---: | :---: |
| Input, lihat, cetak pembayaran | Ya | Ya | Tidak |
| Edit pembayaran | Langsung | Ajukan perubahan; berlaku setelah disetujui Admin | Tidak |
| Hapus pembayaran | Langsung | Ajukan penghapusan; berlaku setelah disetujui Admin | Tidak |
| Setujui/tolak pengajuan kasir | Ya | Tidak | Tidak |
| Periksa antrean/riwayat otorisasi | Ya | Pengajuan sendiri | Ya, baca saja |
| Kelola Data Siswa, Kelas/Rombel, SPP, Biaya Lain, Daftar Ulang | Ya | Ya | Tidak |
| Kelola akun/role | Super Admin saja | Tidak | Tidak |
| Laporan Global | Ya | Ya | Ya |

Guard backend berada di `includes/auth.php`. Hak akses harus diperiksa pada endpoint mutasi, bukan hanya dengan menyembunyikan tombol.

## Lokasi kode utama

- `backup_restore.php`: antarmuka baca khusus Super Admin untuk Backup & Restore serta Import Legacy; menyediakan importer identitas Legacy melalui worker privat; backup/restore penuh tetap nonaktif. CSS/JS terpisah, cakupan pemulihan seluruh database. Lihat [batas tahap UI dan verifikasi](BACKUP_RESTORE_UI_20261003.md) serta [audit kelayakan legacy](LEGACY_IMPORT_FEASIBILITY_20261003.md).

- `pembayaran/`: input, histori, edit/hapus, dan struk transaksi.
- `siswa/`: Data Siswa dan riwayat kelas.
- `master_spp.php`, `master_kelas.php`, `master_biaya_lain.php`, `master_daftar_ulang.php`: pengelolaan master.
- `includes/reports.php`, `laporan/`: query, tampilan, cetak/PDF, dan ekspor laporan.
- `sql/schema.payload`, `sql/schema_source.php`, `sql/bootstrap_production.php`: sumber skema dan installer; `sql/schema.sql` menolak impor langsung. `sql/verify_schema.sql`: pemeriksaan skema.
- `sql/run_legacy_sql.php`: gate CLI untuk skrip SQL mutatif lama; lihat [operasi SQL legacy](LEGACY_SQL_OPERATIONS.md). Impor langsung `sql/*.sql` lama ditolak.
- `tests/`: pengujian regresi dan integrasi.

Sebelum mengubah data atau menjalankan SQL destruktif, periksa database target, buat backup, dan baca hasil verifikasi. Jangan memasukkan dump data siswa, kredensial, atau secret ke Git.
