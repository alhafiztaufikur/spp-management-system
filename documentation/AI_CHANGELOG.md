# Riwayat Perubahan AI SistemSPP

File ini mencatat perubahan proyek secara reverse chronological. Baca [PROJECT_CONTEXT.md](./PROJECT_CONTEXT.md) terlebih dahulu untuk memahami arsitektur, aturan bisnis, dan kewajiban dokumentasi.

## Aturan Pencatatan

- Tambahkan entri terbaru tepat di bawah bagian ini.
- Gunakan tanggal lokal proyek (`Asia/Jakarta`) dengan format `YYYY-MM-DD`.
- Satu entri boleh mencakup satu paket perubahan yang dikerjakan dan diverifikasi bersama.
- Sebutkan AI/aktor, tujuan, perubahan perilaku, database/migrasi, kompatibilitas, dan verifikasi.
- Tulis `Tidak ada` bila suatu bagian memang tidak memiliki perubahan.
- Jangan mencantumkan data siswa nyata, password, token, cookie, atau secret.
- Jangan menghapus atau menulis ulang entri lama. Tambahkan entri koreksi bila diperlukan.
- Perubahan implementasi dan entri changelog wajib masuk commit yang sama.

## 2026-08-27 - Konfirmasi Credential Database Berbagi Secret

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memverifikasi apakah akun database runtime lokal memakai credential yang terpisah sebelum deployment.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime atau source. Pemeriksaan read-only terhadap metadata `mysql.user` menemukan `spp_app_local` dan `spp_audit_local` memiliki fingerprint credential non-kosong yang identik; nilai credential sengaja tidak dicetak.

**Database dan migrasi:** Tidak ada migrasi, DDL, perubahan grant, atau mutasi data.

**Kompatibilitas dan data lama:** Tidak ada perubahan perilaku aplikasi. Temuan dicatat sebagai gap deployment `DBSEC-004`; target wajib memakai secret acak yang berbeda untuk akun aplikasi dan akun audit.

**Verifikasi:** Query fingerprint boolean pada `mysql.user` menghasilkan `SAME_NONEMPTY`; tidak ada hash/password, cookie, atau token yang masuk log/repository.

**Catatan tindak lanjut:** Rotasi kedua credential secara terkoordinasi melalui secret store target, perbarui konfigurasi, lalu ulangi inventory grant dan regression. Ini tetap di luar scope perubahan kode saat ini.

## 2026-08-27 - Sinkronisasi Register dan Manifest Evidence DBSEC-004

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menjaga laporan completion, manifest release, dan register keamanan tetap menunjuk commit runtime/evidence yang benar setelah temuan credential lokal dicatat.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. `COMPLETION_AUDIT.md`, `RELEASE_MANIFEST.md`, `EXECUTIVE_REPORT.md`, `SECURITY_FINDINGS.md`, dan `DEPENDENCY_DEPLOYMENT_REPORT.md` kini menyebut `DBSEC-004`, source evidence `2c88ab0`, serta status NO-GO dan rotasi target secara konsisten.

**Database dan migrasi:** Tidak ada. Pemeriksaan dan perubahan hanya dokumentasi.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi, schema, grant, atau data.

**Verifikasi:** `git diff --check` dan pemeriksaan status worktree dijalankan; postflight port audit dan database suite tetap kosong. Nilai credential tidak dicetak.

**Catatan tindak lanjut:** Regenerasi checksum/manifest pada tag release final setelah secret store, host HTTPS, dan gate client tersedia.

## 2026-08-27 - Rekonsiliasi Inventory Manifest Route dan SQL

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan angka dan daftar artefak pada manifest aplikasi mencerminkan filesystem/worktree saat ini.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. `APPLICATION_MANIFEST.md` diselaraskan menjadi 63 PHP tracked, 25 SQL, 8 asset, 29 route produksi, 11 helper include, dan 18 test PHP; pemeriksaan read-only mencocokkan seluruh route dengan filesystem.

**Database dan migrasi:** Tidak ada. Daftar migrasi hanya dibandingkan dengan `MIGRATION_MANIFEST.md`; database tidak disentuh.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi atau data. Baseline historis tetap dipertahankan dan dibedakan dari inventory terkini.

**Verifikasi:** Enumerasi filesystem menghasilkan `ROUTE_FILES=29`, `SQL_FILES=25`, `INCLUDE_PHP=11`, `TEST_PHP=18`, dan `git ls-files '*.php'=63`; seluruh tautan relatif dokumentasi diperiksa tanpa broken link.

**Catatan tindak lanjut:** Regenerasi inventory/checksum sekali lagi pada tag release final dan setelah perubahan arsitektur.

## 2026-08-27 - Retest Static dan Coverage Gate

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mengulang pemeriksaan sintaks, kontrak keamanan route, dan pemetaan test setelah sinkronisasi dokumentasi audit.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime atau source.

**Database dan migrasi:** Tidak ada; semua pemeriksaan bersifat read-only.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi maupun data.

**Verifikasi:** Lint 64 file PHP PASS, `node --check assets/js/app.js` PASS, route contract `29/29` PASS, coverage `40/40` terpetakan (13 PASS, 0 FAIL, 22 NOT TESTED, 5 PENDING DECISION), dan `git diff --check` PASS.

**Catatan tindak lanjut:** Coverage parsial dan gate target client tetap tidak boleh dipromosikan menjadi status GO; ulangi pada tag release dan environment target.

## 2026-08-27 - Retest Scanner Secret Histori Git

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memeriksa ulang histori Git setelah penambahan catatan audit, dengan output metadata saja.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Scanner membaca 79 commit dan 412 kandidat revisi pada 12 path; nilai kandidat sengaja tidak pernah dicetak. Konfigurasi contoh saat ini hanya memakai placeholder secret.

**Database dan migrasi:** Tidak ada; pemeriksaan tidak menyentuh database.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi. Kandidat historis tetap memerlukan triage dan rotasi out-of-band.

**Verifikasi:** `tests/support/scan_git_history_secrets.ps1` selesai tanpa error dan hanya mengeluarkan metadata path; `git diff --check` tetap lulus.

**Catatan tindak lanjut:** Jalankan kembali pada tag final, review kandidat dengan owner, dan rotasi seluruh credential yang pernah aktif sebelum deployment.

## 2026-08-27 - Retest Verifier Integritas Snapshot Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan pembaruan dokumentasi tidak diikuti perubahan tak sengaja pada snapshot audit.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime atau source.

**Database dan migrasi:** Tidak ada migrasi. `run_data_verifier.ps1` dijalankan read-only pada `db_spp_audit_20260820_090000` dengan flag eksplisit untuk instance lokal tanpa password.

**Kompatibilitas dan data lama:** Tidak ada perubahan data, saldo, jurnal, pembayaran, atau histori legacy.

**Verifikasi:** Probe database eksplisit, `INVARIANT_ROWS=17`, `INVARIANT_FAIL=0`, dan `DATA_INTEGRITY=PASS`.

**Catatan tindak lanjut:** Jalankan ulang verifier dengan credential target non-kosong setelah backup/migrasi deployment disetujui.

## 2026-08-27 - Pembaruan Completion Audit dan Gate Deployment

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menyelaraskan completion audit dengan inventory, scanner secret, verifier, dan temuan credential terbaru.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. `COMPLETION_AUDIT.md` menambahkan bukti inventory 29 route/25 SQL/18 test, scanner metadata-only 79 commit/412 kandidat, serta referensi gap `DBSEC-004`.

**Database dan migrasi:** Tidak ada; verifier snapshot tetap read-only.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi atau data. Status serah-terima tetap NO-GO sampai gate target/client dan keputusan owner selesai.

**Verifikasi:** Completion audit ditinjau ulang terhadap `RENCANA_AUDIT_FINAL_SISTEMSPP.md`; seluruh bukti baru hanya berasal dari command read-only yang dicatat pada execution log.

**Catatan tindak lanjut:** Jangan menerbitkan tag produksi sebelum seluruh baris PARSIAL/BELUM DIUJI/PENDING CLIENT memperoleh evidence target atau risk acceptance tertulis.

## 2026-08-27 - Prosedur Rotasi Credential DB pada Runbook

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menyediakan langkah operasional yang dapat diulang untuk menutup gap `DBSEC-004` tanpa mencatat secret.

**Perubahan fitur dan perilaku:** `OPERATIONS_RUNBOOK.md` menambahkan prosedur dua-person untuk membuat secret berbeda, memperbarui account/config, memeriksa grant/host, menjalankan smoke dan regression, lalu mencabut secret lama.

**Database dan migrasi:** Tidak ada migrasi atau perubahan database; prosedur belum dieksekusi pada target.

**Kompatibilitas dan data lama:** Tidak ada perubahan runtime/data.

**Verifikasi:** Review dokumen memastikan langkah melarang command history/log/repository secret dan mewajibkan probe `SHOW GRANTS`, verifier, serta regression setelah rotasi.

**Catatan tindak lanjut:** Infra/DBA dan pemilik sistem harus menjalankan prosedur pada target client dan mencatat hasil tersanitasi sebelum GO.

## 2026-08-27 - Sinkronisasi PROGRESS dengan Temuan Credential

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan ringkasan progres utama menampilkan status `DBSEC-004` dan batasan rotasi credential target.

**Perubahan fitur dan perilaku:** `documentation/PROGRESS.md` diperbarui untuk menautkan bukti grant stale, credential reuse lokal, dan kewajiban secret terpisah pada target.

**Database dan migrasi:** Tidak ada; hanya dokumentasi.

**Kompatibilitas dan data lama:** Tidak ada perubahan runtime atau data.

**Verifikasi:** Review silang terhadap execution log, dependency report, runbook, completion audit, dan security findings; `git diff --check` dijalankan.

**Catatan tindak lanjut:** Pertahankan status NO-GO sampai rotasi target, verifikasi grant, dan gate deployment/client selesai.

## 2026-08-27 - Postflight Dokumentasi dan Environment Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan paket dokumentasi dan environment audit tetap bersih setelah rangkaian pembaruan.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Pemeriksaan tautan Markdown, status Git, port audit, dan database suite dilakukan read-only.

**Database dan migrasi:** Tidak ada migrasi; snapshot audit dipertahankan dan tidak dimutasi.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi atau data.

**Verifikasi:** 28 Markdown/12 tautan relatif tanpa broken link; worktree bersih; `git diff --check` PASS; port 8099/8100/8133 tidak listening; disposable suite database tersisa 0.

**Catatan tindak lanjut:** Ulangi postflight pada tag final dan host client; status NO-GO tetap sampai gate eksternal selesai.

## 2026-08-27 - Retest Dependency Composer

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan dependency lock dan platform requirement tetap valid pada worktree saat ini.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime atau dependency.

**Database dan migrasi:** Tidak ada; database tidak disentuh.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi/data.

**Verifikasi:** `composer validate --strict` PASS, `composer audit --locked --no-dev` melaporkan 0 advisory, dan `composer check-platform-reqs --no-dev` PASS (PHP 8.3.31; dom/gd/iconv/mbstring tersedia).

**Catatan tindak lanjut:** Ulangi pada tag/package final dan Apache target; hasil lokal tidak menggantikan verifikasi platform client.

## 2026-08-27 - Sinkronisasi Bukti Dependency pada Completion Report

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menyelaraskan completion audit, executive report, dan PROGRESS dengan retest Composer serta postflight terbaru.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Tiga dokumen status menautkan `DEP-RECHECK-001` dan `POSTFLIGHT-002` sebagai bukti terbaru.

**Database dan migrasi:** Tidak ada.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi/data.

**Verifikasi:** Review silang terhadap execution log; `git diff --check` lulus.

**Catatan tindak lanjut:** Bukti dependency lokal tetap tidak menggantikan verifikasi host/client target.

## 2026-08-27 - Retest Web Containment Lokal

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan aturan deny artefak internal tetap aktif setelah pembaruan dokumentasi.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. HTTP smoke read-only memeriksa login dan lima path internal.

**Database dan migrasi:** Tidak ada; database tidak disentuh.

**Kompatibilitas dan data lama:** Login tetap HTTP 200; path internal tetap ditolak.

**Verifikasi:** `login.php` 200; `.git/HEAD`, `sql/schema.sql`, `tests/security_regression_test.php`, `documentation/PROJECT_CONTEXT.md`, dan `config/app.local.php` masing-masing 403.

**Catatan tindak lanjut:** Ulangi containment pada HTTPS/virtual host target dan jangan menjadikan smoke lokal sebagai bukti deployment.

## 2026-08-27 - Retest Ketersediaan Browser UAT (Keenam)

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memeriksa kembali ketersediaan browser resmi untuk menyelesaikan gate UAT/accessibility.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Setup browser berhasil, tetapi discovery mengembalikan daftar kosong; troubleshooting resmi dibaca sesuai prosedur.

**Database dan migrasi:** Tidak ada; tidak ada tab, cookie, session store, atau database yang disentuh.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi/data.

**Verifikasi:** `agent.browsers.list()` menghasilkan `[]`; tidak ada browser/tab yang dapat dipilih atau sesi UAT yang dibuat.

**Catatan tindak lanjut:** Sediakan browser runtime/in-app browser atau host client. Jangan mengganti bukti UAT dengan HTTP smoke/standalone automation.

## 2026-08-27 - Sinkronisasi PROGRESS Browser Blocker

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menyelaraskan ringkasan progres dengan retest browser resmi terbaru.

**Perubahan fitur dan perilaku:** `documentation/PROGRESS.md` menambahkan bukti `UI-BROWSER-006` dan mempertahankan status UAT sebagai blocker environment.

**Database dan migrasi:** Tidak ada.

**Kompatibilitas dan data lama:** Tidak ada perubahan runtime/data.

**Verifikasi:** Review silang execution log; discovery browser tetap `[]` dan tidak ada sesi/tab dibuat.

**Catatan tindak lanjut:** Jalankan UAT penuh hanya setelah runtime browser atau host client tersedia.

## 2026-08-27 - Snapshot Triage Secret Histori Terbaru

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan penambahan dokumentasi audit tidak memperkenalkan path kandidat secret baru.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Scanner metadata-only memeriksa 90 commit/511 kandidat pada 12 path; path review tetap sama, dan nilai kandidat tidak dicetak.

**Database dan migrasi:** Tidak ada.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi/data. Kenaikan hit diperlakukan sebagai noise checksum/teks audit sampai triage manual, bukan bukti secret aktif.

**Verifikasi:** `tests/support/scan_git_history_secrets.ps1` selesai tanpa error; output hanya commit/path metadata.

**Catatan tindak lanjut:** Ulangi pada tag final, triage owner, dan rotasi credential historis sebelum deployment.

## 2026-08-27 - Static Source Credential dan SQL Sink Scan

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mencari indikasi baru default credential/MD5 runtime atau query yang menggabungkan request superglobal secara langsung.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Scan source mengecualikan dokumentasi dan vendor; hasil hanya kontrol yang diharapkan dan tidak ada sink query langsung.

**Database dan migrasi:** Tidak ada.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi/data.

**Verifikasi:** `rg` source scan selesai tanpa temuan baru; placeholder config, hashing modern, marker MD5 legacy, dan fixture test acak diklasifikasikan sebagai expected. Heuristik dicatat sebagai bukti terfokus, bukan DAST penuh.

**Catatan tindak lanjut:** Jalankan secret scan CI dan DAST Cartesian pada target release/client.

## 2026-08-27 - Checker Kelengkapan Artefak Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mencegah artefak audit, route, SQL, atau migrasi baru terlewat dari paket handover.

**Perubahan fitur dan perilaku:** Menambahkan `tests/support/verify_audit_artifacts.ps1`, pemeriksaan read-only yang memvalidasi 22 file wajib, 29 route PHP, 25 SQL, 63 PHP tracked, dan seluruh migrasi `add_*.sql` terhadap manifest.

**Database dan migrasi:** Tidak ada; checker tidak membuka atau memutasi database.

**Kompatibilitas dan data lama:** Tidak ada perubahan runtime/data.

**Verifikasi:** Checker menghasilkan `REQUIRED_AUDIT_ARTIFACTS=22`, `MISSING_AUDIT_ARTIFACTS=0`, `ROUTE_FILES=29`, `SQL_FILES=25`, `TRACKED_PHP_FILES=63`, `MIGRATIONS_MISSING_FROM_MANIFEST=0`, `AUDIT_ARTIFACT_CHECK=PASS`.

**Catatan tindak lanjut:** Jalankan checker pada setiap tag release dan setelah perubahan route, migrasi, atau dokumentasi.

## 2026-08-27 - Sinkronisasi Release Manifest dengan Checker Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan manifest release menyebut tooling kelengkapan handover yang baru ditambahkan.

**Perubahan fitur dan perilaku:** `RELEASE_MANIFEST.md` kini mencatat `verify_audit_artifacts.ps1` (commit `a1d4dbe`) sebagai tooling bundle audit terbatas, bukan runtime publik.

**Database dan migrasi:** Tidak ada.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi/data.

**Verifikasi:** Review silang manifest dengan file aktual dan run `AUDIT_ARTIFACT_CHECK=PASS`; `git diff --check` lulus.

**Catatan tindak lanjut:** Jalankan checker lagi pada tag final dan setelah perubahan artefak handover.

## 2026-08-27 - Tambahan Status Checker pada PROGRESS

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Membuat hasil checker handover terlihat pada ringkasan progres utama.

**Perubahan fitur dan perilaku:** `documentation/PROGRESS.md` menambahkan hasil `verify_audit_artifacts.ps1` dan aturan menjalankannya pada setiap tag release.

**Database dan migrasi:** Tidak ada.

**Kompatibilitas dan data lama:** Tidak ada perubahan runtime/data.

**Verifikasi:** Nilai dicocokkan dengan run checker (`22` artefak, `29` route, `25` SQL, `63` PHP, migrasi missing `0`, status PASS); `git diff --check` lulus.

**Catatan tindak lanjut:** Pertahankan checker sebagai gate handover sebelum release final.

## 2026-08-27 - Probe Time-based SQLi Terfokus

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memperluas corpus keamanan dengan pemeriksaan keterlambatan injeksi pada route read-only yang memakai filter laporan/riwayat.

**Perubahan fitur dan perilaku:** `tests/security_input_corpus_test.php` kini mengukur latency request dan mengirim payload `SLEEP(3)` sebagai nilai literal pada empat route read-only; batas latency dibandingkan dengan baseline route yang sama.

**Database dan migrasi:** Tidak ada migrasi. Pengujian berjalan pada clone disposable `db_spp_audit_20260827_034249_suite_4017` yang kemudian dihapus; `db_spp` dan snapshot audit tidak disentuh.

**Kompatibilitas dan data lama:** Perilaku filter aplikasi tidak berubah; payload tidak menghasilkan SQL error, HTTP 500, atau delay injeksi.

**Verifikasi:** Regression disposable `REG-FINAL-008` lulus 18/18 (`FAILURE_COUNT=0`), termasuk probe time-based baru pada field filter yang tepat. Dump, log server, dan suite log mentah dipurge; ringkasan tersanitasi dipertahankan di luar repository.

**Catatan tindak lanjut:** Probe ini tetap terfokus dan tidak menggantikan DAST Cartesian penuh, mutating-route/failpoint, browser sink, atau validasi target client.

## 2026-08-27 - Validasi Tautan Dokumentasi Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan paket dokumentasi audit tidak memiliki tautan relatif yang rusak setelah rangkaian pembaruan evidence.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; hanya menambahkan catatan evidence `DOC-LINK-001` pada execution log.

**Database dan migrasi:** Tidak ada. Pemeriksaan bersifat read-only dan tidak menyentuh `db_spp` maupun snapshot audit.

**Kompatibilitas dan data lama:** Tidak ada perubahan perilaku aplikasi atau data.

**Verifikasi:** 28 file Markdown di bawah `documentation/` diperiksa; seluruh tautan relatif valid (`BROKEN_RELATIVE_LINKS=0`) dan 14 artefak audit wajib ditemukan.

**Catatan tindak lanjut:** Pemeriksaan tautan tidak menggantikan review isi, approval client, atau validasi target deployment.

## 2026-08-27 - Retest Ketersediaan Browser UAT

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memverifikasi ulang apakah runtime Browser resmi tersedia untuk memenuhi gate UAT frontend/accessibility.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; hanya menambahkan evidence `UI-BROWSER-005` pada execution log.

**Database dan migrasi:** Tidak ada. Discovery browser bersifat read-only dan tidak mengakses cookie, session store, atau database.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi atau data.

**Verifikasi:** Discovery resmi mengembalikan daftar browser kosong (`[]`), sehingga tidak ada sesi UAT interaktif yang dapat dijalankan.

**Catatan tindak lanjut:** Gate browser/accessibility, screenshot regression, dan seluruh route/role/state tetap pending sampai runtime resmi atau host client tersedia.

## 2026-08-27 - Hash Paket Runtime Lokal

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menambah bukti checksum dan exclusion artefak internal untuk paket runtime pada release rehearsal lokal.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; evidence `DEP-PACKAGE-001` ditambahkan ke execution log.

**Database dan migrasi:** Tidak ada. Arsip dibuat dari staging lokal tanpa koneksi atau mutasi database.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi/data.

**Verifikasi:** Arsip runtime dari HEAD `8a213641cea540e64e63b3ef6d8c75adb6a12e06` berisi 569 file, tidak memuat artefak terlarang (`FORBIDDEN_ARTIFACTS=0`), dan memiliki SHA-256 `0A4C35521E5213DEEE34B5F7351B08C09D5DF8951DD3C2EC7BC8A965A4D76C82`; staging dihapus setelah pemeriksaan.

**Catatan tindak lanjut:** Hash harus dibuat ulang pada tag/package final dan diverifikasi bersama document root serta signing client.

## 2026-08-27 - Inventory Akun MySQL Pasca-Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan cleanup rehearsal tidak meninggalkan akun MySQL fixture atau akun sementara.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; evidence `DBSEC-003` ditambahkan pada execution log.

**Database dan migrasi:** Query metadata `mysql.user` bersifat read-only; tidak ada data/schema aplikasi yang diubah.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi.

