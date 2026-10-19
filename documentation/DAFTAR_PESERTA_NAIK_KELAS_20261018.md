# Daftar Peserta Naik Kelas — 18 Oktober 2026

## Pemantauan dan pemrosesan

Pada **Master Kelas/Rombel → Naik Kelas**, pilih tahun dan tingkat asal. **Lihat Daftar Peserta Tahun Ajaran** membuka daftar pemantauan; **Tutup Daftar Peserta** menutupnya. Daftar tertutup pada kunjungan normal. Pencarian lintas tahun dan tautan Buka Peserta/Lihat Peserta Tujuan membukanya melalui `show_participants=1`.

Daftar mengikuti tahun dan tingkat terpilih, termasuk siswa yang sudah diproses atau belum memenuhi syarat. **Checkbox pemantauan hanya untuk ekspor** dan terpisah dari checkbox formulir kenaikan. Menutup daftar tidak menghapus pilihan pada formulir kenaikan.

**Tingkat Saat Ini/Rombel Aktif** berasal dari data siswa sekarang. **Rombel Penempatan** berasal dari tahun terpilih. Rombel tujuan dengan keterangan **Tersimpan** mempunyai penempatan berikutnya; **Rencana** hanya rekomendasi existing, belum disimpan. Mode Custom tidak memilih tujuan otomatis. Kelulusan tetap memakai aturan tingkat akhir unit.

Tahun mendatang boleh dilihat dan diekspor, tetapi belum boleh menjadi asal proses berikutnya. Pengaman kenaikan ganda, unit, penempatan asal, autentikasi dan tindakan existing tetap berlaku.

## Pencarian, pilihan, dan ekspor

- Pencarian tahun terpilih mencakup nama, NIS, NIS Diknas dan rombel. Pagination menyediakan 10/25/50 baris, default 10. Pencarian atau perubahan jumlah baris kembali ke halaman pertama.
- Checkbox kepala tabel memilih halaman yang terlihat. Menu ⋮ header menyediakan **Pilih Semua Hasil** dan **Kosongkan Pilihan**. Pilihan bertahan saat pagination atau pencarian, termasuk siswa tersembunyi. Pergantian unit/tahun/tingkat/mode membersihkan pilihan.
- Menu ⋮ baris menyediakan **Detail Penempatan** hanya baca dan **Buka Data Siswa** melalui akses existing. Menu menggunakan geometri viewport bersama dan mendukung keyboard; detail mengembalikan fokus ke pembuka.
- **Ekspor → Excel/PDF** mengekspor semua siswa terpilih, termasuk yang tersembunyi. Tanpa pilihan, seluruh hasil pencarian diekspor, bukan hanya halaman aktif. Jumlah terpilih serta cakupan ekspor tampil pada toolbar.

`POST master_kelas_export.php` menerima `csrf_token`, `format=excel|pdf`, `source_year_id`, `source_level`, `promotion_mode=regular|custom`, `roster_q`, dan opsional `selected_placements[]`. ID pilihan harus merupakan penempatan dalam unit/tahun/tingkat yang sama. Scalar/nested pilihan, format dan cakupan tidak valid ditolak. GET tidak digunakan untuk ekspor. Semua Unit tidak dapat memperluas cakupan ekspor penempatan.

Pembentuk data bersama `includes/promotion_participants.php` dipakai tabel, detail, dan ekspor. Pemanggilan ini hanya membaca; tidak membuat tahun ajaran atau penempatan. PDF memakai teks pada A4 lanskap dan kepala tabel berulang; Excel menyimpan NIS sebagai teks. Snapshot/username audit tetap tersimpan.

## Informasi dan identitas petugas

Peringatan **“N siswa belum dapat diproses”** memisahkan nama/NIS, penempatan asal, kelas aktif, alasan, dan tautan pemeriksaan. Tidak ada perbaikan atau pembukaan blokir otomatis.

Keterangan `@username` serta placeholder Username tidak tercatat dihilangkan dari detail histori siswa Input Pembayaran, Riwayat Pembayaran/Daftar Ulang, serta daftar/detail/aktivitas Otorisasi pembayaran dan tabungan, termasuk AJAX. Nama, peran, waktu dan audit tetap ditampilkan. Akun, pencarian, payload/snapshot, dan ekspor audit existing tidak diubah.

Informasi Master Biaya Lain menjadi: **“Kelola biaya tambahan di luar SPP. Berulang dibayar langsung; Tidak Berulang diterbitkan sekali per siswa.”** Label sistem kenaikan tidak lagi memakai tanda pisah dekoratif.

## Verifikasi dan penerapan

Tidak ada migrasi database atau perubahan aturan pembayaran/kenaikan. Cadangan sumber terverifikasi dan artefak pengujian berada di `C:/laragon/backups/spp-management-system/promotion_roster_20261018`. Pengujian mutatif/regresi kenaikan hanya pada instance MySQL terpisah dengan UUID, port dan datadir terverifikasi; jumlah transaksi dan saldo operasional dibandingkan sebelum/sesudah.

Pengujian lulus: 142 model/HTTP/keamanan ekspor/regresi, 742 pemeriksaan browser pada SD/SMP/SMA, terang/gelap dan lebar 1632/1024/390 px, 11 interaksi keyboard/unduhan/reset/aktivitas, serta 26 pemeriksaan baca balik PDF/XLSX. Unduhan dari tombol browser juga diperiksa dengan pilihan yang tersembunyi oleh pencarian.
