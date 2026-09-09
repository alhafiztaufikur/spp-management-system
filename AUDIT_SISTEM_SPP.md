# Audit dan Baseline SistemSPP

**Tanggal audit:** 2026-09-09 (Asia/Jakarta)  
**Ruang lingkup:** pembacaan struktur repository, konfigurasi, alur bisnis, role, schema SQL, database lokal baca-saja, pemeriksaan invariant finansial, dokumentasi, dan test.  
**Batas keamanan dokumen:** tidak memuat password, hash, token, cookie, URL privat, maupun data identitas siswa.

## Ringkasan

SistemSPP adalah aplikasi monolit PHP/MySQL tanpa framework. Halaman dirender dari PHP, menggunakan `mysqli`, session PHP, CSS/JavaScript biasa, serta Dompdf melalui Composer untuk PDF. Database utama bernama `db_spp` dan modul bisnis utamanya adalah siswa, pembayaran multi-komponen, daftar ulang, biaya lain, tabungan, laporan, dan manajemen akun.

Audit dilakukan pada branch `main` yang bersih. Tidak ada migrasi atau perubahan data yang dijalankan selama audit.

## Arsitektur dan domain

| Area | Tanggung jawab utama |
| --- | --- |
| Autentikasi | Login session, role `admin`, `bendahara`, dan `kasir`. |
| Master | Siswa, kelas/rombel, biaya lain, daftar ulang, dan akun operator. |
| Pembayaran | Header `bayar`, klaim periode SPP, detail biaya lain, daftar ulang, serta komponen tahunan. |
| Tahun ajaran | `tahun_ajaran` dan `siswa_tahun_ajaran` menyimpan konteks kelas/tarif per periode sekolah. |
| Tabungan | Saldo `tabungan` serta jurnal masuk `transaksi_m` dan keluar `transaksi_k`. |
| Laporan | Laporan umum, template global, struk, PDF, dan ekspor spreadsheet. |

Pembayaran SPP yang baru menggunakan aturan penuh per bulan, satu transaksi per periode, dan urutan kalender Juli--Juni. Daftar ulang, komponen tahunan, serta biaya lain memiliki tagihan dan batas sisa terpisah. Siswa diarsipkan melalui `is_active`, bukan dihapus dari aplikasi.

## Role aktual

| Role | Akses utama |
| --- | --- |
| Admin | Seluruh master, transaksi, tabungan, laporan, dan akun. |
| Bendahara | Dashboard, laporan, ekspor, dan riwayat tabungan. |
| Kasir | Input/edit pembayaran, tabungan, riwayat terkait, serta laporan/struk yang diizinkan. |

Role aktual harus selalu divalidasi dari kode, karena beberapa tabel lama di dokumentasi belum sepenuhnya mengikuti implementasi terbaru.

## Kontrak finansial yang sudah diperiksa

Pemeriksaan baca-saja pada database lokal menemukan kondisi berikut tanpa selisih:

- Total header pembayaran cocok dengan komponen dan detail terkait.
- Klaim SPP baru tidak memiliki duplikasi atau pemetaan yatim.
- Pembayaran komponen tahunan, daftar ulang, dan biaya lain tidak melampaui tagihan.
- Saldo tabungan tidak negatif dan cocok dengan jurnal masuk dikurangi jurnal keluar.
- Tidak ditemukan relasi pembayaran siswa atau operator yang yatim pada snapshot audit.

Kontrak implementasi yang harus dipertahankan pada perubahan berikutnya:

- Nominal transaksi dihitung ulang oleh backend.
- Mutasi finansial memakai transaction dan penguncian baris yang relevan.
- Pembayaran legacy tidak diedit atau dihapus otomatis.
- Riwayat kelas/tarif menggunakan snapshot tahun ajaran, bukan hanya data siswa terkini.
- Perbaikan data finansial selalu membutuhkan backup dan database disposable untuk regression test.

## Aturan SPP terkini

1. SPP wajib dibayar penuh satu kali per bulan.
2. Kewajiban historis dibentuk dari `siswa_tahun_ajaran` berstatus `aktif`.
3. Setiap penempatan aktif mencakup Juli sampai Juni pada tahun ajaran tersebut.
4. Sebelum membayar periode pilihan, seluruh periode aktif terdahulu yang tercatat harus lunas berdasarkan `spp_perbulan_snapshot` tahun asalnya.
5. Penempatan `pindah` atau `lulus`, periode sebelum penempatan aktif pertama, dan jeda tanpa penempatan aktif tidak membentuk utang otomatis.
6. Edit atau hapus periode prasyarat ditolak apabila sudah ada pembayaran pada periode sesudahnya, termasuk lintas tahun ajaran.

## Risiko dan utang teknis yang masih terbuka

Prioritas tinggi:

- Backup database dan artefak session pernah terlacak di repository. File seperti ini harus dikeluarkan dari Git secara aman sebelum repository dibagikan lebih luas.
- Mutasi pembayaran/tabungan belum seluruhnya dilindungi CSRF dan idempotency; penghapusan pembayaran masih menggunakan GET.
- Konfigurasi database masih ditulis langsung dalam `koneksi.php` dan error koneksi dapat membocorkan detail internal.
- Sebagian akun/data seed masih memakai pola autentikasi legacy. Data demo tidak boleh diperlakukan sebagai basis produksi.
- Database lokal terdeteksi kehilangan beberapa CHECK constraint walaupun schema referensi mendefinisikannya. Verifikasi schema perlu dibuat gagal bila ada requirement `MISSING`.

Prioritas menengah:

- Ringkasan master biaya lain memakai agregasi `SUM(DISTINCT ...)`, yang dapat meremehkan total bila nominal beberapa siswa sama.
- Keterangan tabungan dari form belum disimpan ke jurnal.
- Backend pembayaran belum secara eksplisit menolak transaksi total nol.
- Membuka Master Daftar Ulang dengan parameter tahun dapat membuat tahun ajaran draf melalui GET.
- Saldo API hanya memeriksa login, belum membatasi role secara khusus.
- Beberapa nilai uang legacy masih menggunakan `DOUBLE`; histori juga dapat terhapus bila database diubah langsung lewat foreign key cascade.

## Verifikasi rutin yang disarankan

```powershell
php -l pembayaran/proses.php
php tests/spp_sequence_test.php
node --check assets/js/app.js
```

Test HTTP `tests/payment_process_integration_test.php` sengaja membutuhkan `SPP_TEST_ALLOW_MUTATION=1` dan `SPP_TEST_ADMIN_PASSWORD`. Jalankan hanya pada database disposable yang terpisah dari data operasional.

## Batas audit ini

Audit ini bukan pengganti penetration test, review kepatuhan, backup produksi, atau regression test penuh pada database produksi. Temuan prioritas tinggi harus ditangani bertahap dengan backup, branch terpisah, dan verifikasi sebelum deployment.