**Verifikasi:** Hanya `spp_audit_local` yang muncul dari namespace audit/test; tidak ada akun `release_runner_*`, `legacy_audit_*`, atau `session_*` tertinggal.

**Catatan tindak lanjut:** Ulangi inventory akun pada host client sebelum dan sesudah rehearsal deployment.

## 2026-08-27 - Bersihkan Grant Schema Audit Stale

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menghapus privilege yang tertinggal pada akun runtime audit agar least-privilege dapat diverifikasi.

**Perubahan fitur dan perilaku:** Grant `spp_audit_local` ke schema disposable lama dicabut secara spesifik; grant aplikasi `spp_app_local` tidak diubah.

**Database dan migrasi:** Tidak ada perubahan tabel/data. Hanya grant akun audit lokal yang disesuaikan; `db_spp` tidak disentuh.

**Kompatibilitas dan data lama:** Runtime aplikasi tidak berubah.

**Verifikasi:** Schema stale tidak ada, revoke berhasil, dan `SHOW GRANTS` akhir membatasi `spp_audit_local` ke `db_spp_audit_20260820_090000`; `spp_app_local` tetap hanya DML pada `db_spp`.

**Catatan tindak lanjut:** Ulangi inventory grant pada host target dan pastikan tidak ada wildcard privilege atau grant option.

## 2026-08-27 - Konfirmasi Gap Redirect HTTPS Lokal

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menguji perilaku HTTP/HTTPS dan cache header lokal sebagai bagian gate deployment.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; evidence `TLS-LOCAL-001` ditambahkan pada execution log.

**Database dan migrasi:** Tidak ada. Smoke `curl` bersifat read-only.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi/data.

**Verifikasi:** HTTPS lokal merespons 200 dengan security/cache headers; HTTP juga 200 tanpa redirect HTTPS dan HSTS tidak dikirim. Artefak internal merespons 403; export anonim 302 dengan `no-store`.

**Catatan tindak lanjut:** Redirect HTTP→HTTPS, sertifikat, dan HSTS harus dikonfigurasi serta diuji pada virtual host target; tidak dipaksakan pada `.htaccess` lokal dev/test.

## 2026-08-27 - Retest Verifier Snapshot Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan hardening/test terbaru tidak mengubah integritas schema atau data snapshot audit.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; evidence `DBPOST-005` ditambahkan pada execution log.

**Database dan migrasi:** Verifier dijalankan read-only terhadap `db_spp_audit_20260820_090000`; tidak ada migrasi atau update.

**Kompatibilitas dan data lama:** Tidak ada perubahan data.

**Verifikasi:** Identity probe cocok; data verifier menghasilkan 17/17 invariant PASS dan schema verifier menghasilkan 87 requirement `OK`.

**Catatan tindak lanjut:** Ulangi verifier pada target client setelah backup dan migrasi produksi disetujui.

## 2026-08-27 - Scan Sink Request dan SQL Statis

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menambah bukti static analysis untuk output request langsung, include/path berbasis input, dan interpolasi SQL pada route produksi.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; evidence `STATIC-006` ditambahkan pada execution log.

**Database dan migrasi:** Tidak ada. Pemeriksaan hanya membaca source.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi atau data.

**Verifikasi:** Heuristik source tidak menemukan output langsung dari superglobal request, include/file sink berbasis request, atau SQL yang menggabungkan superglobal secara langsung. Dynamic SQL yang terdeteksi memakai cast/whitelist/placeholder.

**Catatan tindak lanjut:** Hasil ini terfokus dan tidak menggantikan DAST Cartesian, browser sink, atau pengujian renderer pada target.

## 2026-08-27 - Koreksi Hasil Scanner Secret Historis

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan hasil scanner secret terbaru mencakup commit hardening password fixture yang baru dibuat.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; evidence `SECRET-004` ditambahkan pada execution log.

**Database dan migrasi:** Tidak ada. Scanner bersifat metadata-only dan read-only.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi atau data.

**Verifikasi:** Scanner pada HEAD `2c88ab0` memeriksa 67 commit dan 304 kandidat revisi pada 12 path review; output tidak mencetak nilai credential dan tidak menemukan path baru di luar daftar triage.

**Catatan tindak lanjut:** Kandidat historis/default tetap harus ditriase dan credential target dirotasi sebelum deployment.

## 2026-08-27 - Hilangkan Password Fixture Statis pada Test

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menghapus kredensial fixture tetap dari source test agar test disposable tidak menyimpan password yang dapat digunakan ulang.

**Perubahan fitur dan perilaku:** `tests/session_lifecycle_test.php` dan `tests/security_regression_test.php` kini menghasilkan password fixture acak dengan `random_bytes` setiap run; alur assertion tidak berubah.

**Database dan migrasi:** Tidak ada migrasi. Regression berjalan pada clone disposable dan database utama tidak disentuh.

**Kompatibilitas dan data lama:** Tidak ada perubahan aplikasi produksi; hash MD5 pada test keamanan tetap hanya fixture legacy sintetis untuk menguji guard.

**Verifikasi:** PHP lint kedua file lulus; `REG-FINAL-009` menjalankan 18/18 test dengan `FAILURE_COUNT=0`, clone/server dibersihkan. Scanner metadata-only memeriksa 66 commit dan 295 kandidat tanpa mencetak nilai password.

**Catatan tindak lanjut:** Kandidat credential historis/default yang terdeteksi scanner tetap memerlukan triage dan rotasi oleh owner/infra sebelum deployment.

## 2026-08-27 - Retest PDF Dokumentasi Operasional

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memverifikasi ulang PDF SOP dan flowchart terhadap gate render/inspeksi visual dokumentasi.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime atau isi PDF; evidence `DOC-PDF-002` ditambahkan pada execution log.

**Database dan migrasi:** Tidak ada. Pemeriksaan PDF bersifat read-only.

**Kompatibilitas dan data lama:** Hash kedua PDF tetap sama dengan `DOC-PDF-001`.

**Verifikasi:** SOP 12 halaman dan flowchart 4 halaman berhasil diparse dan dirender dengan PyMuPDF; seluruh halaman memiliki teks nonkosong, tidak ada placeholder umum, dan inspeksi visual contact sheet tidak menunjukkan halaman kosong, clipping, atau overlap yang terlihat. Intermediate PNG QA dihapus setelah pemeriksaan.

**Catatan tindak lanjut:** Pemeriksaan bahasa dan pencetakan pada viewer/printer client tetap menjadi gate UAT.

## 2026-08-27 - Regression Disposable Final pada HEAD Terkini

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan sinkronisasi username pada login dan revalidasi sesi tidak merusak regression suite atau integritas database audit.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime pada sesi ini; hanya verifikasi ulang terhadap source yang sudah committed.

**Database dan migrasi:** Clone disposable `db_spp_audit_20260827_033642_suite_4402` dibuat dari snapshot audit, diuji, lalu dihapus. `db_spp` dan snapshot audit tidak dimutasi.

**Kompatibilitas dan data lama:** 18/18 test lulus, termasuk payment/savings, audit append-only, session lifecycle, input corpus, laporan/export, dan legacy guard. Fixture bisnis dibersihkan sebelum clone di-drop.

**Verifikasi:** `REG-FINAL-006` menghasilkan `TEST_COUNT=18`, `FAILURE_COUNT=0`, `REGRESSION_SUITE=PASS`; PHP lint 64 file, Node syntax, Composer checks, route contract, coverage matrix, data verifier 17/17, schema verifier 87/87, dan `git diff --check` juga lulus. Bukti mentah dipurge; ringkasan tersanitasi disimpan di luar repository.

**Catatan tindak lanjut:** DAST penuh, browser/UAT client, failpoint/deadlock, target HTTPS/backup/ACL, serta keputusan owner tetap terbuka sehingga status serah-terima tetap NO-GO.

## 2026-08-27 - Sinkronisasi Inventaris Test Wave 7

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memisahkan jumlah test pada snapshot baseline dari inventaris test current agar dokumentasi dead-code tidak lagi menyiratkan hanya delapan script yang tersedia.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. `DEAD_CODE_INVENTORY.md` kini menandai delapan script sebagai hitungan baseline dan mencatat 18 test current yang semuanya guarded/test-only.

**Database dan migrasi:** Tidak ada mutasi database.

**Kompatibilitas dan data lama:** Histori baseline dipertahankan; tidak ada file test yang dihapus.

**Verifikasi:** Inventory source menunjukkan 18 test PHP guarded; regression disposable `REG-FINAL-005` tetap 18/18 PASS.

**Catatan tindak lanjut:** Runtime coverage dan persetujuan owner masih dibutuhkan sebelum kandidat dead code dihapus.

## 2026-08-27 - Normalisasi ID Temuan PROGRESS

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menghilangkan duplikasi ID temuan pada ringkasan progres sesuai aturan audit.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Dua baris historis yang sebelumnya memakai `PAY-006` dan `PAY-007` kedua kalinya diberi ID unik `PAY-015` dan `PAY-016`.

**Database dan migrasi:** Tidak ada mutasi database atau migrasi.

**Kompatibilitas dan data lama:** Isi temuan dan status tetap sama; hanya identifier yang dinormalisasi.

**Verifikasi:** Parser ID pada `documentation/PROGRESS.md` menemukan 34 ID dan `DUPLICATE_IDS=0`; `git diff --check` lulus.

**Catatan tindak lanjut:** Pertahankan pemeriksaan uniqueness ID setiap pembaruan progres.

## 2026-08-27 - Benchmark Laporan Berulang

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menambah bukti latency bounded tanpa menyamarkan keterbatasan ukuran dataset.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime.

**Database dan migrasi:** 70 query/report build read-only pada snapshot audit; tidak ada mutasi database.

**Kompatibilitas dan data lama:** Tidak ada perubahan data aplikasi.

**Verifikasi:** 10 pengulangan untuk tujuh template, process failures 0; p95 wall time 1,573–3,094 ms/template dan maksimum 2–4 SELECT. Hasil dicatat sebagai `REP-PERF-002`.

**Catatan tindak lanjut:** Hasil belum merupakan SLA; perlu volume 1×/3×/worst allowed, p99, RAM, rows examined, lock budget, dan persetujuan owner.

## 2026-08-27 - Retest Containment Apache Lokal

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan aturan deny artefak internal benar-benar aktif pada Apache lokal, bukan hanya lulus secara statis.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime pada source; hanya verifikasi HTTP read-only.

**Database dan migrasi:** Tidak ada akses mutasi database.

**Kompatibilitas dan data lama:** Endpoint login publik tetap merespons 200; artefak internal ditolak.

**Verifikasi:** Pada `http://127.0.0.1/spp-management-system`, `/login.php` 200 dengan security headers; `.git`, SQL, tests, documentation, includes, config, `.env`, dan `koneksi.php` 403; test PHP tidak dapat dieksekusi via HTTP. HSTS belum ada karena listener masih HTTP.

**Catatan tindak lanjut:** Ulangi pada virtual host HTTPS client dan validasi certificate/HSTS.

## 2026-08-27 - Perbaikan Harness dan Retest Concurrency

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan runner concurrency tidak gagal hanya karena output `git status` kosong dan mengulang skenario race pada environment disposable.

**Perubahan fitur dan perilaku:** `tests/support/run_concurrency_matrix.ps1` kini membungkus output status worktree sebagai array sebelum membaca `.Count`; tidak ada perubahan perilaku aplikasi.

**Database dan migrasi:** Rerun memakai clone disposable dari snapshot audit dan tidak memutasi `db_spp`; clone/server dibersihkan.

**Kompatibilitas dan data lama:** Tidak ada perubahan data aplikasi.

**Verifikasi:** Retest `CONC-004` menghasilkan 25/25 assertion PASS. Artefak request/cookie/raw fixture dipurge; ringkasan tersanitasi disimpan pada evidence root dengan ACL terbatas.

**Catatan tindak lanjut:** Deadlock/retry, failpoint setiap write, dan concurrent report correction masih membutuhkan test/keputusan tambahan.

## 2026-08-27 - Retest Migration dan Release Rehearsal

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memverifikasi ulang chain migrasi dan clean-package rehearsal setelah commit audit terbaru.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime.

**Database dan migrasi:** `run_migration_matrix.ps1 -AllowEmptyPassword` lulus 19 migrasi pada fresh/pass 1/pass 2 dengan verifier dan fingerprint logis; `run_release_rehearsal.ps1 -AllowEmptyPassword` juga lulus pada database disposable. Tidak ada mutasi pada `db_spp`.

**Kompatibilitas dan data lama:** Tidak ada backfill pada database sumber; database disposable dibersihkan setelah run.

**Verifikasi:** `MIGRATION_MATRIX_STATUS=PASS`, `RELEASE_REHEARSAL=PASS`, schema/data verifier lulus, allowlist package dan deny artefak internal lulus. Hasil dicatat sebagai `DBM-004` dan `DEP-REHEARSAL-009`.

**Catatan tindak lanjut:** Snapshot histori client, HTTPS/ACL target, backup terenkripsi/rollback, PDF/Excel client, UAT, dan sign-off masih terbuka.

## 2026-08-27 - Regression Disposable Pasca-Commit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan source pada commit `a91edab` tetap lulus regression setelah sinkronisasi manifest dan dokumentasi.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime atau schema.

**Database dan migrasi:** Regression memakai clone disposable; database sumber `db_spp_audit_20260820_090000` tidak dimutasi.

**Kompatibilitas dan data lama:** Tidak ada perubahan.

**Verifikasi:** `run_disposable_regression.ps1 -GenerateTemporaryAdmin` lulus 18/18 (`REG-FINAL-005`); postflight menyisakan hanya snapshot audit, seluruh port audit bebas, dan ringkasan tersanitasi tanpa pola secret/cookie/token.

**Catatan tindak lanjut:** Bukti target client, browser UAT, deployment HTTPS, backup terenkripsi/rollback, dan sign-off tetap terbuka.

## 2026-08-27 - Rekonsiliasi Referensi Commit dan Evidence Terbaru

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menyelaraskan manifest dan ringkasan progres dengan commit audit yang sudah dibuat serta regression disposable terbaru.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Manifest aplikasi, release manifest, completion audit, executive report, coverage matrix, dan PROGRESS kini menunjuk commit `dbef925` serta `REG-FINAL-004`; status parsial dan gate eksternal tetap dipertahankan.

**Database dan migrasi:** Tidak ada mutasi database atau migrasi.

**Kompatibilitas dan data lama:** Referensi baseline historis tetap dipertahankan dan diberi konteks; tidak ada backfill atau perubahan data aplikasi.

**Verifikasi:** `git status` bersih setelah commit sebelumnya; ringkasan `REG-FINAL-004` ada dan berisi 18 test dengan failure count 0, source database tidak dimutasi, serta port dilepas. Lint/Node/coverage/route checks tetap PASS.

**Catatan tindak lanjut:** Tag release, allowlist/checksum final, UAT browser/client, dan gate deployment tetap belum disetujui.

## 2026-08-27 - Sinkronisasi Bukti Dokumentasi Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menghapus dua klaim historis yang sudah tidak merepresentasikan source saat ini dan mencatat regression disposable terbaru.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime. Manifest migrasi kini menyatakan schema tidak menanam akun/password default; inventaris dead-code menandai hint credential sebagai historical/remediated; bagian temuan keamanan diberi penanda bahwa line reference rinci merujuk baseline 20 Agustus.

**Database dan migrasi:** Tidak ada mutasi database atau migrasi.

**Kompatibilitas dan data lama:** Histori temuan dipertahankan; tidak ada backfill atau perubahan data aplikasi.

**Verifikasi:** Ringkasan tersanitasi `regression-20260827_025835-current/sanitized-summary.txt` diverifikasi tanpa pola secret/cookie/token dan dengan ACL terbatas; regression disposable 18/18 PASS, source audit tidak berubah, dan seluruh port audit bebas.

**Catatan tindak lanjut:** Gate browser/UAT, deployment client, backup terenkripsi, rollback, dan sign-off owner tetap terbuka.

## 2026-08-27 - Hardening ACL Evidence Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup akses umum yang tidak semestinya ke evidence audit di luar document root.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime aplikasi. Pernyataan cleanup pada register audit dan `KNOWN_LIMITATIONS.md` juga diperjelas agar berlaku pada run yang disebut, bukan klaim bahwa seluruh evidence historis telah kosong.

**Database dan migrasi:** Tidak ada mutasi database atau schema.

**Kompatibilitas dan data lama:** Tidak ada perubahan pada data aplikasi; evidence historis tetap dipertahankan sebagai material terbatas.

**Verifikasi:** ACL root dan 2.767 objek di `C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0` diubah dari inheritance yang memberi `Authenticated Users` hak `Modify` menjadi hanya owner audit, `BUILTIN\\Administrators`, dan `SYSTEM`; root dan child sample diverifikasi ulang (`OPS-003`). Regression disposable terbaru tetap lulus 18/18 dan seluruh port audit bebas.

**Catatan tindak lanjut:** Terapkan ACL, enkripsi, dan retensi yang setara pada host client; evidence lama yang berisi dump/log/cookie harus dimusnahkan terkontrol setelah masa retensi disetujui.

## 2026-08-27 - Retest Ketersediaan Browser UAT

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan status UAT browser didasarkan pada pemeriksaan runtime resmi, bukan asumsi atau pengganti automation yang tidak setara.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime aplikasi.

**Database dan migrasi:** Tidak ada mutasi database atau inspeksi cookie/session.

**Kompatibilitas dan data lama:** Tidak ada perubahan.

**Verifikasi:** Browser skill runtime resmi dicoba ulang; `agent.browsers.list()` mengembalikan daftar kosong dan `getDefault()` tidak menemukan browser. Hasil dicatat sebagai `UI-BROWSER-004`; tidak ada fallback automation atau data mutation.

**Catatan tindak lanjut:** UAT browser seluruh role/route/state, accessibility tree, contrast, screen reader, keyboard, console/network, dan screenshot regression memerlukan runtime browser atau host client yang tersedia.

## 2026-08-27 - Retest Security Session Username

**AI/Aktor:** Codex berbasis GPT-5 / security regression agent

**Tujuan:** Memastikan username sesi menggunakan nilai kanonis dari database setelah login dan saat revalidasi akun, tanpa mengubah hasil hardening keamanan yang sudah lulus.

**Perubahan fitur dan perilaku:**

- `login.php` kini menyimpan `admin_username` dari baris akun yang berhasil diautentikasi.
- `includes/auth.php` menyegarkan `admin_username` dari akun aktif pada setiap revalidasi sesi.
- Tidak ada perubahan pada alur role, revocation, atau audit event selain konsistensi identitas sesi.

**Database dan migrasi:** Tidak ada migrasi baru atau perubahan data baseline.

**Kompatibilitas dan data lama:** Sesi yang valid tetap bekerja; sesi akun yang dihapus atau role-nya berubah tetap direvalidasi oleh kontrol yang sama.

**Verifikasi:** Security regression direct `SEC-REG-FINAL-002` pada `db_spp_audit_20260820_090000` lulus; baseline/after identik (`audit_event=0`, fixture legacy=0, rate-limit rows=0, `session_version=1`), server 8099 dihentikan, serta PHP lint untuk `login.php` dan `includes/auth.php` lulus.

**Catatan tindak lanjut:** Verifikasi HTTPS/HSTS, ACL/credential, browser/UAT, dan pengulangan pada tag/package final tetap terbuka.

## 2026-08-27 - Sinkronisasi Bukti Audit dan Inventaris Worktree

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menjaga register audit tetap sesuai dengan bukti runtime terbaru tanpa menaikkan status lokal menjadi kesiapan produksi.

**Perubahan fitur dan perilaku:**

- Tidak ada perubahan runtime aplikasi pada entri ini. `FULLSEC-011` diperjelas sebagai temuan baseline yang memiliki remediation `audit_event` append-only, actor/reason/before-after/request ID, serta residual retention/approval/monitoring.
- Manifest membedakan artefak Git-tracked dari 6 test PHP dan 1 migrasi SQL untracked di worktree yang belum boleh dianggap bagian tag release.
- Bukti Excel diperluas melalui `REP-XLS-004` (11/11 workbook dibuka read-only pada Excel lokal, formula native 0) dan benchmark laporan bounded `REP-PERF-001`.
- `PROJECT_CONTEXT.md` memperjelas bahwa konteks tagihan legacy boleh dipetakan untuk saldo, tetapi `bayar_du.bayar_id` tidak pernah diisi otomatis; kepemilikan pembayaran tetap legacy sampai rekonsiliasi manual.

**Database dan migrasi:**

- Snapshot audit hanya diberi grant `SELECT` sementara untuk benchmark, kemudian grant dicabut dan diverifikasi. `db_spp` tidak disentuh dan tidak ada migrasi/backfill baru.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan perilaku pembayaran, tabungan, legacy, atau schema. Artefak untracked tetap memerlukan keputusan packaging/commit.

**Verifikasi:**

- Excel COM: 11 file dibuka, formula native total 0.
- Benchmark read-only: tujuh template, 2–4 SELECT, 1.987–3.567 ms/report pada snapshot audit.
- Coverage matrix 40/40, lint helper, dan `git diff --check` lulus.
- Link checker dokumentasi Markdown memeriksa 12 link file relatif tanpa target hilang (`DOC-001`).

**Catatan tindak lanjut:**

- Browser runtime resmi masih mengembalikan daftar kosong; UAT browser penuh, performance budget produksi, client target, dan deployment gates tetap terbuka.

## 2026-08-27 - Klarifikasi Status Kontrak Invariant

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mencegah pembaca menafsirkan kolom level gate pada kontrak data sebagai hasil verifier terbaru.

**Perubahan fitur dan perilaku:**

- `documentation/audit/DATA_CONTRACT.md` kini membedakan tingkat pengendalian invariant dari status observasi suatu run.
- Referensi hasil salinan audit dan queue baseline diarahkan ke `RECONCILIATION_REPORT.md`; tidak ada angka baseline yang dipromosikan sebagai status produksi terkini.

**Database dan migrasi:** Tidak ada perubahan.

**Kompatibilitas dan data lama:** Aturan legacy tetap sama; tidak ada pencocokan atau backfill otomatis.

