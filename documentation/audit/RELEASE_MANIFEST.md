# Manifest Release SistemSPP

Status: **NO-GO pending client gates**  
Tanggal dibuat: 27 Agustus 2026  
Source audit terkini: commit `dbef925` (`Audit dan hardening keamanan SistemSPP`); tag produksi belum dibuat

## Bentuk artefak release

Release harus dipisah menjadi dua artefak yang tidak boleh dicampur:

1. **Paket runtime publik** — hanya entrypoint PHP, asset, Composer autoloader/dependency, dan konfigurasi contoh yang diperlukan. Paket ini tidak memuat SQL, tests, dokumentasi internal, `.git`, dump, atau evidence.
2. **Handover bundle terbatas** — source/migrasi dan seluruh dokumentasi audit untuk admin teknis/client, disimpan di lokasi nonpublik dengan ACL/retensi terpisah. Bundle ini bukan document root dan tidak boleh disajikan Apache.

## Isi handover bundle

- Source PHP/CSS/JS dan SQL dari worktree yang telah melalui lint/static checks.
- Enam test/helper PHP dan satu migrasi SQL yang sebelumnya untracked sudah masuk commit `dbef925`; `config/app.local.php` tetap ignored dan dilarang masuk package. Allowlist/checksum masih wajib diregenerasi pada tag release.
- `sql/schema.sql` untuk instalasi baru saja; upgrade memakai urutan 19 migrasi pada `MIGRATION_MANIFEST.md`.
- `documentation/` termasuk evidence summary, runbook, UAT, decision log, dan known limitations; diserahkan sebagai bundle terbatas, bukan public package.
- `documentation/audit/SBOM.md` sebagai inventaris dependency PHP produksi dari lockfile.
- Composer dependency berdasarkan `composer.lock`; `vendor/` dibangun oleh pipeline, bukan disalin dari evidence audit.

Paket runtime publik hasil rehearsal mengecualikan `sql/`, `tests/`, `documentation/`, `.git/`, dan konfigurasi lokal; bukti `DEP-REHEARSAL-008` memastikan artefak tersebut tidak masuk document root melalui pemeriksaan filesystem dan HTTP.

## Jangan ikutkan

`db_spp-baseline.sql`, dump database audit, cookie jar, log request, secret, credential, file `config/app.local.php`, evidence runtime di luar repository, dan database disposable. Evidence root berada di `C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0` dan memiliki retensi terpisah.

## Bukti sebelum packaging

- PHP lint 64 file: PASS.
- `node --check assets/js/app.js`: PASS.
- `git diff --check`: PASS; warning line ending Windows bukan error.
- Migration matrix 19 file, fresh + dua pass: PASS; database dibuat harness telah di-drop.
- Disposable regression: 18/18 PASS (`REG-FINAL-004`) pada clone `db_spp_audit_20260827_025835_suite_9725`; ringkasan tersanitasi berada di `regression-20260827_025835-current/sanitized-summary.txt`. Run mencakup session idle/absolute-timeout lifecycle, focused SQLi boolean/error/encoding/duplicate/array/XSS corpus pada route GET/export, array/scalar boundary, POST dengan CSRF, body JSON/text, pagination/protected rekap, idempotency rollback/replay, formula/control-character oracle, legacy payment guard, isolasi child DU timestamp identik, lifecycle siswa, snapshot/cap Biaya Lain, pemeriksaan CSRF sebelum pembuatan tahun ajaran, atomisitas ensure tahun, validasi scalar alasan audit, dan seluruh target publish Biaya Lain.
- Clean deploy rehearsal terbaru: `release-rehearsal-20260827_024610` (`DEP-REHEARSAL-008`) PASS pada schema kosong + 19 migrasi, verifier, privilege runtime, auth smoke, Composer, filesystem allowlist, dan deny artefak internal; target host/HTTPS/PDF/UAT tetap terbuka.
- Concurrency matrix: 25/25 PASS termasuk withdrawal/replay tabungan, race pembayaran periode SPP, publish DU, publish Biaya Lain, dan last-admin; clone di-drop setelah evidence.
- Report HTTP smoke: 34/34 PASS; PDF artefak lulus parser/render lokal, dan seluruh 11 `.xls` dibuka read-only pada Excel lokal tanpa formula native; viewer/print/PDF dan Excel client target belum diuji.
- Snapshot audit schema/data verifier: PASS dengan target database diprobe eksplisit.
- Backup/restore drill lokal: PASS; backup logical direstore ke schema audit disposable berbeda, 22 tabel row-count cocok, schema/data verifier PASS, lalu schema restore di-drop. Ini belum menggantikan restore target terenkripsi dan RPO/RTO.
- Evidence root audit berada di luar document root dan ACL lokalnya telah di-hardening (`OPS-003`): inheritance umum dihentikan, hanya owner audit/Administrators/SYSTEM yang memiliki akses. Dump, cookie, dan log historis tetap wajib mengikuti retensi/enkripsi target.
- Release rehearsal lokal: PASS; package tanpa artefak internal diprovision ke schema kosong + 19 migrasi, verifier, grant runtime disposable, login/dashboard smoke, filesystem allowlist, HTTP deny, dan Composer checks lulus pada `release-rehearsal-20260827_024610`. Ini belum menggantikan host HTTPS/client.

## Release gate wajib

Paket tidak boleh diberi tag produksi sebelum `EXECUTIVE_REPORT.md` berubah dari NO-GO berdasarkan bukti browser/UAT, PDF/Excel client, target HTTPS/DB/backup/restore, concurrency/failpoint, dan keputusan owner. Pada deployment, backup harus dibuat dan diuji restore terlebih dahulu; jangan pernah menjalankan `sql/schema.sql` pada database berisi data.
