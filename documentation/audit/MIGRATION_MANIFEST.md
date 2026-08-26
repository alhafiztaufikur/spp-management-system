# Manifest Migrasi Kanonik SistemSPP

Manifest ini adalah sumber urutan upgrade database SistemSPP. Tujuannya mencegah migrasi dijalankan berdasarkan urutan nama file, tanggal tebakan, atau potongan dokumentasi lama. `sql/schema.sql` hanya untuk database baru dan tidak termasuk rantai upgrade produksi.

Baseline yang dipetakan: repository dan schema per 20 Agustus 2026, MariaDB 10.4/XAMPP. Paket security menambahkan `add_security_controls.sql`, audit append-only memakai `add_financial_audit_log.sql`, idempotency mutasi memakai `add_mutation_idempotency.sql`, sedangkan rekonsiliasi operator exact-match memakai `normalize_transaction_operators.sql`; semuanya sudah diberi posisi dependency di bawah. Migrasi SQL baru lain yang belum diklasifikasikan adalah dependency **pending** dan harus menghentikan matrix sampai manifest ditinjau.

## Aturan keselamatan

- Jangan pernah menjalankan `sql/schema.sql` pada database berisi data. File itu memakai `DROP TABLE`, mematikan pemeriksaan FK sementara, dan memuat data demo; schema saat ini tidak menanam akun/password default.
- Upgrade produksi wajib dimulai dari backup yang telah diuji restore-nya, versi/tag Git yang diketahui, dan salinan database terisolasi.
- DDL MariaDB melakukan implicit commit. Tidak ada jaminan rollback transaction untuk rangkaian migrasi; rollback produksi berarti restore backup ke database baru lalu mengembalikan konfigurasi aplikasi.
- File verifier bersifat read-only. File `sync_*` dan `remove_*` tetap merupakan migrasi data mutatif walaupun dapat dijalankan ulang.
- Seed bukan migrasi. `seed_kasir_accounts.sql` dan `seed_demo_students_30.sql` dilarang pada produksi/client.
- Satu migration batch harus dijalankan saat aplikasi dalam maintenance mode agar tidak bersaing dengan transaksi kasir.

## Jalur instalasi baru

1. Buat database kosong khusus instalasi.
2. Jalankan `sql/schema.sql` satu kali.
3. Jalankan `sql/verify_schema.sql`; seluruh requirement harus `OK`.
4. Jalankan `sql/verify_data_integrity.sql`; seluruh INV harus `PASS`.
5. Hapus/nonaktifkan akun dan data demo sesuai prosedur provisioning, lalu buat akun produksi dengan credential unik.
6. Jalankan smoke test role, pembayaran, DU, Biaya Lain, tabungan, dan laporan pada database nonproduksi sebelum cutover.

Migrasi bertahap tidak diperlukan untuk instalasi baru karena schema sudah memuat bentuk akhir. Matrix test tetap menjalankan seluruh migrasi dua kali di atas fresh schema untuk membuktikan kompatibilitas dan idempotensi paket.

## Urutan upgrade kanonik