**Verifikasi:** PHP lint 64 file, Node check, pemeriksaan link dokumentasi, coverage matrix 40/40, route contract, dan `git diff --check` lulus.

**Catatan tindak lanjut:** Hasil verifier produksi/client dan keputusan deployment tetap merupakan gate eksternal yang belum dipenuhi.

## 2026-08-27 - Retest Postflight dan Pembersihan Evidence

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan snapshot audit dan evidence lokal tetap bersih setelah seluruh regression/security run.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime, schema, atau data aplikasi.

**Database dan migrasi:** `verify_schema.sql` read-only pada snapshot audit menghasilkan 87 requirement tanpa missing; `run_data_verifier.ps1` menghasilkan 17/17 invariant PASS. `db_spp` tidak disentuh.

**Kompatibilitas dan data lama:** Tidak ada perubahan; aturan legacy tetap berlaku.

**Verifikasi:** Port audit bebas; 59 artefak cookie/session uji nonvendor di evidence root dihapus dan diverifikasi tersisa 0. Secret-history scanner diulang pada 49 commit/142 kandidat revisi tanpa mencetak nilai. Regression disposable diulang pada clone unik dan lulus 18/18 (`REG-FINAL-003`), release rehearsal package bersih + 19 migrasi lulus (`DEP-REHEARSAL-007`), dan seluruh 18 test PHP/SQL verifier lulus containment guard (`TESTISO-002`); clone/server/fixture/database sementara dibersihkan. Hasil dicatat sebagai `DBPOST-003`, `DATA-FINAL-002`, `OPS-002`, `SECRET-002`, `REG-FINAL-003`, `DEP-REHEARSAL-007`, dan `TESTISO-002`.

**Catatan tindak lanjut:** Retensi/ACL evidence produksi, postflight target client, dan sign-off deployment tetap terbuka.

## 2026-08-27 - Klarifikasi Artefak Runtime dan Handover

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mencegah SQL, tests, dokumentasi internal, atau evidence ikut tersaji pada document root produksi.

**Perubahan fitur dan perilaku:** `RELEASE_MANIFEST.md` kini membedakan paket runtime publik dari handover bundle terbatas. Runtime package wajib mengecualikan `sql/`, `tests/`, `documentation/`, `.git/`, dump, evidence, dan konfigurasi lokal; dokumentasi/source migrasi diserahkan terpisah dengan ACL.

**Database dan migrasi:** Tidak ada perubahan.

**Kompatibilitas dan data lama:** Tidak ada perubahan perilaku aplikasi atau aturan legacy.

**Verifikasi:** Release rehearsal `DEP-REHEARSAL-007` sebelumnya membuktikan exclusion artefak internal pada package staging; link checker dan `git diff --check` lulus setelah klarifikasi.

**Catatan tindak lanjut:** Allowlist final, checksum, commit/tag, dan pemeriksaan document root host client tetap wajib sebelum release.

## 2026-08-27 - Assertion Isi Paket Runtime

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan exclusion artefak internal diverifikasi pada filesystem staging, bukan hanya melalui HTTP.

**Perubahan fitur dan perilaku:** `tests/support/run_release_rehearsal.ps1` kini gagal bila `.git`, `sql`, `tests`, `documentation`, `.env`, atau `config/app.local.php` tersalin ke paket runtime.

**Database dan migrasi:** Tidak ada perubahan pada database aplikasi; rehearsal memakai schema disposable.

**Kompatibilitas dan data lama:** Tidak ada perubahan runtime produksi.

**Verifikasi:** PowerShell parser lulus; `DEP-REHEARSAL-008` lulus dengan schema + 19 migrasi dua pass, verifier, auth/HTTP smoke, Composer, filesystem allowlist, dan cleanup disposable.

**Catatan tindak lanjut:** Ulangi assertion pada tag/package final dan host client; status produksi tetap menunggu HTTPS, ACL, UAT, backup/rollback, dan sign-off.

## 2026-08-27 - Gate Statis Final Worktree

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan perubahan audit terakhir tidak menimbulkan regresi syntax, coverage, route contract, atau whitespace.

**Perubahan fitur dan perilaku:** Tidak ada perubahan runtime; hanya verifikasi terhadap source saat ini.

**Database dan migrasi:** Tidak ada mutasi database.

**Kompatibilitas dan data lama:** Tidak ada perubahan.

**Verifikasi:** PHP lint 64 file, Node check, coverage matrix 40/40, route contract 29/26/15, PowerShell parser release rehearsal, Composer validate/platform/audit (0 advisory), 14 artefak wajib, link checker, dan `git diff --check` lulus (`STATIC-005`, `DEP-SBOM-002`).

**Catatan tindak lanjut:** Gate statis harus dijalankan ulang pada commit/tag final; gate client/produksi tetap terbuka.

## 2026-08-27 - Hardening Layout Login Mobile

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mencegah flex item halaman login mempertahankan lebar minimum konten sehingga form terpotong pada viewport mobile.

**Perubahan fitur dan perilaku:**

- Menetapkan `min-width: 0`, basis flex, dan batas `100vw` pada panel login serta inner form di breakpoint tablet/mobile. Input dan tombol kini dapat menyusut mengikuti viewport tanpa horizontal overflow.
- Versi query asset `login.css` dinaikkan dari `3.4` ke `3.5` agar perbaikan tidak tertahan cache browser.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, atau data.

**Kompatibilitas dan data lama:**

- Layout desktop tetap menggunakan split panel; breakpoint mobile hanya memperketat sizing dan tidak mengubah alur login.

**Verifikasi:**

- Playwright + Chrome headless viewport 390x844: `innerWidth=390`, `scrollWidth=390`, panel 390px, form/input/button 350px.
- Cache-busted smoke mengonfirmasi stylesheet `assets/css/login.css?v=3.5` termuat dan `scrollWidth=390`.
- Keyboard Tab mencapai theme, username, password, toggle password, dan submit; toggle dark→light tidak menambah overflow.
- Smoke autentikasi pada dashboard, laporan, pembayaran, tabungan, dan Master Daftar Ulang di viewport 390px/1440px menghasilkan HTTP 200 tanpa overflow atau console error (`UI-BROWSER-002`).
- PHP lint, Node check, dan `git diff --check` lulus.

**Catatan tindak lanjut:**

- Smoke ini mencakup login dan enam halaman read-only lokal; UAT browser seluruh route/role, accessibility tree, console/network menyeluruh, dan target client tetap terbuka.

## 2026-08-26 - CSRF Gate Sebelum Pembuatan Tahun Ajaran

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan POST Master Daftar Ulang yang tokennya tidak valid tidak membuat state tahun ajaran sebelum validasi keamanan.

**Perubahan fitur dan perilaku:**

- `master_daftar_ulang.php` memindahkan `master_du_ensure_year()` ke setelah validasi CSRF. POST invalid/cross-site kini ditolak sebelum operasi database; GET tetap read-only.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi.

**Kompatibilitas dan data lama:**

- POST sah tetap memastikan tahun ajaran dan melanjutkan alur sebelumnya; histori dan pembayaran legacy tidak disentuh.

**Verifikasi:**

- Probe token CSRF salah dengan label tahun valid: 302 dan jumlah tahun ajaran before/after identik.
- Regression disposable `regression-csrf-du-20260826_231000`: 18/18 PASS; PHP lint dan `git diff --check` PASS.

**Catatan tindak lanjut:**

- Matrix seluruh kombinasi method/content-type/CSRF dan fault-injection masih menjadi residual audit.

## 2026-08-26 - Atomisitas Ensure Tahun Ajaran Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mencegah kegagalan aksi POST yang sah meninggalkan draft tahun ajaran baru.

**Perubahan fitur dan perilaku:**

- `master_du_ensure_year()` dipindahkan ke dalam transaksi aksi Master Daftar Ulang. Aksi tidak dikenali atau validasi bisnis yang gagal sekarang menggulung balik pembuatan tahun yang baru.

**Database dan migrasi:**

- Tidak ada perubahan schema/migrasi; hanya urutan operasi transaksi pada handler.

**Kompatibilitas dan data lama:**

- POST valid tetap membuat/menggunakan tahun ajaran seperti sebelumnya; GET tetap read-only dan legacy tidak disentuh.

**Verifikasi:**

- Probe valid-CSRF dengan aksi invalid dan label tahun terisolasi: redirect, jumlah `tahun_ajaran` before/after identik.
- Regression disposable `regression-du-atomic-20260826_234500`: 18/18 PASS.

**Catatan tindak lanjut:**

- Failpoint di tengah setiap write dan matrix seluruh method/content-type/CSRF masih terbuka.

## 2026-08-27 - Validasi Scalar Alasan Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mencegah nilai array/struktur tidak sengaja berubah menjadi teks `Array` dan masuk ke audit trail.

**Perubahan fitur dan perilaku:**

- `audit_require_reason()` sekarang menolak input non-scalar sebelum normalisasi dan batas panjang.

**Database dan migrasi:**

- Tidak ada perubahan schema/migrasi atau data baseline.

**Kompatibilitas dan data lama:**

- Alasan teks biasa tetap berfungsi; request berbentuk array harus diperbaiki oleh caller.

**Verifikasi:**

- Assertion `audit_event_test.php` membuktikan array reason ditolak.
- Regression disposable `regression-audit-reason-20260827_001500`: 18/18 PASS; PHP lint dan `git diff --check` PASS.

**Catatan tindak lanjut:**

- DAST input lengkap dan fault-injection tetap belum tersedia pada lingkungan target.

## 2026-08-26 - Final Security Regression dan Revalidasi Username

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan revalidasi sesi membawa snapshot username yang konsisten dan mengulang regression keamanan langsung pada snapshot audit.

**Perubahan fitur dan perilaku:**

- Login dan `includes/auth.php` kini menyimpan serta menyegarkan `admin_username` ketika akun berhasil diverifikasi; perubahan role/password/session version tetap memaksa revalidasi sesi.

**Database dan migrasi:**

- Tidak ada migrasi baru. Probe memakai `db_spp_audit_20260820_090000`; baseline dan after tetap identik, tanpa fixture tersisa.

**Kompatibilitas dan data lama:**

- Tidak mengubah data pembayaran, tabungan, atau histori legacy; kontrol MD5 legacy tetap memerlukan reset eksplisit.

**Verifikasi:**

- Security regression direct PASS untuk request ID, security headers/cookie/HSTS, CSRF, POST-only, revocation, MD5 block, multi-bucket throttle, last-admin guard, dan logout.
- Port 8099 dilepas setelah uji; PHP lint login/auth PASS.

**Catatan tindak lanjut:**

- Bukti ini lokal pada snapshot audit; TLS/ACL/credential target, browser/UAT, DAST penuh, dan keputusan deployment tetap terbuka.

## 2026-08-26 - Sanitasi Fallback Error Riwayat Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup satu fallback query yang sebelumnya menyusun pesan exception dari `$koneksi->error` dan berpotensi membocorkan detail internal.

**Perubahan fitur dan perilaku:**

- `pembayaran/riwayat_daftar_ulang.php` kini mengembalikan pesan domain generik ketika statement halaman gagal disiapkan; detail teknis tetap ditangani oleh logging/exception handler terpusat.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, saldo, atau histori.

**Kompatibilitas dan data lama:**

- Jalur normal dan filter laporan tidak berubah; hanya pesan kegagalan internal yang disanitasi.

**Verifikasi:**

- PHP lint seluruh 64 file dan `git diff --check` lulus setelah perubahan.
- Regression disposable final tetap lulus 18/18 sebelum perubahan fallback ini; perubahan hanya pada cabang error prepare.

**Catatan tindak lanjut:**

- Failure injection untuk seluruh query report dan browser/UAT deployment masih merupakan residual audit.

## 2026-08-26 - Regression Disposable Final Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memverifikasi ulang paket hardening dan regression terbaru setelah seluruh perbaikan boundary input, idempotency, audit, dan lifecycle diterapkan.

**Perubahan fitur dan perilaku:**

- Tidak ada perubahan perilaku baru pada source dalam run ini; seluruh suite dijalankan pada clone disposable.

**Database dan migrasi:**

- Database `db_spp_audit_20260826_221654_suite_8627` dibuat dan dikelola harness disposable. `db_spp` tidak pernah menjadi target mutasi.

**Kompatibilitas dan data lama:**

- Tidak ada backfill atau perubahan histori baseline. Guard legacy, relasi pembayaran, dan audit append-only tetap diuji sesuai kontrak.

**Verifikasi:**

- `run_disposable_regression.ps1 -AllowEmptyPassword -GenerateTemporaryAdmin`: 18/18 PASS.
- PHP lint 64 file, `node --check assets/js/app.js`, coverage matrix 40/40, route contract, dan `git diff --check` PASS.

**Catatan tindak lanjut:**

- Status audit tetap NO-GO sampai browser/UAT, DAST penuh, PDF/Excel client, failpoint/deadlock/retry, target TLS/ACL/credential, backup terenkripsi, serta keputusan owner tersedia.

## 2026-08-26 - Harness Recovery Migrasi Pasca-Kegagalan

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Membuktikan migrasi dapat dijalankan ulang setelah kegagalan SQL sintetis tanpa menyentuh database utama.

**Perubahan fitur dan perilaku:**

- Menambahkan `tests/support/run_migration_failure_recovery.ps1`. Harness membuat database `db_spp_audit_failure_*`, menyuntikkan satu statement invalid setelah masing-masing migrasi kanonik, mengharapkan kegagalan, lalu menjalankan ulang migrasi asli.

**Database dan migrasi:**

- Tidak ada perubahan pada migrasi produksi. Database disposable dibersihkan dengan guard identitas.

**Kompatibilitas dan data lama:**

- Bukti hanya mencakup rerun setelah statement migrasi selesai; tidak mengklaim rollback DDL, failpoint di tengah statement, deadlock/retry, atau upgrade semua generasi histori.

**Verifikasi:**

- Run `migration-failure-20260826_191000`: 19/19 failure injection teramati, 19/19 recovery PASS, verifier schema/data PASS.
- PowerShell parser PASS dan tidak ada database `db_spp_audit_failure_*` tersisa setelah cleanup.

**Catatan tindak lanjut:**

- Recovery produksi, snapshot histori client, deadlock/retry, dan sign-off deployment masih terbuka; status audit tetap NO-GO.

## 2026-08-26 - Perluasan Oracle Formula Spreadsheet

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memperkuat pemeriksaan netralisasi formula dan control character pada helper export tanpa mengklaim validasi aplikasi spreadsheet client.

**Perubahan fitur dan perilaku:**

- `tests/report_export_parity_test.php` kini menguji awalan `=`, `+`, `-`, `@`, whitespace Unicode, CR/LF/tab, dan NUL; setiap hasil harus bebas control character dan diawali apostrophe.

**Database dan migrasi:**

- Tidak ada perubahan schema/migrasi; fixture tetap berada di transaksi test dan di-rollback.

**Kompatibilitas dan data lama:**

- Tidak mengubah format export atau data lama. Verifikasi ini hanya memperluas oracle helper yang telah dipakai export.

**Verifikasi:**

- `regression-formula-20260826_193000`: 18/18 PASS.
- PHP lint dan `git diff --check` PASS.

**Catatan tindak lanjut:**

- Workbook tetap harus dibuka pada client target untuk memverifikasi tipe sel dan perilaku formula; FULLSEC-014 tetap belum tertutup.

## 2026-08-26 - Hardening Scalar Filter Rekap Kelas

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menghindari cast langsung parameter GET array pada halaman rekap kelas yang dilindungi.

**Perubahan fitur dan perilaku:**

- `laporan/rekap_kelas.php` memakai `security_input_scalar()` untuk filter kelas, bulan, tahun, dan pencarian. Markup, CSS, selector, dan konsep visual tidak diubah.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, saldo, atau histori.

**Kompatibilitas dan data lama:**

- Filter scalar valid tetap sama; input array kembali ke default aman.

**Verifikasi:**

- `regression-recap-scalar-20260826_201500`: 18/18 PASS, termasuk probe array seluruh filter rekap.
- PHP lint, route contract, coverage checker, dan `git diff --check` PASS.

**Catatan tindak lanjut:**

- Screenshot/browser regression, DAST parameter penuh, dan validasi akurasi snapshot rekap tetap terbuka.

## 2026-08-26 - Hardening Scalar Pagination Parameter

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mencegah parameter GET berbentuk array memicu cast/warning pada helper pagination.

**Perubahan fitur dan perilaku:**

- `includes/pagination.php` kini hanya meng-cast nilai scalar pada `page_int_param()` dan `page_size_param()`; array/object kembali ke nilai default.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, atau data.

**Kompatibilitas dan data lama:**

- Parameter halaman scalar valid tetap berperilaku sama; input array diperlakukan sebagai default yang aman.

**Verifikasi:**

- `regression-pagination-scalar-20260826_200000`: 18/18 PASS, termasuk corpus array/scalar endpoint tabungan dan laporan.
- PHP lint, Node check, route contract, coverage checker, dan `git diff --check` PASS.

**Catatan tindak lanjut:**

- DAST parameter penuh, time-based payload, dan browser sink tetap di luar coverage lokal.

## 2026-08-26 - Corpus Content-Type Terstruktur pada Endpoint Mutasi

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menambah bukti boundary method/content-type tanpa mengklaim DAST atau Cartesian coverage penuh.

**Perubahan fitur dan perilaku:**

- `tests/security_input_corpus_test.php` kini dapat mengirim raw body dan menguji `application/json` serta `text/plain` ke endpoint mutasi pembayaran, tabungan, master kelas/biaya/Daftar Ulang, siswa, dan role management.
- Request sengaja tanpa token form; aplikasi harus menolak atau mengarahkan ulang tanpa SQL error/HTTP 500 dan tidak memperlakukan body terstruktur sebagai `$_POST` form.

**Database dan migrasi:**

- Tidak ada perubahan schema/migrasi. Clone disposable dibersihkan oleh harness; `db_spp` tidak disentuh.

**Kompatibilitas dan data lama:**

- Form `application/x-www-form-urlencoded` dan URL tetap tidak berubah. Probe ini hanya menambah coverage negatif untuk content-type non-form.

**Verifikasi:**

- `regression-rollback-20260826_185000`: 18/18 test PASS, termasuk corpus JSON/text, SQLi/array boundary, security, payment, savings, report, dan lifecycle; snapshot count sembilan tabel bisnis sebelum/sesudah tetap identik.
- `payment_process_integration_test.php` memverifikasi penolakan payment plan tahunan me-rollback claim idempotency yang dibuat sebelum validasi gagal.
- PHP lint dan `git diff --check` PASS.

**Catatan tindak lanjut:**

- Full Cartesian route × method × content-type × parameter, failpoint/deadlock/retry, authenticated DAST, dan browser/UAT client tetap terbuka; status serah-terima tetap NO-GO.

## 2026-08-26 - Retest Release Rehearsal Final Scalar Boundary

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan clean package terbaru setelah normalisasi scalar pada route master, siswa, role, payment, savings, dan histori Daftar Ulang tetap dapat dipasang ulang.

**Perubahan fitur dan perilaku:**

- Tidak ada perubahan tambahan pada rehearsal; source terbaru diprovision apa adanya.

**Database dan migrasi:**

- `db_spp_audit_migration_20260826_171823_20904_20d17597` menerima schema + 19 migrasi dua pass, verifier lulus, lalu dihapus.

**Kompatibilitas dan data lama:**

- Tidak ada data baseline yang dimutasi.

**Verifikasi:**

- `run_release_rehearsal.ps1 -AllowEmptyPassword`: PASS pada evidence `release-rehearsal-20260826_174000`.
- Migration matrix, schema/data verifier, least-privilege grant, login/dashboard smoke, deny artefak internal, Composer checks, dan cleanup probe: PASS.

**Catatan tindak lanjut:**

- Target Apache/HTTPS/PDF/ACL/credential/rollback/UAT client tetap belum diuji.

## 2026-08-26 - Retest Clean Package Setelah Hardening POST

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan paket instalasi bersih tetap reproducible setelah normalisasi scalar pada handler pembayaran/tabungan dan verifier kontrak route.

**Perubahan fitur dan perilaku:**

- Tidak ada perubahan tambahan pada rehearsal; source terbaru diprovision apa adanya.

**Database dan migrasi:**

- `db_spp_audit_migration_20260826_171319_13708_d7fe8e3b` menerima schema + 19 migrasi dua pass, diverifikasi, lalu dihapus.

**Kompatibilitas dan data lama:**

- Tidak ada data baseline yang dimutasi.

**Verifikasi:**

- `run_release_rehearsal.ps1 -AllowEmptyPassword`: PASS pada evidence `release-rehearsal-20260826_172000`.
- Schema/data verifier, migration matrix, least-privilege grant, login/dashboard smoke, deny artefak internal, dan Composer checks: PASS; post-run probe migration database: 0.

**Catatan tindak lanjut:**

- Target Apache/HTTPS/PDF/ACL/credential/rollback/UAT client tetap belum diuji.

## 2026-08-26 - Hardening Scalar Input Laporan dan Export

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup boundary input array pada filter laporan, receipt, dan export yang sebelumnya mengandalkan cast langsung ke string atau integer.

**Perubahan fitur dan perilaku:**

- `includes/reports.php` memakai helper scalar-only untuk seluruh filter laporan.
- `laporan/index.php`, `template.php`, `export_global.php`, `export_pdf.php`, `export_excel.php`, `detail_siswa.php`, `cetak_struk.php`, dan `cetak_struk_tahunan.php` menormalisasi parameter scalar; `ids[]` pada PDF tetap didukung sebagai daftar terpilih.
- `security_input_scalar()` dipakai pada field scalar pembayaran dan tabungan; array list Biaya Lain tetap diproses hanya pada jalur yang memang mengharapkan array.
- `verify_route_security_contract.ps1` diselaraskan agar memverifikasi helper scalar tersebut sebagai kontrak input handler pembayaran.
- URL dan perilaku filter scalar valid tetap kompatibel; input array diperlakukan sebagai nilai kosong/default dan tidak memicu warning/HTTP 500.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, saldo, jurnal, pembayaran, atau data legacy.

