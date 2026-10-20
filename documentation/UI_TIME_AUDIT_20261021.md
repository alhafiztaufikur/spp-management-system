# Audit tampilan dan waktu transaksi — 21 Oktober 2026

Perbaikan diterapkan pada workspace lokal, dengan target tetap `db_spp_test2`. Remote diperiksa ulang melalui fetch dan tetap di `d681e84202a62cd6f3816bb0a6cf6d647afd9eef`; tidak ada pembaruan untuk di-fast-forward. Pekerjaan audit sebelumnya dipertahankan. Permintaan pengguna berikutnya juga mencakup commit/push. Konfigurasi lokal `koneksi.php` dipertahankan dan dikecualikan dari commit. Tidak ada migrasi skema atau pembaruan massal timestamp pada pekerjaan tampilan/waktu ini.

## Hasil pembacaan waktu

| Cakupan | Pembayaran | Jam asli pulih | Hanya tanggal | Tanpa tanggal |
|---|---:|---:|---:|---:|
| SD | 46.852 | 446 | 43.342 | 3.064 |
| SMP | 60.181 | 286 | 54.811 | 5.084 |
| SMA | 11.211 | 61 | 10.010 | 1.140 |
| Semua Unit | **118.244** | **793** | **108.163** | **9.288** |

Seluruh 793 jam dicocokkan secara terpisah menggunakan parser Python terhadap `raw_data.jam`, lalu dibandingkan berdasarkan unit dan ID transaksi hasil pencocokan sumber. Pembaca PHP menghubungkan **unit, source ID, dan row key**; NIS/nama tidak digunakan untuk mencocokkan transaksi. Timestamp tersimpan seluruh sampel tersebut tetap memiliki padding `00:00:00` semula.

Format SQL Server dengan tujuh digit pecahan detik dinormalisasi untuk pembacaan. Parser memeriksa kalender, AM/PM, spasi tambahan, rentang jam/menit/detik, nilai kosong, dan nilai tidak valid. Jam sumber dibaca sebagai WIB. `12 AM` menjadi `00`; `12 PM` tetap `12`. Tengah malam yang benar-benar diketahui tetap ditampilkan sebagai `00:00:00 WIB`.

Metadata `time_precision` membedakan tanggal/jam lengkap, hanya tanggal, dan keduanya tidak tersedia. Halaman, JSON histori, detail, daftar ulang, aktivitas, struk, PDF, dan Excel menggunakan pembacaan yang sama. Ketika jam tidak diketahui, tampil **Jam tidak tercatat**. Tanggal kosong tidak diganti dengan tanggal impor atau tanggal saat ini.

Waktu koreksi yang sudah diterapkan mengalahkan jam arsip. Jam arsip hanya melengkapi tanggal sumber yang sesuai. Tidak ditemukan konflik pada 793 bukti aktual. Kasus konflik buatan diuji: timestamp tersimpan yang sah dipertahankan dan bukti konflik tetap ditandai oleh pembaca.

**Dibuat pada** pada transaksi Legacy ditampilkan **Tidak tercatat**. **Diimpor ke SistemSPP** menggunakan waktu penyelesaian batch secara terpisah. **Diubah pada** berasal dari audit koreksi yang benar-benar diterapkan; pengajuan yang masih menunggu tidak dianggap perubahan transaksi. Aktivitas sumber juga dibedakan dari bukti pembuatan transaksi.

## Perubahan tampilan dan perilaku edit

- Input Pembayaran: jarak dan ukuran kontrol dirapikan, nominal rata kanan, keterangan komponen lebih jelas, panel DU dibatasi layar, serta tanggal dan jam histori ditampilkan dua baris.
- Riwayat Pembayaran: filter histori tanpa tanggal memiliki ikon, jumlah sesuai filter, dan penjelasan pendamping. Parameter URL, Semua Periode, sumber, pemilik, dan identitas transaksi dipertahankan.
- Riwayat Daftar Ulang: filter empat kolom desktop, dua tablet, satu ponsel; kontrol 48 px dan tindakan pada baris tersendiri. Jumlah Legacy ditempatkan setelah rekap. Detail memakai teks utama minimal 14 px dan judul pemilik 20 px; status historis dan operasional tetap terpisah.
- Edit Legacy: form utama dan ringkasan transaksi dua kolom, bertumpuk pada layar kecil. Tanggal dan jam dipisahkan; jam memakai format 24 jam. Pratinjau, alasan, CSRF, versi, persetujuan, pengiriman ulang, dan tautan kembali memakai alur existing.
- Rekap Tabungan: perapihan dibatasi pada kartu, filter tiga kontrol sama ukuran, identitas pemilik, saldo, aktivitas, dan tindakan dalam bagian rekap. Siswa serta rekening non-siswa tetap tercakup.
- Laporan Global: kolom status sekitar 240 px pada desktop; label membungkus dengan tinggi baris mengikuti isi. Cetak/PDF/Excel tetap memakai renderer existing dan membungkus status panjang.

