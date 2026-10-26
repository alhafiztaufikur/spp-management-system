# Prosedur migrasi Legacy

Keputusan berlaku 24 Oktober 2026. Baca bersama `LEGACY_FULL_MIGRATION.md` dan
`AGENTS.md`. Aturan workflow 11, operasional 15, otomasi 4, perencana bulanan 2,
billing 3, perencana DU 1, dan pelacakan biaya lain 1 (pembaruan 26 Oktober 2026).
Worker lama harus dihentikan dan dimulai ulang; konfirmasi/simulasi versi lama
tidak boleh diterapkan. Metadata pembayaran otomasi versi 1–3 tetap dapat dibaca.

## Urutan wajib

1. Periksa database, port, UUID server, unit, aktor dan sumber. Jangan mengira
   database yang dibuka IDE sama dengan target aplikasi.
2. Buat dump konsisten lengkap (routines, triggers, events); hitung SHA-256.
   Pulihkan pada server MySQL terisolasi dengan UUID/port/datadir berbeda dan
   event scheduler OFF. Bandingkan `lmr_snapshot()` sumber dan hasil restore.
   Simpan JSON bukti `verified_restore`, `backup_file`, `sha256`, `target`
   (`lmr_target()`), `restored_server_uuid`, serta `snapshot`.
   Simpan juga `database_checksums` dari `lmr_database_checksums()`: seluruh tabel
   hasil restore harus sama, bukan hanya jumlah baris.
3. Ekstraksi memakai pipeline `legacy_extract.ps1`, profil unit, hash/manifest
   sumber, dan arsip mentah existing. Jangan membuka sumber sebagai target tulis.
4. Klasifikasikan pemilik dan kelompok; NIS dipasangkan dengan unit. Rekening
   nonakademik tidak menjadi siswa aktif atau menerima tagihan otomatis.
5. Rekonsiliasi PSB/DU. PSB master menyimpan gross dan discount terpisah.
   `PANGKAL_BAYAR` mencakup penerimaan rinci; perubahan penerimaan setelah impor
   dihitung sebagai delta, bukan tambahan seluruh rincian.
6. Petakan komponen. `U_SORGA` = Komite/POMG di ketiga unit. Rekategorisasi
   mempertahankan jumlah/tanggal penerimaan. Pembayaran yang pernah dikoreksi
   tidak ditimpa; tinjau auditnya melalui peninjauan Legacy existing.
7. Lacak biaya lain otomatis, pada GUI maupun runner: pasangkan LAIN_LAIN1–4
   dengan JUMLAH1–4 dan master `lain.KODE` dalam unit/sumber yang sama. Kode
   tetap string, termasuk nol di depan. Simpan identitas detail unit + sumber +
   row_key + slot, ID transaksi asal, kode asli, master tujuan dan dasar keputusan.
   Kode belum teridentifikasi tetap diterima tanpa persetujuan per komponen.
   Nominal tidak sah/total bertentangan diarsipkan otomatis pada transaksi itu;
   transaksi valid lain tetap diproses. Jangan menggandakan kas ke tabel native.
8. Jalankan perencana SPP–Komite sejak Januari 2020. Bukti pembayaran menyebut
   periode dan kelasnya. Tarif stabil mengisi bulan terlewat di antara bukti;
   header aktif tahun peralihan hanya memperpanjang tarif yang cocok sampai Juni.
   Jangan mengisi bulan sebelum bukti masa kewajiban pertama, memakai NIS
   sebagai bukti tahun masuk, atau memakai tarif terbaru untuk semua tahun.
9. Pertahankan pembayaran periode mendatang pada bulan tagihannya. Jangan
   mengubah tanggal kas atau membuat penerimaan baru untuk kredit pembukaan.
10. Verifikasi tabungan, saldo pembukaan dan seluruh jurnal. Rekonstruksi tagihan
   tidak boleh mengubah salah satunya. Migrasi operasional baru memakai
   `op_preserve_unproven_balances=true`: selisih header/jurnal diarsipkan sebagai
   pengecualian rekening tanpa jurnal penyeimbang otomatis. Koreksi dengan bukti
   eksplisit dan keputusan batch lama tetap dipertahankan.
11. Preview/simulasi, periksa jumlah dan nominal per komponen/periode serta
    pengecualian. Per orang yang belum lengkap masuk daftar peninjauan existing;
    orang lain tetap dapat diproses.
