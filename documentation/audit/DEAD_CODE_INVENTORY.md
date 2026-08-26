# Inventaris Dead Code, Fitur Tanggung, dan Aset

> Snapshot read-only: 20 Agustus 2026 WIB  
> Commit sumber: `a446af3fbb89cb0933870443be5aedd85d34eaa3`  
> Status: inventaris awal Wave 7; belum ada penghapusan dan belum ada item berstatus `mati terkonfirmasi`.

## 1. Aturan klasifikasi

| Status | Arti operasional |
| --- | --- |
| `aktif` | mempunyai entrypoint/pemanggil dan fungsi bisnis yang terlihat pada source/runtime |
| `dinamis` | dipanggil melalui query, string, data attribute, AJAX, generated markup, atau pola yang tidak aman dinilai dengan pencarian literal saja |
| `compatibility` | dipertahankan untuk histori, URL lama, data legacy, receipt, atau upgrade |
| `test-only` | hanya dipakai verifier/test/fixture dan tidak boleh dipaketkan sebagai route produksi tanpa keputusan |
| `kandidat` | memiliki minimal dua indikasi independen tidak terpakai, tetapi belum boleh dihapus |
| `mati terkonfirmasi` | owner menyetujui tidak ada kebutuhan dan lint/test/crawl/runtime coverage/screenshot setelah penghapusan lulus |
| `dilindungi` | sengaja dipertahankan; coverage nol tidak mengubah status |

Aturan bukti:

1. Pencarian literal tunggal tidak cukup.
2. Klaim `kandidat` memerlukan minimal dua bukti independen, misalnya graph referensi statis + runtime coverage, atau zero reference + Git/owner decision.
3. Klaim `mati terkonfirmasi` memerlukan uji setelah penghapusan dan persetujuan owner.
4. Selector dinamis, callback global, direct URL, compatibility data, migration, dan test harus diperiksa terpisah.
5. Hapus satu cluster kecil per commit; ulang lint, test, role crawl, console/network check, coverage, accessibility, dan screenshot regression.

## 2. Allowlist dilindungi

Seluruh item berikut berstatus `dilindungi` dan tidak boleh dihapus atau diubah konsepnya dalam cleanup dead code:

| Item | Bukti dependency |
| --- | --- |
| `laporan/rekap_kelas.php` | route utama referensi; link siswa dibuat di `rekap_kelas.php:277-331` |
| `laporan/detail_siswa.php` | kembali ke rekap pada `detail_siswa.php:41,52,146`; dibuka dari rekap pada `rekap_kelas.php:277-331` |
| Link dan active state sidebar | item menu `includes/sidebar.php:69-71`; detail diwarisi sebagai active pada `:125,170` |
| Selector rekap tabel/filter/mobile | cluster `.class-recap-*`/`.recap-*` pada `assets/css/style.css:2782-2888`, `3707-4073` |
| Selector histori siswa | `.student-history-*`/`.history-*` pada `assets/css/style.css:4074-4285` |
| Helper lokal rekap/detail | `recap_*` di `laporan/rekap_kelas.php` dan `history_*` di `laporan/detail_siswa.php` |

Selector global yang dipakai halaman protected—misalnya sidebar, theme, `.main-card`, `.payment-table`, `.table-container`, `.responsive-table`, badge, button, dan `app.js`—tidak boleh dihapus hanya karena analisis satu halaman tidak melihatnya. Dependency harus dibuktikan per selector/function.

## 3. Inventaris route dan entrypoint

### 3.1 Aktif atau dinamis

| Item | Status | Bukti |
| --- | --- | --- |
| `index.php`, `login.php`, `logout.php` | aktif | entry/redirect/session; sidebar menautkan logout di `includes/sidebar.php:158` |
| `dashboard.php` | aktif | tujuan login admin dan menu sidebar |
| `master_kelas.php`, `master_biaya_lain.php`, `master_daftar_ulang.php`, `role_management.php` | aktif | direct menu admin pada `includes/sidebar.php:37-47,73-75` |
| `siswa/daftar.php` | aktif | direct menu admin pada `includes/sidebar.php:33-35` |
| seluruh route `pembayaran/` | aktif | menu, form action, redirect, edit link, dan receipt flow |
| `tabungan/masuk.php`, `keluar.php`, `riwayat.php`, `proses.php` | aktif | menu, form action, dan redirect |
| `tabungan/get_saldo.php` | dinamis | dipanggil melalui `fetch()` pada `tabungan/masuk.php:197-203` dan pola yang sama di keluar |
| `laporan/index.php`, `export_excel.php`, `export_pdf.php` | aktif | laporan umum dan tombol export (`laporan/index.php:457` untuk Excel) |
| `laporan/global.php`, `template.php`, `export_global.php` | dinamis | registry menghasilkan template dan URL berdasarkan query; `laporan/global.php:12`, `template.php:37` |
| `laporan/cetak_struk.php` | aktif | link dari detail/riwayat pembayaran |
| `laporan/cetak_struk_tahunan.php` | compatibility | receipt batch lama masih dibaca berdasarkan `payment_batch_token` (`cetak_struk_tahunan.php:132-194`) walaupun input tahunan baru ditangguhkan |
| `laporan/rekap_kelas.php`, `detail_siswa.php` | dilindungi | lihat allowlist; tidak boleh dinilai dead |
| `includes/*.php` | aktif/dinamis | library internal, bukan direct route; petakan call graph per function sebelum cleanup |
| `tests/*_test.php` | test-only | delapan script regresi mandiri pada snapshot baseline; inventaris current mencakup runner audit tambahan dan seluruhnya tetap bukan route produksi |

