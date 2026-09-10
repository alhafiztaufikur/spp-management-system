# Riwayat Perubahan AI SistemSPP

File ini mencatat perubahan proyek secara reverse chronological. Baca [PROJECT_CONTEXT.md](./PROJECT_CONTEXT.md) terlebih dahulu untuk memahami arsitektur, aturan bisnis, dan kewajiban dokumentasi.

## Aturan Pencatatan

- Tambahkan entri terbaru tepat di bawah bagian ini.
- Gunakan tanggal lokal proyek (`Asia/Jakarta`) dengan format `YYYY-MM-DD`.
- Satu entri boleh mencakup satu paket perubahan yang dikerjakan dan diverifikasi bersama.
- Sebutkan AI/aktor, tujuan, perubahan perilaku, database/migrasi, kompatibilitas, dan verifikasi.
- Tulis `Tidak ada` bila suatu bagian memang tidak memiliki perubahan.
- Jangan mencantumkan data siswa nyata, password, token, cookie, atau secret.
- Jangan menghapus atau menulis ulang entri lama. Tambahkan entri koreksi bila diperlukan.
- Perubahan implementasi dan entri changelog wajib masuk commit yang sama.

## 2026-09-10 - Rekap Riwayat Tagihan per Siswa

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan Riwayat Tagihan ketika laporan difilter berdasarkan rombel atau tingkat tanpa mengubah sumber dan nominal tagihan.

**Perubahan fitur dan perilaku:**

- Filter rombel dan tingkat menampilkan satu baris per siswa dengan total tagihan, terbayar, sisa, serta seluruh rincian yang lolos filter.
- Pagination pada mode kelompok menghitung siswa. Pemilihan satu siswa melalui NIS/NIS Diknas tetap memakai tabel detail satu tagihan per baris.
- Komponen memakai urutan bisnis tetap dan SPP memakai kode periode `YYYY-MM`, sehingga bulan tampil kronologis dalam kalender tahun ajaran.
- Cetak, PDF, dan Excel memakai representasi kelompok yang sama, sementara total laporan tetap dihitung dari data tagihan datar.
- Tampilan kelompok dibuat responsif untuk desktop, tablet, ponsel, serta mode terang dan gelap.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, transaksi, atau nominal tagihan.

**Kompatibilitas dan data lama:**

- Filter Semua Kelas dan pencarian satu siswa mempertahankan format detail sebelumnya.
- Data historis hanya dibaca dan diurutkan; tidak ada backfill atau perubahan snapshot.

**Verifikasi:**

- PHP lint seluruh 59 file, pemeriksaan sintaks `assets/js/app.js`, dan `git diff --check` lulus.
- Unit test pengelompokan, total, pemilihan mode, pagination siswa, dan urutan SPP lulus.
- Integration test database lokal secara read-only membuktikan jumlah rincian dan seluruh total tidak berubah setelah pengelompokan.
- Smoke test render HTML web dan cetak membuktikan mode kelompok, label pagination siswa, versi stylesheet, dan kolom ekspor tersedia.
- `modular_reports_test.php` tidak dapat diselesaikan karena database lokal tidak memiliki enam placeholder kelas aktif yang menjadi prasyarat test lama. Pengujian visual interaktif tidak dijalankan karena browser pengujian tidak tersedia.

## 2026-09-10 - Popup Peringatan Status SPP

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memberi peringatan singkat dan mudah dipahami sebelum kasir mencoba membayar SPP yang terblokir.

**Perubahan fitur dan perilaku:**

- Input dan Edit Pembayaran memeriksa status SPP terbaru melalui endpoint read-only setelah siswa serta bulan/tahun lengkap dipilih.
- Popup hanya muncul untuk penghalang pembayaran, termasuk tunggakan pertama, periode lunas, pembayaran lama sebagian, tarif kosong, siswa belum memenuhi syarat, nominal tidak penuh, dan proteksi perubahan transaksi lama.
- Kolom SPP dikunci saat pemeriksaan atau saat terblokir. Pembayaran komponen lain tetap dapat disimpan bila SPP tidak diisi.
- Endpoint dan proses simpan memakai layanan status yang sama; penolakan backend setelah perubahan data bersamaan dikembalikan sebagai popup yang seragam.
- Dialog mendukung mode terang/gelap, layar kecil, keyboard, pengembalian fokus, dan reduced motion.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, transaksi, atau nominal historis.

**Kompatibilitas dan data lama:**

- Pembayaran legacy sebagian tidak ditambah sebagai cicilan baru; kasir diarahkan untuk mengoreksi transaksi lama.
- Aturan SPP penuh, urutan lintas tahun ajaran, serta pengecualian penempatan `pindah`/`lulus` tetap dipertahankan.

**Verifikasi:**

- PHP lint seluruh 56 file dan pemeriksaan sintaks `assets/js/app.js` lulus.
- `spp_payment_status_test.php`, `spp_sequence_test.php`, `class_snapshot_test.php`, dan `student_tariff_consistency_test.php` lulus.
- Endpoint tanpa sesi mengembalikan HTTP 401 dengan JSON singkat dan header `no-store`.
- Integration test mutasi tidak dijalankan karena database disposable dan kredensial test tidak tersedia. Smoke test visual interaktif tidak dijalankan karena browser kontrol tidak tersedia pada sesi verifikasi.

## 2026-09-10 - Proteksi Konsistensi Tarif Siswa dan Tagihan

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mencegah edit tarif siswa dilaporkan berhasil ketika tarif tidak tersimpan atau snapshot tagihan tahun berjalan tidak ikut diproses.

**Perubahan fitur dan perilaku:**

- Perubahan field Advance ditolak bila switch Advance tidak aktif; browser juga meminta konfirmasi sebelum membuang perubahan panel.
- Sinkronisasi kelas dipisahkan dari tarif. Kelas penempatan berbayar tetap menjadi histori, tetapi tidak lagi menghentikan sinkronisasi komponen lain.
- SPP, komponen tahunan, dan Daftar Ulang dikunci per komponen yang sudah dibayar. Tarif master baru tetap tersimpan untuk penerbitan berikutnya.
- Snapshot tanpa pembayaran direkonsiliasi otomatis saat siswa disimpan, diverifikasi sebelum commit, dan hasilnya dicatat dalam JSON audit siswa.
- Alert membedakan tidak ada perubahan, sinkronisasi, perbaikan otomatis, tarif terkunci, dan kelas historis yang dipertahankan.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi database.

**Kompatibilitas dan data lama:**

- Pembayaran, nominal, dan kelas historis tidak diubah. Pembayaran legacy pada tabel `bayar` ikut diperiksa sebelum snapshot dianggap aman untuk diperbarui.
- Anomali lama diperbaiki hanya ketika siswa terkait disimpan dan hanya untuk komponen tanpa pembayaran.

**Verifikasi:**

- PHP lint pada 52 file dan pemeriksaan sintaks `assets/js/app.js` serta skrip inline Data Siswa lulus.
- `php tests/student_tariff_consistency_test.php` dan `php tests/class_snapshot_test.php` lulus.
- Simulasi rekonsiliasi Hafizz dijalankan dalam transaksi rollback: kelas historis tetap, sedangkan komponen tanpa pembayaran tersinkron.
- Test lain lulus, kecuali `modular_reports_test.php` yang terhenti karena database lokal tidak memiliki enam placeholder kelas aktif; integration test pembayaran tetap melewati dirinya karena database disposable dan kredensial test tidak disediakan.
- Endpoint Data Siswa merespons redirect autentikasi normal. Smoke test interaktif tidak dijalankan karena browser kontrol tidak tersedia pada sesi verifikasi.

**Catatan tindak lanjut:**

- Test suite database penuh tetap harus dijalankan pada database disposable yang memenuhi seluruh fixture laporan.

## 2026-09-09 - Urutan SPP Penuh Lintas Tahun Ajaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menutup celah ketika SPP Juli tahun ajaran baru dapat dibayar walaupun ada tunggakan pada penempatan aktif tahun ajaran sebelumnya.

**Perubahan fitur dan perilaku:**

- SPP tetap wajib dibayar penuh satu kali pada setiap periode.
- Validasi backend dan petunjuk form kini menyusun urutan dari seluruh `siswa_tahun_ajaran` berstatus `aktif`; Juli tidak lagi otomatis dianggap bebas dari prasyarat histori siswa.
- Tarif tunggakan memakai `spp_perbulan_snapshot` dari tahun ajaran asal, sehingga perubahan tarif siswa saat ini tidak mengubah kewajiban periode lama.
- Penempatan `pindah` dan `lulus`, waktu sebelum penempatan aktif pertama, serta jeda tanpa penempatan aktif tidak menjadi utang SPP otomatis.
- Edit atau hapus periode prasyarat ditolak bila menyebabkan pembayaran pada periode sesudahnya, termasuk lintas tahun ajaran, menjadi bolong.

**Database dan migrasi:**

- Tidak ada perubahan schema, migrasi, backfill, maupun perubahan nominal transaksi lama.

**Kompatibilitas dan data lama:**

- Pembayaran legacy tetap dihitung bila periodenya cocok; sistem tidak mencoba merekonstruksi kewajiban sebelum penempatan aktif yang tercatat.
- Test cicilan SPP yang tidak lagi sesuai diganti dengan test urutan SPP penuh lintas tahun ajaran.

**Verifikasi:**

- PHP lint pada file yang diubah dan `node --check assets/js/app.js` lulus.
- `php tests/spp_sequence_test.php` lulus tanpa database.
- Test HTTP integrasi kini menolak berjalan tanpa penanda database disposable eksplisit.

## 2026-08-20 - Pemulihan Rekap Pembayaran per Kelas

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengembalikan halaman mandiri rekap pembayaran per kelas dari riwayat Git tanpa menghapus sistem Laporan Global yang baru.

**Perubahan fitur dan perilaku:**

- Memulihkan versi penuh terakhir `laporan/rekap_kelas.php` dari commit `a3497e2`, sebelum file tersebut diganti menjadi redirect pada commit `75e6b4f`.
- Mengembalikan menu `Rekap per Kelas` untuk admin dan bendahara, termasuk status menu aktif saat membuka detail siswa.
- Rekap lama kembali menyediakan filter kelas 1–6, bulan, tahun, pencarian siswa, ringkasan, tabel komponen pembayaran, tampilan mobile, detail siswa, dan cetak browser.
- Laporan Global dan template Per Item tetap tersedia sebagai fitur terpisah.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi database.

**Kompatibilitas dan data lama:**

