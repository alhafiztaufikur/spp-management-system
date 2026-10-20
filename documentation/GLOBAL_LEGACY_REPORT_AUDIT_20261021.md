# Audit Laporan Global setelah migrasi Legacy — 21 Oktober 2026

## Cakupan dan aturan pembacaan

Sembilan template Global memakai data native dan Legacy dalam kelompok siswa, kelas, komponen, dan total yang sama. Tidak ada migrasi ulang, rekonsiliasi data pengguna, tabel baru, atau perubahan dashboard/jurnal operasional. `koneksi.php` lokal dipertahankan.

Pembaca bersama berada di `includes/global_report_evidence.php`. Identitas sumber dihubungkan melalui ID siswa/pemilik dan unit. NIS dipakai setelah hubungan tersebut diketahui, dalam cakupan satu unit; nama tidak dipakai untuk memasangkan transaksi. Semua Unit menggabungkan hasil tiga unit dengan identitas unit tetap tersimpan.

- Pembayaran SPP/Komite mengikuti bulan kewajiban, DU mengikuti tahun ajaran, dan PSB mengikuti kewajiban sekali saat masuk. Pembayaran di muka dan pembayaran tanpa tanggal tetap mengurangi kewajiban yang periodenya terbukti.
- Tagihan native menggunakan snapshot tersimpan serta alokasi aktif. Riwayat Legacy menggunakan transaksi aktif dan periode sumber. Kredit kumulatif PSB memakai pembaca bukti existing yang mengurangi bukti rinci yang sudah tercakup, sehingga tidak dijumlahkan dua kali.
- Pembatalan dan koreksi yang telah diterapkan dibaca kembali; pratinjau/pengajuan yang belum diterapkan tidak mengubah laporan. Cache bukti periode menyertakan revisi audit.
- Nominal kewajiban yang tidak dapat dibuktikan bernilai `null` dan ditampilkan **—**. Pembayaran tetap tampil dengan status **Ada Pembayaran**, atau **Belum Diketahui** ketika belum ada pembayaran. Total parsial diberi satu catatan: **Total kewajiban hanya mencakup nominal yang diketahui**.
- Bukti pelunasan eksplisit dapat menyatakan sisa nol tanpa mengarang nominal pokok historis. Pembayaran baru atas saldo tunggakan awal ditambahkan sekali; sisa mengikuti bukti saldo awal tersebut.
- Pembayaran opsional Biaya Lain tetap tampil, tetapi tidak dipakai untuk melunasi tagihan lain. Tarif terbaru dan tanggal impor tidak dijadikan kewajiban/tanggal penerbitan historis.
- Pilihan tahun dapat berasal dari periode sumber tanpa membuat tahun ajaran atau penempatan baru.

## Filter transaksi tanpa tanggal

GET `include_undated=1` tersedia pada Penerimaan, Setoran, Transaksi Tabungan, Kas Tabungan, dan Rekap per Item yang memakai tanggal transaksi. Default tidak dicentang. Filter kewajiban bulanan/tahunan tidak bergantung pada checkbox ini.

Checkbox menambahkan transaksi tanpa tanggal hanya apabila bulan, tahun kalender eksplisit, atau irisan Juli–Juni tahun ajarannya sesuai rentang laporan. Tanggal impor/hari ini/peralihan tidak menjadi pengganti periode. Jurnal tanpa bukti periode tidak masuk total periode tertentu. Semua Periode existing tetap tersedia; pembatasan bukti transaksi tanpa tanggal tetap berlaku. Pagination, tautan unit, cetak, PDF dan Excel mempertahankan pilihan; Reset menghapusnya.

Keterangan **Termasuk transaksi tanpa tanggal sesuai periode kewajiban** dicantumkan sekali pada ekspor yang menggunakan filter tersebut. Penjelasan pendek pada checkbox dan catatan total menggantikan kalimat panjang berulang pada judul/header. Sel bulan memakai status singkat dan nominal tanpa pill penjelasan panjang. Ekspor Per Item menuliskan periode kewajiban yang dipilih, bukan rentang tanggal transaksi yang tersembunyi.

## Hasil per template

