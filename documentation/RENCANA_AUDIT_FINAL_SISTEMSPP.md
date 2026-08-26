# Rencana Audit Maksimal dan Kesiapan Serah-Terima SistemSPP

> **Status:** rencana kerja dan baseline reconnaissance; belum berarti seluruh audit atau remediasi telah selesai.  
> **Baseline kode:** commit `a446af3` pada 2026-08-20.  
> **Target:** aplikasi administrasi SPP SDIT yang aman, dapat direkonsiliasi, dapat dipasang ulang, dan layak diserahkan kepada client.  
> **Aturan khusus:** [`laporan/rekap_kelas.php`](../laporan/rekap_kelas.php) adalah referensi visual yang dilindungi. File, alur, dan styling yang diperlukan halaman tersebut tidak boleh dihapus, diganti konsepnya, atau dikategorikan sebagai dead code tanpa instruksi baru dan eksplisit dari pemilik proyek.

## 1. Tujuan dokumen

Dokumen ini adalah rencana induk untuk memeriksa SistemSPP secara menyeluruh sebelum serah-terima. Audit harus menjawab, dengan bukti yang dapat diulang:

1. Apakah setiap nominal yang tampil dapat ditelusuri ke transaksi dan tagihan sumber yang benar?
2. Apakah input, edit, koreksi, dan penghapusan selalu atomik serta tidak merusak histori siswa lain?
3. Apakah role hanya dapat membaca dan mengubah data yang memang menjadi kewenangannya?
4. Apakah aplikasi tahan terhadap akses langsung, CSRF, injection, XSS, manipulasi parameter, request ulang, dan kegagalan di tengah transaksi?
5. Apakah schema baru dan setiap jalur upgrade lama menghasilkan struktur serta data yang ekuivalen?
6. Apakah laporan web, print, PDF, dan Excel menghasilkan angka yang sama untuk filter dan dataset yang sama?
7. Apakah antarmuka dapat dipakai di perangkat sekolah, termasuk mobile, keyboard, mode terang/gelap, kondisi data kosong, dan data besar?
8. Apakah ada fitur setengah jadi, cabang kode mati, aset tak terpakai, dokumentasi basi, atau endpoint yang tidak lagi memiliki pemilik bisnis?
9. Apakah aplikasi dapat dipasang dari clone bersih, di-backup, dipulihkan, dipantau, dan di-rollback oleh pengelola setelah handover?
10. Apakah seluruh risiko tersisa telah diperbaiki atau diterima secara tertulis oleh client?

Audit dinyatakan selesai hanya bila ada artefak bukti, bukan karena halaman terlihat berjalan atau lint kode lulus.

## 2. Batasan, prinsip keselamatan, dan larangan

### 2.1 Ruang lingkup

Audit mencakup kode PHP, JavaScript, CSS, gambar, Composer, schema dan seluruh migrasi SQL, test, konfigurasi XAMPP/Apache/PHP/MySQL yang memengaruhi aplikasi, dokumentasi, backup/restore, dan alur operasional pengguna.

Audit juga mencakup perilaku runtime pada empat identitas: anonymous, admin, bendahara, dan kasir; ditambah sesi invalid, akun yang dihapus, serta akun yang rolenya berubah saat sesi masih aktif.

### 2.2 Yang tidak boleh dilakukan

- Jangan pernah menjalankan `sql/schema.sql` pada database yang berisi data penting. Script tersebut hanya untuk database baru yang kosong.
- Jangan menjalankan script `tests/*.php`, seed, migrasi, fuzzing, load test, atau concurrency test pada database client/produksi.
- Jangan menebak relasi transaksi legacy berdasarkan NIS, tanggal, nominal, kelas, atau tahun ajaran.
- Jangan menguji endpoint test melalui web selama endpoint tersebut masih terhubung ke database bersama.
- Jangan menyimpan dump database, cookie, password, token, PII siswa, atau bukti sensitif di repository maupun document root.
- Jangan menghapus file/fungsi hanya karena satu hasil pencarian statis tidak menemukan pemanggil.
- Jangan menurunkan severity agar release dapat dipaksakan. Gunakan penerimaan risiko tertulis bila keputusan bisnis memang menerima risiko.
- Jangan menyatakan SQL injection, XSS, CSRF, race condition, atau parity laporan aman tanpa test yang relevan.
- Jangan mengubah [`laporan/rekap_kelas.php`](../laporan/rekap_kelas.php) dalam pekerjaan pembersihan. Halaman ini boleh diuji dari sisi akses, keamanan, data, responsif, dan screenshot regression, tetapi konsep visualnya harus dipertahankan.

### 2.3 Lingkungan audit wajib terisolasi

Gunakan tiga lingkungan berbeda:

| Lingkungan | Isi | Boleh dimutasi | Tujuan |
| --- | --- | --- | --- |
| Baseline read-only | Clone commit target + snapshot informasi produksi yang sudah disanitasi | Tidak | Inventaris, review statis, dan pembandingan |
| Audit integration | Clone terpisah + database fixture anonim | Ya | Test endpoint, transaksi, migrasi, fuzz, dan concurrency |
| Release rehearsal | Paket rilis seperti yang akan diterima client + database baru/restore latihan | Ya | Membuktikan instalasi, upgrade, rollback, backup, PDF/Excel, dan UAT |

Semua bukti harus disimpan di lokasi terlindungi di luar web root melalui variabel konseptual `AUDIT_EVIDENCE_ROOT`. Setiap run mempunyai ID unik, timestamp WIB, commit SHA, versi runtime, checksum backup, dan nama skenario. Bukti yang mengandung PII harus disanitasi sebelum dimasukkan ke laporan.

## 3. Baseline arsitektur yang harus dipertahankan sebagai peta awal

SistemSPP adalah aplikasi PHP prosedural/server-rendered tanpa framework, menggunakan `mysqli`, MySQL/MariaDB, JavaScript dan CSS global, serta Dompdf untuk PDF. Baseline ini bukan spesifikasi final; setiap bagian tetap harus diverifikasi terhadap kode dan keputusan pemilik proyek.

| Area | Artefak utama | Peran bisnis |
| --- | --- | --- |
| Login dan sesi | `login.php`, `logout.php`, `includes/auth.php` | Autentikasi dan pembatasan role |
| Konfigurasi | `koneksi.php`, `composer.json`, `composer.lock` | Koneksi DB, timezone, dependency |
| Dashboard | `dashboard.php`, `includes/reports.php` | Closing/penerimaan harian |
| Pengguna | `role_management.php` | Akun admin, bendahara, kasir |
| Siswa/kelas | `siswa/daftar.php`, `master_kelas.php`, `includes/kelas.php` | Identitas, tarif, status, rombel, snapshot |
| Pembayaran | `pembayaran/form.php`, `edit.php`, `lihat.php`, `proses.php` | Pembayaran, koreksi, histori, receipt |
| Daftar Ulang | `master_daftar_ulang.php`, `includes/daftar_ulang.php`, `pembayaran/riwayat_daftar_ulang.php` | Tahun ajaran, penerbitan tagihan, cicilan |
| Biaya lain | `master_biaya_lain.php`, `includes/biaya_lain.php` | Master, penerbitan tagihan, cicilan |
| Tabungan | `tabungan/masuk.php`, `keluar.php`, `proses.php`, `riwayat.php`, `get_saldo.php` | Setoran, penarikan, ledger, saldo |
| Laporan umum | `laporan/index.php`, `export_excel.php`, `export_pdf.php` | Ringkasan periode dan export |
| Laporan modular | `laporan/global.php`, `template.php`, `export_global.php`, `includes/reports.php` | Tujuh template laporan global |
| Rekap dilindungi | `laporan/rekap_kelas.php`, `detail_siswa.php` | Referensi visual dan rekap bulanan per tingkat |
| Struk | `laporan/cetak_struk.php`, `cetak_struk_tahunan.php` | Bukti transaksi biasa dan batch legacy |
| Database | `sql/schema.sql`, seluruh `sql/add_*.sql`, script legacy/cleanup/sync, `verify_schema.sql` | Instalasi, upgrade, kompatibilitas, verifikasi |
| Regression | Delapan script PHP dalam `tests/` | Regression bisnis berbasis database/HTTP |
| Frontend | `assets/js/app.js`, `assets/css/*.css`, `assets/img/*` | Interaksi, tema, responsive, aset visual |
| Dokumentasi | Seluruh `documentation/` | Konteks, SOP, flowchart, MoM, progres, changelog |

## 4. Temuan pendahuluan yang sudah terkonfirmasi

Tabel ini bukan pengganti audit penuh. Ia menunjukkan mengapa urutan audit harus dimulai dari containment dan rekonsiliasi. Verifikasi dilakukan secara read-only pada baseline lokal 2026-08-20; setelah perbaikan, semuanya harus diuji ulang.