Tidak ditemukan file PHP aplikasi dengan nol referensi literal eksternal pada pass awal. Hasil ini bukan bukti semua route dibutuhkan secara bisnis; direct URL, redirect dinamis, form action, dan compatibility tetap harus diuji.

Catatan current (27 Agustus 2026): seluruh 18 test PHP yang terlihat pada worktree telah memiliki guard CLI/database audit dan tercakup dalam regression disposable; tidak ada item yang dihapus atau dinyatakan `mati terkonfirmasi`. Browser/runtime coverage dan keputusan owner tetap menjadi syarat klasifikasi final.

### 3.2 Tumpang tindih yang aktif, bukan dead code

| Area | Klasifikasi | Keputusan yang diperlukan |
| --- | --- | --- |
| Laporan Umum (`laporan/index.php` + export khusus) versus tujuh Laporan Global modular | aktif | tentukan owner/audience dan parity; konsolidasikan hanya lewat keputusan produk |
| PDF slip (`export_pdf.php`) versus PDF laporan modular (`export_global.php`) | aktif | renderer berbeda karena tujuan berbeda; uji dependency, layout, dan error secara terpisah |
| Excel umum versus Excel modular | aktif tetapi tanggung | keduanya mengirim HTML `.xls`; putuskan format final/client compatibility |
| Receipt tahunan | compatibility | jangan hapus sampai data `payment_batch_token` historis dan kebutuhan cetak ulang diputuskan |

## 4. Inventaris JavaScript

### 4.1 Aktif/dinamis

| Cluster | Status | Bukti |
| --- | --- | --- |
| Theme/sidebar/clock | aktif | dipanggil shell/sidebar; `app.js:5-88` |
| Clickable payment rows | dinamis | listener berdasarkan `.clickable-payment-row[data-edit-url]`, `app.js:46-65` |
| Student search combobox | aktif/dinamis | markup form memakai `oninput="pilihSiswaDatalist(this)"`; helper saling memanggil pada `app.js:123-314` |
| Perhitungan form dan biaya lain | aktif | function chain `app.js:319-1443,1563-1644`, dipicu DOMContentLoaded/input |
| Report date range | aktif/dinamis | mencari `[data-range-picker]`, `app.js:1485-1561`; markup laporan menyediakan data attribute |
| `showToast()` | aktif | dipakai laporan untuk error pilihan cetak (`laporan/index.php:637-638`) |
| `closeModal()` | aktif | tombol modal tabungan memanggilnya (`tabungan/masuk.php:154`, `keluar.php:155`) |

### 4.2 Kandidat cleanup

| ID | Item | Status | Bukti 1 | Bukti 2 | Gate sebelum hapus |
| --- | --- | --- | --- | --- | --- |
| DC-JS-001 | `switchTab()` dan `renderLihatTable()` | kandidat | `switchTab` hanya muncul pada definisinya (`app.js:98`); tidak ada pemanggil PHP/JS/HTML | Tidak ada markup `.tab`/`.tab-panel` aktif; `renderLihatTable()` hanya dipanggil dari cabang `switchTab` (`app.js:110,1680`) | Instrument global calls + JS coverage seluruh route/state + Git history |
| DC-JS-002 | `cariSiswa()` | kandidat | hanya satu occurrence, definisi `app.js:1648` | pencarian aktif memakai combobox/datalist lain (`app.js:123-314`) dan tidak ada `onclick/oninput` yang memanggil `cariSiswa` | coverage login/form/edit/tabungan + history |
| DC-JS-003 | `filterTable()` | kandidat | hanya satu occurrence, definisi `app.js:1668` | pencarian pembayaran aktif dikirim sebagai filter server pada `pembayaran/lihat.php:199-219`, bukan callback ini | coverage riwayat pembayaran dan direct global call instrumentation |
| DC-JS-004 | localStorage `dataStore`/`spp_data`, `inputData()` | kandidat | `dataStore` hanya hidup dalam blok fallback `app.js:1677-1725`; `inputData` hanya definisi | transaksi aktif memakai form POST `pembayaran/proses.php`; tidak ada `tbl-lihat-body` atau `empty-lihat` pada markup PHP | cek Git history, localStorage compatibility decision, coverage seluruh form/list |
| DC-JS-005 | `editData()`, `hapusData()`, `keluarForm()`, `cetakLaporan()`, `konfirmasiHapus()` | kandidat | masing-masing tidak mempunyai pemanggil statis eksternal; definisi di `app.js:1728-1757` | aplikasi aktif memakai link/form/receipt dan modal spesifik; tidak ada atribut inline yang menyebut function tersebut | global call instrumentation, history, crawl semua aksi |

