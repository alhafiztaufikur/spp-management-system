# Audit Frontend, Responsive, Accessibility, dan Performa

> Snapshot read-only: 20 Agustus 2026 WIB  
> Commit sumber: `a446af3fbb89cb0933870443be5aedd85d34eaa3`  
> Status: baseline statis Wave 6; remediation FE-001/007/008/013 dan semantics dasar modal dilakukan pada source. Smoke browser lokal terbatas untuk login dan enam halaman read-only (viewport 390px/1440px, keyboard dasar, dan toggle tema) lulus pada 27 Agustus 2026 (`UI-BROWSER-001`/`UI-BROWSER-002`). Browser skill runtime resmi tetap terblokir karena discovery mengembalikan daftar browser kosong; pengujian seluruh route, accessibility tree, dan runtime coverage belum dijalankan.

## 1. Tujuan dan batas audit

Dokumen ini memetakan permukaan frontend SistemSPP dan menetapkan pemeriksaan yang harus dibuktikan sebelum aplikasi dinyatakan siap diserahkan kepada client. Temuan statis di bawah bukan pengganti UAT browser. Istilah `terbukti statis` berarti hubungan tersebut dapat diturunkan langsung dari source; istilah `perlu runtime` berarti hasil akhirnya masih harus diuji pada browser nyata.

Aturan perlindungan:

- `laporan/rekap_kelas.php` adalah referensi tampilan yang dilindungi;
- `laporan/detail_siswa.php`, link navigasi, dan selector yang menopang alur tersebut ikut dilindungi;
- audit boleh mencatat masalah atau membuat baseline, tetapi tidak boleh menghapus, mengganti konsep, atau mengategorikannya sebagai dead code tanpa keputusan baru dan eksplisit dari pemilik proyek.

## 2. Inventaris permukaan frontend

### 2.1 Route dan audience

| Kelompok | Route utama | Role/akses berdasarkan guard saat ini | Fungsi UI |
| --- | --- | --- | --- |
| Publik | `index.php`, `login.php` | anonim/session | redirect awal dan autentikasi |
| Session | `logout.php` | session | keluar aplikasi |
| Dashboard | `dashboard.php` | admin, bendahara | setoran dan ringkasan operasional |
| Master | `master_kelas.php`, `master_biaya_lain.php`, `master_daftar_ulang.php`, `role_management.php` | admin | kelas, tagihan, tahun ajaran, akun |
| Siswa | `siswa/daftar.php` | admin | CRUD/arsip siswa dan tarif |
| Pembayaran | `pembayaran/form.php`, `lihat.php`, `edit.php`, `riwayat_daftar_ulang.php`, `proses.php` | admin, kasir | input, koreksi, histori, endpoint mutasi |
| Tabungan | `tabungan/masuk.php`, `keluar.php`, `riwayat.php`, `proses.php`, `get_saldo.php` | mutasi: admin/kasir; riwayat: semua role; `get_saldo.php` hanya memeriksa session | setoran, penarikan, histori, AJAX saldo |
| Laporan umum | `laporan/index.php`, `export_excel.php`, `export_pdf.php` | admin, bendahara | laporan historis, preview/download Excel, PDF slip |
| Laporan modular | `laporan/global.php`, `template.php`, `export_global.php` | admin, bendahara, kasir | katalog tujuh template dan web/print/PDF/Excel |
| Receipt | `laporan/cetak_struk.php`, `cetak_struk_tahunan.php` | admin, bendahara, kasir | receipt tunggal dan compatibility batch tahunan |
| Referensi dilindungi | `laporan/rekap_kelas.php`, `detail_siswa.php` | admin, bendahara | rekap kelas dan histori siswa |

Catatan route:

- Sidebar mendefinisikan role menu pada `includes/sidebar.php:16-76`; backend tetap harus menjadi sumber otorisasi.
- `tabungan/get_saldo.php:6-9` hanya memeriksa adanya session, bukan role. Ini harus masuk matriks route × role walaupun endpoint dipanggil dari UI admin/kasir.
- Seluruh direct entrypoint harus diuji dengan anonim dan tiga role; keberadaan link atau menu bukan bukti guard backend.

### 2.2 Aset global

