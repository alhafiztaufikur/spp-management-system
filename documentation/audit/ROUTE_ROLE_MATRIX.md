# Route, Method, dan Role Matrix SistemSPP

Baseline: 20 Agustus 2026, branch `main`, commit `a446af3fbb89`. Bagian 1–6 mempertahankan perilaku baseline untuk bukti historis; appendix status 26 Agustus di bawah adalah koreksi otoritatif setelah remediation. `A` = admin, `B` = bendahara, `K` = kasir, `Publik` = tidak memerlukan session.

## 1. Entry, autentikasi, dashboard, dan akun

| Route | Method yang dipakai / ditegakkan | Akses saat ini | Input utama | Efek state | CSRF saat ini | Bukti |
|---|---|---|---|---|---|---|
| `/index.php` | `GET`; method tidak dibatasi | Publik | Tidak ada | Tidak ada; redirect berdasarkan `admin_id` | N/A | `index.php:5-10` |
| `/login.php` | `GET` render; `POST` autentikasi | Publik; session aktif diarahkan ke dashboard | `username`, `password`; `remember` tampil tetapi tidak diproses | Membuat session; dapat mengganti hash MD5 menjadi `PASSWORD_DEFAULT` | Tidak ada login-CSRF token | `login.php:5-19`, `login.php:42-64`, `login.php:173-208` |
| `/logout.php` | Method apa pun menghancurkan session; UI memakai `GET` | Publik/session apa pun | Tidak ada | `session_destroy()` | Tidak ada | `logout.php:5-8`, `includes/sidebar.php:158` |
| `/dashboard.php` | `GET`; method tidak dibatasi | A, B; K redirect | Tidak ada | Tidak ada | N/A | `dashboard.php:5-13` |
| `/role_management.php` | `GET` list/form; `POST` mutasi | A | POST `aksi`; tambah: `nama`, `username`, `role`, `password`, `password_confirmation`; reset: `account_id`, `new_password`, konfirmasi; hapus: `account_id` | Tambah akun, reset password, hapus akun | Ada, session token + `hash_equals()` | `role_management.php:13-19`, `role_management.php:33-47`, `role_management.php:86-125` |

Acceptance keamanan:

Kontrak source terbaru untuk empat route pembayaran di atas menambahkan hidden `idempotency_key` 64-hex pada form input/edit/delete. Handler hanya menerima POST, memverifikasi CSRF `payment`, lalu mengklaim scope `payment` di `mutation_request` sebelum mutasi. Snapshot baseline pada baris tabel yang masih menyebut form tanpa token dipertahankan sebagai histori audit; bukti runtime terbaru adalah payment integration + replay disposable PASS.

- Login mempunyai rate limit, pencatatan gagal-login, session regeneration, dan kebijakan kredensial default; respons gagal tidak membedakan username valid/tidak.
- Logout hanya menerima POST dengan CSRF dan menghapus cookie serta data session server.
- Reset password, perubahan role, penonaktifan, atau penghapusan akun mencabut seluruh session akun tersebut.
- Pemeriksaan “admin terakhir” atomik dan tahan request konkuren.

## 2. Data master dan siswa