**Kompatibilitas dan data lama:**

- Tidak ada backfill atau mutasi baseline; semua pengujian memakai clone disposable.

**Verifikasi:**

- `security_input_corpus_test.php` diperluas dengan probe array/scalar pada laporan index/template/global, export PDF/Excel, detail siswa, dan receipt.
- Regression disposable `regression-route-scalar-20260826_175000`: **18/18 PASS**; probe GET laporan/export dan POST pembayaran/tabungan, master, siswa, role, serta histori Daftar Ulang dengan CSRF valid tidak menghasilkan HTTP 500 atau marker SQL.
- `master_daftar_ulang.php` tidak lagi membuat tahun ajaran pada GET; probe before/after dan regression `regression-get-safety-20260826_180000` membuktikan GET read-only, sementara pembuatan tetap dilakukan pada POST.
- Clean release rehearsal terbaru `release-rehearsal-20260826_181000` juga PASS setelah perubahan method-safety; database migration disposable dibersihkan.
- PHP lint, Node check, coverage matrix, route contract, Composer checks, data verifier, dan `git diff --check` dijalankan ulang setelah perubahan.

**Catatan tindak lanjut:**

- Ini tetap focused corpus, bukan authenticated DAST penuh; mutating route, time-based payload, seluruh kombinasi parameter/content-type, browser sink, dan UAT client masih terbuka.

## 2026-08-26 - Sinkronisasi Bukti Audit Terakhir

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menyamakan referensi evidence pada ringkasan audit dengan rehearsal dan regression final terbaru.

**Perubahan fitur dan perilaku:**

- Tidak ada perubahan runtime atau schema; hanya referensi dokumentasi yang diperbarui.

**Database dan migrasi:**

- Tidak ada database yang dimutasi.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data atau perilaku legacy.

**Verifikasi:**

- PHP lint 64 file, `node --check assets/js/app.js`, route security contract, coverage matrix 40/40, Composer validate/platform/audit, dan `git diff --check`: PASS.
- Evidence terbaru dirujuk konsisten pada saat entri ini dibuat: regression `regression-final-20260826_165546`, release rehearsal `release-rehearsal-20260826_165900`.

**Catatan tindak lanjut:**

- Status keseluruhan tetap NO-GO sampai browser/UAT client, target HTTPS/Apache, PDF/Excel client, rollback/RPO-RTO, dan sign-off risiko tersedia.

## 2026-08-26 - Retest Clean Release Rehearsal

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memverifikasi ulang paket runtime bersih setelah remediation frontend dan input boundary terbaru.

**Perubahan fitur dan perilaku:**

- Tidak ada perubahan kode pada rehearsal ini; validasi memakai source terkini.

**Database dan migrasi:**

- `db_spp_audit_migration_20260826_165823_1952_7a4e896a` dibuat disposable, menerima schema + 19 migrasi dua pass, diverifikasi, lalu dihapus.

**Kompatibilitas dan data lama:**

- Tidak ada data baseline yang dimutasi.

**Verifikasi:**

- `run_release_rehearsal.ps1 -AllowEmptyPassword`: PASS pada evidence `release-rehearsal-20260826_165900`.
- Auth/dashboard smoke, deny artefak internal, schema/data verifier, least-privilege grant, Composer validate/platform check: PASS.

**Catatan tindak lanjut:**

- Target Apache/HTTPS/PDF/ACL/credential/rollback/UAT client tetap belum diuji.

## 2026-08-26 - Hardening Array/Scalar Boundary Endpoint Tabungan

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup variasi input array yang dapat menyebabkan error pada endpoint tabungan ketika parameter `nis` seharusnya scalar.

**Perubahan fitur dan perilaku:**

- `tabungan/get_saldo.php`, `tabungan/masuk.php`, dan `tabungan/keluar.php` kini menormalkan `nis` hanya jika scalar; array diperlakukan sebagai filter kosong tanpa TypeError/HTTP 500.
- `tests/security_input_corpus_test.php` menambah probe array/scalar untuk ketiga endpoint tersebut.

**Database dan migrasi:**

- Tidak ada perubahan database atau migrasi.

**Kompatibilitas dan data lama:**

- Filter scalar dan format URL tetap kompatibel; input array yang sebelumnya error sekarang ditolak secara aman sebagai nilai kosong.

**Verifikasi:**

- PHP lint ketiga route + test dan `node --check assets/js/app.js`: PASS.
- Regression disposable final: **PASS 18/18**, clone `db_spp_audit_20260826_165547_suite_2778`, evidence `regression-final-20260826_165546`.

**Catatan tindak lanjut:**

- Corpus ini tetap bukan DAST Cartesian seluruh route/parameter dan belum menggantikan browser sink testing.

## 2026-08-26 - Remediasi Frontend Statis Wave 6

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Mengurangi defect frontend yang dapat dibuktikan tanpa browser runtime, sambil mempertahankan konsep visual `laporan/rekap_kelas.php`.

**Perubahan fitur dan perilaku:**

- Sel rekap dashboard mendapat `data-label` untuk konteks kartu mobile.
- Timer jam global hanya dibuat pada halaman yang benar-benar memiliki `#liveClock`.
- `prefers-reduced-motion` ditambahkan pada stylesheet aplikasi dan login.
- Toast laporan/tabungan diberi live-region semantics; modal tabungan diberi `dialog` semantics, label relasi, `tabindex`, fokus awal, restore focus, dan penutupan Escape.

**Database dan migrasi:**

- Tidak ada perubahan database atau migrasi.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan kontrak backend atau data; halaman rekap kelas protected tidak diubah konsep maupun markup-nya.

**Verifikasi:**

- `node --check assets/js/app.js`: PASS.
- PHP lint route yang berubah: PASS.
- `git diff --check`: PASS.
- Regression disposable final setelah perubahan: **PASS 18/18**, evidence `regression-final-20260826_164653`.
- Browser runtime resmi tidak tersedia (`agent.browsers.list()` mengembalikan `[]`), sehingga screenshot/accessibility tree/runtime UAT tetap belum diuji.

**Catatan tindak lanjut:**

- Uji keyboard, screen reader, contrast, viewport, tema, dan performance pada browser target sebelum Wave 6 ditutup.

## 2026-08-26 - Audit Lifecycle Master Siswa

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup celah bukti TC-STD-001 untuk perubahan NIS, arsip/restore, cascade histori, dan audit perubahan master siswa tanpa menyentuh database baseline.

**Perubahan fitur dan perilaku:**

- Menambahkan `tests/student_lifecycle_integration_test.php` yang menguji endpoint admin secara HTTP: ganti NIS, cascade FK histori `bayar`/`transaksi_m`, snapshot before/after pada `siswa_audit_log`, arsip, restore, dan filter status arsip.
- Cleanup fixture memperhitungkan placement/tagihan Daftar Ulang otomatis dalam urutan FK yang aman.

**Database dan migrasi:**

- Tidak ada migrasi atau perubahan schema; seluruh fixture berjalan pada clone disposable dan clone di-drop.

**Kompatibilitas dan data lama:**

- Kontrak NIS lama tetap mengikuti `ON UPDATE CASCADE`; tidak ada backfill atau tebakan relasi histori.

**Verifikasi:**

- `run_disposable_regression.ps1 -GenerateTemporaryAdmin`: **PASS 18/18**, clone `db_spp_audit_20260826_163853_suite_4100`, evidence `regression-lifecycle-20260826_163852`.
- PHP lint test baru dan `git diff --check` lulus.

**Catatan tindak lanjut:**

- Browser/UAT, seluruh tipe referensi, serta deployment target tetap berada di luar bukti lokal ini.

## 2026-08-26 - Clean Deploy Provisioning Rehearsal

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Membuktikan paket runtime bersih dapat dipasang pada schema audit kosong dan melakukan smoke autentikasi tanpa membawa artefak internal.

**Perubahan fitur dan perilaku:**

- `tests/support/run_release_rehearsal.ps1` kini menjalankan schema kosong + 19 migrasi kanonik melalui migration matrix, memberi grant runtime disposable, membuat admin fixture ephemeral, lalu menguji login dan dashboard pada package staging.
- Rehearsal tetap memeriksa URL internal, Composer, dan cleanup exact; evidence tersanitasi dipertahankan di luar repository, sedangkan package stage dibersihkan.

**Database dan migrasi:**

- Database migration rehearsal hanya memakai namespace `db_spp_audit_migration_*`, diverifikasi, lalu di-drop; `db_spp` tidak disentuh.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan aplikasi produksi atau data histori.

**Verifikasi yang benar-benar dijalankan:**

- `run_release_rehearsal.ps1`: **PASS**, evidence `release-rehearsal-20260826_162519`; schema/data verifier, 19 migrasi, login/dashboard, HTTP deny, dan Composer checks lulus.
- Tidak ada disposable database atau server yang tersisa.

**Catatan tindak lanjut:**

- Host HTTPS/Apache target, PDF HTTP, secret/ACL target, rollback, backup terenkripsi, dan sign-off infra masih terbuka.

## 2026-08-26 - Perluasan Corpus Input Read-only

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memperluas bukti DAST fokus tanpa menyentuh mutasi atau database sumber.

**Perubahan fitur dan perilaku:**

- `tests/security_input_corpus_test.php` kini menguji predicate boolean, quote/error, slash, NUL/encoding, parameter duplikat, array/scalar, oversized, dan reflected-XSS pada 24 route GET terautentikasi yang read-only.
- Tidak ada perubahan schema, saldo, pembayaran, atau histori legacy.

**Database dan migrasi:**

- Tidak ada migrasi; test tetap memakai clone disposable.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan format URL normal atau perilaku bisnis.

**Verifikasi yang benar-benar dijalankan:**

- Regression disposable terbaru `regression-20260826_163314_suite_3628`: **PASS 17/17**; seluruh payload fokus pada 26 route termasuk export tidak menghasilkan SQL error, HTTP 500, atau payload XSS mentah.

**Catatan tindak lanjut:**

- Corpus ini bukan DAST penuh; route mutasi, time-based, sink browser, dan seluruh Cartesian parameter tetap terbuka.

## 2026-08-26 - Backup Restore Drill Disposable

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup bukti lokal bahwa backup logical dapat dipulihkan ke schema berbeda tanpa menyentuh database sumber.

**Perubahan fitur dan perilaku:**

- Menambahkan `tests/support/run_backup_restore_drill.ps1` dengan guard nama database audit/restore, probe identitas `DATABASE()`, perbandingan row count tiap tabel, verifier schema/data, dan cleanup exact.
- Guard `run_data_verifier.ps1` menerima namespace restore audit yang tetap terisolasi; `db_spp` tetap ditolak.

**Database dan migrasi:**

- Tidak ada migrasi atau backfill; source `db_spp_audit_20260820_090000` hanya dibaca.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan perilaku aplikasi atau relasi histori.

**Verifikasi yang benar-benar dijalankan:**

- `run_backup_restore_drill.ps1`: **PASS**; 22 tabel row-count cocok, `verify_schema.sql` PASS, INV-001--017 PASS, evidence `backup-restore-20260826_161023`.
- Restore schema disposable di-drop; `db_spp` tidak disentuh.

**Catatan tindak lanjut:**

- Backup terenkripsi, host kosong, least-privilege credential, RPO/RTO, monitoring, dan rollback target client tetap terbuka.

## 2026-08-26 - Isolasi Child Daftar Ulang pada Timestamp Identik

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan koreksi/hapus satu header pembayaran tidak mengubah child Daftar Ulang lain yang kebetulan memiliki NIS dan timestamp sama.

**Perubahan fitur dan perilaku:**

- `tests/academic_year_billing_test.php` kini membuat dua header dan dua child dengan timestamp identik, menghapus header pertama, lalu memeriksa saldo dan child header kedua berdasarkan `bayar_id`.
- Tidak ada perubahan pada handler produksi atau schema.

**Database dan migrasi:**

- Tidak ada migrasi; seluruh skenario berada dalam transaction rollback-only.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan perilaku data legacy.

**Verifikasi yang benar-benar dijalankan:**

- Regression disposable terbaru `regression-20260826_161334_suite_6626`: **PASS 17/17**, termasuk assertion isolasi timestamp identik.
- Tidak ada fixture tersisa pada clone; `db_spp` tidak disentuh.

**Catatan tindak lanjut:**

- Skenario lintas siswa dan fault-injection/deadlock masih memerlukan perluasan terpisah.

## 2026-08-26 - Verifikasi Target Publish Biaya Lain

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup variasi acceptance target `all`, `tingkat`, `rombel`, dan `siswa` pada penerbitan Biaya Lain.

**Perubahan fitur dan perilaku:**

- Menambahkan `tests/optional_fee_publish_integration_test.php` untuk membuat fixture anonim pada clone disposable, login melalui HTTP, menerbitkan empat master biaya ke target berbeda, dan memeriksa jumlah tagihan tepat sasaran.
- Parser CSRF test diarahkan ke form `form-terbit-biaya` agar token yang diverifikasi adalah token form penerbitan, bukan hidden field dari form lain.
- Tidak ada perubahan schema, saldo, pembayaran, atau histori produksi; seluruh fixture dihapus pada akhir test.

**Verifikasi yang benar-benar dijalankan:**

- `run_disposable_regression.ps1 -GenerateTemporaryAdmin`: **PASS 15/15** pada clone `db_spp_audit_20260826_153630_suite_3269`; cleanup domain dan mutation request lulus, clone di-drop.
- PHP lint 61 file, `node --check assets/js/app.js`, dan `git diff --check` lulus.
- `tests/support/verify_test_coverage_matrix.ps1`: 40/40 mapped, PASS 10, FAIL 0, NOT TESTED 25, PENDING DECISION 5.

**Di luar scope:** Repeat lintas target, deadlock/retry, failpoint, DAST penuh, browser UAT, dan gate deployment/client tetap terbuka.

## 2026-08-26 - Rehearsal Paket Runtime Bersih

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memeriksa bahwa paket runtime lokal dapat dijalankan tanpa membawa artefak internal atau konfigurasi secret.

**Perubahan fitur dan perilaku:**

- Menambahkan `tests/support/run_release_rehearsal.ps1`, harness yang membuat staging di luar repository dengan `.git`, `sql`, `tests`, dokumentasi, dan konfigurasi lokal dikeluarkan.
- Harness menguji login/asset publik, deny URL internal, `composer validate`, dan `composer check-platform-reqs`, lalu menghentikan server dan membersihkan staging.
- Tidak ada perubahan database aplikasi, schema, atau perilaku produksi.

**Verifikasi yang benar-benar dijalankan:**

- `run_release_rehearsal.ps1`: **RELEASE_REHEARSAL=PASS**; `login.php` dan asset 200, URL internal 403/404, Composer platform lulus, staging/server dibersihkan.
- `composer validate --strict`, `composer check-platform-reqs --no-dev`, dan `composer audit --locked --no-dev` pada worktree: PASS tanpa advisory.

**Di luar scope:** Fresh install/migrate, PDF HTTP dari paket staging, virtual host/HTTPS, target client, backup/RPO/RTO, dan release tag final.

## 2026-08-26 - Regression Lifecycle Session Akun

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memverifikasi bahwa perubahan role dan penghapusan akun mencabut kewenangan session aktif.

**Perubahan fitur dan perilaku:**

- Menambahkan `tests/session_lifecycle_test.php` yang membuat akun fixture anonim, login melalui HTTP, mengubah role menjadi kasir, lalu menghapus akun kedua saat session masih aktif.
- Test memastikan dashboard admin dialihkan setelah role berubah dan session akun terhapus kembali ke login.
- Tidak ada perubahan schema atau database sumber; fixture hanya hidup di clone disposable dan dibersihkan.

**Verifikasi yang benar-benar dijalankan:**

- `run_disposable_regression.ps1 -GenerateTemporaryAdmin`: **PASS 16/16** pada clone `db_spp_audit_20260826_154651_suite_5600`.
- PHP lint 62 file, Node check, route contract, data/schema verifier, dan cleanup disposable tetap lulus.

**Di luar scope:** Idle/absolute timeout, reuse cookie lintas proses, seluruh route pascarevokasi, browser UAT, dan deployment client.

## 2026-08-26 - Focused SQLi Input Corpus

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memperluas bukti SQL injection dari filter NIS ke parameter GET pada route laporan dan histori yang bersifat read-only.

**Perubahan fitur dan perilaku:**

- Menambahkan `tests/security_input_corpus_test.php` untuk login pada clone disposable dan mengirim payload quote/comment ke sepuluh route GET terautentikasi.
- Test menolak HTTP 500 dan marker error SQL, tanpa mengubah database; ini bukti fokus, bukan klaim DAST seluruh aplikasi.

**Verifikasi yang benar-benar dijalankan:**

- `run_disposable_regression.ps1 -GenerateTemporaryAdmin`: **PASS 17/17** pada clone `db_spp_audit_20260826_154928_suite_6970`.
- Payload quote/comment tidak menghasilkan SQL error atau HTTP 500 pada seluruh sepuluh route yang dipilih; cleanup clone lulus.

**Di luar scope:** Boolean/error/time fuzzing, array-scalar/duplicate/oversized input, seluruh route/mutasi, stored/reflected/DOM XSS, dan browser UAT.

## 2026-08-26 - Perlindungan Jurnal Tabungan Manual Saat Koreksi Pembayaran

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Membuktikan bahwa transaksi tabungan manual yang kebetulan memiliki timestamp sama tidak ikut dibalikkan ketika pembayaran dikoreksi atau dihapus.

**Perubahan fitur dan perilaku:**

- `tests/payment_process_integration_test.php` kini membuat fixture `transaksi_m` dengan `bayar_id=NULL` dan timestamp pembayaran.
- Setelah payment dipindah dan dihapus melalui endpoint, test memeriksa row manual, nominal, dan `bayar_id` tetap utuh; cleanup menggunakan ID exact.
- Tidak ada perubahan schema atau perilaku produksi.

**Verifikasi yang benar-benar dijalankan:**

- Regression disposable `db_spp_audit_20260826_155257_suite_8080`: **PASS 17/17**.
- Jurnal manual tetap satu row dengan nominal fixture dan relasi NULL; clone/server dibersihkan.

**Di luar scope:** Rekonsiliasi manual production, failpoint/deadlock/retry, dan browser UAT.

## 2026-08-26 - Snapshot dan Cap Biaya Lain

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memastikan perubahan master Biaya Lain tidak mengubah histori tagihan/detail, dan cicilan melebihi nominal ditolak atomik.

**Perubahan fitur dan perilaku:**

- `optional_fee_publish_integration_test.php` mengubah nama/nominal master setelah publish dan membandingkan snapshot tagihan sebelum/sesudah.
- `payment_process_integration_test.php` mengubah master setelah pembayaran, memeriksa detail snapshot tetap, lalu mengirim overpay dan memastikan jumlah detail tidak berubah.
- Tidak ada perubahan schema atau perilaku produksi; fixture dibersihkan pada clone.

**Verifikasi yang benar-benar dijalankan:**

- Regression disposable `db_spp_audit_20260826_155659_suite_1733`: **PASS 17/17**.
- TC-FEE-002 dinaikkan ke PASS; coverage checker tetap memetakan 40/40 test case.

**Di luar scope:** Failpoint/deadlock/retry, browser UAT, dan deployment client.

## 2026-08-26 - Retest Idle Timeout Session

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menambah bukti runtime bahwa session yang idle melewati batas dicabut, selain role change dan penghapusan akun.

**Perubahan fitur dan perilaku:**

- `tests/session_lifecycle_test.php` memodifikasi `__security_last_seen` pada session fixture disposable, lalu mengakses dashboard setelah melewati `SPP_SESSION_IDLE_TIMEOUT`.
- Assertion memastikan redirect ke login; fixture role-change dan deleted-account tetap dijalankan pada test yang sama.
- Tidak ada perubahan schema atau database sumber.

**Verifikasi yang benar-benar dijalankan:**

- Regression disposable `db_spp_audit_20260826_155904_suite_6399`: **PASS 17/17**.
- Idle timeout, role change, dan deleted account lulus; clone/server/session fixture dibersihkan.

**Di luar scope:** Absolute timeout, reuse cookie lintas proses, seluruh route pascarevokasi, browser UAT, dan host client.

## 2026-08-26 - Retest Absolute Timeout Session

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Melengkapi retest lifecycle dengan umur maksimum session, bukan hanya idle timeout.

**Perubahan fitur dan perilaku:**

- `tests/session_lifecycle_test.php` membuat akun fixture terpisah, mengubah `__security_created_at` melewati `SPP_SESSION_ABSOLUTE_TIMEOUT`, lalu menguji akses dashboard melalui cookie aktif.
- Assertion mengharuskan redirect ke login; idle timeout, role change, dan deleted account tetap dijalankan.
- Tidak ada perubahan schema atau database sumber.

**Verifikasi yang benar-benar dijalankan:**

- Regression disposable `db_spp_audit_20260826_160022_suite_9004`: **PASS 17/17**.
- Absolute timeout dan seluruh assertion lifecycle yang tercakup lulus; clone/server/session fixture dibersihkan.

**Di luar scope:** Reuse cookie lintas proses, seluruh route pascarevokasi, browser UAT, dan host client.

## 2026-08-26 - Hardening Array/Scalar Filter Riwayat Tabungan

**AI/Aktor:** Codex berbasis GPT-5

**Temuan:** Focused input corpus menemukan `nis[]=...` pada `tabungan/riwayat.php` memicu `TypeError` dari `trim(array)` dan HTTP 500.

**Perubahan fitur dan perilaku:**

- `tabungan/riwayat.php` kini memeriksa `is_scalar()` untuk `nis`, bulan, dan tahun; bentuk array ditolak sebagai filter kosong/default sebelum validasi dan prepared statement.
- Filter normal NIS kosong/exact dan format URL tetap kompatibel; tidak ada perubahan schema, saldo, pembayaran, atau legacy transaction.

**Verifikasi yang benar-benar dijalankan:**

