# Progres SistemSPP

Dokumen kerja ini melacak pekerjaan teknis yang sedang dan sudah dilakukan. Perbarui status, bukti verifikasi, dan tindak lanjut setiap kali ada perubahan yang memengaruhi data, alur bisnis, atau keamanan.

## Aturan pembaruan

- Tambahkan atau perbarui satu baris temuan pada tabel di bawah dalam pekerjaan yang sama dengan perubahan kode.
- Tulis bukti yang benar-benar dijalankan; jangan menyebut pengujian yang belum dilakukan.
- Jangan mengubah histori legacy secara otomatis hanya untuk menutup temuan. Catat prosedur rekonsiliasi manual bila diperlukan.
- Setelah pekerjaan selesai, tambahkan ringkasan yang konsisten di `AI_CHANGELOG.md` dan perbarui `PROJECT_CONTEXT.md` bila kontrak sistem berubah.

## Baseline database dan migrasi

| Item | Status | Catatan |
| --- | --- | --- |
| Database pengembangan | Tersedia | `db_spp` lokal; saat migrasi ini diterapkan, tabel transaksi pembayaran dan tabungan tidak berisi data. |
| Instalasi baru | Siap | `sql/schema.sql` sudah memuat relasi pembayaran aman. File ini destruktif dan tidak boleh dijalankan pada database berisi data. |
| Upgrade database berhistori | Siap | Jalankan migrasi bertahap setelah backup, termasuk `sql/add_academic_year_billing.sql`. Migrasi idempoten dan melakukan backfill DU yang memiliki konteks valid. |
| Pemeriksaan schema | Siap | Jalankan `sql/verify_schema.sql`; hasil harus seluruhnya `OK`. |

## Register temuan dan progres