| Route | Method yang dipakai / ditegakkan | Role | Input utama | Efek state | CSRF saat ini | Bukti |
|---|---|---|---|---|---|---|
| `/master_kelas.php` | `GET` list/edit; `POST` mutasi | A | GET `edit`; POST `aksi`, `id`, `tingkat`, `kode_rombel` | Tambah/edit/toggle rombel | Ada | `master_kelas.php:6-24`, `master_kelas.php:74` |
| `/master_biaya_lain.php` | `GET` list/edit; `POST` mutasi | A | GET `edit`; POST `aksi`; master: `id`, `nama`, `nominal`, `is_active`; penerbitan: `master_id`, `target`, `tingkat`, `master_kelas_id`, `no_induk[]` | CRUD/toggle master; menerbitkan tagihan ke semua/tingkat/rombel/siswa | Ada | `master_biaya_lain.php:7-34`, `master_biaya_lain.php:45-53`, `master_biaya_lain.php:97-176` |
| `/master_daftar_ulang.php` | `GET` list; `POST` mutasi | A | GET/POST `tahun`/`tahun_ajaran`; POST `aksi`, `jumlah[1..6]` | Simpan tarif, terbitkan tagihan, tutup tahun ajaran | Ada | `master_daftar_ulang.php:28-50`, `master_daftar_ulang.php:93-109` |
| `/siswa/daftar.php` | `GET` list/edit; `POST` mutasi | A | GET `edit`, `q`, `kelas`, `status`, `page`, `per_page`; POST `aksi`, `id`, `no_induk`, `nama`, `master_kelas_id`, `advanced_enabled`, NIS Diknas, tarif/potongan/saldo-awal | Tambah/edit/arsip/pulihkan siswa serta audit siswa | Ada | `siswa/daftar.php:9-13`, `siswa/daftar.php:125-170`, `siswa/daftar.php:326-371` |

Acceptance keamanan:

- Semua aksi hanya menerima POST, token salah/cross-session/expired ditolak tanpa mutasi, dan ID tidak valid tidak membocorkan detail internal.
- Uji mass-assignment memastikan field yang tidak muncul/disabled di UI tidak dapat diubah lewat request buatan.
- Mutasi tarif/tagihan besar diuji untuk race, rollback, overflow, dan jejak audit before/after.
- Nama/NIS/label diuji stored XSS serta formula injection pada semua ekspor.

## 3. Pembayaran

| Route | Method yang dipakai / ditegakkan | Role | Input utama | Efek state | CSRF saat ini | Bukti |
|---|---|---|---|---|---|---|
| `/pembayaran/form.php` | `GET` render; method tidak dibatasi | A, K | Tidak ada input server utama | Tidak ada | Form tidak mempunyai token | `pembayaran/form.php:5-11`, `pembayaran/form.php:176` |
| `/pembayaran/lihat.php` | `GET` | A, K | `search`, `bulan`, `tahun`, `page`, `per_page` | Tidak ada | N/A | `pembayaran/lihat.php:5-10`, `pembayaran/lihat.php:54-60` |
| `/pembayaran/edit.php` | `GET` | A, K | `id` | Tidak ada; transaksi legacy ditolak | Form update tidak mempunyai token | `pembayaran/edit.php:5-25`, `pembayaran/edit.php:248` |
| `/pembayaran/proses.php` — input | Dispatcher membaca `aksi` dari POST atau GET; data input dari POST | A, K | `aksi=input`, `no_induk`, `bulan_bayar`, `tahun_bayar`, `sistem_pembayaran`, `payment_plan`, seluruh `uang_*`, `uang_du`, `potongan_spp`, `catatan`, konteks DU, array detail/tagihan/nominal/keterangan biaya lain; `tabungan_wajib` legacy ditolak | Menulis header pembayaran, klaim SPP, DU, biaya lain, dan total terbayar siswa dalam transaksi DB | Tidak ada | `pembayaran/proses.php:5-13`, `pembayaran/proses.php:617-670`, `pembayaran/proses.php:780-801` |
| `/pembayaran/proses.php` — update | Dispatcher membaca `aksi`; `id` dan data dari POST | A, K | `aksi=update`, `id`, `tanggal_bayar`, dan field pembayaran yang sama dengan input | Mengubah transaksi serta detail/klaim terkait; menimpa `user_id` dengan operator edit | Tidak ada | `pembayaran/proses.php:826-887`, `pembayaran/proses.php:947-1014` |
| `/pembayaran/proses.php` — hapus | `GET ?aksi=hapus&id=...` | A, K | `aksi`, `id` | Menghapus header dan child melalui FK/cascade/sinkronisasi | Tidak ada; mutasi melalui GET | `pembayaran/lihat.php:282-284`, `pembayaran/proses.php:1035-1083` |
| `/pembayaran/riwayat_daftar_ulang.php` | `GET` | A, K | `q`, `kelas`, `tahun_ajaran`, `status`, `page`, `per_page` | Tidak ada | N/A | `pembayaran/riwayat_daftar_ulang.php:2-6`, `pembayaran/riwayat_daftar_ulang.php:32-38` |