- Rerun `run_disposable_regression.ps1 -GenerateTemporaryAdmin`: **PASS 17/17** pada clone `db_spp_audit_20260826_160323_suite_7179`.
- Corpus quote/comment, array/scalar, oversized, dan reflected-XSS fokus tidak menghasilkan SQL error, HTTP 500, atau payload mentah; PHP lint route lulus.

**Di luar scope:** DAST seluruh route/mutasi, boolean/error/time fuzzing, DOM/stored XSS, browser UAT, dan deployment client.

## 2026-08-26 - Concurrency Payment Periode SPP

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Memverifikasi bahwa dua operator tidak dapat membuat dua pembayaran untuk siswa dan periode SPP yang sama ketika transaksi berjalan bersamaan.

**Perubahan fitur dan perilaku:**

- Runner `tests/support/run_concurrency_matrix.ps1` kini menambahkan fixture siswa dengan kelas aktif dan tarif SPP, membuat pembayaran prasyarat, lalu mengirim dua POST periode 08/2026 dengan key `payment` berbeda di dua server.
- Blocker `SELECT ... FOR UPDATE` memaksa race pada baris siswa; oracle memeriksa header `bayar`, claim `bayar_spp_periode`, audit `payment.created`, dan redirect penolakan.
- Tidak ada perubahan schema atau perilaku produksi tambahan di luar implementasi idempotency yang sudah dicatat pada entri berikutnya.

**Verifikasi yang benar-benar dijalankan:**

- `run_concurrency_matrix.ps1 -AllowEmptyRootPassword`: **PASS 25/25** pada clone `db_spp_audit_20260826_151239`; withdrawal/replay tabungan, payment-period race, publish Daftar Ulang, publish Biaya Lain, dan last-admin tidak menggandakan data bisnis, seluruh response 302 tanpa 500.
- Clone, server, cookie/token, dan artefak sensitif dibersihkan; `db_spp` tidak menjadi target mutasi.
- Snapshot sumber audit kemudian dibackup sebelum migrasi (`pre-idempotency-migration-20260826`, SHA-256 dicatat di execution log), diterapkan `add_mutation_idempotency.sql` tanpa backfill, lalu `verify_schema.sql` dan verifier data 17/17 lulus.
- Setelah migrasi snapshot tersebut, regression disposable diulang pada clone `db_spp_audit_20260826_151628_suite_8792`: 13/13 PASS dan cleanup bisnis/mutation request nol.
- Menambahkan `tests/tabungan_riwayat_sqli_test.php`; regression terbaru `db_spp_audit_20260826_151957_suite_9048` lulus 14/14. Filter kosong, exact NIS, dan payload `' OR 1=1 --` tetap 200, menghasilkan 0 row, tanpa SQL error. Ini bukan DAST menyeluruh.
- Payment integration juga menguji satu header legacy (`payment_link_version=0`): edit UI dan crafted delete ditolak, row/audit tetap utuh. Rerun clone `db_spp_audit_20260826_152423_suite_8799` tetap PASS 14/14.

**Di luar scope:** Target tingkat/rombel/siswa pada publish Biaya Lain, deadlock/retry, failpoint, DAST, browser UAT, dan keputusan client tetap terbuka.

## 2026-08-26 - Idempotency Mutasi Tabungan/Pembayaran dan Verifikasi Replay

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup temuan integritas finansial bahwa submit ulang form Tabungan/Pembayaran dapat menggandakan saldo, jurnal, dan audit event.

**Perubahan fitur dan perilaku:**

- Menambahkan `includes/idempotency.php` dan hidden `idempotency_key` acak 64-hex pada form Tabungan Masuk/Keluar.
- Handler `tabungan/proses.php` mengklaim key scope `savings` setelah transaksi dimulai; duplicate/replay atau payload berbeda ditolak sebelum mutasi kedua.
- Form pembayaran input/edit/delete memakai key yang sama dengan scope `payment`; replay create diuji dan tidak menggandakan header, nominal, atau audit.
- Klaim ikut rollback bila transaksi gagal; histori lama tidak diberi key atau direlasikan otomatis.

**Database dan migrasi:**

- Menambahkan `sql/add_mutation_idempotency.sql`, tabel `mutation_request`, definisi pada `sql/schema.sql`, dan requirement verifier tanpa FK actor.
- Manifest kanonik menjadi 19 migrasi. Matrix fresh/pass1/pass2 lulus dan database disposable dibersihkan.

**Verifikasi:**

- PHP lint helper, handler, form, dan test lulus; `git diff --check` lulus.
- `run_migration_matrix.ps1 -AllowEmptyPassword`: PASS seluruh 19 migrasi, verifier, fingerprint logis, cleanup.
- `run_disposable_regression.ps1 -GenerateTemporaryAdmin`: PASS 13/13, termasuk replay payment.
- `run_concurrency_matrix.ps1 -AllowEmptyRootPassword`: PASS 13/13; withdrawal paralel, replay savings key tunggal (saldo/jurnal/audit sekali), dan last-admin.

**Di luar scope:** Idempotency publish Daftar Ulang/Biaya Lain, payment concurrency, deadlock retry, failpoint, DAST, browser UAT, dan keputusan client tetap terbuka. Tidak ada database utama yang dimutasi.

## 2026-08-26 - Evidence PDF Laporan dan Sinkronisasi Gate Audit

**AI/Aktor:** Codex berbasis GPT-5

**Tujuan:** Menutup keterbatasan parser/render PDF lokal secara evidence-backed dan menyelaraskan status audit tanpa mengubah status NO-GO client.

**Perubahan fitur dan perilaku:**

- Tidak ada perubahan perilaku aplikasi.
- Menambahkan pemeriksa kontrak keamanan statis untuk 29 route produksi serta metadata evidence yang lebih lengkap pada wrapper regression disposable.
- Menambahkan SBOM Composer, completion audit, konfigurasi contoh HSTS/rate-limit secret, dan menyelaraskan dokumen audit, runbook, SOP, flowchart, manifest, serta matriks role dengan implementasi saat ini.

**Database dan migrasi:**

- Tidak ada perubahan data atau migrasi pada pekerjaan ini. Pemeriksaan database tetap read-only pada snapshot audit dan `db_spp` tidak menjadi target mutasi.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan kontrak URL, laporan, pembayaran, tabungan, maupun transaksi legacy.

**Verifikasi yang benar-benar dijalankan:**

- Sebelas PDF hasil HTTP smoke dibuka dengan `pypdf`; seluruh 17 halaman mempunyai teks nonkosong.
- PyMuPDF merender 17/17 halaman ke PNG; lima contact sheet kecil diinspeksi tanpa halaman kosong, clipping, atau overlap yang terlihat. Bukti dibatasi pada artefak tersimpan tersebut.
- Sebelas artefak `.xls` dapat diparse sebagai tabel HTML dan tidak memuat sel fixture berprefix formula berbahaya; pembukaan pada client spreadsheet tetap belum diuji.
- PDF SOP 12 halaman dan flowchart 4 halaman diregenerasi dari HTML final, diparse, dirender, dan diinspeksi visual.
- `composer validate --strict`, `composer audit --locked --no-dev`, dan `composer check-platform-reqs --no-dev` lulus.
- `tests/support/verify_route_security_contract.ps1` lulus untuk 29 route, 26 role guard, dan 15 kelompok kontrak method/CSRF literal.
- `tests/support/run_route_role_http_matrix.ps1` lulus 168/168 pada clone disposable untuk anonymous, invalid cookie, admin, bendahara, kasir, direct role denial, method/CSRF utama, JSON auth, logout, dan session pascalogout. Count serta checksum 19 tabel domain tetap identik; clone di-drop dan credential/dump sementara dikosongkan.
- `tests/support/run_concurrency_matrix.ps1` lulus 9/9 menggunakan dua server PHP dan transaksi blocker: withdrawal paralel tidak membuat saldo negatif/duplikat, dan dua delete admin paralel tetap menyisakan satu admin. Clone di-drop serta seluruh cookie/token/dump sementara dikosongkan.
- Coverage checker sesudah bukti concurrency tetap konsisten 40/40: PASS 6, FAIL 0, NOT TESTED 29, PENDING DECISION 5.

**Catatan tindak lanjut:**

- Status serah-terima tetap **NO-GO**. PDF belum diuji pada viewer/print target, Excel belum dibuka pada client nyata, dan browser UAT, concurrency/failpoint, historical upgrade, load test, infra target, serta keputusan owner masih terbuka.

## 2026-08-26 - Penutupan Wave Audit, Audit Append-only, dan Evidence Release Gate

**AI/Aktor:** Codex berbasis GPT-5 bersama agen audit repository

**Tujuan:** Menjalankan strategi audit menyeluruh sampai batas yang dapat dibuktikan pada lingkungan lokal, memperbaiki temuan integritas/security yang sudah masuk scope, dan menyerahkan dokumentasi release secara jujur.

**Perubahan fitur dan perilaku:**

- Menambahkan jurnal `audit_event` append-only, trigger penolak update/delete, request ID, actor snapshot tanpa FK, alasan koreksi wajib, before/after teredaksi, dan integrasi pembayaran, tabungan, serta Role Management.
- Menambahkan guard disposable untuk seluruh regression mutatif, rate-limit login multi-bucket, session revalidation, penolakan hash MD5 lama, CSRF/method guard, dan hardening route.
- Mempertahankan kontrak legacy: pembayaran `payment_link_version=0` tidak ditebak relasinya dan tidak dapat diedit/dihapus dari aplikasi.
- Menyelaraskan manifest 18 migrasi, matrix coverage, execution log, PROGRESS, PROJECT_CONTEXT, serta menambahkan `EXECUTIVE_REPORT.md`, `KNOWN_LIMITATIONS.md`, dan `RELEASE_MANIFEST.md`.

**Database dan migrasi:**

- `sql/add_financial_audit_log.sql` dan `sql/schema.sql` memakai actor snapshot tanpa FK agar histori audit tidak berubah saat akun dihapus; `sql/verify_schema.sql` memeriksa kontrak tersebut.
- Matrix migrasi kanonik 18 file dijalankan fresh + dua pass pada database disposable; tidak ada migrasi atau test mutatif dijalankan ke `db_spp`.
- Clone regression `db_spp_audit_20260825_120004_suite_4825` di-drop setelah test; event fixture append-only tidak dihapus paksa.

**Kompatibilitas dan data lama:**

- Filter NIS `tabungan/riwayat.php` tetap kompatibel (kosong = semua, terisi = exact match) tetapi seluruh query memakai prepared statement; payload SQL diperlakukan literal.
- Histori transaksi dan operator legacy tidak dicocokkan otomatis. Rekonsiliasi memerlukan prosedur manual dan persetujuan tertulis.

**Verifikasi yang benar-benar dijalankan:**

- `tests/support/run_migration_matrix.ps1`: `MIGRATION_MATRIX_STATUS=PASS` untuk 18 migrasi, dua pass, verifier schema/data, fingerprint logis, dan cleanup guard.
- `tests/support/run_disposable_regression.ps1`: 13/13 PASS (audit event, role, payment, savings, security, SPP, DU, legacy, pagination, reports, optional fee), fixture bisnis nol, 14 event fixture di clone.
- HTTP report smoke 34/34 PASS, oracle Wave 5 PASS, 0 HTTP 500/fatal; PDF hanya mendapat structural signature/page-object checks.
- PHP lint 58 file, `node --check assets/js/app.js`, dan `git diff --check` PASS.
- `tests/support/verify_test_coverage_matrix.ps1` memetakan 40/40 test case: PASS 5, FAIL 0, NOT TESTED 30, PENDING DECISION 5.

**Catatan tindak lanjut:**

- Status serah-terima tetap **NO-GO**: browser/accessibility UAT, visual PDF, client Excel, concurrency/failpoint, target HTTPS/backup/restore/RPO-RTO, dependency runtime Apache, keputusan bisnis, dan CSP strict belum dibuktikan.
- CSRF sebelumnya dicatat sebagai debt pada entri lama; endpoint yang sekarang dipasang token tetap memerlukan route-matrix/DAST lebih luas sebelum residual dianggap tertutup.

## 2026-08-20 - Rencana Audit Maksimal dan Kesiapan Serah-Terima

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyusun rencana pemeriksaan end-to-end yang dapat dipakai untuk menemukan anomali keamanan, integritas finansial, alur setengah jadi, dead code, drift dokumentasi, dan blocker sebelum aplikasi diserahkan kepada client.

**Perubahan fitur dan perilaku:**

- Menambahkan `documentation/RENCANA_AUDIT_FINAL_SISTEMSPP.md` sebagai rencana induk 23 bagian yang mencakup containment, backup/restore, route-role-method, authentication/session, CSRF, injection/XSS, audit trail, integritas pembayaran/SPP/DU/biaya lain/tabungan, migrasi, parity laporan, frontend/accessibility, performa, dead code, deployment, UAT, dan gate go/no-go.
- Menetapkan `laporan/rekap_kelas.php` beserta alur pendukungnya sebagai artefak referensi visual yang dilindungi dari penghapusan atau klasifikasi dead code.
- Mencatat baseline reconnaissance secara jujur, termasuk exposure artefak nonpublik melalui web, privilege database runtime, payment SPP tanpa claim periode, mismatch cache biaya awal, CSRF/method safety, dependency PDF, dan drift dokumentasi. Temuan tersebut belum diperbaiki dalam perubahan ini.
- Menambahkan rencana tersebut ke urutan baca `PROJECT_CONTEXT.md` untuk pekerjaan audit, hardening, dead-code cleanup, deployment, dan handover.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, maupun data. Pemeriksaan database yang dilakukan hanya query read-only dan tidak menampilkan identitas siswa.

**Kompatibilitas dan data lama:**

- Tidak ada perilaku aplikasi yang diubah. Aturan transaksi legacy tetap sama dan tidak ada backfill otomatis.

**Verifikasi:**

- Inventaris file, endpoint, role guard, schema, migrasi, dependency, test, frontend, dan dokumentasi diperiksa terhadap commit baseline `a446af3`.
- HTTP `HEAD` read-only membuktikan enam URL artefak sensitif masih mengembalikan 200 pada XAMPP lokal; tidak ada script test yang dieksekusi.
- Query database read-only mengonfirmasi 16 pembayaran SPP tanpa claim periode, dua mismatch cache biaya awal, serta privilege runtime MySQL yang terlalu luas.
- `composer validate --strict` berhasil; `composer check-platform-reqs` secara jujur gagal karena ekstensi GD belum aktif dan `vendor/` belum tersedia.
- Struktur Markdown, link lokal, `git diff --check`, dan status perubahan diperiksa setelah penyusunan.

**Catatan tindak lanjut:**

- Mulai eksekusi dari Wave 0. Jangan menjalankan test mutasi, migrasi, fuzzing, atau load test sebelum web exposure ditutup serta database audit terisolasi dan backup dapat direstore.

## 2026-08-20 - Pemulihan Rekap Pembayaran per Kelas

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengembalikan halaman mandiri rekap pembayaran per kelas dari riwayat Git tanpa menghapus sistem Laporan Global yang baru.

**Perubahan fitur dan perilaku:**

- Memulihkan versi penuh terakhir `laporan/rekap_kelas.php` dari commit `a3497e2`, sebelum file tersebut diganti menjadi redirect pada commit `75e6b4f`.
- Mengembalikan menu `Rekap per Kelas` untuk admin dan bendahara, termasuk status menu aktif saat membuka detail siswa.
- Rekap lama kembali menyediakan filter kelas 1–6, bulan, tahun, pencarian siswa, ringkasan, tabel komponen pembayaran, tampilan mobile, detail siswa, dan cetak browser.
- Laporan Global dan template Per Item tetap tersedia sebagai fitur terpisah.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi database.

**Kompatibilitas dan data lama:**

- Halaman memakai kolom tingkat legacy `siswa.KELAS` yang tetap dipertahankan pada schema saat ini, sehingga siswa dari seluruh rombel pada tingkat yang sama dirangkum bersama.
- URL lama `rekap_kelas.php?kelas={1-6}&bulan={01-12}&tahun={YYYY}&q={pencarian}` kembali merender halaman dan tidak lagi mengalihkan ke Laporan Global.

**Verifikasi:**

- Lint PHP, pemeriksaan HTTP untuk halaman dan filter, pembandingan sumber dengan blob historis, `node --check`, serta `git diff --check` dijalankan setelah pemulihan.

**Catatan tindak lanjut:**

- Rekap mandiri mempertahankan konsep tingkat kelas 1–6 dari versi lama; laporan per rombel seperti 1A/1B tetap tersedia melalui Laporan Global.

## 2026-08-20 - Perombakan Dashboard menjadi Closing Harian

**AI/Aktor:** Antigravity, bersama pemilik proyek

**Tujuan:** Merombak keseluruhan antarmuka Dashboard agar berfokus 100% pada *closing harian* (rekap penerimaan uang hari ini) dan menyembunyikan metrik/statistik *all-time* (global) yang kurang relevan bagi operasional kasir.

**Perubahan Perilaku / Kode:**
- **`dashboard.php`**: Dihapus kueri lama yang meload metrik *all-time* (seperti total siswa, total transaksi keseluruhan). Mengimpor `includes/reports.php` dan memanfaatkan fungsi `report_settlement_data()` khusus untuk tanggal hari ini.
- Mengubah 4 kotak metrik statistik di atas menjadi representasi uang masuk hari ini (Transaksi Hari ini, Total Penerimaan Kotor, Tunai Diterima, dan Kas Disetorkan/Tunai Bersih).
- Mengubah tautan "Quick Actions" untuk mengarahkan pengguna pada operasi *closing*: Input Pembayaran, Mutasi Tabungan (arah ke riwayat tabungan), dan Rincian Setoran Lengkap.
- Menghapus tabel "Transaksi Terbaru" agar fokus UI tidak terdistraksi.
- Menjadikan tabel "Rekap Setoran Kas Fisik Hari Ini" sebagai satu-satunya tabel yang tampil di dashboard, lengkap dengan fungsionalitas Export Excel yang otomatis disesuaikan untuk mengekspor rekap tanggal hari berjalan.

**Kompatibilitas:** Sepenuhnya *backward-compatible*. Data tidak berubah, ini murni perombakan cara menampilkan agregasi data laporan pada halaman depan.

**Tindak Lanjut / Verifikasi:**
- Uji sintaks `php -l dashboard.php` lolos tanpa *error*.
- Memastikan navigasi tombol aksi cepat (`tabungan/riwayat.php`) berfungsi tanpa error.
- Tampilan dievaluasi melalui tangkapan visual, dan fitur *export Excel* dipastikan menuju *endpoint* yang sudah ada dengan parameter `tanggal_awal=TODAY&tanggal_akhir=TODAY`.

## 2026-08-18 - Laporan Global Modular dan Master Kelas/Rombel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah Laporan Global menjadi katalog tujuh template yang konsisten pada web/cetak/PDF/Excel, menambahkan Master Kelas/Rombel dan histori tarif, serta menjadikan Biaya Lain sebagai tagihan nyata yang dapat dicicil.

**Perubahan fitur dan perilaku:**

- Menambahkan Master Kelas/Rombel tingkat 1–6 dengan placeholder migrasi, pemilihan rombel di Data Siswa, snapshot penempatan per tahun ajaran, dan snapshot kelas pada transaksi baru.
- Master Daftar Ulang membentuk snapshot kelas, SPP, dan Komite saat menerbitkan tahun ajaran; perubahan kelas aktif tidak menulis ulang histori yang sudah mempunyai pembayaran.
- Master Biaya Lain dapat menerbitkan tagihan secara transaksional/idempoten ke semua siswa, tingkat, rombel, atau siswa terpilih. Input/Edit Pembayaran hanya menerima tagihan milik siswa dan memvalidasi cicilan serta overpay di backend.
- `laporan/global.php` menjadi katalog tujuh card; implementasi laporan dipisahkan ke registry/query bersama, halaman template, dan renderer export bersama.
- Menambahkan Status Pembayaran, Penerimaan Harian, matriks SPP Juli–Juni, Pembayaran per Item, dua bentuk laporan tabungan, dan Setoran Kas Harian live yang memisahkan tunai, non-tunai, dan dana tabungan.
- Mengubah label menu menjadi Laporan Umum, menghapus menu Rekap per Kelas, serta mempertahankan URL lama sebagai redirect ke template Per Item.

**Database dan migrasi:**

- Menambahkan `master_kelas`, relasi/snapshot kelas, snapshot SPP/Komite, `tagihan_biaya_lain`, audit penerbitan, referensi tagihan detail pembayaran, foreign key, unique key, dan indeks laporan.
- Migrasi `sql/add_modular_global_reports.sql` melakukan preflight, backfill placeholder tanpa mengarang rombel, migrasi pembayaran Biaya Lain lama, dan postcheck data yatim.
- Migrasi lokal dijalankan dua kali setelah backup dan seluruh pemeriksaan pascamigrasi bernilai nol.

**Kompatibilitas dan konfigurasi:**

- Kolom kelas dan detail transaksi legacy dipertahankan. Histori Biaya Lain tanpa master tetap dapat dibaca sebagai histori.
- Schema instalasi baru dan `verify_schema.sql` diselaraskan. Composer dikunci ke platform PHP 8.0.30 dan `ext-gd` dicantumkan untuk logo PDF.

**Verifikasi:**

- Seluruh PHP lint, JavaScript syntax check, Composer validate/audit, schema pada database salinan, migrasi idempoten, schema verification, seluruh regression test, dan HTTP terautentikasi ketujuh template dijalankan.
- Cetak, Excel, dan PDF ketujuh template diuji; response PDF terverifikasi sebagai `application/pdf`.

## 2026-08-17 - Validasi Urutan SPP dan Filter Tanggal Laporan

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mencegah pembayaran SPP melompati bulan sebelumnya dalam tahun ajaran Juli-Juni, menyederhanakan sisa pembayaran pada struk, dan merapikan filter tanggal laporan.

**Perubahan fitur dan perilaku:**

