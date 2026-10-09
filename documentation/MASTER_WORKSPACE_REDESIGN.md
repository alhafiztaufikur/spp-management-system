# Redesign Data Siswa, Sidebar, dan Master Daftar Ulang

Tanggal pemeriksaan: 8 Oktober 2026.

## Perubahan

- Data Siswa memakai empat kartu informasi dan enam bagian formulir sesuai referensi. Tarif pada header adalah pratinjau formulir, sedangkan jumlah dan status menjelaskan filter daftar.
- Bidang Advanced tetap terlihat. Saat mati, bidang memakai `readonly` agar nilai lama tetap terkirim dan pemeriksaan server tetap berjalan. Saat aktif, bidang dapat diedit. Pembatalan perubahan mengembalikan nilai awal.
- Formulir tetap memakai nama bidang, ID, CSRF, sumber tarif, dan tujuan POST yang sama. Daftar, filter, ekspor, serta tindakan siswa tetap memakai sumber dan alur lama.
- Sidebar menggunakan warna pekat pada kedua tema: SD hijau, SMP biru, SMA merah, dan Semua Unit ungu. Variabel warna dibatasi pada sidebar. Identitas unit navigasi mengikuti unit operasional, termasuk ketika cakupan laporan berbeda.
- Master Daftar Ulang memakai kartu tingkat dengan angka Romawi I–XII, estimasi per kelas, dan estimasi keseluruhan. Estimasi JavaScript hanya mengalikan tarif formulir dengan jumlah siswa aktif; server tetap menentukan hasil penyimpanan.
- Tampilan draft, published, closed, dan pemantauan Semua Unit mempertahankan tindakan dan penguncian sebelumnya. Tidak ada tombol penyamaan tarif atau endpoint baru.

## Hasil pemeriksaan

Database pengujian: `db_spp_audit_authorization_20261007`, salinan database. Database aktif tidak digunakan untuk mutasi.

| Pemeriksaan | Hasil |
|---|---|
| Dua halaman × empat cakupan × dua tema × tiga lebar (1600/900/390 px) | 48 kombinasi lolos, tanpa luapan halaman horizontal |
| Jumlah siswa, tarif tersimpan, jumlah siswa per tingkat, estimasi keseluruhan | Cocok dengan query sumber pada salinan database |
| Advanced aktif/mati, bidang terkunci, hitungan potongan, konfirmasi lanjut/batal | Lolos |
| Pengiriman nilai lama saat edit dengan Advanced mati | Nilai bidang tetap ada dalam FormData dan tidak berubah |
| Pengajuan formulir edit tidak valid pada SD/SMP/SMA | Isian dan status Advanced pulih setelah rollback |
| Dropdown kelas, pemilihan, Escape, serta posisi opsi pada seluruh lebar; Space pada Advanced | Lolos |
| Status filter ganda dan hasil pencarian kosong | Keterangan dan jumlah mengikuti hasil |
| Master draft, published, dan closed | Tindakan sesuai status; tarif closed terkunci |
| Pergantian SD/SMP/SMA/Semua Unit | Pilihan dan warna navigasi sesuai |
| Admin/Kasir/Bendahara | Akses dan menu mengikuti aturan lama |
| Tanpa JavaScript | Bidang Advanced terlihat terkunci; nilai dasar dan pemilih unit native tersedia |
| Sintaks PHP, JavaScript, dan `git diff --check` | Lolos |
| Audit integritas keuangan sebelum/sesudah | Output identik; seluruh pemeriksaan integritas tetap nol |

Pengujian Master yang sudah tersedia juga lolos:

- `master_du_get_read_only_http_test.php`: GET tidak membuat tahun ajaran; CSRF salah ditolak; penerbitan gagal rollback.
- `master_du_post_year_target_test.php`: POST menargetkan tahun formulir dan tidak mengubah tahun pada URL.
- `student_tariff_consistency_test.php`: perlindungan Advanced dan pendeteksian perubahan komponen. Ekspektasi Pangkal yang sudah usang diperbarui menjadi PSB/Daftar Ulang.

## Mengulang pemeriksaan browser

Gunakan server PHP lokal yang diarahkan ke salinan `db_spp_audit_*`, dengan:

- `SPP_DB_NAME`: nama salinan database.
- `SPP_TEST_ALLOW_MUTATION=1`.
- `SPP_HTTP_BASE`: URL server pengujian lokal.
- `SPP_QA_DIR`: direktori artefak di luar repositori.
- `SPP_PLAYWRIGHT_CORE`: lokasi modul Playwright yang tersedia.