12. Uji apply dan verify di salinan. Periksa Data Siswa, Master SPP, Input
    Pembayaran, Laporan Global, ekspor, pembayaran baru dan pembatalannya.
    Periksa pula Master Biaya Lain: Transaksi menghitung struk berbeda, jumlah
    rincian dan nominal mengikuti seluruh slot, tautan membuka riwayat komponen
    pada seluruh periode. Bisa Dihapus tidak memasukkan master yang mempunyai
    pembayaran native/Legacy, pembatalan, atau referensi audit. Verifikasi runner
    menyertakan `verification_fee_trace.master_usage` dari pembaca master yang sama.
    Bandingkan pula Ringkasan Kewajiban pada Input Pembayaran dengan rincian Komite:
    pembayaran native dan Legacy harus sama-sama mengurangi sisa per periode.
    Nama item struk/riwayat cukup nama biaya; ID/kode/slot tetap pada metadata.
    Sisa DU yang belum diketahui ditampilkan `-`, dengan nilai internal tetap null.
13. Apply target sesudah backup terverifikasi; transaksi per unit, audit tiap
    penyelarasan. Verify ulang harus exit 0 dan `changes: 0`. Arsip, identitas,
    penerimaan, rekening dan jurnal harus mempunyai fingerprint yang sama.

## CLI

```powershell
php scripts/legacy_migration_runner.php --database=db_spp_test2 --port=3306 --mode=preview --output=C:/private/preview.json
php scripts/legacy_migration_runner.php --database=db_spp_test2 --port=3306 --mode=apply --backup-proof=C:/private/backup-proof.json --output=C:/private/apply.json
php scripts/legacy_migration_runner.php --database=db_spp_test2 --port=3306 --mode=verify --output=C:/private/verify.json
```

`--unit=1|2|3` membatasi unit; tanpa unit memeriksa ketiganya. `--actor` default 1
harus Super Admin aktif. `--source=C:/private/smpit_mh.dat --unit=2` mencocokkan
hash sumber dengan batch existing atau menyiapkan job sumber baru melalui
extractor, klasifikasi, bulk review dan simulasi existing. `--settings=file.json`
melengkapi keputusan yang belum terbukti; jangan mengarang pengesahan saldo.
Sumber baru yang masih memiliki pengecualian tetap dapat ditinjau melalui Legacy.
Penerapan sumber baru juga menggunakan backup terverifikasi dan gerbang akses
aplikasi existing (`lful_run`); konfigurasi layanan backup harus cocok target.
Pada sumber baru, pipeline membuat dan memulihkan backup sebelum/sesudah penerapan
secara otomatis; `--backup-proof` diperlukan untuk rekonsiliasi batch existing.
Konfigurasi backup bawaan mengikuti database aktif pada
`C:/laragon/data/spp-backup/<database>/config.json`; `SPP_BACKUP_CONFIG` tetap
menjadi override eksplisit. Installer backup menerima `-DatabaseName`,
`-DatabaseHost`, `-DatabasePort`, dan `-ConfirmTarget <database>` untuk target
bernomor seperti `db_spp_test3`; akun ekspor serta storage terpisah. UUID,
port, datadir, akun, dan storage harus cocok; jangan memakai konfigurasi
database atau proyek lain untuk database pengujian.
`verify` keluar dengan kode 2 jika masih ada perubahan yang perlu diterapkan.

GUI dan CLI harus membaca root `SPP_LEGACY_STORAGE` yang sama. Bila batch
existing ada di MySQL tetapi pekerjaan tidak terlihat pada GUI, periksa root
privat lama sebelum mengunggah atau mengimpor ulang. Cocokkan ID job, unit dan
hash sumber terhadap batch completed. Pemulihan arsip mencakup file job serta
tabel SQLite `jobs`, `rows`, dan `full_drafts`; simpan backup antrean tujuan dan
verifikasi checksum salinan. Jangan menimpa pekerjaan/koreksi yang sudah ada.

Simpan output di luar webroot karena berisi bukti per siswa. `preview` menjalankan
rekonsiliasi dalam transaksi yang di-rollback, termasuk audit dan tagihannya.
Persiapan sumber baru menyimpan arsip/draf untuk ditinjau, tanpa menerapkan siswa.

## Alur GUI yang dipakai

- **Data Siswa → Edit:** PSB gross/potongan efektif tampil dari sumber bila master
  impor belum terisi. Menyimpan identitas/bulan Komite tidak meminta siswa kembali
  ke kelas PSB. Koreksi pengguna dipertahankan dan tercatat pada audit.
- **Master Penerbitan SPP → Mulai tagihan → Terbitkan SPP:** memilih Oktober
  melengkapi Oktober yang kurang; cutover November tidak melarangnya. Tagihan
  existing dan kredit Juli–September dipertahankan; Komite disiapkan berpasangan.
- **Input Pembayaran:** pilih bulan tagihan, lunasi SPP dan Komite sesuai urutan.
  Tanggal penerimaan baru diisi tanggal uang benar-benar diterima.
