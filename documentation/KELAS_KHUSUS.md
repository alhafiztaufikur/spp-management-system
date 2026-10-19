# Kelas Khusus pada Master Kelas

Pilih satu unit, buka **Master Kelas/Rombel**, lalu pilih **Tambah Kelas Khusus**. Gunakan fitur ini untuk kelompok nonakademik seperti BTQ, GK, atau Tabungan Guru. Rombel akademik tetap dibuat melalui **Tambah Rombel**.

## Penggunaan

1. Isi **Nama Kelas** (maksimal 100 karakter) dan pilih **Aktif** atau **Nonaktif**, lalu simpan. Nama ditampilkan sesuai ejaan pengguna. Nama yang sama dalam satu unit ditolak tanpa membedakan huruf besar/kecil; antarunit boleh sama.
2. Cari kelas pada bagian **Kelas Khusus**, gunakan filter status, atau pindah halaman (25 kelas per halaman).
3. **Lihat Anggota** membuka anggota yang memakai kelas tersebut. Penempatan anggota memakai formulir Data Siswa existing; menambah master tidak membuat anggota atau rekening.
4. **Edit** mengganti nama/status dengan ID tetap. Identitas, rekening, dan snapshot histori tidak ditulis ulang. Nama kelas pada tampilan data sekarang mengikuti master.
5. **Nonaktifkan** menghentikan pilihan kelas untuk anggota baru. Anggota existing tetap berada di kelasnya dan dapat diedit tanpa dipaksa pindah.
6. **Hapus** memerlukan konfirmasi. Kelas dengan anggota, riwayat pemakaian, penempatan, transaksi yang berelasi, atau hubungan Legacy harus dinonaktifkan. Relasi diperiksa kembali saat penghapusan dijalankan.

Kelas khusus tidak mempunyai tingkat akademik, tidak mengikuti kenaikan kelas otomatis, dan tidak menerbitkan SPP otomatis. Pengelolaan master tidak memindahkan siswa, membuat rekening, atau menerbitkan tagihan. Aksi massal rombel akademik tidak mengubah kelas khusus. PSB, OUT, dan Alumni tetap mengikuti mekanisme sistem; kelompok Legacy tidak otomatis disalin menjadi kelas khusus. Mode Semua Unit hanya untuk membaca.

Jika penyimpanan gagal, periksa pesan di halaman; nama dan status tetap terisi. Bila kelas telah berubah pada tab lain, muat ulang sebelum mengulangi. Pembatalan konfirmasi hapus tidak menghapus kelas. Jalur formulir server tetap berfungsi tanpa JavaScript.

## Pemeriksaan dan pemasangan CLI

Jalankan dari folder aplikasi dengan PHP CLI yang dipakai Laragon:

```powershell
php scripts/migrate_special_classes.php
```

Perintah tanpa opsi hanya memeriksa database tujuan dan kesiapan. Pemasangan memakai backup SQL lengkap di luar folder web serta nama database tujuan yang persis:

```powershell
php scripts/migrate_special_classes.php --apply --confirm-target=db_spp_test2 --backup-file="C:\laragon\backups\lab-before.sql"
```

Gunakan koneksi lab ketika memasang ke lab. CLI dapat dijalankan ulang: menyiapkan jenis/nama custom, tingkat kosong, indeks nama per unit, panjang label snapshot yang diperlukan, serta memperbarui view/trigger yang dikenali. Proteksi unit dan Legacy dipertahankan. Perintah ini tidak menjalankan migrasi akun, workflow, atau keuangan. Tidak ada perubahan skema melalui web.

DDL MySQL tidak dapat dibatalkan sebagai satu transaksi. Hentikan mutasi selama pemasangan, simpan backup lengkap, dan periksa hasil CLI sebelum membuka aplikasi. Script memeriksa keberadaan/header backup; uji pemulihan backup tetap harus dilakukan tersendiri. Database utama `db_spp` memerlukan otorisasi pemasangan terpisah dan tidak dipasang dalam pekerjaan ini. Pemeriksaan kesiapan workflow aplikasi existing tetap berlaku; CLI ini tidak menggantikannya.

## Pemasangan dan hasil pemeriksaan 19 Oktober 2026

- Source utama diperbarui; patch fitur dan dependensi yang diperlukan dipasang di **lab copy**, dengan koneksi tetap `db_spp_test2`. Basis aplikasi lab tidak diganti secara keseluruhan.
- Backup lab berhasil dipulihkan pada instance MySQL terisolasi: fingerprint 59 tabel sumber identik. Skema dipasang hanya pada lab; database utama tidak diubah.
- Fingerprint seluruh isi existing sebelum/sesudah identik: **50 tabel utama dan 59 tabel lab**, dengan hanya kolom master baru `jenis` dan `nama_custom` dikeluarkan dari perbandingan lab. Klasifikasi PSB pada kolom baru tidak mengubah timestamp existing.
- Delapan file konfigurasi/runtime yang dilindungi serta manifest/draf sumber yang diperiksa tetap identik. Tidak dibuat kelas, anggota, rekening, atau transaksi contoh pada lab pengguna; tidak menjalankan finalisasi migrasi.
- Tes SQL/HTTP pada dua salinan terisolasi lulus: skema berulang, CRUD, nama 100 karakter, nama kosong/terlalu panjang/reserved, duplikasi/unit, perubahan versi, kelas terpakai, CSRF, retry, konfirmasi hapus server, isian validasi gagal, serta edit anggota pada kelas nonaktif. Penambahan anggota khusus tidak membuat tagihan/rekening atau penempatan akademik.
- Nama kelas diverifikasi pada model dan halaman tabungan, buku tabungan, laporan saldo, workbook Excel yang dibaca kembali, serta respons PDF. Master CRUD tidak mengubah jumlah baris pada delapan tabel keuangan yang diperiksa.
- **48 kasus browser** pada dua basis aplikasi: Chrome 155 dan Chromium **109.0.5412.0**, desktop/tablet/ponsel, terang/gelap, JavaScript/tanpa JavaScript, pencarian, pembatalan hapus, fokus keyboard, dan anggota. Delapan pemeriksaan baca tambahan dijalankan pada lab pengguna tanpa mutasi.
- Empat palet unit diuji pada Chromium 109 dalam kedua tema dan viewport setara zoom 80%, 100%, dan 200% (24 pemeriksaan reflow). Ini simulasi ukuran viewport, bukan pengujian seluruh kombinasi zoom native browser atau perangkat Windows 7 fisik.
- Enam belas rangkaian regresi Legacy, buku tabungan, pengelompokan histori, dan cetak lulus. Tes cache laporan sempat gagal pada konkurensi cold-start; pengulangan terpisah kemudian lulus 20 pemeriksaan. Kode cache laporan tidak diubah oleh fitur ini.

Bukti privat: `C:\laragon\backups\spp-management-system\special_classes_qa_20261019` (backup, fingerprint, hasil browser, dan screenshot). Tidak melakukan commit/push atau pembaruan paket deployment.


## Penyatuan dengan kelas hasil Legacy

Daftar kini mencakup Alumni dan OUT selain custom/kegiatan/rekening, dengan filter jenis. Kelompok rekening hasil migrasi dihubungkan ke master yang sama; kode asal ditampilkan dalam rincian. Kelompok dari Legacy tertutup dan baru dimuat saat dibuka. Lihat [panduan penyatuan kelas](LEGACY_CLASS_UNIFICATION.md) untuk perbaikan existing, CLI, dan hasil pemasangan lab.
