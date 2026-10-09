# Header Sidebar dan Keterbacaan

Tanggal pemeriksaan: 9 Oktober 2026.

## Perubahan

- Header sidebar memakai logo sekolah yang tersedia, nama SistemSPP, subjudul Sistem Pembayaran Sekolah, dan dua gelombang SVG ringan. Warna mengikuti unit operasional: SD hijau, SMP biru, SMA merah, Semua Unit ungu. Pembaruan berikutnya menyamakan latar pekat dan teks terang dengan navigasi pada kedua tema.
- `workspace_readability.css` dimuat pada Role Management, Susun Surat Orang Tua beserta pratinjaunya, Laporan Umum, Riwayat Pembayaran, Riwayat Daftar Ulang, Data Siswa, dan Master Daftar Ulang. Penanda `data-readable-workspace` membatasi aturan halaman dan dropdown dalam top layer. Pemantauan Master Penerbitan SPP tidak memuat aturan tambahan ini.
- Ukuran memakai rem: isi/input/editor 16 px, tabel/identitas 15 px, label/tombol 14 px, keterangan minimal 13 px, judul panel 20 px, angka ringkasan 24 px, judul halaman 28–32 px. Kontrol formulir minimal 48 px dan tombol tindakan minimal 44 px.
- Panel, padding, tabel, menu tindakan, dan modal menyesuaikan ukuran teks. Tabel akun menjadi kartu pada layar kecil; tabel laporan menggunakan scroll dalam wadahnya. Judul topbar dan identitas panjang dapat membungkus.
- Handler PHP, model data, nama bidang, tujuan formulir, JavaScript bisnis, dan renderer cetak/PDF tidak diubah. Semua perubahan lokal sebelumnya dipertahankan; tidak ada migrasi, endpoint baru, commit, atau push.

## Hasil Pemeriksaan

- Baseline sebelum perubahan dibandingkan dengan teks/data serta kontrak formulir pada 12 tampilan sumber. Pemeriksaan 96 kombinasi tiga halaman, empat unit, dua tema, dan lebar 1600/1280/768/390 px menunjukkan data/formulir identik, tanpa galat JavaScript atau luapan horizontal halaman. Teks antarmuka utama yang terlihat minimal 13 px.
- Pemeriksaan interaksi pada 16 kombinasi unit/tema/lebar mencakup dropdown, kalender, pencarian siswa, menu akun, modal password/nonaktifkan, dialog penyalinan pesan, dan tinggi kontrol 48 px. Tiga halaman juga diperiksa tanpa JavaScript.
- Pemeriksaan tambahan mencakup 48 kombinasi breakpoint, nama/username panjang, nominal besar, serta emulasi pembesaran 125%/200%. Pembesaran diuji menggunakan viewport CSS dan device scale yang setara; pengaturan zoom manual Chrome tidak diotomatisasi.
- Sembilan variasi laporan mencakup jenis tagihan, pilihan ganda, tab komponen/transaksi, dan hasil kosong. Arsip PDF surat tetap dapat dimuat dengan respons PDF yang valid.
- Pemeriksaan terakhir mencakup 24 tampilan ponsel 320 px, judul topbar tanpa pemotongan, drawer sidebar, fokus keyboard, simpan/copy pesan dengan format tebal, dan isolasi CSS pada Data Siswa. Nama/nominal panjang hanya diubah dalam DOM browser; pesan uji hanya disimpan dalam sesi sementara.
- Sintaks empat berkas PHP dan `git diff --check` lolos. Prefix handler/model sebelum HTML identik dengan salinan sebelum pekerjaan. Audit `readiness_integrity_audit.php` pada salinan `db_spp_audit_authorization_20261007` lolos dan hasil sebelum/sesudah identik.
- Artefak dan skrip pemeriksaan berada di `C:/Users/Matchaa/.codex/tmp/readability-qa`, di luar repositori. Sesi sementara dibersihkan dan server pemeriksaan dihentikan. Tidak ada perubahan data keuangan dalam pengujian ini.

## Pembaruan Header Pekat dan Empat Halaman