- Halaman memakai kolom tingkat legacy `siswa.KELAS` yang tetap dipertahankan pada schema saat ini, sehingga siswa dari seluruh rombel pada tingkat yang sama dirangkum bersama.
- URL lama `rekap_kelas.php?kelas={1-6}&bulan={01-12}&tahun={YYYY}&q={pencarian}` kembali merender halaman dan tidak lagi mengalihkan ke Laporan Global.

**Verifikasi:**

- Lint PHP, pemeriksaan HTTP untuk halaman dan filter, pembandingan sumber dengan blob historis, `node --check`, serta `git diff --check` dijalankan setelah pemulihan.

**Catatan tindak lanjut:**

- Rekap mandiri mempertahankan konsep tingkat kelas 1–6 dari versi lama; laporan per rombel seperti 1A/1B tetap tersedia melalui Laporan Global.

## 2026-08-20 - Perombakan Dashboard menjadi Closing Harian

**AI/Aktor:** Antigravity, bersama pemilik proyek

**Tujuan:** Merombak keseluruhan antarmuka Dashboard agar berfokus 100% pada *closing harian* (rekap penerimaan uang hari ini) dan menyembunyikan metrik/statistik *all-time* (global) yang kurang relevan bagi operasional kasir.

**Perubahan Perilaku / Kode:**
- **`dashboard.php`**: Dihapus kueri lama yang meload metrik *all-time* (seperti total siswa, total transaksi keseluruhan). Mengimpor `includes/reports.php` dan memanfaatkan fungsi `report_settlement_data()` khusus untuk tanggal hari ini.
- Mengubah 4 kotak metrik statistik di atas menjadi representasi uang masuk hari ini (Transaksi Hari ini, Total Penerimaan Kotor, Tunai Diterima, dan Kas Disetorkan/Tunai Bersih).
- Mengubah tautan "Quick Actions" untuk mengarahkan pengguna pada operasi *closing*: Input Pembayaran, Mutasi Tabungan (arah ke riwayat tabungan), dan Rincian Setoran Lengkap.
- Menghapus tabel "Transaksi Terbaru" agar fokus UI tidak terdistraksi.
- Menjadikan tabel "Rekap Setoran Kas Fisik Hari Ini" sebagai satu-satunya tabel yang tampil di dashboard, lengkap dengan fungsionalitas Export Excel yang otomatis disesuaikan untuk mengekspor rekap tanggal hari berjalan.

**Kompatibilitas:** Sepenuhnya *backward-compatible*. Data tidak berubah, ini murni perombakan cara menampilkan agregasi data laporan pada halaman depan.

**Tindak Lanjut / Verifikasi:**
- Uji sintaks `php -l dashboard.php` lolos tanpa *error*.
- Memastikan navigasi tombol aksi cepat (`tabungan/riwayat.php`) berfungsi tanpa error.
- Tampilan dievaluasi melalui tangkapan visual, dan fitur *export Excel* dipastikan menuju *endpoint* yang sudah ada dengan parameter `tanggal_awal=TODAY&tanggal_akhir=TODAY`.

## 2026-08-18 - Laporan Global Modular dan Master Kelas/Rombel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah Laporan Global menjadi katalog tujuh template yang konsisten pada web/cetak/PDF/Excel, menambahkan Master Kelas/Rombel dan histori tarif, serta menjadikan Biaya Lain sebagai tagihan nyata yang dapat dicicil.

**Perubahan fitur dan perilaku:**

- Menambahkan Master Kelas/Rombel tingkat 1–6 dengan placeholder migrasi, pemilihan rombel di Data Siswa, snapshot penempatan per tahun ajaran, dan snapshot kelas pada transaksi baru.
- Master Daftar Ulang membentuk snapshot kelas, SPP, dan Komite saat menerbitkan tahun ajaran; perubahan kelas aktif tidak menulis ulang histori yang sudah mempunyai pembayaran.
- Master Biaya Lain dapat menerbitkan tagihan secara transaksional/idempoten ke semua siswa, tingkat, rombel, atau siswa terpilih. Input/Edit Pembayaran hanya menerima tagihan milik siswa dan memvalidasi cicilan serta overpay di backend.
- `laporan/global.php` menjadi katalog tujuh card; implementasi laporan dipisahkan ke registry/query bersama, halaman template, dan renderer export bersama.
- Menambahkan Status Pembayaran, Penerimaan Harian, matriks SPP Juli–Juni, Pembayaran per Item, dua bentuk laporan tabungan, dan Setoran Kas Harian live yang memisahkan tunai, non-tunai, dan dana tabungan.
- Mengubah label menu menjadi Laporan Umum, menghapus menu Rekap per Kelas, serta mempertahankan URL lama sebagai redirect ke template Per Item.

**Database dan migrasi:**

- Menambahkan `master_kelas`, relasi/snapshot kelas, snapshot SPP/Komite, `tagihan_biaya_lain`, audit penerbitan, referensi tagihan detail pembayaran, foreign key, unique key, dan indeks laporan.
- Migrasi `sql/add_modular_global_reports.sql` melakukan preflight, backfill placeholder tanpa mengarang rombel, migrasi pembayaran Biaya Lain lama, dan postcheck data yatim.
- Migrasi lokal dijalankan dua kali setelah backup dan seluruh pemeriksaan pascamigrasi bernilai nol.

**Kompatibilitas dan konfigurasi:**

- Kolom kelas dan detail transaksi legacy dipertahankan. Histori Biaya Lain tanpa master tetap dapat dibaca sebagai histori.
- Schema instalasi baru dan `verify_schema.sql` diselaraskan. Composer dikunci ke platform PHP 8.0.30 dan `ext-gd` dicantumkan untuk logo PDF.

**Verifikasi:**

- Seluruh PHP lint, JavaScript syntax check, Composer validate/audit, schema pada database salinan, migrasi idempoten, schema verification, seluruh regression test, dan HTTP terautentikasi ketujuh template dijalankan.
- Cetak, Excel, dan PDF ketujuh template diuji; response PDF terverifikasi sebagai `application/pdf`.

## 2026-08-17 - Validasi Urutan SPP dan Filter Tanggal Laporan

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mencegah pembayaran SPP melompati bulan sebelumnya dalam tahun ajaran Juli-Juni, menyederhanakan sisa pembayaran pada struk, dan merapikan filter tanggal laporan.

**Perubahan fitur dan perilaku:**

- Backend menolak pembayaran SPP untuk bulan terpilih bila ada SPP bulan sebelumnya dalam tahun ajaran yang sama belum lunas terhadap `siswa.SPP_PERBULAN`.
- Edit atau hapus pembayaran SPP bulan sebelumnya ditolak bila membuat bulan setelahnya yang sudah dibayar menjadi bolong.
- UI input/edit pembayaran mengunci input SPP dan menampilkan alasan bila bulan sebelumnya belum lunas; cicilan bulan berjalan tetap boleh selama tidak melebihi sisa.
- Bagian `Sisa Pembayaran` pada struk biasa dan struk tahunan hanya menampilkan `Sisa PSB` dan `Sisa DU`; rincian pembayaran utama tetap lengkap.
- Laporan Keuangan dan Laporan Global memakai satu kontrol date-range custom lokal yang tetap mengirim `tanggal_awal` dan `tanggal_akhir` untuk kompatibilitas export dan URL lama.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi baru.

**Kompatibilitas:**

- URL lama laporan dengan `tanggal_awal` dan `tanggal_akhir` tetap berjalan.
- Struk tahunan lama tetap dapat dibuka, hanya tampilan area sisa pembayaran yang diringkas.

**Verifikasi:**

- PHP lint, Node syntax check, schema check, regression test SPP/DU/biaya lain/pagination/legacy, dan HTTP terautentikasi untuk pembayaran serta laporan dijalankan pada lingkungan lokal.

## 2026-08-17 - Pemisahan Tabungan dari Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengunci tahun ajaran pembayaran pada periode Juli-Juni, mempertahankan SPP bulanan berbasis `SPP_PERBULAN`, dan memisahkan input tabungan dari transaksi pembayaran.

**Perubahan fitur dan perilaku:**

- Input dan edit pembayaran tidak lagi menampilkan field Tabungan; bagian penyesuaian hanya berisi Potongan SPP dan Kewajiban SPP.
- Backend menolak POST lama dengan `tabungan_wajib` bernilai lebih dari nol dan mengarahkan kasir memakai menu Tabungan Masuk.
- Struk, export PDF, detail siswa, dan rekap kelas tidak lagi menambahkan tabungan yang terhubung pembayaran ke total transaksi pembayaran.
- Tahun ajaran laporan memakai helper `du_academic_year_label()` yang sama dengan pembayaran Daftar Ulang.

**Database dan migrasi:**

- Menambahkan `sql/remove_payment_linked_savings.sql` untuk mengurangi saldo tabungan dari jurnal pembayaran lama, lalu menghapus jurnal `transaksi_m` yang memiliki `bayar_id`.
- Migrasi dijalankan pada database lokal dan menghasilkan `remaining_payment_linked_savings = 0`.

**Kompatibilitas:**

- Kolom `transaksi_m.bayar_id` dipertahankan untuk kompatibilitas schema, tetapi alur pembayaran baru tidak mengisinya.
- Modul Tabungan Masuk, Tabungan Keluar, Riwayat Tabungan, serta laporan tabungan tetap berjalan sebagai sumber tabungan resmi.

**Verifikasi:**

- Cleanup SQL dijalankan pada database lokal, lalu diuji dengan fixture linked saving sementara; saldo turun sesuai nominal dan `transaksi_m.bayar_id IS NOT NULL` menjadi 0.
- PHP lint XAMPP untuk 37 file, Node syntax check `assets/js/app.js`, dan `sql/verify_schema.sql` berhasil.
- Regression test `spp_installment`, `academic_year_billing`, `student_optional_fees`, `registration_history_pagination`, `payment_process_integration`, dan `legacy_compatibility` berhasil.
- HTTP terautentikasi ke form dan edit pembayaran memastikan field `tabungan_wajib`, `tab-wajib`, dan label `Potongan & Tabungan` tidak muncul.

## 2026-08-06 - Pagination Riwayat Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menjaga halaman Riwayat Daftar Ulang tetap ringan dan mudah dinavigasi ketika data sekolah mencapai ratusan siswa.

**Perubahan fitur dan perilaku:**

