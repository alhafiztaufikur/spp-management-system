# Laporan Global: tabel dan keluaran yang rapi

Pembaruan 12 Oktober 2026 melanjutkan matriks horizontal 11 Oktober. Katalog, susunan filter, data, pagination, perhitungan, hak akses, dan unit operasional tetap mengikuti alur sebelumnya. Surat Kepala Sekolah tetap memakai renderer khususnya.

## Penggunaan

- Tabel memakai lebar kolom yang sama untuk header, isi dan subtotal. Identitas memiliki latar solid agar nominal tidak terlihat menembusnya saat digeser.
- Identitas tetap dikunci ketika ruang panel mencukupi. Pada panel sempit, termasuk tablet dengan sidebar terbuka, hanya NIS dikunci sehingga kolom transaksi tetap mendapat ruang.
- Panel filter berada di bawah lapisan topbar; tombol dan dropdown tidak menutupinya.
- Tabel dengan sedikit kolom mengisi lebar panel. Penerimaan satu periode menampilkan header komponen satu tingkat pada halaman dan pratinjau, tanpa baris kelompok tambahan. Subtotal menggunakan kolom identitas terpisah dengan offset/latar yang sama seperti isi tabel; pada panel sempit hanya label Subtotal di kolom NIS yang dikunci. Cetak/PDF tetap memakai label subtotal gabungan sesuai rentang kertas.
- **PDF** membuka pratinjau tabel interaktif. **Excel** membuka pratinjau tabel Excel. Keduanya bisa digulir menyamping sampai kolom terakhir.
- **Cetak** membuka PDF inline di penampil PDF browser. Gunakan perintah cetak penampil tersebut. **Download PDF** mengunduh keluaran dari pembentuk dokumen yang sama.
- PDF memakai A4 otomatis: potret untuk laporan ringkas, lanskap untuk tabel lebar. Teks isi 9 pt, keterangan 8 pt, dan margin 10 mm. Lebar nominal, padding, border dan ruang identitas menentukan rentang kolom. Kelompok status yang muat dipertahankan utuh; kelompok panjang berlanjut sesuai urutan sumber. Identitas/header diulang, subtotal tetap di kolom terkait.
- XLSX mempertahankan lembar dan nilai sumber. Kop ringkas memiliki ruang logo sendiri dan merge pada sisi identitas tanpa melintasi freeze. NIS/NIS Diknas tetap teks; nominal/tanggal tetap bertipe. Header, identitas, subtotal dan pengaturan cetak memakai A4 dengan pengulangan identitas dan pemisah kolom kelompok. Penanda Excel “angka disimpan sebagai teks” diabaikan hanya untuk identitas yang memang berupa teks.

## Implementasi

`global_report_layout.php` menyimpan ukuran presentasi dan ringkasan filter terapan. Renderer matriks memakai colgroup eksplisit; JS membaca ukuran yang dirender untuk offset sticky serta mendeteksi ruang panel. CSS hanya berlaku pada workspace Laporan Global. Tabel khusus Riwayat Tagihan/tabungan/kas diperiksa dengan bentuk masing-masing.

`global_report_excel.php` adalah finishing opt-in `presentation=global`; writer/preview modul lain tetap memakai alur lama. PDF menghitung ukuran border/padding selain lebar isi. Pratinjau HTML sengaja berbeda dari pagination kertas, tetapi memakai pemetaan nilai yang sama. Cetak dan unduh menggunakan endpoint serta renderer PDF yang sama dengan Content-Disposition berbeda; tidak ada parameter URL, API publik atau migrasi baru.

## Verifikasi

Semua pengujian database memakai clone `db_spp_audit_operator_pdf_20261010`. Baseline, XLSX, PDF dan screenshot disimpan di direktori QA di luar webroot; sesi privat dibersihkan setelah pengujian. Hasil juga dicatat pada changelog.

- Pembanding 56 model; 52 kasus matriks dan 36 XLSX langsung, termasuk rincian ganda, kelas historis, nol, tidak ditagihkan, semua komponen/periode, sumber subtotal dan batas A4.
- Browser: dua rangkaian masing-masing 240 keadaan lulus. Sembilan laporan ditambah variasi periode, empat cakupan, kedua tema, tiga ukuran. Pemeriksaan meliputi awal/tengah/akhir gulir, koordinat header/isi, lapisan header, latar identitas, topbar, hasil kosong, filter/reset/pagination dan pratinjau.
- Endpoint: 56 XLSX dibaca kembali untuk seluruh nilai/tipe/kolom/subtotal. Sebanyak 61 PDF/310 halaman dibaca kembali untuk nilai, urutan, font nominal minimal 9 pt, tumpang tindih nominal, batas halaman dan header berulang, termasuk hasil penuh untuk pergantian halaman/kolom.
- Sebanyak 243 permintaan akses/cakupan dan 48 ekspor biner lulus untuk empat peran dan empat unit, penolakan input tidak valid, isolasi unit operasional, serta fingerprint database sebelum/sesudah yang sama. Regresi periode Daftar Ulang dan Komite lulus.
- Sebanyak 40 XLSX dibuka read-only dengan Microsoft Excel 16; area worksheet dirender melalui CopyPicture untuk memeriksa logo, judul, header dan isi. Freeze, A4, orientasi, pengulangan identitas/header serta batas merge kop diperiksa melalui Excel. Pemisah halaman diperiksa pada struktur workbook.

