# Riwayat Daftar Ulang

## Tampilan dan sumber data

- Tab Aktif mempertahankan satu kelompok per tagihan siswa/tahun, termasuk tagihan tanpa pembayaran. Ringkasan menghitung seluruh hasil filter, bukan halaman yang tampil.
- Daftar kiri dan detail kanan mengikuti komponen visual Riwayat Pembayaran. Filter kelas/tahun/status/operator mendukung beberapa pilihan; ukuran halaman tetap tunggal. Nama rombel pada Semua Unit mencantumkan unit.
- Setiap cicilan menampilkan nominal Daftar Ulang, total transaksi utuh, metode, waktu WIB, pembuat awal, aktivitas terakhir, dan tindakan yang diizinkan.
- Tab Dihapus membaca snapshot jurnal terakhir per transaksi. Kelompok menggunakan unit, NIS, tahun, dan ID tagihan. Tidak bergantung pada siswa atau pembayaran aktif; tidak menebak rincian dari `U_LAIN`.
- Arsip menghitung jumlah siswa/periode, transaksi unik, dan nominal Daftar Ulang dihapus. Filter pelunasan hanya berlaku pada Aktif. Arsip tanpa rincian/ID tagihan yang terbukti tidak dihitung; keterangannya tampil pada halaman.

## Hak akses dan kembali dari edit

- Kepemilikan tetap berasal dari ID pada kejadian Pembayaran dibuat. Super Admin dapat bertindak dalam cakupannya; Admin/Kasir mengajukan perubahan pada transaksi sendiri; Bendahara hanya membaca dan mencetak transaksi sendiri.
- Semua transaksi dalam unit tetap terlihat. Akun lain atau pemilik tidak tercatat terkunci bagi non-Super Admin. Arsip tidak memiliki tindakan edit/hapus/cetak.
- Cetak membuka struk transaksi utuh yang sudah tersedia. Semua Unit tetap memerlukan pemilihan satu unit sebelum perubahan.
- Konteks kembali berupa token sesi acak, terikat akun/unit/transaksi, maksimum dua jam. Setiap render memperoleh konteks sendiri sehingga dua tab dengan filter sama tidak berbagi draf. Rute hanya dibuat server; tidak menerima URL eksternal.
- Simpan, pengajuan, hapus, replay, dan Batal dari halaman ini kembali ke filter/halaman/kelompok asal. Kesalahan edit kembali ke formulir dengan isian aman. Konteks kedaluwarsa kembali ke halaman utama Daftar Ulang.
- Edit tanpa konteks tetap kembali ke Riwayat Pembayaran; keputusan Otorisasi tetap kembali ke Otorisasi.

## Endpoint

`GET pembayaran/detail_daftar_ulang.php?view=active|deleted&tagihan_id=…&unit_id=…`

Parameter filter halaman dapat disertakan untuk mempertahankan konteks kembali. Respons JSON berisi `ok`, `html`, dan `capabilities` per ID pembayaran. Sesi/akun/peran/unit diperiksa sebelum detail ditampilkan. Kesalahan parameter menghasilkan 400, sesi awal tidak tersedia 401, akun tidak diizinkan 403, data di luar cakupan/tidak ditemukan 404, serta kegagalan internal 500. Respons memakai `Cache-Control: private, no-store`.

## Verifikasi 7 Oktober 2026

Pengujian memakai salinan `db_spp_audit_authorization_20261007`; database aktif tidak diubah.

- `registration_workspace_fixture.php`: cocokkan jumlah dan total dengan SQL independen pada empat cakupan; tingkat + rombel tidak menggandakan hasil; identitas siswa mengikuti ID/unit.
- `registration_workspace_browser_test.js`: 48 keadaan (empat cakupan × dua tab × dua tema × desktop/tablet/ponsel), seluruh identitas lintas pagination, pencarian tidak tertutup, pemilihan/panah/tutup, respons lama, gagal/Coba Lagi, Escape/fokus modal, parameter tidak sah, serta tautan tanpa JavaScript untuk Admin/Kasir/Bendahara.
- `registration_workspace_http_test.php`: handler nyata pada tiga unit dan empat peran; struk sendiri/asing, pengajuan Kasir, persetujuan Super Admin, pengajuan hapus Admin, edit langsung, replay, rollback, draf ter-escape, konteks dua tab/kedaluwarsa, arsip terakhir, CSRF, unit asing, serta akun nonaktif. Server HTTP harus membuktikan database salinan yang sama sebelum tes melakukan mutasi.
- `registration_workspace_model_test.php`: snapshot tanpa siswa/tagihan aktif, nol awal, karakter khusus, transaksi campuran, deduplikasi penghapusan, bukti tidak lengkap, dan rollback.
- Pemeriksaan sintaks PHP/JavaScript dan `git diff --check` lulus. Audit integritas keuangan sebelum/sesudah identik: seluruh pemeriksaan bernilai nol.

Fixture HTTP menghapus baris keuangan/pengajuan uji setelah selesai; jurnal uji tetap tersimpan pada salinan karena bersifat tidak dapat dihapus. Fixture model menggunakan rollback. Cookie sesi dan screenshot disimpan di direktori artefak di luar repositori.

Cetak memakai renderer struk yang tersedia; pengujian memeriksa akses dan pratinjau HTML. Hasil kertas fisik/printer belum diuji. Tidak ada migrasi database.

## Filter operator ? 10 Oktober 2026

Pilih satu/beberapa operator lewat dropdown checkbox, lalu Tampilkan Rekap. Pembuat awal transaksi menjadi dasar filter, termasuk arsip yang dihapus oleh petugas lain. Tanpa filter operator, tagihan tanpa pembayaran tetap tampil. Saat filter operator aktif, hanya siswa/periode dengan pembayaran operator terpilih yang tampil dan rincian cicilannya disaring sesuai pilihan.

Ringkasan Pembayaran operator terpilih menjumlahkan komponen Daftar Ulang, bukan total transaksi campuran. Tagihan, total terbayar, sisa, dan status lunas tetap menghitung seluruh pembayaran siswa/periode yang cocok. Arsip menghitung hanya transaksi dan nominal operator terpilih. Parameter `operator[]` diteruskan ke detail awal/AJAX, pagination, tab, dan konteks kembali dari edit/pengajuan.

Verifikasi tambahan: `history_operator_pdf_test.php` dan `history_operator_pdf_browser_test.js` mencakup dua operator pada satu tagihan, saldo utuh, akun nonaktif, dan penghapusan oleh petugas lain.