- Riwayat memakai pagination server-side per tagihan siswa/tahun ajaran dengan pilihan 25, 50, atau 100 baris.
- Ringkasan jumlah siswa, tagihan, pembayaran, dan sisa tetap menghitung seluruh hasil filter, bukan hanya halaman aktif.
- Detail cicilan diambil sekaligus hanya untuk tagihan pada halaman aktif; pencarian, kelas, tahun ajaran, status, dan ukuran halaman dipertahankan saat navigasi.
- Navigasi desktop menyediakan Awal, Sebelumnya, nomor halaman, Berikutnya, dan Akhir; tampilan mobile diringkas menjadi tiga kontrol.

**Database dan migrasi:**

- Tidak ada perubahan schema atau data permanen. Indeks tagihan dan relasi cicilan yang sudah tersedia digunakan kembali.

**Kompatibilitas:**

- Urutan, filter, status Lunas/Belum Lunas, rincian transaksi, cetak, dan edit tetap dipertahankan.
- Parameter `page` dinormalisasi ke rentang valid dan `per_page` dibatasi pada `25`, `50`, atau `100`.

**Verifikasi:**

- Fixture sementara 105 tagihan menguji halaman 25/50/100, nomor global, tanpa duplikasi, ringkasan global, filter status, detail halaman aktif, preservasi query, serta parameter invalid; seluruh fixture kemudian dibersihkan.
- Regression test database 105 tagihan berjalan dalam transaction dan di-rollback.
- Syntax check, HTTP terautentikasi, tampilan data kosong/satu halaman, dan regression pembayaran Daftar Ulang berhasil.

## 2026-08-06 - Tarif Manual Makan, Sorga, dan Infaq

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyediakan sumber tagihan per siswa untuk Uang Makan, Sorga, dan Infaq agar ketiga komponen dapat dibayar serta dicicil melalui alur pembayaran resmi.

**Perubahan fitur dan perilaku:**

- Data Siswa Advance memperoleh tarif manual Makan, Sorga, dan Infaq sebagai tagihan satu kali per siswa.
- Input dan edit pembayaran menampilkan total, terbayar, dan sisa dari tarif siswa serta seluruh transaksi; tarif nol dan tagihan lunas mengunci input.
- Penurunan tarif di bawah akumulasi pembayaran ditolak. Nilai Advance tetap dipertahankan ketika panel ditutup saat edit.
- `bayar.U_MAKAN`, `U_SORGA`, dan `U_INFAQ` tetap hanya menyimpan nominal transaksi aktual; laporan dan struk memakai nilai tersebut seperti sebelumnya.

**Database dan migrasi:**

- Menambahkan kolom `siswa.MAKAN`, `siswa.SORGA`, dan `siswa.INFAQ` melalui `sql/add_student_optional_fees.sql` yang idempoten.
- Migrasi dijalankan dua kali. Seluruh siswa lama mendapat default Rp0 dan tidak ada transaksi lama yang diubah atau di-backfill.

**Kompatibilitas:**

- Master Biaya Lain dan sistem Tagihan Daftar Ulang tidak berubah.
- Bulan/tahun transaksi hanya mencatat waktu cicilan; saldo ketiga komponen dihitung sepanjang histori siswa.

**Verifikasi:**

- Uji HTTP terautentikasi mencakup tambah/edit siswa, preservasi Advance, cicilan Makan, Sorga, Infaq, penolakan tarif nol dan pembayaran berlebih, edit/hapus cicilan, penolakan penurunan tarif, struk, serta laporan.
- Data uji dibersihkan dan database kembali berisi tujuh siswa serta satu transaksi awal tanpa perubahan nominal.
- Syntax check PHP/JavaScript, regression test, migrasi dua kali, dan seluruh pemeriksaan schema berhasil.

## 2026-08-05 - Integrasi Penerbitan dan Cicilan Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menghubungkan tarif Master Daftar Ulang langsung dengan rincian pembayaran siswa dan memastikan tagihan dapat dicicil secara konsisten.

**Perubahan fitur dan perilaku:**

- Tahun ajaran draf memakai satu aksi `Simpan & Terbitkan Tagihan`; penyimpanan enam tarif, sinkronisasi penempatan internal, pembuatan tagihan, perubahan status, dan audit berjalan dalam satu transaksi.
- Form lama yang masih mengirim aksi penerbitan terpisah tetap dipetakan ke proses atomik yang sama.
- Input dan edit pembayaran hanya membaca `tagihan_daftar_ulang`; total, terbayar, sisa, tahun ajaran, kelas, dan status ditampilkan langsung pada baris Daftar Ulang.
- Warning tagihan tidak tersedia dipindahkan dari Potongan & Tabungan ke baris Daftar Ulang. Input dikunci bila tagihan tidak tersedia, dibatalkan, atau sudah lunas.
- Cicilan Daftar Ulang dapat dilakukan berkali-kali sampai sisa nol; pembayaran berlebih tetap ditolak server dan hidden field kelas/tahun ajaran tidak menjadi sumber otoritatif.

**Database dan migrasi:**

- Tidak ada tabel atau kolom baru.
- Master `2026/2027` dengan enam tarif Rp1.000.000 diterbitkan menjadi tujuh tagihan berdasarkan kelas tujuh siswa aktif.

**Kompatibilitas:**

- Snapshot kelas dan tahun ajaran pada `bayar_du` tetap dipertahankan, tetapi saldo baru selalu dihitung melalui relasi `tagihan_daftar_ulang_id`.
- Riwayat, edit, hapus, dan cetak transaksi lama tetap memakai kontrak relasi pembayaran yang sudah ada.

**Verifikasi:**

- Uji pemetaan memastikan Agustus 2026 dan Januari 2027 sama-sama memakai `2026/2027`.
- Uji HTTP terautentikasi membuktikan cicilan Rp400.000 lalu Rp600.000 melunasi tagihan, pembayaran tambahan ditolak, edit mengembalikan saldo, dan hapus kedua transaksi uji mengembalikan saldo ke nol.
- Syntax check PHP/JavaScript, regression test, pemeriksaan schema, halaman input/edit/master/riwayat, dan kondisi akhir database diperiksa tanpa mengubah transaksi pengguna.

## 2026-08-05 - Penyederhanaan Penerbitan Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menghilangkan pengelolaan penempatan massal dari UI dan mempertahankan penerbitan tagihan yang sederhana untuk sekolah dengan ratusan siswa.

**Perubahan fitur dan perilaku:**

- Master Daftar Ulang tidak lagi memuat atau menampilkan tabel Penempatan Siswa.
- Kelas pada Data Siswa menjadi sumber penerbitan; siswa tinggal kelas cukup mempertahankan kelasnya dan siswa pindah/lulus dinonaktifkan sebelum penerbitan.
- Tombol Terbitkan Tagihan membuat penempatan internal serta tagihan secara atomik berdasarkan seluruh siswa aktif kelas 1–6.

**Database dan migrasi:**

- Kolom, indeks, serta workflow konfirmasi penempatan eksperimental dibatalkan dan dibersihkan.
- Migrasi akademik tidak lagi mengubah tahun draf menjadi terbit hanya karena enam tarif sudah tersedia.

**Verifikasi:**

- Rollback memastikan tagihan tanpa pembayaran hasil migrasi eksperimental dihapus dan `2026/2027` kembali menjadi draf.
- Migrasi dijalankan dua kali tanpa menerbitkan draf atau membuat tagihan baru.
- Uji transaksi memastikan kelas mengikuti Data Siswa, siswa tidak aktif tidak ditagih, siswa baru tetap memperoleh tagihan, dan halaman master tidak memuat tabel penempatan.

## 2026-08-05 - Tagihan Daftar Ulang Berbasis Tahun Ajaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memisahkan tarif master dari tagihan siswa, menyimpan kelas per tahun ajaran Juli–Juni, dan menghubungkan cicilan Daftar Ulang ke tagihan yang diterbitkan.

**Perubahan fitur dan perilaku:**

- Master Daftar Ulang mengelola enam tarif kelas, pratinjau kenaikan kelas, status tahun ajaran, dan penerbitan tagihan massal.
- Input/edit pembayaran menghitung tahun ajaran dari periode pilihan, mengambil kelas dan saldo tagihan dari server, serta tidak mempercayai hidden field konteks DU.
- Riwayat Daftar Ulang sekarang menampilkan tagihan belum bayar, cicilan, dan lunas.
- Periode tunggakan dapat dibayar; periode masa depan ditolak.
- Mode tahunan Januari–Desember ditangguhkan untuk transaksi baru, tanpa menghapus histori batch lama.

**Database dan migrasi:**

- Menambahkan tabel tahun ajaran, penempatan, tagihan, audit DU, dan relasi tagihan pada `bayar_du` melalui `sql/add_academic_year_billing.sql`.
- Migrasi melakukan backfill aman untuk master, penempatan, tagihan, dan pembayaran DU lama serta dapat dijalankan ulang.

**Verifikasi:**

- Migrasi dijalankan dua kali dan seluruh pemeriksaan `sql/verify_schema.sql` berstatus `OK`.
- Regression test mencakup batas Juni/Juli, penerbitan idempoten, cicilan, dan pemulihan saldo setelah hapus.
- Halaman master, input, edit, serta riwayat dimuat melalui Apache tanpa fatal error; request tahunan dan periode masa depan ditolak server.

## 2026-08-04 - Tahun Ajaran Sistem dan Cicilan Daftar Ulang

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan daftar ulang sebagai tagihan tahunan SD berbasis tahun ajaran Juli-Juni dan memastikan cicilan DU terkunci ke master yang benar.

**Perubahan fitur dan perilaku:**

- Mengganti input manual tahun ajaran pada Master Daftar Ulang menjadi dropdown sistem.
- Tahun ajaran aktif dihitung otomatis dengan aturan Juli-Desember memakai `YYYY/YYYY+1`, sedangkan Januari-Juni memakai `YYYY-1/YYYY`.
- Dropdown tahun ajaran menampilkan tahun ajaran aktif +/- 3 tahun serta tahun master lama yang sudah ada.
- Form input pembayaran memakai tahun ajaran aktif sebagai default DU, bukan master terbaru.
- Kelas DU otomatis mengikuti kelas siswa saat siswa dipilih, selama admin belum mengubahnya manual.
- Input `Uang Daftar Ulang` dikunci saat master DU untuk kombinasi kelas/tahun ajaran belum tersedia, dan warning tetap tampil.
- Membump `app.js` pada halaman master/input/edit DU ke `v=4.2`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah schema atau route.
- Tahun ajaran lama yang sudah tersimpan tetap dimasukkan ke pilihan agar data master lama dapat diedit.
- Fallback ke data siswa tetap hanya berlaku bila tabel master DU benar-benar kosong total.

**Verifikasi:**

