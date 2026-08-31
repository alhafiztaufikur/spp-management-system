# Register Integrasi Perubahan Teman

Dokumen ini mencatat perbandingan perubahan dari laptop teman terhadap branch keamanan lokal. Integrasi dilakukan selektif agar kontrol keamanan dan kontrak ledger tidak tertimpa.

## Basis perbandingan

| Referensi | Commit |
|---|---|
| Branch keamanan lokal | `a5ac4ffe0d2c4f19c1c51d766cf0e80245de9835` |
| `origin/main` teman | `95bdb9b117564180ccb6c8de225205e7c4369d94` |
| Merge base | `a446af3fbb89cb0933870443be5aedd85d34eaa3` |
| Commit remote yang diperiksa | `5623529`, `95bdb9b` |

`main` lokal tetap dipertahankan sebagai baseline keamanan. Pekerjaan integrasi dilakukan pada branch `safety-branch`; tidak ada force-push atau rewrite history.

## Hasil seleksi awal

Perubahan tampilan berisiko rendah telah diambil secara manual pada `assets/js/app.js`, `master_biaya_lain.php`, `pembayaran/form.php`, `pembayaran/edit.php`, `pembayaran/riwayat_daftar_ulang.php`, dan `siswa/daftar.php`. Hunk yang menghapus bootstrap keamanan, CSRF, idempotency, audit, validasi input, atau proteksi legacy tidak diambil.

Perubahan berikut sengaja ditunda meskipun sebagian tidak menimbulkan konflik tekstual:

- `includes/kelas.php`
- `siswa/export_excel.php`
- `sql/add_modular_global_reports.sql`
- `sql/schema.sql`
- `sql/verify_schema.sql`
- `sql/repair_negative_savings_balances.sql`
- `sql/seed_demo_reporting_dataset.sql`

Alasannya adalah perubahan tersebut menyentuh promosi/pengarsipan siswa, export baru, schema/migrasi, rekonsiliasi saldo, atau seed destruktif.

## Konflik manual yang ditunda

| File | Keputusan | Syarat penerimaan berikutnya |
|---|---|---|
| `assets/css/style.css` | Tunda, gabung manual | Port desain tanpa menghapus CSS security/accessibility; `git diff --check`. |
| `dashboard.php` | Tunda | Validasi query komponen pembayaran dan tabungan terhadap oracle laporan. |
| `includes/reports.php` | Tunda | Pertahankan `transaksi_m.bayar_id IS NULL` untuk setoran manual dan lulus report oracle. |
| `laporan/export_excel.php` | Tunda | Pertahankan scalar/range guard, batas baris, dan `excel_text`. |
| `laporan/export_global.php` | Tunda | Pertahankan validasi input, default aman, dan sanitasi error. |
| `laporan/export_pdf.php` | Tunda | Uji parity data, MIME, dan output PDF pada database disposable. |
| `laporan/index.php` | Tunda | Pertahankan snapshot/tagihan, `potong_spp`, prepared statement, dan filter aman. |
| `laporan/template.php` | Tunda | Uji template, role, default rombel, dan error handling. |
| `master_kelas.php` | Tunda | Keputusan bisnis terpisah untuk promosi, pengarsipan, dan nonaktif rombel. |
| `pembayaran/lihat.php` | Tunda | Pertahankan POST-only, CSRF, idempotency, audit, dan blokir legacy. |
| `tabungan/get_saldo.php` | Tunda | Pertahankan locking dan aturan saldo ledger. |
| `tabungan/keluar.php` | Tunda | Uji pembalikan dan penolakan saldo negatif secara atomik. |
| `tabungan/masuk.php` | Tunda | Pastikan setoran manual tidak memperoleh `bayar_id`. |
| `tabungan/proses.php` | Tunda | Pertahankan CSRF, idempotency, audit, dan transaksi atomik. |
| `tabungan/riwayat.php` | Tunda | Pertahankan prepared statement filter NIS dan verifikasi SQL injection regression. |

## Kontrak yang tidak boleh berubah

- Route produksi memakai `security_bootstrap_session()`, bukan `session_start()` langsung.
- Perubahan pembayaran dan tabungan wajib melalui CSRF, POST-only, idempotency, audit, dan transaksi atomik.
- Pembayaran legacy tetap tidak dapat diedit/dihapus dari aplikasi.
- Laporan tabungan menghitung setoran manual (`transaksi_m.bayar_id IS NULL`) dan seluruh penarikan sesuai kontrak lokal.
- Schema penuh tidak dijalankan pada database berisi data; migrasi diuji dua kali pada database disposable.

## Kebijakan SQL

`seed_demo_reporting_dataset.sql` tetap dev-only dan tidak masuk manifest produksi. `repair_negative_savings_balances.sql` tidak boleh dijalankan apa adanya; jika diperlukan harus diubah menjadi rekonsiliasi eksplisit dengan backup, target probe, operator/alasan, audit event, marker idempotensi, dan rollback penuh.

## Status

Register ini diperbarui setiap kali satu kelompok konflik ditinjau. Perubahan yang ditunda tidak dianggap aman hanya karena Git dapat melakukan auto-merge.