- Header memakai `--nav-base`/`--nav-deep`, teks `--nav-text`/`--nav-muted`, dan gelombang beropacity rendah pada kedua tema. Kotak logo menjadi 52 px dengan padding 2 px; gambar asli tetap 512 × 512 px. Nama aplikasi 18 px dan subjudul 13 px.
- Penanda baru: `payment-history`, `registration-history`, `students`, dan `master-registration`. Detail hasil AJAX, dialog aktivitas, penghapusan/pengajuan, tawaran cetak, dropdown, kalender, dan peringatan tunggakan mengikuti ukuran antarmuka. Renderer struk, PDF, dan ekspor tidak diubah.
- Kartu transaksi menyesuaikan grid dan ruang identitas/nominal. Master menyejajarkan ikon/angka ringkasan dan membungkus pilihan tahun yang panjang. Pada ruang terbatas, ringkasan Master pindah ke baris berikutnya. Potongan siswa mendapat label dan padding yang cukup; teks tidak diperkecil pada ponsel.
- Aturan tampilan ponsel yang sebelumnya mengalahkan atribut `hidden` pada riwayat kelas siswa diperbaiki secara khusus pada halaman siswa. Panel kembali mengikuti tombol Riwayat yang tersedia; handler tetap sama.

### Verifikasi Pembaruan

- Data, teks, dan kontrak formulir pada 16 tampilan sumber (empat halaman × empat unit) identik dengan baseline sebelum perubahan. Token konteks acak dikecualikan dari perbandingan teks. Prefix handler/model sebelum HTML pada lima berkas PHP identik dengan salinan awal pekerjaan.
- Pemeriksaan awal 160 kombinasi unit/tema/lebar menemukan beberapa ukuran kecil yang kemudian diperbaiki. Pemeriksaan ulang 128 kombinasi empat unit, kedua tema, dan lebar 1600/768/390/320 px lolos: tidak ada teks antarmuka utama di bawah 13 px, luapan horizontal halaman, atau galat JavaScript.
- Sebanyak 326 pemeriksaan tampilan/interaksi mencakup aktif/dihapus, hasil kosong, detail AJAX dan tautan tanpa JavaScript, kegagalan detail/Coba Lagi, pergantian pilihan cepat, dialog aktivitas/penghapusan/tawaran cetak, tambah/edit siswa, Advanced, pilihan kelas, Reset ke mode Tambah, pemilih tahun, serta Master draft/published/closed. Input yang diperiksa minimal 16 px dan 48 px; angka ringkasan Master 24 px dan sejajar dengan ikon.
- Pemeriksaan akhir pada 56 kombinasi tampilan pembayaran/header (1920/1600/1280/1024/650/390 px, seluruh unit, kedua tema) lolos. Dimensi logo asli 512 px, kotak 52 px, judul aplikasi 18 px, dan subjudul 13 px terkonfirmasi. Struk transaksi asli dapat dimuat dan tidak memuat stylesheet keterbacaan.
- Pemeriksaan tambahan memakai identitas panjang dan emulasi 125%/200% melalui viewport CSS serta device scale; zoom manual browser tidak diotomatisasi. Regresi tiga halaman sebelumnya pada ponsel lolos. Pemantauan bersama Master SPP tetap tanpa penanda/stylesheet keterbacaan ini.
- Sintaks lima berkas PHP dan `git diff --check` lolos. Uji model arsip `registration_workspace_model_test.php` lolos menggunakan transaksi yang di-rollback pada salinan database. Audit `readiness_integrity_audit.php` sebelum/sesudah identik, termasuk hash hasil.
- Pengujian menggunakan `db_spp_audit_authorization_20261007`. Fixture tahun closed sementara dihapus setelah pemeriksaan; dialog cetak hanya dipicu melalui sesi uji. Browser tidak mengirim perubahan data. Lima sesi sementara dibersihkan dan server pemeriksaan dihentikan. Artefak berada di `C:/Users/Matchaa/.codex/tmp/readability-expanded-qa`, di luar repositori. Tidak ada commit atau push.

## Penyesuaian Ukuran Teks dan Subjudul Sidebar