| ID | Temuan | Prioritas | Status | Bukti / tindak lanjut |
| --- | --- | --- | --- | --- |
| FIN-001 | Edit/hapus pembayaran dahulu dapat menyentuh Daftar Ulang atau jurnal tabungan lain dengan NIS dan tanggal/tahun yang sama. | Kritis | Selesai | `bayar_du.bayar_id` tetap menjadi relasi child pembayaran. Relasi tabungan pembayaran sudah diputus oleh PAY-012. |
| FIN-002 | Pembalikan setoran Tabungan Wajib dapat membuat saldo tabungan negatif. | Kritis | Ditutup oleh PAY-012 | Alur setoran tabungan lewat pembayaran dihapus; cleanup menolak penghapusan linked saving lama bila saldo tidak cukup. |
| DB-001 | Database lama belum memiliki relasi pembayaran eksplisit. | Kritis | Selesai | Migrasi idempoten dan `verify_schema.sql` tersedia; migrasi dijalankan dua kali pada database lokal dan pemeriksaan mengembalikan `OK`. |
| COMP-001 | Histori pembayaran lama tidak dapat dibuktikan relasinya secara aman. | Tinggi | Diterima / dibatasi | Tetap legacy (`payment_link_version=0`, child `bayar_id=NULL`), tidak dicocokkan otomatis, dan hanya dapat direkonsiliasi manual. |
| SEC-001 | CSRF serta lokasi SQL injection/XSS lain masih belum ditangani menyeluruh. | Tinggi | Terbuka | Perbaikan SEC-002 menutup filter NIS riwayat tabungan; endpoint mutasi dan lokasi lain tetap menjadi pekerjaan terpisah. |
| SEC-002 | Filter NIS riwayat tabungan merangkai input URL langsung ke query SQL. | Kritis | Selesai | Kedua cabang query `UNION` sekarang memakai placeholder prepared statement. Uji filter kosong, NIS valid, dan payload SQL dilakukan tanpa error atau perluasan hasil. |
| PAY-001 | Input pembayaran sebelumnya belum mencegah komponen yang sudah terbayar lebih dari total tagihan. | Tinggi | Selesai | Form menampilkan alert, sisa dianggap nol, input komponen dikunci, dan backend menolak input yang melebihi sisa. |
| PAY-002 | Biaya lain sebelumnya harus lunas sesuai nominal master dan belum mendukung cicilan. | Tinggi | Selesai | Baris biaya lain menampilkan `Total`, `Sudah`, `Sisa`, dan `Bayar`; nominal snapshot menyimpan nilai cicilan transaksi. |
| PAY-003 | Reset form masih dapat meninggalkan alert overpayment. | Sedang | Selesai | Reset membersihkan alert, state row overpaid, custom validity, dan baris biaya lain. |
| PAY-004 | Pencatatan daftar ulang belum memakai tagihan siswa secara kontekstual. | Tinggi | Selesai | Backend menghitung tahun ajaran dari periode, mengambil tagihan server-side, dan menghitung sisa melalui relasi `bayar_du.tagihan_daftar_ulang_id`. |
| PAY-006 | Master DU belum menerbitkan kewajiban nyata sehingga siswa nol pembayaran tidak muncul. | Kritis | Selesai | Tahun ajaran, penempatan, dan tagihan materialized ditambahkan; riwayat membaca tagihan lalu `LEFT JOIN` pembayaran. |
| PAY-007 | Mode tahunan Januari–Desember melintasi dua tahun ajaran. | Tinggi | Selesai | Opsi transaksi baru ditangguhkan dan backend menolak `annual`; histori batch lama tetap dapat dibaca dan dicetak. |
| PAY-005 | Daftar pembayaran, dashboard, dan data siswa masih mengharuskan klik tombol edit. | Rendah | Selesai | Baris tabel dapat diklik langsung untuk masuk edit; tombol aksi tetap berjalan sendiri. |
| PAY-006 | Master daftar ulang belum punya CRUD admin dan belum menjadi sumber tarif resmi. | Tinggi | Selesai | `master_daftar_ulang.php`, unique key `Daftar_ulang(th_ajaran, kelas)`, migrasi idempoten, dropdown tahun ajaran sistem Juli-Juni, dan validasi backend pembayaran DU sudah disiapkan. |
| PAY-007 | Edit pembayaran dari riwayat belum otomatis mengikat data siswa dan histori terbaru saat halaman dibuka. | Tinggi | Selesai | Edit sekarang auto-bind konteks siswa, mengecualikan transaksi aktif dari histori, memakai master/tarif terbaru, dan query histori edit memakai prepared statement. |
| PAY-008 | Tahun ajaran master daftar ulang masih bisa diketik manual dan default pembayaran belum mengikuti tahun ajaran aktif sekolah. | Sedang | Selesai | Master DU memakai dropdown tahun ajaran aktif +/- 3 tahun, input pembayaran default ke tahun ajaran aktif, kelas DU mengikuti kelas siswa, dan input DU terkunci bila master kombinasi belum ada. |
| PAY-009 | Tarif Master Daftar Ulang yang tersimpan belum otomatis menerbitkan tagihan dan informasi DU tampil di bagian Potongan & Tabungan. | Tinggi | Selesai | Tahun draf memakai aksi atomik Simpan & Terbitkan Tagihan; input/edit membaca tagihan materialized dan menampilkan total, terbayar, sisa, kelas, tahun ajaran, status, serta warning langsung pada baris Daftar Ulang. |
| PAY-010 | Makan, Sorga, dan Infaq memiliki kolom transaksi tetapi tidak mempunyai sumber total tagihan sehingga selalu nol. | Tinggi | Selesai | Tiga tarif satu kali ditambahkan pada Data Siswa Advance; input/edit menghitung cicilan dari histori, mengunci tarif nol/lunas, dan backend menolak pembayaran di atas sisa serta penurunan tarif di bawah nominal terbayar. |
| PAY-011 | Riwayat Daftar Ulang memuat seluruh tagihan dan seluruh cicilan ke PHP sehingga tidak efisien untuk ratusan siswa. | Sedang | Selesai | Agregasi, ringkasan, LIMIT/OFFSET, dan detail per halaman dipindahkan ke SQL; UI menyediakan ukuran 25/50/100 serta navigasi desktop/mobile yang mempertahankan filter. |
| PAY-012 | Tabungan masih bisa dicatat dari Input Pembayaran sehingga bercampur dengan modul Tabungan Masuk/Keluar. | Tinggi | Selesai | Field tabungan dihapus dari input/edit pembayaran, backend menolak `tabungan_wajib > 0`, struk/laporan pembayaran tidak lagi menghitung linked tabungan, dan cleanup `sql/remove_payment_linked_savings.sql` menghapus jurnal pembayaran lama. |
| PAY-013 | SPP dapat dibayar melompati bulan sebelumnya sehingga berisiko ada tunggakan bolong dalam satu tahun ajaran. | Tinggi | Selesai | Backend dan UI kini mewajibkan SPP bulan sebelumnya dalam tahun ajaran Juli-Juni lunas sebelum bulan berikutnya dibayar; edit mengecualikan transaksi aktif dan edit/hapus prasyarat ditolak bila membuat bulan setelahnya bolong. |
| PAY-014 | Biaya Lain belum menjadi kewajiban siswa dan pilihan master saja tidak dapat membedakan siswa yang ditagih. | Tinggi | Selesai | `tagihan_biaya_lain` diterbitkan idempoten ke target siswa dengan snapshot tarif; input/edit hanya menerima tagihan siswa dan mendukung cicilan sampai lunas. |
| DB-002 | Tingkat kelas 1–6 belum dapat membedakan rombel dan perubahan kelas berisiko mengubah konteks histori. | Tinggi | Selesai | Master Kelas/Rombel, placeholder migrasi, relasi kelas aktif, snapshot penempatan/tarif, dan snapshot transaksi ditambahkan tanpa workflow penempatan massal. |
| REP-001 | Export Excel langsung download tanpa preview. | Sedang | Selesai | Alur diubah menjadi preview terlebih dahulu, lalu tombol `Download Excel`. |
| REP-002 | Slip PDF pernah bergantung pada print browser dan memunculkan header/footer URL. | Tinggi | Selesai | Slip dirender server-side memakai Dompdf dengan ukuran landscape `210mm x 148mm`; header/footer browser tidak ikut tercetak. |
| REP-003 | Filter tanggal laporan memakai dua input terpisah dan kurang nyaman untuk konsep rentang `S/D`. | Sedang | Selesai | UI laporan memakai satu kontrol date-range custom lokal dengan popover Mulai/Sampai, sementara backend tetap membaca `tanggal_awal` dan `tanggal_akhir`. |
| REP-004 | Area `Sisa Pembayaran` pada struk terlalu ramai dan tidak sesuai kuitansi acuan. | Sedang | Selesai | Struk biasa dan struk tahunan hanya menampilkan `Sisa PSB` dan `Sisa DU`; rincian pembayaran utama tetap tidak berubah. |
| REP-005 | Laporan Global masih satu halaman besar dan belum menyediakan template status, SPP tahunan, tabungan, serta setoran kas. | Tinggi | Selesai | Laporan Global menjadi katalog tujuh template dengan registry/query bersama, pagination, web, cetak, PDF, Excel, dan filter konsisten. |
| UI-001 | Beberapa tampilan mobile dan dark mode kurang rapi/user friendly. | Sedang | Selesai bertahap | Sidebar mobile, logout, bottom nav, preview Excel, riwayat tabungan, avatar role, dan palet dark mode sudah direvisi. |
| UI-002 | Halaman Dashboard menampilkan metrik all-time sehingga kurang relevan untuk aktivitas operasional harian (closing kasir). | Sedang | Selesai | Dashboard dirombak total menjadi dashboard closing harian; fokus pada transaksi hari ini, kas fisik bersih, quick actions operasional, dan memuat tabel rekap setoran kas harian. |