| ID | Severity awal | Fakta terkonfirmasi | Dampak | Gate awal |
| --- | --- | --- | --- | --- |
| PRE-SEC-001 | Kritis | Request `HEAD` ke `/.git/HEAD`, `/.git/config`, `/sql/schema.sql`, `/composer.json`, `/sql/`, dan `/tests/` mengembalikan HTTP 200. Directory listing aktif. | Source, histori, schema, kredensial seed, dan script test dapat terekspos; beberapa test dapat menulis DB jika dieksekusi. | Blok semua artefak nonpublik dan buktikan 403/404 sebelum DAST atau UAT. |
| PRE-SEC-002 | Kritis | Aplikasi lokal tersambung sebagai MySQL `root` tanpa password; `SHOW GRANTS` menunjukkan `ALL PRIVILEGES ON *.* WITH GRANT OPTION`. | Kompromi aplikasi dapat berkembang menjadi kompromi seluruh server DB. | Buat user runtime least-privilege dan pindahkan secret dari repository/web root. |
| PRE-DATA-001 | Kritis | Ada 16 baris `bayar` dengan `U_SPP > 0`; seluruh 16 tidak mempunyai claim di `bayar_spp_periode`. | Laporan status/SPP yang membaca claim dapat menyatakan belum bayar walaupun pembayaran ada. | Bekukan keputusan laporan sampai rekonsiliasi dan backfill terverifikasi pada salinan DB. |
| PRE-DATA-002 | Kritis | Ada dua siswa dengan cache `*_BAYAR` biaya awal berbeda dari agregat pembayaran. | Status biaya awal dapat salah walau transaksi sumber benar. | Buat verifier data dan rekonsiliasi sebelum laporan disetujui. |
| PRE-SEC-003 | Tinggi | Input/update pembayaran dan tabungan belum memiliki CSRF; hapus pembayaran memakai `GET`. Logout juga memakai `GET`. | Mutasi dapat dipicu lintas situs atau oleh navigasi yang tidak semestinya. | Seluruh mutasi wajib POST + token CSRF + penolakan method salah. |
| PRE-SEC-004 | Tinggi | Kredensial demo ditampilkan di login; schema/seed membuat akun default lemah berbasis MD5. Tidak ada rate limit/lockout. | Pengambilalihan akun mudah pada deploy yang belum di-hardening. | Hilangkan hint, rotasi akun, wajib ganti password, dan uji proteksi brute force. |
| PRE-SEC-005 | Tinggi | Cookie/session hardening, timeout, revalidasi akun aktif, security header, error sanitization, dan revocation sesi belum memadai. | Sesi lama bertahan, informasi runtime bocor, dan permukaan serangan browser lebih besar. | Selesaikan matriks auth/session dan hardening sebelum go-live. |
| PRE-AUD-001 | Tinggi | Edit pembayaran menimpa identitas operator; delete menghilangkan header tanpa audit before/after dan alasan koreksi. | Jejak keuangan tidak cukup untuk non-repudiation dan investigasi. | Definisikan audit log append-only sebelum client memakai koreksi transaksi nyata. |
| PRE-DEP-001 | Tinggi | `vendor/` tidak ada pada clone saat reconnaissance dan `ext-gd` gagal pada `composer check-platform-reqs`; PDF bergantung pada keduanya. | Instalasi bersih/PDF belum reproducible pada baseline mesin. | Rehearsal dari clone bersih wajib lulus. |
| PRE-UI-001 | Sedang | Tabel responsive dashboard tidak memberi `data-label` pada sel, sementara header disembunyikan di mobile. | Nilai closing pada mobile dapat tampil tanpa nama kolom. | Uji dan perbaiki pada matriks viewport. |
| PRE-DOC-001 | Tinggi | SOP/flowchart lama masih mengajarkan Tabungan Wajib melalui pembayaran, sedangkan backend baru menolak alur tersebut; MoM dan role matrix juga telah drift. | Client dapat mengikuti prosedur yang salah. | Sinkronkan HTML, render ulang PDF, dan validasi silang seluruh dokumentasi. |

Kontrol positif yang juga harus dipertahankan: login meregenerasi session ID setelah autentikasi; filter NIS riwayat tabungan menggunakan prepared statement; banyak output utama telah memakai encoder; mutasi tabungan memakai transaction dan row lock; Dompdf modular menonaktifkan remote resource; dan transaksi legacy tidak dicocokkan secara otomatis.

## 5. Model severity, status, dan kualitas bukti

### 5.1 Severity

| Severity | Kriteria minimum | Contoh pada SistemSPP | Target sebelum rilis |
| --- | --- | --- | --- |
| Kritis | Eksploitasi tanpa autentikasi, kebocoran source/secret besar, korupsi/selisih uang sistemik, kehilangan data, atau kontrol akses total gagal | `.git`/test terekspos; laporan salah sistemik; transaksi lintas siswa | Tidak boleh terbuka |
| Tinggi | Pengambilalihan akun, mutasi tanpa otorisasi, selisih finansial terbatas tetapi nyata, audit trail gagal, fitur inti tidak reproducible | CSRF pembayaran; sesi akun terhapus tetap aktif; PDF gagal deploy | Tidak boleh terbuka kecuali risk acceptance bertanda tangan dan ada mitigasi sementara |
| Sedang | Dampak operasional/UX nyata, akses data terbatas, performa buruk, atau kesalahan dokumentasi yang dapat dipulihkan | mobile label hilang; formula injection; pagination in-memory | Harus diperbaiki atau dijadwalkan dengan owner dan tanggal |
| Rendah | Defect kecil tanpa dampak data/keamanan langsung | inkonsistensi label, cache version tidak seragam | Boleh masuk backlog terukur |
| Informasi | Observasi, kontrol positif, atau peluang maintainability | duplikasi style yang belum berdampak | Catat untuk keputusan |

Severity akhir ditentukan dari dampak, kemungkinan eksploitasi/kegagalan, luas data, kemudahan deteksi, dan kemampuan pemulihan. Jangan memakai severity hanya berdasarkan nama kelas CWE atau selera reviewer.

### 5.2 Status temuan

Gunakan salah satu: `Indikasi`, `Terkonfirmasi`, `Sedang diperbaiki`, `Siap retest`, `Selesai`, `Tidak dapat direproduksi`, `Duplikat`, atau `Diterima client`. Status `Selesai` mensyaratkan bukti retest pada commit dan lingkungan target.

### 5.3 Template temuan wajib

Setiap temuan minimal berisi:

- ID stabil dan judul satu kalimat;
- tanggal, commit, lingkungan, role, endpoint/file, dan versi runtime;
- kategori/CWE bila relevan, severity, confidence, dan status;
- prasyarat serta langkah reproduksi yang aman;
- expected versus actual;
- bukti tersanitasi: status HTTP, query read-only, screenshot, log, atau diff;
- dampak terhadap uang, data siswa, kerahasiaan, operasional, dan audit;
- akar masalah, bukan hanya gejala;
- rekomendasi dan alternatif;
- owner, dependensi, target wave, dan acceptance criteria;
- test regresi yang ditambahkan;
- hasil retest dan residual risk.

## 6. Artefak hasil audit

Audit penuh harus menghasilkan paket berikut:

1. **Laporan eksekutif:** kondisi go/no-go, blocker, residual risk, dan keputusan client.
2. **Register temuan:** seluruh temuan dengan format pada bagian 5.3.
3. **Manifest aplikasi:** route, method, role, include, asset, function JS/PHP, tabel, job manual, seed, test, dan dokumen.
4. **Matriks route × role × method:** expected dan hasil aktual untuk seluruh endpoint.
5. **Kontrak sumber data:** definisi tagihan, pembayaran, snapshot, cache, ledger, periode, dan rumus laporan.
6. **Paket query rekonsiliasi read-only:** struktur dan integritas data, tanpa PII pada output laporan.
7. **Manifest migrasi kanonik:** versi awal, urutan, dependency, preflight, postflight, idempotency, durasi, backup, dan rollback.
8. **Matriks parity laporan:** web/print/PDF/Excel terhadap dataset oracle yang sama.
9. **Inventaris dead/half-built:** `aktif`, `dinamis`, `kompatibilitas`, `test-only`, `kandidat`, `mati terkonfirmasi`, atau `dilindungi`.
10. **Bukti UX/accessibility:** screenshot, keyboard walkthrough, contrast, viewport, dan browser matrix.
11. **Laporan dependency dan deployment:** platform requirements, advisory, lisensi, SBOM, konfigurasi server, dan smoke test.
12. **Runbook operasi:** install, upgrade, backup, restore, rollback, rotasi credential, logging, dan incident response.
13. **Paket UAT:** skenario per role, hasil, nama penanggung jawab, tanggal, dan sign-off.
14. **Release manifest:** commit/tag, checksum paket, schema version, known limitations, serta seluruh dokumen final.

`documentation/PROGRESS.md` tetap menjadi ringkasan status lintas pekerjaan. Bukti detail audit jangan ditumpuk ke sana; simpan register terpisah dan tautkan ID-nya. `documentation/AI_CHANGELOG.md` hanya mencatat perubahan yang benar-benar dilakukan dan test yang benar-benar dijalankan.

## 7. Urutan eksekusi dan gate antar-wave

```text
Wave 0  Containment + backup + lingkungan terisolasi
   |
Wave 1  Kontrak kebutuhan, role, sumber data, dan baseline
   |\
   | +-- Wave 2 Keamanan aplikasi dan platform
   | +-- Wave 3 Integritas bisnis/keuangan dan concurrency
   | +-- Wave 4 Schema, migrasi, dan restore
   | +-- Wave 5 Laporan dan export
   | +-- Wave 6 Frontend, accessibility, dan performa
   | +-- Wave 7 Dead code, fitur tanggung, dan dokumentasi
   |
Wave 8  Remediasi terurut + regression suite
   |
Wave 9  Release rehearsal + UAT + keputusan go/no-go
```

Wave berikutnya hanya boleh memakai environment yang lulus gate wave sebelumnya. Security testing aktif dan test mutasi tidak boleh dimulai sebelum Wave 0 selesai.

## 8. Wave 0 — containment, backup, dan baseline aman

### 8.1 Bekukan baseline