Jalankan `tests/master_workspace_fixture.php`, kemudian `tests/master_workspace_browser_test.js`. Fixture membuat sesi terpisah serta tiga tahun closed sementara untuk memeriksa penguncian. Selalu jalankan `tests/master_workspace_fixture.php --cleanup` setelah pemeriksaan, termasuk jika tes gagal.

Screenshot dan output integritas pemeriksaan ini tersimpan di `C:/Users/Matchaa/.codex/tmp/master-workspace-qa`. Berkas sesi fixture tidak dimasukkan ke repositori.

Tidak ada migrasi, perubahan perhitungan keuangan, commit, atau push.

## Perapian lanjutan

- Padding header, kartu tarif, dan bagian penerbitan ditambah; latar persegi dari aturan header lama dihilangkan.
- Ikon ringkasan sejajar dengan angka. Tinggi baris label disamakan agar angka antarkartu tetap sejajar ketika label membungkus.
- Sidebar SMA dibuat sedikit lebih terang, dengan teks dan ikon yang tetap kontras. Palet unit lain tidak diubah.
- Pemeriksaan awal 24 kombinasi cakupan/tema/lebar lolos. Setelah penyamaan tinggi label, 18 kombinasi SD/SMP/SMA diverifikasi ulang: pusat ikon dan angka sejajar, angka antarkartu sejajar pada desktop/tablet, dan tidak ada luapan halaman horizontal.
- Perubahan lanjutan hanya pada CSS dan pemeriksaan tampilan; tidak mengubah data atau alur formulir.

## Perapian kartu dan Reset Formulir

- Header Data Siswa memakai baris terpisah untuk judul, ikon/angka, dan keterangan. Kartu tarif lebih lebar; nominal rupiah tetap satu baris. Pada layar lebih kecil, kartu berpindah ke bawah judul dan menjadi dua kolom.
- Ringkasan Siswa Aktif, Tagihan Terbit, dan Terbayar memakai baris label yang sama, dengan ikon sejajar angka. Kartu Terbayar mendapat ruang lebih besar.
- Bagian Potongan memakai padding tambahan dan tinggi label yang sama pada desktop, sehingga ketiga input sejajar. Hasil perhitungan diberi latar pembeda dan tetap terkunci.
- Reset Formulir mengosongkan seluruh kolom dan mengembalikan mode Edit menjadi Tambah Siswa, sesuai pilihan pengguna. Advanced dimatikan, pilihan kelas/bulan dikosongkan, pratinjau tarif kembali nol, dan identitas edit dilepas. Token CSRF tetap dipertahankan.
- Reset hanya memengaruhi formulir yang belum disimpan. Tidak ada penghapusan atau perubahan siswa, tagihan, maupun pembayaran di database. Filter daftar dipertahankan; tautan cadangan memakai `reset_form=1` agar Reset juga bekerja tanpa JavaScript dan setelah reload.
- Pengujian browser ditambah untuk memeriksa kekosongan seluruh input, mode Tambah setelah Reset dari Edit, CSRF yang tetap sama, pemulihan setelah reload, dan siswa tersimpan yang tetap utuh.
- Pemeriksaan akhir: 48 kombinasi cakupan/tema/lebar lolos, termasuk Reset dari Tambah/Edit, Advanced, pemulihan validasi, status tahun, akses peran, serta Reset lewat keyboard tanpa JavaScript. Pada lebar 1100/1250/1280/1366/1450 px, kolom Potongan dan filter juga diperiksa; tidak ada luapan halaman horizontal.
- Pemeriksaan sintaks PHP/JavaScript, proteksi Advanced, dan `git diff --check` lolos. Audit integritas keuangan sebelum/sesudah identik pada salinan database; fixture tahun sementara telah dibersihkan.

## Master Biaya Lain

