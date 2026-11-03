# Perapian keluaran delapan laporan, 3 November 2026

Perubahan hanya pada presentasi cetak/PDF, pratinjau desktop, dan Excel:
Rekap Penerimaan Harian, Setoran Kas Harian, Kas Tabungan Harian,
Transaksi Tabungan, Saldo Tabungan, Status Pembayaran, Pembayaran per Item,
dan SPP Tahun Ajaran per Kelas.

- Header Unit dan konteks filter pada empat laporan tambahan disembunyikan,
  mengikuti empat laporan yang sebelumnya sudah menggunakan header ringkas.
  Tahun/periode/status dalam kolom data tetap utuh.
- Awalan Rp dihapus dari format nominal, sel bulan gabungan, subtotal,
  ringkasan, dan total. Nama dan catatan transaksi literal tidak ditulis ulang.
- Alamat pada Saldo Tabungan, Status Pembayaran, Pembayaran per Item,
  dan SPP Tahunan memakai 11 pt pada PDF/cetak/XLSX dan 15 px di pratinjau desktop.
- Nama Pemilik rata tengah dan tetap dapat dibungkus. Kolom tanggal/waktu,
  mutasi, operator, dan keterangan tabungan mendapatkan pengaturan yang sesuai.
- Renderer PDF menggunakan baris pengatur lebar tanpa tinggi sebelum header
  gabungan. Ini mencegah Dompdf membagi seluruh kolom dalam satu grup sama
  lebar dan menabrakkan nominal panjang atau subtotal.
- Penomoran PDF normal dan bertahap memakai posisi garis dan teks yang sama;
  jaraknya diukur minimal 4 pt. Jalur laporan lain mempertahankan perilaku lama.
- Kertas tetap A4, satu halaman secara horizontal, jumlah halaman vertikal
  mengikuti isi. Header tabel diulang; seluruh kolom dan urutan baris dipertahankan.

## Pemeriksaan

- `tests/report_export_cleanup_test.php`: delapan template, nominal negatif dan
  pecahan, nilai belum diketahui, NIS dengan nol awal, format sel bulan gabungan,
  catatan literal, alamat, perataan nama, dan input yang tidak berubah.
  Dengan direktori artefak privat, juga merender fixture 270 baris/12 bulan
  dengan nominal besar dan empat fragmen PDF.
- `tests/report_export_cleanup_browser_test.js`: keluaran aktual delapan laporan
  pada 1280/1366/1920 px, pratinjau cetak dan Excel, unduhan PDF/XLSX serta
  jalur cetak. Kasus tambahan mencakup banyak kategori, semua unit, dan hasil kosong.
- `tests/global_report_clean_native_excel_test.ps1`: sebelas berkas dibuka baca
  saja dalam Excel native, diekspor ke PDF, dan hash berkas asal diperiksa.
- `tests/report_export_cleanup_pdf_test.py`: seluruh halaman PDF aplikasi,
  cetak, Excel native, serta fixture; A4, nomor halaman tunggal dan berurutan,
  jarak garis footer, batas teks, tabrakan horizontal, dan ketiadaan `####`.
- Regresi `tests/global_report_print_test.php` dan
  `tests/desktop_four_presentation_test.php` tetap dijalankan.

Pengujian menggunakan salinan MySQL port 58660 dan PHP 58662. UUID/datadir
dicocokkan dengan bukti restore, dan hash backup sumber diverifikasi kembali.
Checksum seluruh 124 tabel dibandingkan sebelum/sesudah. Konfigurasi koneksi,
`.gitignore`, pembaca/perhitungan keuangan, dan renderer Riwayat Tagihan
termasuk dalam pemeriksaan file yang dipertahankan.

Artefak privat, salinan file sebelum perubahan, screenshot, hasil JSON,
PDF, dan XLSX berada di:
`C:/laragon/backups/spp-management-system/pagination_desktop_20261103_055250/report_cleanup_133735/`.

## Pemeriksaan tampilan yang relevan

Hasil akhir: sebelas skenario browser, sebelas workbook Excel native, serta
31 PDF dengan total 466 halaman lulus. Pemeriksaan ulang delapan laporan inti
dilakukan setelah koreksi lebar header gabungan. Checksum 124 tabel dan hash
sepuluh file yang dipertahankan tetap sama.

- PASS: identitas sekolah, logo, dan gaya tabel existing dipertahankan; tidak ada aset baru.
- PASS: ukuran desktop dan batas setiap sel diperiksa melalui browser.
- PASS: penomoran, halaman lanjutan, nominal panjang, serta subtotal diperiksa dari PDF nyata.
- PASS: nilai numerik, identitas, status belum diketahui, serta catatan sumber dipertahankan.