| Tahap | File | Dependency minimum | Efek dan alasan urutan | Idempotensi / rollback |
| --- | --- | --- | --- | --- |
| 01 | `add_role_management.sql` | Tabel `admin` | Menambah enum role yang dipakai audit/admin berikutnya. | `ADD IF NOT EXISTS`; update deterministik. Restore backup bila enum/role salah. |
| 02 | `add_security_controls.sql` | Tahap 01 | Menambah `admin.session_version`, `password_reset_required`, serta tabel/index rate-limit login. Hash MD5 lama hanya ditandai wajib reset dan tidak diautentikasi; posisi kolom `AFTER role` membuat dependency role eksplisit. | Procedure metadata-guarded; repeat tidak menebak atau mengubah hash credential. Telemetri login yang sudah ada tidak dihapus. |
| 03 | `add_financial_audit_log.sql` | Tahap 01 dan 02; tabel `admin` | Membuat `audit_event` append-only dengan snapshot actor (tanpa FK actor agar histori tidak berubah ketika akun dihapus), index, JSON checks, serta trigger penolak update/delete. Diletakkan sebelum modul finansial agar handler berikutnya dapat menulis audit tanpa window schema kosong. Tidak melakukan backfill histori. | `CREATE TABLE IF NOT EXISTS` dan `CREATE OR REPLACE TRIGGER`; repeat harus menjaga schema/data logis. DDL/trigger memerlukan privilege deployment dan rollback melalui restore. |
| 04 | `add_mutation_idempotency.sql` | Tahap 01 dan 02; tabel `admin` opsional untuk snapshot actor | Membuat `mutation_request` dengan unique `(scope, request_key)` untuk mencegah submit ulang mutasi finansial. Tidak melakukan backfill dan tidak memakai FK actor agar penghapusan akun tidak membuka token lama. | `CREATE TABLE IF NOT EXISTS`; repeat tidak mengubah klaim. Token hanya diklaim di transaksi bisnis yang sama dan rollback bersama transaksi. |
| 05 | `add_master_biaya_lain.sql` | `bayar` beserta slot legacy, `siswa` | Membuat master/detail dan memindahkan slot Biaya Lain lama dengan `legacy_key`. Harus ada sebelum aktivasi mirror dan laporan modular. | `CREATE IF NOT EXISTS` + unique legacy key membuat backfill repeatable. Restore bila pemetaan snapshot salah. |
| 06 | `add_student_advanced.sql` | `siswa`, `admin`, `bayar` | Menambah status siswa, field advance, Komite, cache/audit siswa, dan validasi kelas. Dibutuhkan tahun ajaran dan laporan modular. | Procedure memeriksa metadata; update turunan repeatable. Preflight kelas wajib lulus. |
| 07 | `add_payment_references.sql` | `bayar`, `bayar_du`, `transaksi_m` | Menambah `payment_link_version` dan relasi child aman. Wajib sebelum academic billing dan annual receipt yang menempatkan kolom relatif terhadap field ini. | Metadata-guarded. Tidak melakukan tebakan/backfill legacy. Restore bila FK gagal akibat data yatim. |
| 08 | `add_payment_method.sql` | `bayar` | Menambah metode Tunai/VA/Qris; dibutuhkan index dan laporan modular. | `ADD IF NOT EXISTS`; NULL/kosong dinormalisasi ke VA. Perubahan klasifikasi lama perlu persetujuan bisnis. |
| 09 | `add_payment_updated_at.sql` | `bayar` | Menambah timestamp edit kuitansi. | Metadata-guarded; tidak mengubah histori nominal. |
| 10 | `add_master_daftar_ulang.sql` | Tabel legacy `Daftar_ulang` | Menormalisasi master DU dan unique kelas+tahun sebelum materialisasi tagihan. | Metadata-guarded; konflik duplikat harus diselesaikan sebelum unique key. |
| 11 | `add_academic_year_billing.sql` | Tahap 06, 07, 10 | Membuat tahun ajaran, penempatan, tagihan DU, audit, dan konteks tagihan histori DU yang valid untuk rekonsiliasi saldo. Tidak mengisi kepemilikan header `bayar_id`. | Didesain repeatable dengan unique keys/`INSERT IGNORE`; konteks tagihan legacy harus direview dari output pre/post, sementara `bayar_du.bayar_id` tetap NULL/versi legacy. DDL tidak transactional penuh. |
| 12 | `add_student_optional_fees.sql` | `siswa` advance | Menambah tarif Makan/Sorga/Infaq per siswa. | `ADD IF NOT EXISTS`; tidak memberi tarif otomatis. |
| 13 | `add_annual_payment_receipts.sql` | Tahap 07; `bayar`, `siswa` | Menambah metadata batch dan claim periode SPP, lalu backfill format bulan legacy yang dikenal. | Struktur metadata-guarded; `INSERT IGNORE` repeatable tetapi tidak membetulkan claim salah. |
| 14 | `allow_spp_installments.sql` | Tahap 13 | Menghapus unique siswa+periode bila masih ada, mempertahankan index nonunik, dan upsert seluruh claim SPP valid. | Procedure metadata-guarded; backfill `ON DUPLICATE KEY UPDATE` repeatable. Harus menutup gap yang tersisa dari tahap 13. |
| 15 | `activate_legacy_fields.sql` | Tahap 05, 06, 11 | Memperlebar field legacy, menambah unique NIS Diknas, menyelaraskan total legacy/DU, dan membentuk mirror empat slot Biaya Lain. | Repeatable, tetapi melakukan bulk update. Preflight duplikat Diknas dan DU di bawah pembayaran wajib nol. |
| 16 | `normalize_transaction_operators.sql` | Tahap 01 dan 15 | Mengganti operator yang exact-match `admin.username` menjadi `CAST(admin.id AS CHAR)` pada pembayaran/tabungan. Nilai ambigu atau unknown tidak ditebak dan tetap antrean review. | Wajib idempoten: baris yang sudah ID tidak berubah; simpan hitungan matched/unmatched sebelum/sesudah. |
| 17 | `sync_student_initial_fee_paid_totals.sql` | Semua histori `bayar` sudah tersedia | Menjadikan cache `*_BAYAR` sama dengan agregat ledger pembayaran awal. | Deterministik/repeatable. Ini rekonsiliasi data; simpan output sebelum/sesudah dan jangan jalankan bila client mengakui saldo pembuka di luar ledger. |
| 18 | `remove_payment_linked_savings.sql` | Tahap 07; kode aplikasi baru tidak lagi menulis linked saving | Mengurangi saldo dan menghapus jurnal tabungan yang berasal dari pembayaran. | One-way cleanup yang repeatable setelah baris habis. Membatalkan diri bila saldo tidak cukup, tetapi rollback produksi tetap melalui backup. |
| 19 | `add_modular_global_reports.sql` | Tahap 05, 06, 08, 11, 13/14, 15/16 | Menambah master rombel, snapshot kelas/tarif, tagihan Biaya Lain, index laporan, relasi, dan backfill context. Diletakkan terakhir karena membaca seluruh model sebelumnya. | Sebagian besar metadata-guarded/upsert; jalankan preflight bawaan dan pastikan seluruh output pascamigrasi nol. |