| Aset | Ukuran baseline | Pemakaian | Catatan audit |
| --- | ---: | --- | --- |
| `assets/css/style.css` | 130.857 byte | hampir semua halaman aplikasi | monolitik; mencakup layout, form, tabel, report, responsive, theme, modal, dan selector legacy |
| `assets/css/login.css` | 26.493 byte | login | memuat desain aktif dan cluster lama yang perlu coverage |
| `assets/js/app.js` | 68.316 byte | hampir semua halaman | theme, sidebar, form pembayaran, report picker, toast/modal, dan prototipe lama |
| `assets/img/favicon.png` | 4.700 byte | aktif | direferensikan banyak shell HTML |
| `assets/img/school-logo.png` | 125.496 byte | aktif | login, sidebar, report/export, dokumentasi |
| `assets/img/profile-avatar.png` | 11.580 byte | aktif | sidebar (`includes/sidebar.php:150`) |
| `assets/img/school-background.webp` | 237.116 byte | aktif | background login (`assets/css/login.css:730`) |
| `assets/img/login_illustration.png` | 669.601 byte | nol referensi literal | kandidat, dibahas di `DEAD_CODE_INVENTORY.md` |

Font Inter dimuat dari Google Fonts pada banyak halaman, misalnya `login.php:82-83`, `dashboard.php:58-59`, dan `pembayaran/form.php:126-127`. Operasi sekolah yang offline atau membatasi pihak ketiga perlu keputusan eksplisit: self-host, izinkan jaringan, atau tetapkan fallback yang diterima.

### 2.3 Domain JavaScript

| Domain | Bukti source | Status awal |
| --- | --- | --- |
| Theme dan label theme | `assets/js/app.js:5-44` | aktif |
| Klik/keyboard baris pembayaran | `assets/js/app.js:46-65` | aktif |
| Clock global | `assets/js/app.js:67-75` | aktif, tetapi timer selalu berjalan |
| Sidebar | `assets/js/app.js:77-88` | aktif, semantics belum lengkap |
| Pencarian siswa/datalist | `assets/js/app.js:113-314` | aktif |
| Perhitungan dan validasi UI pembayaran | `assets/js/app.js:319-1443`, `1563-1644` | aktif; harus diparitas dengan backend |
| Date-range picker laporan | `assets/js/app.js:1444-1561` | aktif |
| Flash/toast/modal umum | `assets/js/app.js:1656-1764` | campuran aktif dan kandidat legacy |
| Tab/localStorage/prototipe standalone | `assets/js/app.js:97-110`, `1648-1758` | kandidat; jangan hapus sebelum coverage |

### 2.4 Domain CSS

| Domain | Bukti source | Status awal |
| --- | --- | --- |
| Token dark/light dan reset | `assets/css/style.css:7-383` | aktif |
| Layout/sidebar/topbar/dashboard | `assets/css/style.css:384-1014` | aktif |
| Form pembayaran dan tabel | `assets/css/style.css:1015-2432` | aktif |
| Login lama, wrapper standalone, tab | `assets/css/style.css:2433-2534` | kandidat coverage |
| Responsive/table cards/bottom nav | `assets/css/style.css:2546-2713`, `3295-3705` | aktif |
| Toast/modal | `assets/css/style.css:2715-2781` | aktif |
| Rekap kelas dan histori siswa | `assets/css/style.css:2782-2888`, `3707-4285` | **dilindungi** |
| Master DU dan riwayat DU | `assets/css/style.css:4047-4463` | aktif |
| Laporan modular | mulai `assets/css/style.css:4464` | aktif |

## 3. Temuan baseline