- Header mengikuti referensi: judul/deskripsi, Biaya Aktif, Rombel Aktif, Siswa Aktif, dan ilustrasi SVG sederhana. Semua angka tetap berasal dari query lama.
- Formulir tambah/edit biaya dan penerbitan tagihan ditempatkan berdampingan, dengan tinggi panel dan tombol bawah sejajar. Pada tablet/ponsel, panel bertumpuk.
- Nominal memakai awalan Rp dan status aktif memakai sakelar yang dapat dioperasikan dengan keyboard. Nama input, nilai, tujuan POST, CSRF, dan kunci penerbitan tetap sama.
- Pratinjau jumlah siswa/nominal, empat jenis target, pencarian dan pemilihan siswa, serta seluruh tindakan daftar tetap memakai alur yang tersedia. Tautan Lihat Daftar Biaya menuju tabel pada halaman yang sama.
- CSS dibatasi pada `.fee-workspace`; warna mengikuti unit dan tema halaman. Tidak ada perubahan pada proses POST, query sumber, JavaScript penerbitan, atau perhitungan.
- Pemeriksaan 24 kombinasi (SD/SMP/SMA/Semua Unit, terang/gelap, 1600/900/390 px) lolos tanpa luapan horizontal. Tampilan edit, kontrak formulir, empat target, pencarian, pratinjau, sakelar keyboard, dan formulir tanpa JavaScript diperiksa.
- Pengujian hanya membaca salinan database; tidak ada POST mutasi. Audit integritas sebelum/sesudah identik. Sintaks PHP dan pemeriksaan diff lolos. Artefak pemeriksaan berada di direktori QA yang disebutkan di atas.

### Perapian Pilih Siswa

- Daftar penerima memakai satu kolom, avatar inisial pada desktop, identitas yang dapat membungkus, badge kelas, serta penghitung pilihan. Pencarian dan tombol berada pada baris terpisah agar tidak memaksa panel melebar.
- Aturan grid lama yang memaksakan ukuran minimum kolom diatasi khusus pada halaman ini. Panel Tambah Master Biaya tidak ikut memanjang ketika target Pilih Siswa dibuka.
- Scroll memakai daftar native dengan tinggi terbatas dan pembatasan layout/paint. Kartu tidak memakai animasi atau bayangan hover. Data pencarian dan elemen kartu dicache sekali; pengetikan dikelompokkan per animation frame, tanpa pekerjaan JavaScript pada scroll daftar.
- Pilihan tetap tersimpan saat pencarian berubah. Pilih yang tampil selalu menyelesaikan pencarian terbaru sebelum menandai siswa, termasuk saat diklik sebelum animation frame pencarian berjalan. Kontrak checkbox POST, token, sumber data, validasi, dan penerbitan tetap sama.
- Pemeriksaan 24 kombinasi Pilih Siswa (SD/SMP/SMA, terang/gelap, 1600/1280/900/390 px) lolos: tanpa luapan horizontal, pilihan/pencarian, FormData, keyboard, scroll native, dan pergantian target. PageDown serta pencarian/pemilihan pada frame yang sama juga lolos.
- Dalam simulasi 90 frame scroll pada Chrome headless lokal, p95 jeda frame tercatat 13,8 ms. Angka ini merupakan hasil lingkungan pengujian, bukan jaminan FPS pada semua perangkat.
- Sintaks PHP/JavaScript dan audit integritas keuangan lolos; hasil audit sebelum/sesudah identik. Seluruh pemeriksaan menggunakan salinan database tanpa POST mutasi.

## Master Penerbitan SPP

Tanggal pemeriksaan: 9 Oktober 2026.