- `php -l master_daftar_ulang.php`, `php -l pembayaran/form.php`, `php -l pembayaran/edit.php`, dan `php -l pembayaran/proses.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser pada Master Daftar Ulang dan form pembayaran untuk memastikan default tahun ajaran aktif serta locking DU berjalan sesuai kombinasi master.

## 2026-08-04 - Perbaikan Fundamental Edit Pembayaran Dari Riwayat

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memastikan halaman edit pembayaran dari riwayat langsung memakai data terbaru dan menghitung sisa tagihan dengan mengecualikan transaksi yang sedang diedit.

**Perubahan fitur dan perilaku:**

- Halaman edit pembayaran sekarang otomatis mengikat siswa yang sedang diedit ke konteks datalist saat load.
- `Total Tagihan`, `Sudah Terbayar`, dan `Sisa` pada edit langsung dihitung dari data/master terbaru tanpa perlu mengetik ulang siswa.
- Nominal input transaksi yang sedang diedit tetap dipertahankan, sementara histori pembayaran lain mengecualikan `bayar.id` aktif.
- Data daftar ulang yang eksplisit terhubung melalui `bayar_du.bayar_id` dipakai untuk menguatkan konteks `kelas_du`, `th_ajaran`, dan nominal DU pada edit.
- Query histori edit untuk siswa, SPP/Komite periodik, DU, dan biaya lain diperbaiki memakai prepared statement.
- Membump `app.js` pada halaman riwayat pembayaran agar klik baris edit memakai script terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak menambah schema baru.
- Edit transaksi tetap hanya berlaku untuk pembayaran `payment_link_version=1`.
- Basis edit mengikuti master/tarif terbaru sesuai keputusan project; histori transaksi yang sedang diedit tidak dihitung ganda.

**Verifikasi:**

- `php -l pembayaran/edit.php`, `php -l pembayaran/proses.php`, dan `php -l pembayaran/lihat.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser dari halaman Riwayat Pembayaran dengan klik baris langsung, lalu cek rincian total/sudah/sisa pada transaksi yang punya histori lain.

## 2026-08-04 - Master Daftar Ulang dan Integrasi Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menjadikan `Daftar_ulang` sebagai master resmi nominal daftar ulang per kelas dan tahun ajaran, lengkap dengan CRUD admin dan integrasi pembayaran.

**Perubahan fitur dan perilaku:**

- Menambahkan halaman admin `master_daftar_ulang.php` untuk tambah, edit, hapus, dan daftar nominal daftar ulang per `Tahun Ajaran + Kelas`.
- Menambahkan menu sidebar `Master Daftar Ulang` khusus admin.
- Form input dan edit pembayaran sekarang membaca master daftar ulang dari database untuk dropdown tahun ajaran dan nominal DU.
- Jika master daftar ulang kosong total, pembayaran tetap fallback ke data siswa agar alur lama kompatibel.
- Jika master sudah ada tetapi kombinasi kelas/tahun ajaran belum tersedia, form menampilkan warning dan nominal DU menjadi `0` sampai master dilengkapi.
- Backend pembayaran menghitung ulang nominal DU dari master/fallback resmi dan menolak input DU bila total tagihan belum tersedia atau pembayaran melebihi sisa.

**Database dan migrasi:**

- Menambahkan migrasi idempoten `sql/add_master_daftar_ulang.sql`.
- Memperbarui `sql/schema.sql` agar `Daftar_ulang(th_ajaran, kelas)` memiliki unique key `uk_daftar_ulang_period_class`.
- Memperbarui `sql/verify_schema.sql` untuk memeriksa tabel `Daftar_ulang` dan unique key daftar ulang.

**Kompatibilitas dan data lama:**

- Tidak mengubah route lama pembayaran.
- Histori `bayar_du` tetap dipakai untuk menghitung `Sudah Terbayar` dan `Sisa`.
- Fallback ke kolom daftar ulang di tabel siswa hanya berlaku bila tabel master DU benar-benar belum berisi data.

**Verifikasi:**

- `sql/add_master_daftar_ulang.sql` dijalankan dua kali pada database lokal tanpa error.
- `sql/verify_schema.sql` mengembalikan `OK` untuk seluruh requirement, termasuk `table.Daftar_ulang` dan `uk_daftar_ulang_period_class`.
- `php -l master_daftar_ulang.php`, `php -l includes/sidebar.php`, `php -l pembayaran/form.php`, `php -l pembayaran/edit.php`, dan `php -l pembayaran/proses.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Isi master daftar ulang sesuai kebijakan sekolah, misalnya tahun ajaran terbaru dan nominal per kelas.

## 2026-08-04 - Alert Input Pembayaran Melebihi Sisa

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memberi peringatan langsung saat nominal input pembayaran lebih besar dari sisa tagihan.

**Perubahan fitur dan perilaku:**

- Menambahkan alert inline khusus `payment-input-overlimit-alert` pada form input dan edit pembayaran.
- Menambahkan validasi browser-side untuk membandingkan `Input Bayar` dengan sisa sebelum input (`Total Tagihan - Sudah Terbayar`).
- Input yang melebihi sisa diberi invalid state, barisnya diberi highlight, dan form ditahan dengan `setCustomValidity()`.
- Nilai input user tidak dihapus otomatis agar pengguna dapat melihat nominal yang salah.
- Membump `app.js` halaman input/edit pembayaran ke `v=4.0`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah route, schema, atau data. Backend tetap menjadi sumber kebenaran dan tetap menolak pembayaran yang melebihi sisa.

**Verifikasi:**

- `php -l pembayaran/form.php` dan `php -l pembayaran/edit.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check -- assets/css/style.css assets/js/app.js pembayaran/form.php pembayaran/edit.php documentation/PROJECT_CONTEXT.md documentation/AI_CHANGELOG.md` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser: input lebih dari sisa, sama dengan sisa, lebih kecil dari sisa, dan input pada komponen dengan total nol.

## 2026-08-04 - Rincian Pembayaran Visual-Only Untuk Nilai Sistem

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperjelas logika akuntansi pada form pembayaran agar nilai histori tidak terlihat seperti input manual.

**Perubahan fitur dan perilaku:**

- Mengganti label `Total Sebelum (Rp)` menjadi `Total Tagihan (Rp)` pada form input dan edit pembayaran.
- Menambahkan microcopy bahwa total, sudah terbayar, dan sisa dihitung otomatis dari riwayat transaksi.
- Membuat kolom `Total Tagihan`, `Sudah Terbayar`, dan `Sisa` menjadi readonly visual-only dengan style berbeda dari `Input Bayar`.
- Mempertahankan `Input Bayar` sebagai satu-satunya kolom yang dapat diedit pengguna.
- Membump `style.css` halaman input/edit pembayaran ke `v=4.6`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah route, schema, atau data. Sumber `Sudah Terbayar` tetap berasal dari transaksi, data migrasi/saldo awal siswa, `bayar_du`, dan `bayar_biaya_lain` sesuai komponen masing-masing.

**Verifikasi:**

- `php -l pembayaran/form.php` dan `php -l pembayaran/edit.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check -- assets/css/style.css pembayaran/form.php pembayaran/edit.php documentation/PROJECT_CONTEXT.md documentation/AI_CHANGELOG.md` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser: pilih siswa dengan/ tanpa histori, ubah periode SPP/Komite, ubah kelas/tahun daftar ulang, lalu pastikan hanya kolom `Input Bayar` yang bisa diketik.

## 2026-08-03 - Penyesuaian Label Riwayat Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan istilah tampilan agar lebih jelas sebagai riwayat transaksi.

**Perubahan fitur dan perilaku:**

- Mengganti label menu dan halaman `Lihat Pembayaran` menjadi `Riwayat Pembayaran`.
- Mengganti breadcrumb halaman pembayaran dari `Lihat` menjadi `Riwayat`.
- Mengganti kolom `Transaksi` pada daftar siswa menjadi `Riwayat Transaksi`, termasuk label responsif mobile.
- Merapikan alignment kolom `Riwayat Transaksi` pada daftar siswa dan membump `style.css` halaman siswa ke `v=4.5`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah route, file, atau data. Perubahan hanya teks UI.

**Verifikasi:**

- `php -l includes/sidebar.php`, `php -l pembayaran/lihat.php`, dan `php -l siswa/daftar.php` berhasil.
- `git diff --check -- assets/css/style.css includes/sidebar.php pembayaran/lihat.php siswa/daftar.php documentation/AI_CHANGELOG.md` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Tidak ada.

## 2026-08-02 - Sinkronisasi Database Lama dan Kompatibilitas Kelas

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperbaiki fatal error dashboard dan menyelaraskan database lokal lama dengan kebutuhan schema aplikasi saat ini tanpa menghapus data siswa.

**Perubahan fitur dan perilaku:**

- Master siswa tetap membatasi data baru ke kelas 1 sampai 6.
- Label kelas legacy pada siswa yang sudah ada dapat dipertahankan saat diedit dan tersedia pada filter daftar siswa.
- Pengurutan daftar siswa menempatkan kelas SD sebelum label kelas legacy.

**Database dan migrasi:**

- Memperbarui `sql/add_student_advanced.sql` agar migrasi tetap menambahkan kolom, tipe nominal, audit, dan Uang Komite saat database memuat label kelas legacy maksimal 5 karakter.
- Constraint kelas SD hanya ditambahkan bila seluruh kelas existing sudah bernilai 1 sampai 6.
- Memperluas `sql/verify_schema.sql` agar memeriksa seluruh tabel, kolom, dan index utama dari paket migrasi saat ini.
- Menjalankan seluruh migrasi upgrade pada `db_spp` setelah backup.

**Kompatibilitas dan data lama:**

- Data siswa, akun, dan transaksi lama dipertahankan. Label kelas legacy tidak dipetakan atau diubah otomatis.

**Verifikasi:**

- Migrasi dijalankan dua kali untuk memeriksa idempotensi.
- Verifikasi schema, lint seluruh file PHP, query dashboard, dan smoke test HTTP lokal dijalankan.

**Catatan tindak lanjut:**

- Label kelas legacy dapat dikonversi manual ke kelas 1 sampai 6 bila sekolah tidak lagi membutuhkannya.

## 2026-08-02 - Dokumentasi Progress Flow Sistem

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mencatat progres tiap flow aplikasi dan alur yang sudah berubah agar mudah dipakai untuk laporan.

**Perubahan fitur dan perilaku:**

- Memperluas `documentation/PROGRESS.md` dengan register temuan terbaru dan log perubahan alur aplikasi.
- Mencatat perubahan flow login, dashboard, data siswa, pembayaran, daftar ulang, master biaya lain, lihat pembayaran, tabungan, laporan/export, mobile, dan tema.
- Memperbarui MoM agar perubahan daftar ulang, biaya lain cicilan, dan klik baris untuk edit ikut tercatat pada laporan.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah kode aplikasi atau data; perubahan hanya dokumentasi.

**Verifikasi:**

- `git diff --check -- documentation/PROGRESS.md documentation/MOM_SistemSPP.md documentation/AI_CHANGELOG.md` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Gunakan `PROGRESS.md` sebagai catatan flow teknis dan `MOM_SistemSPP.md` sebagai bahan laporan rapat/project.

## 2026-08-02 - Perbaikan Master Daftar Ulang Pada Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memastikan pencatatan pembayaran daftar ulang memakai konteks master kelas dan tahun ajaran dengan benar.

**Perubahan fitur dan perilaku:**

- Form input dan edit pembayaran memuat master `Daftar_ulang` sebagai sumber nominal daftar ulang per `kelas + tahun ajaran` saat data master tersedia.
- Jika master daftar ulang belum diisi, sistem tetap memakai fallback nominal daftar ulang dari data siswa agar transaksi lama tidak putus.
- Hitungan `sudah terbayar` daftar ulang kini dipisah per `NO_INDUK + kelas + tahun ajaran`, sehingga pembayaran daftar ulang tahun/kelas lain tidak mengurangi sisa periode yang sedang dipilih.
- Validasi backend simpan dan update pembayaran memakai konteks `kelas_du` dan `tahun_ajaran_du`; jika ada duplikat master, data terbaru berdasarkan `id` dipakai.
- Membump `app.js` pada form pembayaran ke `v=3.9`.

**Database dan migrasi:**

- Tidak ada migrasi baru. Pemeriksaan lokal menunjukkan tabel `Daftar_ulang` ada tetapi belum berisi data.

**Kompatibilitas dan data lama:**

- Data lama tetap kompatibel lewat fallback ke nominal daftar ulang pada tabel `siswa`.
- Transaksi `bayar_du` lama tetap dibaca, tetapi perhitungan sisa hanya memakai transaksi yang punya `kelas` dan `th_ajaran`.

**Verifikasi:**

- `php -l pembayaran/form.php`, `php -l pembayaran/edit.php`, dan `php -l pembayaran/proses.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Isi/import master `Daftar_ulang` untuk setiap kelas dan tahun ajaran agar nominal daftar ulang benar-benar berasal dari master.

