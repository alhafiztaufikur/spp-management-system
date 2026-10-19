# Filter Rombel dan Tabungan Custom — 18 Oktober 2026

## Riwayat Pembayaran dan Tabungan

Dropdown **Kelas/Rombel** memakai pilihan checkbox beberapa nilai. Default **Semua Kelas/Rombel** mempertahankan hasil URL lama. Opsi mencakup reguler, PSB, Out dan custom; rombel nonaktif yang masih memiliki anggota tetap tersedia. Semua Unit mengelompokkan opsi berdasarkan unit.

Filter mengikuti **rombel siswa/rekening sekarang**, bukan kelas pada snapshot transaksi lama. Beberapa rombel digabung dengan OR; tanggal, siswa dan operator digabung dengan AND. Daftar, ringkasan, jumlah hasil dan pagination memakai pembatas yang sama, termasuk arsip. Snapshot kelas pada bukti pembayaran tetap sebagaimana tersimpan.

Pilihan dipertahankan dalam tautan detail, pagination dan tab aktif/arsip. Kembali dari edit Pembayaran juga mempertahankannya. Pergantian unit membersihkan filter rombel sebelumnya. Parameter `rombel[]` menerima ID yang tersedia pada cakupan unit; scalar diperlakukan sebagai satu pilihan. ID tidak valid atau lintas unit ditolak server. Filter baru tidak mengubah saldo rekening maupun filter **Rekap Saldo** yang terpisah.

## Dashboard dan closing sekolah

Rekening dengan rombel **custom saat ini** dikecualikan dari Tabungan Masuk, Tabungan Keluar, mutasi bersih, jumlah transaksi Tabungan, dan kontribusi Tabungan pada Total Penerimaan dashboard. **Rekap Kas Tabungan Harian** memakai pembatas yang sama pada layar, preview, PDF dan Excel.

Klasifikasi mengikuti rombel sekarang, sehingga perpindahan ke/dari custom mengubah rekap yang dihitung ulang, termasuk mutasi lama. Ini hanya pembatas pembacaan kas sekolah: jurnal, saldo rekening, buku tabungan, riwayat, audit/otorisasi dan laporan khusus Tabungan tetap memuat rekening custom.

`includes/reports.php` memakai flag internal `_school_savings_cash` hanya untuk dashboard dan pembentuk laporan `kas-tabungan`. Flag tidak diterima sebagai filter publik dan tidak mengubah perilaku global pembaca jurnal.

## Penyempurnaan tampilan

- Tahun Tagihan dan Bulan memiliki ukuran kontrol sama serta gaya pilihan aktif/hover yang konsisten. Input empat digit, daftar tahun, POST dan event existing dipertahankan.
- Histori siswa memakai isi 14 px, nominal 15 px, badge 12 px, judul 17 px dan keterangan 13 px; ponsel memakai isi minimal 13 px dengan tabel bergulir.
- Aktivitas Tabungan pada Otorisasi menampilkan identitas di atas, lalu kartu kronologi dengan operator, waktu WIB, alasan dan kolom Sebelum/Sesudah atau Usulan. Kosong menjadi **Tidak tercatat**; nol tetap nol. Markup/CSS khusus Tabungan berlaku juga setelah AJAX, tanpa mengubah aktivitas Pembayaran.
- Dropdown Kelas/Rombel Data Siswa tidak lagi memiliki kotak Cari rombel. Opsi, scroll dan keyboard tetap tersedia. Kontrol identitas diselaraskan pada tinggi 48 px.
- Potongan disusun Potongan PSB → Total PSB, lalu Potongan DU → Total DU dalam dua kolom. Ponsel memakai satu kolom. Tidak ada ikon/ilustrasi/subtitle; catatan PSB dan perhitungan existing tetap tersedia.

## Batas perubahan dan verifikasi

Tidak ada migrasi database atau perubahan aturan PSB, potongan, tagihan DU maupun pembayaran. Kasus Rohman tidak diperbaiki secara finansial sesuai klarifikasi bahwa siswa berada pada PSB. Pengujian mutatif hanya memakai instance MySQL terpisah yang diverifikasi UUID, port dan datadir.

Cadangan sumber dan artefak privat: `C:/laragon/backups/spp-management-system/history_rombel_20261018`. Pengujian mencakup model/HTTP filter, pembacaan kas custom, saldo, pergantian keanggotaan, arsip, desktop/tablet/ponsel, tema/palet, keyboard, AJAX serta pembacaan balik PDF/XLSX. Jumlah transaksi dan saldo database operasional dibandingkan sebelum/sesudah.

Hasil: 598 pemeriksaan browser pada SD/SMP/SMA, terang/gelap, lebar 1650/1024/390 px; 13 pemeriksaan interaksi filter/navigasi; pengujian model/HTTP, layar/ekspor dashboard-closing, dan 36 pemeriksaan baca balik nominal serta jumlah transaksi PDF/XLSX lulus. Regresi laporan/detail custom tiga unit juga lulus. Cadangan sumber ZIP 275 berkas lolos pemeriksaan integritas. Jumlah dan total pembayaran, jurnal masuk/keluar, serta jumlah rekening dan total saldo operasional identik sebelum/sesudah.