- Header, pemilih tahun, empat kartu ringkasan, tab Tarif SPP/Pilih Siswa/Status Tahun, daftar siswa, panel tarif tersimpan, dan tindakan tahun mengikuti susunan referensi. Ikon memakai SVG sederhana; warna mengikuti unit dan tema.
- Ringkasan siswa aktif, sudah diterbitkan, dan belum diterbitkan memakai data penempatan serta jumlah tagihan yang sudah tersedia. Total tagihan dan tagihan belum bayar memakai sumber lama. Status baris tetap jumlah bulan terbit atau Belum terbit; siswa arsip tetap diberi keterangan.
- Pencarian, pilihan rombel ganda, pilihan siswa, bulan mulai tagihan per siswa, potongan, tarif efektif, serta seluruh kontrak formulir dipertahankan. Formulir simpan tarif, penerbitan, pembatalan siswa keluar, tutup tahun, dan buka kembali memakai aksi, nama bidang, CSRF, dan tujuan tahun yang sama.
- Handler POST, pemeriksaan akses, transaksi database, query sumber, dan perhitungan sebelum markup dibandingkan dengan salinan sebelum redesign dan identik. Konfirmasi serta pemeriksaan tunggakan sebelumnya tetap memakai alur yang tersedia.
- Tab mendukung keyboard dan perubahan fragmen URL. Tanpa JavaScript, seluruh bagian tetap tampil dan kontrol bulan memakai select native. Tabel memakai scroll internal pada layar menengah; ponsel memakai kartu vertikal. Panel tindakan memakai latar solid agar tulisan daftar tidak terlihat menembusnya.
- Semua Unit tetap menggunakan halaman pemantauan tarif per unit/tahun yang sudah tersedia, dengan header dan tabel yang dirapikan. Tidak ditambahkan formulir perubahan.
- Pemeriksaan browser lolos pada 32 kombinasi: SD/SMP/SMA/Semua Unit, terang/gelap, serta lebar 1600/1280/900/390 px. Tidak ada luapan horizontal halaman atau galat JavaScript. Angka, tarif, checkbox POST, CSRF, tahun tujuan, pencarian, pilihan yang tersembunyi oleh pencarian, filter rombel, dropdown bulan, tab, dan keyboard diperiksa pada unit operasional; Semua Unit diperiksa sebagai pemantauan.
- Tahun draft kosong dan fixture closed diperiksa pada salinan database: angka nol, daftar kosong, tarif terkunci, serta kontrak buka kembali sesuai status. Fixture sementara sudah dibersihkan.
- Pengujian `master_spp_get_read_only_test.php` (tahun kosong dan tahun tersedia) serta `master_spp_post_year_target_test.php` lolos pada salinan database. Pemeriksaan sintaks PHP/JavaScript dan `git diff --check` lolos. Audit integritas keuangan sebelum/sesudah identik.
- Artefak browser dan audit tersimpan di direktori QA di luar repositori yang disebutkan di atas. Tidak ada migrasi, perubahan perhitungan, commit, atau push.

## Master Kelas/Rombel

Tanggal pemeriksaan: 9 Oktober 2026.

- Empat kartu ringkasan memakai data rombel yang sudah dimuat: Total Rombel, Siswa Aktif, Histori penempatan, serta Aktif/Nonaktif termasuk placeholder. Angka daftar yang mengikuti filter tetap terpisah dari ringkasan keseluruhan.
- Tambah/Edit Rombel dan konteks Proses Tahun Ajaran berada di atas; Daftar Kelas/Rombel dan Peserta Tahun Ajaran berada di bawah. Tahun tujuan tetap dihitung sistem; tingkat akhir tetap memakai tahun kelulusan asal, tanpa membuat penempatan tahun berikutnya.
- Kartu menjadi tampilan awal. Pilihan Kartu/Tabel disimpan secara lokal dan memakai satu tabel, kumpulan baris, serta formulir tindakan yang sama. Tanpa JavaScript, Kartu tetap tersedia. Ukuran halaman 10/25/50, urutan, pencarian, filter ganda, hasil, dan pagination memakai sumber lama.
- Daftar rombel dan peserta memakai scroll native dengan tinggi terbatas. Kartu diberi padding dan tinggi otomatis; nama siswa panjang membungkus. Tidak ada handler scroll atau efek hover berat yang ditambahkan. Kontainer daftar dapat difokuskan dengan keyboard.
- Checkbox peserta, rombel tujuan, pilihan yang tersembunyi oleh pencarian, Pilih yang Ditampilkan, Pilih Semua, Kosongkan, serta tombol Naikkan/Luluskan memakai JavaScript promosi yang tersedia. ID, atribut, input penempatan asal, CSRF, aksi, dan konteks tahun tetap sama.
- Placeholder tetap dikelola sistem. Pengelolaan massal rombel, konfirmasi, perlindungan kelas terpakai, pemberitahuan siswa ditahan, hasil batch, dan pemisahan data unit dipertahankan. Semua Unit tidak merender formulir perubahan maupun tautan Edit, termasuk tanpa JavaScript; pemeriksaan POST lama tetap berlaku.
- Handler, validasi, query, dan model pagination sebelum markup dibandingkan dengan salinan sebelum redesign dan identik. `assets/js/app.js` serta helper promosi tidak diubah; JavaScript baru hanya mengganti tampilan Kartu/Tabel.
- Pemeriksaan browser mencakup 64 kombinasi tampilan: Kartu/Tabel, SD/SMP/SMA/Semua Unit, terang/gelap, dan lebar 1600/1280/900/390 px. Angka sumber, urutan ID rombel, kontrak pilihan peserta, dropdown, kondisi kosong, dan pembatasan unit sesuai. Tidak ditemukan ID ganda, galat JavaScript, atau luapan halaman horizontal.
- Pemeriksaan tambahan mencocokkan seluruh halaman hasil, OR dalam filter tingkat/status dan AND antarfilter, pilihan pada pagination, edit/Batal, persistensi tampilan, keyboard, scroll peserta, kelulusan pada tiga unit, serta Semua Unit tanpa JavaScript. Uji nama/label panjang dilakukan hanya pada DOM browser, tanpa mengubah database.
- `master_kelas_toggle_guard_test.php` untuk rombel terpakai/kosong, `flexible_promotion_test.php`, dan `flexible_promotion_invalid_test.php` lolos pada salinan database. Mencakup kenaikan parsial, kelulusan, pengiriman berulang, konteks/identitas salah, target lintas unit, dan perlindungan snapshot asal. Data sementara di-rollback dan status rombel uji dipulihkan.
- Sintaks PHP/JavaScript dan `git diff --check` lolos. Audit integritas keuangan sebelum/sesudah identik. Artefak berada di direktori QA di luar repositori; tidak ada endpoint, migrasi, perubahan perhitungan, commit, atau push.


