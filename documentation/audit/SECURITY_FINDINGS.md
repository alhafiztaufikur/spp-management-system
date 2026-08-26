# Security Findings dan Verification Register SistemSPP

Baseline diperiksa secara read-only pada 20 Agustus 2026 dari branch `main`, commit `a446af3fbb89`. Pemeriksaan HTTP hanya meminta halaman normal, directory index, dan file statis; tidak ada `tests/*.php` yang dieksekusi melalui web. Semua temuan harus diverifikasi ulang pada konfigurasi deployment client karena observasi Apache/PHP di bawah berasal dari XAMPP lokal.

## 1. Skala dan status

| Level | Arti |
|---|---|
| Kritis | Dapat langsung membuka source/credential atau memungkinkan mutasi besar tanpa kontrol akses yang layak |
| Tinggi | Dapat mengambil alih session/akun, memalsukan mutasi keuangan, membocorkan data sensitif, atau menghilangkan jejak forensik |
| Sedang | Memerlukan prasyarat/akun, berdampak terbatas, atau terutama memengaruhi hardening/ketersediaan |
| Rendah | Defense-in-depth, konsistensi respons, atau hygiene |

Status `Terkonfirmasi` berarti bukti source/runtime saat ini membuktikan kondisi tersebut. `Perlu validasi dinamis` berarti ada jalur berisiko tetapi eksploitabilitas belum dibuktikan. `Coverage gap` berarti static pass tidak menemukan kerentanan aktif, tetapi bukti pengujian maksimal belum tersedia.

## 2. Ringkasan register

| ID | Temuan | Level | Status |
|---|---|---:|---|
| FULLSEC-001 | Git, SQL, manifest, dan dokumentasi internal terekspos dari web root | Kritis | Terkonfirmasi |
| FULLSEC-002 | Script test dapat diroute dan berpotensi dieksekusi tanpa autentikasi | Kritis | Terkonfirmasi |
| FULLSEC-003 | Aplikasi memakai DB superuser `root` tanpa password | Kritis | Terkonfirmasi |
| FULLSEC-004 | Akun/password default lemah dipublikasikan dan seed memakai MD5 | Kritis | Terkonfirmasi |
| FULLSEC-005 | Tidak ada rate limit/lockout/login telemetry | Tinggi | Terkonfirmasi dari source |
| FULLSEC-006 | CSRF tidak merata dan hapus pembayaran memakai GET | Tinggi | Terkonfirmasi |
| FULLSEC-007 | Cookie/session tidak di-hardening dan tidak memiliki timeout aplikasi | Tinggi | Terkonfirmasi lokal |
| FULLSEC-008 | Perubahan/penghapusan akun tidak mencabut session lama | Tinggi | Terkonfirmasi dari desain source |
| FULLSEC-009 | Header keamanan absen dan versi server dibocorkan | Tinggi | Terkonfirmasi lokal |
| FULLSEC-010 | Detail exception/DB dapat tampil ke pengguna | Tinggi | Terkonfirmasi |
| FULLSEC-011 | Audit trail pembayaran, tabungan, dan akun tidak lengkap | Tinggi | Terkonfirmasi |
| FULLSEC-012 | Batas role/object belum dibuktikan dan akses kasir ke laporan massal perlu keputusan | Tinggi | Perlu keputusan + validasi dinamis |
| FULLSEC-013 | Race penghapusan admin terakhir | Sedang | Terkonfirmasi dari source; reproduksi konkuren tertunda |
| FULLSEC-014 | Formula injection pada Excel HTML | Sedang | Perlu validasi dinamis |
| FULLSEC-015 | Dependency/runtime PDF tidak siap dan advisory scan belum current | Tinggi | Terkonfirmasi readiness; advisory belum terverifikasi |
| FULLSEC-016 | Laporan/export dapat mengonsumsi resource tanpa batas yang jelas | Sedang | Terkonfirmasi desain; load test tertunda |
| FULLSEC-017 | SQL injection menyeluruh belum mempunyai regression/DAST evidence | Tinggi | Coverage gap |
| FULLSEC-018 | XSS/output encoding menyeluruh belum mempunyai regression evidence | Tinggi | Coverage gap |
| FULLSEC-019 | Upload/path/renderer tidak menunjukkan sink aktif, tetapi perlu gate regresi | Sedang | Coverage gap |
| FULLSEC-020 | Hardening TLS, backup, log, dan deployment client belum dibuktikan | Tinggi | Coverage gap deployment |
| FULLSEC-021 | Submit ulang/replay mutasi tabungan/pembayaran tidak memiliki idempotency key dan dapat menggandakan saldo/jurnal/audit | Tinggi | Terkonfirmasi, remediation savings/payment selesai |