## Log perubahan alur aplikasi

### Login dan role

- Login memakai tampilan portal pembayaran sekolah dengan palet hijau-oranye.
- Setelah login, transisi masuk dibuat lebih halus dengan animasi dari sisi kiri/kanan sesuai konteks halaman.
- Role yang dipakai tetap `admin`, `bendahara`, dan `kasir`; akses backend divalidasi dengan `requireRole()`.
- Sidebar profile setiap role memakai avatar gambar, bukan inisial teks.
- Pada mobile, logout tetap tersedia melalui sidebar dan bottom navigation tidak menutup akses menu penting.

### Dashboard dan navigasi tabel

- Dashboard beralih fungsi dari ringkasan global menjadi dashboard *closing* harian yang menampilkan data penerimaan kotor, tunai bersih, kas disetorkan, serta tabel rekap kas khusus untuk hari ini.
- Baris tabel pada seluruh aplikasi umumnya dapat diklik langsung untuk membuka halaman detail/edit.

### Data siswa

- Form siswa memiliki mode dasar dan mode `Advance`.
- Mode `Advance` memuat NIS Diknas, tarif pembayaran, potongan, total turunan, dan saldo awal.
- Siswa tidak dihapus permanen; status diubah menjadi arsip/nonaktif agar histori pembayaran dan laporan tetap aman.
- Baris siswa pada daftar siswa dapat diklik langsung untuk masuk mode edit.

### Pembayaran siswa