Acceptance keamanan:

- Dispatcher hanya menerima POST untuk input/update/delete, memvalidasi action secara eksplisit, dan mengembalikan `405` untuk method salah.
- Seluruh form dan handler memakai token CSRF yang terikat session; GET/prefetch/crawler tidak dapat menghapus pembayaran.
- Uji direct ID memastikan hanya role yang disetujui dapat membaca/mengubah setiap transaksi dan legacy tetap immutable.
- Audit append-only mencatat pembuat asli, pengubah/penghapus, before/after, alasan koreksi, waktu, dan correlation ID; edit tidak menimpa identitas pembuat.

## 4. Tabungan

| Route | Method yang dipakai / ditegakkan | Role | Input utama | Efek state | CSRF saat ini | Bukti |
|---|---|---|---|---|---|---|
| `/tabungan/masuk.php` | `GET` form | A, K | GET `nis` untuk prefill; hidden CSRF + `idempotency_key` 64-hex | Tidak ada | Token `savings` pada form | `tabungan/masuk.php:5-15`, `tabungan/masuk.php:66-71` |
| `/tabungan/keluar.php` | `GET` form | A, K | GET `nis` untuk prefill; hidden CSRF + `idempotency_key` 64-hex | Tidak ada | Token `savings` pada form | `tabungan/keluar.php:5-15`, `tabungan/keluar.php:66-71` |
| `/tabungan/proses.php` | Hanya `POST`; selain itu redirect | A, K | `aksi=masuk|keluar`, `no_induk`, `tanggal`, `nominal`, `keterangan`, `idempotency_key` | Klaim key atomik; mengunci siswa/saldo; upsert saldo; menulis transaksi masuk/keluar | Wajib token `savings` + key unik | `tabungan/proses.php:5-24`, `tabungan/proses.php:36-43`, `includes/idempotency.php:7-38` |
| `/tabungan/riwayat.php` | `GET` | A, B, K | `nis`, `bulan`, `tahun`, `page`, `per_page` | Tidak ada | N/A | `tabungan/riwayat.php:5-17` |
| `/tabungan/get_saldo.php` | Menggunakan GET tetapi method tidak dibatasi | Session dengan `admin_id`; role tidak diperiksa | `nis` | Tidak ada; output JSON saldo | N/A | `tabungan/get_saldo.php:3-23` |

Acceptance keamanan:

- Setoran/penarikan membutuhkan POST + CSRF, menolak content type/method lain, dan tetap atomik pada request konkuren.
- Endpoint saldo memakai `requireRole()`/otorisasi yang disetujui, status `401/403` yang benar, `application/json`, serta tidak melayani session akun yang sudah dicabut.
- Nominal/tanggal/keterangan mempunyai validasi server, batas ukuran, audit append-only, dan respons error generik.

## 5. Laporan, struk, dan ekspor