Setelah tahap 19, jalankan kedua verifier. Jangan menambahkan migrasi baru di tengah urutan tanpa mencatat dependency input/output-nya dan memperbarui harness.

`add_mutation_idempotency.sql` tidak mengisi baris untuk transaksi lama. Pada upgrade berhistori, `mutation_request` dimulai kosong dan hanya menerima klaim dari request baru setelah aplikasi versi yang mendukung key dipasang. Replay histori lama tidak dapat dibuktikan dan tetap ditangani sebagai legacy/rekonsiliasi manual.

## Preflight wajib pada salinan database

1. Catat commit/tag aplikasi, versi MariaDB/PHP, `sql_mode`, timezone, isolation level, engine, collation, ukuran database, dan jumlah baris seluruh tabel.
2. Buat full logical backup dan buktikan restore ke nama database berbeda. Backup yang belum diuji restore tidak dianggap rollback plan.
3. Pastikan tidak ada tabel MyISAM pada domain transaksi dan tidak ada FK yatim.
4. Jalankan pemeriksaan konflik yang dibutuhkan migrasi: kelas siswa invalid, NIS Diknas duplikat, master DU duplikat, pembayaran tanpa siswa, DU tanpa konteks, detail Biaya Lain tanpa master, SPP tanpa claim, dan linked saving melebihi saldo.
5. Simpan output `verify_schema.sql` dan `verify_data_integrity.sql`. Pada baseline lama status MISSING/FAIL boleh menjadi alasan migrasi, tetapi setiap baris harus mempunyai disposisi tertulis.
6. Tentukan kebijakan tiga area yang tidak aman ditebak: claim SPP legacy, cache biaya awal versus saldo pembuka, dan operator teks legacy.
7. Pastikan akun migrasi memiliki privilege DDL/`TRIGGER` yang diperlukan untuk membuat proteksi append-only; akun runtime aplikasi tidak boleh mempunyai privilege untuk mengubah atau menghapus `audit_event` di luar kontrak yang disetujui.
8. Hentikan aplikasi/cron penulis, tutup sesi kasir, dan catat cut-off transaksi sebelum upgrade produksi.

