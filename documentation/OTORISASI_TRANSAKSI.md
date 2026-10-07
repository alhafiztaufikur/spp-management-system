# Otorisasi Transaksi — pembaruan 7 Oktober 2026

## Hak akses

| Peran | Pemantauan | Edit/hapus pembayaran | Keputusan |
|---|---|---|---|
| Super Admin | Unit aktif, atau Semua Unit | Langsung pada satu unit | Setujui/tolak pada satu unit |
| Admin | Semua petugas dalam unitnya | Pengajuan transaksi sendiri dengan alasan | Tidak diperbolehkan |
| Kasir | Transaksi yang pernah dia ajukan | Pengajuan transaksi sendiri dengan alasan | Tidak diperbolehkan |
| Bendahara | Semua petugas dalam unitnya | Tidak diperbolehkan | Tidak diperbolehkan |

Pemohon dapat membatalkan pengajuan sendiri yang masih menunggu. Semua Unit merupakan tampilan baca; pilih SD, SMP, atau SMA sebelum melakukan perubahan. Aturan baru tidak mengubah identitas pelaku pada riwayat lama.

## Cara penggunaan

1. Admin atau Kasir membuka Riwayat Pembayaran, memilih Ajukan Perubahan/Ajukan Hapus, lalu mengisi alasan. Pembayaran belum berubah.
2. Buka Otorisasi Transaksi → Antrean. Pilih kartu untuk melihat identitas, usulan, alasan, dan riwayat langkah.
3. Super Admin memilih unit pengajuan, memeriksa perbandingan, kemudian menyetujui atau menolak. Catatan penolakan wajib diisi.
4. Buka tab Riwayat untuk memantau hasil keputusan dan perubahan langsung. Riwayat tetap tersedia setelah pembayaran dihapus.
5. Gunakan Lihat Riwayat Lengkap untuk melihat operator, alasan/catatan, dan perubahan sebelum/usulan/sesudah.
6. Khusus Super Admin, Export PDF membuka pratinjau. Dokumen mencakup seluruh transaksi hasil filter, termasuk halaman yang tidak sedang tampil, dengan ringkasan dan kronologi lengkap.

Filter mencocokkan aktivitas dalam riwayat; kartu menunjukkan aktivitas terakhir transaksi. Jumlah hasil menghitung transaksi, bukan jumlah kejadian. Nama/operator yang tidak mempunyai bukti ditulis “Tidak tercatat”. Timeline tidak membuat langkah tambahan yang tidak ada dalam sumber.

## Antarmuka dan endpoint

- Antrean dan Riwayat memakai banner, tab, filter, daftar kartu, serta panel detail. Daftar dan detail mempunyai area gulir; pada layar kecil kedua panel tersusun vertikal.
- Pagination tetap 25 hasil. Filter jenis/status tetap mendukung beberapa pilihan; pilihan dipertahankan pada tautan, pagination, dan PDF.
- `GET otorisasi_detail.php?view=queue|history&id=...&unit_id=...` menghasilkan `{ok,html}`. ID pada antrean adalah ID pengajuan; pada riwayat adalah ID pembayaran asal. Unit hanya boleh mempersempit cakupan sesi.
- `GET otorisasi_aktivitas.php?id=...&unit_id=...` menyajikan aktivitas lengkap yang juga digunakan dialog.
- Khusus Super Admin, `GET otorisasi_export_pdf.php` menerima `kind`, `status`, dan `q` yang sama dengan Riwayat. `output=preview` merupakan bawaan; `output=pdf` mengunduh PDF.
- Pemeriksaan peran tidak bergantung pada tombol. Endpoint keputusan dan fungsi bersama memeriksa akun Super Admin aktif. Permintaan tanpa hak akses ditolak tanpa mencatat keputusan; operasi gagal/rollback tidak meninggalkan perubahan pembayaran yang berhasil.

## Verifikasi

Pengujian mutasi dijalankan pada `db_spp_audit_authorization_20261007`, salinan database audit di luar database aktif.

- `authorization_redesign_browser_test.js`: pengajuan Admin/Kasir pada SD/SMP/SMA; persetujuan, penolakan, pembatalan, snapshot berubah, penolakan POST palsu/CSRF, eksekusi berulang, transaksi terhapus, isolasi unit, dialog, navigasi cepat, dan penggunaan tanpa JavaScript. Matriks visual: empat cakupan, dua tab, tiga ukuran layar, dua tema.
- `authorization_policy_test.php`: pembatasan fungsi bersama, Super Admin nonaktif, rollback keputusan, pembatalan pemohon, pembatasan unit dan Semua Unit.
- `payment_activity_test.php`: jurnal tidak dapat diubah/dihapus, rollback, idempotensi, serta bukti operator.
- `payment_role_access_test.php`: pengujian lama diperbarui agar keputusan dan perubahan langsung memakai Super Admin; persetujuan Kasir/Bendahara ditolak dengan HTTP 403.
- PDF dibaca kembali: 28 transaksi per unit atau 84 pada Semua Unit tercantum sekali dalam ringkasan dan sekali dalam detail; hasil kosong tetap mempunyai identitas. Dokumen diperiksa secara visual serta terhadap batas halaman. Catatan/perubahan panjang dengan karakter HTML literal diuji hingga tujuh halaman; penanda akhir tetap terbaca tanpa teks keluar halaman.
- Pemeriksaan integritas keuangan sebelum/sesudah pada salinan: 30 pemeriksaan tanpa pelanggaran. Pemeriksaan database aktif bersifat baca saja.

Tidak ada migrasi, perubahan data keuangan aktif, commit, atau push. Perubahan lokal sebelumnya tetap dipertahankan.

## Revisi popup dan akses ekspor

Persetujuan edit/hapus, penolakan, dan pembatalan menggunakan dialog modern dengan identitas transaksi, dampak tindakan, Batal, serta konfirmasi. Alasan penolakan divalidasi sebelum dialog dibuka. Escape membatalkan konfirmasi dan fokus kembali ke tombol pemicu.

Admin, Kasir, dan Bendahara menerima HTTP 403 pada endpoint PDF untuk seluruh bentuk output. Pembatasan tidak bergantung pada tombol. Riwayat Pembayaran tetap dapat dibaca seluruh petugas dalam unit, tetapi edit/hapus/cetak mengikuti pembuat awal dan hak peran; lihat RIWAYAT_PEMBAYARAN.md.