## 2026-08-02 - Klik Baris Untuk Edit Siswa

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mempercepat akses edit data siswa tanpa harus menekan tombol `Edit`.

**Perubahan fitur dan perilaku:**

- Baris siswa pada `siswa/daftar.php` dapat diklik untuk membuka mode edit siswa.
- Klik pada tombol `Edit`, form `Arsipkan/Pulihkan`, dan elemen interaktif lain tetap menjalankan aksi masing-masing tanpa ikut terkena navigasi baris.
- Reuse handler row-click global dengan dukungan keyboard `Enter`/`Space`.
- Membump `style.css` halaman data siswa ke `v=4.3` dan `app.js` ke `v=3.8`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah data siswa. Perubahan hanya pada navigasi tabel.

**Verifikasi:**

- `php -l siswa/daftar.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser: klik area nama/NIS siswa untuk edit, lalu klik `Arsipkan/Pulihkan` untuk memastikan confirm status tetap muncul.

## 2026-08-02 - Klik Baris Untuk Edit Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mempercepat akses edit pembayaran dari halaman lihat pembayaran dan dashboard tanpa harus menekan tombol `Edit`.

**Perubahan fitur dan perilaku:**

- Baris transaksi pada `pembayaran/lihat.php` dapat diklik untuk membuka halaman edit.
- Baris `Transaksi Terbaru` pada `dashboard.php` dapat diklik untuk membuka halaman edit.
- Klik pada tombol `Edit`, `Hapus`, dan elemen interaktif lain tetap menjalankan aksi masing-masing tanpa ikut terkena navigasi baris.
- Baris clickable diberi cursor pointer, hover state, dan dukungan keyboard `Enter`/`Space`.
- Membump `style.css` halaman lihat pembayaran dan dashboard ke `v=4.3`, serta `app.js` ke `v=3.8`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Hanya transaksi dengan `payment_link_version=1` yang bisa diklik untuk edit. Transaksi legacy tetap tidak bisa diedit dari row click.

**Verifikasi:**

- `php -l pembayaran/lihat.php` dan `php -l dashboard.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji browser: klik area nama/NIS/total untuk edit, lalu klik `Hapus` untuk memastikan confirm hapus tetap muncul.

## 2026-08-02 - Jam Update Pada Lihat Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menampilkan keterangan jam bayar dan jam update pada rekap pembayaran siswa agar perubahan transaksi lebih mudah dilacak.

**Perubahan fitur dan perilaku:**

- Menetapkan timezone aplikasi PHP ke `Asia/Jakarta` dan session MySQL ke `+07:00` melalui `koneksi.php`.
- Kolom `Tanggal` pada `pembayaran/lihat.php` diubah menjadi `Bayar / Update`.
- Nilai tanggal bayar ditampilkan bersama jam `HH:mm WIB` dari `TGL_BYR`.
- Menampilkan label `Diubah` saat `bayar.updated_at` lebih baru dari `created_at`.
- Menambahkan styling `date-time-cell` agar tanggal dan jam tetap rapi di tabel desktop maupun mobile.
- Membump asset `style.css` pada halaman lihat pembayaran ke `v=4.2` dan `app.js` ke `v=3.6`.

**Database dan migrasi:**

- Menambahkan `sql/add_payment_updated_at.sql`.
- Menambahkan kolom `bayar.updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP`.
- Migrasi sudah dijalankan pada database lokal `db_spp`.

**Kompatibilitas dan data lama:**

- Data lama tetap tampil. Migrasi menginisialisasi `updated_at` dari `created_at`; label `Diubah` hanya tampil setelah transaksi benar-benar diedit lagi.
- Jika waktu historinya kosong atau tidak valid, sistem menampilkan tanda `-`.

**Verifikasi:**

- `php -l pembayaran/lihat.php` berhasil.
- `php -l koneksi.php` berhasil.
- `SHOW COLUMNS FROM bayar LIKE 'updated_at'` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Bila membutuhkan audit edit yang lebih detail, tambahkan tabel audit pembayaran agar setiap perubahan historis tersimpan, bukan hanya waktu update terakhir.

## 2026-08-02 - Cicilan Biaya Lain

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah konsep biaya lain agar tidak wajib dibayar penuh dalam satu transaksi.

**Perubahan fitur dan perilaku:**

- Form input dan edit pembayaran menampilkan `Total`, `Sudah`, `Sisa`, dan `Input Bayar` pada setiap baris biaya lain.
- Setiap field biaya lain diberi label kecil agar fungsi kolom `Total`, `Sudah`, `Sisa`, `Bayar`, dan `Keterangan` tidak membingungkan.
- `Input Bayar` biaya lain dibuat editable sehingga master seperti `Buku Rp 500.000` dapat dibayar bertahap.
- Frontend menghitung sisa biaya lain berdasarkan siswa terpilih dan pembayaran sebelumnya.
- Menambahkan alert visual khusus biaya lain saat `Bayar` melebihi `Sisa`.
- Merapikan alignment nomor baris dan tombol hapus pada grid biaya lain agar sejajar dengan input.
- Backend menyimpan nominal cicilan aktual ke `bayar_biaya_lain.nominal_snapshot`.
- Backend menolak nominal biaya lain yang negatif atau melebihi sisa tagihan siswa untuk master biaya tersebut.
- Reset form pembayaran membersihkan alert overpaid, highlight baris, disabled state, dan validasi custom yang tersisa.
- Membump versi `style.css` pada form input/edit pembayaran ke `v=4.4` dan `app.js` ke `v=3.7`.
- Memperbarui MoM dan konteks proyek terkait konsep cicilan biaya lain.

**Database dan migrasi:**

- Tidak ada. Struktur `master_biaya_lain` dan `bayar_biaya_lain` yang sudah ada cukup untuk menyimpan total master dan nominal cicilan transaksi.

**Kompatibilitas dan data lama:**

- Histori tetap memakai snapshot nama dan nominal bayar yang sudah tersimpan.
- Master nonaktif tetap bisa tampil saat mengedit transaksi lama yang sudah memakai master tersebut.

**Verifikasi:**