- Catat branch, commit SHA, `git status --short`, tag terakhir, dan seluruh perubahan tak ter-commit.
- Catat versi Apache, PHP CLI dan Apache module, MySQL/MariaDB, Node, Composer, OS, timezone PHP/DB, `sql_mode`, charset, collation, dan storage engine.
- Catat checksum `composer.lock`, schema, migrasi, JS, CSS, serta paket rilis.
- Ambil inventaris tabel, jumlah baris per tabel, ukuran, FK, index, view, trigger, procedure, event, dan user DB tanpa mengekspor data siswa ke log publik.

### 8.2 Backup dan restore drill

- Buat dump konsisten memakai credential file terlindungi di luar web root; jangan menulis password pada command history.
- Hash dump dengan SHA-256, enkripsi bila berisi data nyata, dan batasi ACL.
- Restore ke nama database audit baru, bukan menimpa `db_spp`.
- Bandingkan jumlah tabel/baris, constraint, serta query rekonsiliasi sebelum dan sesudah restore.
- Catat Recovery Point Objective, Recovery Time Objective, ukuran backup, durasi backup/restore, owner, dan lokasi salinan offsite.
- Uji satu pemulihan penuh. Backup tanpa restore test tidak dianggap lulus.

### 8.3 Tutup exposure web

Opsi yang diutamakan adalah document root publik terpisah. Bila struktur belum dapat diubah, gunakan aturan server yang secara eksplisit menolak dotfiles dan folder/file nonpublik.

Daftar minimum yang tidak boleh dapat diunduh atau dieksekusi melalui HTTP:

- `.git/`, `.agents/`, `.codex/`, dotfiles lain;
- `tests/`, `sql/`, `documentation/`;
- `composer.json`, `composer.lock`, dump, backup, log, cache, dan file konfigurasi secret;
- source map atau file sementara yang membocorkan source bila kelak ditambahkan.

Acceptance:

- directory listing mati;
- setiap URL sensitif mengembalikan 403/404, bukan redirect login dan bukan 200;
- `tests/*.php` tidak dieksekusi melalui HTTP;
- endpoint aplikasi yang memang publik masih bekerja;
- hasil dicek kembali setelah deployment rehearsal, bukan hanya di XAMPP developer.

### 8.4 Pisahkan secret dan privilege

- Pindahkan credential DB ke konfigurasi environment/secret file di luar web root.
- Sediakan `.env.example` atau template konfigurasi tanpa secret, plus validasi konfigurasi saat startup.
- Buat user DB aplikasi khusus dengan privilege tabel minimum; tanpa `GRANT OPTION`, tanpa akses database lain, dan tanpa privilege administratif.
- Pisahkan credential migrasi dari credential runtime bila migrasi membutuhkan `ALTER/CREATE`.
- Scan repository dan histori Git untuk password/token; semua credential yang pernah terekspos dianggap perlu dirotasi.
- Hapus hint akun demo dari login dan pastikan akun seed tidak aktif pada paket produksi.

### 8.5 Gate Wave 0

- Backup dapat direstore.
- Database audit tidak mengandung identitas nyata yang tidak diperlukan.
- Akses HTTP ke artefak sensitif sudah tertutup.
- Test suite hanya menunjuk database audit.
- Credential produksi tidak muncul di repository, log, screenshot, atau command history.
- Ada persetujuan tertulis sebelum fuzz, concurrency, atau load test.

## 9. Wave 1 — kontrak kebutuhan, role, route, dan sumber data

### 9.1 Hierarki sumber kebenaran

Jika sumber bertentangan, gunakan urutan keputusan:

1. keputusan tertulis terbaru dari pemilik/client;
2. aturan bisnis yang telah disetujui dan test acceptance terbaru;
3. kontrak data/schema yang telah diverifikasi;
4. implementasi runtime saat ini;
5. `PROJECT_CONTEXT.md` dan `PROGRESS.md`;
6. changelog, MoM, SOP, flowchart, dan dokumen historis.

Konflik tidak boleh diselesaikan diam-diam. Buat decision log dengan pertanyaan, opsi, dampak historis, keputusan, pemberi keputusan, dan tanggal.

### 9.2 Keputusan client yang wajib dikunci

| ID | Keputusan | Opsi yang harus dibahas | Dampak |
| --- | --- | --- | --- |
| DEC-001 | Akses kasir ke seluruh Laporan Global | tetap penuh / dibatasi template tertentu | Least privilege dan kerahasiaan finansial |
| DEC-002 | Tahun ajaran `closed` | tunggakan masih boleh dibayar / seluruh mutasi ditutup | Validasi DU dan laporan |
| DEC-003 | Koreksi transaksi | edit/delete / reversal append-only / kombinasi dengan approval | Audit trail dan rekonsiliasi |
| DEC-004 | Pembayaran tahunan legacy | baca/cetak saja / dukungan kembali / migrasi terkontrol | Cabang kode tahunan dan batch receipt |
| DEC-005 | Retensi siswa dan transaksi | arsip saja / hard-delete terbatas | FK cascade dan histori keuangan |
| DEC-006 | Operasi offline | font/aset seluruhnya lokal / internet diperbolehkan | Ketersediaan di sekolah |
| DEC-007 | Platform produksi | versi PHP, DB, Apache/Nginx, browser, HTTPS | Dependency dan hardening |
| DEC-008 | Sumber tarif historis | snapshot tahun ajaran / tarif aktif siswa / aturan campuran eksplisit | Koreksi periode lama dan laporan |
| DEC-009 | Pembayaran nol | ditolak / draft nonfinansial dengan tipe khusus | Integritas transaksi |
| DEC-010 | Masa simpan PII/log | durasi, anonimisasi, hak akses, pemusnahan | Privasi dan operasional |

### 9.3 Matriks endpoint baseline

Role di bawah adalah implementasi saat ini dan harus divalidasi client; bukan otomatis kebijakan final.

| Endpoint/kelompok | Method saat ini | Role/kondisi saat ini | Mutasi | Fokus audit |
| --- | --- | --- | --- | --- |
| `/index.php` | GET | publik/redirect | Tidak | open redirect, cache, tujuan per sesi |
| `/login.php` | GET/POST | publik | Ya, login dan rehash | brute force, enumeration, CSRF login, session fixation |
| `/logout.php` | GET | sesi | Ya | ubah ke POST, CSRF, cookie cleanup |
| `/dashboard.php` | GET | admin, bendahara | Tidak | parity closing, role, mobile |
| `/role_management.php` | GET/POST | admin | Ya | last-admin race, password, CSRF, session revocation |
| `/master_kelas.php` | GET/POST | admin | Ya | referential integrity, toggle kelas terpakai |
| `/master_biaya_lain.php` | GET/POST | admin | Ya | publish idempotent, overpay, race, target scope |
| `/master_daftar_ulang.php` | GET/POST | admin | Ya | publish/close state machine, snapshot, race |
| `/siswa/daftar.php` | GET/POST | admin | Ya | IDOR, NIS cascade, arsip, cache, audit log |
| `/pembayaran/form.php` | GET | admin, kasir | Tidak | data binding, tarif/server validation |
| `/pembayaran/edit.php?id=` | GET | admin, kasir | Tidak | ID enumeration, legacy guard, stale form |
| `/pembayaran/lihat.php` | GET | admin, kasir | Tidak | filter, output encoding, action visibility |
| `/pembayaran/proses.php` | POST; delete via GET | admin, kasir | Ya | CSRF, method safety, atomicity, replay, audit |
| `/pembayaran/riwayat_daftar_ulang.php` | GET | admin, kasir | Tidak | aggregation, pagination, access |
| `/tabungan/masuk.php`, `/keluar.php` | GET | admin, kasir | Tidak | saldo stale, ID binding, accessibility |
| `/tabungan/proses.php` | POST | admin, kasir | Ya | CSRF, date/amount validation, lock, replay |
| `/tabungan/get_saldo.php?nis=` | GET | hanya cek `admin_id` | Tidak | role parity, deleted session, ID enumeration |
| `/tabungan/riwayat.php` | GET | admin, kasir, bendahara | Tidak | prepared filter, parity, privacy |
| `/laporan/index.php` | GET | admin, bendahara | Tidak | totals, filter date, scale |
| `/laporan/export_excel.php`, `/export_pdf.php` | GET | admin, bendahara | Tidak | authorization, formula injection, resource cap |
| `/laporan/global.php`, `/template.php`, `/export_global.php` | GET | admin, bendahara, kasir | Tidak | least privilege, whitelist, parity, resource cap |
| `/laporan/rekap_kelas.php`, `/detail_siswa.php` | GET | admin, bendahara | Tidak | protected regression, access, parity |
| `/laporan/cetak_struk.php`, `/cetak_struk_tahunan.php` | GET | admin, bendahara, kasir | Tidak | ID/batch enumeration, ownership, output |
| `/tests/*`, `/sql/*`, dotfiles, manifests | Tidak boleh publik | tidak ada | Sebagian berbahaya | wajib 403/404 dan non-executable |

Untuk setiap endpoint, uji method `GET`, `POST`, `HEAD`, dan method salah; role anonymous/admin/bendahara/kasir; session invalid; akun dihapus; role berubah; parameter hilang; parameter ganda; ID tidak ada; serta direct URL tanpa melewati menu. Expected status harus eksplisit: 200, 302, 400, 403, 404, atau 405. Redirect ke halaman lain tidak selalu setara dengan penolakan aman.

## 10. Wave 2 — audit keamanan menyeluruh

### 10.1 Threat model