- Sistem pembayaran transaksi berubah menjadi pilihan `Tunai`, `VA`, dan `Qris`.
- Total pembayaran dihitung ulang di backend dari komponen resmi, bukan percaya penuh pada nilai browser.
- Pembayaran Komite dan SPP dihitung per periode `NO_INDUK + BULAN + TAHUN`.
- Komponen pembayaran yang sudah terbayar lebih besar dari total menampilkan alert, sisa menjadi nol, dan input dikunci.
- Backend ikut menolak nominal negatif, periode tidak valid, metode pembayaran tidak valid, dan pembayaran yang melebihi sisa tagihan.
- Edit/hapus pembayaran hanya menyentuh child yang terhubung dengan `bayar_id`, bukan mencocokkan berdasarkan NIS/tanggal secara rapuh.
- Edit pembayaran dari riwayat memakai master/tarif terbaru, mempertahankan nominal input transaksi aktif, dan mengecualikan transaksi aktif dari hitungan `Sudah Terbayar`.

### Daftar ulang

- Pembayaran daftar ulang dicatat pada `bayar_du` dengan `bayar_id`, `no_induk`, `kelas`, `th_ajaran`, dan `jumlah`.
- Master `Daftar_ulang` menjadi sumber resmi nominal per `kelas + th_ajaran` dan dikelola melalui halaman admin `Master Daftar Ulang`.
- Tahun ajaran daftar ulang mengikuti kalender sekolah Juli-Juni; tahun ajaran aktif dipilih otomatis berdasarkan tanggal berjalan.
- Kombinasi kelas dan tahun ajaran dijaga unik oleh database agar tidak ada tarif ganda.
- Bila master `Daftar_ulang` kosong total, sistem fallback ke nominal daftar ulang dari tabel `siswa` agar transaksi lama tetap bisa digunakan.
- Bila master sudah ada tetapi kombinasi kelas/tahun belum diatur, form menampilkan warning dan nominal DU menjadi nol sampai master dilengkapi.
- Pada form pembayaran, kelas DU default mengikuti kelas siswa dan input DU dikunci saat master kombinasi belum tersedia.
- Perhitungan `Sudah Terbayar` dan `Sisa` daftar ulang dipisah per siswa, kelas daftar ulang, dan tahun ajaran.

### Master daftar ulang

- Admin dapat menambah dan mengubah nominal daftar ulang berdasarkan tahun ajaran dan kelas.
- Tahun ajaran dipilih dari dropdown sistem, bukan input manual bebas.
- Tahun ajaran divalidasi dengan format `YYYY/YYYY` dan tahun kedua harus tahun pertama + 1.
- Kelas dibatasi `1` sampai `6`, nominal wajib lebih dari nol, dan duplikat kelas/tahun ditolak.
- Data master diurutkan dari tahun ajaran terbaru lalu kelas.
- Penghapusan master ditolak bila kombinasi kelas/tahun tersebut sudah dipakai pada `bayar_du`.

### Master biaya lain

- Biaya tambahan dipindahkan ke master `master_biaya_lain`.
- Form pembayaran bisa menambahkan beberapa baris biaya lain.
- Setiap baris biaya lain menampilkan `Jenis`, `Total`, `Sudah`, `Sisa`, `Bayar`, dan `Keterangan`.
- Biaya lain dapat dicicil; `bayar_biaya_lain.nominal_snapshot` menyimpan nominal yang benar-benar dibayar pada transaksi tersebut.
- Pembayaran biaya lain yang melebihi sisa menampilkan alert di UI dan ditolak di backend.
- Master biaya lain yang sudah dipakai tidak mengubah histori transaksi lama karena nama dan nominal transaksi disimpan sebagai snapshot.

### Lihat pembayaran

- Halaman lihat pembayaran menampilkan tanggal dan jam bayar.
- Bila transaksi pernah diupdate, sistem menampilkan keterangan waktu update.
- Baris transaksi dapat diklik langsung untuk edit tanpa harus menekan tombol `Edit`.
- Saat edit dibuka dari riwayat, rincian pembayaran langsung terisi dari konteks siswa dan histori terbaru.
- Transaksi legacy dengan `payment_link_version=0` tetap dibatasi dan tidak diedit/hapus otomatis.

### Tabungan

- Tabungan masuk dan keluar tetap berjalan dengan saldo per siswa.
- Penarikan tabungan tidak boleh melebihi saldo.
- Pembayaran siswa tidak lagi membuat setoran tabungan; kasir harus memakai menu Tabungan Masuk.
- POST lama dengan `tabungan_wajib > 0` ditolak agar cache browser tidak menghidupkan alur lama.
- Riwayat tabungan mobile sudah dirapikan pada filter, tombol, dan tabel.

### Laporan dan export

