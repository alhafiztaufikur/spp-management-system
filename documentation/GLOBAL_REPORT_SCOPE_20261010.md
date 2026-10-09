# Unit Laporan Global — 10 Oktober 2026

## Penggunaan

Super Admin, Admin, Kasir, dan Bendahara dapat memilih SD, SMP, SMA, atau Semua Unit pada katalog Laporan Global dan template di dalamnya. Empat tombol bersegmen memakai bingkai membulat dan pilihan aktif biru; ukuran seragam minimal 48 px. Desktop/tablet memakai satu baris, ponsel sampai 650 px memakai dua baris/dua kolom. Navigasi GET tetap bekerja melalui keyboard dan tanpa JavaScript.

Pilihan awal mengikuti unit operasional. Pilihan laporan tidak menulis sesi unit operasional dan tidak memberi hak transaksi lintas unit. Unit yang dipilih tetap terbawa pada filter, pagination, Reset, kembali, cetak, PDF, dan Excel. Saat mengganti cakupan, pilihan siswa, kelas/rombel, operator, pencarian, serta kategori dibersihkan; tanggal dan tahun yang tersedia pada target dipertahankan.

## Kontrak akses

Parameter `unit` menerima `1`, `2`, `3`, `all`; nilai kosong/`active` mengikuti unit operasional. Bentuk array, nilai negatif, `0`, `4`, atau teks lain menghasilkan HTTP 400. `scope_change=1` menandai navigasi cakupan yang membersihkan filter bergantung unit.

Resolver baru hanya dipakai pada `laporan/global.php`, serta template/ekspor untuk status, penerimaan, spp-tahunan, per-item, tabungan-siswa, saldo-tabungan, riwayat-tagihan, setoran, dan kas-tabungan. Surat kepala sekolah tetap menggunakan resolver lama, demikian pula Dashboard, Laporan Umum, surat, master, dan transaksi. Akun tetap divalidasi melalui bootstrap/session/role sebelum scope diterapkan; sesi anonim/nonaktif tidak mendapat akses. Endpoint Global baru membaca melalui GET.

Query laporan menggunakan konteks database yang dipilih untuk operator, kelas, rentang tingkat, serta identitas data. Unit laporan tidak mengambil alih sesi operasional. Semua Unit memakai penggabungan per unit dan menambahkan identitas unit pada baris, kelas, serta pilihan siswa. Ekspor memakai identitas sekolah sesuai cakupan. Tidak ada perubahan skema atau data operasional.

## Verifikasi

QA memakai clone `db_spp_audit_global_scope_20261010` dari dump baru. Kredensial/sesi, dump, screenshot, dan fingerprint berada di direktori privat `C:\laragon\backups\spp-management-system\global_report_units_20261010`, di luar repository.

- Model: empat peran, sembilan template, tiga unit serta gabungan. Jumlah baris dan total gabungan cocok dengan penjumlahan per unit; penerimaan dan saldo tabungan dibandingkan dengan SQL independen. Cakupan, alias active, identitas baris, operator, kelas, dan pembersihan filter diperiksa.
- HTTP: 818 permintaan mencakup empat peran × empat cakupan × sembilan template dan preview/cetak/Excel/PDF. PDF/XLSX biner valid. Cakupan unit bertahan pada formulir; scope salah/array, akses anonim, permintaan mutasi asing, dan batas Dashboard/surat tetap diperiksa.
- Pemeriksaan tambahan 243 permintaan tanpa ekspor mencakup akun nonaktif, dengan pengembalian fixture akun. Fingerprint seluruh tabel clone identik setelah suite.
- Readback Excel: 387 permintaan tambahan mencakup seluruh peran/cakupan/template; 144 workbook dimuat kembali dan identitas sekolah setiap sheet cocok dengan unit yang dipilih. Reader juga menolak formula executable yang tidak diharapkan.
- Browser: 192 keadaan (empat peran × empat cakupan × dua halaman × tiga lebar × dua tema), tanpa galat JavaScript/overflow. Ukuran tombol seragam, satu baris desktop/tablet atau 2×2 ponsel, warna aktif biru/putih, keyboard, tanggal/tahun yang dipertahankan, pembersihan filter, identitas siswa, navigasi native dan unit operasional diuji.
- Seluruh 30 pemeriksaan integritas clone tetap nol.

Regresi sepuluh template/rekap kas, surat, dan draf berformat lulus. Tes modular lama yang masih mengharapkan komponen Pangkal diperbarui agar mengikuti kontrak PSB yang sudah berlaku pada kode asal. Pemeriksaan sintaks PHP/JavaScript dan `git diff --check` lulus. Fingerprint seluruh tabel operasional serta empat berkas lokal yang dikecualikan tetap identik; health lokal `ok`.

Data Siswa, koreksi tarif SPP, dan perbaikan Susun Pesan sebelumnya tetap dipertahankan. Empat berkas lokal awal tidak disertakan dalam commit. Status master SD 2027/2028 dan seluruh database operasional tidak diubah pada pekerjaan ini.