Edit native yang mengirim tanggal saja mempertahankan jam lama. Pembayaran baru tetap menggunakan waktu server. Edit nominal/catatan Legacy mempertahankan timestamp tersimpan; jam hasil pembacaan arsip tidak otomatis disalin ke database. Perubahan tanggal/jam eksplisit tetap melewati pratinjau dan audit.

Pembacaan arsip dilakukan berkelompok. Hanya bukti jam yang diperlukan disertakan dalam proyeksi SQL yang dimaterialisasi, sehingga pengurutan memakai waktu hasil pembacaan sebelum pagination dan ID sebagai pembeda terakhir. Agregasi nominal yang hanya membutuhkan kalender tidak mengulang pembacaan jam. Cache privat menyimpan bukti arsip yang immutable dengan identitas server/database/unit, fingerprint sumber, checksum, batas umur, dan penulisan atomik; audit koreksi selalu dibaca kembali dari database. Cache kosong/rusak dibangun ulang. Daftar tanpa filter kompleks memilih ID dan waktu dari proyeksi ringkas, kemudian memuat identitas/rincian halaman dalam satu kelompok; filter kompleks mempertahankan pembaca lengkap. Seluruh data dan urutan dibandingkan dengan hasil pembacaan penuh. Tidak ada indeks atau constraint baru.

## Pengujian

Semua pengujian yang menulis transaksi dilakukan pada MySQL terisolasi port **58360**. Setelah uji selesai, salinan dipulihkan lagi dari cadangan. Hash seluruh 62 tabel, signature skema, dan seluruh definisi objek cocok dengan baseline. Estimasi ukuran fisik penyimpanan dapat berbeda setelah restore; nilai tersebut bukan definisi skema atau isi baris. Server uji dihentikan sebelum pengukuran akhir.

| Pemeriksaan | Hasil |
|---|---|
| Model/skema/rekonsiliasi seluruh unit dan 10 template Global | 40 pada salinan; 37 pemeriksaan baca ulang pada database utama lulus |
| HTTP modul, Data Master, histori, filter, arsip, isolasi, dan ekspor | 121 permintaan, tanpa kegagalan |
| PDF/Excel Global, Surat Laporan, Laporan Transaksi, struk terpilih | 98 permintaan, tanpa kegagalan |
| Buku tabungan siswa/non-siswa dan struk | 12 dokumen PDF lulus |
| Parser waktu, tengah malam, format tidak valid, dan input edit | 36 kasus lulus |
| Pembacaan 793 jam dari arsip secara terpisah | 793/793 cocok; SD 446, SMP 286, SMA 61 |
| Waktu pada detail/JSON/struk/laporan/PDF/Excel | Sampel jam lengkap, hanya tanggal, dan tanpa tanggal pada tiga unit lulus |
| Koreksi waktu, prioritas arsip, aktivitas, replay, dan rollback | 69 pemeriksaan lulus |
| Form edit Legacy melalui HTTP | 36 pemeriksaan lulus |
| Pengajuan, persetujuan, penolakan hak akses, dan replay | 18 pemeriksaan lulus |
| Siklus pembayaran/tabungan native melalui HTTP | 60 pemeriksaan lulus |
| Siklus koreksi Legacy, tabungan, dan tagihan native pada salinan | 27 pemeriksaan lulus |
| Tampilan dan keyboard pada 390/768/1024/1440 px | 37 pemeriksaan lulus |
| Status pada matriks SPP tahunan dan per-item seluruh unit | 8 pemeriksaan tambahan lulus |
| PDF/Excel matriks tahunan dan per-item setelah perapihan terakhir | 16 dokumen tambahan lulus |
| Pagination/pengurutan ringkas dibandingkan pembacaan penuh | 36 pemeriksaan lulus |

Uji waktu native menetapkan jam `08:12:34`, kemudian mengirim edit tanggal saja dan memeriksa bahwa jam tersebut tetap sama. Uji Legacy HTTP memeriksa langsung nilai tersimpan sesudah edit catatan: padding timestamp tetap sama; perubahan jam eksplisit baru menulis jam yang dipilih. Pengujian persetujuan membuktikan pengajuan tidak mengubah timestamp, dan tengah malam eksplisit tetap diketahui setelah disetujui.