### Status remediation per 27 Agustus 2026

Register di atas mempertahankan kondisi baseline 20 Agustus agar histori temuan tidak hilang. Setelah hardening dan regression disposable, status operasional terbaru adalah:

| Temuan baseline | Perubahan saat ini | Status terbaru |
|---|---|---|
| FULLSEC-001/002 | `.htaccess` deny untuk artefak internal/tests; route test diberi guard database disposable dan tidak dieksekusi lewat web | Selesai lokal, wajib retest virtual host client |
| FULLSEC-003 | Runtime lokal memakai user schema khusus; wrapper regression memberi privilege hanya pada clone disposable | Selesai lokal, credential/ACL target belum dibuktikan |
| FULLSEC-004 | Seed MD5 lama ditandai reset wajib dan diblok login; akun modern harus dibuat/dirotasi saat provisioning | Selesai pada source, reset akun target tetap wajib |
| FULLSEC-005/007/008/009/010 | Rate limit, session/cookie/header hardening, session version revalidation, generic error, request ID | PASS pada security regression disposable; HSTS/Apache target masih terbuka |
| FULLSEC-006 | CSRF dan POST-only dipasang pada mutasi pembayaran, tabungan, siswa, master, dan role management; delete payment tidak lagi GET | Implementasi utama selesai, route-wide DAST/negative matrix masih terbuka |
| FULLSEC-011 | `audit_event` append-only, actor snapshot tanpa FK, redaksi, reason, before/after, dan integrasi pembayaran/tabungan/akun | PASS pada regression disposable terbaru 18/18; retensi/approval koreksi masih keputusan owner |
| FULLSEC-012/013 | Last-admin locking dan role guards dipertahankan; test konkurensi savings/last-admin sudah lulus | Parsial; keputusan role/object dan skenario domain lain pending |
| FULLSEC-021 | Form tabungan dan pembayaran memakai key acak 64-hex; `mutation_request` unique per scope/key diklaim di transaksi bisnis yang sama; replay probe payment + savings dan withdrawal/payment-period/DU/fee publish concurrency | Selesai untuk mutasi finansial yang diuji pada disposable; deadlock/retry dan failpoint masih terbuka |
| FULLSEC-015/016/017/018/020 | Dependency check lokal (`composer validate`, `composer check-platform-reqs`, `composer audit --locked --no-dev`), prepared statement audit, dan static controls tersedia | FULLSEC-015 selesai lokal dengan 0 advisory/platform PASS; target deployment/PDF HTTP, load, DAST, dan browser gate tetap terbuka sesuai `KNOWN_LIMITATIONS.md` |

Regression keamanan direct terbaru (`SEC-REG-FINAL-002`) mengulang request ID, header/cookie/HSTS, CSRF, POST-only, revocation, blokir MD5, throttling multi-bucket, guard admin terakhir, dan logout pada snapshot audit setelah sinkronisasi `admin_username` pada login dan revalidasi sesi. Baseline/after identik (`audit_event=0`, fixture legacy=0, rate-limit rows=0, `session_version=1`), dan server uji dihentikan. Ini memperkuat bukti lokal saja; tidak menutup verifikasi TLS/ACL/credential atau UAT pada deployment client.

## 3. Temuan terkonfirmasi

### FULLSEC-001 — Source dan artefak internal terekspos

**Bukti**

- Request lokal ke `/.git/HEAD`, `/.git/config`, `/composer.json`, dan `/sql/schema.sql` menghasilkan `200 OK`.
- `/sql/` dan `/tests/` menampilkan directory index.
- Apache lokal mengaktifkan `Options Indexes FollowSymLinks Includes ExecCGI` pada `C:\xampp\apache\conf\httpd.conf:266`.
- Schema yang dapat diunduh memuat seed akun pada `sql/schema.sql:24-33`.

**Risiko**

Histori Git dapat direkonstruksi, termasuk file yang sudah dihapus. SQL dan dokumentasi mengungkap struktur data, migrasi, akun default, serta informasi yang mempercepat serangan. Jika secret pernah ada di histori, menghapus file saat ini tidak cukup.

**Acceptance**

1. Document root produksi hanya memuat entrypoint/aset yang memang publik, atau web server memberi deny eksplisit untuk `.git`, dotfiles, `sql`, `tests`, dokumentasi internal, manifest, lockfile, dan bootstrap/helper.
2. Directory listing mati.
3. Seluruh URL bukti mengembalikan `403`/`404`, tanpa redirect ke halaman yang membocorkan isi.
4. Secret scan mencakup seluruh histori Git; seluruh credential yang pernah terekspos dirotasi.
5. Gate deployment otomatis gagal bila file internal dapat diambil lewat HTTP.