Petakan aset: data identitas siswa, saldo/tabungan, transaksi dan receipt, akun operator, schema/source, backup, log, dan credential. Petakan pelaku: anonymous internet/LAN, siswa/orang tua yang melihat layar bersama, operator jujur yang salah input, operator berniat buruk, akun yang dicuri, malware browser, dan developer dengan akses server.

Untuk setiap trust boundary—browser, Apache/PHP, database, filesystem, Composer/network, dan file export—catat input, output, autentikasi, otorisasi, logging, serta dampak bila boundary gagal.

### 10.2 Authentication dan akun

- Uji username kosong, salah, case variation, whitespace, Unicode, password sangat panjang, dan timing/enumeration.
- Uji rate limit per akun dan sumber, progressive delay, lockout aman, recovery, serta audit gagal-login.
- Verifikasi `password_hash()`/`password_verify()`, rehash, panjang kolom, dan penghapusan seluruh MD5/default account.
- Uji pembuatan user, reset password, hapus user, larangan menghapus diri sendiri/last admin, dan dua request hapus admin secara bersamaan.
- Verifikasi password policy dan kewajiban ganti password awal tanpa mencatat password ke log.
- Jika fitur “Ingat perangkat” tidak ada backend, hapus affordance atau implementasikan token terpisah yang dapat dicabut; jangan biarkan kontrol semu.
- Tautan lupa password, hubungi admin, syarat, dan privasi harus berfungsi atau dihapus dari UI final.

### 10.3 Session

- Aktifkan dan uji `HttpOnly`, `Secure` pada HTTPS, `SameSite`, `use_strict_mode`, cookie path/domain minimum, dan regenerasi ID saat login/perubahan privilege.
- Uji fixation dengan ID pilihan attacker, reuse ID lama, dua browser, back button, cache halaman privat, dan logout.
- Tetapkan idle timeout dan absolute timeout; uji boundary waktunya.
- Revalidasi akun/role atau gunakan session version sehingga reset password, delete user, dan perubahan role mencabut sesi lama.
- Pastikan cookie benar-benar dihapus saat logout dan halaman privat tidak dapat dilihat dari browser cache setelah logout.

### 10.4 Authorization, IDOR, dan least privilege

- Uji seluruh matriks pada 9.3 melalui direct URL.
- Enumerasi ID pembayaran, NIS, ID siswa, ID tagihan, receipt, batch token, filter kelas, saldo, dan export.
- Pastikan pembatasan tidak hanya pada tombol/menu; handler dan query harus menolak akses.
- Verifikasi kasir tidak mendapat data global yang tidak disetujui client.
- Uji akun yang dihapus/diturunkan role tetapi session masih hidup.
- Verifikasi `tabungan/get_saldo.php` memakai guard yang sama dengan modul induknya.

### 10.5 CSRF dan method safety

- Semua mutasi harus POST atau method semantik lain yang diterima secara eksplisit; GET/HEAD tidak boleh mengubah state.
- Token harus random, terikat sesi, dibandingkan dengan `hash_equals()`, tidak tampil di URL/log, dan diregenerasi sesuai kebijakan.
- Uji token kosong, salah, milik sesi lain, kadaluarsa, duplikat, serta request form/JSON/multipart.
- Uji `Origin`/`Referer` sebagai defense-in-depth, bukan pengganti token.
- Uji pembayaran input/update/delete, tabungan masuk/keluar, user, siswa, semua master, publish, close year, archive/restore, dan logout.
- Method salah harus memberi 405 tanpa perubahan DB.

### 10.6 Injection dan validasi input

- Inventaris seluruh `$_GET`, `$_POST`, header, cookie, session, dan nilai dari database yang dipakai untuk query dinamis.
- Uji SQLi boolean, error, stacked bila driver memungkinkan, time-based, encoding ganda, parameter array, duplicate parameter, malformed UTF-8, dan angka ekstrem.
- Prepared statement tetap harus ditinjau untuk dynamic identifiers, `ORDER BY`, `IN (...)`, `LIMIT`, dan fragment filter.
- Uji nominal: kosong, nol, negatif, `NaN`, `INF`, notasi ilmiah, overflow, ribuan separator, pecahan >2 digit, dan string campuran.
- Uji tanggal mustahil, timezone boundary, tahun terlalu lama/masa depan, bulan tidak valid, serta rentang terbalik/terlalu lebar.
- Uji panjang maksimum nama, keterangan, NIS, username, kode rombel, dan nama biaya pada backend serta database.

### 10.7 XSS dan output context

- Uji reflected, stored, dan DOM XSS pada nama siswa, NIS, akun, nama master, keterangan pembayaran, catatan biaya, filter, flash/error, report, receipt, modal, dan pencarian.
- Gunakan payload berbeda untuk HTML text, attribute, URL, JavaScript inline, CSS, print, dan PDF.
- Pastikan encoder sesuai konteks; `htmlspecialchars()` bukan encoder untuk JavaScript atau spreadsheet.
- Uji Content Security Policy awal dalam report-only, lalu enforcement setelah inline script/style dipetakan.
- Verifikasi semua link eksternal memakai kebijakan `rel` yang tepat dan tidak ada `javascript:`/open redirect.

### 10.8 Spreadsheet, PDF, dan file content

- Uji formula injection dengan nilai diawali `=`, `+`, `-`, `@`, tab, CR, dan kombinasi whitespace.
- Pertahankan leading zero NIS sebagai teks; uji angka besar, Unicode, karakter HTML, dan line break.
- Verifikasi MIME, `Content-Disposition`, nama file, encoding, dan warning Excel karena output saat ini HTML ber-ekstensi `.xls`.
- Uji Dompdf terhadap data panjang, page break, resource lokal, chroot, memory limit, timeout, dan advisory dependency.
- Pastikan tidak ada remote fetch, local file disclosure, path traversal, atau stack trace pada kegagalan render.

### 10.9 Error, logging, dan audit trail

- UI hanya menampilkan pesan generik dan correlation ID; detail exception/SQL hanya ke log terlindungi.
- Matikan `display_errors` dan `expose_php` pada produksi; hilangkan banner server versi.
- Gunakan log terstruktur untuk login, perubahan akun/role, publish/close master, siswa, transaksi, koreksi, delete/reversal, export sensitif, backup, dan kegagalan integritas.
- Log keuangan harus append-only, memuat actor awal, actor koreksi, before/after, alasan, timestamp WIB/UTC yang jelas, request ID, dan referensi transaksi.
- Jangan memasukkan password, cookie, CSRF token, koneksi DB, atau PII berlebihan.
- Uji log injection, rotasi, retensi, ACL, kapasitas disk, pencarian insiden, dan sinkronisasi waktu.

### 10.10 Server, header, dan dependency

- Tambahkan dan uji `nosniff`, frame protection/CSP `frame-ancestors`, Referrer-Policy, Permissions-Policy, cache control halaman privat, dan HSTS hanya setelah HTTPS benar-benar aktif.
- Matikan directory index dan eksekusi script pada folder nonpublik.
- Jalankan `composer validate --strict`, `composer audit --locked --no-dev` pada CI berjaringan, `composer check-platform-reqs`, SBOM, dan pemeriksaan lisensi.
- Uji versi PHP yang benar-benar disepakati. Baseline Composer menargetkan 8.0.30 sedangkan CLI reconnaissance 8.3.31; hasil pada satu versi tidak boleh diasumsikan identik.
- Pastikan `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` atau strategi error eksplisit konsisten sehingga transaction benar-benar rollback ketika query gagal.
- Jalankan SAST, secret scan, authenticated DAST, dan regression keamanan; temuan tool harus ditriase manual.

## 11. Wave 3 — integritas data dan aturan bisnis keuangan

### 11.1 Kontrak sumber data

```text
Siswa aktif + kelas aktif
  +-- siswa_tahun_ajaran (snapshot kelas, SPP, Komite)
  |     +-- bayar_spp_periode --> bayar
  |     +-- tagihan_daftar_ulang --> bayar_du --> bayar
  |
  +-- tagihan_biaya_lain --> bayar_biaya_lain --> bayar
  +-- tarif biaya awal siswa --> bayar --> cache *_BAYAR
  +-- transaksi_m / transaksi_k --> cache tabungan.SALDO
```

Kontrak penting:

- `bayar` adalah header penerimaan pembayaran sekolah, bukan ledger tabungan.
- `bayar_spp_periode` adalah claim periode SPP; laporan kewajiban SPP tidak boleh menebak periode hanya dari tanggal transaksi.
- Tahun ajaran SPP dan DU adalah Juli–Juni. `TGL_BYR` adalah waktu penerimaan kas dan tidak sama dengan periode kewajiban.
- Tagihan DU dan biaya lain bersifat materialized/snapshot. Perubahan master tidak boleh mengubah histori terbit.
- `bayar_du.bayar_id` dan `bayar_biaya_lain.bayar_id` mengikat child ke pembayaran. Child legacy yang tidak dapat dibuktikan tidak boleh ditebak.
- `payment_link_version=0` adalah legacy read-only sampai rekonsiliasi manual; versi aman harus memiliki relasi yang konsisten.
- `transaksi_m` dan `transaksi_k` adalah ledger tabungan; `tabungan.SALDO` adalah cache yang wajib dapat direkonsiliasi dari saldo awal yang didefinisikan + ledger.
- `PANGKAL_BAYAR`, `BANGUNAN_BAYAR`, `SERAGAM_BAYAR`, dan `KEGIATAN_BAYAR` adalah cache/turunan; transaksi adalah bukti sumber.
- Pembayaran tahunan baru saat ini ditolak; batch lama tetap perlu dapat dibaca/cetak sampai keputusan DEC-004.