Excel dibuka kembali sebagai ZIP/XML. Tanggal yang diketahui tetap berupa sel numerik dengan format waktu sesuai ketepatannya; jam tidak diketahui memakai format tanggal dengan keterangan. Sel nominal tetap numerik. PDF dibaca kembali untuk memeriksa identitas, nominal, jam, keterangan kosong, status panjang, dan kelengkapan dokumen. Tidak mengganti renderer.

## Kelengkapan per modul dan unit

| Modul | SD | SMP | SMA | Semua Unit |
|---|---|---|---|---|
| Input, histori, rincian dan cetak pembayaran | Lulus | Lulus | Lulus | Lulus, baca saja |
| Riwayat dan detail daftar ulang | Lulus | Lulus | Lulus | Lulus |
| Siswa, kelas/rombel, SPP, biaya lain, daftar ulang | Lulus | Lulus | Lulus | Lulus |
| Tabungan masuk/keluar, histori, rekap, buku dan struk | Lulus | Lulus | Lulus | Lulus, baca saja |
| Laporan Transaksi/Umum dan dokumen | Lulus | Lulus | Lulus | Lulus |
| Seluruh 10 template Global dan ekspor | Lulus | Lulus | Lulus | Lulus |

Filter pembayaran 2022–2026 tetap menemukan **23.309 SD, 21.160 SMP, dan 5.209 SMA**, total **49.678** dengan nominal **Rp45.100.701.752**. Histori Adam tetap **54 pembayaran**. Rekonsiliasi **2.418 rekening** dan **95.575 jurnal tabungan** tetap sesuai baseline; saldo peralihan dipisahkan dari setoran. Histori DU tanpa tanggal tetap **9.288** dan tidak menerbitkan tagihan baru. Status kewajiban yang tidak memiliki bukti tidak diubah menjadi lunas.

## Performa akhir

Pengukuran akhir memakai database utama melalui helper HTTP yang hanya menerima GET; server MySQL salinan sudah dihentikan. Semua angka adalah pengukuran lokal, bukan jaminan waktu pada perangkat lain. Mesin juga menjalankan IDE/browser pengguna. Pembacaan arsip pertama membangun cache privat; permintaan berikutnya membaca bukti arsip yang sudah diverifikasi, sedangkan koreksi tetap diperiksa dari database.

| Halaman | SD | SMP | SMA | Semua Unit |
|---|---:|---:|---:|---:|
| Riwayat Pembayaran, 2022–2026 | 2,66 dtk | 2,62 dtk | 0,63 dtk | 3,38 dtk |
| Riwayat Daftar Ulang | 2,17 dtk | 1,81 dtk | 0,55 dtk | 2,66 dtk |
| Data Siswa | 3,18 dtk | 2,73 dtk | 0,32 dtk | 0,39 dtk |
| Riwayat Tabungan, semua periode | 5,55 dtk | 1,52 dtk | 0,51 dtk | 4,30 dtk |
| Laporan Transaksi | 1,40 dtk | 1,26 dtk | 0,36 dtk | 2,06 dtk |

Sesudah optimasi pemilihan halaman, Riwayat Pembayaran Semua Unit turun dari **5,43 menjadi 3,38 detik** pada rangkaian pengukuran ini. Tabel/urutan seluruh unit tetap sama dengan pembacaan penuh. Template Global Semua Unit pada dua pengukuran: status **4,06–4,24 detik**, SPP tahunan **1,36–1,50 detik**, tabungan siswa **4,18–4,21 detik**, dan tunggakan **2,45–2,52 detik**. Direktori Surat Laporan **2,80–4,97 detik**.

Target halaman ≤3 detik **belum tercapai untuk seluruh halaman**. Perbandingan kode sebelum/sesudah melalui dua helper baca saja menunjukkan beban lambat juga terdapat pada versi sebelum pekerjaan ini; pengukuran antarputaran bervariasi. Bukti mentah disimpan di `performance-comparison.json`, `performance-before-pagination.json`, dan `performance-complete.json`. Tidak menghilangkan histori, membatasi ekspor lengkap, atau mengubah perhitungan untuk mengejar angka target.

| Ekspor yang dibuka kembali | Hasil |
|---|---|
| Sampel struk/laporan PDF dengan jam lengkap/tanggal saja | 0,76–2,69 dtk |
| Sampel histori/laporan Excel dengan tanggal numerik | 0,34–2,73 dtk |
| Excel saldo SD, 794 baris detail | 1,58 dtk |
| PDF saldo SD, 794 baris detail/33 halaman, render pertama | **12,81 dtk** |
| PDF sama, cache tervalidasi | 0,90 dtk |
| Excel **seluruh** pembayaran, 118.244 baris | 24,95 dtk; jumlah dan nominal per unit cocok |
| Excel **seluruh** jurnal, 95.575 baris | 18,29 dtk; jumlah dan nominal per unit cocok |