### FULLSEC-002 — Script test web-executable

**Bukti**

- Directory index `/tests/` memperlihatkan delapan file PHP.
- Tidak ada guard `PHP_SAPI === 'cli'` pada awal script.
- `tests/class_snapshot_test.php:6-10` menulis master kelas, siswa, pembayaran, dan klaim periode.
- `tests/payment_process_integration_test.php:63-83` membuat siswa/master/tagihan dan kemudian memanggil endpoint aplikasi.
- `tests/academic_year_billing_test.php:18` dan `tests/registration_history_pagination_test.php:8` memulai transaksi uji langsung pada DB aplikasi.

**Risiko**

Request tanpa autentikasi dapat menjalankan beban berat dan mutasi database. Rollback di script bukan kontrol keamanan; fatal error, koneksi putus, atau test yang sengaja melakukan HTTP request dapat meninggalkan efek atau mengganggu data.

**Acceptance**

1. `tests/` tidak dikirim ke public document root atau diblokir sebelum PHP interpreter.
2. Setiap test mempunyai guard CLI dan pemeriksaan eksplisit bahwa database adalah database test.
3. Test memakai credential/database terisolasi, fixture idempotent, dan cleanup terverifikasi.
4. Setelah server deny aktif, request ke setiap nama test menghasilkan `403/404`; jangan menguji file PHP tersebut melalui web sebelum deny dipastikan.

Catatan pembacaan: line reference dan bukti pada bagian temuan terkonfirmasi berikut merujuk snapshot baseline 20 Agustus. Status source/runtime terbaru diringkas pada tabel remediation di atas; line number historis tidak boleh dibaca sebagai kondisi runtime terbaru.

### FULLSEC-003 — DB superuser tanpa password

**Bukti**

- `koneksi.php:6-13` menetapkan host lokal, user `root`, password kosong, database `db_spp`.
- `SHOW GRANTS FOR CURRENT_USER()` pada baseline menghasilkan `ALL PRIVILEGES ON *.* ... WITH GRANT OPTION` dan hak proxy.

**Risiko**

SQL injection, local file compromise, atau kelemahan PHP akan mempunyai blast radius seluruh database server, termasuk kemampuan membuat user/grant baru.

**Acceptance**

1. Aplikasi memakai account khusus dengan password acak kuat dari secret store/config di luar repository dan web root.
2. Grant dibatasi pada schema/tabel/operasi yang benar-benar diperlukan; tanpa `GRANT OPTION`, global privilege, file, process, atau proxy.
3. Account migrasi terpisah dari account runtime.
4. Koneksi gagal menampilkan pesan generik dan detail hanya tercatat pada log terlindungi.
5. Credential lama dirotasi dan least-privilege diuji dengan seluruh regression suite.

### FULLSEC-004 — Default credential dan MD5

**Bukti**

- `login.php:225` menampilkan credential default kepada siapa pun.
- `sql/schema.sql:25-32` membuat beberapa akun default dengan password yang diketahui dan MD5.
- `sql/seed_kasir_accounts.sql:1-5` mengulang pola MD5/password default kasir.
- `login.php:33-49` menerima MD5 legacy dan baru meng-upgrade hash setelah login sukses.

**Risiko**

Fresh deployment dan akun yang belum pernah dipakai dapat langsung diambil alih. MD5 cepat di-crack bila dump database bocor.

**Acceptance**

1. Tidak ada password yang dapat dipakai bersama di source, schema, seed, UI, dokumentasi, atau test default.
2. Bootstrap menghasilkan secret satu kali atau mewajibkan admin membuat password pada setup terautentikasi/out-of-band.
3. Seluruh hash aktif memakai algoritme `password_hash()` yang disetujui; deployment memblokir akun MD5 sampai password direset.
4. First-login password change, password policy, dan daftar credential awal yang sudah dicabut dibuktikan.

### FULLSEC-005 — Proteksi serangan login tidak tersedia

**Bukti**

`login.php:14-69` melakukan lookup dan verifikasi untuk setiap POST tanpa counter, throttling, lockout, CAPTCHA adaptif, IP/account rate limit, atau pencatatan gagal-login.

**Acceptance**

- Terapkan rate limit gabungan per akun dan sumber, backoff, alert threshold, log gagal/sukses tanpa password, serta respons generik.
- Uji burst, distributed-low-rate, username enumeration, reset window, dan recovery admin; pastikan kontrol tidak mudah dipakai untuk mengunci seluruh petugas.