- Backend menolak pembayaran SPP untuk bulan terpilih bila ada SPP bulan sebelumnya dalam tahun ajaran yang sama belum lunas terhadap `siswa.SPP_PERBULAN`.
- Edit atau hapus pembayaran SPP bulan sebelumnya ditolak bila membuat bulan setelahnya yang sudah dibayar menjadi bolong.
- UI input/edit pembayaran mengunci input SPP dan menampilkan alasan bila bulan sebelumnya belum lunas; cicilan bulan berjalan tetap boleh selama tidak melebihi sisa.
- Bagian `Sisa Pembayaran` pada struk biasa dan struk tahunan hanya menampilkan `Sisa PSB` dan `Sisa DU`; rincian pembayaran utama tetap lengkap.
- Laporan Keuangan dan Laporan Global memakai satu kontrol date-range custom lokal yang tetap mengirim `tanggal_awal` dan `tanggal_akhir` untuk kompatibilitas export dan URL lama.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi baru.

**Kompatibilitas:**

- URL lama laporan dengan `tanggal_awal` dan `tanggal_akhir` tetap berjalan.
- Struk tahunan lama tetap dapat dibuka, hanya tampilan area sisa pembayaran yang diringkas.

**Verifikasi:**

- PHP lint, Node syntax check, schema check, regression test SPP/DU/biaya lain/pagination/legacy, dan HTTP terautentikasi untuk pembayaran serta laporan dijalankan pada lingkungan lokal.

## 2026-08-17 - Pemisahan Tabungan dari Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengunci tahun ajaran pembayaran pada periode Juli-Juni, mempertahankan SPP bulanan berbasis `SPP_PERBULAN`, dan memisahkan input tabungan dari transaksi pembayaran.

**Perubahan fitur dan perilaku:**

- Input dan edit pembayaran tidak lagi menampilkan field Tabungan; bagian penyesuaian hanya berisi Potongan SPP dan Kewajiban SPP.
- Backend menolak POST lama dengan `tabungan_wajib` bernilai lebih dari nol dan mengarahkan kasir memakai menu Tabungan Masuk.
- Struk, export PDF, detail siswa, dan rekap kelas tidak lagi menambahkan tabungan yang terhubung pembayaran ke total transaksi pembayaran.
- Tahun ajaran laporan memakai helper `du_academic_year_label()` yang sama dengan pembayaran Daftar Ulang.

**Database dan migrasi:**

- Menambahkan `sql/remove_payment_linked_savings.sql` untuk mengurangi saldo tabungan dari jurnal pembayaran lama, lalu menghapus jurnal `transaksi_m` yang memiliki `bayar_id`.
- Migrasi dijalankan pada database lokal dan menghasilkan `remaining_payment_linked_savings = 0`.

**Kompatibilitas:**

- Kolom `transaksi_m.bayar_id` dipertahankan untuk kompatibilitas schema, tetapi alur pembayaran baru tidak mengisinya.
- Modul Tabungan Masuk, Tabungan Keluar, Riwayat Tabungan, serta laporan tabungan tetap berjalan sebagai sumber tabungan resmi.

**Verifikasi:**

- Cleanup SQL dijalankan pada database lokal, lalu diuji dengan fixture linked saving sementara; saldo turun sesuai nominal dan `transaksi_m.bayar_id IS NOT NULL` menjadi 0.
- PHP lint XAMPP untuk 37 file, Node syntax check `assets/js/app.js`, dan `sql/verify_schema.sql` berhasil.
- Regression test `spp_installment`, `academic_year_billing`, `student_optional_fees`, `registration_history_pagination`, `payment_process_integration`, dan `legacy_compatibility` berhasil.
- HTTP terautentikasi ke form dan edit pembayaran memastikan field `tabungan_wajib`, `tab-wajib`, dan label `Potongan & Tabungan` tidak muncul.

## 2026-08-06 - Pagination Riwayat Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menjaga halaman Riwayat Daftar Ulang tetap ringan dan mudah dinavigasi ketika data sekolah mencapai ratusan siswa.

**Perubahan fitur dan perilaku:**

- Riwayat memakai pagination server-side per tagihan siswa/tahun ajaran dengan pilihan 25, 50, atau 100 baris.
- Ringkasan jumlah siswa, tagihan, pembayaran, dan sisa tetap menghitung seluruh hasil filter, bukan hanya halaman aktif.
- Detail cicilan diambil sekaligus hanya untuk tagihan pada halaman aktif; pencarian, kelas, tahun ajaran, status, dan ukuran halaman dipertahankan saat navigasi.
- Navigasi desktop menyediakan Awal, Sebelumnya, nomor halaman, Berikutnya, dan Akhir; tampilan mobile diringkas menjadi tiga kontrol.

**Database dan migrasi:**

- Tidak ada perubahan schema atau data permanen. Indeks tagihan dan relasi cicilan yang sudah tersedia digunakan kembali.

**Kompatibilitas:**

- Urutan, filter, status Lunas/Belum Lunas, rincian transaksi, cetak, dan edit tetap dipertahankan.
- Parameter `page` dinormalisasi ke rentang valid dan `per_page` dibatasi pada `25`, `50`, atau `100`.

**Verifikasi:**

- Fixture sementara 105 tagihan menguji halaman 25/50/100, nomor global, tanpa duplikasi, ringkasan global, filter status, detail halaman aktif, preservasi query, serta parameter invalid; seluruh fixture kemudian dibersihkan.
- Regression test database 105 tagihan berjalan dalam transaction dan di-rollback.
- Syntax check, HTTP terautentikasi, tampilan data kosong/satu halaman, dan regression pembayaran Daftar Ulang berhasil.

## 2026-08-06 - Tarif Manual Makan, Sorga, dan Infaq

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyediakan sumber tagihan per siswa untuk Uang Makan, Sorga, dan Infaq agar ketiga komponen dapat dibayar serta dicicil melalui alur pembayaran resmi.

**Perubahan fitur dan perilaku:**

- Data Siswa Advance memperoleh tarif manual Makan, Sorga, dan Infaq sebagai tagihan satu kali per siswa.
- Input dan edit pembayaran menampilkan total, terbayar, dan sisa dari tarif siswa serta seluruh transaksi; tarif nol dan tagihan lunas mengunci input.
- Penurunan tarif di bawah akumulasi pembayaran ditolak. Nilai Advance tetap dipertahankan ketika panel ditutup saat edit.
- `bayar.U_MAKAN`, `U_SORGA`, dan `U_INFAQ` tetap hanya menyimpan nominal transaksi aktual; laporan dan struk memakai nilai tersebut seperti sebelumnya.

**Database dan migrasi:**

- Menambahkan kolom `siswa.MAKAN`, `siswa.SORGA`, dan `siswa.INFAQ` melalui `sql/add_student_optional_fees.sql` yang idempoten.
- Migrasi dijalankan dua kali. Seluruh siswa lama mendapat default Rp0 dan tidak ada transaksi lama yang diubah atau di-backfill.

**Kompatibilitas:**

- Master Biaya Lain dan sistem Tagihan Daftar Ulang tidak berubah.
- Bulan/tahun transaksi hanya mencatat waktu cicilan; saldo ketiga komponen dihitung sepanjang histori siswa.

**Verifikasi:**

- Uji HTTP terautentikasi mencakup tambah/edit siswa, preservasi Advance, cicilan Makan, Sorga, Infaq, penolakan tarif nol dan pembayaran berlebih, edit/hapus cicilan, penolakan penurunan tarif, struk, serta laporan.
- Data uji dibersihkan dan database kembali berisi tujuh siswa serta satu transaksi awal tanpa perubahan nominal.
- Syntax check PHP/JavaScript, regression test, migrasi dua kali, dan seluruh pemeriksaan schema berhasil.

## 2026-08-05 - Integrasi Penerbitan dan Cicilan Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menghubungkan tarif Master Daftar Ulang langsung dengan rincian pembayaran siswa dan memastikan tagihan dapat dicicil secara konsisten.

**Perubahan fitur dan perilaku:**

- Tahun ajaran draf memakai satu aksi `Simpan & Terbitkan Tagihan`; penyimpanan enam tarif, sinkronisasi penempatan internal, pembuatan tagihan, perubahan status, dan audit berjalan dalam satu transaksi.
- Form lama yang masih mengirim aksi penerbitan terpisah tetap dipetakan ke proses atomik yang sama.
- Input dan edit pembayaran hanya membaca `tagihan_daftar_ulang`; total, terbayar, sisa, tahun ajaran, kelas, dan status ditampilkan langsung pada baris Daftar Ulang.
- Warning tagihan tidak tersedia dipindahkan dari Potongan & Tabungan ke baris Daftar Ulang. Input dikunci bila tagihan tidak tersedia, dibatalkan, atau sudah lunas.
- Cicilan Daftar Ulang dapat dilakukan berkali-kali sampai sisa nol; pembayaran berlebih tetap ditolak server dan hidden field kelas/tahun ajaran tidak menjadi sumber otoritatif.

**Database dan migrasi:**

- Tidak ada tabel atau kolom baru.
- Master `2026/2027` dengan enam tarif Rp1.000.000 diterbitkan menjadi tujuh tagihan berdasarkan kelas tujuh siswa aktif.

**Kompatibilitas:**

- Snapshot kelas dan tahun ajaran pada `bayar_du` tetap dipertahankan, tetapi saldo baru selalu dihitung melalui relasi `tagihan_daftar_ulang_id`.
- Riwayat, edit, hapus, dan cetak transaksi lama tetap memakai kontrak relasi pembayaran yang sudah ada.

**Verifikasi:**

- Uji pemetaan memastikan Agustus 2026 dan Januari 2027 sama-sama memakai `2026/2027`.
- Uji HTTP terautentikasi membuktikan cicilan Rp400.000 lalu Rp600.000 melunasi tagihan, pembayaran tambahan ditolak, edit mengembalikan saldo, dan hapus kedua transaksi uji mengembalikan saldo ke nol.
- Syntax check PHP/JavaScript, regression test, pemeriksaan schema, halaman input/edit/master/riwayat, dan kondisi akhir database diperiksa tanpa mengubah transaksi pengguna.

## 2026-08-05 - Penyederhanaan Penerbitan Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menghilangkan pengelolaan penempatan massal dari UI dan mempertahankan penerbitan tagihan yang sederhana untuk sekolah dengan ratusan siswa.

**Perubahan fitur dan perilaku:**

- Master Daftar Ulang tidak lagi memuat atau menampilkan tabel Penempatan Siswa.
- Kelas pada Data Siswa menjadi sumber penerbitan; siswa tinggal kelas cukup mempertahankan kelasnya dan siswa pindah/lulus dinonaktifkan sebelum penerbitan.
- Tombol Terbitkan Tagihan membuat penempatan internal serta tagihan secara atomik berdasarkan seluruh siswa aktif kelas 1–6.

**Database dan migrasi:**

- Kolom, indeks, serta workflow konfirmasi penempatan eksperimental dibatalkan dan dibersihkan.
- Migrasi akademik tidak lagi mengubah tahun draf menjadi terbit hanya karena enam tarif sudah tersedia.

**Verifikasi:**

- Rollback memastikan tagihan tanpa pembayaran hasil migrasi eksperimental dihapus dan `2026/2027` kembali menjadi draf.
- Migrasi dijalankan dua kali tanpa menerbitkan draf atau membuat tagihan baru.
- Uji transaksi memastikan kelas mengikuti Data Siswa, siswa tidak aktif tidak ditagih, siswa baru tetap memperoleh tagihan, dan halaman master tidak memuat tabel penempatan.

## 2026-08-05 - Tagihan Daftar Ulang Berbasis Tahun Ajaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memisahkan tarif master dari tagihan siswa, menyimpan kelas per tahun ajaran Juli–Juni, dan menghubungkan cicilan Daftar Ulang ke tagihan yang diterbitkan.

**Perubahan fitur dan perilaku:**

- Master Daftar Ulang mengelola enam tarif kelas, pratinjau kenaikan kelas, status tahun ajaran, dan penerbitan tagihan massal.
- Input/edit pembayaran menghitung tahun ajaran dari periode pilihan, mengambil kelas dan saldo tagihan dari server, serta tidak mempercayai hidden field konteks DU.
- Riwayat Daftar Ulang sekarang menampilkan tagihan belum bayar, cicilan, dan lunas.
- Periode tunggakan dapat dibayar; periode masa depan ditolak.
- Mode tahunan Januari–Desember ditangguhkan untuk transaksi baru, tanpa menghapus histori batch lama.

**Database dan migrasi:**

- Menambahkan tabel tahun ajaran, penempatan, tagihan, audit DU, dan relasi tagihan pada `bayar_du` melalui `sql/add_academic_year_billing.sql`.
- Migrasi melakukan backfill aman untuk master, penempatan, tagihan, dan pembayaran DU lama serta dapat dijalankan ulang.

**Verifikasi:**

- Migrasi dijalankan dua kali dan seluruh pemeriksaan `sql/verify_schema.sql` berstatus `OK`.
- Regression test mencakup batas Juni/Juli, penerbitan idempoten, cicilan, dan pemulihan saldo setelah hapus.
- Halaman master, input, edit, serta riwayat dimuat melalui Apache tanpa fatal error; request tahunan dan periode masa depan ditolak server.

## 2026-08-04 - Tahun Ajaran Sistem dan Cicilan Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan daftar ulang sebagai tagihan tahunan SD berbasis tahun ajaran Juli-Juni dan memastikan cicilan DU terkunci ke master yang benar.

**Perubahan fitur dan perilaku:**

- Mengganti input manual tahun ajaran pada Master Daftar Ulang menjadi dropdown sistem.
- Tahun ajaran aktif dihitung otomatis dengan aturan Juli-Desember memakai `YYYY/YYYY+1`, sedangkan Januari-Juni memakai `YYYY-1/YYYY`.
- Dropdown tahun ajaran menampilkan tahun ajaran aktif +/- 3 tahun serta tahun master lama yang sudah ada.
- Form input pembayaran memakai tahun ajaran aktif sebagai default DU, bukan master terbaru.
- Kelas DU otomatis mengikuti kelas siswa saat siswa dipilih, selama admin belum mengubahnya manual.
- Input `Uang Daftar Ulang` dikunci saat master DU untuk kombinasi kelas/tahun ajaran belum tersedia, dan warning tetap tampil.
- Membump `app.js` pada halaman master/input/edit DU ke `v=4.2`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah schema atau route.
- Tahun ajaran lama yang sudah tersimpan tetap dimasukkan ke pilihan agar data master lama dapat diedit.
- Fallback ke data siswa tetap hanya berlaku bila tabel master DU benar-benar kosong total.

**Verifikasi:**

- `php -l master_daftar_ulang.php`, `php -l pembayaran/form.php`, `php -l pembayaran/edit.php`, dan `php -l pembayaran/proses.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser pada Master Daftar Ulang dan form pembayaran untuk memastikan default tahun ajaran aktif serta locking DU berjalan sesuai kombinasi master.

## 2026-08-04 - Perbaikan Fundamental Edit Pembayaran Dari Riwayat

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memastikan halaman edit pembayaran dari riwayat langsung memakai data terbaru dan menghitung sisa tagihan dengan mengecualikan transaksi yang sedang diedit.

**Perubahan fitur dan perilaku:**

- Halaman edit pembayaran sekarang otomatis mengikat siswa yang sedang diedit ke konteks datalist saat load.
- `Total Tagihan`, `Sudah Terbayar`, dan `Sisa` pada edit langsung dihitung dari data/master terbaru tanpa perlu mengetik ulang siswa.
- Nominal input transaksi yang sedang diedit tetap dipertahankan, sementara histori pembayaran lain mengecualikan `bayar.id` aktif.
- Data daftar ulang yang eksplisit terhubung melalui `bayar_du.bayar_id` dipakai untuk menguatkan konteks `kelas_du`, `th_ajaran`, dan nominal DU pada edit.
- Query histori edit untuk siswa, SPP/Komite periodik, DU, dan biaya lain diperbaiki memakai prepared statement.
- Membump `app.js` pada halaman riwayat pembayaran agar klik baris edit memakai script terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak menambah schema baru.
- Edit transaksi tetap hanya berlaku untuk pembayaran `payment_link_version=1`.
- Basis edit mengikuti master/tarif terbaru sesuai keputusan project; histori transaksi yang sedang diedit tidak dihitung ganda.

**Verifikasi:**

- `php -l pembayaran/edit.php`, `php -l pembayaran/proses.php`, dan `php -l pembayaran/lihat.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser dari halaman Riwayat Pembayaran dengan klik baris langsung, lalu cek rincian total/sudah/sisa pada transaksi yang punya histori lain.

## 2026-08-04 - Master Daftar Ulang dan Integrasi Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menjadikan `Daftar_ulang` sebagai master resmi nominal daftar ulang per kelas dan tahun ajaran, lengkap dengan CRUD admin dan integrasi pembayaran.

**Perubahan fitur dan perilaku:**

- Menambahkan halaman admin `master_daftar_ulang.php` untuk tambah, edit, hapus, dan daftar nominal daftar ulang per `Tahun Ajaran + Kelas`.
- Menambahkan menu sidebar `Master Daftar Ulang` khusus admin.
- Form input dan edit pembayaran sekarang membaca master daftar ulang dari database untuk dropdown tahun ajaran dan nominal DU.
- Jika master daftar ulang kosong total, pembayaran tetap fallback ke data siswa agar alur lama kompatibel.
- Jika master sudah ada tetapi kombinasi kelas/tahun ajaran belum tersedia, form menampilkan warning dan nominal DU menjadi `0` sampai master dilengkapi.
- Backend pembayaran menghitung ulang nominal DU dari master/fallback resmi dan menolak input DU bila total tagihan belum tersedia atau pembayaran melebihi sisa.

**Database dan migrasi:**

- Menambahkan migrasi idempoten `sql/add_master_daftar_ulang.sql`.
- Memperbarui `sql/schema.sql` agar `Daftar_ulang(th_ajaran, kelas)` memiliki unique key `uk_daftar_ulang_period_class`.
- Memperbarui `sql/verify_schema.sql` untuk memeriksa tabel `Daftar_ulang` dan unique key daftar ulang.

**Kompatibilitas dan data lama:**

- Tidak mengubah route lama pembayaran.
- Histori `bayar_du` tetap dipakai untuk menghitung `Sudah Terbayar` dan `Sisa`.
- Fallback ke kolom daftar ulang di tabel siswa hanya berlaku bila tabel master DU benar-benar belum berisi data.

**Verifikasi:**

