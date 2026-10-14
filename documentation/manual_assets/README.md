# Screenshot manual pengguna

Gambar dibuat pada 14 Oktober 2026 dari antarmuka aplikasi yang berlaku, melalui HTTP loopback dengan database disposable `db_spp_audit_manual_20261014`. Data dibuat dari installer bersih dan seeder demo; akun petugas memakai identitas contoh dan password login dinonaktifkan pada database uji. Tidak ada dump, cookie, password, atau data operasional dalam folder ini.

`tests/manual_book_capture.js` menangkap bagian layar yang relevan, menggunakan tema terang dan penunjuk bernomor pada kontrol utama. Seluruh POST aplikasi diblokir saat pengambilan gambar. Konteks server dan sesi berada di direktori QA privat di luar repositori, ditentukan melalui `SPP_MANUAL_QA`; runtime Playwright ditentukan melalui `SPP_PLAYWRIGHT_CORE`.

Gambar `24-pemulihan-selesai.png` memperlihatkan controller pemulihan aktif dengan respons pekerjaan **simulasi** melalui intersepsi browser. Tidak ada pemulihan database nyata atau backup yang dijalankan untuk screenshot. Gambar backup/Legacy memperlihatkan instalasi contoh yang layanannya belum dipasang; manual menjelaskan cara membaca status tersebut.

HTML adalah sumber isi utama. Setelah mengedit isi atau gambar, jalankan `php documentation/generate_manual_pdf.php`; pembentuk PDF membaca aset lokal, menghitung nomor halaman daftar isi dari hasil sebenarnya, dan memperbarui PDF serta referensi halaman HTML. Periksa dengan `tests/manual_book_browser_test.js` dan `python tests/manual_book_pdf_test.py` (PyMuPDF), lalu baca hasil PDF secara visual.