- `php -l pembayaran/form.php`, `php -l pembayaran/edit.php`, dan `php -l pembayaran/proses.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual di browser: pilih siswa, pilih biaya lain, ubah `Input Bayar` menjadi cicilan, lalu coba input melebihi sisa untuk memastikan validasi muncul.

## 2026-08-02 - Alert Pembayaran Melebihi Tagihan

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memberi peringatan saat nilai `Sudah Terbayar` lebih besar dari `Total Sebelum` pada form pembayaran.

**Perubahan fitur dan perilaku:**

- Menambahkan alert overpaid pada form input dan edit pembayaran.
- Menandai baris komponen yang sudah overpaid dan mengunci `Input Bayar` komponen tersebut ke `0`.
- Menambahkan validasi backend agar input komponen baru tidak boleh melebihi sisa tagihan.
- Membump versi `app.js` pada form input dan edit pembayaran ke `v=3.4`.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak mengubah data lama. Jika data lama sudah overpaid, form akan memberi peringatan dan mencegah penambahan pembayaran pada komponen tersebut.

**Verifikasi:**

- `php -l pembayaran/proses.php`, `php -l pembayaran/form.php`, dan `php -l pembayaran/edit.php` berhasil.
- `node --check assets/js/app.js` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual di browser dengan kondisi contoh: `Total Sebelum Rp 100.000` dan `Sudah Terbayar Rp 200.000`.

## 2026-08-02 - Cetak Slip PDF Per Transaksi Terpilih

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan konsep cetak slip selektif dari detail transaksi pembayaran tanpa mengubah fungsi export PDF semua transaksi.

**Perubahan fitur dan perilaku:**

- Menambahkan checkbox pada tabel detail transaksi pembayaran di `laporan/index.php`.
- Menambahkan tombol `Cetak Dipilih` yang aktif setelah minimal satu transaksi dipilih.
- Menambahkan tombol `Cetak` per baris transaksi untuk membuat slip satu transaksi.
- Menambahkan dukungan parameter `mode=selected` dan `ids[]` pada `laporan/export_pdf.php`.
- Mempertahankan tombol `Export PDF` periode sebagai cetak semua transaksi.
- Membump versi `style.css` ke `v=3.9` agar styling tombol dan checkbox terbaru terambil browser.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- URL export PDF lama tetap berlaku untuk mencetak seluruh transaksi periode. Parameter baru hanya mempersempit hasil PDF bila dipakai.

**Verifikasi:**

- `php -l laporan/index.php` dan `php -l laporan/export_pdf.php` berhasil.
- Pemeriksaan manual memastikan form filter, form cetak terpilih, checkbox, dan tombol per baris berada pada struktur HTML yang sesuai.

**Catatan tindak lanjut:**

- Uji visual di browser: pilih beberapa transaksi, klik `Cetak Dipilih`, dan pastikan PDF hanya berisi slip yang dipilih.

## 2026-08-02 - Perbaikan Insert Pembayaran Baru

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperbaiki error penyimpanan pembayaran baru setelah penambahan kolom relasi pembayaran dan metode pembayaran.

**Perubahan fitur dan perilaku:**

- Menambahkan satu placeholder pada query `INSERT INTO bayar` di `pembayaran/proses.php` agar jumlah value sesuai dengan jumlah kolom.
- Mempertahankan `payment_link_version=1` sebagai nilai literal untuk transaksi baru.

**Database dan migrasi:**

- Tidak ada perubahan schema baru. Database lokal sebelumnya sudah menjalankan `add_payment_references.sql` dan `add_payment_method.sql`.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data lama. Perbaikan hanya memulihkan proses simpan pembayaran baru.

**Verifikasi:**

- `php -l pembayaran/proses.php` berhasil.
- Kolom `bayar.payment_link_version` dan `bayar.sistem_pembayaran` tersedia pada database lokal.

**Catatan tindak lanjut:**

- Uji simpan pembayaran baru melalui browser menggunakan data siswa uji.

## 2026-07-31 - Pembaruan Dokumen MoM

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperbarui Minutes of Meeting agar sesuai dengan kondisi terbaru project setelah revisi UI, export, pembayaran, dan push repository.

**Perubahan fitur dan perilaku:**

- Memperbarui `documentation/MOM_SistemSPP.md` dengan tanggal rapat terbaru, repository, commit terakhir yang sudah dipush, ringkasan fitur, hasil revisi, keputusan, action items, risiko, dan lampiran modul.
- Menambahkan pembahasan sistem pembayaran `Tunai`, `VA`, dan `Qris`.
- Menyesuaikan bagian laporan agar menyebut PDF server-side Dompdf dan preview Excel sebelum download.
- Menambahkan catatan revisi mobile, avatar role, dark mode, dan riwayat tabungan.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan perilaku aplikasi. Perubahan hanya pada dokumentasi project.

**Verifikasi:**

- Review manual isi MoM berdasarkan changelog dan konteks project terbaru.

**Catatan tindak lanjut:**

- Lengkapi placeholder waktu, tempat, pemimpin rapat, notulis, peserta, PIC, dan target tanggal sesuai kebutuhan laporan.

## 2026-07-31 - Perapihan Mobile Preview Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan tampilan preview export Excel pada layar mobile agar tombol, ringkasan, dan tabel lebih nyaman dibaca.

**Perubahan fitur dan perilaku:**

- Mengubah area preview Excel mobile menjadi full-width tanpa shadow/card besar yang membuat ruang terasa sempit.
- Merapikan tombol `Download Excel` dan `Kembali` pada mobile agar tinggi dan jaraknya konsisten.
- Membungkus setiap tabel laporan dalam container scroll tersendiri, sehingga tabel tidak dipaksa mengecil dan kolom tetap terbaca.
- Mempertahankan tabel komponen pembayaran sebagai tabel compact agar tetap pas di layar mobile.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data. Download Excel tetap memakai URL dan format `.xls` yang sama.

**Verifikasi:**

- `php -l laporan/export_excel.php` berhasil.
- Test HTTP lokal preview Excel berhasil status `200` dan memuat wrapper tabel mobile.
- Test HTTP lokal download Excel berhasil status `200` dengan `Content-Type: application/vnd.ms-excel; charset=UTF-8` dan attachment `Laporan_SPP_Juli_2026.xls`.

**Catatan tindak lanjut:**

- Uji visual langsung di viewport mobile browser untuk memastikan scroll horizontal per tabel terasa nyaman.

## 2026-07-31 - Perapihan Layout Slip PDF

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan konsep slip PDF server-side agar tetap memakai format baru tetapi tidak berantakan atau terpecah halaman.

**Perubahan fitur dan perilaku:**

- Mengubah template slip PDF dari layout berbasis `div/grid` menjadi layout tabel HTML yang lebih stabil untuk Dompdf.
- Menyesuaikan ukuran konten slip agar total halaman pas pada kertas landscape `210mm x 148mm`.
- Menghapus sisa toolbar/aksi cetak HTML dari output slip PDF.
- Mempertahankan contoh slip otomatis ketika periode belum memiliki transaksi.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun URL export. Endpoint `laporan/export_pdf.php` tetap mengembalikan PDF server-side.

**Verifikasi:**

- `php -l laporan/export_pdf.php` berhasil.
- Test HTTP lokal export PDF berhasil mengembalikan `Content-Type: application/pdf`, file berawalan `%PDF`, satu page object, dan MediaBox `595.276 x 419.528` pt.
- `composer validate --strict` berhasil; `composer audit` tidak menemukan security advisory, dengan peringatan sebagian metadata Packagist memakai cache lokal karena timeout koneksi.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual langsung di Chrome PDF viewer setelah refresh tab export.

## 2026-07-31 - Perbaikan Mobile Riwayat dan Preview Export

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan tampilan mobile riwayat tabungan dan preview Excel, serta mengembalikan konsep slip saat periode belum memiliki transaksi.

**Perubahan fitur dan perilaku:**

- Merapikan form filter riwayat tabungan mobile agar field dan tombol tidak saling menekan.
- Merapikan tombol `Tabungan Masuk` dan `Tabungan Keluar` pada card riwayat tabungan mobile.
- Mengubah tabel riwayat tabungan dan rekap saldo menjadi responsive card pada mobile.
- Merapikan toolbar dan tabel preview Excel mobile agar tidak membuat halaman melebar.
- Export PDF kini otomatis menampilkan slip contoh saat periode belum memiliki transaksi, sehingga konsep slip tidak berubah menjadi halaman kosong.
- Membump versi `style.css` ke `v=3.8` agar browser mengambil styling mobile terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. Slip contoh hanya tampil sebagai preview PDF saat periode kosong dan tidak menyimpan data.

**Verifikasi:**

- Lint PHP berhasil untuk `tabungan/riwayat.php`, `laporan/export_excel.php`, `laporan/export_pdf.php`, dan halaman yang memakai `style.css`.
- Pencarian versi lama `style.css?v=3.0` sampai `v=3.7` tidak menemukan sisa pada file PHP.
- Test HTTP lokal export PDF periode kosong berhasil mengembalikan file `%PDF` dengan MediaBox `595.276 x 419.528` pt atau `210mm x 148mm`.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual mobile pada Riwayat Tabungan dan Preview Excel di browser.

## 2026-07-31 - Ukuran Cetak Slip Landscape

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan hasil cetak slip pembayaran agar tidak memakai A4 portrait penuh, menghapus logo dari slip, dan menghilangkan header/footer bawaan Chrome.

**Perubahan fitur dan perilaku:**

- Menghapus logo dari header slip pembayaran.
- Mengubah ukuran print slip menjadi landscape `210mm x 148mm`.
- Mengubah export slip dari HTML print browser menjadi PDF asli yang dirender server-side dengan Dompdf.
- Menghapus toolbar print HTML dari template PDF agar file hanya berisi isi slip.
- Menyederhanakan judul browser menjadi `Slip Pembayaran`.
- Menambahkan `vendor/` ke `.gitignore`; dependency dipulihkan lewat `composer install`.

**Database dan migrasi:**

- Tidak ada perubahan database.
- Menambahkan dependency Composer `dompdf/dompdf` versi `3.1.6`.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. URL export PDF lama tetap sama, tetapi response kini berupa `application/pdf` dari server.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- `composer audit` berhasil tanpa security advisory setelah Dompdf diperbarui ke `3.1.6`.
- Test HTTP lokal setelah login berhasil mengembalikan `Content-Type: application/pdf` dan file berawalan `%PDF`.
- Pencarian di `laporan/export_pdf.php` memastikan ukuran `210mm x 148mm` sudah dipakai, referensi logo slip sudah tidak ada, dan toolbar print HTML sudah dihapus.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual file PDF di browser untuk memastikan posisi slip sesuai contoh cetak.

## 2026-07-31 - Logo pada Slip Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan logo sekolah pada slip pembayaran agar header slip tidak hanya berisi teks.

**Perubahan fitur dan perilaku:**

- Menambahkan favicon pada halaman preview/cetak slip PDF.
- Menambahkan logo `assets/img/school-logo.png` pada header slip pembayaran.
- Menyesuaikan layout header slip agar logo berada di kiri dan judul sekolah tetap rata tengah.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. Perubahan hanya pada tampilan slip.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- Asset `assets/img/school-logo.png` dan `assets/img/favicon.png` tersedia.
- Pencarian di `laporan/export_pdf.php` memastikan path logo dan favicon sudah dipasang.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji cetak/save PDF di browser untuk memastikan logo tampil pada hasil cetak.

## 2026-07-31 - Palet Dark Mode Lebih Ramah

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah palet dark mode agar tampilan tidak terlalu hijau pekat dan lebih nyaman dibaca.

**Perubahan fitur dan perilaku:**

- Mengubah variable dark mode dari palet hijau pekat menjadi dasar neutral charcoal/slate dengan aksen emerald.
- Mengurangi intensitas glow dan orb background agar area dashboard terasa lebih bersih.
- Menyesuaikan sidebar, topbar, active menu, kartu statistik, tabel, badge, search box, tab, dan bottom navigation agar kontras lebih ramah mata.
- Menyesuaikan dark mode halaman login agar selaras dengan palet baru.
- Membump versi `style.css` ke `v=3.7` dan `login.css` ke `v=3.4` agar browser mengambil styling terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data, role, maupun alur fitur. Perubahan hanya pada tampilan dark mode.

**Verifikasi:**

- Lint PHP berhasil untuk halaman yang memuat stylesheet utama: dashboard, login, master biaya lain, role management, sidebar, laporan, pembayaran, siswa, dan tabungan.
- Pencarian versi lama `style.css?v=3.0` sampai `v=3.6` dan `login.css?v=3.0` sampai `v=3.3` tidak menemukan sisa pada file PHP.
- Pencarian warna dark mode lama hanya menyisakan override light mode yang memang terpisah.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual dark mode di dashboard, form pembayaran, tabel laporan, dan halaman login.

## 2026-07-31 - Logout Mobile Sidebar

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memastikan tombol logout bisa diakses dengan nyaman pada tampilan mobile.

**Perubahan fitur dan perilaku:**

- Menambahkan label `Logout` pada tombol logout sidebar agar lebih jelas di mobile.
- Menyembunyikan bottom navigation saat sidebar mobile terbuka supaya tidak menutupi footer sidebar.
- Menaikkan prioritas tampilan sidebar mobile dan menambahkan safe-area padding pada footer.
- Membump versi `style.css` ke `v=3.6` pada halaman utama agar browser mengambil styling mobile terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun hak akses. Logout tetap memakai `logout.php`.

**Verifikasi:**

- `C:\xampp\php\php.exe -l includes\sidebar.php` berhasil.
- `C:\xampp\php\php.exe -l login.php` berhasil.
- Pencarian `style.css?v=3.0` sampai `v=3.5` tidak menemukan sisa pada file PHP.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual di mobile: buka sidebar, pastikan bottom nav hilang dan tombol logout terlihat di footer.

## 2026-07-31 - Animasi Transisi Login Per Role

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan animasi masuk setelah login berhasil agar perpindahan ke halaman sesuai role terasa lebih halus.

**Perubahan fitur dan perilaku:**

- Mengubah redirect login valid dari redirect server instan menjadi render transisi singkat lalu redirect via JavaScript.
- Menambahkan overlay transisi kiri-kanan dengan panel hijau dan oranye sebelum pengguna diarahkan ke halaman role.
- Menonaktifkan tombol login saat transisi berjalan dan menampilkan status `Masuk...`.
- Membump versi `login.css` ke `v=3.3` agar browser mengambil animasi terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data atau hak akses role. Tujuan redirect tetap sama: kasir ke tabungan masuk, bendahara ke laporan, role lain ke dashboard.

**Verifikasi:**

- `C:\xampp\php\php.exe -l login.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual login untuk role admin, bendahara, dan kasir di browser.