| Template | Temuan dan perilaku setelah perbaikan |
|---|---|
| Status Pembayaran | Oktober Legacy terbaca meskipun tagihan native bulan itu tidak ada. SPP/Komite, PSB/DU, komponen histori dan pembelian opsional tetap terlihat menurut buktinya. |
| Rekap SPP Tahunan | Satu model bulanan dengan Status dan Per Item; pembayaran November Rp555.000 tidak lagi hilang. Sisa dijumlahkan per kewajiban, sehingga kelebihan satu bulan tidak melunasi bulan lain. |
| Rekap per Item | Membaca bulan/tahun kewajiban atau tanggal transaksi sesuai kategori. U_SORGA dan komponen histori tetap tampil walaupun tidak memiliki tagihan. PSB tidak disaring sebagai tagihan tahunan. |
| Riwayat Tagihan | Mempertahankan snapshot tagihan dan kredit sumber. Bukti pembayaran tanpa tagihan native tampil dengan pokok/tanggal historis tidak diketahui. Filter tanggal penerbitan tidak menganggap tanggal impor sebagai tanggal tagihan. |
| Penerimaan | Native dan Legacy dijumlahkan per siswa/kelas transaksi. Filter kategori tunggal/jamak/Semua memakai satu pembacaan; kolom kosong yang tidak dipilih tidak diperluas menjadi puluhan laporan. |
| Setoran | Transaksi historis ikut total sesuai tanggal/checkbox. Metode yang diketahui mengikuti Tunai/VA/QRIS; metode yang tidak tercatat tidak dihilangkan atau ditebak sebagai Tunai. Komponen tidak dipisahkan menurut sumber. |
| Transaksi Tabungan | Jurnal native/historis digabung, penyesuaian dipisahkan dari masuk/keluar kas. Kolom saldo merupakan saldo berjalan; urutan yang tidak dapat dibuktikan tidak diberi saldo buatan. |
| Saldo Tabungan | Saldo setiap rekening dibandingkan dengan saldo operasional sumber dan rekening native yang tidak tertaut. Penyesuaian/peralihan tidak menggandakan saldo. |
| Kas Tabungan | Jurnal historis ikut kas masuk/keluar. Saldo awal dan penyesuaian tetap bukan kas baru. Default helper operasional di luar Global tetap dipertahankan. |

## Metode pengujian

Dump hasil migrasi dipulihkan ke instance MySQL terpisah, port 58470, database `db_spp_audit_global_20261021`. HTTP QA menggunakan port 58471 dan sesi privat. Dump, sesi, identitas siswa, dokumen hasil uji, dan log disimpan di luar repository/web root. Konfigurasi target tes dipasok melalui environment, bukan perubahan `koneksi.php`.

Baseline independen membaca item transaksi sumber, periode sumber, jurnal dan saldo rekening langsung. Nominal sumber dihitung dalam sen menggunakan parser terpisah dari helper produksi. Semua template dan kategori tersedia diuji pada SD, SMP, SMA, dan Semua Unit. Hasil Semua Unit dibandingkan dengan penjumlahan tiga cakupan setara.

Suite yang dapat dijalankan ulang:

- `tests/global_legacy_reconciliation_test.php`: **3.264 pemeriksaan**, termasuk **2.418 perbandingan saldo rekening**, kategori, periode, kas, kredit, dan jumlah Semua Unit. Seluruh kategori Biaya Lain juga dibandingkan dengan jumlah pembayaran sumber.
- `tests/global_legacy_native_test.php`: **68 pemeriksaan** data native, presisi sen, kategori jamak, PSB/DU, pembayaran opsional, arsip/lulus, pembatalan SPP/Biaya Lain, snapshot kelas setelah perpindahan dan identitas sama antarunit; seluruh fixture di-rollback.
- `tests/global_legacy_coverage_test.php`: **15 pemeriksaan** pelunasan eksplisit, tidak ditagihkan, bukti tunggakan belum tersedia, pembayaran saldo awal dan pembatalan; seluruh fixture di-rollback.
- `tests/global_legacy_correction_test.php`: **15 pemeriksaan** layanan koreksi nyata, pratinjau, penerapan, replay, pembatalan dan pemulihan melalui rollback pada tiga unit.
- `tests/global_legacy_payment_case_test.php`: **13 pemeriksaan** kasus November aktual; Rp555.000 pada SD cocok pada empat laporan kewajiban, berikut pemeriksaan sampel SMP/SMA.
- `tests/global_legacy_http_exports_test.py`: **225 pemeriksaan** UI, akses administrator, filter/tautan/pagination, 36 PDF dan 36 Excel dari sembilan template × empat cakupan, serta cetak inline. Mengukur waktu dan ukuran respons privat.
- `tests/global_legacy_export_readback_test.py`: **157 pemeriksaan**, membuka kembali seluruh PDF/Excel, total sumber independen, nilai kosong, keterangan filter, A4, serta render Poppler halaman awal/akhir perwakilan. Hasil render perwakilan diperiksa visual.
- `tests/global_legacy_browser_test.js`: **219 pemeriksaan Chrome 155**, empat unit × lebar 1440/768/390 × tema terang/gelap, keyboard, filter/ekspor, Reset dan penggunaan tanpa JavaScript; **57 pemeriksaan Edge 155** pada SD dengan tiga ukuran dan dua tema.

