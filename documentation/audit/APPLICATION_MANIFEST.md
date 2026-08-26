# Application Manifest SistemSPP

Dokumen ini adalah inventaris teknis untuk audit akhir SistemSPP. Baseline diperiksa pada 20 Agustus 2026 dari branch `main`, commit `a446af3fbb89`, dengan worktree yang sudah memuat pekerjaan dokumentasi audit yang belum di-commit. Dokumen ini bersifat deskriptif: ia tidak menyatakan suatu kontrol aman sebelum bukti pengujian tersedia.

## 1. Batas sistem

```text
Browser petugas
  -> Apache 2.4 / PHP 8.3 / PHP session
      -> aplikasi PHP procedural di web root
          -> MySQL/MariaDB db_spp
          -> filesystem lokal (asset, Composer autoloader, logo)
          -> renderer Dompdf untuk PDF
  -> Google Fonts (resource eksternal pada halaman UI)
  -> Excel/PDF/print viewer pada perangkat petugas
```

Tidak ditemukan API publik terpisah, worker, queue, upload handler, command runner, atau integrasi pembayaran eksternal. Endpoint JSON yang ada hanya `tabungan/get_saldo.php`. Seluruh modul utama berbagi koneksi database dan session PHP yang sama.

## 2. Aset dan klasifikasi data

| Aset | Contoh | Klasifikasi | Dampak utama bila bocor/berubah |
|---|---|---|---|
| Identitas siswa | NIS, NIS Diknas, nama, kelas/rombel, status aktif | Data pribadi | Privasi siswa dan reputasi sekolah |
| Kewajiban siswa | SPP, Komite, Daftar Ulang, biaya awal/opsional/lain | Keuangan rahasia | Salah tagih, sengketa, manipulasi laporan |
| Transaksi pembayaran | Header `bayar`, klaim periode, detail DU/biaya lain | Keuangan kritis | Kehilangan uang, transaksi ganda, jejak koreksi hilang |
| Tabungan siswa | Saldo, setoran, penarikan | Keuangan kritis | Saldo negatif/salah, kehilangan dana |
| Identitas petugas | Username, nama, role, password hash, session | Kredensial/otorisasi | Pengambilalihan akun dan eskalasi hak akses |
| Audit trail | Audit siswa, Daftar Ulang, biaya lain | Bukti forensik | Tidak dapat membuktikan siapa mengubah data |
| Source dan histori Git | PHP, SQL, dokumentasi, konfigurasi | Internal | Mempermudah eksploitasi dan membocorkan secret historis |
| Hasil laporan | HTML, print, PDF, Excel | Data pribadi dan keuangan | Eksfiltrasi massal serta formula injection |

## 3. Inventaris kode

Baseline memiliki 45 file PHP: 29 route aplikasi yang dimaksudkan untuk browser, 1 bootstrap koneksi yang juga dapat diroute langsung, 7 helper di `includes/`, dan 8 script pengujian. Terdapat 19 file SQL dan 8 asset statis yang terinventaris oleh Git.

### Route aplikasi

| Area | Route |
|---|---|
| Entry dan autentikasi | `index.php`, `login.php`, `logout.php` |
| Dashboard dan akun | `dashboard.php`, `role_management.php` |
| Master | `master_kelas.php`, `master_biaya_lain.php`, `master_daftar_ulang.php`, `siswa/daftar.php` |
| Pembayaran | `pembayaran/form.php`, `pembayaran/lihat.php`, `pembayaran/edit.php`, `pembayaran/proses.php`, `pembayaran/riwayat_daftar_ulang.php` |
| Tabungan | `tabungan/masuk.php`, `tabungan/keluar.php`, `tabungan/proses.php`, `tabungan/riwayat.php`, `tabungan/get_saldo.php` |
| Laporan | `laporan/index.php`, `laporan/global.php`, `laporan/template.php`, `laporan/rekap_kelas.php`, `laporan/detail_siswa.php`, `laporan/cetak_struk.php`, `laporan/cetak_struk_tahunan.php`, `laporan/export_excel.php`, `laporan/export_pdf.php`, `laporan/export_global.php` |

Kontrak method, input, role, dan efek setiap route dicatat pada [ROUTE_ROLE_MATRIX.md](ROUTE_ROLE_MATRIX.md).

### Bootstrap dan helper internal