| Route | Method saat ini | Role | Input utama | Efek state / keluaran | Bukti |
|---|---|---|---|---|---|
| `/laporan/index.php` | `GET` | A, B | `bulan`, `tahun`, `tanggal`, `tanggal_awal`, `tanggal_akhir`, `jenis_laporan`, `urut`, `page`, `per_page` | HTML laporan umum | `laporan/index.php:5-10`, `laporan/index.php:85-123` |
| `/laporan/global.php` | `GET` | A, B, K | Tidak ada | Katalog tujuh template | `laporan/global.php:2-6` |
| `/laporan/template.php` | `GET` | A, B, K | `template`; filter bersama: tanggal, tahun ajaran, bulan, tahun, kelas, kategori, status, status siswa, operator, metode, `q`, mode, pagination | HTML hasil template | `laporan/template.php:2-17`, `includes/reports.php:35-65` |
| `/laporan/export_global.php` | `GET` | A, B, K | Semua filter template + `format=print|pdf|excel` | HTML print atau download PDF/Excel; dapat menjadi query mahal | `laporan/export_global.php:2-8`, `laporan/export_global.php:24-26` |
| `/laporan/rekap_kelas.php` | `GET` | A, B | `kelas`, `bulan`, `tahun`, `q` | HTML rekap pembayaran per kelas | `laporan/rekap_kelas.php:2-6`, `laporan/rekap_kelas.php:25-37` |
| `/laporan/detail_siswa.php` | `GET` | A, B | `nis`; `kelas`, `bulan`, `tahun`, `q` dipertahankan untuk tautan kembali | HTML histori siswa | `laporan/detail_siswa.php:2-6`, `laporan/detail_siswa.php:38-52`, `laporan/detail_siswa.php:144` |
| `/laporan/cetak_struk.php` | `GET` | A, B, K | `id` pembayaran | HTML/print struk tunggal | `laporan/cetak_struk.php:2-10` |
| `/laporan/cetak_struk_tahunan.php` | `GET` | A, B, K | `batch` token hex 32 karakter | HTML/print sekumpulan struk tahunan | `laporan/cetak_struk_tahunan.php:2-13` |
| `/laporan/export_excel.php` | `GET` | A, B | `bulan`, `tahun`, `tanggal`, `tanggal_awal`, `tanggal_akhir`, `download` | Preview HTML atau download `.xls` berbasis HTML | `laporan/export_excel.php:5-24`, `laporan/export_excel.php:129-135` |
| `/laporan/export_pdf.php` | `GET` | A, B | Filter tanggal/bulan/tahun; `contoh`, `mode`, `ids[]` | PDF slip via Dompdf | `laporan/export_pdf.php:5-27`, `laporan/export_pdf.php:612-618` |

Acceptance keamanan:

- Pemilik bisnis menyetujui atau mempersempit akses K terhadap seluruh Laporan Global dan export massal.
- Setiap ID/batch/filter diuji untuk enumeration, bypass role, SQLi, XSS, data silang, serta batas rentang/baris/waktu.
- Excel menetralkan awalan formula (`=`, `+`, `-`, `@`, tab, CR) pada semua sel data bebas.
- PDF mempunyai dependency/chroot/remote-resource policy yang konsisten dan gagal secara aman saat dependency hilang.
- `laporan/rekap_kelas.php` tetap dipertahankan sebagai referensi tampilan; audit tidak menghapus atau merefactornya.

## 6. Route internal dan development yang saat ini ikut terekspos

| Surface | Perilaku saat ini | Target kontrak |
|---|---|---|
| `/koneksi.php` | Dapat diminta langsung; membuat koneksi DB | Tidak public; bootstrap berada di luar document root atau di-deny |
| `/includes/*.php` | Dapat diminta langsung | Tidak public; helper berada di luar document root atau di-deny |
| `/tests/` | Directory index `200 OK` | `403/404`, tanpa listing |
| `/tests/*.php` | Script tidak punya CLI guard dan berpotensi dieksekusi lewat HTTP | Tidak dapat dieksekusi lewat web; CLI-only pada DB test |
| `/sql/`, `/sql/*.sql` | Listing dan source schema/migrasi dapat diunduh | `403/404` |
| `/.git/*` | Metadata Git dapat diunduh | `403/404`; Git tidak berada di document root deploy |
| `/composer.json`, `/composer.lock` | Manifest dependency dapat diunduh | Tidak public pada deployment produksi |
| `/documentation/` | Artefak internal berada dalam web root | Hanya dokumen yang sengaja dipublikasikan; sisanya `403/404` |

## 7. Matriks pengujian wajib

Untuk setiap route, jalankan kombinasi berikut pada salinan database:

1. tanpa cookie, cookie invalid, session valid A/B/K, role session invalid, akun yang baru dihapus, akun yang role/password-nya baru diubah;
2. GET, POST form, POST JSON, HEAD, OPTIONS, PUT/PATCH/DELETE yang tidak semestinya;
3. parameter hilang, duplikat, array menggantikan scalar, scalar menggantikan array, oversized, batas min/max, malformed UTF-8;
4. ID milik record pertama/terakhir/tidak ada/legacy serta batch token valid/tidak valid;
5. token CSRF valid, kosong, salah, token user lain, token sebelum/selepas login ulang;
6. dua request mutasi konkuren dan retry request yang sama; dan
7. snapshot DB serta audit log sebelum/sesudah untuk membuktikan request ditolak tidak mengubah state.

Matriks dianggap selesai hanya jika hasil aktual, status HTTP, redirect, body generik, perubahan DB, dan bukti log dicatat per kombinasi yang relevan.

## Status remediasi lokal 2026-08-27

Baris pada bagian sebelumnya adalah snapshot exposure baseline dan dipertahankan sebagai histori. Setelah hardening, deny `.git`/SQL/tests/documentation/includes, session bootstrap, CSRF, POST-only, request ID, dan role guards telah diuji melalui smoke/security regression pada clone disposable. Route-wide kombinasi role × method × parameter, concurrency, browser UAT, dan virtual-host client masih belum lengkap; karena itu matriks belum berstatus PASS untuk serah-terima.

| Route/kelompok yang berubah | Kontrak source terbaru | Bukti lokal | Status acceptance penuh |
|---|---|---|---|
| `/login.php` | GET form + POST autentikasi; login CSRF, generic failure, HMAC rate-limit account/source/pair, modern password hash only, session rotation | `security_regression_test.php` PASS dalam suite disposable | Parsial: timing corpus, recovery, dan target HTTPS belum lengkap |
| `/logout.php` | Hanya POST, CSRF `logout`, destroy session/cookie | security regression PASS | Parsial: browser back/cache target belum diuji |
| `/role_management.php` | A; POST + CSRF; create/reset/delete memakai transaksi, last-admin lock, session-version bump, alasan dan audit event | `role_management_audit_test.php` + security regression + concurrency matrix 25/25 PASS | Parsial: browser/Cartesian parameter dan target deployment belum lengkap |
| `/pembayaran/form.php`, `/edit.php`, `/lihat.php` | A,K; form mutasi mengirim CSRF `payment` + key 64-hex; edit legacy ditolak; delete form POST meminta alasan | payment integration + replay + payment-period concurrency PASS | Parsial: failpoint dan seluruh role/ID/XSS/browser matrix belum lengkap |
| `/pembayaran/proses.php` | A,K; POST-only + CSRF + scope `payment`; create/update/delete atomik, reason/audit, original actor preserved, legacy immutable | payment + audit integration + replay + payment-period concurrency PASS | Parsial: failpoint dan boundary input belum lengkap |
| `/tabungan/masuk.php`, `/keluar.php`, `/proses.php` | A,K; form CSRF `savings` + key 64-hex; handler POST-only, claim idempotency, row lock, ledger/cache, audit event | savings + security regression + concurrency 25/25 PASS | Parsial: deadlock/retry, boundary tanggal, dan browser UAT belum lengkap |
| `/tabungan/get_saldo.php` | GET-only; `requireRoleJson(['admin','kasir'])`; JSON | static source + security bootstrap | Belum ada route-wide HTTP negative matrix |
| Route privat lainnya | Bootstrap session terpusat, revalidasi akun/role/session version dan header keamanan | `run_route_role_http_matrix.ps1` PASS 168/168 pada clone disposable; 19 tabel domain count/checksum identik | Masih parsial terhadap Cartesian product method/content-type/parameter, DAST, concurrency, dan keputusan DEC-001 |
| `.git`, `config`, `includes`, `sql`, `tests`, `documentation`, Composer manifest | deny server lokal; test juga mempunyai DB/environment guard | URL deny smoke lokal PASS | Wajib retest pada virtual host/package client |
