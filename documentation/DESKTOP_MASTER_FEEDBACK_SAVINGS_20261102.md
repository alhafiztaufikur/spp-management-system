# Perapian master, popup hasil, dan tabungan desktop — 2 November 2026

## Perubahan

- Ilustrasi kalender Master Daftar Ulang dihapus; header memakai ruang penuh tanpa kolom ilustrasi.
- Ilustrasi Master Biaya Lain dan kartu Siswa Aktif dihapus. Biaya Aktif, Rombel Aktif, formulir, daftar master, dan penerbitan tetap tersedia.
- Daftar siswa masih diperlukan formulir penerbitan Biaya Tidak Berulang. Pembacaannya dipertahankan dengan empat kolom yang digunakan dan tanpa join kelas tambahan. Sasaran penerbitan tidak dikurangi demi menghapus statistik.
- Komponen hasil bersama menyediakan `showOperationResult({type, title, message, source})`. Pesan server/hasil AJAX tampil dalam popup hijau, merah, atau kuning dengan tombol Mengerti. Escape menutup popup, Tab tetap berada di dalam popup, dan fokus dikembalikan ke kontrol terkait.
- Flash existing pada master, siswa, role management, pembayaran, tabungan, dan otorisasi ditampilkan sekali. Hasil inline Legacy serta penyimpanan AJAX tarif SPP/pengaturan backup dihubungkan melalui adapter tampilan. Popup validasi/konfirmasi khusus tetap bekerja; autosave dan pembacaan biasa tidak menghasilkan popup baru.
- Pekerjaan bertahap memberi hasil setelah transisi selesai/gagal yang diamati, dengan deduplikasi. Pengakuan antrean dan polling biasa tetap berupa status progres.
- Setoran/penarikan siswa maupun pemilik non-siswa kembali ke formulir rekening yang sama. Pencarian, jenis pemilik, kelas, halaman daftar, dan tanggal dipertahankan. Saldo dibaca ulang; nominal/keterangan dibersihkan dan kunci permintaan berikutnya dibuat baru setelah berhasil. Detail dan buku rekening tetap tersedia lewat tombol existing.
- Nominal penarikan yang melebihi saldo membuat kolom, bingkai fokus, kartu saldo, dan keterangan menjadi merah. Nilai dibandingkan dalam sen; nominal tepat sebesar saldo diperbolehkan. Pemeriksaan saldo dan transaksi database tetap menggunakan engine existing.

Perubahan pekerjaan sebelumnya tetap dipertahankan. Tidak ada perubahan skema, format respons API, aturan tarif, otorisasi, audit, atau mesin transaksi keuangan.

## Verifikasi

Dump konsisten dipulihkan pada MySQL terpisah dengan UUID, port, dan direktori data berbeda serta event scheduler OFF. Checksum extended seluruh 65 tabel cocok setelah restore. Setelah pengujian, checksum seluruh 65 tabel database operasional tetap sama.

- `desktop_operation_feedback_browser_test.js`: lulus. CRUD/status/no-op Master Biaya Lain serta pembuatan/penolakan akun dijalankan melalui controller sebenarnya pada salinan. Diperiksa juga fallback tanpa JavaScript, deduplikasi, teks aman, pesan panjang, Escape, Tab, dan pengembalian fokus.
- `desktop_savings_entry_browser_test.js`: lulus untuk siswa dan pemilik non-siswa pada SD, SMP, SMA. Mencakup transaksi aktual, saldo/jumlah jurnal, rekening/filter/tanggal, reset setelah sukses, refresh, replay, nilai di bawah/tepat/melebihi saldo, pecahan sen, POST berlebih yang dipaksakan, perubahan saldo dari tab lain, dan saldo nol.
- `desktop_async_feedback_browser_test.js`: lulus. Respons pengaturan dan pekerjaan backup dimock untuk menguji adapter UI: tidak ada sukses sebelum respons, antrean berbeda dari penyelesaian, serta sukses/gagal terminal tidak berulang pada polling. Tidak menjalankan backup atau restore sebenarnya.
- `other_fee_master_usage_transactions_test.php`: 22 pemeriksaan lulus; fixture di-rollback. Referensi native/Legacy dan perlindungan penghapusan tetap diperiksa.
- `savings_precision_test.php`: 15 pemeriksaan presisi lulus. `payment_form_feedback_test.php` juga lulus.
- Desktop diperiksa pada 1280, 1440, dan 1920 piksel, tema terang/gelap. Warna nominal, border/fokus, dan saldo diperiksa dari CSS hasil render, bukan hanya keberadaan class.
- Perbandingan master sebelum/sesudah membuktikan baris, nominal, hitungan transaksi, dan kartu tarif tetap sama. Checksum seluruh 65 tabel pada salinan tidak berubah oleh GET master.
- ID, nama, identitas siswa, dan kelas pada roster penerbitan juga dibandingkan dengan versi sebelumnya dan identik pada ketiga unit. Pemeriksaan sintaks 19 berkas PHP, 15 berkas JavaScript, serta `git diff --check` lulus.
- Pengujian browser existing untuk tarif SPP dan pengaturan backup disesuaikan agar mengakui popup hasil baru. Suite fixture khusus tersebut tidak dijalankan pada salinan ini; pemeriksaan adapter AJAX di atas menggunakan respons terkendali.

## Pengukuran Master Biaya Lain

Median lima permintaan PHP per varian, dijalankan sebelum/sesudah bergantian pada salinan yang sama. Angka merupakan waktu pemrosesan server, tanpa pemuatan seluruh aset browser.

| Unit | Sebelum (ms) | Sesudah (ms) | Kueri sebelum → sesudah |
| --- | ---: | ---: | ---: |
| SD | 4.664 | 4.540 | 108 → 108 |
| SMP | 4.858 | 5.082 | 112 → 112 |
| SMA | 912 | 897 | 84 → 84 |

Penghapusan tampilan tidak memberi percepatan server yang konsisten pada pengukuran ini. Pembacaan sasaran penerbitan dan pemakaian master tetap dibutuhkan; jumlah kueri tetap sama. Perubahan tidak menghapus pembacaan transaksi/audit yang melindungi master dari penghapusan keliru.

Dump, bukti restore, metrik mentah, pembandingan, dan tangkapan layar disimpan privat di `C:/laragon/backups/spp-management-system/master_feedback_20261102/`.