- `sql/add_master_daftar_ulang.sql` dijalankan dua kali pada database lokal tanpa error.
- `sql/verify_schema.sql` mengembalikan `OK` untuk seluruh requirement, termasuk `table.Daftar_ulang` dan `uk_daftar_ulang_period_class`.
- `php -l master_daftar_ulang.php`, `php -l includes/sidebar.php`, `php -l pembayaran/form.php`, `php -l pembayaran/edit.php`, dan `php -l pembayaran/proses.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Isi master daftar ulang sesuai kebijakan sekolah, misalnya tahun ajaran terbaru dan nominal per kelas.

## 2026-08-04 - Alert Input Pembayaran Melebihi Sisa

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memberi peringatan langsung saat nominal input pembayaran lebih besar dari sisa tagihan.

**Perubahan fitur dan perilaku:**

- Menambahkan alert inline khusus `payment-input-overlimit-alert` pada form input dan edit pembayaran.
- Menambahkan validasi browser-side untuk membandingkan `Input Bayar` dengan sisa sebelum input (`Total Tagihan - Sudah Terbayar`).
- Input yang melebihi sisa diberi invalid state, barisnya diberi highlight, dan form ditahan dengan `setCustomValidity()`.
- Nilai input user tidak dihapus otomatis agar pengguna dapat melihat nominal yang salah.
- Membump `app.js` halaman input/edit pembayaran ke `v=4.0`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah route, schema, atau data. Backend tetap menjadi sumber kebenaran dan tetap menolak pembayaran yang melebihi sisa.

**Verifikasi:**

- `php -l pembayaran/form.php` dan `php -l pembayaran/edit.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check -- assets/css/style.css assets/js/app.js pembayaran/form.php pembayaran/edit.php documentation/PROJECT_CONTEXT.md documentation/AI_CHANGELOG.md` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser: input lebih dari sisa, sama dengan sisa, lebih kecil dari sisa, dan input pada komponen dengan total nol.

## 2026-08-04 - Rincian Pembayaran Visual-Only Untuk Nilai Sistem

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperjelas logika akuntansi pada form pembayaran agar nilai histori tidak terlihat seperti input manual.

**Perubahan fitur dan perilaku:**

- Mengganti label `Total Sebelum (Rp)` menjadi `Total Tagihan (Rp)` pada form input dan edit pembayaran.
- Menambahkan microcopy bahwa total, sudah terbayar, dan sisa dihitung otomatis dari riwayat transaksi.
- Membuat kolom `Total Tagihan`, `Sudah Terbayar`, dan `Sisa` menjadi readonly visual-only dengan style berbeda dari `Input Bayar`.
- Mempertahankan `Input Bayar` sebagai satu-satunya kolom yang dapat diedit pengguna.
- Membump `style.css` halaman input/edit pembayaran ke `v=4.6`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah route, schema, atau data. Sumber `Sudah Terbayar` tetap berasal dari transaksi, data migrasi/saldo awal siswa, `bayar_du`, dan `bayar_biaya_lain` sesuai komponen masing-masing.

**Verifikasi:**

- `php -l pembayaran/form.php` dan `php -l pembayaran/edit.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check -- assets/css/style.css pembayaran/form.php pembayaran/edit.php documentation/PROJECT_CONTEXT.md documentation/AI_CHANGELOG.md` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser: pilih siswa dengan/ tanpa histori, ubah periode SPP/Komite, ubah kelas/tahun daftar ulang, lalu pastikan hanya kolom `Input Bayar` yang bisa diketik.

## 2026-08-03 - Penyesuaian Label Riwayat Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan istilah tampilan agar lebih jelas sebagai riwayat transaksi.

**Perubahan fitur dan perilaku:**

- Mengganti label menu dan halaman `Lihat Pembayaran` menjadi `Riwayat Pembayaran`.
- Mengganti breadcrumb halaman pembayaran dari `Lihat` menjadi `Riwayat`.
- Mengganti kolom `Transaksi` pada daftar siswa menjadi `Riwayat Transaksi`, termasuk label responsif mobile.
- Merapikan alignment kolom `Riwayat Transaksi` pada daftar siswa dan membump `style.css` halaman siswa ke `v=4.5`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah route, file, atau data. Perubahan hanya teks UI.

**Verifikasi:**

- `php -l includes/sidebar.php`, `php -l pembayaran/lihat.php`, dan `php -l siswa/daftar.php` berhasil.
- `git diff --check -- assets/css/style.css includes/sidebar.php pembayaran/lihat.php siswa/daftar.php documentation/AI_CHANGELOG.md` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Tidak ada.

## 2026-08-02 - Sinkronisasi Database Lama dan Kompatibilitas Kelas

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperbaiki fatal error dashboard dan menyelaraskan database lokal lama dengan kebutuhan schema aplikasi saat ini tanpa menghapus data siswa.

**Perubahan fitur dan perilaku:**

- Master siswa tetap membatasi data baru ke kelas 1 sampai 6.
- Label kelas legacy pada siswa yang sudah ada dapat dipertahankan saat diedit dan tersedia pada filter daftar siswa.
- Pengurutan daftar siswa menempatkan kelas SD sebelum label kelas legacy.

**Database dan migrasi:**

- Memperbarui `sql/add_student_advanced.sql` agar migrasi tetap menambahkan kolom, tipe nominal, audit, dan Uang Komite saat database memuat label kelas legacy maksimal 5 karakter.
- Constraint kelas SD hanya ditambahkan bila seluruh kelas existing sudah bernilai 1 sampai 6.
- Memperluas `sql/verify_schema.sql` agar memeriksa seluruh tabel, kolom, dan index utama dari paket migrasi saat ini.
- Menjalankan seluruh migrasi upgrade pada `db_spp` setelah backup.

**Kompatibilitas dan data lama:**

- Data siswa, akun, dan transaksi lama dipertahankan. Label kelas legacy tidak dipetakan atau diubah otomatis.

**Verifikasi:**

- Migrasi dijalankan dua kali untuk memeriksa idempotensi.
- Verifikasi schema, lint seluruh file PHP, query dashboard, dan smoke test HTTP lokal dijalankan.

**Catatan tindak lanjut:**

- Label kelas legacy dapat dikonversi manual ke kelas 1 sampai 6 bila sekolah tidak lagi membutuhkannya.

## 2026-08-02 - Dokumentasi Progress Flow Sistem

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mencatat progres tiap flow aplikasi dan alur yang sudah berubah agar mudah dipakai untuk laporan.

**Perubahan fitur dan perilaku:**

- Memperluas `documentation/PROGRESS.md` dengan register temuan terbaru dan log perubahan alur aplikasi.
- Mencatat perubahan flow login, dashboard, data siswa, pembayaran, daftar ulang, master biaya lain, lihat pembayaran, tabungan, laporan/export, mobile, dan tema.
- Memperbarui MoM agar perubahan daftar ulang, biaya lain cicilan, dan klik baris untuk edit ikut tercatat pada laporan.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah kode aplikasi atau data; perubahan hanya dokumentasi.

**Verifikasi:**

- `git diff --check -- documentation/PROGRESS.md documentation/MOM_SistemSPP.md documentation/AI_CHANGELOG.md` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Gunakan `PROGRESS.md` sebagai catatan flow teknis dan `MOM_SistemSPP.md` sebagai bahan laporan rapat/project.

## 2026-08-02 - Perbaikan Master Daftar Ulang Pada Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memastikan pencatatan pembayaran daftar ulang memakai konteks master kelas dan tahun ajaran dengan benar.

**Perubahan fitur dan perilaku:**

- Form input dan edit pembayaran memuat master `Daftar_ulang` sebagai sumber nominal daftar ulang per `kelas + tahun ajaran` saat data master tersedia.
- Jika master daftar ulang belum diisi, sistem tetap memakai fallback nominal daftar ulang dari data siswa agar transaksi lama tidak putus.
- Hitungan `sudah terbayar` daftar ulang kini dipisah per `NO_INDUK + kelas + tahun ajaran`, sehingga pembayaran daftar ulang tahun/kelas lain tidak mengurangi sisa periode yang sedang dipilih.
- Validasi backend simpan dan update pembayaran memakai konteks `kelas_du` dan `tahun_ajaran_du`; jika ada duplikat master, data terbaru berdasarkan `id` dipakai.
- Membump `app.js` pada form pembayaran ke `v=3.9`.

**Database dan migrasi:**

- Tidak ada migrasi baru. Pemeriksaan lokal menunjukkan tabel `Daftar_ulang` ada tetapi belum berisi data.

**Kompatibilitas dan data lama:**

- Data lama tetap kompatibel lewat fallback ke nominal daftar ulang pada tabel `siswa`.
- Transaksi `bayar_du` lama tetap dibaca, tetapi perhitungan sisa hanya memakai transaksi yang punya `kelas` dan `th_ajaran`.

**Verifikasi:**

- `php -l pembayaran/form.php`, `php -l pembayaran/edit.php`, dan `php -l pembayaran/proses.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Isi/import master `Daftar_ulang` untuk setiap kelas dan tahun ajaran agar nominal daftar ulang benar-benar berasal dari master.

## 2026-08-02 - Klik Baris Untuk Edit Siswa

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mempercepat akses edit data siswa tanpa harus menekan tombol `Edit`.

**Perubahan fitur dan perilaku:**

- Baris siswa pada `siswa/daftar.php` dapat diklik untuk membuka mode edit siswa.
- Klik pada tombol `Edit`, form `Arsipkan/Pulihkan`, dan elemen interaktif lain tetap menjalankan aksi masing-masing tanpa ikut terkena navigasi baris.
- Reuse handler row-click global dengan dukungan keyboard `Enter`/`Space`.
- Membump `style.css` halaman data siswa ke `v=4.3` dan `app.js` ke `v=3.8`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah data siswa. Perubahan hanya pada navigasi tabel.

**Verifikasi:**

- `php -l siswa/daftar.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser: klik area nama/NIS siswa untuk edit, lalu klik `Arsipkan/Pulihkan` untuk memastikan confirm status tetap muncul.

## 2026-08-02 - Klik Baris Untuk Edit Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mempercepat akses edit pembayaran dari halaman lihat pembayaran dan dashboard tanpa harus menekan tombol `Edit`.

**Perubahan fitur dan perilaku:**

- Baris transaksi pada `pembayaran/lihat.php` dapat diklik untuk membuka halaman edit.
- Baris `Transaksi Terbaru` pada `dashboard.php` dapat diklik untuk membuka halaman edit.
- Klik pada tombol `Edit`, `Hapus`, dan elemen interaktif lain tetap menjalankan aksi masing-masing tanpa ikut terkena navigasi baris.
- Baris clickable diberi cursor pointer, hover state, dan dukungan keyboard `Enter`/`Space`.
- Membump `style.css` halaman lihat pembayaran dan dashboard ke `v=4.3`, serta `app.js` ke `v=3.8`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Hanya transaksi dengan `payment_link_version=1` yang bisa diklik untuk edit. Transaksi legacy tetap tidak bisa diedit dari row click.

**Verifikasi:**

- `php -l pembayaran/lihat.php` dan `php -l dashboard.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser: klik area nama/NIS/total untuk edit, lalu klik `Hapus` untuk memastikan confirm hapus tetap muncul.

## 2026-08-02 - Jam Update Pada Lihat Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menampilkan keterangan jam bayar dan jam update pada rekap pembayaran siswa agar perubahan transaksi lebih mudah dilacak.

**Perubahan fitur dan perilaku:**

- Menetapkan timezone aplikasi PHP ke `Asia/Jakarta` dan session MySQL ke `+07:00` melalui `koneksi.php`.
- Kolom `Tanggal` pada `pembayaran/lihat.php` diubah menjadi `Bayar / Update`.
- Nilai tanggal bayar ditampilkan bersama jam `HH:mm WIB` dari `TGL_BYR`.
- Menampilkan label `Diubah` saat `bayar.updated_at` lebih baru dari `created_at`.
- Menambahkan styling `date-time-cell` agar tanggal dan jam tetap rapi di tabel desktop maupun mobile.
- Membump asset `style.css` pada halaman lihat pembayaran ke `v=4.2` dan `app.js` ke `v=3.6`.

**Database dan migrasi:**

- Menambahkan `sql/add_payment_updated_at.sql`.
- Menambahkan kolom `bayar.updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP`.
- Migrasi sudah dijalankan pada database lokal `db_spp`.

**Kompatibilitas dan data lama:**

- Data lama tetap tampil. Migrasi menginisialisasi `updated_at` dari `created_at`; label `Diubah` hanya tampil setelah transaksi benar-benar diedit lagi.
- Jika waktu historinya kosong atau tidak valid, sistem menampilkan tanda `-`.

**Verifikasi:**

- `php -l pembayaran/lihat.php` berhasil.
- `php -l koneksi.php` berhasil.
- `SHOW COLUMNS FROM bayar LIKE 'updated_at'` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Bila membutuhkan audit edit yang lebih detail, tambahkan tabel audit pembayaran agar setiap perubahan historis tersimpan, bukan hanya waktu update terakhir.

## 2026-08-02 - Cicilan Biaya Lain

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah konsep biaya lain agar tidak wajib dibayar penuh dalam satu transaksi.

**Perubahan fitur dan perilaku:**

- Form input dan edit pembayaran menampilkan `Total`, `Sudah`, `Sisa`, dan `Input Bayar` pada setiap baris biaya lain.
- Setiap field biaya lain diberi label kecil agar fungsi kolom `Total`, `Sudah`, `Sisa`, `Bayar`, dan `Keterangan` tidak membingungkan.
- `Input Bayar` biaya lain dibuat editable sehingga master seperti `Buku Rp 500.000` dapat dibayar bertahap.
- Frontend menghitung sisa biaya lain berdasarkan siswa terpilih dan pembayaran sebelumnya.
- Menambahkan alert visual khusus biaya lain saat `Bayar` melebihi `Sisa`.
- Merapikan alignment nomor baris dan tombol hapus pada grid biaya lain agar sejajar dengan input.
- Backend menyimpan nominal cicilan aktual ke `bayar_biaya_lain.nominal_snapshot`.
- Backend menolak nominal biaya lain yang negatif atau melebihi sisa tagihan siswa untuk master biaya tersebut.
- Reset form pembayaran membersihkan alert overpaid, highlight baris, disabled state, dan validasi custom yang tersisa.
- Membump versi `style.css` pada form input/edit pembayaran ke `v=4.4` dan `app.js` ke `v=3.7`.
- Memperbarui MoM dan konteks proyek terkait konsep cicilan biaya lain.

**Database dan migrasi:**

- Tidak ada. Struktur `master_biaya_lain` dan `bayar_biaya_lain` yang sudah ada cukup untuk menyimpan total master dan nominal cicilan transaksi.

**Kompatibilitas dan data lama:**

- Histori tetap memakai snapshot nama dan nominal bayar yang sudah tersimpan.
- Master nonaktif tetap bisa tampil saat mengedit transaksi lama yang sudah memakai master tersebut.

**Verifikasi:**

- `php -l pembayaran/form.php`, `php -l pembayaran/edit.php`, dan `php -l pembayaran/proses.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual di browser: pilih siswa, pilih biaya lain, ubah `Input Bayar` menjadi cicilan, lalu coba input melebihi sisa untuk memastikan validasi muncul.

## 2026-08-02 - Alert Pembayaran Melebihi Tagihan

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memberi peringatan saat nilai `Sudah Terbayar` lebih besar dari `Total Sebelum` pada form pembayaran.

**Perubahan fitur dan perilaku:**

- Menambahkan alert overpaid pada form input dan edit pembayaran.
- Menandai baris komponen yang sudah overpaid dan mengunci `Input Bayar` komponen tersebut ke `0`.
- Menambahkan validasi backend agar input komponen baru tidak boleh melebihi sisa tagihan.
- Membump versi `app.js` pada form input dan edit pembayaran ke `v=3.4`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah data lama. Jika data lama sudah overpaid, form akan memberi peringatan dan mencegah penambahan pembayaran pada komponen tersebut.

**Verifikasi:**

- `php -l pembayaran/proses.php`, `php -l pembayaran/form.php`, dan `php -l pembayaran/edit.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual di browser dengan kondisi contoh: `Total Sebelum Rp 100.000` dan `Sudah Terbayar Rp 200.000`.

## 2026-08-02 - Cetak Slip PDF Per Transaksi Terpilih

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan konsep cetak slip selektif dari detail transaksi pembayaran tanpa mengubah fungsi export PDF semua transaksi.

**Perubahan fitur dan perilaku:**

- Menambahkan checkbox pada tabel detail transaksi pembayaran di `laporan/index.php`.
- Menambahkan tombol `Cetak Dipilih` yang aktif setelah minimal satu transaksi dipilih.
- Menambahkan tombol `Cetak` per baris transaksi untuk membuat slip satu transaksi.
- Menambahkan dukungan parameter `mode=selected` dan `ids[]` pada `laporan/export_pdf.php`.
- Mempertahankan tombol `Export PDF` periode sebagai cetak semua transaksi.
- Membump versi `style.css` ke `v=3.9` agar styling tombol dan checkbox terbaru terambil browser.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- URL export PDF lama tetap berlaku untuk mencetak seluruh transaksi periode. Parameter baru hanya mempersempit hasil PDF bila dipakai.

**Verifikasi:**

- `php -l laporan/index.php` dan `php -l laporan/export_pdf.php` berhasil.
- Pemeriksaan manual memastikan form filter, form cetak terpilih, checkbox, dan tombol per baris berada pada struktur HTML yang sesuai.

**Catatan tindak lanjut:**

- Uji visual di browser: pilih beberapa transaksi, klik `Cetak Dipilih`, dan pastikan PDF hanya berisi slip yang dipilih.

## 2026-08-02 - Perbaikan Insert Pembayaran Baru

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperbaiki error penyimpanan pembayaran baru setelah penambahan kolom relasi pembayaran dan metode pembayaran.

**Perubahan fitur dan perilaku:**

- Menambahkan satu placeholder pada query `INSERT INTO bayar` di `pembayaran/proses.php` agar jumlah value sesuai dengan jumlah kolom.
- Mempertahankan `payment_link_version=1` sebagai nilai literal untuk transaksi baru.

**Database dan migrasi:**

- Tidak ada perubahan schema baru. Database lokal sebelumnya sudah menjalankan `add_payment_references.sql` dan `add_payment_method.sql`.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data lama. Perbaikan hanya memulihkan proses simpan pembayaran baru.

**Verifikasi:**

- `php -l pembayaran/proses.php` berhasil.
- Kolom `bayar.payment_link_version` dan `bayar.sistem_pembayaran` tersedia pada database lokal.

**Catatan tindak lanjut:**

- Uji simpan pembayaran baru melalui browser menggunakan data siswa uji.

## 2026-07-31 - Pembaruan Dokumen MoM

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperbarui Minutes of Meeting agar sesuai dengan kondisi terbaru project setelah revisi UI, export, pembayaran, dan push repository.

**Perubahan fitur dan perilaku:**

- Memperbarui `documentation/MOM_SistemSPP.md` dengan tanggal rapat terbaru, repository, commit terakhir yang sudah dipush, ringkasan fitur, hasil revisi, keputusan, action items, risiko, dan lampiran modul.
- Menambahkan pembahasan sistem pembayaran `Tunai`, `VA`, dan `Qris`.
- Menyesuaikan bagian laporan agar menyebut PDF server-side Dompdf dan preview Excel sebelum download.
- Menambahkan catatan revisi mobile, avatar role, dark mode, dan riwayat tabungan.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan perilaku aplikasi. Perubahan hanya pada dokumentasi project.

**Verifikasi:**

- Review manual isi MoM berdasarkan changelog dan konteks project terbaru.

**Catatan tindak lanjut:**

- Lengkapi placeholder waktu, tempat, pemimpin rapat, notulis, peserta, PIC, dan target tanggal sesuai kebutuhan laporan.

## 2026-07-31 - Perapihan Mobile Preview Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan tampilan preview export Excel pada layar mobile agar tombol, ringkasan, dan tabel lebih nyaman dibaca.

**Perubahan fitur dan perilaku:**

- Mengubah area preview Excel mobile menjadi full-width tanpa shadow/card besar yang membuat ruang terasa sempit.
- Merapikan tombol `Download Excel` dan `Kembali` pada mobile agar tinggi dan jaraknya konsisten.
- Membungkus setiap tabel laporan dalam container scroll tersendiri, sehingga tabel tidak dipaksa mengecil dan kolom tetap terbaca.
- Mempertahankan tabel komponen pembayaran sebagai tabel compact agar tetap pas di layar mobile.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data. Download Excel tetap memakai URL dan format `.xls` yang sama.

**Verifikasi:**

- `php -l laporan/export_excel.php` berhasil.
- Test HTTP lokal preview Excel berhasil status `200` dan memuat wrapper tabel mobile.
- Test HTTP lokal download Excel berhasil status `200` dengan `Content-Type: application/vnd.ms-excel; charset=UTF-8` dan attachment `Laporan_SPP_Juli_2026.xls`.

**Catatan tindak lanjut:**

- Uji visual langsung di viewport mobile browser untuk memastikan scroll horizontal per tabel terasa nyaman.

## 2026-07-31 - Perapihan Layout Slip PDF

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan konsep slip PDF server-side agar tetap memakai format baru tetapi tidak berantakan atau terpecah halaman.

**Perubahan fitur dan perilaku:**

- Mengubah template slip PDF dari layout berbasis `div/grid` menjadi layout tabel HTML yang lebih stabil untuk Dompdf.
- Menyesuaikan ukuran konten slip agar total halaman pas pada kertas landscape `210mm x 148mm`.
- Menghapus sisa toolbar/aksi cetak HTML dari output slip PDF.
- Mempertahankan contoh slip otomatis ketika periode belum memiliki transaksi.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun URL export. Endpoint `laporan/export_pdf.php` tetap mengembalikan PDF server-side.

**Verifikasi:**

- `php -l laporan/export_pdf.php` berhasil.
- Test HTTP lokal export PDF berhasil mengembalikan `Content-Type: application/pdf`, file berawalan `%PDF`, satu page object, dan MediaBox `595.276 x 419.528` pt.
- `composer validate --strict` berhasil; `composer audit` tidak menemukan security advisory, dengan peringatan sebagian metadata Packagist memakai cache lokal karena timeout koneksi.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual langsung di Chrome PDF viewer setelah refresh tab export.

## 2026-07-31 - Perbaikan Mobile Riwayat dan Preview Export

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan tampilan mobile riwayat tabungan dan preview Excel, serta mengembalikan konsep slip saat periode belum memiliki transaksi.

**Perubahan fitur dan perilaku:**

- Merapikan form filter riwayat tabungan mobile agar field dan tombol tidak saling menekan.
- Merapikan tombol `Tabungan Masuk` dan `Tabungan Keluar` pada card riwayat tabungan mobile.
- Mengubah tabel riwayat tabungan dan rekap saldo menjadi responsive card pada mobile.
- Merapikan toolbar dan tabel preview Excel mobile agar tidak membuat halaman melebar.
- Export PDF kini otomatis menampilkan slip contoh saat periode belum memiliki transaksi, sehingga konsep slip tidak berubah menjadi halaman kosong.
- Membump versi `style.css` ke `v=3.8` agar browser mengambil styling mobile terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. Slip contoh hanya tampil sebagai preview PDF saat periode kosong dan tidak menyimpan data.

**Verifikasi:**

- Lint PHP berhasil untuk `tabungan/riwayat.php`, `laporan/export_excel.php`, `laporan/export_pdf.php`, dan halaman yang memakai `style.css`.
- Pencarian versi lama `style.css?v=3.0` sampai `v=3.7` tidak menemukan sisa pada file PHP.
- Test HTTP lokal export PDF periode kosong berhasil mengembalikan file `%PDF` dengan MediaBox `595.276 x 419.528` pt atau `210mm x 148mm`.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual mobile pada Riwayat Tabungan dan Preview Excel di browser.

## 2026-07-31 - Ukuran Cetak Slip Landscape

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan hasil cetak slip pembayaran agar tidak memakai A4 portrait penuh, menghapus logo dari slip, dan menghilangkan header/footer bawaan Chrome.

**Perubahan fitur dan perilaku:**

- Menghapus logo dari header slip pembayaran.
- Mengubah ukuran print slip menjadi landscape `210mm x 148mm`.
- Mengubah export slip dari HTML print browser menjadi PDF asli yang dirender server-side dengan Dompdf.
- Menghapus toolbar print HTML dari template PDF agar file hanya berisi isi slip.
- Menyederhanakan judul browser menjadi `Slip Pembayaran`.
- Menambahkan `vendor/` ke `.gitignore`; dependency dipulihkan lewat `composer install`.

**Database dan migrasi:**

- Tidak ada perubahan database.
- Menambahkan dependency Composer `dompdf/dompdf` versi `3.1.6`.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. URL export PDF lama tetap sama, tetapi response kini berupa `application/pdf` dari server.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- `composer audit` berhasil tanpa security advisory setelah Dompdf diperbarui ke `3.1.6`.
- Test HTTP lokal setelah login berhasil mengembalikan `Content-Type: application/pdf` dan file berawalan `%PDF`.
- Pencarian di `laporan/export_pdf.php` memastikan ukuran `210mm x 148mm` sudah dipakai, referensi logo slip sudah tidak ada, dan toolbar print HTML sudah dihapus.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual file PDF di browser untuk memastikan posisi slip sesuai contoh cetak.

## 2026-07-31 - Logo pada Slip Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan logo sekolah pada slip pembayaran agar header slip tidak hanya berisi teks.

**Perubahan fitur dan perilaku:**

- Menambahkan favicon pada halaman preview/cetak slip PDF.
- Menambahkan logo `assets/img/school-logo.png` pada header slip pembayaran.
- Menyesuaikan layout header slip agar logo berada di kiri dan judul sekolah tetap rata tengah.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. Perubahan hanya pada tampilan slip.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- Asset `assets/img/school-logo.png` dan `assets/img/favicon.png` tersedia.
- Pencarian di `laporan/export_pdf.php` memastikan path logo dan favicon sudah dipasang.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji cetak/save PDF di browser untuk memastikan logo tampil pada hasil cetak.