## 2026-07-31 - Avatar Sidebar Per Role

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan tampilan profile role di sidebar agar lebih mirip avatar profile pada contoh.

**Perubahan fitur dan perilaku:**

- Menambahkan asset `assets/img/profile-avatar.png` untuk avatar profile sidebar.
- Mengubah avatar footer sidebar menjadi gambar profile dengan badge inisial role: `AD` untuk admin, `BD` untuk bendahara, dan `KS` untuk kasir.
- Menambahkan warna badge berbeda per role serta styling border dan shadow agar tampil seperti profile badge.
- Menyesuaikan styling light mode agar avatar tetap terlihat rapi.
- Mengunci ukuran avatar lewat atribut gambar dan CSS agar gambar tidak tampil pada ukuran asli saat cache CSS lama masih tersisa.
- Membump versi `style.css` ke `v=3.5` pada halaman utama agar browser mengambil styling avatar terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data maupun hak akses.

**Verifikasi:**

- Lint seluruh file PHP berhasil.
- Pencarian versi lama `style.css?v=3.2`, `v=3.3`, dan `v=3.4` tidak menemukan sisa pada file PHP.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual sebagai admin, bendahara, dan kasir untuk memastikan avatar role sesuai.

## 2026-07-31 - Sistem Pembayaran Tunai VA Qris

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan pilihan sistem pembayaran pada transaksi pembayaran sekolah.

**Perubahan fitur dan perilaku:**

- Menambahkan pilihan `Tunai`, `VA`, dan `Qris` pada form input dan edit pembayaran.
- Menambahkan validasi backend agar hanya tiga metode pembayaran tersebut yang dapat disimpan.
- Menampilkan sistem pembayaran pada daftar pembayaran, laporan web, export Excel, dan slip PDF.
- Slip PDF tidak lagi hardcoded `VA`, tetapi memakai metode dari transaksi.

**Database dan migrasi:**

- Menambahkan kolom `bayar.sistem_pembayaran` melalui `sql/add_payment_method.sql`.
- Memperbarui `sql/schema.sql` agar instalasi baru langsung memiliki kolom sistem pembayaran.

**Kompatibilitas dan data lama:**

- Data transaksi lama memakai default `VA`.
- Tidak ada perubahan struktur tabel selain penambahan kolom baru pada `bayar`.

**Verifikasi:**

- Migrasi `sql/add_payment_method.sql` berhasil dijalankan pada database lokal dan dijalankan ulang tanpa error.
- Verifikasi schema lokal menunjukkan `bayar.sistem_pembayaran` bertipe `enum('Tunai','VA','Qris')`, `NOT NULL`, default `VA`.
- Lint seluruh file PHP berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji input dan edit transaksi dengan metode `Tunai`, `VA`, dan `Qris`.

## 2026-07-31 - Penyempurnaan Tampilan Preview Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan halaman preview Excel agar tidak banyak area kosong dan paletnya sesuai tema hijau-oranye.

**Perubahan fitur dan perilaku:**

- Mengubah palet preview Excel dari ungu menjadi hijau dengan aksen oranye.
- Membuat tabel preview melebar penuh di dalam lembar preview agar area kosong berkurang.
- Menambahkan header laporan yang lebih ringkas dan kartu ringkasan total pembayaran, tabungan masuk, dan tabungan keluar.
- Mengurangi jarak kosong antar section tabel dan memperbaiki `colspan` header tabel.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Perubahan hanya memengaruhi tampilan preview dan gaya HTML export.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_excel.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.
- Pencarian warna ungu lama dan `colspan` tabel yang tidak sesuai tidak menemukan sisa di `laporan/export_excel.php`.

**Catatan tindak lanjut:**

- Uji visual di browser pada periode kosong dan periode berisi data.

## 2026-07-31 - Preview Sebelum Download Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah alur Export Excel agar pengguna dapat melihat preview sebelum mengunduh file.

**Perubahan fitur dan perilaku:**

- Menambahkan mode preview default pada `laporan/export_excel.php`.
- Download file `.xls` hanya dilakukan saat URL memakai parameter `download=1`.
- Menambahkan tombol `Download Excel` dan `Kembali` pada halaman preview.
- Menambahkan empty state untuk komponen pembayaran, transaksi pembayaran, dan transaksi tabungan saat periode belum memiliki data.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. URL export lama kini menampilkan preview terlebih dahulu, sedangkan format download tetap `.xls`.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_excel.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji manual download dari preview untuk memastikan browser menerima file `.xls` sesuai periode yang dipilih.

## 2026-07-31 - Export Laporan Tetap di Tab yang Sama

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mencegah tab browser menumpuk saat pengguna membuka export laporan.

**Perubahan fitur dan perilaku:**

- Menghapus `target="_blank"` pada tombol Export Excel dan Export PDF di `laporan/index.php`.
- Export laporan kini dibuka dari tab yang sama agar alur navigasi lebih rapi.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Perubahan hanya memengaruhi perilaku navigasi link export.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\index.php` berhasil.
- Pencarian `target="_blank"` tidak menemukan penggunaan tersisa di kode aplikasi.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji manual dari halaman Laporan di browser untuk memastikan tombol Export Excel dan Export PDF terasa sesuai alur kerja pengguna.

## 2026-07-31 - Template Slip Pembayaran Sekolah

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengganti tampilan cetak/PDF laporan pembayaran agar mengikuti contoh slip pembayaran sekolah yang diberikan pemilik proyek.

**Perubahan fitur dan perilaku:**

- Mengubah `laporan/export_pdf.php` dari kwitansi rekap bulanan menjadi slip pembayaran per transaksi.
- Menyesuaikan layout slip dengan header SD Al-Qur'an Mutiara Hikmah, data siswa dua kolom, rincian pembayaran, sisa pembayaran, pembayaran lain-lain, jumlah total, terbilang, sistem pembayaran, dan tanda tangan bagian keuangan.
- Menambahkan fungsi terbilang rupiah untuk menampilkan total pembayaran dalam bentuk teks.
- Menampilkan biaya lain, tabungan wajib, uang daftar ulang, dan sisa PSB/DU berdasarkan data transaksi yang tersedia.
- Menambahkan mode preview `contoh=1` saat periode belum memiliki transaksi agar template slip tetap dapat dilihat tanpa membuat data pembayaran palsu di database.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Output PDF kini berisi slip per transaksi pada periode yang dipilih, bukan rekap tabel bulanan.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- Query lokal menunjukkan tabel `bayar` masih kosong sehingga mode preview diperlukan untuk melihat template.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji cetak dari browser dengan data transaksi nyata untuk memastikan hasil visual sesuai format laporan yang diinginkan.

## 2026-07-30 - Dokumen Minutes of Meeting Project

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyusun dokumen Minutes of Meeting untuk kebutuhan laporan project SistemSPP.

**Perubahan fitur dan perilaku:**

- Menambahkan dokumen `documentation/MOM_SistemSPP.md` berisi informasi rapat, agenda, ringkasan pembahasan fitur, keputusan, action items, risiko, kesimpulan, dan lampiran modul project.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan perilaku aplikasi maupun struktur data.

**Verifikasi:**

- Review manual isi dokumen berdasarkan `documentation/PROJECT_CONTEXT.md`, `documentation/AI_CHANGELOG.md`, schema database, dan file modul utama.

**Catatan tindak lanjut:**

- Lengkapi placeholder peserta, waktu, tempat, PIC, dan target tanggal sesuai kebutuhan laporan.

## 2026-07-30 - Perbaikan SQL Injection Riwayat Tabungan

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menutup SQL injection pada filter NIS di riwayat tabungan tanpa mengubah perilaku laporan.

**Perubahan fitur dan perilaku:**

- Menghapus interpolasi langsung `$_GET['nis']` dari query masuk dan keluar pada `tabungan/riwayat.php`.
- Filter NIS kosong tetap menampilkan seluruh transaksi pada periode yang dipilih.
- Filter NIS terisi tetap menggunakan exact match, tetapi nilainya sekarang dikirim sebagai parameter prepared statement.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi database.

**Kompatibilitas dan data lama:**

- Format URL, filter bulan/tahun, hasil laporan normal, saldo, jurnal, pembayaran, dan transaksi legacy tetap tidak berubah.

**Verifikasi:**

- Lint seluruh file PHP, `node --check assets/js/app.js`, dan `git diff --check` dijalankan.
- Filter kosong, filter NIS valid, dan payload SQL `' OR 1=1 --` diuji melalui HTTP; payload diperlakukan sebagai nilai literal tanpa SQL error atau perluasan hasil.
- Tidak ada data transaksi yang tersisa setelah pengujian.