Pada rangkaian HTTP akhir, respons terlama HTML 6,146 detik, PDF 5,135 detik dan Excel 4,713 detik. Pengukuran ini memakai data migrasi penuh dengan filter sampel siswa untuk ekspor matriks/detail; Setoran dan Kas memakai seluruh data yang sesuai rentang. Nilai ini merupakan pengukuran mesin QA, bukan jaminan waktu pada semua perangkat.

Uji tambahan Penerimaan Semua Unit tanpa filter siswa mereproduksi kehabisan memori Dompdf pada batas 768 MB. Ekspor besar kini merender blok 80 baris secara berurutan pada dokumen PDF yang sama melalui API canvas existing Dompdf; pohon HTML dilepas antarbagiannya. Renderer, kop, gaya tabel, ukuran font, nominal dan pembagian kolom tetap memakai kode existing. Nomor baris/halaman berlanjut, subtotal seluruh hasil hanya pada akhir tiap kelompok kolom, total akhir hanya sekali, dan file baru dikirim setelah seluruh bagian selesai. Tidak menambah paket PDF atau mengganti renderer dengan gambar/canvas browser. Dokumen kecil tetap memakai jalur sebelumnya.

Uji `--full-receipt` akhir lulus **8 pemeriksaan**: PDF lengkap **257 halaman**, setiap nomor/unit/NIS baris Excel ditemukan pada PDF, nomor halaman berurutan, keterangan checkbox sekali, dan total kedua format **Rp2.589.734.200** sama dengan baseline independen. PDF selesai 101,014 detik dan Excel 2,076 detik; penggunaan memori proses yang diamati selama render sekitar 230 MB. PDF sangat besar tetap memerlukan waktu lebih lama. Halaman awal, lanjutan dan terakhir dirender dengan Poppler serta diperiksa visual.

Regresi existing meliputi SPP belum diterbitkan/dibatalkan, DU lintas tahun, riwayat sesudah kenaikan/kelulusan, dan snapshot kelas transaksi. Fixture riwayat kini memakai actor Super Admin existing agar audit perubahan master memenuhi foreign key; pemeriksaan perilaku tidak dihapus.

Contoh rekonsiliasi bertanggal 1–21 Oktober 2026, seluruh siswa/kelas/operator, checkbox mati:

| Cakupan | Penerimaan = Setoran | Tabungan masuk | Tabungan keluar |
|---|---:|---:|---:|
| SD | Rp156.508.200 | Rp33.846.000 | Rp3.710.000 |
| SMP | Rp117.931.300 | Rp11.481.000 | Rp5.825.000 |
| SMA | Rp25.970.000 | Rp3.590.000 | Rp150.000 |
| Semua Unit | Rp300.409.500 | Rp48.917.000 | Rp9.685.000 |

Checkbox menambahkan bukti tanpa tanggal yang periodenya cocok: SD Rp1.245.424.700; SMP Rp835.450.000; SMA Rp208.450.000. Nilai ini bukan bukti transaksi terjadi dalam tanggal tertentu: penyertaannya mengikuti periode kewajiban yang diminta pengguna. U_SORGA SD pada rentang tanggal di atas: Rp3.120.000, sama pada Penerimaan dan Per Item.

Hash isi dan skema **62 tabel database pengguna sebelum/sesudah identik**. Tidak menjalankan migrasi atau menulis ulang data pengguna. Artefak QA mengandung data privat dan tidak dimasukkan ke Git. Server HTTP/MySQL milik pengujian telah dihentikan; salinan serta bukti uji tetap disimpan privat. Lint PHP/JavaScript, parsing Python, pemeriksaan patch, dan regresi terdampak lulus. Jumlah pemeriksaan dari sepuluh suite di atas: **4.041**, di luar assertion regresi existing.

## Batas bukti

Nominal pokok, tanggal, periode, atau kelas historis yang tidak tersedia tidak direkonstruksi. Total kewajiban dapat parsial meskipun pembayaran lengkap. Saldo berjalan transaksi tanpa urutan yang terbukti ditampilkan kosong; saldo rekening authoritative tetap diperiksa tersendiri. Pengujian browser memakai browser terpasang, dan hasil cetak fisik bergantung pada printer/driver pengguna.