Belum ada status `mati terkonfirmasi`: bukti kedua pada tabel di atas masih statis/arsitektural, belum runtime coverage dan belum persetujuan owner.

### 4.3 Compatibility yang tampak mati tetapi belum boleh dihapus

Annual-payment helper di `app.js:423-449,1208-1246` tidak mempunyai kontrol aktif karena `pembayaran/form.php:183` memaksa hidden `payment_plan=monthly`, dan backend menolak nilai selain monthly pada `pembayaran/proses.php:650-661`. Namun backend masih memiliki cabang batch pada `proses.php:694-811` dan receipt historis memakai `payment_batch_token`. Klasifikasi awal: `compatibility/kandidat keputusan`, bukan mati.

## 5. Inventaris CSS

| ID | Cluster | Status | Bukti 1 | Bukti 2 | Gate |
| --- | --- | --- | --- | --- | --- |
| DC-CSS-001 | Login lama `.login-body`, `.login-wrapper`, `.login-card`, `.login-logo`, `.login-subtitle`, `.login-form`, `.login-hint` (`style.css:2433-2483`) | kandidat | class-token exact tidak ditemukan pada markup aktif; login sekarang memakai `.login-split-body`, `.login-card-wrap`, `.login-left/right` (`login.php:89-230`) | desain aktif berasal dari `login.css`; selector lama tidak dirujuk JS | CSS coverage login default/error/autofill/theme/mobile + screenshot diff |
| DC-CSS-002 | Standalone wrapper `.app-wrapper`, `.app-header`, `.header-brand`, `.header-meta` (`style.css:2485-2499`) | kandidat | exact class-token tidak ditemukan pada PHP aktif | shell sekarang memakai `.layout`, `.sidebar`, `.main-content`, `.topbar` | coverage seluruh route + history |
| DC-CSS-003 | Tabs `.tabs`, `.tab`, `.tab-panel` (`style.css:2501-2534`) | kandidat | tidak ada markup tab aktif | satu-satunya controller `switchTab()` juga kandidat tanpa caller | joint CSS/JS coverage + history |
| DC-CSS-004 | Illustration/metric/social login lama di `login.css`, termasuk `.left-illustration*`, `.left-metrics-row`, `.metric-pill`, `.social-row`, `.social-btn` | kandidat | exact selector tidak ditemukan pada `login.php` atau JS | markup login aktif hanya logo/card/tagline/form; aset ilustrasi juga nol referensi | coverage semua login state + owner/design approval |
| Rekap dan histori (`.class-recap-*`, `.recap-*`, `.student-history-*`, `.history-*`) | dilindungi | dipakai eksplisit oleh dua route protected | owner menetapkan referensi tampilan | jangan hapus; screenshot regression wajib |
| Dynamic state: `.status-*`, `.badge-role-*`, `.admin-avatar-*`, `.is-*`, `.show`, `.active`, `.open` | dinamis | dibentuk PHP/JS dari status/role/state | pencarian literal dapat gagal karena concatenation | allowlist + generated DOM + coverage |

## 6. Inventaris gambar dan dependency browser

| ID | Item | Status | Dua bukti awal | Keputusan |
| --- | --- | --- | --- | --- |
| DC-ASSET-001 | `assets/img/login_illustration.png` (669.601 byte) | kandidat | pencarian nama file di luar folder aset menghasilkan nol referensi; CSS login aktif memakai `school-background.webp` (`login.css:730`) | periksa Git/Figma/desain client dan network coverage sebelum hapus |
| `favicon.png` | aktif | 23 referensi literal; dipakai shell HTML | pertahankan |
| `school-logo.png` | aktif | login/sidebar/export/dokumen; dipakai sebagai data URI PDF | pertahankan, optimasi hanya dengan visual regression |
| `profile-avatar.png` | aktif | `includes/sidebar.php:150`; tampak pada semua role | pertahankan |
| `school-background.webp` | aktif | `login.css:730`; bagian desain login | pertahankan |
| Google Fonts | aktif, dependency eksternal | banyak link `fonts.googleapis.com`; tidak ter-versioning lokal | putuskan offline/privacy, bukan dead-code cleanup |