Pemeriksaan lanjutan perapihan antarmuka: 288 keadaan tabel lulus, termasuk posisi subtotal saat digeser, tabel ringkas yang mengisi panel, Penerimaan satu periode, kesamaan warna header yang dikunci/bergulir, dan pratinjau. Sebanyak 56 model sumber identik serta 52 kasus matriks/36 XLSX kembali lulus. Sebanyak 243 permintaan akses/cakupan kembali lulus dengan fingerprint database tetap. Nilai, subtotal dan pagination tetap memakai sumber sebelumnya.

Cetak fisik belum dilakukan. ExportAsFixedFormat pada Excel lokal menunjukkan ketidaksesuaian metrik font (kontrol workbook baru juga menunjukkan metrik berbeda), sehingga hasil API tersebut tidak dipakai sebagai bukti kualitas tipografi cetak Excel. Pemeriksaan visual XLSX memakai renderer worksheet Excel; PDF aplikasi diperiksa melalui rasterisasi PDF yang diunduh.


## Perataan identitas dan waktu ekspor ? 12 Oktober 2026

No dan NIS diratakan tengah pada halaman, kedua pratinjau, PDF dan XLSX. NIS/NIS Diknas tetap disimpan sebagai teks. Pencarian Pembayaran per Item SPP berada di samping Baris/Halaman, lalu tersusun vertikal pada ponsel. Tanggal/Waktu Tabungan memakai dua baris dengan jarak yang jelas; tanggal Excel tetap berupa nilai tanggal, dengan format dua baris.

Pratinjau interaktif tidak membentuk tabel cetak tersembunyi. PDF hanya membentuk rentang cetak yang diperlukan. Renderer layar melewati pengukuran nominal untuk kertas; pembacaan geometri browser dilakukan sebelum perubahan style dan offset yang sama tidak ditulis berulang. Format XLSX Global diterapkan per kolom, dengan nilai/tipe, warna baris, status, header dan pengaturan cetak yang sama. Writer non-Global mempertahankan alurnya. Sesi ditutup setelah otorisasi dan pemilihan cakupan Global selesai, sehingga pembentukan dokumen tidak menahan kunci sesi akun. Data tetap dibaca ulang pada setiap permintaan; tidak ada cache hasil yang berisiko menyajikan transaksi lama.

Pengukuran lokal menggunakan clone db_spp_audit_operator_pdf_20261010, server PHP 8.1, sembilan template unit SD ditambah dua matriks Semua Unit. Satu lintasan sebelum dan sesudah, masing-masing 33 respons (11 kasus ? pratinjau/PDF/XLSX), tanpa menjalankan pengujian lain bersamaan:

| Keluaran | Total sebelum | Total sesudah | Pengurangan |
| --- | ---: | ---: | ---: |
| Pratinjau HTML | 2,959 dtk | 2,274 dtk | 23,1% |
| XLSX | 30,376 dtk | 4,473 dtk | 85,3% |
| PDF | 74,584 dtk | 58,770 dtk | 21,2% |

Contoh XLSX SPP Tahunan SD: 3,415 ? 0,496 detik; Status beberapa kategori Semua Unit: 7,035 ? 0,693 detik. PDF SPP Tahunan Semua Unit: 19,566 ? 15,702 detik. Ini pengukuran pada data/perangkat lokal, bukan jaminan waktu untuk semua volume. Dokumen PDF panjang tetap membutuhkan penyusunan halaman; waktu transfer, pembukaan aplikasi Excel dan printer fisik tidak termasuk.


Verifikasi pembaruan: 312 keadaan browser lulus, termasuk tabel Tabungan yang tetap terlihat pada ponsel, perataan No/NIS, jarak tanggal/waktu, pencarian SPP sejajar ukuran halaman, gulir dan pratinjau. Sebanyak 56 model sumber/52 kasus matriks tetap cocok; 56 XLSX endpoint, 61 PDF/310 halaman, 243 pemeriksaan akses/cakupan serta 48 keluaran biner lulus. Microsoft Excel membuka/render 40 workbook read-only; tambahan empat workbook Tabungan dengan total 50 baris memeriksa tanggal numerik dua baris dan perataan identitas. Probe sesi memperoleh kunci dalam 0,075 detik ketika PDF masih dirender; PDF akhirnya tetap valid. Manual PDF 35 halaman dibaca kembali.
