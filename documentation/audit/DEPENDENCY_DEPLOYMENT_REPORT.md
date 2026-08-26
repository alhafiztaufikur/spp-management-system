# Dependency dan Deployment Readiness SistemSPP

Baseline pemeriksaan: 20 Agustus 2026, commit sumber `a446af3fbb89cb0933870443be5aedd85d34eaa3`. Hasil lokal tidak menggantikan smoke test pada host produksi client.

## Ringkasan

| Area | Hasil lokal | Status release |
| --- | --- | --- |
| Dependency PHP | Instalasi dari lockfile berhasil; platform requirement dan audit Composer lulus. | Siap retest target |
| Ekstensi PHP | `mysqli`, `mbstring`, `gd`, `dom`, `fileinfo`, `openssl`, dan `zip` tersedia pada CLI. | Apache belum direload/dibuktikan |
| Database runtime | User aplikasi least-privilege berhasil dipakai pada schema lokal; `spp_app_local` hanya DML pada `db_spp`, dan grant stale akun audit dibersihkan melalui `DBSEC-002`. Pemeriksaan `DBSEC-004` menemukan `spp_app_local` dan `spp_audit_local` lokal berbagi fingerprint credential yang sama. | Credential target belum dibuat/dirotasi; wajib memisahkan secret kedua akun, memperbarui konfigurasi secara terkoordinasi, lalu mengulang inventory grant dan regression |
| Web containment | Deny terhadap source/internal artifact dan header dasar lulus di Apache lokal. | Virtual host/HTTPS target belum diuji |
| Build reproducible | `composer.lock` tersedia dan `composer install --no-dev --prefer-dist --optimize-autoloader` lulus; clean package rehearsal memprovision schema + 19 migrasi, verifier, login/dashboard smoke, dan deny artifact. | Tag/package final dan host client tetap perlu direhearsal |
| PDF | Dompdf terpasang; GD aktif pada CLI. | Render lewat Apache dan inspeksi output final belum lulus |
| Excel | Implementasi memakai HTML table ber-ekstensi `.xls`. | Formula-injection dan open-test aplikasi spreadsheet belum lulus |
| Asset offline | Google Fonts masih dimuat dari internet. | Menunggu DEC-006 |
| Browser/UX | Browser runtime audit tidak tersedia. | NO-GO untuk gate visual sampai UAT |

## Dependency terkunci dan lisensi

`composer validate --strict`, `composer audit --locked --no-dev`, dan `composer check-platform-reqs --no-dev` berhasil pada run ini. Composer tidak melaporkan advisory atau package abandoned saat pemeriksaan.

| Package | Versi lock | Direct | Lisensi yang dilaporkan Composer |
| --- | --- | --- | --- |
| `dompdf/dompdf` | 3.1.6 | Ya | LGPL-2.1 |
| `dompdf/php-font-lib` | 1.0.2 | Tidak | LGPL-2.1-or-later |
| `dompdf/php-svg-lib` | 1.0.2 | Tidak | LGPL-3.0-or-later |
| `masterminds/html5` | 2.10.1 | Tidak | MIT |
| `sabberworm/php-css-parser` | 9.4.0 | Tidak | MIT |
| `thecodingmachine/safe` | 2.5.0 | Tidak | MIT |

Project menyatakan lisensi `proprietary`. Pemilik paket release tetap harus menyertakan notice/license dependency sesuai kewajiban masing-masing lisensi; tabel ini adalah inventaris teknis, bukan nasihat hukum.

Inventaris dependency produksi yang dapat diserahkan sebagai SBOM tersedia di [SBOM.md](SBOM.md). Ia harus diregenerasi/divalidasi terhadap `composer.lock` pada commit/tag release.

## Kontrak build

1. Gunakan source dari commit/tag release yang tercatat, bukan salinan folder kerja acak.
2. Jangan memasukkan `config/app.local.php`, dump, log, evidence audit, cookie jar, atau credential ke paket.
3. Jalankan `composer install --no-dev --prefer-dist --optimize-autoloader` dari lockfile.
4. Jalankan `composer validate --strict`, `composer audit --locked --no-dev`, dan `composer check-platform-reqs --no-dev` pada runner berjaringan.
5. Jalankan seluruh lint, migration matrix, schema/data verifier, regression suite, HTTP deny/role/method matrix, PDF/Excel smoke, dan UAT.
6. Hanya entrypoint/aset publik yang boleh berada di document root. Bila layout repository dipakai langsung, deny server wajib diuji untuk `.git`, dotfiles, `config`, `documentation`, `includes`, `sql`, `tests`, Composer manifest, dump, backup, dan log.

## Konfigurasi minimum target