### FULLSEC-006 — CSRF dan unsafe method

**Bukti**

- Payment form tidak membawa token pada `pembayaran/form.php:176` dan `pembayaran/edit.php:248`.
- Dispatcher menerima action POST atau GET pada `pembayaran/proses.php:13`.
- Delete dipicu link GET pada `pembayaran/lihat.php:282-284` dan dijalankan pada `pembayaran/proses.php:1035-1083`.
- Form tabungan tidak membawa token pada `tabungan/masuk.php:66` dan `tabungan/keluar.php:66`; handler `tabungan/proses.php:10-23` hanya memeriksa method/data.
- Logout adalah GET pada `includes/sidebar.php:158` dan `logout.php:5-8`.
- Kontrol positif: Role Management, master kelas, master biaya lain, Master DU, dan siswa telah memakai token session + `hash_equals()`.

Perbaikan lokal terbaru (`SEC-CSRF-012`/`SEC-CSRF-013`): Master Daftar Ulang tidak lagi menjalankan `master_du_ensure_year()` sebelum pemeriksaan CSRF, dan operasi ensure berada di dalam transaksi aksi. Probe token salah maupun aksi valid-token yang tidak dikenali menunjukkan 302 dan tidak ada perubahan jumlah `tahun_ajaran`; regression `regression-du-atomic-20260826_234500` lulus 18/18.

**Acceptance**

1. Semua mutasi hanya POST dengan CSRF token session; delete tidak dapat dilakukan lewat GET/HEAD/prefetch.
2. Token kosong, salah, milik session lain, dan token sebelum regenerasi session ditolak tanpa perubahan DB.
3. Method salah mengembalikan `405` + `Allow`, bukan menjalankan atau diam-diam mengubah state.
4. SameSite dipakai sebagai defense-in-depth, bukan pengganti token.
5. Test memverifikasi snapshot semua tabel terdampak sebelum/sesudah request gagal.

### FULLSEC-007 — Cookie dan lifecycle session lemah

**Bukti runtime lokal**

- `session.cookie_httponly=Off`.
- `session.cookie_secure=Off`.
- `session.cookie_samesite` kosong.
- `session.use_strict_mode=Off`.
- Cookie aktual hanya `PHPSESSID=<redacted>; path=/`.
- Tidak ditemukan idle timeout, absolute timeout, device/session list, atau rotation berkala pada aplikasi.
- Kontrol positif: `session_regenerate_id(true)` dijalankan setelah login sukses (`login.php:51`).

**Acceptance**

- Produksi memaksa HTTPS, `Secure`, `HttpOnly`, `SameSite` yang sesuai, strict mode, cookie-only, nama/path/domain minimal, entropy memadai, idle dan absolute timeout.
- Regenerasi dilakukan pada login dan perubahan privilege; logout menghapus cookie dan session server.
- Uji fixation, reuse ID lama, paralel tab, timeout, restore cookie, serta akses setelah logout.

### FULLSEC-008 — Revokasi akun/session tidak ada

**Bukti**

- `includes/auth.php:16-29` hanya membaca `admin_id` dan `admin_role` dari session.
- Reset password pada `role_management.php:86-120` dan delete pada `role_management.php:124-163` tidak menyentuh session target.
- Tidak ada `is_active`, `session_version`, `password_changed_at`, atau session registry pada schema akun (`sql/schema.sql:15-22`).

**Risiko**

Akun yang telah dihapus atau diturunkan haknya dapat terus bekerja hingga data session kedaluwarsa secara eksternal.

**Acceptance**

- Setiap request privat memvalidasi akun aktif dan versi/epoch session terhadap DB/cache.
- Reset password, perubahan role, disable/delete, dan tindakan darurat mencabut seluruh session target.
- Test membuktikan cookie lama langsung gagal pada semua route, termasuk `tabungan/get_saldo.php`.

### FULLSEC-009 — Header keamanan dan information disclosure

**Bukti runtime lokal**

- Respons mengungkap `Server: Apache/2.4.58 ... OpenSSL/3.0.20 PHP/8.3.31` dan `X-Powered-By: PHP/8.3.31`.
- Tidak terlihat CSP, `X-Content-Type-Options`, frame protection, `Referrer-Policy`, atau `Permissions-Policy`.
- `expose_php=On` pada runtime PHP.

**Acceptance**

- Hilangkan banner versi dan `X-Powered-By`.
- Terapkan `nosniff`, frame protection melalui CSP `frame-ancestors`, referrer/permissions policy, dan CSP bertahap untuk script/style/resource.
- HSTS hanya sesudah HTTPS pada host client tervalidasi dan seluruh subdomain yang dicakup siap.
- Header diuji pada login, redirect, error, JSON, HTML, print, serta download.