| File | Tanggung jawab | Catatan audit |
|---|---|---|
| `koneksi.php` | Credential DB, timezone, koneksi MySQL | Berada di web root; memakai `root` tanpa password pada baris 6-13 dan menampilkan error koneksi pada baris 15-19 |
| `includes/auth.php` | `requireRole()`, `isRole()`, `hasRole()` | Mengandalkan role dalam session; tidak memeriksa ulang akun ke DB |
| `includes/pagination.php` | Normalisasi page size dan link pagination | Input halaman berasal dari query string |
| `includes/kelas.php` | Lookup/snapshot kelas dan tarif | Query dinamis berasal dari flag/kolom internal; tetap perlu static-taint regression |
| `includes/daftar_ulang.php` | Tahun ajaran, penerbitan dan saldo tagihan DU | Mengandung transaksi dan audit helper yang dipanggil route admin/pembayaran |
| `includes/biaya_lain.php` | Tagihan serta audit biaya lain | Mengandung query dan penulisan audit internal |
| `includes/reports.php` | Registry, filter, query, dan model tujuh template laporan | Membangun hasil penuh sebelum pagination; mempunyai query dinamis berbasis whitelist/cast |
| `includes/sidebar.php` | Navigasi berbasis role | Hanya kontrol presentasi; otorisasi wajib tetap dilakukan route |

File helper dan `koneksi.php` tidak dirancang sebagai halaman, tetapi masih berada di document root. Audit deployment wajib memastikan file internal tidak menjadi surface publik yang tidak diperlukan.

### Script pengujian

`tests/` berisi:

- `academic_year_billing_test.php`
- `class_snapshot_test.php`
- `legacy_compatibility_test.php`
- `modular_reports_test.php`
- `payment_process_integration_test.php`
- `registration_history_pagination_test.php`
- `spp_installment_test.php`
- `student_optional_fees_test.php`

Script tersebut tidak mempunyai guard CLI. Beberapa memulai transaksi dan menulis ke database, misalnya `tests/class_snapshot_test.php:6-10` dan `tests/payment_process_integration_test.php:63-83`. Script hanya boleh dijalankan melalui CLI pada database uji terisolasi; tidak boleh dapat dieksekusi dari web.

## 4. Model data

Schema baseline mendefinisikan 19 tabel:

| Domain | Tabel |
|---|---|
| Identitas dan akses | `admin` |
| Siswa/kelas | `master_kelas`, `siswa`, `siswa_tahun_ajaran` |
| Pembayaran | `bayar`, `bayar_spp_periode`, `bayar_du`, `bayar_biaya_lain` |
| Tagihan | `tahun_ajaran`, `Daftar_ulang`, `tagihan_daftar_ulang`, `master_biaya_lain`, `tagihan_biaya_lain` |
| Tabungan | `tabungan`, `transaksi_m`, `transaksi_k` |
| Audit | `siswa_audit_log`, `daftar_ulang_audit_log`, `tagihan_biaya_lain_audit_log` |

Relasi keuangan penting yang harus diverifikasi:

- `bayar` adalah header transaksi dan `payment_link_version=1` menandai relasi aman.
- `bayar_spp_periode`, `bayar_du`, dan `bayar_biaya_lain` adalah detail milik pembayaran.
- Setoran/penarikan tabungan manual berada di `transaksi_m`/`transaksi_k`; saldo materialized berada di `tabungan`.
- Histori pembayaran legacy tidak boleh ditebak relasinya.
- Audit trail sudah ada untuk siswa dan sebagian master/tagihan, tetapi belum lengkap untuk lifecycle pembayaran, tabungan, dan akun.

## 5. Role dan trust model saat ini

| Role | Kemampuan utama saat ini |
|---|---|
| `admin` | Seluruh master, siswa, akun, pembayaran, tabungan, laporan |
| `bendahara` | Dashboard, riwayat tabungan, laporan umum/global, rekap kelas, ekspor dan struk |
| `kasir` | Input/edit/hapus pembayaran, setoran/penarikan tabungan, riwayat terkait, struk, seluruh Laporan Global |

`requireRole()` menerima role dari `$_SESSION['admin_role']` (`includes/auth.php:21-29`). Session tidak diikat kembali ke record `admin` pada setiap request, sehingga penghapusan akun, reset password, atau perubahan role belum otomatis mencabut session lama. Akses kasir ke seluruh Laporan Global adalah perilaku eksplisit (`includes/sidebar.php:65-67`) dan harus dikonfirmasi sebagai keputusan bisnis client.

Tidak ada tenant, pemilik record, akun siswa, atau pembatasan per loket. Karena itu, object-level authorization saat ini sama dengan role-level authorization: setiap admin/kasir yang berhak mengelola pembayaran dapat menunjuk ID pembayaran mana pun.

## 6. Surface file, export, dan dependensi