| Komponen | Nilai/aturan minimum |
| --- | --- |
| PHP | Versi yang kompatibel dengan platform Composer 8.0.30 dan dependency lock; versi target harus ditetapkan melalui DEC-007. |
| Extension | `mysqli`, `mbstring`, `gd`, `dom`, `fileinfo`, `openssl`; `zip` direkomendasikan untuk tooling/export. |
| Timezone | Aplikasi dan database menggunakan Asia/Jakarta; baseline CLI global masih Europe/Berlin sehingga bootstrap aplikasi wajib eksplisit. |
| Session | Strict mode, cookie-only, HttpOnly, SameSite, idle+absolute timeout; `Secure` wajib pada HTTPS. |
| PHP error | `display_errors=Off` pada produksi; detail masuk log terlindungi dan respons hanya correlation ID/pesan generik. |
| Database | User runtime khusus satu schema, password acak kuat, tanpa global grant/GRANT OPTION; account migrasi terpisah. |
| Web server | Directory listing mati, source/internal files 403/404, version banner ditekan, security headers konsisten. |
| TLS | HTTPS end-to-end, certificate renewal, redirect HTTP, dan keputusan HSTS diuji pada host client. |
| Filesystem | Config lokal/log/backup tidak dapat dibaca user web selain yang diperlukan; direktori publik tidak writable kecuali kebutuhan eksplisit. |

## Temuan operasional

### DEPLOY-001 - Apache belum memakai runtime GD yang diverifikasi

`php.ini` lokal telah mengaktifkan GD dan CLI melihat modul tersebut. Apache tidak terpasang sebagai service sehingga restart otomatis tidak berhasil dilakukan pada run audit. Acceptance: setelah restart terkontrol, halaman diagnostik nonpublik/CLI yang memakai konfigurasi Apache dan smoke PDF membuktikan GD aktif; halaman diagnostik lalu dihapus/diblokir.

### DEPLOY-002 - Timezone global tidak sesuai sekolah

PHP CLI melaporkan `Europe/Berlin`. Timestamp pembayaran, audit, expiry session, dan laporan harus memakai Asia/Jakarta secara eksplisit dan diverifikasi bersama timezone database. Jangan hanya mengandalkan konfigurasi workstation developer.

### DEPLOY-003 - Tabel grant MariaDB sempat korup

`mysql.db` lokal gagal saat pembuatan grant least-privilege. File tabel sistem dibackup terlebih dahulu, kemudian `mysqlcheck --repair mysql db` dan post-check berhasil. Ini adalah indikasi kesehatan operasi, bukan bug aplikasi. Server target wajib diperiksa untuk storage error, shutdown tidak bersih, backup tabel sistem, dan monitoring database.

### DEPLOY-004 - Asset font eksternal

Stylesheet dan beberapa halaman memuat Google Fonts. Saat koneksi internet terputus aplikasi akan fallback, tetapi ketersediaan visual dan request data ke pihak ketiga belum disetujui. Tutup melalui DEC-006: self-host font atau dokumentasikan dependency/privasi secara eksplisit.

### DEPLOY-005 - Artefak internal berada satu tree dengan entrypoint publik

`.htaccess` memperbaiki exposure pada Apache lokal, tetapi kontrol yang lebih kuat adalah document root khusus public serta package allowlist. Nginx/IIS/PHP built-in server tidak otomatis membaca `.htaccess`; masing-masing membutuhkan konfigurasi ekuivalen dan test deny.

### DEPLOY-006 - Redirect HTTPS dan HSTS belum aktif pada Apache lokal

Smoke header `TLS-LOCAL-001` menemukan virtual host lokal dapat menjawab HTTPS, tetapi HTTP masih mengembalikan 200 tanpa redirect dan `Strict-Transport-Security` tidak dikirim. Ini adalah gap konfigurasi deployment, bukan perubahan yang dipaksakan ke `.htaccess` aplikasi karena lingkungan development/test masih membutuhkan HTTP.

Acceptance target: redirect HTTP→HTTPS, sertifikat valid, dan HSTS opt-in setelah seluruh hostname/subdomain siap; ulangi smoke pada semua route publik, redirect, error, HTML, JSON, print, dan download.

## Bukti command lokal

```text
composer install --no-dev --prefer-dist --optimize-autoloader    PASS
composer validate --strict                                      PASS
composer audit --locked --no-dev                                PASS (0 advisory dilaporkan)
composer check-platform-reqs --no-dev                           PASS
PHP CLI GD                                                      ON
Apache reload + HTTP PDF                                        NOT TESTED
HTTPS/HSTS/certificate                                          NOT TESTED
clean-package rehearsal                                         PASS (lokal; evidence release-rehearsal-20260827_024610)
```

Status dokumen ini tetap `Siap retest`, bukan `Selesai`, sampai seluruh item `NOT TESTED` mempunyai bukti dari target yang disetujui.