### FULLSEC-010 — Raw error disclosure

**Bukti**

- `koneksi.php:15-19` memasukkan `$connect_error` ke respons.
- Payment meneruskan exception pada `pembayaran/proses.php:818-821`, `1027-1031`, dan `1077-1080`.
- Tabungan melakukan hal sama pada `tabungan/proses.php:93-96`.
- Master/Student/Report meneruskan `getMessage()` pada `master_biaya_lain.php:90-93`, `master_daftar_ulang.php:115-117`, `master_kelas.php:66-67`, `siswa/daftar.php:354-356`, dan `laporan/template.php:14-16`.
- Runtime lokal memakai `display_errors=STDOUT`.

Perbaikan lokal tambahan: fallback `prepare()` pada `pembayaran/riwayat_daftar_ulang.php` tidak lagi menggabungkan `$koneksi->error` ke pesan pengguna; ia memakai pesan generik. Jalur exception terpusat tetap menghasilkan correlation ID untuk investigasi.

Boundary audit juga diperketat melalui `SEC-INPUT-012`: `audit_require_reason()` menolak nilai array/objek sebelum normalisasi, sehingga alasan tidak dapat berubah menjadi teks implisit seperti `Array`.

**Acceptance**

- Pengguna hanya menerima pesan domain/generik dan correlation ID; SQL, table, path, stack, credential, dan exception internal tidak tampil.
- Detail masuk ke log terstruktur dengan access control, masking, retention, dan alert.
- Uji sintetik meliputi DB unavailable, constraint, missing dependency, invalid encoding, disk penuh, dan renderer failure.

### FULLSEC-011 — Audit trail finansial tidak lengkap

**Bukti baseline (20 Agustus 2026; kondisi historis sebelum remediation)**

- Edit pembayaran menulis operator saat ini ke `bayar.user_id` pada `pembayaran/proses.php:947-965`, sehingga identitas pembuat asli tertimpa.
- Delete menghapus header pada `pembayaran/proses.php:1069-1073` tanpa catatan before/after/alasan khusus pembayaran.
- Tabungan menyimpan `user_id` pada jurnal (`tabungan/proses.php:68-79`) tetapi tidak mempunyai workflow koreksi/audit immutable.
- Role Management tidak menulis audit tambah/reset/hapus akun (`role_management.php:42-163`).
- Kontrol positif tersedia pada siswa (`siswa/daftar.php:79-91`) dan audit DU/biaya lain.

**Bukti remediation (27 Agustus 2026)**

- `includes/audit.php::audit_event_write()` menulis event dengan actor ID/name/username/role snapshot, request ID, alasan, before/after terpilih, metadata ter-redaksi, dan payload JSON berbatas ukuran.
- `pembayaran/proses.php` menulis `payment.created`, `payment.updated`, dan `payment.deleted`; update/delete mengambil snapshot sebelum perubahan dan mewajibkan `audit_reason` yang scalar serta panjangnya tervalidasi.
- `tabungan/proses.php` menulis `savings.deposited`/`savings.withdrawn` dalam transaksi yang sama dengan jurnal dan saldo.
- `role_management.php` menulis `account.created`, `account.password_reset`, dan `account.deleted`; reset password menaikkan `session_version`, sedangkan penghapusan memakai lock admin terakhir.
- Trigger database append-only menolak `UPDATE`/`DELETE` pada `audit_event`; regression disposable `regression-20260827_023858` (`REG-FINAL-003`) lulus 18/18 dan security regression direct menjaga `AUDIT_EVENT_DELTA=0` pada snapshot baseline.

**Acceptance**

- Audit append-only untuk create/update/delete/reversal pembayaran, tabungan, akun, dan perubahan privilege.
- Simpan actor asli, actor koreksi, before/after terpilih, alasan, timestamp server, request/correlation ID, dan hasil.
- Audit tidak dapat diedit/dihapus oleh role aplikasi biasa dan memiliki retention/backup.
- Rekonsiliasi membuktikan setiap perubahan saldo/header mempunyai event audit dan tidak ada PII/secret berlebihan di log.

**Status terbaru:** kontrol pencatatan dan append-only terbukti pada environment disposable/snapshot audit. Temuan tidak boleh ditutup sebagai deployment production sampai retention/backup, monitoring/alert, approval koreksi, dan review event pada target client disetujui. Perubahan role langsung di luar workflow aplikasi juga belum mempunyai event khusus dan harus diperlakukan sebagai operasi terkontrol.

