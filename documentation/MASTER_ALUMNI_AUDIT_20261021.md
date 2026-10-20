# Audit Data Master, Alumni, dan Cetak — 21 Oktober 2026

## Cakupan dan penerapan

Perubahan ini hanya pada source, dependensi lokal, pengujian, dan dokumentasi. Tidak menjalankan migrasi, impor ulang, rekonsiliasi lulusan lama, atau perintah tulis pada database pengguna. Konfigurasi lokal `koneksi.php`, entri pemulihan pada changelog, dan `IMPORT_RECOVERY_20261021.md` yang sudah ada dipertahankan. Tidak melakukan commit, push, atau deployment.

Mutasi QA dijalankan pada MySQL terpisah, port **58460**, database **db_spp_audit_master_20261021**, dengan data hasil pemulihan dump tervalidasi. HTTP QA memakai port **58461**, sesi privat, dan identitas database diperiksa sebelum tes. Artefak berisi data/sesi tidak dimasukkan ke repository; lokasi privat: `C:\laragon\backups\spp-management-system\master_audit_20261021`.

## Perilaku kelulusan

- Layanan bersama menangani SD 6, SMP 9, SMA 12, baik individual maupun batch. Tahun akhir TA asal menentukan kelas: **2026/2027 → Alumni2027**, walaupun diproses terlambat.
- Dalam transaksi yang sama: penempatan asal menjadi `lulus`, siswa dipindahkan ke master Alumni unit/tahun yang sama, `KELAS=0`, `is_active=0`, dan audit disimpan. Master Alumni dibuat nonaktif bila belum tersedia; master existing dipakai kembali dengan validasi klasifikasi. Tidak ada penempatan akademik tahun berikutnya.
- Snapshot kelas/rombel dan tarif penempatan lama dipertahankan. Tagihan, pembayaran, jurnal tabungan, dan nilai biaya siswa tidak diubah oleh kelulusan.
- Penguncian, versi penempatan, pembatasan unit, batch parsial, penolakan sumber mendatang, dan pencegahan pemrosesan ganda tetap berlaku. Kegagalan audit membatalkan seluruh perubahan siswa tersebut.
- Konfirmasi, hasil, peserta, target ekspor, daftar anggota, serta timeline menampilkan Alumni sesuai bukti. Timeline memakai keanggotaan tersimpan dan audit kelulusan; Alumni hasil migrasi tanpa audit hanya menampilkan tahun yang diketahui. Tidak menciptakan tahun akademik yang hilang.
- API hasil kelulusan menambahkan `alumni_year`, `alumni_class_id`, dan `target`. Field `graduation_year` lama tetap berupa label TA asal agar pemanggil lama kompatibel.
- Identitas lulusan bisa diedit dengan kelas existing yang nonaktif. Tidak mengaktifkan siswa, mengubah kelulusan, memprosesnya sebagai PSB, atau menerbitkan tagihan. Penggantian kelas melalui formulir identitas ditolak.

Lulusan yang sudah diproses sebelum perbaikan tidak dipindahkan otomatis. Perbaikan berlaku saat proses kelulusan berikutnya dijalankan.

## Temuan tambahan yang diperbaiki

1. Penempatan PSB dapat memulihkan/menimpa penempatan aktif. Sekarang ditolak jika siswa masih memiliki penempatan aktif atau bukti kelulusan.
2. Pemulihan ke kelas reguler mengganti snapshot rombel sebelum pemeriksaan pembayaran. Pemulihan sekarang mengubah status saja; sinkronisasi existing mempertahankan snapshot berbayar. Tingkat yang berbeda dari penempatan tersimpan ditolak.
3. Edit identitas parsial membaca nilai DECIMAL database dengan parser uang formulir, sehingga titik desimal dianggap pemisah ribuan. Nilai existing yang tidak dikirim kini dipertahankan sebagai angka; tampilan/input biaya juga mempertahankan pecahan dua desimal.

## Tampilan dan navigasi

- Kelas Khusus memakai **Lihat Anggota** dan menu `⋮` untuk tindakan lain sesuai hak akses. Teks “Dikelola sistem” dihapus. Proteksi Alumni/OUT tetap di server. Menu native tetap berfungsi tanpa JavaScript; JavaScript menambahkan Escape, panah, Home/End, penutupan saat fokus/klik keluar, dan satu menu terbuka.
- Pagination terpisah dari anggota: **10/25/50**, default **25**, jumlah hasil, nomor halaman, Sebelumnya/Berikutnya nonaktif pada batas. Filter dan ukuran dipertahankan pada tautan serta sesudah mutasi; pencarian mengawali halaman pertama, halaman berlebih diklem ke batas.
- URL buku dan struk tabungan dipisahkan. Tombol buku pada daftar, detail, dan dialog setelah simpan menggunakan NIS + ID siswa, atau `owner_id` untuk pemilik non-siswa. Bukti transaksi tetap memakai endpoint struk.
- Dashboard menghapus Total Penerimaan, rumus gabungan, dan “Total bersih”. Angka utama memakai total pembayaran sekolah. Tiga kartu tersisa berjajar di desktop dan bertumpuk pada ponsel.

## PNG surat orang tua

Tombol **Cetak PNG** tersedia di pratinjau surat yang sudah disesuaikan. Inputnya byte PDF dari endpoint draf existing yang sama dengan pratinjau. Pemeriksaan sesi, pemilik, unit, dan kedaluwarsa tetap di endpoint tersebut; tidak ada API bisnis, tabel, atau generator surat kedua.