### 11.2 Verifier integritas data wajib

Buat verifier read-only terpisah dari `sql/verify_schema.sql`. Ia harus mengembalikan jumlah mismatch, sample ID anonim, dan exit/gate yang jelas untuk:

| ID | Invariant |
| --- | --- |
| INV-001 | Setiap `bayar.U_SPP > 0` mempunyai claim periode yang tepat; claim tidak yatim dan tidak menunjuk siswa/periode berbeda. |
| INV-002 | Total SPP per siswa/periode tidak melebihi snapshot tagihan dan urutan Juli–Juni tidak bolong sesuai aturan yang disetujui. |
| INV-003 | `bayar.total_jumlah = max(0, Σ komponen tetap + bayar_du.jumlah + Σ bayar_biaya_lain.nominal_snapshot - potong_spp)` dengan toleransi DECIMAL yang ditetapkan. |
| INV-004 | `U_LAIN` dan slot legacy, bila dipertahankan sebagai cache kompatibilitas, setara dengan detail biaya lain atau ditandai legacy secara eksplisit. |
| INV-005 | Child pembayaran mempunyai NIS/tagihan yang sama dengan header dan tidak tertukar lintas siswa. |
| INV-006 | Pembayaran versi aman memenuhi relasi wajib; versi legacy tetap dibatasi dan tidak memiliki relasi hasil tebakan. |
| INV-007 | Paid DU tidak melebihi `tagihan_daftar_ulang.nominal_tagihan`; status open/paid/cancelled konsisten. |
| INV-008 | Paid biaya lain tidak melebihi `tagihan_biaya_lain.nominal_tagihan`; snapshot nama/nominal tetap historis. |
| INV-009 | Cache biaya awal `*_BAYAR` sama dengan agregat transaksi sumber. |
| INV-010 | `tabungan.SALDO` sama dengan saldo awal yang sah + masuk − keluar; tidak negatif; jurnal tidak yatim. |
| INV-011 | Tidak ada linked-saving baru dari pembayaran setelah pemisahan modul; data lama mengikuti kebijakan cleanup yang terdokumentasi. |
| INV-012 | Snapshot kelas, SPP, dan Komite tidak kosong untuk histori yang membutuhkannya; transaksi tidak berubah saat kelas/tarif aktif diubah. |
| INV-013 | NIS/NIS Diknas unik sesuai aturan; FK tidak yatim; siswa arsip tetap menjaga histori. |
| INV-014 | Rentang `tahun_ajaran` valid, tidak tumpang tindih secara tidak sah, dan hanya memiliki state transition yang diizinkan. |
| INV-015 | Payment batch legacy mempunyai token, sequence 1–12 unik/lengkap, nominal dan receipt konsisten. |
| INV-016 | Actor/created/updated timestamp valid, timezone konsisten, dan koreksi dapat ditelusuri. |
| INV-017 | Nilai uang finite, nonnegatif pada kolom yang seharusnya, maksimal dua desimal, dan tidak mengalami drift float. |

Verifier struktur yang ada juga harus diperluas untuk tipe kolom, precision/scale, nullability, default, enum, charset/collation, engine, exact FK columns/rules, unique/index columns/order, CHECK, serta absence constraint lama yang dilarang. Sekadar mengetahui nama kolom/index ada belum cukup.

### 11.3 Skenario pembayaran wajib

- Input satu komponen, banyak komponen, biaya lain, DU, SPP cicilan, Komite, diskon, dan kombinasi semua komponen.
- Input seluruh komponen nol harus mengikuti keputusan DEC-009.
- Double submit, refresh setelah POST, back/forward, retry karena timeout, dan request identik paralel; tentukan idempotency key atau mekanisme pencegah replay.
- Manipulasi hidden/readonly: NIS, kelas, tarif, tagihan, nominal, total, role, metode, periode, dan `payment_link_version` harus dihitung/validasi server-side.
- Edit transaksi dengan menambah/mengurangi/mengosongkan/memindahkan siswa/periode/tagihan; pastikan cache dan claim ikut berubah atomik.
- Hapus transaksi terakhir dan transaksi prasyarat; pastikan aturan SPP berikutnya tidak rusak.
- Dua transaksi siswa yang sama pada tanggal sama; edit/hapus hanya menyentuh child miliknya.
- Kegagalan sengaja setelah setiap write dalam transaction; seluruh tabel dan cache harus kembali identik dengan before-state.
- Legacy v0 tidak boleh diedit/delete melalui UI maupun direct request.
- Koreksi pembayaran periode lama setelah tarif atau kelas aktif berubah harus memakai sumber historis yang diputuskan di DEC-008.
- Urutan lock harus konsisten. Uji input-vs-edit, edit-vs-edit, input-vs-delete, dan edit silang siswa dengan dua koneksi; deadlock harus rollback penuh dan memberikan respons aman/retriable.

### 11.4 SPP

- Uji semua boundary tahun ajaran: Juni→Juli, Desember→Januari, tahun kabisat, periode lalu dan depan.
- Uji cicilan 1/2/lebih, pelunasan tepat, overpay kecil/besar, dan pembulatan dua desimal.
- Uji bulan sebelumnya nol/sebagian/lunas dan pengaruh edit/delete pada bulan setelahnya.
- Bandingkan tarif snapshot terhadap tarif aktif setelah perubahan siswa.
- Rekonsiliasi 16 pembayaran baseline yang belum memiliki claim pada salinan DB; jangan backfill produksi sebelum aturan pemetaan dan hasil preview disetujui.
- Jalankan backfill dua kali dan buktikan tidak menduplikasi claim.

### 11.5 Daftar Ulang

- Uji state `draft → published → closed` dan semua transisi terlarang.
- Terbitkan dengan 0 siswa, satu siswa, siswa arsip, kelas placeholder, semua kelas, dan concurrent publish.
- Ubah tarif sebelum/sesudah publish; histori terbit tidak boleh berubah tanpa workflow koreksi/audit.
- Uji potongan, nominal nol bila diizinkan, cicilan, pelunasan, overpay, cancel, edit/delete pembayaran, dan tahun `closed` sesuai DEC-002.
- Pastikan tagihan nol pembayaran tetap muncul di riwayat dan pagination/ringkasan benar.

### 11.6 Biaya lain dan biaya awal

- Terbitkan ke semua siswa, tingkat, rombel, dan siswa terpilih; uji target kosong, duplikat, arsip, concurrent publish, dan master nonaktif.
- Uji cicilan, overpay, perubahan nama/tarif master, delete master terpakai, dan snapshot histori.
- Rekonsiliasi cache biaya awal pada setiap input/edit/delete dan setelah migrasi sync.
- Tentukan apakah cache tetap diperlukan; bila ya, tambahkan verifier/regression. Bila tidak, rencanakan penghapusan terkontrol setelah seluruh consumer dipindah.

### 11.7 Tabungan

- Definisikan sumber saldo awal secara eksplisit agar ledger dapat direkonsiliasi.
- Uji setoran/penarikan nol, negatif, pecahan, sangat besar, saldo pas, lebih besar dari saldo, dan dua penarikan paralel.
- Validasi tanggal backend, periode masa depan/lalu, NIS aktif/arsip/tidak ada, serta keterangan. Saat ini keterangan dibaca tetapi perlu dibuktikan apakah disimpan dan ditampilkan.
- Uji duplicate submit/replay/idempotency, crash di tiap write, deadlock, dan respons pengguna.
- Uji saldo endpoint terhadap akses role, akun terhapus, dan ID enumeration.
- Pastikan transaksi manual pada tanggal yang sama dengan pembayaran/koreksi lain tidak pernah ikut terhapus.

### 11.8 Uang bertipe tepat

Audit seluruh `DOUBLE/FLOAT` pada `bayar`, `tabungan`, dan jurnal. Rencanakan migrasi ke `DECIMAL(p,2)` setelah profiling nilai maksimum, pembulatan, dan query parity. Tambahkan CHECK nonnegatif/range bila didukung target DB, tetap dengan validasi backend. Migrasi wajib membandingkan nilai sebelum/sesudah dan tidak boleh membulatkan diam-diam.

## 12. Wave 4 — schema, migrasi, install, upgrade, dan rollback

### 12.1 Jalur yang wajib diuji

| Jalur | Starting point | Target | Bukti |
| --- | --- | --- | --- |
| Fresh | database kosong | `schema.sql` terbaru | semua structure/data verifier lulus; akun produksi aman |
| Legacy minimum | snapshot schema tertua yang masih didukung | schema terbaru | setiap migrasi, preflight, postflight, dan data parity |
| Legacy berhistori | snapshot anonim berisi seluruh tipe transaksi lama | schema terbaru | legacy tetap aman, tidak ada relasi tebakan |
| Versi perantara | snapshot sebelum setiap migrasi besar | schema terbaru | dependency/urutan benar |
| Current production-like | restore snapshot anonim terbaru | schema terbaru | durasi, lock, disk, backup, rollback |
| Repeat | hasil upgrade | jalankan migrasi lagi | idempotent, nol duplikasi/error |
| Failed midway | injeksi kegagalan terkontrol | resume/rollback | state dapat dipahami dan dipulihkan |

### 12.2 Inventaris migrasi yang harus masuk manifest kanonik

Dokumentasi upgrade saat ini belum mencantumkan semua script. Jangan memakai urutan alfabet atau daftar berikut langsung di produksi; audit dependency dan test harus menghasilkan satu urutan final.

