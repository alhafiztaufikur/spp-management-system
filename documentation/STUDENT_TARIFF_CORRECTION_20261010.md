# Data Siswa dan Koreksi Tarif SPP — 10 Oktober 2026

## Data Siswa

Panel/sakelar/guard Advanced dihapus. NIS Diknas, PSB, Komite, Daftar Ulang, dan potongan langsung dapat diisi oleh pengelola yang berhak. Parameter lama `advanced_enabled` diabaikan. Field yang tidak dikirim saat update mempertahankan nilai lama; nilai kosong yang dikirim tetap mengikuti validasi normal. NIS internal siswa existing dan total hasil perhitungan tetap read-only. Semua Unit menonaktifkan seluruh formulir.

Lima panel tetap tersedia; Potongan memenuhi baris desktop. Reset tidak menghapus data tersimpan. Pemilih kelas native tersedia tanpa JavaScript, bersama validasi/penyimpanan server.

## Tarif SPP

Master draft memakai Simpan Tarif. Master published terkunci secara bawaan dan menyediakan Edit Tarif untuk Super Admin, Admin, dan Kasir sesuai unit. Setelah edit dibuka, perubahan nominal mengaktifkan Simpan Perubahan Tarif; pembatalan memulihkan nilai awal. Tahun closed harus dibuka kembali sebelum koreksi.

Aksi POST `ubah_tarif_terbit` memakai nama field tarif/tahun yang sama, `confirm_rate_change=1`, serta `expected_rate_version`. Simpan draft juga meminta konfirmasi dan versi. Penerbitan pertama memakai `confirm_spp_publish=1`. Versi mencakup unit/tahun, status, tarif, waktu, keberadaan tagihan, dan audit master; stale form ditolak. CSRF serta bootstrap akun/unit tetap berlaku. Tidak ada skema atau endpoint baru.

Koreksi memperbarui tarif master dan tagihan open/waived yang belum menerima pembayaran, memakai potongan nominal yang ditetapkan pada masing-masing tagihan. Tagihan berbayar, cancelled, dan covered_psb tidak berubah. Informasi tarif siswa/penempatan mengikuti mekanisme existing; snapshot berbayar/tahun lain dipertahankan. Audit `koreksi_tarif_terbit` memuat tarif sebelum/sesudah dan jumlah tagihan diperbarui/dipertahankan.

Penguncian siswa memakai bentuk lookup NIS yang sama dengan kasir, lalu mengunci tagihan dan memeriksa alokasi melalui locking read. Ini mencegah siklus antara indeks NIS/FK pembayaran yang ditemukan pada pengujian awal. Jika transaksi lain mengubah tarif/status, pengguna harus memeriksa ulang sebelum menyimpan.

## Konfirmasi

Simpan draft, penerbitan pertama, dan koreksi terbit menggunakan modal Input Pembayaran. Modal menampilkan unit/tahun, tarif per kelas atau nominal lama–baru, serta jumlah siswa pada penerbitan. Urutan penerbitan: konfirmasi tarif → pemeriksaan/konfirmasi tunggakan existing → penerbitan. Perubahan pilihan membatalkan konfirmasi lama. Formulir tanpa JavaScript memakai layar konfirmasi server dengan gaya yang sama. Input dipertahankan saat kembali/gagal; Batalkan menghapus draft koreksi yang belum disimpan.

Tombol Edit/Simpan Tarif memenuhi lebar grid dan memakai ikon pensil/simpan yang konsisten. Batalkan berada terpisah di bawah tindakan utama. Keterangan pendek mengikuti keadaan form: Tarif terkunci, Tarif siap diedit, atau Perubahan belum disimpan; tahun tertutup tetap menunjukkan cara membuka kembali. Pengingat generik Semua Unit dihapus dari seluruh halaman, dengan guard backend dan penonaktifan form readonly tetap utuh. Pemeriksaan browser membaca 18 keadaan tarif (tiga unit × tiga lebar × dua tema), ikon/alignment/status/modal/Batalkan, lima halaman Semua Unit tanpa banner, dan tata letak native tanpa JavaScript; semuanya lulus. Data operasional tidak berubah.

## Verifikasi

Semua mutasi QA memakai `db_spp_audit_student_tariff_20261010`, dipulihkan dari backup baru. Artefak/dump/fingerprint/screenshot privat berada di `C:\laragon\backups\spp-management-system\student_tariff_edit_20261010`.

- `spp_confirmed_correction_test.php`: tiga unit, konfirmasi wajib, pembayaran lama, pembatalan/PSB, potongan penuh yang menjadi tagihan terbuka, stale version, audit, dan tahun tertutup; rollback.
- `spp_tariff_race_test.php`: koneksi kasir dan koreksi independen; koreksi terbukti menunggu kunci kasir, lalu mempertahankan Juli Rp250.000 dan mengubah 11 bulan belum bayar menjadi Rp300.000.
- `spp_correction_http_test.php`: sembilan kombinasi peran/unit melalui controller nyata, no-JS review tanpa mutasi, CSRF, simpan koreksi, stale version, close/reopen; Bendahara dan Semua Unit tidak dapat menulis.
- `student_tariff_edit_browser_test.js`: lima panel, field langsung editable, Reset, 12 kombinasi desktop/ponsel dan terang/gelap, Edit/Batalkan/perubahan Rp1/pemulihan nilai awal/modal, tiga modal dan urutan tunggakan; konfirmasi native, tambah siswa native, NIS existing terkunci, dan Semua Unit read-only. Native action diuji lewat keyboard.
- Regresi nominal siswa melalui HTTP pada tiga unit, proteksi tarif tanpa aksi koreksi, pembayaran bulanan, snapshot historis, parsing nominal, dan target tahun pada POST tetap lulus.
- Update parsial tanpa field biaya/Diknas mempertahankan nilai lama, termasuk kiriman legacy `advanced_enabled=0`. Matriks endpoint menjalankan 307 permintaan peran/unit/anonim/CSRF dan membuktikan penolakan tidak mengubah fingerprint tabel.
- Audit integritas clone setelah pengujian menunjukkan seluruh 30 pemeriksaan bernilai nol.

Pemeriksaan sintaks 18 PHP/11 JavaScript dan `git diff --check` lulus. Hash seluruh tabel operasional serta metrik keuangan identik sebelum/sesudah pekerjaan; health lokal `ok`. Empat file lokal sebelumnya dan kedua file utama perbaikan Susun Pesan tetap identik.

## Koreksi lokal 2027/2028

Skrip CLI `sql/reset_empty_spp_2027_draft.php` hanya menargetkan SD 2027/2028, mempertahankan tarif dan metadata tahun ajaran, serta mencatat `kembalikan_draft`. Pemeriksaan ulang menemukan 24 penempatan pada target; tagihan/pembayaran SPP tahun tersebut masih nol. Guard awal menolak koreksi ketika penempatan ada. Opsi `--preserve-existing-placements` telah diuji pada clone, tetapi penggunaan pada database operasional menunggu keputusan pemilik atas kondisi ini. Tidak ada penghapusan penempatan atau tagihan.

Seluruh perubahan lokal sebelumnya, termasuk perbaikan Susun Pesan, dipertahankan. Tidak ada commit/push.