**Catatan tindak lanjut:**

- CSRF pada endpoint mutasi pembayaran, tabungan, dan Master Biaya Lain tetap berada di luar scope dan dicatat sebagai technical debt.

## 2026-07-30 - Integritas Child Pembayaran dan Kesiapan Schema

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menghilangkan pencocokan child pembayaran yang ambigu dan menyiapkan migrasi aman untuk database berhistori.

**Perubahan fitur dan perilaku:**

- Pembayaran baru menandai header dengan `payment_link_version=1` dan menyimpan `bayar_id` pada Daftar Ulang serta setoran Tabungan Wajib miliknya.
- Edit/hapus pembayaran sekarang hanya membaca atau mengubah child dengan `bayar_id` yang sama; transaksi tabungan manual dan penarikan tidak disentuh.
- Pembalikan setoran Tabungan Wajib mengunci saldo. Jika pembalikan akan membuat saldo negatif, operasi ditolak dan seluruh transaction di-rollback.
- Pembayaran legacy ditandai di daftar/dashboard serta tidak dapat diedit atau dihapus, termasuk dengan akses langsung ke endpoint.
- Menambahkan `documentation/PROGRESS.md` sebagai register temuan, status, bukti, dan aturan pembaruan progres.

**Database dan migrasi:**

- Menambahkan `sql/add_payment_references.sql` yang idempoten untuk `bayar.payment_link_version`, `bayar_du.bayar_id`, `transaksi_m.bayar_id`, index unik, dan foreign key cascade.
- Menyelaraskan `sql/schema.sql` untuk instalasi baru dan menambahkan `sql/verify_schema.sql` berbasis `information_schema`.

**Kompatibilitas dan data lama:**

- Tidak ada backfill atau pencocokan otomatis berdasarkan NIS, tanggal, maupun tahun ajaran.
- Histori yang telah ada tetap legacy (`payment_link_version=0` dan child `bayar_id=NULL`) dan membutuhkan rekonsiliasi manual sebelum dapat diubah.

**Verifikasi:**

- Sebelum migrasi, jumlah transaksi pada database lokal diperiksa dan bernilai nol.
- `add_payment_references.sql` dijalankan dua kali pada database lokal tanpa error; `verify_schema.sql` mengembalikan `OK` untuk tabel, kolom, index unik, dan foreign key cascade wajib.
- Lint seluruh 22 file PHP dan `git diff --check` berhasil. Peringatan normalisasi akhir baris Git tidak mengubah hasil pemeriksaan.
- Uji HTTP/database terisolasi membuat dua pembayaran pada tanggal sama serta satu setoran manual. Edit dan hapus salah satunya hanya mengubah child ber-`bayar_id` miliknya; jurnal manual tetap ada. Pembalikan yang akan membuat saldo negatif dan edit/hapus pembayaran legacy sama-sama ditolak. Semua data uji dibersihkan hingga jumlah transaksi kembali nol.

**Catatan tindak lanjut:**

- Perbaikan SQL injection, CSRF, XSS, dan temuan keamanan lain tidak termasuk ruang lingkup perubahan ini.

## 2026-07-28 - Master Biaya Lain, Master Siswa Advance, dan Uang Komite

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan SistemSPP untuk kebutuhan SDIT, membuat biaya tambahan berbasis master, memperkuat pengelolaan siswa, dan menjaga konsistensi laporan.

**Perubahan fitur dan perilaku:**

- Menambahkan CRUD admin `Master Biaya Lain` dengan nama unik, nominal positif, status aktif/nonaktif, jumlah penggunaan, dan proteksi penghapusan master terpakai.
- Mengganti baris `Uang Lain` lama dengan baris biaya dinamis pada input/edit pembayaran.
- Menyimpan nama dan nominal biaya lain sebagai snapshot agar histori tidak berubah ketika tarif master diperbarui.
- Mempertahankan detail legacy yang tidak memiliki master dan menampilkan master nonaktif hanya pada transaksi lama yang memakainya.
- Mengubah Master Siswa menjadi CRUD dasar/Advance dengan kelas 1 sampai 6, pencarian, filter kelas/status, badge status, serta aksi Edit dan Arsipkan/Pulihkan.
- Menambahkan field Advance untuk NIS Diknas, tarif siswa, POMG/Komite, daftar ulang, potongan, total turunan, dan migrasi saldo awal.
- Menjaga field Advance saat panel ditutup dan menolak perubahan saldo awal setelah siswa memiliki histori.
- Mengizinkan perubahan nomor induk dalam transaction dengan cascade foreign key.
- Menambahkan audit JSON sebelum/sesudah untuk tambah, edit, arsip, dan pemulihan siswa.
- Mengintegrasikan `siswa.POMG` sebagai Uang Komite opsional yang dilacak per siswa, bulan, dan tahun.
- Menghitung sisa Komite di frontend dan memvalidasi ulang tarif, status siswa, periode, serta batas sisa di backend.
- Membatasi siswa arsip dari pembayaran dan tabungan baru tanpa menghapus histori lama.
- Menambahkan Uang Komite dan agregasi biaya lain ke laporan web, halaman kwitansi/PDF, dan Excel.
- Menghitung ulang `total_jumlah` di backend serta mengabaikan total dan nominal biaya master dari browser.
- Menambahkan responsive styling untuk Master Siswa dan memperbaiki constraint lebar konten mobile.
- Menambahkan dokumentasi konteks proyek dan aturan changelog AI pada folder `documentation`.

**Database dan migrasi:**

- Menambahkan `master_biaya_lain` dan `bayar_biaya_lain` melalui `sql/add_master_biaya_lain.sql`.
- Memigrasikan `U_LAIN` serta empat slot biaya lama secara idempotent melalui `legacy_key` tanpa mengubah `bayar.total_jumlah`.
- Mengubah data finansial siswa menjadi `DECIMAL(15,2)`, membatasi kelas 1 sampai 6, serta menambahkan `siswa.is_active` dan index pencarian.
- Menambahkan `siswa_audit_log` dan `bayar.U_KOMITE` melalui `sql/add_student_advanced.sql`.
- Menyinkronkan `tot_pangkal` dan `tot_du` dari tarif dan potongan.
- Memperbarui `sql/schema.sql` agar instalasi baru langsung memakai struktur terkini.

**Kompatibilitas dan data lama:**

- Kolom pembayaran lama tetap ada, tetapi transaksi baru tidak lagi menulis nominal biaya lain ke kolom legacy.
- Snapshot menjaga tarif dan nama biaya transaksi lama.
- Migrasi siswa berhenti bila masih menemukan kelas di luar 1 sampai 6 dan tidak memetakan kelas secara otomatis.
- Siswa arsip tetap ikut histori, laporan, dan edit transaksi lama.

**Verifikasi:**

- Lint seluruh 22 file PHP berhasil.
- `node --check assets/js/app.js` dan `git diff --check` berhasil.
- Kedua migrasi diuji pada database salinan; migrasi siswa juga dijalankan ulang dua kali pada database lokal tanpa error.
- Pengujian HTTP/database mencakup CRUD siswa, total turunan, audit, cascade nomor induk, perlindungan saldo awal, CSRF, role non-admin, arsip/pulihkan, dan penolakan siswa arsip.
- Pembayaran Komite diuji sebagian, berlebih, edit transaksi siswa arsip, serta periode bulan berbeda.
- Laporan web, PDF, dan Excel diverifikasi memuat Uang Komite.
- Tampilan Master Siswa diperiksa melalui screenshot desktop dan mobile.
- Seluruh data pengujian dibersihkan setelah verifikasi.

**Catatan tindak lanjut:**

- Terapkan CSRF secara konsisten pada pembayaran, tabungan, dan Master Biaya Lain.
- Pertimbangkan automated integration test dan migrasi kolom uang legacy dari `DOUBLE` ke `DECIMAL` pada pekerjaan terpisah.

## 2026-07-28 - Penyempurnaan Alur Pembayaran dan Kwitansi PDF

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `232bbc1`

**Ringkasan:** Menyempurnakan alur pembayaran, representasi bulan, kelas SD, pengisian rincian biaya, serta halaman laporan cetak menjadi format yang lebih menyerupai kwitansi.

## 2026-07-28 - Light Mode Lebih Terang

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `5f35c16`

**Ringkasan:** Meningkatkan kontras dan kecerahan light mode tanpa mengganti konsep visual utama SistemSPP.

## 2026-07-28 - Refactor Struktur dan Maintainability

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `95bb7b0`, `379dd3e`

**Ringkasan:** Merapikan struktur kode dan meningkatkan keterbacaan serta maintainability tanpa perubahan domain utama yang tercatat pada pesan commit.

## 2026-07-28 - Role Management dan Perbaikan Pencarian

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `9a8e99d`, `daacb41`

**Ringkasan:** Menambahkan pengelolaan akun berbasis role, memperbarui akses UI/backend, dan mengganti pencarian menjadi search box dengan ikon yang konsisten.

## 2026-07-28 - Multi-role, Tabungan, dan Export Laporan

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `35e1dd9`

**Ringkasan:** Menambahkan role admin/bendahara/kasir, alur tabungan masuk/keluar, riwayat tabungan, laporan keuangan, dan export.

## 2026-07-27 - Implementasi Awal SistemSPP

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `2943f44`

**Ringkasan:** Membuat fondasi aplikasi SistemSPP dan desain Material 3 sebagai baseline repository.

## Template Entri Berikutnya

Salin template ini ke bagian paling atas setelah `Aturan Pencatatan`:

```markdown
## YYYY-MM-DD - Judul Perubahan

**AI/Aktor:** Nama AI/model atau developer
**Tujuan:** Ringkasan permintaan dan hasil yang diinginkan.

**Perubahan fitur dan perilaku:**

- Perubahan yang benar-benar diterapkan.

**Database dan migrasi:**

- Nama migrasi, perubahan schema, atau `Tidak ada`.

**Kompatibilitas dan data lama:**

- Dampak terhadap data/API/perilaku lama atau `Tidak ada`.

**Verifikasi:**

- Command dan skenario yang benar-benar dijalankan.

**Catatan tindak lanjut:**

- Risiko tersisa, technical debt, atau `Tidak ada`.
```