| Script | Tujuan yang harus diverifikasi | Risiko khusus |
| --- | --- | --- |
| `add_role_management.sql` | role akun | default role dan akun lama |
| `add_master_biaya_lain.sql` | master/detail biaya lain + legacy | backfill dan unique legacy key |
| `add_master_daftar_ulang.sql` | master DU | duplikasi kelas/periode |
| `add_academic_year_billing.sql` | tahun, placement, tagihan DU | backfill histori dan snapshot |
| `add_student_advanced.sql` | field siswa, audit, Komite, DECIMAL sebagian | preflight kelas/duplikasi NIS |
| `add_student_optional_fees.sql` | Makan/Sorga/Infaq | default dan tipe uang |
| `add_payment_references.sql` | relasi eksplisit child | legacy tidak boleh ditebak |
| `add_payment_method.sql` | Tunai/VA/Qris | default transaksi lama |
| `add_payment_updated_at.sql` | waktu koreksi | nilai histori lama |
| `add_annual_payment_receipts.sql` | batch receipt + claim/backfill SPP | coverage dan duplikasi claim |
| `allow_spp_installments.sql` | claim non-unique per periode | constraint lama dan backfill |
| `activate_legacy_fields.sql` | aktivasi/rekonsiliasi field legacy | perubahan data besar dan konflik |
| `add_modular_global_reports.sql` | rombel, snapshot, tagihan lain, index laporan | placeholder, FK, data besar |
| `remove_payment_linked_savings.sql` | pemisahan tabungan dari pembayaran | saldo tidak cukup dan histori |
| `sync_student_initial_fee_paid_totals.sql` | sinkron cache biaya awal | harus cocok agregat pembayaran |
| `verify_schema.sql` | verifier struktur | saat ini belum memeriksa coverage data |
| `seed_demo_students_30.sql`, `seed_kasir_accounts.sql` | data development | tidak boleh masuk produksi |

Manifest final harus menyebut prerequisite, schema version before/after, transactional behavior DDL target DB, expected row impact, preflight stop condition, postflight query, idempotency, estimasi lock/disk, rollback/restore, dan siapa yang menyetujui hasil preview.

### 12.3 Pemeriksaan schema

- Bandingkan `SHOW CREATE TABLE` seluruh tabel fresh vs upgrade, bukan hanya keberadaan nama.
- Pastikan engine InnoDB, charset/collation konsisten, timezone dan `sql_mode` eksplisit.
- Audit FK cascade/restrict/set-null terhadap retensi histori; khususnya hard delete siswa tidak boleh diam-diam menghapus pembayaran.
- Audit index berdasarkan query nyata dengan `EXPLAIN`, cardinality, selectivity, dan dataset besar.
- Audit CHECK pada target MySQL/MariaDB karena dukungan/enforcement berbeda antar versi.
- Pastikan procedure sementara migrasi terhapus dan tidak ada trigger/event tersembunyi.
- Buat tabel/version marker migrasi atau mekanisme manifest yang dapat menentukan apa yang sudah dijalankan; jangan bergantung pada ingatan operator.

## 13. Wave 5 — audit laporan, struk, dan export

### 13.1 Dataset oracle

Buat fixture anonim kecil tetapi lengkap: minimal dua tahun ajaran, dua rombel per tingkat terpilih, siswa aktif/arsip, tarif berbeda, pembayaran tunai/non-tunai, SPP cicilan, DU, biaya lain cicilan, Komite, diskon, transaksi koreksi, legacy, tabungan masuk/keluar, transaksi di batas tanggal, serta siswa tanpa pembayaran.

Hitung expected result secara independen dari query aplikasi. Simpan rumus dan expected totals. Jangan membuat oracle dengan memanggil helper produksi yang sama karena defect dapat terduplikasi.

### 13.2 Matriks parity

Untuk setiap laporan, bandingkan jumlah baris, subtotal, grand total, saldo awal/akhir, filter, urutan, kelas, NIS, periode, metode, dan status pada semua format yang tersedia.

| Laporan | Web | Print | PDF | Excel | Fokus khusus |
| --- | --- | --- | --- | --- | --- |
| Laporan Umum | Ya | preview | Ya | Ya | tanggal transaksi dan semua komponen |
| Status Pembayaran | Ya | Ya | Ya | Ya | snapshot/tagihan versus paid |
| Penerimaan Harian | Ya | Ya | Ya | Ya | gross, metode, actor, koreksi |
| SPP Tahun Ajaran | Ya | Ya | Ya | Ya | claim Juli–Juni, cicilan, kelas snapshot |
| Pembayaran per Item | Ya | Ya | Ya | Ya | tarif aktif vs historis harus diputuskan |
| Mutasi Tabungan per Kelas | Ya | Ya | Ya | Ya | opening/mutasi/closing |
| Tabungan Siswa | Ya | Ya | Ya | Ya | seluruh siswa dan saldo |
| Setoran Kas Harian | Ya | Ya | Ya | Ya | tunai, non-tunai, dana tabungan |
| Rekap per Kelas dilindungi | Ya | print browser | sesuai fitur saat ini | sesuai fitur saat ini | screenshot regression, jangan ubah konsep |
| Struk biasa/tahunan | preview | Ya | sesuai endpoint | N/A | ID, batch, sisa PSB/DU, actor |

Uji data kosong, satu baris, normal, data besar, rentang satu hari/bulan/tahun, batas tengah malam, rentang terbalik, filter invalid, semua role, dan concurrent correction saat report dibuat. Putuskan apakah laporan live boleh berubah atau memerlukan snapshot closing.

### 13.3 Skala dan kegagalan

- Pagination harus dilakukan di DB untuk dataset besar; ukur query count, wall time, peak memory, rows examined, temporary table/filesort, dan ukuran response.
- Tetapkan batas rentang tanggal, jumlah row export, timeout, dan pesan ketika limit terlampaui.
- Buktikan PDF multi-page dapat dibuka dan tidak terpotong.
- Buka file Excel pada aplikasi client yang disepakati; cek warning format, formula injection, leading zero, encoding, nominal/date type, dan filter.
- Uji kegagalan Dompdf/dependency agar tidak menampilkan stack trace atau menghasilkan file kosong bernama sukses.

## 14. Wave 6 — frontend, UX, responsive, accessibility, dan browser

### 14.1 Matriks visual

Uji seluruh route dan state penting pada lebar 320, 360, 390, 540, 768, 1024, dan 1440 px; orientasi portrait/landscape; tema terang/gelap; zoom 200%; data kosong/normal/panjang; error/sukses; modal; loading; dan print.

Browser minimum ditentukan pada DEC-007. Baseline yang disarankan untuk validasi: Chrome/Edge desktop terbaru yang didukung client dan Chrome Android; tambah Firefox bila dipakai operasional.

### 14.2 Accessibility

- Semua kontrol memiliki label programatik, accessible name, urutan heading, dan target sentuh memadai.
- Navigasi sidebar/bottom nav, dropdown, modal, date range, tabel, pagination, dan toast dapat dioperasikan hanya dengan keyboard.
- Modal memakai `role="dialog"`, `aria-modal`, label, initial focus, focus trap, Escape, dan return focus.
- Sidebar menyinkronkan `aria-expanded`, dapat ditutup Escape, dan backdrop bukan satu-satunya kontrol.
- Tabel mobile menyimpan nama kolom; audit khusus `data-label` dashboard.
- Error dihubungkan ke field, tidak hanya warna; focus menuju error pertama; screen reader mendapat status.
- Uji contrast, focus indicator, reflow, text spacing, dan `prefers-reduced-motion`.
- Gambar informatif memiliki alt; dekoratif memakai alt kosong; SVG button memiliki nama.

### 14.3 Konsistensi dan ketahanan form

- Label/istilah NIS, NIS Diknas, kelas, rombel, SPP, Komite, DU, biaya lain, saldo, metode, tanggal, dan tahun ajaran konsisten.
- Validasi frontend dan backend mempunyai aturan sama, tetapi backend tetap otoritatif.
- Tombol disable/loading mencegah double click tanpa mengunci pengguna selamanya saat error.
- Reset form membersihkan seluruh state, validity, hidden ID, alert, nominal, dan pilihan dinamis.
- Back/refresh tidak mengulang mutasi; PRG dan idempotency diuji.
- Empty state, skeleton/loading, timeout, offline font, session expired, 403/404/500, dan koneksi DB gagal memiliki UI yang dapat dipahami tanpa detail internal.

### 14.4 Aset dan performa frontend

- Buat satu strategi versioning/cache asset; baseline saat ini memakai versi query yang berbeda-beda.
- Ukur ukuran transfer, parse/execute JS, style recalculation, layout shift, dan jumlah request setiap route.
- `app.js` dan `style.css` global harus dipetakan per penggunaan; jangan memecah sebelum coverage dan dependency diketahui.
- Hentikan timer global pada halaman yang tidak mempunyai target, atau lazy-init komponen berdasarkan DOM.
- Putuskan self-host Google Fonts/fallback untuk operasi offline dan privasi.
- Optimalkan gambar hanya setelah referensi, kualitas, dimensi, dan screenshot regression diverifikasi.

## 15. Wave 7 — dead code, sistem setengah jadi, dan drift dokumentasi

### 15.1 Metodologi aman