## Postflight dan release gate

- Jalankan `verify_schema.sql`; seluruh requirement harus `OK`.
- Jalankan `verify_data_integrity.sql`; seluruh INV harus `PASS`. Antrean legacy `REVIEW` memerlukan sign-off dan pembatasan mutasi yang terdokumentasi.
- Bandingkan jumlah baris, total nominal per komponen, saldo tabungan, total DU, dan total Biaya Lain dengan baseline preflight.
- Pastikan setiap `bayar.U_SPP > 0` mempunyai claim periode yang cocok dan laporan status/tahunan sama dengan query ledger.
- Pastikan `audit_event` beserta tiga index, tiga JSON check, dua trigger append-only, dan kolom snapshot actor tanpa FK tersedia. Negative test update/delete harus ditolak; create/update/delete finansial yang didukung harus menghasilkan event actor/request/reason/before/after yang tepat dan ikut rollback bila transaksi bisnis gagal.
- Jalankan regression test pada database salinan, lalu smoke test web/PDF/Excel sebagai admin, bendahara, kasir, dan tanpa session.
- Uji dua sesi konkurensi untuk input/edit/hapus pembayaran dan tabungan; deadlock harus rollback penuh.
- Simpan bukti command, exit code, output verifier, fingerprint, timestamp, operator migrasi, dan keputusan rekonsiliasi.

## Rollback dan recovery

Tidak ada down migration otomatis karena perubahan mencakup DDL, backfill, normalisasi, dan cleanup one-way. Prosedur recovery yang didukung:

1. Jangan menulis transaksi baru setelah postflight gagal.
2. Pertahankan database gagal sebagai bukti dengan akses read-only.
3. Restore backup tervalidasi ke database baru; jangan menimpa database gagal di tempat.
4. Jalankan verifier pada hasil restore dan bandingkan fingerprint/count dengan preflight.
5. Arahkan konfigurasi aplikasi kembali ke database hasil restore, restart service bila perlu, lalu lakukan smoke test.
6. Catat root cause dan revisi migrasi/harness sebelum percobaan berikutnya.

Pada database disposable yang dibuat harness, rollback berarti `DROP DATABASE` hanya setelah nama schema cocok dengan identitas yang dibuat proses tersebut.

## Harness migration matrix

`tests/support/run_migration_matrix.ps1` melakukan urutan berikut:

1. Menolak nama selain pola `db_spp_audit_migration_YYYYMMDD_HHMMSS_PID_RANDOM` dan secara eksplisit menolak `db_spp`.
2. Menolak reuse database yang sudah ada.
3. Mengganti target `CREATE DATABASE/USE db_spp` di memory saja; file SQL sumber tidak diubah.
4. Membuat database unik, menjalankan fresh schema, schema verifier, dan 17 invariant data.
5. Menjalankan 19 migrasi kanonik dua kali.
6. Menjalankan verifier setelah setiap pass dan membandingkan SHA-256 dump schema+data logis pass pertama versus kedua. Nilai allocator `AUTO_INCREMENT` dinormalisasi untuk perbandingan logis, sementara fingerprint dump mentah tetap dihitung dan drift-nya dilaporkan.
7. Membersihkan hanya database yang dibuat proses dan lolos pemeriksaan identitas; `-KeepAuditDatabase` mempertahankannya untuk forensik.
8. Menghentikan eksekusi bila menemukan file migrasi baru yang belum tercantum di manifest.