## Penyesuaian Dua Tab Master Kelas/Rombel ? 9 Oktober 2026

### Implementasi

- Header menyediakan Kelola Rombel dan Naik Kelas, dengan SVG dari ikon bersama, warna unit, serta kedua tema. Empat ringkasan tetap memakai sumber dan aturan yang sama, termasuk placeholder. Laporan Umum tidak diubah.
- Kelola Rombel menempatkan Tambah/Edit di kiri dan daftar di kanan. Daftar tetap memakai satu kumpulan baris/formulir untuk Kartu/Tabel, pencarian, filter ganda, ukuran halaman 10/25/50, pagination, placeholder, dan tindakan massal.
- Naik Kelas menempatkan konteks tahun di atas. Mulai 1400 px, rombel asal/filter/jumlah peserta berada di kiri, tabel peserta di tengah, serta tahun/tingkat asal, tahun tujuan, daftar tujuan dan tombol proses di kanan. Daftar tujuan menampilkan tingkat tujuan yang tersedia; pilihan tujuan tetap dilakukan per siswa di tabel. Tablet menempatkan panel asal/tujuan bersebelahan dan peserta di bawah; ponsel menumpuk asal, peserta, lalu tujuan.
- Tahun tujuan tetap otomatis. Tingkat terakhir menampilkan kelulusan, memakai tahun asal, dan tidak menampilkan pemilih rombel tujuan. Tidak ada nilai akhir, kapasitas, syarat baru, pemilihan tujuan massal, endpoint, atau migrasi.
- Semua kontrol peserta tetap berada di satu POST form. Header dan baris berada di satu kontainer scroll native, dengan kolom identik serta header tetap; tombol proses berada di luar daftar gulir. Tidak ditambahkan listener scroll atau efek berat.
- JavaScript mengganti visibilitas bagian tanpa merender ulang formulir. Fragment `#kelola-rombel`/`#naik-kelas` memiliki prioritas, kemudian konteks edit/filter, konteks promosi, dan Kelola Rombel sebagai default. Parameter filter berbentuk array dikenali. Navigasi panah/Home/End mengatur fokus dan atribut tab. Tanpa JavaScript, kedua bagian terlihat dan tautan menuju bagian terkait.
- Preferensi Kartu/Tabel tetap memakai penyimpanan lama. Pilihan siswa/tujuan bertahan saat mengganti tab; penguncian tujuan dan Naikkan/Luluskan memakai JavaScript promosi lama. Seluruh nama/ID/atribut bidang, CSRF, aksi dan kontrak GET/POST tetap sama.
- Penanda `master-classes` memakai skala ringkas: isi/input 15 px, identitas/tabel 14 px, label/tombol 13 px, keterangan/badge 12 px, judul panel 18 px, angka ringkasan 22 px, serta judul halaman 26?28 px. Input sampai 650 px tetap 16 px, kontrol minimal 48 px, tindakan 44 px. Teks aktif tab/Kartu/Tabel memenuhi rasio kontras 4,5 pada delapan kombinasi unit/tema.

### Hasil Pemeriksaan

