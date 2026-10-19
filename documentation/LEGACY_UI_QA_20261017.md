# Pemeriksaan UI/UX Import Legacy — 17 Oktober 2026

## Perubahan

- Tiga kartu tujuan pada Import Legacy, riwayat berwaktu WIB, serta tautan melanjutkan pekerjaan. Tab menggunakan GET dan tetap dapat dibuka tanpa JavaScript.
- Migrasi Penuh memakai lima tahap GET. Bagian pengecualian terpisah menjadi Siswa & Kelas, Komponen Biaya, Tabungan, dan Tunggakan. Ringkasan berada di samping pada desktop dan di atas pada ponsel.
- Penggantian kelompok memuat ulang anggota melalui GET. Pratinjau menampilkan perbandingan berlabel; Kembali Mengubah mempertahankan isian tanpa pengesahan. Pengaman versi/fingerprint tetap dipakai.
- Formulir server untuk unggah, pemeriksaan, konfirmasi, dan hasil Impor Siswa/Histori Keuangan. Aksi JSON lama memakai layanan unggah/penerapan yang sama. Kunci permintaan opsional mencegah unggah formulir yang sama membuat pekerjaan ganda, termasuk saat layanan worker kemudian tidak tersedia.
- Arsip dan peninjauan keuangan dipisahkan. Pencarian siswa pada endpoint keuangan dibatasi Super Admin dan unit aktif. UI menampilkan nama/NIS/kelas; ID hanya menjadi nilai pilihan tersembunyi. Pencarian arsip, daftar peninjauan, dan komponen memiliki filter/pagination.
- Kode masalah dan metadata tindakan ditambahkan sebagai field opsional; tindakan tidak ditentukan dari pencocokan kalimat error. Data mentah berada dalam rincian sumber.
- Sidebar dirender dalam ruang variabel terpisah agar tidak menimpa bagian/tahap pekerjaan. Gulir otomatis formulir Legacy dibuat stabil pada Chromium 109. Tanggal sumber tanpa zona pada hasil ekstraksi diformat dengan benar; tidak mengubah tanggal tersimpan. Kontras pilihan aktif pada tema gelap diperbaiki.

## Lingkungan pengujian

Seluruh mutasi memakai MySQL 8.4 terisolasi, UUID dan direktori data berbeda dari Laragon operasional, database bernama `db_spp`, koneksi loopback, event scheduler nonaktif, serta kredensial privat. SQL Server LocalDB QA terpisah memulihkan sumber. Server PHP QA memakai PHP 8.3.33 pada port privat. Fixture dan tes memverifikasi identitas instance sebelum mutasi.

Artefak berada di `C:\laragon\backups\spp-management-system\legacy_ui_qa_20261017`, di luar webroot/Git. Tidak ada pemasangan browser baru; runtime Chromium 109 existing digunakan kembali. Penolakan cleanup sebelumnya dihormati dan runtime importer operasional dipertahankan. Setelah pengujian, HTTP, LocalDB, dan MySQL QA dihentikan dengan pemeriksaan identitas proses/instance. Browser ditutup; data, log dan bukti QA privat dipertahankan, tanpa mencoba ulang penghapusan yang sebelumnya ditolak.

## Hasil

| Pemeriksaan | Hasil |
| --- | --- |
| Workflow/model dan API existing | Lulus: pengesahan kelompok, versi/digest, CSRF, unit/peran, formulir server, input gagal, simulasi worker sebenarnya |
| Keuangan existing | Lulus: saldo awal, setoran/penarikan baru, cicilan/pelunasan, struk, otorisasi edit/hapus, retry, akun dicabut, CSV, konfirmasi tanpa JavaScript |
| UI native baru | Lulus: landing, tahap/bagian GET, penggantian sasaran, pratinjau manusia, kembali mengubah, pencarian unit, CSRF, konfirmasi ulang ditolak, unggah ulang formulir tidak membuat pekerjaan kedua |
| `.dat` melalui formulir server | Lulus: SD asli → pemeriksaan → konfirmasi → penerapan → hasil untuk kategori siswa dan arsip keuangan pada salinan |
| Tiga sumber asli | Restore/CHECKDB/profil/ekstraksi lulus: SD 34.676 baris/77 komponen, SMP 43.599/104, SMA 11.133/30; jumlah serta total komponen dicocokkan dengan perhitungan independen |
| Sumber besar | Lulus: 3.000 siswa/63.000 baris, termasuk 60.000 histori berulang; pengesahan dan simulasi tidak menerbitkan data operasional |
| Final Migrasi Penuh | Lulus melalui konfirmasi server/worker: rollback sebelum commit; hasil sesudah commit diblokir; Verifikasi Ulang dan backup pemulihan lulus; retry tidak menggandakan hasil; halaman selesai hanya sesudah verifikasi |
| Sesudah migrasi pada salinan | Lulus: transaksi tabungan baru, saldo tidak dihitung dua kali, cicilan/pelunasan/idempotensi, histori tidak menjadi kas baru, rincian, PDF, dan Excel |
| Integritas salinan | Semua pemeriksaan `readiness_integrity_audit.php` bernilai OK, termasuk relasi Legacy, saldo/jurnal, total pembayaran, dan tunggakan |
| Operasional | Fingerprint isi 50 tabel sebelum/sesudah identik; tidak ada migrasi skema atau impor pada database operasional |

Browser yang benar-benar dijalankan: **Chrome 155.0.8059.80**, **Edge 155.0.4283.45**, dan **Chromium 109.0.5412.0**. Matriks utama lulus **300 kasus** halaman/viewport/tema/palet pada lebar 360, 390, 768, 1024, 1366, dan 1920 px. Kedua tema, empat palet unit, penggantian kelompok, formulir tanpa JavaScript, fokus modal, dan Escape diperiksa. Screenshot desktop dan ponsel ditinjau secara visual; katalog SMP panjang dengan pagination/formulir tanpa JavaScript lulus 36 kasus tambahan, sehingga total pemeriksaan tampilan 336 kasus.

**Batas:** pengujian browser dijalankan di PC pengembangan, bukan Windows 7 fisik. Snapshot yang tersedia berasal dari Februari 2019. Backup final terbaru tetap wajib menjalani pemeriksaan dan simulasi sendiri. Kelulusan UI tidak membenarkan melewati masalah keuangan aktif atau persyaratan unit kosong.

## Tes yang digunakan

`legacy_full_workflow_test.php`, `legacy_full_workflow_http_test.php`, `legacy_finance_http_test.php`, `legacy_ui_http_test.php`, `legacy_full_components_sources_test.php`, `legacy_full_workflow_sources_test.php`, `legacy_ui_browser_test.js`, `legacy_ui_catalog_test.php`, `legacy_ui_catalog_browser_test.js`, `legacy_ui_final_test.php`, dan `readiness_integrity_audit.php`.

Jalankan melalui fixture terisolasi dan pemeriksaan identitas pada `tests/http_form_scope.php`; jangan mengarahkan skrip mutasi ke database operasional. Tes UI final dijalankan setelah tes yang membutuhkan unit tujuan kosong. Lint PHP, pemeriksaan sintaks JavaScript, dan `git diff --check` menjadi pemeriksaan penutup.

Panduan pengguna: [Menggunakan Import Legacy](LEGACY_UI_GUIDE.md). Tidak melakukan commit/push atau memperbarui paket deployment.