## 4. Temuan yang memerlukan keputusan atau validasi dinamis

### FULLSEC-012 — Role dan object authorization

Kasir secara eksplisit boleh membuka Laporan Global (`includes/sidebar.php:65-67`, `laporan/global.php:6`, `laporan/template.php:4`, `laporan/export_global.php:4`), termasuk laporan massal penerimaan/tabungan/setoran. `tabungan/get_saldo.php:6-9` hanya memeriksa `admin_id`, bukan role. Tidak ada ownership/per-loket scope; admin/kasir yang berhak mengelola pembayaran dapat menunjuk ID mana pun.

**Acceptance**

- Client menandatangani matriks role per route, aksi, kolom data, export, dan cakupan record.
- Automated test meliputi anonymous/A/B/K/invalid/deleted/role-changed, direct URL, manipulasi ID/batch, serta hidden-menu bypass.
- Default-deny diterapkan; API memberikan `401/403` yang konsisten dan tidak membocorkan keberadaan record.

### FULLSEC-013 — Race admin terakhir

**Bukti baseline:** `role_management.php:142-152` dahulu menghitung admin lalu menghapus pada statement terpisah tanpa transaksi/row lock. Dua admin dapat sama-sama melihat count lebih dari satu lalu saling menghapus.

**Bukti remediation:** Handler kini mengunci seluruh baris admin ber-role `admin` dalam transaksi sebelum menghitung dan menghapus. Barrier concurrency dua server pada clone disposable menghasilkan tepat satu penghapusan sukses, satu penolakan, dan satu admin tetap tersedia (`CONC-001/CONC-003`, 25/25 PASS). Target deployment dan skenario recovery deadlock masih terbuka.

**Acceptance**

- Invariant minimal satu admin ditegakkan secara atomik melalui transaksi/locking atau desain disable yang aman.
- Barrier-based concurrency test menjalankan dua delete bersamaan dan membuktikan tepat satu ditolak.

### FULLSEC-014 — Formula injection Excel

Nama siswa hanya diwajibkan tidak kosong dan maksimal 100 karakter (`siswa/daftar.php:105-117`); nama biaya juga menerima teks bebas (`master_biaya_lain.php:97-104`). Nilai tersebut diekspor sebagai sel HTML (`laporan/export_excel.php:384`, `409-412`). `htmlspecialchars()` menutup HTML injection tetapi bukan formula semantics Excel.

**Acceptance**

- Pada workbook hasil nyata, payload dengan awalan `=`, `+`, `-`, `@`, tab, dan CR tampil sebagai teks literal tanpa formula/link/prompt eksternal.
- Sanitasi diterapkan pada semua export lama/global dan semua kolom data bebas, dengan regression fixture serta inspeksi workbook.

**Bukti lokal terbaru:** `REP-FORMULA-001` memperluas oracle helper pada `regression-formula-20260826_193000` untuk seluruh prefix formula, whitespace Unicode, CR/LF/tab, dan NUL; hasil 18/18 regression PASS. `REP-XLS-004` kemudian membuka seluruh 11 artefak `.xls` melalui Excel COM read-only dan menemukan total formula native 0. Ini membuktikan transformasi helper dan fixture normal lokal, tetapi belum menggantikan payload terarah, tipe sel, warning format, dan workflow pada aplikasi spreadsheet client target.

### FULLSEC-015 — Dependency dan PDF readiness

**Bukti baseline (20 Agustus 2026; kondisi sebelum provisioning dependency)**

- `composer.json:5-8` mensyaratkan GD dan Dompdf.
- `vendor/autoload.php` tidak ada pada worktree baseline, tetapi dipanggil `laporan/export_pdf.php:8` dan `laporan/export_global.php:26`.
- `composer check-platform-reqs` menyatakan `ext-gd` missing.
- `composer validate --strict` berhasil.
- `composer audit --locked` tidak dapat mengambil metadata Packagist pada sesi baseline, sehingga changelog lama bukan bukti advisory current.

**Bukti remediation lokal:** Composer lock terpasang; `composer validate --strict`, `composer audit --locked --no-dev` (0 advisory), dan `composer check-platform-reqs --no-dev` lulus pada environment audit. Dompdf/GD tersedia pada CLI, SBOM dan clean-package rehearsal tercatat. Validasi Apache/PDF HTTP dan runtime target tetap terbuka.

**Acceptance**

