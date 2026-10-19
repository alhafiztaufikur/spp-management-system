# Sinkronisasi migrasi Legacy dengan pembaruan operasional

Source Legacy lokal digabung dengan `origin/main` sampai `1ccaad3`, termasuk `fa62b96`. Kedua commit remote memuat akses operasional lintas unit, rombel Custom/Out, Biaya Lain Berulang/Tidak Berulang, koreksi tabungan, bukti pembayaran, kalibrasi buku PLQ-20, dan penyempurnaan laporan.

## Penyatuan perilaku

- Kelas Alumni Legacy tetap memiliki label/tahun dan tidak menjadi kelas aktif penagihan. Rombel Custom/Out serta penempatan akademik dari remote tetap tersedia.
- Tarif Legacy per siswa, cakupan periode, pembayaran/jurnal migrasi, saldo FIX, penyesuaian nonkas, konfirmasi ringan, serta antrean tunggu akses tetap dipertahankan.
- Laporan mempertahankan status dan operator Legacy bersama operator native yang ditelusuri dari bukti otorisasi. Rombel Custom tetap dikecualikan dari kas tabungan sekolah; buku rekeningnya tetap tersedia.
- Buku tabungan memakai tata letak/kalibrasi baru, dengan pecahan rupiah dan label sumber/penyesuaian Legacy tetap terbaca.
- Setoran/penarikan baru dan koreksi tabungan memakai saldo peralihan sebagai titik awal, ditambah jurnal native sekali. Penghapusan setoran native mengembalikan saldo ke posisi yang benar tanpa menghapus saldo migrasi. Uji siklus setoran, koreksi, buku dan pembatalan lulus.
- Pengelola operasional tetap dapat mengimpor identitas siswa. Arsip keuangan, pengesahan saldo, Migrasi Penuh dan koreksi Legacy tetap membutuhkan Super Admin aktif.
- Penerbitan Biaya Lain memakai alur pratinjau/versioning terbaru. Master Tunggakan Legacy tetap dilindungi dari penerbitan massal atau pilihan biaya berulang biasa; master tanpa tarif tetap nonaktif sampai tarif sah diisi.

## Pemeriksaan

17 rangkaian uji logika/cetak lulus, termasuk tarif, komponen, identitas, presisi, saldo FIX, jurnal nol, memori, antrean akses, buku tabungan, receipt/PDF PLQ-20 dan cache laporan. Uji gabungan pada instance MySQL terisolasi membuktikan publikasi, rollback, retry, arsip, nonkas, dan backup yang benar-benar dipulihkan. Uji SQL/HTTP tambahan memeriksa label kelas khusus, operator gabungan, riwayat/detail, serta pemisahan izin impor siswa dan keuangan. Browser memakai Chrome terpasang dan Chromium 109 pada ponsel/desktop dengan/tanpa JavaScript; Windows 7 fisik tidak diuji.

## Database dan instalasi

Sinkronisasi ini hanya mengubah source/Git. Database utama, konfigurasi privat, deployment dan lab copy dipertahankan. Pembaruan remote membutuhkan skema workflow/rombel serta migrasi akun melalui CLI; skema tersebut **belum terpasang pada `db_spp` saat sinkronisasi**. Pemeriksaan kesiapan remote tetap dipertahankan sehingga halaman utama menampilkan status pembaruan sampai pemasangan tersebut dilakukan.

Ikuti [panduan workflow remote](WORKFLOW_UPDATE_20261017.md) dan [panduan rombel/pembayaran](ROMBEL_PEMBAYARAN_20261018.md), dengan backup terverifikasi dan peninjauan dampak perubahan akun. Tidak menjalankan migrasi akun/skema tersebut otomatis pada database pengguna dalam pekerjaan sinkronisasi ini.