1. Buat manifest seluruh route, direct entrypoint, include/require, form action, link, AJAX/fetch, redirect, asset, PHP function, JS function, selector CSS, tabel/kolom, migrasi, test, dan dokumen.
2. Bangun graph referensi statis dengan pencarian literal dan parser/linter bila tersedia.
3. Jalankan authenticated crawl untuk admin/bendahara/kasir; rekam status, redirect, request, console error, dan route dinamis.
4. Ambil JS/CSS runtime coverage untuk setiap route, tema, viewport, modal, error, print, dan semua template laporan.
5. Tinjau Git history, changelog, SOP, dan keputusan pemilik untuk mengetahui alasan compatibility.
6. Klasifikasikan setiap item: `aktif`, `dipanggil dinamis`, `compatibility`, `migration-only`, `test-only`, `kandidat`, `mati terkonfirmasi`, atau `dilindungi`.
7. Kandidat baru boleh dihapus setelah minimal dua bukti independen dan persetujuan owner.
8. Hapus per cluster kecil dalam commit terpisah; ulang lint, test, crawl, keyboard test, dan screenshot regression.

### 15.2 Allowlist dilindungi

- `laporan/rekap_kelas.php`;
- link sidebar dan alur `laporan/detail_siswa.php` yang menopangnya;
- selector CSS, helper, filter, dan aset yang terbukti diperlukan tampilan tersebut;
- data/class legacy yang masih diperlukan rekap sampai keputusan migrasi eksplisit.

Coverage nol pada satu run tidak mengalahkan status dilindungi.

### 15.3 Kandidat yang harus diputuskan, bukan langsung dihapus

| Kandidat | Indikasi | Verifikasi wajib |
| --- | --- | --- |
| Cabang annual payment di `pembayaran/proses.php` | backend menolak mode selain monthly tetapi implementasi 12 transaksi masih ada di bawahnya | test reachability, kebutuhan receipt legacy, DEC-004 |
| Helper annual dan prototipe lama di `assets/js/app.js` | beberapa fungsi tidak punya pemanggil statis/markup aktif | runtime coverage seluruh form/edit/legacy |
| `switchTab`, `cariSiswa`, `filterTable`, `inputData`, `editData`, `hapusData`, `keluarForm`, `cetakLaporan`, localStorage `dataStore/spp_data` | pola prototipe/fallback lama | crawl, global invocation, history, console instrumentation |
| Cluster CSS login/wrapper/tab lama | selector tidak ditemukan pada markup aktif | CSS coverage semua state termasuk login/error |
| `assets/img/login_illustration.png` | nol referensi literal dan ukuran besar | cek CSS, generated HTML, history, desain client |
| Checkbox “Ingat perangkat” dan tautan login mati | UI ada tanpa alur backend/tujuan | keputusan implement/hapus dan UAT |
| Dua pipeline laporan | keduanya aktif tetapi berpotensi tumpang tindih/drift | ownership produk, parity, maintenance cost |
| Kolom cache/legacy dan migration scripts | terlihat tidak dipakai alur baru | query consumer, histori, rollback, report compatibility |
| Seed demo/default | berguna developer tetapi berbahaya produksi | packaging profile development vs production |

### 15.4 Dokumentasi

Audit setiap klaim pada `PROJECT_CONTEXT.md`, `PROGRESS.md`, `AI_CHANGELOG.md`, brief, MoM, SOP HTML/PDF, dan flowchart HTML/PDF terhadap source/runtime terbaru.

Minimum drift yang harus diselesaikan:

- role matrix versus guard/sidebar aktual;
- dashboard closing harian versus deskripsi lama;
- Tabungan Wajib yang sudah dipisah dari pembayaran;
- jumlah dan jenis automated test;
- urutan migrasi yang belum lengkap;
- status/fallback DU yang kontradiktif;
- ID temuan duplikat pada register progres;
- placeholder peserta/tanggal/PIC dan commit lama pada MoM;
- sinkronisasi HTML dengan PDF hasil render;
- tujuh template laporan modular versus laporan umum dan rekap dilindungi.

Setiap PDF dokumentasi harus dirender ulang dari source final dan dicek visual halaman per halaman.

## 16. Wave 8 — test automation dan remediasi

### 16.1 Fondasi test

- Tambahkan satu runner resmi melalui Composer atau script PowerShell yang memberi exit code nonzero saat gagal.
- Pisahkan unit test murni, DB integration, HTTP integration, browser E2E, migration, security regression, concurrency, dan performance.
- Gunakan database unik per run/worker, fixture anonim deterministik, credential test khusus, cleanup `finally`, dan guard yang menolak nama DB non-test.
- Jangan bergantung pada password default atau server developer yang kebetulan aktif.
- Bekukan clock/timezone ketika test periode/tanggal; hindari asumsi bulan saat ini.
- Hasil test memuat commit, schema version, seed version, runtime, duration, dan failure artifact.

Delapan script yang ada harus dipetakan: apa yang benar-benar diuji, data apa yang ditulis, apakah rollback/cleanup selalu berjalan, dan apakah test bisa dijalankan ulang. `modular_reports_test.php` perlu diperluas agar mendeteksi payment SPP tanpa claim; integration payment perlu fixture dan login khusus test.

### 16.2 Urutan remediasi

1. Containment tanpa perubahan domain: web exposure, directory listing, test execution, secret, default account.
2. Backup/restore dan verifier data read-only.
3. Rekonsiliasi data dengan preview dan persetujuan; jangan langsung backfill produksi.
4. Authentication/session/RBAC/CSRF/method/error hardening.
5. Integritas transaksi, audit trail, idempotency, concurrency, dan tipe uang.
6. Canonical migration manifest dan fresh/upgrade parity.
7. Laporan/export parity dan resource limits.
8. UX/accessibility/performance.
9. Dead code/documentation cleanup per cluster kecil.
10. Full regression, release rehearsal, UAT, dan sign-off.

Setiap fix harus memiliki regression test yang gagal pada versi lama dan lulus pada versi baru bila praktis. Hindari menggabungkan security hardening, perubahan rumus uang, migrasi besar, dan redesign visual dalam satu commit.

## 17. Katalog test minimum

| Kelompok | ID | Skenario minimum | Acceptance |
| --- | --- | --- | --- |
| Exposure | TC-WEB-001 | `.git`, SQL, test, docs, manifest, dotfile | seluruhnya 403/404; test non-executable |
| Auth | TC-AUTH-001 | valid/invalid/enumeration/rate limit | pesan aman, throttling tercatat |
| Session | TC-AUTH-002 | fixation, logout, timeout, deleted/role-changed account | sesi lama tidak berwenang |
| RBAC | TC-RBAC-001 | seluruh endpoint × seluruh role × direct URL | sesuai matriks disetujui |
| CSRF | TC-SEC-001 | setiap mutasi dengan token kosong/salah/cross-session | ditolak tanpa perubahan DB |
| Method | TC-SEC-002 | GET/HEAD pada mutasi | 405/aman, state identik |
| SQLi | TC-SEC-003 | seluruh parameter dengan boolean/error/time payload | literal/ditolak, tanpa perluasan data |
| XSS | TC-SEC-004 | stored/reflected/DOM per output context | tidak ada eksekusi |
| Export | TC-SEC-005 | formula prefix/tab/CR dan leading zero | inert, tipe/format benar |
| Error | TC-SEC-006 | DB/PDF/validation failure sintetis | UI generik, detail hanya log |
| Payment | TC-PAY-001 | create/edit/delete kombinasi komponen | atomik dan total benar |
| Payment | TC-PAY-002 | nol/negatif/overflow/NaN/replay | sesuai kontrak, tanpa row liar |
| Payment | TC-PAY-003 | dua pembayaran tanggal sama | child tidak tertukar |
| Payment | TC-PAY-004 | legacy v0 direct edit/delete | selalu ditolak |
| SPP | TC-SPP-001 | cicilan dan sequence Juli–Juni | cap dan urutan benar |
| SPP | TC-SPP-002 | edit/delete prasyarat | tidak membuat bulan bolong |
| SPP | TC-SPP-003 | backfill claim dijalankan dua kali | coverage 100%, nol duplikasi |
| DU | TC-DU-001 | publish idempotent dan concurrent | satu bill/siswa/tahun |
| DU | TC-DU-002 | cicilan/overpay/closed year | sesuai DEC-002, atomik |
| Fee | TC-FEE-001 | publish berbagai target | target tepat, nol duplikasi |
| Fee | TC-FEE-002 | snapshot/cicilan/master berubah | histori tetap, cap benar |
| Savings | TC-SAV-001 | masuk/keluar/saldo pas/overdraw | ledger dan cache seimbang |
| Savings | TC-SAV-002 | dua withdrawal concurrent/replay | tidak negatif/duplikat |
| Savings | TC-SAV-003 | payment correction pada tanggal sama | jurnal manual tetap utuh |
| Student | TC-STD-001 | NIS change/archive/restore | FK, histori, audit benar |
| User | TC-USR-001 | delete last admin concurrent | minimal satu admin tetap ada |
| Data | TC-DATA-001 | seluruh verifier 11.2 | nol mismatch atau exception disetujui |
| Migration | TC-DBM-001 | fresh schema | structure/data verifier lulus |
| Migration | TC-DBM-002 | semua snapshot upgrade + repeat | parity dan idempotent |
| Migration | TC-DBM-003 | failure/resume/restore | dapat pulih tanpa state ambigu |
| Report | TC-REP-001 | dataset oracle pada semua laporan/format | row/subtotal/grand total sama |
| Report | TC-REP-002 | kosong/normal/besar/range boundary | benar dan dalam limit |
| Receipt | TC-REP-003 | ID/batch/legacy/multi-page | akses dan isi tepat |
| UI | TC-UI-001 | viewport/theme/zoom/state matrix | tidak terpotong/kehilangan makna |
| A11y | TC-UI-002 | keyboard, focus, dialog, label, contrast | lulus acceptance yang ditetapkan |
| Perf | TC-PERF-001 | volume realistis dan worst allowed | memenuhi budget response/RAM/query |
| Backup | TC-DR-001 | backup + restore penuh | checksum dan verifier lulus |
| Deploy | TC-DEP-001 | clone bersih → install → migrate → smoke | reproducible tanpa file lokal tersembunyi |
| Dead code | TC-DEAD-001 | hapus satu cluster kandidat | lint/test/crawl/coverage/screenshot lulus |
| Protected | TC-REG-001 | rekap kelas + detail siswa | visual/alur tetap sesuai baseline |

