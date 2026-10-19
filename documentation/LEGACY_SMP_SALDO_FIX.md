# Saldo FIX presisi sumber SMP — 19 Oktober 2026

## Masalah dan keputusan

Backup `smp17102026` dan `source.dat` pekerjaan lab `12924a6b4da734f17a343e3c830ba0cb` memiliki SHA-256 yang sama: `61a115c21dd1f672ecd86b08885618b81e79b52fab4d57ffdf93e787add80b7f`.

Rohis Mhi (`220817`, GK) memiliki satu rekening, SALDO mentah `0.13100000005215406`, dan tujuh jurnal. Enam jurnal bernilai rupiah penuh berjumlah masuk/keluar Rp643.000; satu jurnal masuk tanggal 7 September 2017 bernilai `0.131`. Parser dua desimal menolak kedua nilai pecahan tersebut, melewatkan jurnal, dan sebelumnya keliru menghitung rekening sebagai tidak tersedia.

Pengguna memilih jurnal operasional **Rp0,13**, saldo FIX **Rp0**, dan penyesuaian **−Rp0,13**. Ini bukan pengesahan pembulatan global atau perubahan arsip sumber.

## Perilaku aplikasi

- Keberadaan rekening dihitung terpisah dari validitas nominal. Kesalahan rekening ganda tetap dibedakan dari kesalahan presisi saldo/jurnal; hasil jurnal yang belum lengkap tidak ditampilkan sebagai saldo nol yang telah cocok.
- Periksa Saldo FIX menawarkan nominal jurnal sebelum/sesudah dan dampak saldo dalam satu konfirmasi. Tabel menjadi kartu pada ponsel, memakai token tema dan komponen Legacy existing.
- Setelah konfirmasi, koreksi jurnal dan FIX disimpan bersama melalui satu perubahan draf existing. Koreksi lain, tanggal, arah, operator, pemetaan, kelas dan bukti asli dipertahankan. Pembatalan/validasi gagal mempertahankan isian serta checkbox.
- Pembulatan usulan menggunakan string desimal, dua desimal dan aturan setengah ke atas; hanya rincian yang ditampilkan dan disahkan menjadi koreksi operasional. Parser uang umum tetap ketat.
- Pengesahan saldo sumber berpresisi berlebih diikat ke kunci baris dan nilai mentah. Jurnal tidak valid lainnya, tanggal/arah salah, rekening ganda dan identitas ambigu tidak dilewatkan. Konfirmasi memeriksa kembali sumber, versi, pelaku, unit, token, CSRF, masa berlaku, versi aturan dan dampak sebelum penyimpanan.
- Aturan operasional **9** menggugurkan cache/simulasi lama. Alur UI mengantrekan simulasi setelah pengesahan. API/draf existing tetap digunakan; metadata baru bersifat opsional dan tidak ada perubahan skema MySQL.
- Penyesuaian tampil sebelum peralihan, membawa saldo sekali, dan tidak menjadi kas baru. Setelah rekening diterapkan, gunakan Koreksi Rekening untuk transaksi lanjutan.

## Pemasangan lab dan hasil

Tujuh file aplikasi terkait dipasang dari source utama ke lab copy. Konfigurasi, unggahan, antrean lain, dan source asli tidak ditimpa. File sebelumnya serta draf versi 8 dicadangkan; worker lab dimulai ulang dan heartbeat melaporkan aturan 9.

Pada draf SMP versi **8 → 9**, hanya FIX `220817` dan satu koreksi jurnal pecahan ditambahkan. Koreksi penarikan tanggal 3 Januari 2018 yang sudah disimpan pengguna tetap sama. Simulasi ulang menghasilkan:

| Pemeriksaan | Hasil |
| --- | --- |
| Pembayaran siap | 60.181 |
| Pengecualian / kelompok masalah | 0 / 0 |
| Jurnal Rohis Mhi | 7 jurnal asli operasional + 1 penyesuaian |
| Masuk / keluar historis | Rp643.000,13 / Rp643.000 |
| Penyesuaian / saldo akhir | −Rp0,13 / Rp0 |
| Total pembayaran historis | Rp56.374.180.301 |

GET localhost lab pada tabungan dan konfirmasi mengembalikan HTTP 200. Rekening menampilkan Siap; tombol Finalisasi Migrasi tersedia dan **tidak ditekan**. Draf tetap belum dikonfirmasi final.

Fingerprint isi seluruh **51 tabel `db_spp` dan 59 tabel `db_spp_test2` identik** sebelum/sesudah. Tidak ada impor final, perubahan skema/database utama, commit/push, atau deployment.

## Pengujian dan batas

- `tests/legacy_savings_precision_fix_test.php`: kasus sumber SMP, nominal mentah tetap utuh, tujuh jurnal, satu penyesuaian, saldo akhir nol, operator/tanggal, pengulangan deterministik, penolakan nominal negatif/teks, sumber berubah, tanggal salah, rekening ganda dan FIX tidak disahkan. Regresi pure, bulk dan pemeriksaan otomatis lulus.
- Controller aplikasi dijalankan melalui proses CLI terpisah pada salinan database/importer: GET, pratinjau, kembali mengubah, validasi gagal, CSRF/token salah, penyimpanan atomik dan kiriman ulang. Simulasi sumber SMP lengkap menghasilkan nol pengecualian. Pengujian lanjutan menolak versi aturan lama, versi draf berubah, konfirmasi kedaluwarsa dan sumber berubah tanpa mengubah draf.
- Fixture publikasi pada MySQL privat port 58236 menggunakan baris Rohis Mhi: backup dipulihkan dan terverifikasi, kegagalan sebelum commit dibatalkan penuh, penerapan/retry mempertahankan delapan jurnal dan satu audit penyesuaian, saldo/buku nol, dan penyesuaian tidak masuk laporan kas baru.
- HTML hasil render dan fragmen konfirmasi terbaru diperiksa dengan Chrome 155 serta Chromium **109.0.5412.0**, ponsel 390/desktop 1366, terang/gelap, JavaScript aktif/nonaktif. Fokus modal, batas layar dan screenshot diperiksa. Ini pengujian visual HTML lokal, bukan pengiriman formulir melalui server QA.
- Peluncuran server HTTP QA ditolak pemeriksaan persetujuan otomatis dengan pesan `blocked by policy`, tanpa alasan lebih rinci. Mutasi formulir diuji melalui controller CLI terisolasi; pengujian HTTP langsung pada lab hanya GET. Zoom dan Windows 7 fisik belum diuji dalam pekerjaan ini.

Bukti privat berada di `C:\laragon\backups\spp-management-system\smp_fix_20261019`: backup SQL/SQLite/draf, hasil controller dan simulasi, backup terverifikasi/rollback, screenshot, fingerprint sebelum/sesudah dan hasil baca lab. Data pengujian tidak ditambahkan ke database pengguna.
