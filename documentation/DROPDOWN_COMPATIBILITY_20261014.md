# Posisi dropdown dan kompatibilitas browser

## Perbaikan 14 Oktober 2026

Masalah direproduksi pada Chromium 109.0.5414.46, acuan mesin browser Chrome terakhir untuk Windows 7. Pada fixture dengan panel induk bertransformasi, menu lama bergeser sekitar 198 px secara horizontal dan 156 px secara vertikal dari posisi yang seharusnya. Menu memakai koordinat viewport, tetapi masih berada dalam elemen yang mengubah acuan `position: fixed`. Browser ini juga melempar `SyntaxError` untuk `matches(':popover-open')`; pemanggilan lama tidak semuanya dilindungi pemeriksaan dukungan.

`window.sppFloatingPanels` di komponen dropdown menyediakan `show`, `hide`, `position`, `contains`, dan `viewport`. Select tetap menjadi sumber nilai, validasi, dan pengiriman formulir. Tidak ada endpoint, parameter filter, aturan transaksi, atau skema database yang berubah.

- Browser dengan dukungan lengkap memakai Popover. Browser lainnya memakai lapisan sementara pada `body`, atau pada dialog aktif untuk kontrol dalam modal. Panel kembali ke tempat asal saat ditutup.
- Menu mengikuti kontrol dengan jarak 6 px. Arah bawah diutamakan; arah atas dipilih bila ruang bawah tidak cukup dan ruang atas lebih besar. Lebar minimum pilihan 260 px dibatasi area layar; panel rentang tanggal/bulan/tahun memakai kebutuhan lebar 300–320 px.
- Batas layar 8 px, tinggi maksimum daftar 440 px, dan pencarian siswa 320 px. Isi panjang dapat digulir. Pengukuran memakai `visualViewport` ketika tersedia.
- Selama terbuka, posisi mengikuti perpindahan kontrol, perubahan isi, ukuran layar, dan gulir. Panel ditutup jika kontrol dihapus, disembunyikan, keluar sepenuhnya dari viewport, atau modal asal ditutup. Pengawasan berhenti ketika panel ditutup.
- Hubungan form, token warna, dan hubungan panel bertingkat dipertahankan ketika panel dipindahkan. Klik di menu anak tetap dianggap berada di panel induk. Pemilih kelas memperbarui seluruh opsi melalui referensi panelnya.
- Caption tombol hanya ditulis ulang jika nilainya berubah; penulisan pada setiap `pointerdown` sebelumnya dapat menghilangkan klik pada WebKit. Fokus pemilih menggunakan `preventScroll` agar Firefox tidak menggeser target antara penekanan dan pelepasan mouse. Fokus tertunda pada panel rentang yang sudah ditutup dibatalkan.

## Cakupan audit

Komponen mencakup select tunggal, checkbox beberapa pilihan, pencarian operator, tahun pembayaran, kelas/rombel, tagihan Daftar Ulang, filter laporan, rentang tanggal/bulan/tahun, dan pencarian siswa. Kontrol tanggal bawaan browser/OS tetap memakai perilaku browser tersebut.

Audit halaman meliputi Dashboard, Data Siswa, master kelas/SPP/Daftar Ulang/Biaya Lain, Input/Riwayat Pembayaran, Riwayat Daftar Ulang, Otorisasi, Riwayat/Cetak Tabungan, katalog/template Laporan Global, Surat Orang Tua, Role Management, dan Backup & Restore. Halaman master dengan tab tersembunyi menggunakan komponen select yang sama; kontrol yang tersembunyi/nonaktif tidak dibuka paksa.

## Pengujian

Tiga suite browser tersedia:

- `tests/dropdown_position_browser_test.js`: fixture DOM tanpa database memakai kode komponen aktual; posisi, fallback dengan selector tidak didukung, enam lebar layar, dua tema, tiga warna unit, viewport pendek, form asal, modal, panel bertingkat, scroll, kontrol dihapus, opsi dinamis, keyboard, dan area viewport di atas keyboard.
- `tests/dropdown_application_browser_test.js`: halaman aplikasi pada instance MySQL salinan dengan manifest identitas terverifikasi; memeriksa posisi dan callback kelas, tahun, pencarian siswa, Daftar Ulang, serta rentang bulan. `SPP_DROPDOWN_UNITS=0` menjalankan cakupan Semua Unit yang diizinkan.
- `tests/dropdown_filter_browser_test.js`: pilihan kelas/status, Terapkan, reload, pagination dan identitas siswa dibandingkan dengan hasil query independen pada salinan, serta pengiriman form native tanpa JavaScript dalam sesi yang telah masuk.

Runtime pengujian: Chrome 155.0.8059.40, Edge 155.0.4283.45, Firefox 144.0.2, WebKit 26.0, dan Chromium 109.0.5414.46. Chromium lama diperoleh dari distribusi pengujian Playwright 1.29.2; browser lain memakai Playwright 1.57.0. Instalasi alat dan artefak berada di luar repository.

Suite komponen lulus 515 pemeriksaan posisi, termasuk interaksi sentuh pada lima mesin browser. Audit aplikasi lulus 1.098 pemeriksaan per browser untuk tiga unit, ditambah 200 pemeriksaan Semua Unit masing-masing pada Chrome, Chromium 109, dan Firefox. Filter kelas/status cocok dengan query independen pada keempat cakupan; reload, pagination dan form tanpa JavaScript lulus. Pemeriksaan tambahan edit siswa/aktivasi Legacy serta pengambilan tampilan menu terbuka lulus 33 kasus masing-masing pada Chrome dan Chromium 109. Screenshot menu desktop/ponsel diperiksa pada kedua tema. Fingerprint seluruh 36 tabel operasional sebelum/sesudah pengujian identik.

Audit otomatis memosisikan scroll secara langsung sebelum klik, lalu menunggu posisi kontrol stabil. Ini menghindari klik otomatis yang terlepas saat animasi smooth-scroll/asynchronous panning Firefox masih berjalan. Perubahan posisi saat menu sudah terbuka tetap diuji secara terpisah.

Lebar layar: 360, 390, 768, 1024, 1366, dan 1920 px. Zoom 80–200% diperiksa melalui perubahan ukuran viewport CSS yang setara dengan zoom browser; area keyboard diuji melalui viewport visual yang diperkecil dan bergeser. Ini tidak menggantikan pemeriksaan fisik skala Windows, keyboard Android/iOS, atau Safari pada perangkat Apple. Chromium 109 diuji pada Windows lokal saat ini; PC Windows 7 fisik belum tersedia untuk pengujian langsung.

Suite komponen dapat berjalan tanpa database dengan `SPP_PLAYWRIGHT_CORE`, `PLAYWRIGHT_BROWSERS_PATH`, dan `SPP_CHROMIUM109_EXECUTABLE`. Suite aplikasi/filter membutuhkan URL loopback server pengujian, `SPP_TEST_ISOLATED_INSTANCE`, dan `SPP_DROPDOWN_ACCOUNT_FILE` privat berisi username/password akun salinan. Server wajib memakai konfigurasi instance MySQL salinan sesuai manifest. Jangan mengarahkan suite ke server operasional.

Untuk suite filter, `SPP_DROPDOWN_SOURCE_ROWS` menunjuk JSON privat hasil `SELECT id,unit_id,master_kelas_id,is_active,legacy_pending FROM siswa` dengan konteks unit masing-masing 1, 2, 3, dan 0. Bentuknya objek berkunci nomor unit, masing-masing berisi array baris. Ekspor harus memanggil pemeriksaan target salinan dari helper tes sebelum membaca koneksi. Nilai siswa dan screenshot tetap berada di luar Git/webroot.

## Penerapan

Pembaruan cukup melalui source terbaru. Versi aset menggunakan `filemtime` existing agar browser meminta JavaScript/CSS terbaru. Tidak ada migrasi atau perubahan database operasional. ZIP deployment lama tidak diperbarui oleh pekerjaan ini.
