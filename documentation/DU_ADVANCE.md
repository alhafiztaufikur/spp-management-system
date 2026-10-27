# DU tahun berikutnya dapat diterbitkan lebih awal

Pilih unit dan tahun berikutnya pada Master Daftar Ulang, isi tarif kelas tujuan,
lalu tekan **Simpan Tarif & Lengkapi Tagihan**. Kasir memilih tahun tagihan itu
sendiri pada dropdown DU di Input Pembayaran. Cicilan dan pelunasan menggunakan
alur pembayaran yang sama. Tidak ada pengaktifan tahunan atau tugas terjadwal.

Tahun yang boleh diterbitkan adalah tahun berjalan dan tepat satu tahun berikutnya.
Kalender Februari maupun H+1 tidak membatasi penerbitan. Tahun tertutup tidak dapat
diubah. Tarif kosong tidak diubah; kelas tanpa tarif dilewati tanpa tagihan nol.
Tarif positif untuk kelas 1 SD, 7 SMP, dan 10 SMA ditolak karena tercakup PSB.

## Kelas dan penempatan

`includes/du_advance.php` menyediakan keputusan tahun, peserta, kelas tujuan,
validitas relasi, penerbitan dan pengikatan tagihan. Penempatan tahun tujuan yang
sudah ada diprioritaskan. Jika belum ada, kelas diproyeksikan tepat satu tingkat
dari penempatan aktif tahun berjalan. Kelas akhir, siswa tidak aktif dan kelas
nonakademik tidak diproyeksikan. NIS tidak dipakai untuk menebak tahun masuk.

Tagihan tetap unik per unit, siswa, dan tahun. Tagihan proyeksi memiliki
`penempatan_id=NULL` dan `penempatan_asal_id` yang menunjuk bukti penempatan asal.
Master DU tidak membuat penempatan, mengganti kelas aktif, atau menerbitkan
SPP/Komite. Database menolak tagihan yang tidak mempunyai kedua jenis dasar itu.
Validator juga memeriksa unit, siswa, tahun berurutan, batas tingkat dan kelas.

Kenaikan kelas resmi mengisi penempatan tujuan pada ID tagihan yang sama. Harga,
pembayaran, koreksi dan histori tidak dipindah. Perubahan kelas di luar proyeksi
dicatat sebagai konflik tujuan dan pembayaran lanjut ditolak sampai dikoreksi
melalui alur existing. Pergantian kalender sendiri tidak membuat penempatan dan
tidak menghilangkan tagihan yang sudah terbit.

## Pembayaran dan laporan

Payload dropdown mempertahankan kolom lama serta menambahkan `is_future`.
Tagihan Mendatang tidak dipilih otomatis dan tidak dihitung sebagai tunggakan
berjalan. Saat tahun tujuan dimulai, klasifikasi menjadi tahun berjalan melalui
pembacaan kalender, tanpa menyalin atau menerbitkan tagihan lagi.

Tanggal kas tetap tanggal penerimaan. `bayar_du` menyimpan ID tagihan, kelas dan
tahun kewajiban. Kredit native/Legacy hanya mengurangi kewajiban tahun yang sama.
Validasi saldo terkini, snapshot formulir, unit/siswa, kelebihan bayar, permintaan
ganda dan pembatalan tetap berjalan. Pembayaran yang sudah masuk melindungi
snapshot tarif dari perubahan tarif kelas.

Struk menyelesaikan ID DU yang dibayar sebelum memeriksa cakupan PSB tahun kas.
Riwayat, Laporan Global, PDF dan Excel menggunakan kelas/tahun tagihan. Cakupan
PSB tahun pertama tidak menghapus kewajiban kelas lanjutan tahun berikutnya.

## Peningkatan dan verifikasi

Jalankan `scripts/migrate_du_advance_schema.php --database=db_spp --port=3306`
untuk preview. Apply memerlukan `--mode=apply --backup-proof=...` dari backup
lengkap yang telah dipulihkan pada instance terisolasi, dengan checksum seluruh
tabel dan snapshot terlindungi cocok. Script memakai kunci akses existing.
Pembaca FK dan integritas tetap dapat memverifikasi backup sebelum kolom baru ada.

Runtime: workflow 14, operasional 18, otomasi 5, billing 6, DU 4. Metadata lama
tetap dibaca; simulasi/worker versi sebelumnya harus diperbarui. Rekonsiliasi
Legacy tetap melalui runner resmi. Deployment tidak otomatis menerbitkan tarif
atau tagihan tahun berikutnya.

Regresi fitur: `du_advance_test.php`, `du_advance_race_test.php` dan
`du_advance_browser_test.js`. Semuanya membutuhkan database/server uji terisolasi;
pengujian pergantian tahun hanya menerima override pada database audit disposable.