## 7. Sistem tanggung dan drift dokumentasi

| ID | Item | Klasifikasi | Bukti/risiko | Tindak lanjut |
| --- | --- | --- | --- | --- |
| HALF-001 | “Ingat perangkat ini” | fitur tanggung | checkbox `login.php:204-207`; tidak ada consumer `remember` di backend | implementasikan kebijakan cookie yang aman atau hilangkan UI |
| HALF-002 | Hubungi Admin/Lupa Password/Syarat/Privasi | fitur tanggung | `href="#"` + `onclick="return false"` di `login.php:161,189,220-221` | implementasikan tujuan resmi atau ganti teks non-interaktif |
| HALF-003 | Hint akun default | historical/remediated | Snapshot baseline pernah menampilkan hint/seed `admin/admin123`; `login.php` dan schema/seed saat ini tidak lagi menampilkan atau menanam credential default | verifikasi ulang packaging profile produksi dan rotasi credential historis |
| HALF-004 | Version query aset | sistem tanggung | versi `style.css`/`app.js` berbeda antar halaman | satu release hash/manifest |
| DOC-001 | SOP/flowchart semula mengajarkan Tabungan Wajib lewat pembayaran | selesai lokal | HTML/PDF SOP dan flow kini menyatakan Tabungan Masuk manual, annual ditolak, dan legacy read-only; 12+4 halaman dirender dan diperiksa | Retest viewer client saat UAT |
| DOC-002 | MoM lama | drift | placeholder `MOM_SistemSPP.md:9-13,138-144`; commit lama baris 15/173; klaim tests/role tidak sesuai baris 88/148 | update dari evidence final, isi owner/date, jangan menebak |
| DOC-003 | HTML/PDF operasional | compatibility document | source/PDF bertanggal 4 Agustus, sementara code berubah sampai 20 Agustus | parity review per halaman dan checksum hasil render final |

## 8. Prosedur pembuktian dan removal

### 8.1 Bangun graph

- Parse literal `href`, `action`, `include/require`, redirect, fetch/XHR, script/style/image URL, inline handler, function call, class/id/data attribute.
- Tambahkan edge dinamis dari report registry, `http_build_query`, PHP interpolation, role filtering, callback global, dan class concatenation.
- Masukkan direct URL yang didokumentasikan, bookmark user, receipt lama, migration/test, dan entrypoint CLI.
- Simpan manifest dengan commit SHA; perbedaan manifest antar commit harus direview.

### 8.2 Runtime coverage

- Crawl sebagai anonim/admin/bendahara/kasir dengan session terpisah.
- Kunjungi seluruh route, query variant, CRUD state, error/success, modal, dark/light, viewport, print, dan tujuh template laporan.
- Rekam request/redirect, console error, DOM generated, JS coverage, CSS coverage, localStorage access, download, dan popup.
- Instrument function global kandidat untuk mendeteksi pemanggilan dari inline script, console/bookmark, atau integrasi yang tidak terlihat statis.
- Coverage nol harus diulang pada state lengkap; tidak pernah mengalahkan allowlist protected.

### 8.3 Gate removal

Untuk setiap kandidat:

1. kumpulkan dua bukti independen dan owner decision;
2. buat commit removal satu cluster;
3. jalankan lint seluruh PHP, `node --check`, test resmi, HTTP role/method crawl, browser console/network, accessibility, visual screenshot, print/PDF/Excel yang relevan;
4. pastikan tidak ada kenaikan 404/500, console error, broken layout, atau perubahan protected rekap/detail;
5. catat ukuran sebelum/sesudah dan rollback commit;
6. baru ubah status menjadi `mati terkonfirmasi`.

## 9. Exit criteria Wave 7

- Semua route, include, function JS/PHP, selector CSS, aset, test, migration, dan dokumen memiliki klasifikasi serta owner/keputusan.
- Semua kandidat mempunyai dua bukti independen; item yang belum mempunyai runtime evidence tetap `kandidat`.
- Fitur tanggung diputuskan implement/hapus/defer dengan risk acceptance dan tanggal.
- Dokumentasi operasional, HTML, dan PDF sama dengan perilaku source/runtime final.
- Tidak ada seed/default credential atau artefak test/internal pada paket public client.
- Setiap removal lulus test/crawl/coverage/accessibility/screenshot.
- `laporan/rekap_kelas.php`, detail siswa, navigasi, selector, dan alur terkait tetap `dilindungi` serta lulus regression.