- Snapshot handler, query, model filter, dan pagination sebelum markup identik dengan awal pekerjaan. Perbandingan 13 tampilan sebelum/sesudah (awal/promosi/kelulusan/edit pada tiga unit, serta Semua Unit) menunjukkan ringkasan, rombel, peserta, nilai/nama/ID/opsi bidang, status disabled, dan tujuan/metode formulir identik.
- 80 kombinasi tampilan lolos: dua tab, empat unit, dua tema, serta lebar 1600/1400/1280/768/390 px. Tidak ditemukan luapan halaman, ID ganda, galat JavaScript, teks utama di bawah 12 px, atau kontrol di bawah ukuran yang ditetapkan.
- 378 pemeriksaan interaksi lolos: tab/fragment/reload, prioritas edit/filter/konteks, Kartu/Tabel/persistensi, pilihan siswa/tujuan bertahan saat berganti tab, penguncian tujuan, OR filter rombel asal, pencarian/hasil kosong, pilihan yang ditampilkan/seluruh peserta/pembersihan, kesejajaran tabel, header tetap, kelulusan pada tiga unit, dropdown/Escape, keyboard, pagination, tanpa JavaScript, dan Semua Unit tanpa formulir perubahan. Browser tidak mengirim POST mutasi.
- 19 pemeriksaan tambahan lolos untuk hasil batch parsial dan alasan gagal (fixture sesi), konteks tidak sah, prioritas parameter array, identitas panjang, popover, tema gelap, dan emulasi 125%/200% melalui viewport/device scale. Ini bukan pengujian zoom manual browser.
- `master_kelas_toggle_guard_test.php` pada rombel terpakai/kosong, `flexible_promotion_test.php`, dan `flexible_promotion_invalid_test.php` lolos pada salinan `db_spp_audit_authorization_20261007`. Mencakup kenaikan bertahap/parsial, kelulusan, replay, tahun asal mendatang, snapshot, sumber yang salah, siswa ditahan, tujuan lintas unit, serta Semua Unit. Fixture promosi di-rollback dan status rombel kosong dipulihkan.
- Sintaks PHP/JavaScript dan `git diff --check` lolos. Audit integritas keuangan sebelum/sesudah identik dengan semua pemeriksaan OK. Lima sesi khusus QA dibersihkan dan server lokal pemeriksaan dihentikan. Artefak di `C:/Users/Matchaa/.codex/tmp/class-tabs-qa`.
- Seluruh perubahan lokal sebelumnya dipertahankan. Tidak ada perubahan perhitungan, handler promosi, hak akses, commit, atau push.


## Kelola Rombel per Kelas dan Pemantauan PSB (lanjutan)

### Perubahan

- Sesuai pilihan pengguna, PSB ditambahkan pada Tingkat Asal sebagai pemantauan saja. GET `source_level=psb` menampilkan calon siswa aktif pada unit yang diizinkan, dengan identitas dan pencarian nama/NIS/NIS Diknas. Tidak ada checkbox proses, rombel tujuan, atau tombol Naikkan/Luluskan pada daftar PSB.
- PSB tidak mempunyai penempatan tahun ajaran; pemantauan membaca daftar aktif saat ini. Tahun asal dinonaktifkan pada pilihan PSB dan diaktifkan kembali ketika memilih tingkat reguler. Semua Unit menyediakan tautan pemantauan PSB tanpa formulir perubahan; tautan juga tersedia tanpa JavaScript.
- Query pemantauan mengenali nilai kelas `0` dan `PSB`; facade siswa pada database ini menampilkan kelas sebagai `PSB`. Identitas unit dipertahankan, termasuk pada Semua Unit. Pada clone pemeriksaan, jumlah cocok dengan relasi master kelas: SD 9, SMP 6, SMA 6, Semua Unit 21.
- Kelola Rombel mengikuti susunan referensi: filter cepat Semua/Aktif/Nonaktif/PSB/tingkat, empat ringkasan yang sama, pintasan Tambah Rombel, daftar per kelas yang dapat dibuka/tutup, serta filter dan pencarian di kanan. Pada layar sempit, filter berada di atas daftar. Formulir tambah tetap tersedia melalui pintasan, terbuka saat edit, dan tersedia secara native tanpa JavaScript.
- Tampilan Per Kelas menjadi default baru. Kartu/Tabel dan preferensi lokal tetap tersedia; ketiga tampilan memakai baris dan formulir tindakan yang sama. Kelompok memuat total rombel/siswa dari seluruh hasil filter, sedangkan baris tetap memakai pagination lama per rombel 10/25/50. Jumlah baris pada halaman dijelaskan; kelompok yang berada di halaman lain menyediakan tautan filter tingkat tersebut.
- Nomor baris, kolom, data, status placeholder, hak tindakan, konfirmasi, serta pengelolaan massal dipertahankan. Tidak ditambahkan urutan, kapasitas, nilai akhir, atau aturan kenaikan baru.
- Panel Tujuan dan Ringkasan diringkas dengan judul 16 px, angka rincian 14 px, serta padding/gap lebih kecil. Rombel Asal memakai caption satu baris `Semua rombel`, sementara jumlah peserta tetap tersedia pada daftar dan ringkasan. Ukuran utama tetap mengikuti skala menu sebelumnya; input ponsel tetap 16 px.
- Scroll native dipertahankan. Smooth scroll global dinonaktifkan khusus halaman ini dan ruang terhadap navigasi tetap disediakan agar pergantian tampilan/fokus tidak tertutup header atau navigasi ponsel.
- Pemeriksaan POST menemukan dependency redirect yang sebelumnya belum dimuat: `filter_build_query()` baru tersedia setelah handler. `filter_choices.php` kini dimuat sebelum handler sehingga pengalihan berhasil maupun gagal dapat berjalan. Tidak ada perubahan validasi atau perhitungan.