- **Legacy:** selesaikan bukti yang belum cukup lewat peninjauan yang sudah ada.
  Laporan Global menandai nominal belum diketahui, bukan menyebutnya nol/lunas.

Untuk **biaya lain**, pemetaan/pemeriksaan per rincian oleh pengguna bukan
syarat migrasi. Status belum teridentifikasi dan struk yang ditahan dicatat
otomatis; koreksi manual tetap pilihan. Rincian, struk dan ekspor existing
memakai jejak sumber yang sama. [Perilaku dan pengujian](LEGACY_FEE_TRACE_20261026.md).
Sebelum setiap penerapan data, jalankan pengujian penulisan pelacakan biaya
lain pada server uji terisolasi sesuai penjagaan UUID/port di test. Pekerjaan
awal hanya menjalankan fixture murni; pengujian penulisan dan publikasi nyata
kemudian lulus pada persiapan migrasi test3. Hasil sebelumnya tidak menggantikan
kewajiban menguji kode dan sumber yang akan diterapkan.

Raisa SMP `252607034`: PSB gross 14.295.000, potongan 6.900.000, net/paid
7.395.000, sisa 0, kelas tetap 8A. Juli–September 2026 dibayar berdasarkan
periodenya; Oktober SPP 327.500 dan Komite 15.000. Contoh ini merupakan regresi
wajib, bukan aturan tarif yang disalin ke siswa lain.

Hasil penerapan dan batas bukti yang masih perlu ditinjau dicatat pada
[audit perbaikan 24 Oktober 2026](LEGACY_BILLING_REPAIR_20261024.md).

## Pelacakan DU wajib untuk sumber baru dan existing

Gunakan perencana `includes/legacy_du_planner.php` dan engine
`includes/legacy_du.php` melalui runner/publikasi GUI yang sama. Tahun DU
berjalan dibekukan pada draf/simulasi (`du_target_year`); `--du-year=2026/2027`
menyebutkannya eksplisit pada runner. Jika tahun berubah, simulasi harus diulang.

Per unit + siswa + tahun, cocokkan arsip tarif sumber, kelas kewajiban,
potongan eksplisit, dan periode rincian transaksi. Rekap `bayar_du` hanya
menguatkan rekonsiliasi bila kelas/tahun/nominal cocok, bukan penerimaan baru.
Tanggal kas tidak menentukan tahun DU. Pembayaran untuk tahun mendatang tetap
milik periodenya dan tidak dikreditkan ke tahun berjalan.

Bentuk master serta tagihan untuk siswa aktif yang bukti tahun berjalan lengkap,
termasuk tagihan lunas. Terbitkan tahun draf melalui fungsi resmi meski sebagian
kelas belum terbukti. Jangan menerbitkan ulang siswa lulus, tahun lama/tertutup,
tagihan dibatalkan, penagihan Legacy yang sudah tersedia, atau menimpa koreksi.
Kekurangan bukti diarsipkan otomatis sebagai `du_evidence`; tidak menunggu pemetaan
pengguna dan tidak menghambat siswa yang bukti lengkap. Rekening tabungan saja
tidak menerima tagihan. Master/tagihan bukan penerimaan kas.

Audit `du_trace` menyimpan hubungan master/tagihan, ID/row_key sumber, kolom,
periode dan nominal tiap rincian, hash sumber, tarif serta rekap yang cocok.
Pembayaran Legacy tetap di penyimpanan Legacy. Kredit native + Legacy dipakai
bersama pada kasir, ringkasan kewajiban, riwayat, Master DU dan laporan.
Tombol existing **Lengkapi Tagihan yang Belum Ada** memakai engine yang sama.

Wajib uji murni `tests/legacy_du_planner_test.php`, publikasi sumber baru melalui
`tests/legacy_migration_automation_transactions_test.php --draft --publication --du-publication`
pada fixture terisolasi, lalu `tests/legacy_du_integration_test.php` pada salinan
existing. Verifikasi laporan `verification_du`: jumlah/nominal lunas, bersisa,
belum terbukti, serta hubungan transaksi?tagihan?master. Pastikan Raisa SMP
252607034 DU 2026/2027 Rp4.310.000, paid Rp4.310.000, sisa Rp0, tetap 8A.
Uji sisa/cicilan, pembayaran ganda, pembatalan/koreksi, lintas unit, tahun
tertutup, bukti parsial, dan pengulangan nol perubahan. Verifikasi halaman Master
DU, riwayat/rincian transaksi, Input Pembayaran, laporan dan ekspornya sebelum
menyatakan migrasi selesai. Backup/restore terverifikasi tetap wajib sebelum apply.