Dependensi lokal: **PDF.js legacy 4.10.38**, worker dan standard fonts; JSZip existing. Versi, URL arsip resmi, checksum, dan daftar berkas dicatat pada `assets/vendor/pdfjs/VERSION.json`; lisensi Apache dan lisensi font disertakan. Tidak memuat PDF.js atau font PDF dari CDN. Build legacy upstream menargetkan Chrome 103+, dengan baseline source [PDF.js 4.10.38](https://github.com/mozilla/pdf.js/blob/v4.10.38/gulpfile.mjs).

Setiap halaman dirender berurutan pada skala `300/72` (A4 sekitar **2480 × 3508 px**), latar putih. Satu halaman diunduh PNG, beberapa halaman menjadi ZIP bernomor `halaman-001.png`, dst. Progres dan kunci tombol mencegah klik ganda. Canvas dilepas per halaman dan worker dihancurkan setelah selesai/gagal. Unduhan dibuat setelah semua halaman berhasil, sehingga kegagalan di tengah proses tidak mengunduh hasil parsial.

## Verifikasi yang dijalankan

| Pemeriksaan | Hasil |
| --- | --- |
| `master_graduation_alumni_test.php` | 63 pemeriksaan; tiga unit, Alumni baru/existing, batch parsial, replay, kelulusan aktual tahun lampau/mendatang, snapshot, timeline, peserta, keanggotaan, jumlah dan nominal keuangan; rollback |
| `alumni_graduation_race_test.php` | Dua operator memakai satu master; siswa sama diproses tepat sekali; kegagalan audit membatalkan kelulusan |
| `master_migrated_billing_test.php` | 744 pemeriksaan terhadap tarif/tagihan Legacy nyata; DU/SPP belum dibayar, snapshot berbayar, diskon, versi basi, penerbitan ulang, rollback, dan jumlah transaksi |
| `master_workspace_http_test.py` | 110 pemeriksaan; edit Alumni tiga unit, penolakan pembalikan kelas, CRUD siswa native dengan pecahan, pagination 0/18/26/53, CRUD kelas khusus, halaman master empat cakupan, tautan buku benar-benar dibuka, PDF dan penolakan draf lintas sesi/unit |
| `master_catalog_migrated_http_test.py` | 25 pemeriksaan; CRUD katalog tiga unit, duplikasi, presisi, snapshot transaksi/tagihan lama, hapus master terpakai/kosong, lintas unit, proteksi Alumni |
| `master_legacy_workspace_browser_test.js` | Masing-masing **146** pemeriksaan di Chrome **155.0.8059.85** dan Edge **155.0.4283.62**; SD/SMP/SMA/Semua, lebar 1440/768/390, terang/gelap, menu/keyboard, tanpa JavaScript, PNG/ZIP, draf gagal, tanpa error JavaScript |
| `master_legacy_export_failure_browser_test.js` | **8** pemeriksaan Chrome; identitas Alumni, reset, kegagalan halaman kedua tanpa unduhan parsial, retry dan klik ganda |
| `parent_letter_png_readback_test.py` | **26** pemeriksaan; satu halaman dan dua penerima/enam halaman, pesan berformat, urutan ZIP, ukuran dan latar; dibandingkan per halaman dengan rasterizer Poppler independen |

PNG dibandingkan pada 150 DPI dengan referensi Poppler: rerata perbedaan kanal RGB di bawah 8/255 setiap halaman. PNG asli tetap sekitar 300 DPI. Halaman tunggal dan halaman akhir surat majemuk juga diperiksa visual.

Regresi existing yang lulus: `flexible_promotion_test.php`, `flexible_promotion_invalid_test.php`, `class_graduation_history_test.php`, `psb_placement_test.php`, `class_rombel_transfer_promotion_test.php`, `historical_reports_after_promotion_test.php`, `spp_published_rate_lock_test.php`, `student_tariff_consistency_test.php`, `savings_book_test.php`. Tambahan: target tahun POST Master SPP/DU dan penolakan tahun array, proteksi rombel terisi, serta **75** pemeriksaan publisher Biaya Lain/stale form/replay. Fixture rombel dan aktor tes SPP disesuaikan dengan snapshot/akun pada skema terkini. Lint PHP/JavaScript dan `git diff --check` dijalankan.

## Batas dan catatan bukti

- **Chromium 109 belum dijalankan pada pekerjaan ini.** Perintah gabungan menyalakan HTTP QA dan mengunduh browser ditolak pemeriksaan persetujuan otomatis dengan alasan `blocked by policy`. Unduhan browser tidak dijalankan/dicoba ulang. Server QA kemudian dijalankan terpisah; pengujian memakai Chrome/Edge yang sudah terpasang. Kompatibilitas target build legacy tidak dianggap sebagai bukti uji aktual Chromium 109.
- `student_optional_fees_test.php` lama masih mengacu pada kolom/fitur Pangkal yang sudah dihapus. Tes tersebut tidak lulus pada skema sekarang dan tidak dihitung sebagai verifikasi. Tidak mengembalikan kolom Pangkal demi fixture lama.
- Tidak menguji printer fisik, perangkat Windows 7, atau ekspor ratusan halaman sekaligus. ZIP menyimpan blob seluruh halaman sampai selesai, sehingga kebutuhan memori mengikuti banyaknya halaman.
- Pembandingan baca-saja ke snapshot pemulihan terdahulu menunjukkan 61 dari 62 tabel identik; `daftar_ulang_audit_log_data` memiliki satu baris tambahan. Snapshot tersebut mendahului pekerjaan ini, sehingga tidak dipakai untuk mengklaim seluruh database identik sepanjang sesi. Semua perintah mutasi pekerjaan ini diarahkan ke instance QA terpisah; tidak ada rekonsiliasi atau perbaikan data pengguna.
- Log, PDF, PNG, ZIP, dan salinan database QA tetap privat di luar web root/repository. Layanan QA dihentikan setelah verifikasi; salinan/evidence dipertahankan.