### Verifikasi

- Baseline 13 tampilan reguler sebelum/sesudah cocok untuk jumlah, rombel, peserta, nilai/nama/opsi bidang, disabled, dan kontrak formulir. Penambahan opsi PSB, ID label filter baru, serta perubahan caption Rombel Asal merupakan perubahan tampilan yang disengaja.
- 120 kombinasi unit/tema/viewport untuk Kelola, Naik Kelas, dan PSB lolos tanpa luapan horizontal, ID ganda, galat JavaScript, teks di bawah standar, atau kontrol di bawah tinggi minimum. Lebar yang diperiksa: 1600/1400/1280/768/390 px.
- 351 pemeriksaan interaksi lolos untuk Per Kelas/Kartu/Tabel, baris dan tindakan yang sama, pintasan tambah/edit, filter cepat/ganda, pagination, kelompok native, panel ringkas, caption asal, pilihan peserta saat berganti tab, dropdown PSB, identitas/jumlah/unit PSB terhadap database, pencarian/hasil kosong, tanpa JavaScript, dan Semua Unit.
- Permintaan POST langsung `source_level=psb` serta `0` dengan CSRF yang benar ditolak dan kembali melalui redirect yang benar. Pemantauan PSB tidak membuka kemampuan promosi baru.
- Pengujian perlindungan status rombel terpakai/kosong, promosi fleksibel pada tiga unit, serta kasus promosi tidak sah lolos pada clone `db_spp_audit_authorization_20261007`. Fixture transaksi di-rollback dan status rombel dipulihkan. Audit integritas keuangan sebelum/sesudah identik dengan seluruh pemeriksaan OK.
- Sintaks PHP dan kedua JavaScript terkait serta `git diff --check` lolos. Empat sesi khusus QA dibersihkan; server lokal pemeriksaan dihentikan. Artefak berada di `C:/Users/Matchaa/.codex/tmp/class-manage-refresh-qa`.
- Tidak ada endpoint baru, migrasi, perubahan perhitungan, commit, atau push. Seluruh perubahan lokal sebelumnya dipertahankan.


## Penempatan Awal PSB dan Kelola Rombel Tanpa Pagination

### Kontrak dan Perilaku