| ID | Severity awal | Status bukti | Temuan dan bukti | Verifikasi/acceptance |
| --- | --- | --- | --- | --- |
| FE-001 | Tinggi | Remediasi statis selesai, runtime belum diuji | Sel dashboard kini memberi `data-label` untuk Komponen Setoran, Klasifikasi, dan Nominal; aturan kartu mobile dapat menampilkan konteks kolom. | Jalankan screenshot/runtime 320–768 px dan screen-reader check pada target client. |
| FE-002 | Tinggi | Remediasi source parsial, runtime belum diuji | Tombol sidebar kini memiliki accessible label/`aria-expanded`, dan `toggleSidebar()` menyinkronkan state setelah toggle. Backdrop tetap memakai alur klik sederhana; Escape/focus containment/return focus drawer belum dibuktikan. | Keyboard-only: buka/tutup, Escape, focus tidak hilang, tombol mengumumkan state, konten belakang tidak menerima focus saat drawer modal. |
| FE-003 | Tinggi | Terbukti statis | Modal tabungan tidak memiliki `role="dialog"`, `aria-modal`, atau accessible label (`tabungan/masuk.php:149-156`; pola sama di `keluar.php`). Helper global hanya mengubah class (`app.js:1747-1757`) dan tidak mengelola fokus. | Dialog memiliki nama, initial focus, trap, Escape, return focus, dan background inert; uji NVDA/keyboard. |
| FE-004 | Sedang | Remediasi source parsial, runtime belum diuji | Tombol sidebar yang sebelumnya hanya mengandalkan ikon/`title` kini diberi `aria-label="Buka navigasi"`; SVG tetap perlu diverifikasi sebagai dekoratif pada accessibility tree. | Semua tombol mempunyai accessible name stabil; SVG dekoratif tidak dibaca ganda. |
| FE-005 | Sedang | Terbukti statis | Banyak label visual tidak terhubung programatik ke kontrol. Contoh utama: filter modular satu-baris `laporan/template.php:24-35` dan filter tabungan `tabungan/riwayat.php:217-240`. | Audit seluruh `input/select/textarea`; gunakan `for/id`, wrapping label valid, atau `aria-labelledby`; axe tidak melaporkan form-label violation. |
| FE-006 | Sedang | Terbukti statis, UX perlu runtime | Bottom nav merender seluruh item yang lolos role (`includes/sidebar.php:167-180`). Pada mobile ia menjadi strip horizontal dengan scrollbar disembunyikan (`style.css:3544-3563`). Admin menerima banyak item sehingga affordance menu lanjutan berisiko tidak terlihat. | Uji 320/360/390/540 px untuk tiga role; tidak ada fitur penting yang tersembunyi tanpa affordance scroll/menu yang jelas. |
| FE-007 | Sedang | Remediasi source selesai, runtime belum diuji | `assets/css/style.css` dan `assets/css/login.css` kini memiliki media query `prefers-reduced-motion: reduce` yang menonaktifkan animasi/transisi non-esensial. | Uji dengan pengaturan OS reduced motion pada browser target. |
| FE-008 | Sedang | Remediasi source selesai, runtime belum diukur | Timer `liveClock` kini hanya dibuat bila elemen `#liveClock` ada; timer global pada halaman tanpa jam tidak lagi dibuat. | Ukur transfer, parse/execute, timer, dan long task per route pada perangkat target. |
| FE-009 | Sedang | Terbukti statis | Cache-buster tidak konsisten: `style.css` memakai versi `4.7` sampai `7.0`, sedangkan `app.js` memakai `2.8` sampai `7.0`; contoh `login.php:86-87,232`, `dashboard.php:60,192`, `laporan/template.php:20,42`. Rilis dapat memakai cache campuran. | Satu strategi build/release hash; seluruh shell merujuk artefak release yang sama; uji cold/warm cache dan rollback. |
| FE-010 | Sedang | Terbukti statis, keputusan produk | Login menampilkan kontrol/tautan tanpa alur: “Hubungi Admin” (`login.php:161`), “Lupa kata sandi” (`:189`), Syarat/Kebijakan (`:220-221`) memakai `href="#"`; checkbox remember (`:204-207`) tidak mempunyai consumer backend. | Setiap elemen diimplementasikan, diganti teks non-interaktif, atau dihapus lewat keputusan owner; tab order tidak berhenti pada tautan palsu. |
| FE-011 | Sedang | Diremediasi pada source | Hint kredensial default telah dihapus dan schema/seed tidak membuat akun default aktif. | Ulang smoke anonim pada paket client dan pastikan provisioning akun pertama dilakukan out-of-band. |
| FE-012 | Sedang | Terbukti statis, perlu load test | Laporan modular membangun seluruh hasil lalu memaginasi dengan `array_slice()` (`includes/reports.php:106-108`); query terkait memakai `fetch_all()` tanpa `LIMIT`, misalnya `:128,135,150,164,187-188,225`. UI/export berisiko timeout atau kehabisan RAM pada data besar. | Tetapkan volume dan budget; ukur p95, peak memory, rows examined, ukuran HTML/PDF/Excel; failure memberi pesan aman. |
| FE-013 | Rendah | Remediasi source parsial, runtime belum diuji | Toast pada laporan umum dan tabungan kini memakai `role=status`, `aria-live=polite`, `aria-atomic=true`, dan ikon dekoratif tersembunyi dari accessibility tree. | Audit seluruh route yang memiliki toast dan pastikan pesan dibaca sekali tanpa mencuri fokus. |
| FE-014 | Sedang | Perlu runtime | Tabel modular dapat sangat lebar dan memakai scroll container (`laporan/template.php:39`); matriks tahunan menambah 12 kolom. Semantik header, fokus scroll, sticky column, zoom 200%, dan print belum dibuktikan. | Tabel tetap dapat dipahami di 320 px/zoom 200%, header association benar, scroll dapat dioperasikan keyboard, hasil print tidak memotong data tanpa indikasi. |