Target ekspor biasa ≤10 detik tercapai pada sampel Excel dan dokumen kecil. PDF saldo 33 halaman saat render pertama masih melewati target; timing menunjukkan sekitar **11,97 detik** berada pada renderer PDF existing. Cache hanya digunakan setelah model/filter/hak akses dibaca kembali dan divalidasi. Ekspor histori besar diukur terpisah dan tetap lengkap. Batas performa ini dicatat sebagai hasil pengujian, bukan dinyatakan selesai memenuhi seluruh target.

## Cadangan, bukti, dan pemulihan

Bukti privat berada di `C:\laragon\backups\spp-management-system\ui_time_audit_20261021`:

- `source-before.zip`: kode sebelum pekerjaan, termasuk dependency existing.
- `before.sql`: cadangan penuh `db_spp_test2`, termasuk routine, trigger, view, dan constraint; telah diuji restore dua kali pada server terisolasi.
- `before-live.json`, `before-restored.json`, `after-restored.json`, `clone-restored.json`: hash tabel serta bukti pemulihan.
- `after-live.json`, `live-unchanged.json`: bukti seluruh 62 tabel dan skema utama tidak berubah.
- `time-fixture.json`, `time-readback*.json`, `time-document-results.json`, `time-mutation.json`, `time-edit-http-results.json`, `time-approval.json`: bukti waktu dan alur koreksi.
- `readback.json`, `http-results.json`, `document-results.json`, `savings-documents.json`, `mutations.json`, `mutation-http-results.json`: hasil audit modul dan dokumen.
- `artifacts/`: screenshot serta PDF/XLSX hasil pembacaan kembali; `ui-browser-results.json`: ukuran layar dan hasil pemeriksaan.
- `performance-*.json`: pengukuran akhir, perbandingan dengan kode sebelum perubahan, dan ekspor histori lengkap tanpa pembatasan.
- `pagination.json`, `status-matrix-browser-results.json`, `final-matrix-document-results.json`: pembandingan pengurutan/pagination serta pembungkusan label dan dokumen matriks tahunan/per-item.
- `source-after.zip`, `artifact-hashes.json`: kode akhir dan checksum cadangan/bukti utama.

Contoh screenshot: [edit dua kolom](C:/laragon/backups/spp-management-system/ui_time_audit_20261021/artifacts/ui-1440-edit.png), [detail/filter daftar ulang](C:/laragon/backups/spp-management-system/ui_time_audit_20261021/artifacts/ui-1-registration.png), [dropdown DU ponsel](C:/laragon/backups/spp-management-system/ui_time_audit_20261021/artifacts/ui-390-input-dropdown.png), [status Global](C:/laragon/backups/spp-management-system/ui_time_audit_20261021/artifacts/ui-1-global-status.png), dan [tema gelap](C:/laragon/backups/spp-management-system/ui_time_audit_20261021/artifacts/ui-dark-registration.png).

Untuk mengembalikan perbaikan kode ini, bandingkan file terkait dengan `source-before.zip` pada folder pemeriksaan terpisah, lalu kembalikan hanya perubahan pekerjaan ini. Pertahankan `koneksi.php`, pekerjaan audit sebelumnya, dan perubahan baru pengguna. Tidak diperlukan rollback database untuk mengembalikan perubahan tampilan/pembacaan ini karena data utama tidak ditulis ulang.

Jika pemulihan database penuh diperlukan untuk kejadian lain, hentikan input, ambil cadangan keadaan terbaru, aktifkan pemeliharaan existing, periksa server/database tujuan, dan pulihkan `before.sql` melalui CLI. Uji dahulu pada server terisolasi. Pemulihan penuh mengganti transaksi sesudah waktu cadangan; gunakan prosedur pada [audit impor sebelumnya](IMPORT_AUDIT_20261020.md) dan periksa kembali hash, skema, rekonsiliasi, serta akses sebelum membuka input. SQL asli pengguna dipertahankan.

Status lulus merujuk skenario dan data yang diuji. Pengujian tidak mencakup printer fisik, Excel desktop interaktif, atau beban banyak pengguna serentak. Data baru dan perubahan di luar alur aplikasi tetap memerlukan pemeriksaan tersendiri.
