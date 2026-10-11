# Laporan Global: tampilan semula dan matriks horizontal

Catatan versi: geometri tabel, pratinjau, kertas dan keluaran telah disempurnakan pada [12 Oktober 2026](GLOBAL_REPORT_LAYOUT_20261012.md). Bagian verifikasi di bawah merekam pengujian versi 11 Oktober.

Berlaku 11 Oktober 2026; menggantikan penyajian tabel terpisah pada redesign 10 Oktober. Katalog, header, dan panel filter kembali ke susunan sebelumnya. Hak baca empat peran, pilihan SD/SMP/SMA/Semua Unit, checkbox, pencarian, reset, dan tema tetap berlaku.

## Membaca hasil

1. Pilih unit laporan dan satu/beberapa/semua komponen melalui checkbox. Tekan Terapkan, lalu Tampilkan Rekap.
2. Status Pembayaran menampilkan kelompok komponen/periode berisi Tagihan, Terbayar, Sisa, Status. Penerimaan Harian menampilkan nominal tiap komponen dan Total Penerimaan. Potongan mengikuti tanda sumber.
3. Pembayaran per Item mempertahankan rincian bulan, total dan status untuk komponen bulanan, serta tagihan/pembayaran/sisa/status komponen tahunan atau transaksi. SPP Tahunan menampilkan tahun ajaran menyamping, masing-masing Juli–Juni dan total.
4. Geser tabel ke kanan untuk komponen/periode lain. Identitas membeku pada desktop/tablet; pada ponsel NIS tetap terlihat agar tersedia ruang untuk nominal.
5. Baris dihitung sesudah penggabungan unit+siswa+kelas historis. Kelas berbeda tetap terpisah. Rincian ganda dalam kelompok yang sama menjadi baris lanjutan sesuai urutan sumber. Sel `—`, Rp0 dan Tidak Ditagihkan berbeda.
6. Subtotal di bawah kelompok kolom berlaku untuk seluruh hasil filter, termasuk halaman lain. Total laporan berasal dari model asal; rincian bulanan tidak dijumlahkan kembali ke subtotal.

Riwayat Tagihan Siswa mempertahankan matriksnya. Transaksi/saldo Tabungan, ringkasan kas dan Surat Kepala Sekolah tetap memakai bentuk khususnya.

## Cetak dan unduh

Pratinjau, cetak dan PDF memakai kolom/pemetaan yang sama. Tabel lebar dibagi ke rentang kolom lanjutan; identitas siswa dan header berulang. Kertas, orientasi, kop dan metadata tetap mengikuti jenis laporan. Tanda tangan kas tetap tersedia. Excel memiliki header kelompok, angka/tanggal bertipe, identitas yang dibekukan, serta kolom identitas yang diulang saat dicetak. NIS Diknas menjadi kolom teks tersendiri pada Excel.

## Batas implementasi

`includes/global_report_matrix.php` bekerja setelah `report_build` dan sebelum pagination. Query/model bisnis tidak diubah. Penggabungan memakai unit, NIS siswa, kelas historis dan ordinal rincian dalam setiap kelompok. Nominal/status sumber disalin; total penerimaan baris menggabungkan total sumber komponen yang dipilih. Nilai null tetap dibedakan dari nol. Helper Excel memakai metadata opsional untuk matriks; modul lain tidak ikut berubah. Tidak ada migrasi, parameter URL baru, atau perubahan API publik.

## Verifikasi

Semua tes memakai clone `db_spp_audit_operator_pdf_20261010` dan artefak privat di luar repositori; tidak menulis database utama.

- Baseline 56 model laporan/filter/unit sebelum perubahan dibandingkan kembali secara utuh: baris, nilai, status, subtotal, total dan model Excel asal identik.
- `tests/global_report_matrix_test.php`: 52 kasus matriks, termasuk semua komponen/periode pada empat unit; 36 XLSX dibaca kembali seluruh nilai dan subtotalnya. Fixture menguji NIS sama beda unit, kelas historis, rincian ganda, sebagian kelompok kosong, nol dan Tidak Ditagihkan. Urutan/kelengkapan rentang kolom cetak diperiksa.
- `tests/global_report_redesign_browser_test.js`: katalog dan sembilan template, empat cakupan, dua tema, desktop/tablet/ponsel (240 keadaan), checkbox, pagination sesudah penggabungan, pencarian/reset, periode kondisional, keyboard/dropdown, identitas membeku dan pratinjau.
- `tests/global_report_matrix_browser_test.js`: tambahan 120 keadaan matriks multikomponen/multiperiod pada empat cakupan, kedua tema, tiga ukuran layar; NIS tetap terlihat, tabel tunggal, rentang kolom print dan header/freeze pratinjau Excel.
- `tests/global_report_redesign_exports_test.php`: 56 XLSX endpoint, seluruh nilai bertipe, header kelompok, subtotal, palette dan metadata; PDF serta 168 respons preview/print/Excel. Opsi `--full-only` menambahkan lima PDF seluruh hasil untuk menguji kelanjutan baris/kolom.
- `tests/global_report_redesign_pdf_test.py`: 61 PDF/227 halaman dibaca kembali, termasuk lima hasil penuh; memeriksa sekolah/unit, orientasi/kertas, urutan, nominal, status, subtotal/total, identitas/header berulang, rentang kolom lanjutan dan batas halaman.
- PDF hasil kosong dua komponen juga diperiksa: tepat dua rentang kolom, tanpa halaman kop tersendiri, identitas/header berulang. Manual PDF final terbaca 35 halaman.
- Regresi cakupan model/HTTP empat peran: 243 permintaan, fingerprint database tetap; tahun Daftar Ulang, periode per item, SPP belum terbit/dibatalkan; sintaks PHP/JS dan whitespace diff.

Simpan sesi QA dan baseline di luar webroot. Tes CLI perlu `SPP_DB_NAME`, `SPP_TEST_ALLOW_MUTATION=1` sesuai guard tes, `SPP_QA_DIR` dan URL loopback clone. Server dan tes sesi memakai `session.save_path` privat yang sama. Tes browser membutuhkan Playwright Core dan Chrome; PDF readback membutuhkan PyMuPDF. Setelah QA, hentikan server dan hapus hanya berkas sesi QA yang dibuat pengujian. Cetak fisik pada printer belum dilakukan.