- Subjudul Sistem Pembayaran Sekolah menjadi 11 px (`0.6875rem`) dengan `white-space: nowrap`. Logo tetap 52 px, nama aplikasi 18 px, dan palet unit tetap sama. Pemeriksaan browser memastikan teks utuh dalam satu baris.
- Empat penanda halaman pembayaran/siswa/Master Daftar Ulang mempunyai variabel tersendiri: isi/input 15 px, identitas/tabel 14 px, label/tombol 13 px, keterangan/badge 12 px, judul panel/modal 18 px, angka ringkasan 22 px, dan judul halaman 26–28 px. Role Management, Susun Surat Orang Tua, dan Laporan Umum mempertahankan ukuran sebelumnya.
- Token input terpisah mengatur pencarian, dropdown, pemilih kelas/tahun, kalender, dan textarea dialog. Pada lebar sampai 650 px, input/pencarian tetap 16 px. Tinggi kontrol minimal 48 px dan tindakan 44 px tetap dipertahankan.
- Padding panel menjadi 16–20 px dengan jarak bidang 12–16 px. Label Komponen Biaya dan Potongan sejajar pada desktop; ketika bidang bertumpuk, tinggi label kembali mengikuti isi. Ukuran angka Master dan ikon tetap sejajar.

### Hasil Pemeriksaan Penyesuaian

- Baseline 16 tampilan sebelum/sesudah menunjukkan teks, angka, nama/ID/nilai bidang, serta tujuan/metode formulir identik, dengan token acak dinormalisasi.
- Pemeriksaan 128 kombinasi empat halaman, empat unit, dua tema, dan lebar 1600/1280/768/390 px lolos tanpa luapan horizontal, galat JavaScript, atau teks utama di bawah 12 px. Subjudul sidebar merupakan pengecualian 11 px yang diminta.
- Sebanyak 326 pemeriksaan tampilan/interaksi lolos: aktif/dihapus/kosong, detail AJAX dan tanpa JavaScript, gagal/Coba Lagi, pergantian cepat, dialog aktivitas/penghapusan/cetak, Advanced, pemilih kelas, Reset tambah/edit, dropdown tahun, dan Master draft/published/closed. Pemeriksaan memvalidasi input 15 px pada desktop atau 16 px pada ponsel dengan tinggi minimal 48 px; subjudul sidebar tetap satu baris. Ukuran dasar tiga halaman sebelumnya tetap 16 px.
- Pemeriksaan khusus memastikan kesejajaran input Komponen Biaya/Potongan, kontrol kalender 15 px/48 px, dan fokus keyboard. Identitas panjang dan pembesaran 125%/200% diperiksa melalui emulasi viewport serta device scale; zoom manual browser tidak diotomatisasi.
- Sintaks lima berkas PHP dan `git diff --check` lolos. Audit integritas keuangan sebelum/sesudah pada `db_spp_audit_authorization_20261007` identik. Browser tidak mengirim perubahan data; fixture tahun closed sementara dihapus dan lima sesi uji dibersihkan.
- Perubahan pekerjaan ini hanya pada CSS dan dokumentasi. Tidak ada endpoint, migrasi, perubahan PHP/JavaScript bisnis, commit, atau push. Artefak berada di `C:/Users/Matchaa/.codex/tmp/readability-compact-qa`; server pemeriksaan dihentikan setelah selesai.


## Master Biaya Lain dan Master Penerbitan SPP ? 9 Oktober 2026

### Perubahan

- Kedua halaman memakai penanda `master-fees`/`master-spp` dan skala ringkas terakhir: isi/input 15 px, identitas/tabel 14 px, label/tombol 13 px, keterangan/badge 12 px, judul panel 18 px, angka ringkasan 22 px, serta judul halaman 26?28 px. Input/pencarian pada layar sampai 650 px tetap 16 px; kontrol minimal 48 px dan tombol 44 px.
- Ukuran mencakup formulir tambah/edit biaya, target penerima, daftar siswa, ringkasan nominal, daftar master, tab SPP, tarif, status tahun, dropdown di luar panel, dialog tunggakan, dan pemantauan SPP pada Semua Unit. Ukuran halaman lain dan sidebar dipertahankan.
- Header serta delapan kolom daftar SPP memakai grid yang sama di dalam satu area scroll native. Header tetap terlihat saat menggulir vertikal; scroll horizontal tersedia di dalam daftar ketika kolom membutuhkan ruang tambahan. Pada ponsel, baris menjadi kartu dengan label rincian. Ikon pencarian dan checkbox disejajarkan kembali.
- Tombol penerbitan berada setelah area gulir dan tidak lagi menutupi baris terakhir. Tidak ada listener scroll, efek baru yang mahal, atau perubahan JavaScript aplikasi.
- Handler/model PHP kedua master identik dengan salinan awal pekerjaan. Nama bidang, nilai, CSRF, tujuan/metode formulir, hak akses, perhitungan, dan format cetak/ekspor dipertahankan. Tidak ada endpoint, migrasi, commit, atau push.