## 4. Matriks uji browser wajib

### 4.1 Dimensi

- Viewport: 320, 360, 390, 540, 768, 1024, dan 1440 px.
- Orientasi: portrait dan landscape pada ukuran mobile/tablet.
- Tema: terang dan gelap.
- Zoom/text: 100%, 200%, text spacing WCAG.
- State: kosong, normal, data panjang, error, sukses, loading, session expired, modal terbuka, sidebar terbuka, print.
- Browser minimum: Chrome/Edge desktop target client dan Chrome Android; tambah Firefox bila dipakai operasional.
- Role: anonim, admin, bendahara, kasir dengan cookie jar/profile terpisah.

### 4.2 Route coverage minimum

1. Login: valid/invalid, password visibility, keyboard, offline font, autofill.
2. Dashboard: empty/data, table card mobile, period/filter, direct navigation.
3. Master dan siswa: create/edit/archive validation, long names/NIS, modal, pagination.
4. Pembayaran: search combobox, monthly validation, over-limit state, biaya lain rows, reset, error rollback presentation, receipt prompt.
5. Tabungan: search/fetch saldo, masuk/keluar, insufficient balance, history filter, recap search.
6. Laporan Umum: filter, select receipt, preview/download Excel, PDF.
7. Tujuh template Laporan Global: filters, empty/data/large, pagination, web/print/PDF/Excel.
8. Rekap per Kelas dan detail siswa: **protected screenshot regression** pada seluruh breakpoint utama; jangan mengubah konsep.

### 4.3 Accessibility procedure

- Jalankan axe/Lighthouse sebagai pembuka, bukan satu-satunya bukti.
- Keyboard-only: `Tab`, `Shift+Tab`, Enter, Space, arrow keys sesuai widget, dan Escape.
- Periksa accessibility tree untuk name, role, value, state, description, heading, landmark, table header, dan live region.
- Uji NVDA + Chrome/Edge pada login, pembayaran, modal, laporan, dan rekap protected.
- Ukur contrast seluruh state termasuk hover/focus/disabled/error pada dua tema.
- Verifikasi target sentuh minimum yang disepakati dan tidak ada kontrol tertutup bottom nav.
- Pastikan error tidak hanya mengandalkan warna, terhubung ke field, dan fokus menuju error pertama saat submit gagal.

## 5. Baseline performa dan bukti

Sebelum optimasi, rekam per route:

- jumlah request, transfer bytes, decoded bytes, cache hit/miss;
- FCP/LCP/CLS/INP atau pengganti lab yang tersedia;
- parse/compile/execute JS, style recalculation, layout, long tasks;
- jumlah timer/listener aktif setelah navigasi dan modal ditutup;
- response time server, ukuran HTML, dan peak memory untuk laporan/export;
- dampak Google Fonts saat online, lambat, diblokir, dan offline.

Budget final harus diputuskan bersama client berdasarkan perangkat dan volume nyata. Sampai budget dan hasil ukur tersedia, jangan menyatakan frontend “ringan”, “accessible”, atau “siap mobile”.

## 6. Format bukti dan exit criteria Wave 6

Nama artefak:

```text
<run-id>__W6__<finding-or-case>__<role>__<route>__<viewport-theme>__<result>.<ext>
```

Wave 6 selesai hanya bila:

- seluruh route/state/role target mempunyai bukti browser bertanggal dan commit SHA;
- FE-001 sampai FE-014 mempunyai hasil pass, remediation, atau risk acceptance resmi;
- tidak ada blocker keyboard/screen-reader pada alur transaksi utama;
- tidak ada clipping/kehilangan makna pada viewport, zoom, tema, dan print target;
- performa memenuhi budget yang disetujui pada data realistis dan worst allowed;
- baseline Rekap per Kelas dan detail siswa tetap utuh serta lulus screenshot regression.