Contoh lokal XAMPP dengan root tanpa password yang memang sengaja dikonfigurasi hanya untuk mesin developer:

```powershell
powershell -ExecutionPolicy Bypass -File tests\support\run_migration_matrix.ps1 -AllowEmptyPassword
```

Dengan credential admin audit melalui environment process:

```powershell
$env:SPP_AUDIT_ADMIN_DB_PASS = '<secret-lokal>'
powershell -ExecutionPolicy Bypass -File tests\support\run_migration_matrix.ps1 -AdminUser '<admin-audit>'
```

Password diteruskan ke child process melalui `MYSQL_PWD` dan dipulihkan setelah harness selesai; nilainya tidak ditulis ke repository atau command line. Gunakan akun lifecycle khusus yang hanya berwenang membuat/menghapus database berprefix audit pada server lokal/CI.

### Harness failure/recovery

`tests/support/run_migration_failure_recovery.ps1` membuat database disposable `db_spp_audit_failure_*`, memasang schema fresh, lalu untuk setiap 19 migrasi menjalankan salinan dengan satu statement SQL invalid setelah migrasi. Exit non-zero wajib terjadi; migrasi asli kemudian dijalankan ulang dan seluruh verifier schema/data harus lulus. Run `migration-failure-20260826_191000` menghasilkan 19/19 recovery PASS dan database dihapus oleh cleanup guard. Skenario ini membuktikan rerun pasca-error setelah statement migrasi selesai, bukan rollback DDL, failpoint di tengah statement, deadlock/retry, atau recovery pada histori produksi.

## Gap dokumentasi dan coverage yang masih terbuka

- Urutan upgrade lama di `PROJECT_CONTEXT.md` hanya memuat sebagian migrasi dan melewatkan annual claim, cicilan SPP, aktivasi legacy, sinkron cache, cleanup linked saving, serta laporan modular. Manifest ini harus dirujuk sebagai sumber kanonik saat dokumentasi induk diperbarui.
- Belum ada fixture anonymized untuk setiap generasi schema legacy. Matrix saat ini membuktikan fresh schema menerima paket migrasi dan pass kedua tidak mengubah fingerprint schema+data logis, tetapi belum membuktikan upgrade seluruh bentuk database historis.
- Repeat `INSERT IGNORE` pada rangkaian academic billing dapat mengonsumsi allocator `AUTO_INCREMENT` walau tidak menambah/mengubah baris. Harness memperlakukan ini sebagai raw metadata drift yang terlihat, bukan perubahan data logis; monitoring kapasitas ID tetap diperlukan bila migrasi sering diulang.
- `schema.sql` mengandung tahun ajaran/data demo bertanggal tetap. Di luar periode tersebut, INV-014 dapat gagal secara benar; provisioning fresh schema perlu dipisahkan dari fixture demo.
- DDL implicit commit berarti kegagalan di tengah migrasi tidak dapat dipulihkan dengan `ROLLBACK` SQL biasa.
- MariaDB yang lebih baru, MySQL 8, Linux case-sensitive table names, dan PHP target berbeda belum masuk matrix lintas platform.
- Migrasi security, audit finansial, dan operator telah diklasifikasikan berdasarkan bentuk file saat manifest dibuat. Perubahan lanjutan pada kontraknya atau migrasi security/audit baru tetap harus memicu inventory guard dan review dependency.
- Matrix kanonik 19 migrasi (termasuk `add_financial_audit_log.sql` dan `add_mutation_idempotency.sql`) sudah lulus fresh schema, dua pass, verifier schema/data, fingerprint logis, serta cleanup guard pada 2026-08-26. Harness failure/recovery menambah bukti rerun 19/19 setelah error sintetis pasca-migrasi; privilege deployment ke target client, upgrade dari seluruh bentuk histori, failpoint di tengah statement, deadlock/retry, dan failed-midway recovery produksi tetap merupakan gate deployment terpisah.
