# Laporan Eksekutif Audit SistemSPP

Tanggal status: 27 Agustus 2026  
Lingkungan: Windows/XAMPP lokal, PHP 8.3.31, MariaDB 10.4.32  
Database sumber yang dilindungi: `db_spp`  
Database bukti: `db_spp_audit_20260820_090000` dan clone disposable yang selalu diberi guard

## Kesimpulan

Perbaikan integritas pembayaran, migrasi schema, audit append-only, idempotency savings/payment, hardening session/login, laporan, dan regression otomatis telah dikerjakan pada source tree. Bukti teknis lokal kuat: matrix migrasi kanonik 19 file lulus dua pass; harness recovery 19/19 lulus setelah error sintetis pasca-migrasi; verifier schema/data pada snapshot audit lulus; regression disposable 18/18 lulus; HTTP smoke laporan 34/34 lulus; direct route-role HTTP matrix 168/168 lulus; concurrency savings/replay/payment-period/DU/fee/last-admin 25/25 lulus; lint PHP/JavaScript dan `git diff --check` lulus.

Status serah-terima client tetap **NO-GO**. Keputusan ini bukan karena test otomatis gagal, tetapi karena gate yang memang membutuhkan lingkungan dan keputusan eksternal belum mempunyai bukti: UAT seluruh route/role dan accessibility, validasi PDF dan Excel pada client target, concurrency/failpoint, restore target dan RPO/RTO, HTTPS/HSTS, privilege/secret target, retensi/approval koreksi, dan keputusan residual risk. Smoke browser lokal untuk login dan enam halaman read-only pada viewport mobile/desktop, keyboard dasar, serta tema sudah lulus, tetapi tidak menggantikan UAT penuh. Sebelas PDF smoke (17 halaman) sudah lulus parse, render, dan inspeksi visual lokal.

## Perubahan yang tervalidasi

- Child Daftar Ulang dan jurnal tabungan yang dibuat oleh pembayaran memiliki relasi `bayar_id`; histori lama tetap legacy dan tidak ditebak relasinya.
- Pembayaran legacy (`payment_link_version=0`) ditandai dan ditolak untuk edit/hapus dari UI maupun endpoint.
- `audit_event` append-only menyimpan actor/request/reason/before/after dengan redaksi secret. Actor disimpan sebagai snapshot tanpa FK agar histori tidak berubah ketika akun dihapus; trigger menolak update/delete.
- CSRF, session revalidation, session version, rate limiting multi-bucket, request ID, POST-only, dan penolakan hash MD5 lama telah dipasang pada jalur yang diuji.
- Filter NIS riwayat tabungan menggunakan prepared statement; format URL dan perilaku filter dipertahankan.
- Form mutasi tabungan dan pembayaran memakai key idempotency 64-hex yang diklaim atomik per scope; replay yang sama hanya dapat commit sekali.
- Laporan global, export, receipt, dan rekap kelas dilindungi dari perubahan markup pada `laporan/rekap_kelas.php`; parity oracle/endpoint sudah diuji sesuai bukti Wave 5.

## Evidence utama

| Bukti | Hasil |
| --- | --- |
| Backup baseline/restore drill | Baseline SHA-256 `07E4CE1AC126B524070F75B320CB0329FB2E12B8A735A3C866566A8CE92BA1E7`; drill terbaru backup SHA-256 `74249621DB085CC8ACCFDB8319E0445B9C3E35146DDEAAC6FD9ECBDF9D50B7D2`, 22 tabel row-count cocok, schema/data verifier PASS pada restore disposable |
| Data verifier snapshot audit | INV-001–INV-017 PASS; command memilih target dengan probe `DATABASE()` |
| Migration matrix | `MIGRATION_MATRIX_STATUS=PASS`, 19 migrasi, dua pass, cleanup guard; evidence run 2026-08-26 |
| Disposable regression | 18/18 PASS (`REG-FINAL-004`) pada clone `db_spp_audit_20260827_025835_suite_9725`, ringkasan tersanitasi `C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0\regression-20260827_025835-current\sanitized-summary.txt`; cakupan termasuk session idle/absolute-timeout lifecycle, GET method-safety Master Daftar Ulang, focused SQLi boolean/error/encoding/duplicate/array/XSS corpus, array/scalar boundary, POST dengan CSRF valid, body JSON/text, pagination/protected rekap scalar boundary, rollback/replay idempotency, formula prefix/control-character oracle, legacy payment guard, isolasi child DU timestamp identik, lifecycle NIS + arsip/restore siswa, focused SQLi filter NIS, snapshot/cap Biaya Lain, pemeriksaan CSRF sebelum pembuatan tahun ajaran, atomisitas ensure tahun saat aksi gagal, validasi scalar alasan audit, dan seluruh target Biaya Lain |
| Report HTTP/oracle | 34/34 smoke PASS, 0 HTTP 500/fatal, oracle Wave 5 PASS; 11 PDF/17 halaman lulus parse-render-visual lokal; 11 `.xls` lulus parse struktur HTML dan seluruhnya dibuka read-only pada Excel lokal tanpa formula native |
| Route-role HTTP | 168/168 PASS pada clone disposable untuk anonymous, invalid cookie, A/B/K, direct route, method/CSRF utama, logout; 19 tabel domain count/checksum identik |
| Concurrency | 25/25 PASS dengan dua server dan row-lock barrier: withdrawal/replay savings, satu pembayaran periode SPP, publish DU, publish Biaya Lain, dan satu admin tetap ada saat dua admin saling menghapus |
| Clean deploy rehearsal | PASS lokal pada `release-rehearsal-20260827_024610`: package bersih, schema + 19 migrasi, verifier, grant runtime disposable, login/dashboard smoke, filesystem allowlist, HTTP deny, dan Composer checks; target HTTPS/Apache/PDF/client tetap terbuka |
| Browser smoke lokal | `UI-BROWSER-001`: Playwright + Chrome headless viewport 390x844, `scrollWidth=390`, keyboard Tab, dark→light tanpa overflow; screenshot tersanitasi di luar repo |
| Static checks | PHP lint 64 file, Node check, `git diff --check` PASS |

## Gate sebelum GO

1. Pemilik sistem menyetujui `DECISION_LOG.md` (role laporan, koreksi transaksi, periode tertutup, retensi audit, asset eksternal, volume, RPO/RTO).
2. Infra menyiapkan host HTTPS, database least-privilege, secret store, backup terenkripsi, monitoring, dan restore rehearsal.
3. QA/client menjalankan seluruh `UAT_PACKAGE.md`, termasuk screenshot desktop/mobile, keyboard/accessibility, PDF viewer, dan aplikasi spreadsheet.
4. QA menambahkan concurrency/failpoint evidence untuk publish DU/biaya lain, deadlock/retry, report correction, dan setiap write path yang belum diuji.
5. Postflight target menjalankan verifier dengan nama database target eksplisit; `sql/schema.sql` tidak boleh dijalankan pada database berisi data.

Audit requirement-by-requirement ada di [COMPLETION_AUDIT.md](COMPLETION_AUDIT.md). Detail residual ada di [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md), register security di [SECURITY_FINDINGS.md](SECURITY_FINDINGS.md), dependency di [SBOM.md](SBOM.md), dan prosedur operasi di [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md).