- Mengikuti keputusan terbaru pengguna: PSB dapat ditempatkan pada tahun ajaran berjalan, ke kelas awal unit (SD 1, SMP 7, SMA 10). Pengguna memilih siswa dan rombel per siswa; tingkat/tahun ditetapkan server. Semua Unit tetap pemantauan.
- POST `aksi=tempatkan_psb` menggunakan CSRF master kelas, `selected_students[]`, `target_master_kelas_id[NIS]`, `target_tahun_ajaran`, dan konteks kembali PSB. Tidak ada endpoint atau migrasi baru. GET tidak membuat tahun maupun penempatan.
- Helper `includes/psb_placement.php` memeriksa tahun berjalan, akun aktif/peran/unit, identitas PSB aktif non-Legacy, konsistensi rombel asal, ketiadaan penempatan lama, serta tujuan aktif/non-placeholder pada kelas awal unit. Actor, siswa, dan rombel dikunci dengan urutan stabil; kegagalan per siswa menggunakan savepoint dan transaksi dapat diulang pada deadlock.
- Perhitungan tarif, snapshot awal, sinkronisasi Komite, dan Daftar Ulang memakai helper yang sama dengan Data Siswa. Identitas, asal PSB, potongan, komponen biaya, dan pembayaran lama dipertahankan. Tidak ada penerbitan SPP otomatis. Audit memakai aksi update dengan metadata penempatan PSB serta snapshot sebelum/sesudah.
- UI menyediakan pencarian, pilihan yang terlihat/semua/pembersihan, penguncian pemilih tujuan menurut checkbox, ringkasan, konfirmasi identitas/tujuan/tahun, pencegahan submit berulang, dan hasil berhasil/gagal. Pilihan bertahan saat pencarian atau pergantian tab; siswa berhasil keluar dari daftar PSB. Formulir juga dapat digunakan tanpa JavaScript.
- Ketiga tampilan Kelola Rombel merender seluruh hasil filter satu kali. Pagination, pemotongan hasil, pemilih ukuran halaman, serta pesan halaman lain dihapus. Parameter pagination pada tautan lama tidak memotong hasil. Pencarian, OR dalam filter/AND antarfilter, urutan, kelompok dan preferensi tampilan tetap tersedia.
- Kartu memakai tiga kolom desktop, dua tablet, satu ponsel. Padding tabel dipisahkan dari kartu, termasuk override padding/flex global pada ponsel. Informasi siswa aktif dan histori bersebelahan; tindakan tetap 44 px. Kartu normal terukur 166-170 px pada empat viewport yang diperiksa; teks panjang tetap dapat menambah tinggi.
- Pengelolaan Rombel mempunyai padding 16 px, gap konsisten, dan tombol bertumpuk pada ponsel. Nonaktifkan Rombel Kosong bekerja untuk semua rombel reguler kosong pada satu unit, termasuk yang di luar filter, dengan pemeriksaan akun/unit dan penguncian. PSB, placeholder, dan rombel dengan siswa aktif dilindungi; histori tidak dihapus. PSB tidak dapat diedit/dinonaktifkan individual dan PSB yang sudah nonaktif tetap dapat diaktifkan.

### Hasil Pengujian

- `tests/psb_placement_test.php` lolos pada tiga unit dengan NIS sama dan berawalan nol: penempatan berbeda per siswa, partial batch, snapshot/tagihan setara alur Data Siswa, perlindungan SPP PSB, pembayaran lama identik, audit, tanpa penerbitan SPP, replay, target kosong/salah tingkat/lintas unit/nonaktif/placeholder, penempatan lama, tahun mendatang, akun/siswa nonaktif, serta Semua Unit.
- Uji dua proses dengan akun berbeda pada siswa yang sama menghasilkan satu keberhasilan dan satu kegagalan, tepat satu penempatan dan satu audit. Uji penempatan melawan nonaktif massal tidak menghasilkan siswa pada target nonaktif. Nonaktif massal mempertahankan PSB kosong, placeholder, rombel terpakai dan histori siswa arsip; status rombel semula dipulihkan.
- Pengujian browser mengirim POST nyata hanya untuk fixture clone pada tiga unit, dengan konfirmasi, pencarian, pilihan tersembunyi, perpindahan tab, hasil parsial, redirect sukses, dan replay. Penempatan native tanpa JavaScript juga berhasil. Semua Unit tidak menyediakan formulir perubahan; seluruh hasil tampil pada Per Kelas/Kartu/Tabel walaupun URL memuat parameter pagination lama.
- Permintaan HTTP langsung untuk edit/nonaktif PSB, tahun penempatan salah, dan perubahan Semua Unit ditolak. Status penolakan Semua Unit mempertahankan 409 dari guard yang tersedia.
- 128 kombinasi empat unit, dua tema, empat viewport (1600/1280/768/390 px), dan empat tampilan lolos tanpa luapan halaman, ID ganda, galat JavaScript, teks di bawah standar, maupun kontrol pagination. Kartu normal tetap 166-170 px pada desktop/tablet/ponsel.
- Pengujian snapshot kelas, promosi fleksibel, kasus promosi tidak sah, serta perlindungan toggle rombel terpakai/kosong lolos. Fixture toggle kini memilih rombel reguler sesuai perlindungan PSB yang baru.
- Sintaks PHP/JavaScript dan `git diff --check` lolos. Semua mutasi pengujian berada pada `db_spp_audit_authorization_20261007`; data fixture beserta tagihan, penempatan, audit, kelas dan sesi dibersihkan. Audit integritas keuangan setelah pembersihan identik dengan kondisi awal, seluruh pemeriksaan OK. Server khusus QA dihentikan.
- Artefak berada di `C:/Users/Matchaa/.codex/tmp/psb-placement-qa`. Seluruh perubahan lokal sebelumnya dipertahankan. Tidak ada perubahan rumus keuangan, commit, atau push.