### Verifikasi

- Baseline delapan tampilan (dua halaman ? empat unit) sebelum/sesudah memiliki teks, angka, bidang formulir, dan tujuan/metode formulir yang identik. Token acak dinormalisasi.
- Pemeriksaan 64 kombinasi dua halaman, empat unit, dua tema, dan lebar 1600/1280/768/390 px lolos tanpa luapan horizontal halaman, galat JavaScript, atau teks utama di bawah 12 px.
- 81 pemeriksaan interaksi/tata letak lolos: target siswa, pencarian/hasil kosong, pilihan yang ditampilkan, pembersihan pilihan, pilihan tersimpan saat pencarian, pratinjau nominal, scroll native, kesejajaran kolom, header tetap, baris terakhir, tombol penerbitan, tinggi/font kontrol, tab tarif/status, navigasi keyboard, tahun draft tanpa penempatan, dan tampilan tanpa JavaScript.
- 16 pemeriksaan tambahan mencakup dropdown, formulir edit biaya, identitas panjang/nominal besar, dialog tunggakan, tema gelap, serta emulasi 125%/200% melalui viewport/device scale. Ini bukan pengujian zoom manual browser.
- Sintaks tiga berkas PHP serta `git diff --check` lolos. Audit integritas keuangan sebelum/sesudah pada salinan `db_spp_audit_authorization_20261007` identik; seluruh pemeriksaan audit bernilai OK. Browser tidak mengirim POST perubahan data. Pengujian tidak menjalankan penerbitan, penutupan tahun, atau penghapusan tagihan.
- Artefak pemeriksaan berada di `C:/Users/Matchaa/.codex/tmp/master-readability-qa`. Empat sesi uji dibersihkan; server lokal khusus pemeriksaan dihentikan. Seluruh perubahan lokal sebelumnya dipertahankan.


## Master Kelas/Rombel ? 9 Oktober 2026

Penanda `master-classes` menerapkan skala ringkas yang sama dengan Master Biaya Lain/SPP: 15/14/13/12 px untuk isi/tabel/label/keterangan, judul panel 18 px, angka 22 px, judul halaman 26?28 px; input ponsel tetap 16 px. Tinggi kontrol 48 px dan tindakan 44 px berlaku pada tab, dropdown, formulir, tabel, pagination, pemberitahuan, serta hasil promosi. Teks aktif tab dan Kartu/Tabel diperjelas sesuai tema, dengan kontras minimal 4,5. Hasil pemeriksaan lengkap tercatat di `MASTER_WORKSPACE_REDESIGN.md`, bagian Penyesuaian Dua Tab Master Kelas/Rombel.


### Penyesuaian Kelola Rombel dan PSB

Daftar per kelas, filter di kanan, pintasan tambah, dan pemantauan PSB mengikuti token tipografi `master-classes`. Panel Tujuan dan Ringkasan memakai judul 16 px, rincian 14 px, keterangan 12 px, dengan padding lebih ringkas. Caption Rombel Asal dibuat satu baris tanpa jumlah dalam tanda kurung. PSB hanya pemantauan sesuai pilihan pengguna. Rincian kontrak dan hasil pemeriksaan ada pada bagian Kelola Rombel per Kelas dan Pemantauan PSB di `MASTER_WORKSPACE_REDESIGN.md`.


### Kartu Kompak dan Penempatan PSB

Kartu Kelola Rombel mempunyai padding 13 px dan empat baris ringkas, dengan siswa aktif/histori bersebelahan serta tombol minimum 44 px. Padding dan arah flex tabel responsif tidak lagi diterapkan pada kartu. Ukuran kartu normal pada pemeriksaan 1600/1280/768/390 px adalah 166-170 px. Daftar tidak memakai pagination; scroll tetap native. Formulir penempatan PSB menggunakan token input 15 px pada desktop dan 16 px pada ponsel, label 13 px, serta keterangan 12 px. Rincian perilaku dan pengujian ada pada bagian Penempatan Awal PSB dan Kelola Rombel Tanpa Pagination dalam `MASTER_WORKSPACE_REDESIGN.md`.