## 18. Budget performa dan volume uji

Volume ditentukan dari proyeksi client, lalu diuji minimal pada 1× data sekarang, 3× proyeksi satu tahun, dan worst allowed. Catat jumlah siswa, transaksi per siswa, tahun histori, row detail, dan concurrent operator tanpa PII.

Budget harus disepakati sebelum test. Minimum yang perlu diukur:

- p95 response halaman operasional dan laporan;
- p95 submit transaksi serta lock wait;
- peak memory PHP untuk web/PDF/Excel;
- jumlah query dan rows examined;
- ukuran HTML/PDF/Excel;
- waktu migrasi, backup, restore, dan verifier;
- perilaku saat limit terlampaui dan saat dua operator mengoreksi data bersamaan.

Load test tidak boleh menggunakan produksi dan tidak boleh menargetkan pihak ketiga seperti Google Fonts.

## 19. Perintah baseline dan bukti yang direkomendasikan

Gunakan command yang sesuai environment dan simpan output tersanitasi. Contoh berikut bukan izin menjalankan mutasi pada database utama.

```powershell
git status --short
git rev-parse HEAD
git log -5 --oneline

Get-ChildItem -Recurse -Filter *.php |
  Where-Object { $_.FullName -notmatch '\\vendor\\' } |
  ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }

node --check assets\js\app.js
composer validate --strict
composer check-platform-reqs
composer audit --locked --no-dev
git diff --check
```

Pemeriksaan lain yang harus discript dan versioned:

- HTTP smoke/role/method matrix dengan cookie jar terpisah per role;
- URL-deny matrix untuk artefak sensitif;
- `SHOW CREATE TABLE`, `information_schema`, `EXPLAIN`, dan verifier integritas data;
- backup/hash/restore pada database audit;
- fresh install dan upgrade manifest dua kali;
- browser screenshot/console/network/accessibility run;
- PDF render/open dan Excel open/formula regression;
- concurrency dengan dua koneksi dan failpoint transaction.

Nama bukti disarankan:

```text
<run-id>__<finding-or-test-id>__<role>__<endpoint-or-module>__<result>.<ext>
```

Setiap log test harus menyebut expected, actual, pass/fail, dan state DB sebelum/sesudah. Screenshot tanpa konteks dan output command tanpa commit SHA tidak cukup.

## 20. Audit kesiapan operasi dan handover

### 20.1 Paket deployment

- Clone/paket bersih tidak bergantung pada `vendor/` lokal yang tidak ter-versioning; dokumentasikan `composer install --no-dev --prefer-dist --optimize-autoloader` dan lockfile.
- Daftar ekstensi PHP, konfigurasi memory/upload/timezone/session, versi DB, web server, dan permission filesystem.
- Konfigurasi development, test, dan production terpisah.
- Health/readiness check tidak membocorkan detail dan tidak melakukan mutasi.
- Asset/font tersedia sesuai keputusan offline.
- Tidak ada seed demo, test, schema, docs internal, `.git`, dump, atau secret dalam public package.

### 20.2 Operasional

- SOP pembuatan/nonaktif akun, rotasi password, closing, koreksi transaksi, rekonsiliasi, backup, restore, dan incident response.
- Jadwal backup, retensi, enkripsi, monitoring keberhasilan, restore drill, dan PIC.
- Log rotation, disk alert, error alert, failed login alert, integrity mismatch alert, dan review audit trail.
- Prosedur tahun ajaran, publish tagihan, penutupan, perpindahan kelas, siswa keluar, serta legacy reconciliation.
- Prosedur support: kanal, severity, response target, eskalasi, dan informasi yang boleh dikirim.

### 20.3 Privasi dan legal operasional

- Inventaris PII, tujuan penggunaan, role yang dapat melihat, lokasi penyimpanan, retensi, backup, export, dan pemusnahan.
- Uji file download tidak tersimpan publik dan browser/shared PC tidak meninggalkan data sensitif tanpa kebijakan.
- Dokumentasikan dependency/license dan attribution yang diwajibkan.
- Client menyetujui privacy notice/syarat bila link tersebut dipertahankan di login.

## 21. Gate final sebelum diserahkan ke client

Release hanya boleh berstatus **GO** bila seluruh syarat berikut terpenuhi:

### Security

- Nol temuan Kritis terbuka.
- Nol temuan Tinggi terbuka, kecuali ada risk acceptance bertanda tangan, expiry, owner, dan mitigasi sementara.
- `.git`, SQL, tests, docs internal, manifest, backup, config, log, dan dotfiles tidak dapat diakses dari HTTP.
- Aplikasi tidak memakai DB root; semua default credential telah dihapus/dirotasi.
- Seluruh mutasi memakai method aman, CSRF, backend authorization, dan validation.
- Session, cookie, header, error, logging, dependency, dan TLS lulus matrix.

### Data dan bisnis

- Seluruh verifier INV-001 sampai INV-017 menghasilkan nol mismatch atau exception yang didokumentasikan dan disetujui.
- Khusus baseline, payment SPP tanpa claim dan cache biaya awal mismatch telah direkonsiliasi melalui proses yang di-preview, di-backup, dan dapat diaudit.
- Test create/edit/delete/reversal, SPP, DU, biaya lain, Komite, tabungan, siswa, dan user lulus termasuk concurrency/failpoint.
- Tidak ada hard delete yang dapat menghilangkan histori keuangan tanpa workflow dan audit yang disetujui.

### Database dan deployment

- Fresh install, seluruh jalur upgrade yang didukung, repeat migration, failed-midway recovery, schema verifier, dan data verifier lulus.
- Backup terakhir tervalidasi dan restore drill lulus.
- Clone/paket bersih dapat dipasang tanpa file rahasia/dependency lokal tersembunyi.
- PDF/Excel berfungsi pada runtime target dan dependency advisory ditriase.

### Laporan dan UI

- Dataset oracle sama pada web/print/PDF/Excel untuk semua laporan yang relevan.
- Export aman dari formula injection, menjaga NIS, dan memenuhi limit resource.
- Role UAT, mobile/desktop, theme, keyboard, accessibility, dan browser target lulus.
- `laporan/rekap_kelas.php` dan alur detailnya tetap tersedia serta lolos screenshot regression terhadap baseline yang disetujui.

### Dokumentasi dan handover

- `PROJECT_CONTEXT`, `PROGRESS`, changelog, SOP, flowchart, MoM, migration manifest, runbook, dan known limitations saling konsisten.
- PDF dokumentasi dirender ulang dan diperiksa visual.
- Client menerima daftar akun/role tanpa password dalam dokumen, prosedur rotasi, backup/restore, support, dan rollback.
- Commit/tag rilis, checksum paket, schema version, hasil test bertanggal, residual risk, UAT, dan keputusan GO ditandatangani.

Jika satu gate Kritis gagal, status otomatis **NO-GO**. Istilah “aman”, “selesai”, dan “siap client” tidak boleh dipakai pada laporan akhir bila gate relevan belum diuji.

## 22. Format update progres audit

Setiap sesi audit menambahkan baris pada register audit dengan format berikut:

```markdown
| Tanggal WIB | Wave | ID | Status | Commit/Environment | Bukti | Hasil | Next action | Owner |
```

Aturan update:

- jangan menimpa bukti lama; tambahkan retest baru;
- pisahkan `tidak diuji`, `gagal`, dan `tidak berlaku`;
- temuan baru mendapat ID baru, bukan memakai ID lama yang kebetulan kosong;
- perubahan severity/status harus mempunyai alasan;
- semua data uji harus dicatat cleanup-nya;
- setiap akhir wave lakukan completion audit: bandingkan scope, checklist, temuan, commit, bukti, dan gate agar tidak ada pekerjaan yang terlewat;
- pembaruan dokumen dan implementasi masuk commit yang sama ketika perubahan kode benar-benar dilakukan.

## 23. Definition of Done audit penuh

Audit penuh baru selesai ketika:

1. seluruh file dan endpoint pada manifest telah diberi status;
2. seluruh route-role-method telah diuji;
3. seluruh sumber data dan rumus keuangan telah disetujui serta direkonsiliasi;
4. seluruh jalur migrasi yang didukung telah dibuktikan pada snapshot;
5. seluruh laporan dan export telah dibandingkan dengan oracle;
6. seluruh kategori keamanan, concurrency, error, dan dependency telah diuji;
7. seluruh halaman utama telah melewati matriks UX/accessibility/browser;
8. semua kandidat dead code telah diputuskan dengan bukti, dengan rekap kelas tetap dilindungi;
9. semua dokumen operasional dan client-facing telah disinkronkan;
10. backup/restore, deployment bersih, rollback, UAT, dan sign-off telah selesai;
11. tidak ada Critical/High tanpa perlakuan yang memenuhi gate;
12. completion audit terakhir tidak menemukan scope, test, bukti, atau keputusan yang hilang.

Dokumen ini harus diperbarui bila arsitektur, aturan bisnis, platform target, atau keputusan client berubah. Perubahan rencana tidak boleh menghapus jejak alasan dan versi sebelumnya.
