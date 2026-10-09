# Proteksi Tarif SPP dan Susun Pesan — 10 Oktober 2026

> Bagian proteksi tarif di bawah mencatat implementasi awal. Revisi berikutnya menyediakan Edit Tarif terbit dengan konfirmasi untuk tagihan belum dibayar serta menghapus Advanced Data Siswa. Lihat [aturan dan verifikasi terbaru](STUDENT_TARIFF_CORRECTION_20261010.md). Perbaikan Susun Pesan tetap berlaku.

## Aturan SPP

Tarif dasar hanya dapat disimpan pada master draft yang belum mempunyai waktu penerbitan maupun tagihan. Penerbitan pertama yang berhasil mengunci seluruh tarif dasar dalam unit/tahun tersebut. Penerbitan yang tidak menghasilkan tagihan tidak mengunci draft. Pembatalan tagihan serta penutupan/pembukaan kembali tahun tidak membuka pengeditan tarif.

Formulir memakai aturan yang sama dengan backend. Penyimpanan tarif dan penerbitan mengunci baris master yang sama dalam transaksi. Kiriman dari formulir lama ditolak sebelum perubahan tarif, tagihan, siswa, penempatan, atau audit. Tidak ada migrasi, penulisan ulang tarif historis, maupun rekonsiliasi data lama.

Potongan khusus Data Siswa tetap dapat diedit. Tagihan yang belum menerima pembayaran memakai potongan terbaru dan tarif dasar snapshot terbit. Bulan yang sudah menerima pembayaran tetap utuh; batas unit, tahun/penempatan, PSB, dan tahun tertutup tetap mengikuti aturan sebelumnya. Contoh teruji: Juli–Agustus Rp250.000 telah dibayar, potongan baru Rp30.000 membuat September dan bulan terbuka berikutnya Rp220.000.

## Pesan Surat

UI penerapan pesan mengirim `overwrite: true`; seluruh target terpilih menerima pesan/format sumber, termasuk target yang sudah mempunyai pesan. Pilih Semua mencakup target yang tersembunyi oleh pencarian. Penerapan sebagian tidak mengubah penerima di luar pilihan. Salinan tetap dapat diedit secara mandiri.

Sumber disimpan terlebih dahulu melalui antrean autosave; interaksi editor dikunci selama penerapan dan state diperbarui dari respons server. Kegagalan dapat dicoba kembali tanpa kehilangan isi. Endpoint dan bentuk respons tetap; perilaku API `overwrite: false` tetap tersedia untuk pemanggilan lama.

Halaman Susun Pesan menghapus tips dan penjelasan yang berulang, termasuk penempatan paragraf dan penyimpanan per siswa. Identitas, pencarian, tanggal tunggakan, editor/format, batas karakter, status simpan, dan tindakan tetap tersedia. Pratinjau dan renderer PDF tidak diubah.

## Verifikasi

Seluruh mutasi pengujian menggunakan `db_spp_audit_lock_letters_20261010`, dipulihkan dari dump baru database lokal. Artefak privat disimpan di `C:\laragon\backups\spp-management-system\spp_lock_letters_20261010`, di luar web dan Git.

- `spp_published_rate_lock_test.php`: tiga unit, perubahan draft, penerbitan kosong/berhasil, kiriman lama termasuk nominal sama, penerbitan tambahan, close/reopen, tagihan batal, bukti timestamp/status, dan tahun terpisah. Fixture memakai rollback.
- `spp_rate_lock_http_test.php`: controller nyata tiga unit; kolom draft/terbit, POST lama, close/reopen, penerbitan tambahan, CSRF, dan penolakan mutasi Semua Unit. Fixture hanya tinggal pada clone yang dapat dibuang.
- `spp_billing_integration_test.php`: penolakan tarif terbit, dua bulan berbayar tetap Rp250.000, bulan belum bayar Rp220.000 setelah potongan, urutan tunggakan, nominal tepat, dan penerbitan idempoten.
- `nominal_student_http_test.php`: tiga unit melalui Data Siswa dan kasir; identitas/Diknas opsional, penolakan tarif terbit, potongan penuh/pengurangan potongan, serta snapshot bulan berbayar.
- `historical_tariff_edit_test.php`: potongan SPP pada penempatan terbaru tidak mengubah tagihan tahun asal.
- `parent_letter_apply_browser_test.js`: empat cakupan, pesan lama berbeda diganti, format, salinan sebagian/mandiri, reload, retry, respons autosave tertunda, validasi/CSRF, 16 kombinasi desktop/ponsel dan terang/gelap, serta akses PDF.
- `parent_letter_rich_draft_test.php`: normalisasi/Unicode, kompatibilitas API lama, penggantian seluruh target, pesan dalam HTML surat tiap siswa, target asing/duplikasi, masa berlaku/unit/pemilik draf, escaping, dan PDF panjang.

Pengujian browser memeriksa akses dan signature PDF; tidak menguji printer fisik. Tidak ada commit/push atau perubahan skema/database operasional. Empat perubahan lokal sebelumnya tetap dipertahankan.

Hash isi seluruh tabel fisik dan metrik `db_spp` identik sebelum/sesudah: 1.359 siswa, 1.020 pembayaran/Rp577.790.000, 12 rekening tabungan/Rp1.050.000. Health lokal `ok`; seluruh 30 pemeriksaan integritas pada clone tetap nol setelah tes. Pemeriksaan sintaks sembilan PHP/empat JavaScript dan `git diff --check` lulus. Server, database clone, dan password pengujian dibersihkan; dump, fingerprint, dan screenshot tetap disimpan privat di direktori artefak.