- Build produksi reproducible menginstal dependency dari lockfile, memverifikasi hash, dan menjalankan `composer audit --locked --no-dev` pada runner berjaringan.
- `composer check-platform-reqs` hijau, SBOM tersedia, kebijakan update/advisory didokumentasikan, dan smoke test PDF valid.
- Missing dependency menghasilkan error generik, bukan fatal detail/path.

### FULLSEC-016 — Resource exhaustion laporan/export

`includes/reports.php:106-108` mem-paginate array setelah report dibangun. `laporan/template.php:14-17` membangun seluruh report lebih dahulu. Export Global membangun seluruh hasil dan dapat diakses kasir. Filter tanggal memvalidasi format tetapi tidak membatasi panjang rentang (`includes/reports.php:31-39`).

**Acceptance**

- Query/pagination dilakukan di DB atau streaming/batch; rentang, jumlah row, ukuran file, execution time, dan concurrency mempunyai limit yang disetujui.
- Load test menggunakan data volume client plus margin, mengukur p95/p99, memory, timeout, DB locks, dan request paralel.
- Request melampaui limit ditolak terkontrol tanpa menurunkan layanan transaksi.

## 5. Coverage gap yang wajib ditutup

### FULLSEC-017 — SQL injection

Static pass tidak menemukan SQL injection aktif terkonfirmasi. Filter riwayat tabungan memakai placeholder (`tabungan/riwayat.php:33-78`). Query dinamis laporan umumnya menggunakan cast/whitelist/placeholder, misalnya `includes/reports.php:102-104`, `157-168`, dan `227-230`. Namun belum ada suite keamanan yang membuktikan semua parameter.

Regression HTTP terfokus `tests/tabungan_riwayat_sqli_test.php` membuktikan filter kosong, exact NIS, dan payload quote/comment (`' OR 1=1 --`) pada clone disposable. Tambahan `tests/security_input_corpus_test.php` mengirim payload boolean/error, quote/comment, encoding, array/scalar, duplicate, oversized, dan reflected-XSS fokus ke route GET terautentikasi termasuk export dan rekap kelas dilindungi; corpus menemukan lalu menutup TypeError `nis[]=...` pada `tabungan/riwayat.php`, `tabungan/get_saldo.php`, `tabungan/masuk.php`, dan `tabungan/keluar.php`. Helper laporan, endpoint report/export, pagination, rekap kelas, serta field scalar handler pembayaran/tabungan/master/siswa/role/tahun ajaran kini menolak array input; corpus POST dengan CSRF valid mencakup master kelas, Biaya Lain, Daftar Ulang, siswa, dan role management. Probe tambahan membuktikan GET Master Daftar Ulang tidak membuat tahun ajaran, sedangkan probe `SEC-CORPUS-009` mengirim body `application/json` dan `text/plain` tanpa CSRF ke endpoint mutasi serta membandingkan count sembilan tabel bisnis sebelum/sesudah; rerun terbaru pada `regression-recap-scalar-20260826_201500` lulus 18/18 tanpa HTTP 500, marker error SQL, atau payload XSS mentah. Bukti ini tetap tidak dinaikkan menjadi DAST menyeluruh karena time-based, seluruh handler mutasi/master, seluruh parameter Cartesian, failpoint, dan browser sink coverage belum ada.

**Acceptance**

- Inventaris source-to-sink seluruh `$_GET`/`$_POST`/DB-stored values menuju `query()`/`prepare()`.
- Fuzz read-only route dan mutasi pada DB disposable dengan boolean/error/time payload, quote/comment, encoding, array/scalar confusion, duplicate parameter, dan oversized input.
- Tidak ada perubahan cardinality, delay terkendali, SQL error, atau data tambahan; seluruh query data memakai prepared statement atau identifier whitelist yang terdokumentasi.

### FULLSEC-018 — XSS dan context encoding

Mayoritas output memakai `htmlspecialchars()`/helper seperti `report_e()`. Tidak ada stored/reflected XSS terkonfirmasi pada static pass. Risiko tersisa meliputi attribute, inline JavaScript, flash/error, print, DOM construction, PDF, dan export.

**Acceptance**

- Matrix payload disimpan pada nama siswa/admin/biaya/catatan lalu diperiksa di seluruh list, form, dashboard, laporan, struk, print, PDF, dan Excel.
- Encoder sesuai konteks HTML text, attribute, URL, JavaScript, dan JSON; inline handler yang memasukkan data memakai `json_encode()`/event listener aman.
- CSP dapat dijalankan tanpa `unsafe-inline` setelah migrasi yang direncanakan; browser test memastikan tidak ada eksekusi payload.

### FULLSEC-019 — Upload, path, dan renderer

