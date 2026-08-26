# Keterbatasan dan Residual Risk SistemSPP

Dokumen ini membedakan fakta yang sudah diuji dari hal yang belum dapat dibuktikan pada lingkungan audit lokal. Item di bawah tidak boleh dihapus dari release notes tanpa bukti baru dan owner.

## Belum diuji atau belum diputuskan

| Area | Dampak | Acceptance berikutnya | Referensi |
| --- | --- | --- | --- |
| Browser dan accessibility | Smoke login + enam halaman read-only pada viewport 390px/1440px, keyboard dasar, dan tema sudah lulus, tetapi overflow/focus/contrast/console/responsive/accessibility tree seluruh route dan role belum memiliki evidence; runtime Browser resmi tetap kosong pada `UI-BROWSER-004` | Jalankan browser UAT pada semua role dan viewport; simpan artefak tanpa PII | `FRONTEND_ACCESSIBILITY.md`, `AUDIT_EXECUTION_LOG.md:UI-BROWSER-001/UI-BROWSER-002/UI-BROWSER-004`, `UAT_PACKAGE.md` |
| Viewer PDF dan Excel client | Sebelas PDF smoke (17 halaman) lulus parse, render, dan inspeksi visual lokal; seluruh 11 `.xls` normal berhasil dibuka read-only pada Excel lokal tanpa formula native, tetapi viewer/print target, tipe sel, dan payload formula belum diuji menyeluruh. Excel masih HTML `.xls` | Ulangi PDF pada viewer/print target, uji seluruh workbook dengan payload formula-injection sebagai teks inert, leading zero, tanggal, nominal, dan encoding | `REPORT_EXPORT_PARITY.md`, `AUDIT_EXECUTION_LOG.md:REP-XLS-003/004`, `DEPENDENCY_DEPLOYMENT_REPORT.md` |
| Concurrency dan failpoint | Dua-server barrier 25/25 lulus untuk withdrawal/replay tabungan, payment periode SPP yang sama, publish DU, publish Biaya Lain, dan last-admin; rollback validation failure pada payment juga lulus, tetapi deadlock/retry, report correction, dan failpoint tiap write belum terbukti | Perluas runner dengan fault injection DB di tengah write, rollback/state oracle, dan simulasi deadlock/retry | `TEST_COVERAGE_MATRIX.md` |
| Upgrade histori | Matrix 19 migrasi membuktikan fresh/repeat; harness failure/recovery 19/19 membuktikan rerun setelah error sintetis pasca-migrasi, bukan semua generasi schema client | Fixture anonymized per generasi, failpoint di tengah statement, dan recovery failed-midway pada snapshot target | `MIGRATION_MANIFEST.md` |
| Target deployment | HTTPS/HSTS, Apache GD, ACL jaringan, credential rotation, backup terenkripsi, monitoring, dan log central belum diverifikasi; smoke lokal (`TLS-LOCAL-001`) menemukan HTTP belum redirect ke HTTPS dan HSTS belum aktif. Pemeriksaan `DBSEC-004` juga menemukan dua akun runtime lokal berbagi secret; target tidak boleh menyalin pola ini. Restore dan clean-deploy drill baru terbukti pada clone lokal. ACL evidence root lokal sudah dibatasi melalui `OPS-003`, tetapi belum membuktikan target client | Infra checklist target + buat secret DB terpisah, rotasi terkoordinasi, restore drill dengan RPO/RTO disetujui | `DEPENDENCY_DEPLOYMENT_REPORT.md`, `OPERATIONS_RUNBOOK.md`, `AUDIT_EXECUTION_LOG.md:39,59,124,139` |
| Kebijakan bisnis | Scope kasir laporan, koreksi pembayaran, periode tertutup, asset eksternal, retensi audit, volume, dan RPO/RTO menunggu owner | Isi dan tanda tangani `DECISION_LOG.md` | `DECISION_LOG.md` |
| CSP | Kebijakan masih staged dengan `unsafe-inline` dan Google Fonts | Self-host/nonce migration dan uji browser tanpa inline policy | `SECURITY_FINDINGS.md` (DEC-006) |
| Formula injection | Sanitasi helper tersedia, tetapi opening nyata pada spreadsheet client belum dibuktikan untuk semua export | Fixture `=`, `+`, `-`, `@`, tab, CR di semua kolom bebas; inspeksi file | `SECURITY_FINDINGS.md` FULLSEC-014 |
| Resource/performance | Benchmark bounded lokal sudah mencatat 2–4 SELECT dan 1.987–3.567 ms/report pada tujuh template, tetapi belum ada volume client, p95/p99, rows examined, RAM/lock budget, ukuran export, atau acceptance budget yang disetujui | Data generator anonim worst-case, load/concurrency test, EXPLAIN, rows examined/temp/filesort, response size, dan budget owner | `SECURITY_FINDINGS.md` FULLSEC-016, `AUDIT_EXECUTION_LOG.md:REP-PERF-001` |

Catatan performa terbaru (`REP-PERF-002`): benchmark read-only diulang 10 kali untuk tujuh template (70 sampel), dengan p95 wall time 1,573–3,094 ms per template dan 2–4 SELECT. Angka ini tetap hanya baseline dataset kecil; p99, volume client, rows examined, RAM/lock budget, ukuran export, dan SLA resmi belum tersedia.

## Batas data legacy

Pembayaran lama dengan `payment_link_version=0`, child tanpa `bayar_id`, operator teks ambigu, dan histori yang tidak punya konteks cukup tidak dicocokkan otomatis. Rekonsiliasi manual harus menghasilkan keputusan, bukti, dan audit reason sebelum perubahan. Tidak ada tombol force-edit yang melewati guard aplikasi.

## Cara menutup item

Tambahkan command, tanggal WIB, environment, expected/actual, exit code, artefak, cleanup, dan owner ke `AUDIT_EXECUTION_LOG.md`; perbarui matriks coverage dan changelog pada perubahan yang sama. Jangan menulis credential, cookie, token, dump, atau PII ke repository.