- Laporan web menampilkan rekap pembayaran, tabungan masuk, tabungan keluar, detail pembayaran, dan rekap tabungan per periode.
- Export Excel berubah dari download otomatis menjadi preview terlebih dahulu, lalu download setelah pengguna menekan tombol.
- Preview Excel memakai palet hijau-oranye dan layout mobile yang lebih rapi.
- Export PDF slip pembayaran memakai Dompdf server-side, bukan print browser.
- Slip pembayaran memakai ukuran landscape `210mm x 148mm` dan tidak memunculkan header/footer URL browser.
- Laporan periode kosong dapat menampilkan contoh slip untuk kebutuhan contoh kwitansi.
- Detail transaksi pembayaran mendukung cetak slip per transaksi atau beberapa transaksi terpilih.

### Tampilan mobile dan tema

- Sidebar, bottom navigation, tombol aksi, tabel, dan preview laporan disesuaikan untuk viewport kecil.
- Dark mode diganti ke palet yang lebih nyaman dibaca dengan aksen hijau-oranye.
- Avatar profile role menggantikan badge inisial agar sidebar lebih rapi.

## Kontrak kompatibilitas pembayaran

- Pembayaran baru diberi `bayar.payment_link_version=1` dan setiap Daftar Ulang miliknya menyimpan `bayar_id`.
- Pembayaran berhistori tetap `payment_link_version=0`. Sistem tidak menebak relasi berdasarkan NIS, tanggal, atau tahun ajaran.
- Pembayaran legacy ditandai di daftar dan tidak bisa diedit atau dihapus melalui UI maupun endpoint langsung. Selesaikan hanya melalui rekonsiliasi manual yang terdokumentasi dan disetujui.
- Transaksi tabungan manual (`transaksi_m.bayar_id IS NULL`) dan semua penarikan (`transaksi_k`) bukan child pembayaran; edit/hapus pembayaran tidak boleh mengubahnya.

## Bukti verifikasi terakhir

Dilakukan pada 2026-08-04 untuk perapihan tahun ajaran dan cicilan DU:

```powershell
C:\xampp\php\php.exe -l master_daftar_ulang.php
C:\xampp\php\php.exe -l pembayaran\form.php
C:\xampp\php\php.exe -l pembayaran\edit.php
C:\xampp\php\php.exe -l pembayaran\proses.php
node --check assets\js\app.js
git diff --check
```

Seluruh lint dan check berhasil. `git diff --check` tidak menemukan error whitespace; hanya muncul warning line ending CRLF dari Git di Windows.

## Bukti verifikasi sebelumnya

Dilakukan pada 2026-08-04 untuk perbaikan edit pembayaran dari riwayat:

```powershell
C:\xampp\php\php.exe -l pembayaran\edit.php
C:\xampp\php\php.exe -l pembayaran\proses.php
C:\xampp\php\php.exe -l pembayaran\lihat.php
node --check assets\js\app.js
git diff --check
```

Seluruh lint dan check berhasil. `git diff --check` tidak menemukan error whitespace; hanya muncul warning line ending CRLF dari Git di Windows.

## Bukti verifikasi Master Daftar Ulang

Dilakukan pada 2026-08-04 di database lokal `db_spp` untuk fitur Master Daftar Ulang:

```powershell
Get-Content sql\add_master_daftar_ulang.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\add_master_daftar_ulang.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\verify_schema.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
C:\xampp\php\php.exe -l master_daftar_ulang.php
C:\xampp\php\php.exe -l includes\sidebar.php
C:\xampp\php\php.exe -l pembayaran\form.php
C:\xampp\php\php.exe -l pembayaran\edit.php
C:\xampp\php\php.exe -l pembayaran\proses.php
node --check assets\js\app.js
git diff --check
```

Verifikasi schema mengembalikan `OK` untuk seluruh requirement, termasuk tabel `Daftar_ulang` dan unique key `uk_daftar_ulang_period_class`. Migrasi daftar ulang aman dijalankan ulang. `git diff --check` tidak menemukan error whitespace; hanya muncul warning line ending CRLF dari Git di Windows.

## Bukti verifikasi 2026-07-30

Dilakukan pada 2026-07-30 di database lokal `db_spp` setelah memastikan tabel transaksi kosong:

```powershell
Get-Content sql\add_payment_references.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\add_payment_references.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\verify_schema.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
git diff --check
```

Verifikasi schema mengembalikan `OK` untuk tabel, kolom, index unik, dan FK cascade relasi pembayaran. Uji HTTP/database terisolasi juga membuktikan pembuatan relasi, edit/hapus selektif pada dua pembayaran bertanggal sama, pelestarian setoran manual, penolakan saldo negatif, dan penolakan pembayaran legacy. Semua data uji dibersihkan kembali hingga jumlah transaksi kembali nol.