Tidak ditemukan `$_FILES`, `move_uploaded_file()`, dynamic include, shell execution, atau path input. `laporan/export_global.php:10-14` membaca logo statis via `realpath()` dan mematikan remote Dompdf pada baris 26; `laporan/export_pdf.php:612` menetapkan chroot.

**Acceptance**

- SAST gate mempertahankan ketiadaan dynamic include/command/path sink yang tidak disetujui.
- Kebijakan Dompdf konsisten: remote off, chroot minimal, data URI/HTML terkontrol, dan advisory current.
- Jika upload ditambahkan kelak, desain terpisah wajib mencakup MIME/content inspection, random name, storage non-executable di luar web root, size/quota, authz, dan malware scanning.

### FULLSEC-020 — Deployment, TLS, backup, dan observability

Konfigurasi lokal tidak membuktikan konfigurasi client. Belum ada bukti HTTPS/HSTS, network ACL DB, backup terenkripsi, restore drill, central log, alert, retention, clock sync, patch state, atau incident runbook.

**Acceptance**

1. Inventaris versi OS/Apache/PHP/OpenSSL/DB/Composer dibandingkan advisory resmi dan support lifecycle.
2. HTTPS end-to-end, redirect HTTP, certificate renewal, HSTS decision, dan secure-cookie diuji pada host client.
3. DB hanya dapat dicapai host aplikasi/admin yang disetujui; credential/backup dienkripsi dan dirotasi.
4. Backup menghasilkan checksum dan restore drill pada environment kosong dengan target RPO/RTO yang disetujui.
5. Log auth, privilege, mutasi finansial, error, dan export massal dikirim ke lokasi terlindungi dengan alert serta uji incident response.

### FULLSEC-021 — Replay mutasi tabungan dan pembayaran

**Bukti temuan dan remediation**

- Probe disposable sebelum remediation mengirim payload setoran yang sama dua kali; saldo bertambah dua kali, dua jurnal `transaksi_m`, dan dua `audit_event` tercatat.
- Form Tabungan sekarang menghasilkan `idempotency_key` acak 64-hex. `tabungan/proses.php` memanggil `idempotency_claim()` setelah `begin_transaction()` dan sebelum lock/mutasi finansial.
- Form pembayaran input/edit/delete juga menghasilkan key yang sama; `pembayaran/proses.php` mengklaim scope `payment` untuk create/update/delete sebelum mutasi.
- `mutation_request` memakai unique `(scope, request_key)` dan menyimpan snapshot actor tanpa FK. Duplicate key menjadi penolakan domain; key rollback ikut hilang ketika transaksi bisnis rollback.
- Replay/concurrency probe setelah remediation pada clone `db_spp_audit_20260826_151239` menghasilkan 25/25 assertion PASS: satu sukses/satu ditolak untuk replay savings, withdrawal paralel tetap satu commit, dua pembayaran operator pada periode SPP sama meninggalkan satu header/claim/audit, publish DU menghasilkan satu tagihan per siswa dan satu audit, serta publish Biaya Lain menghasilkan satu tagihan per siswa meski dua attempt bersamaan.
- Regression disposable `db_spp_audit_20260826_145634_suite_5186` menambahkan replay payment dan nominal negatif/NaN/overflow; 13/13 test PASS dan query oracle menunjukkan satu header, nominal, serta audit event.

**Residual dan acceptance lanjutan**

- Publish Daftar Ulang dan Biaya Lain belum memakai key form `mutation_request`, tetapi race database sudah aman melalui lock/status/unique constraint; keputusan apakah publish juga wajib memiliki replay key eksplisit masih menunggu owner.
- Deadlock retry, failpoint tiap write, dan browser UAT belum dibuktikan.

## 6. Gate sebelum serah-terima client

Release hanya boleh dinyatakan siap bila:

- tidak ada temuan Kritis/Tinggi terbuka kecuali residual risk tertulis dan ditandatangani client;
- semua route lulus matrix role/method/CSRF dan state-before/state-after;
- URL internal `.git`, test, SQL, dokumentasi privat, bootstrap, dan manifest tidak dapat diakses;
- default credential, DB root, session/cookie, raw error, dan security header telah ditutup pada deployment aktual;
- SAST, secret-history scan, dependency audit, authenticated DAST, lint, business regression, concurrency, export, dan performance test memiliki artefak hasil;
- data uji dibersihkan dan rekonsiliasi database menghasilkan nol anomali yang tidak dijelaskan;
- backup/restore, rollback release, monitoring, SOP operasional, serta daftar residual risk sudah diserahkan; dan
- `laporan/rekap_kelas.php` tetap tersedia dan lulus regression sebagai referensi tampilan.