- Tidak ditemukan `$_FILES`, `move_uploaded_file()`, dynamic include, shell execution, atau path input pengguna.
- `laporan/export_global.php:10-14` hanya membaca logo statis setelah `realpath()`.
- Dompdf mematikan remote resource pada `laporan/export_global.php:26`; `laporan/export_pdf.php:612` juga menetapkan chroot.
- `vendor/` tidak ada pada baseline worktree, sementara endpoint PDF memerlukan `vendor/autoload.php`.
- `composer check-platform-reqs` menemukan `ext-gd` belum tersedia. `composer audit --locked` belum dapat diperbarui karena Packagist tidak dapat dijangkau pada sesi audit.
- Halaman UI memuat Google Fonts dari domain eksternal; kebijakan CSP, privasi, dan ketersediaannya harus diputuskan saat hardening.
- Output Excel adalah HTML ber-content-type `.xls`, bukan workbook native. Sel teks bebas perlu uji formula injection.

## 7. Observasi deployment lokal

Pemeriksaan aman via HTTP pada 20 Agustus 2026 menghasilkan:

- `/.git/HEAD`, `/.git/config`, `/composer.json`, dan `/sql/schema.sql`: `200 OK`.
- `/tests/` dan `/sql/`: directory listing aktif.
- Cookie sesi hanya memiliki `path=/`; tidak terlihat `HttpOnly`, `Secure`, atau `SameSite`.
- Respons memuat versi Apache/OpenSSL/PHP dan `X-Powered-By`.
- Tidak terlihat CSP, `X-Content-Type-Options`, frame protection, `Referrer-Policy`, atau `Permissions-Policy`.

Pemeriksaan tidak mengeksekusi file apa pun di `tests/*.php` melalui web.

## 8. Artefak yang harus dipertahankan

`laporan/rekap_kelas.php` adalah referensi tampilan yang sengaja dipertahankan. Audit boleh menguji role, input, query, output encoding, performa, dan integrasinya, tetapi tidak boleh menjadikannya kandidat hapus atau refactor tanpa instruksi baru dari pemilik proyek.

## 9. Definisi manifest selesai

Manifest dianggap tervalidasi hanya apabila:

1. daftar route dibandingkan ulang dengan seluruh file PHP dan konfigurasi web aktual;
2. matriks role disetujui pemilik bisnis, khususnya akses kasir terhadap Laporan Global;
3. semua data sensitif, mutasi, export, dependency, dan trust boundary mempunyai pemilik serta test case;
4. tidak ada route/helper/test baru tanpa pembaruan manifest; dan
5. bukti deployment client menggantikan observasi lokal sebelum serah-terima.

## Status setelah remediation lokal (26 Agustus 2026)

Bagian inventaris di atas adalah baseline dan sengaja tidak ditulis ulang. Source terbaru menambahkan `audit_event`, `mutation_request`, `includes/security.php`, rate-limit/session controls, dan `.htaccess` deny; `tests/` kini dijalankan melalui guard disposable. Regression terbaru 18/18 pada `regression-20260827_025835-current` (`REG-FINAL-004`, termasuk body JSON/text negatif pada endpoint mutasi, snapshot count bisnis sebelum/sesudah, pagination/protected rekap scalar boundary, rollback claim idempotency saat validation failure, formula/control-character oracle, pemeriksaan CSRF sebelum pembuatan tahun ajaran, atomisitas ensure tahun saat aksi gagal, dan validasi scalar alasan audit) dan HTTP deny/security smoke lulus pada clone audit. Snapshot audit kini memiliki 22 tabel setelah migrasi security, audit, dan idempotency; backup/restore drill disposable juga memverifikasi seluruh 22 tabel. Namun daftar baseline tetap bukan bukti deployment client: virtual host/HTTPS, credential target, browser, dan route-wide DAST masih harus dijalankan.

Inventaris tracked terbaru: 57 file PHP (29 route browser, 1 bootstrap koneksi, 1 template config, 10 helper `includes/`, 13 test domain, 3 helper test), 24 file SQL, dan 8 asset di bawah `assets/`. `config/app.local.php` sengaja ignored dan tidak termasuk paket/manifest. Tambahan domain utama sejak baseline adalah `audit_event`, `login_rate_limit`, `includes/audit.php`, `includes/security.php`, `includes/login_rate_limit.php`, tiga test audit/security baru, dan tiga helper test. Daftar 29 route produksi tetap lengkap pada tabel route di atas; `ROUTE_ROLE_MATRIX.md` memuat status remediasi yang lebih baru.

Inventory worktree audit pada 27 Agustus 2026 sempat memiliki artefak untracked: 6 file PHP (`includes/idempotency.php`, `tests/optional_fee_publish_integration_test.php`, `tests/security_input_corpus_test.php`, `tests/session_lifecycle_test.php`, `tests/student_lifecycle_integration_test.php`, `tests/tabungan_riwayat_sqli_test.php`) dan 1 migrasi SQL (`sql/add_mutation_idempotency.sql`). Seluruhnya kini masuk commit `dbef925`; source saat ini berisi 63 file PHP yang terlihat dan 25 file SQL. `config/app.local.php` tetap ignored dan tidak boleh masuk package; inventory dan checksum tetap harus diregenerasi pada tag release.