## 2026-07-31 - Palet Dark Mode Lebih Ramah

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah palet dark mode agar tampilan tidak terlalu hijau pekat dan lebih nyaman dibaca.

**Perubahan fitur dan perilaku:**

- Mengubah variable dark mode dari palet hijau pekat menjadi dasar neutral charcoal/slate dengan aksen emerald.
- Mengurangi intensitas glow dan orb background agar area dashboard terasa lebih bersih.
- Menyesuaikan sidebar, topbar, active menu, kartu statistik, tabel, badge, search box, tab, dan bottom navigation agar kontras lebih ramah mata.
- Menyesuaikan dark mode halaman login agar selaras dengan palet baru.
- Membump versi `style.css` ke `v=3.7` dan `login.css` ke `v=3.4` agar browser mengambil styling terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data, role, maupun alur fitur. Perubahan hanya pada tampilan dark mode.

**Verifikasi:**

- Lint PHP berhasil untuk halaman yang memuat stylesheet utama: dashboard, login, master biaya lain, role management, sidebar, laporan, pembayaran, siswa, dan tabungan.
- Pencarian versi lama `style.css?v=3.0` sampai `v=3.6` dan `login.css?v=3.0` sampai `v=3.3` tidak menemukan sisa pada file PHP.
- Pencarian warna dark mode lama hanya menyisakan override light mode yang memang terpisah.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual dark mode di dashboard, form pembayaran, tabel laporan, dan halaman login.

## 2026-07-31 - Logout Mobile Sidebar

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memastikan tombol logout bisa diakses dengan nyaman pada tampilan mobile.

**Perubahan fitur dan perilaku:**

- Menambahkan label `Logout` pada tombol logout sidebar agar lebih jelas di mobile.
- Menyembunyikan bottom navigation saat sidebar mobile terbuka supaya tidak menutupi footer sidebar.
- Menaikkan prioritas tampilan sidebar mobile dan menambahkan safe-area padding pada footer.
- Membump versi `style.css` ke `v=3.6` pada halaman utama agar browser mengambil styling mobile terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun hak akses. Logout tetap memakai `logout.php`.

**Verifikasi:**

- `C:\xampp\php\php.exe -l includes\sidebar.php` berhasil.
- `C:\xampp\php\php.exe -l login.php` berhasil.
- Pencarian `style.css?v=3.0` sampai `v=3.5` tidak menemukan sisa pada file PHP.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual di mobile: buka sidebar, pastikan bottom nav hilang dan tombol logout terlihat di footer.

## 2026-07-31 - Animasi Transisi Login Per Role

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan animasi masuk setelah login berhasil agar perpindahan ke halaman sesuai role terasa lebih halus.

**Perubahan fitur dan perilaku:**

- Mengubah redirect login valid dari redirect server instan menjadi render transisi singkat lalu redirect via JavaScript.
- Menambahkan overlay transisi kiri-kanan dengan panel hijau dan oranye sebelum pengguna diarahkan ke halaman role.
- Menonaktifkan tombol login saat transisi berjalan dan menampilkan status `Masuk...`.
- Membump versi `login.css` ke `v=3.3` agar browser mengambil animasi terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data atau hak akses role. Tujuan redirect tetap sama: kasir ke tabungan masuk, bendahara ke laporan, role lain ke dashboard.

**Verifikasi:**

- `C:\xampp\php\php.exe -l login.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual login untuk role admin, bendahara, dan kasir di browser.

## 2026-07-31 - Avatar Sidebar Per Role

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan tampilan profile role di sidebar agar lebih mirip avatar profile pada contoh.

**Perubahan fitur dan perilaku:**

- Menambahkan asset `assets/img/profile-avatar.png` untuk avatar profile sidebar.
- Mengubah avatar footer sidebar menjadi gambar profile dengan badge inisial role: `AD` untuk admin, `BD` untuk bendahara, dan `KS` untuk kasir.
- Menambahkan warna badge berbeda per role serta styling border dan shadow agar tampil seperti profile badge.
- Menyesuaikan styling light mode agar avatar tetap terlihat rapi.
- Mengunci ukuran avatar lewat atribut gambar dan CSS agar gambar tidak tampil pada ukuran asli saat cache CSS lama masih tersisa.
- Membump versi `style.css` ke `v=3.5` pada halaman utama agar browser mengambil styling avatar terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data maupun hak akses.

**Verifikasi:**

- Lint seluruh file PHP berhasil.
- Pencarian versi lama `style.css?v=3.2`, `v=3.3`, dan `v=3.4` tidak menemukan sisa pada file PHP.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual sebagai admin, bendahara, dan kasir untuk memastikan avatar role sesuai.

## 2026-07-31 - Sistem Pembayaran Tunai VA Qris

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan pilihan sistem pembayaran pada transaksi pembayaran sekolah.

**Perubahan fitur dan perilaku:**

- Menambahkan pilihan `Tunai`, `VA`, dan `Qris` pada form input dan edit pembayaran.
- Menambahkan validasi backend agar hanya tiga metode pembayaran tersebut yang dapat disimpan.
- Menampilkan sistem pembayaran pada daftar pembayaran, laporan web, export Excel, dan slip PDF.
- Slip PDF tidak lagi hardcoded `VA`, tetapi memakai metode dari transaksi.

**Database dan migrasi:**

- Menambahkan kolom `bayar.sistem_pembayaran` melalui `sql/add_payment_method.sql`.
- Memperbarui `sql/schema.sql` agar instalasi baru langsung memiliki kolom sistem pembayaran.

**Kompatibilitas dan data lama:**

- Data transaksi lama memakai default `VA`.
- Tidak ada perubahan struktur tabel selain penambahan kolom baru pada `bayar`.

**Verifikasi:**

- Migrasi `sql/add_payment_method.sql` berhasil dijalankan pada database lokal dan dijalankan ulang tanpa error.
- Verifikasi schema lokal menunjukkan `bayar.sistem_pembayaran` bertipe `enum('Tunai','VA','Qris')`, `NOT NULL`, default `VA`.
- Lint seluruh file PHP berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji input dan edit transaksi dengan metode `Tunai`, `VA`, dan `Qris`.

## 2026-07-31 - Penyempurnaan Tampilan Preview Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan halaman preview Excel agar tidak banyak area kosong dan paletnya sesuai tema hijau-oranye.

**Perubahan fitur dan perilaku:**

- Mengubah palet preview Excel dari ungu menjadi hijau dengan aksen oranye.
- Membuat tabel preview melebar penuh di dalam lembar preview agar area kosong berkurang.
- Menambahkan header laporan yang lebih ringkas dan kartu ringkasan total pembayaran, tabungan masuk, dan tabungan keluar.
- Mengurangi jarak kosong antar section tabel dan memperbaiki `colspan` header tabel.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Perubahan hanya memengaruhi tampilan preview dan gaya HTML export.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_excel.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.
- Pencarian warna ungu lama dan `colspan` tabel yang tidak sesuai tidak menemukan sisa di `laporan/export_excel.php`.

**Catatan tindak lanjut:**

- Uji visual di browser pada periode kosong dan periode berisi data.

## 2026-07-31 - Preview Sebelum Download Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah alur Export Excel agar pengguna dapat melihat preview sebelum mengunduh file.

**Perubahan fitur dan perilaku:**

- Menambahkan mode preview default pada `laporan/export_excel.php`.
- Download file `.xls` hanya dilakukan saat URL memakai parameter `download=1`.
- Menambahkan tombol `Download Excel` dan `Kembali` pada halaman preview.
- Menambahkan empty state untuk komponen pembayaran, transaksi pembayaran, dan transaksi tabungan saat periode belum memiliki data.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. URL export lama kini menampilkan preview terlebih dahulu, sedangkan format download tetap `.xls`.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_excel.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji manual download dari preview untuk memastikan browser menerima file `.xls` sesuai periode yang dipilih.

## 2026-07-31 - Export Laporan Tetap di Tab yang Sama

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mencegah tab browser menumpuk saat pengguna membuka export laporan.

**Perubahan fitur dan perilaku:**

- Menghapus `target="_blank"` pada tombol Export Excel dan Export PDF di `laporan/index.php`.
- Export laporan kini dibuka dari tab yang sama agar alur navigasi lebih rapi.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Perubahan hanya memengaruhi perilaku navigasi link export.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\index.php` berhasil.
- Pencarian `target="_blank"` tidak menemukan penggunaan tersisa di kode aplikasi.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji manual dari halaman Laporan di browser untuk memastikan tombol Export Excel dan Export PDF terasa sesuai alur kerja pengguna.

## 2026-07-31 - Template Slip Pembayaran Sekolah

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengganti tampilan cetak/PDF laporan pembayaran agar mengikuti contoh slip pembayaran sekolah yang diberikan pemilik proyek.

**Perubahan fitur dan perilaku:**

- Mengubah `laporan/export_pdf.php` dari kwitansi rekap bulanan menjadi slip pembayaran per transaksi.
- Menyesuaikan layout slip dengan header SD Al-Qur'an Mutiara Hikmah, data siswa dua kolom, rincian pembayaran, sisa pembayaran, pembayaran lain-lain, jumlah total, terbilang, sistem pembayaran, dan tanda tangan bagian keuangan.
- Menambahkan fungsi terbilang rupiah untuk menampilkan total pembayaran dalam bentuk teks.
- Menampilkan biaya lain, tabungan wajib, uang daftar ulang, dan sisa PSB/DU berdasarkan data transaksi yang tersedia.
- Menambahkan mode preview `contoh=1` saat periode belum memiliki transaksi agar template slip tetap dapat dilihat tanpa membuat data pembayaran palsu di database.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Output PDF kini berisi slip per transaksi pada periode yang dipilih, bukan rekap tabel bulanan.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- Query lokal menunjukkan tabel `bayar` masih kosong sehingga mode preview diperlukan untuk melihat template.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji cetak dari browser dengan data transaksi nyata untuk memastikan hasil visual sesuai format laporan yang diinginkan.

## 2026-07-30 - Dokumen Minutes of Meeting Project

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyusun dokumen Minutes of Meeting untuk kebutuhan laporan project SistemSPP.

**Perubahan fitur dan perilaku:**

- Menambahkan dokumen `documentation/MOM_SistemSPP.md` berisi informasi rapat, agenda, ringkasan pembahasan fitur, keputusan, action items, risiko, kesimpulan, dan lampiran modul project.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan perilaku aplikasi maupun struktur data.

**Verifikasi:**

- Review manual isi dokumen berdasarkan `documentation/PROJECT_CONTEXT.md`, `documentation/AI_CHANGELOG.md`, schema database, dan file modul utama.

**Catatan tindak lanjut:**

- Lengkapi placeholder peserta, waktu, tempat, PIC, dan target tanggal sesuai kebutuhan laporan.

## 2026-07-30 - Perbaikan SQL Injection Riwayat Tabungan

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menutup SQL injection pada filter NIS di riwayat tabungan tanpa mengubah perilaku laporan.

**Perubahan fitur dan perilaku:**

- Menghapus interpolasi langsung `$_GET['nis']` dari query masuk dan keluar pada `tabungan/riwayat.php`.
- Filter NIS kosong tetap menampilkan seluruh transaksi pada periode yang dipilih.
- Filter NIS terisi tetap menggunakan exact match, tetapi nilainya sekarang dikirim sebagai parameter prepared statement.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi database.

**Kompatibilitas dan data lama:**

- Format URL, filter bulan/tahun, hasil laporan normal, saldo, jurnal, pembayaran, dan transaksi legacy tetap tidak berubah.

**Verifikasi:**

- Lint seluruh file PHP, `node --check assets/js/app.js`, dan `git diff --check` dijalankan.
- Filter kosong, filter NIS valid, dan payload SQL `' OR 1=1 --` diuji melalui HTTP; payload diperlakukan sebagai nilai literal tanpa SQL error atau perluasan hasil.
- Tidak ada data transaksi yang tersisa setelah pengujian.

**Catatan tindak lanjut:**

- CSRF pada endpoint mutasi pembayaran, tabungan, dan Master Biaya Lain tetap berada di luar scope dan dicatat sebagai technical debt.

## 2026-07-30 - Integritas Child Pembayaran dan Kesiapan Schema

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menghilangkan pencocokan child pembayaran yang ambigu dan menyiapkan migrasi aman untuk database berhistori.

**Perubahan fitur dan perilaku:**

- Pembayaran baru menandai header dengan `payment_link_version=1` dan menyimpan `bayar_id` pada Daftar Ulang serta setoran Tabungan Wajib miliknya.
- Edit/hapus pembayaran sekarang hanya membaca atau mengubah child dengan `bayar_id` yang sama; transaksi tabungan manual dan penarikan tidak disentuh.
- Pembalikan setoran Tabungan Wajib mengunci saldo. Jika pembalikan akan membuat saldo negatif, operasi ditolak dan seluruh transaction di-rollback.
- Pembayaran legacy ditandai di daftar/dashboard serta tidak dapat diedit atau dihapus, termasuk dengan akses langsung ke endpoint.
- Menambahkan `documentation/PROGRESS.md` sebagai register temuan, status, bukti, dan aturan pembaruan progres.

**Database dan migrasi:**

- Menambahkan `sql/add_payment_references.sql` yang idempoten untuk `bayar.payment_link_version`, `bayar_du.bayar_id`, `transaksi_m.bayar_id`, index unik, dan foreign key cascade.
- Menyelaraskan `sql/schema.sql` untuk instalasi baru dan menambahkan `sql/verify_schema.sql` berbasis `information_schema`.

**Kompatibilitas dan data lama:**

- Tidak ada backfill atau pencocokan otomatis berdasarkan NIS, tanggal, maupun tahun ajaran.
- Histori yang telah ada tetap legacy (`payment_link_version=0` dan child `bayar_id=NULL`) dan membutuhkan rekonsiliasi manual sebelum dapat diubah.

**Verifikasi:**

- Sebelum migrasi, jumlah transaksi pada database lokal diperiksa dan bernilai nol.
- `add_payment_references.sql` dijalankan dua kali pada database lokal tanpa error; `verify_schema.sql` mengembalikan `OK` untuk tabel, kolom, index unik, dan foreign key cascade wajib.
- Lint seluruh 22 file PHP dan `git diff --check` berhasil. Peringatan normalisasi akhir baris Git tidak mengubah hasil pemeriksaan.
- Uji HTTP/database terisolasi membuat dua pembayaran pada tanggal sama serta satu setoran manual. Edit dan hapus salah satunya hanya mengubah child ber-`bayar_id` miliknya; jurnal manual tetap ada. Pembalikan yang akan membuat saldo negatif dan edit/hapus pembayaran legacy sama-sama ditolak. Semua data uji dibersihkan hingga jumlah transaksi kembali nol.

**Catatan tindak lanjut:**

- Perbaikan SQL injection, CSRF, XSS, dan temuan keamanan lain tidak termasuk ruang lingkup perubahan ini.

## 2026-07-28 - Master Biaya Lain, Master Siswa Advance, dan Uang Komite

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan SistemSPP untuk kebutuhan SDIT, membuat biaya tambahan berbasis master, memperkuat pengelolaan siswa, dan menjaga konsistensi laporan.

**Perubahan fitur dan perilaku:**

- Menambahkan CRUD admin `Master Biaya Lain` dengan nama unik, nominal positif, status aktif/nonaktif, jumlah penggunaan, dan proteksi penghapusan master terpakai.
- Mengganti baris `Uang Lain` lama dengan baris biaya dinamis pada input/edit pembayaran.
- Menyimpan nama dan nominal biaya lain sebagai snapshot agar histori tidak berubah ketika tarif master diperbarui.
- Mempertahankan detail legacy yang tidak memiliki master dan menampilkan master nonaktif hanya pada transaksi lama yang memakainya.
- Mengubah Master Siswa menjadi CRUD dasar/Advance dengan kelas 1 sampai 6, pencarian, filter kelas/status, badge status, serta aksi Edit dan Arsipkan/Pulihkan.
- Menambahkan field Advance untuk NIS Diknas, tarif siswa, POMG/Komite, daftar ulang, potongan, total turunan, dan migrasi saldo awal.
- Menjaga field Advance saat panel ditutup dan menolak perubahan saldo awal setelah siswa memiliki histori.
- Mengizinkan perubahan nomor induk dalam transaction dengan cascade foreign key.
- Menambahkan audit JSON sebelum/sesudah untuk tambah, edit, arsip, dan pemulihan siswa.
- Mengintegrasikan `siswa.POMG` sebagai Uang Komite opsional yang dilacak per siswa, bulan, dan tahun.
- Menghitung sisa Komite di frontend dan memvalidasi ulang tarif, status siswa, periode, serta batas sisa di backend.
- Membatasi siswa arsip dari pembayaran dan tabungan baru tanpa menghapus histori lama.
- Menambahkan Uang Komite dan agregasi biaya lain ke laporan web, halaman kwitansi/PDF, dan Excel.
- Menghitung ulang `total_jumlah` di backend serta mengabaikan total dan nominal biaya master dari browser.
- Menambahkan responsive styling untuk Master Siswa dan memperbaiki constraint lebar konten mobile.
- Menambahkan dokumentasi konteks proyek dan aturan changelog AI pada folder `documentation`.

**Database dan migrasi:**

- Menambahkan `master_biaya_lain` dan `bayar_biaya_lain` melalui `sql/add_master_biaya_lain.sql`.
- Memigrasikan `U_LAIN` serta empat slot biaya lama secara idempotent melalui `legacy_key` tanpa mengubah `bayar.total_jumlah`.
- Mengubah data finansial siswa menjadi `DECIMAL(15,2)`, membatasi kelas 1 sampai 6, serta menambahkan `siswa.is_active` dan index pencarian.
- Menambahkan `siswa_audit_log` dan `bayar.U_KOMITE` melalui `sql/add_student_advanced.sql`.
- Menyinkronkan `tot_pangkal` dan `tot_du` dari tarif dan potongan.
- Memperbarui `sql/schema.sql` agar instalasi baru langsung memakai struktur terkini.

**Kompatibilitas dan data lama:**

- Kolom pembayaran lama tetap ada, tetapi transaksi baru tidak lagi menulis nominal biaya lain ke kolom legacy.
- Snapshot menjaga tarif dan nama biaya transaksi lama.
- Migrasi siswa berhenti bila masih menemukan kelas di luar 1 sampai 6 dan tidak memetakan kelas secara otomatis.
- Siswa arsip tetap ikut histori, laporan, dan edit transaksi lama.

**Verifikasi:**

- Lint seluruh 22 file PHP berhasil.
- `node --check assets/js/app.js` dan `git diff --check` berhasil.
- Kedua migrasi diuji pada database salinan; migrasi siswa juga dijalankan ulang dua kali pada database lokal tanpa error.
- Pengujian HTTP/database mencakup CRUD siswa, total turunan, audit, cascade nomor induk, perlindungan saldo awal, CSRF, role non-admin, arsip/pulihkan, dan penolakan siswa arsip.
- Pembayaran Komite diuji sebagian, berlebih, edit transaksi siswa arsip, serta periode bulan berbeda.
- Laporan web, PDF, dan Excel diverifikasi memuat Uang Komite.
- Tampilan Master Siswa diperiksa melalui screenshot desktop dan mobile.
- Seluruh data pengujian dibersihkan setelah verifikasi.

**Catatan tindak lanjut:**

- Terapkan CSRF secara konsisten pada pembayaran, tabungan, dan Master Biaya Lain.
- Pertimbangkan automated integration test dan migrasi kolom uang legacy dari `DOUBLE` ke `DECIMAL` pada pekerjaan terpisah.

## 2026-07-28 - Penyempurnaan Alur Pembayaran dan Kwitansi PDF

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `232bbc1`

**Ringkasan:** Menyempurnakan alur pembayaran, representasi bulan, kelas SD, pengisian rincian biaya, serta halaman laporan cetak menjadi format yang lebih menyerupai kwitansi.

## 2026-07-28 - Light Mode Lebih Terang

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `5f35c16`

**Ringkasan:** Meningkatkan kontras dan kecerahan light mode tanpa mengganti konsep visual utama SistemSPP.

## 2026-07-28 - Refactor Struktur dan Maintainability

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `95bb7b0`, `379dd3e`

**Ringkasan:** Merapikan struktur kode dan meningkatkan keterbacaan serta maintainability tanpa perubahan domain utama yang tercatat pada pesan commit.

## 2026-07-28 - Role Management dan Perbaikan Pencarian

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `9a8e99d`, `daacb41`

**Ringkasan:** Menambahkan pengelolaan akun berbasis role, memperbarui akses UI/backend, dan mengganti pencarian menjadi search box dengan ikon yang konsisten.

## 2026-07-28 - Multi-role, Tabungan, dan Export Laporan

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `35e1dd9`

**Ringkasan:** Menambahkan role admin/bendahara/kasir, alur tabungan masuk/keluar, riwayat tabungan, laporan keuangan, dan export.

## 2026-07-27 - Implementasi Awal SistemSPP

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `2943f44`

**Ringkasan:** Membuat fondasi aplikasi SistemSPP dan desain Material 3 sebagai baseline repository.

## Template Entri Berikutnya

Salin template ini ke bagian paling atas setelah `Aturan Pencatatan`:

```markdown
## YYYY-MM-DD - Judul Perubahan

**AI/Aktor:** Nama AI/model atau developer
**Tujuan:** Ringkasan permintaan dan hasil yang diinginkan.

**Perubahan fitur dan perilaku:**

- Perubahan yang benar-benar diterapkan.

**Database dan migrasi:**

- Nama migrasi, perubahan schema, atau `Tidak ada`.

**Kompatibilitas dan data lama:**

- Dampak terhadap data/API/perilaku lama atau `Tidak ada`.

**Verifikasi:**

- Command dan skenario yang benar-benar dijalankan.

**Catatan tindak lanjut:**

- Risiko tersisa, technical debt, atau `Tidak ada`.
```
