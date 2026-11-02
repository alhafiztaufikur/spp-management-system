# Penyederhanaan Master DU dan surat orang tua — 2 November 2026

## Perubahan

- Master DU tidak lagi memuat empat kartu statistik, diagram kelengkapan, atau keterangan kelengkapan agregat pada footer. Formulir tarif, cakupan PSB, konflik, jumlah siswa per kelas, total tagihan, dan proses penerbitan existing tetap tersedia.
- Jumlah/nominal tagihan dibaca melalui agregasi per kelas. Penghitungan pembayaran dan kredit yang tidak dipakai halaman dihapus. Perencana kewajiban Legacy hanya diperlukan untuk kelas bertarif yang belum memiliki tagihan; kelas tanpa tarif tetap menampilkan `-`.
- Filter Cari Siswa, Kelas/Rombel, dan Status Siswa memiliki lebar setara pada desktop dan tinggi 48 px. Petunjuk panjang dan tombol Cetak Kelas/Rombel dihapus; URL mode kelas existing tetap didukung.
- Penyusunan surat memakai dua kolom tanpa Ringkasan Penerima. Kembali ke daftar berada di atas kartu, sedangkan Pratinjau Surat dan status penyimpanan berada di bawah editor. Tombol Simpan Draf dihapus; autosave tetap bekerja.
- Satu penerima tidak menampilkan bagian berbagi pesan maupun modalnya. Untuk beberapa penerima, Terapkan Pesan & Pratinjau menyimpan pesan terbaru, mengganti pesan target terpilih, lalu langsung menuju pratinjau seluruh penerima. Kegagalan mempertahankan teks dan menyediakan percobaan ulang.
- Teks status tahun DU historis dapat membungkus pada layar kecil.

## Verifikasi

Pengujian memakai dump konsisten yang dipulihkan pada server MySQL tersendiri dengan UUID, port, dan direktori data berbeda; event scheduler OFF. Checksum extended seluruh 65 tabel cocok setelah restore. Checksum 65 tabel sumber operasional juga tetap sama setelah pengujian.

- `tests/parent_letter_apply_browser_test.js`: lulus untuk SD, SMP, SMA, serta semua unit. Mencakup ukuran filter, satu/beberapa penerima, penerapan sebagian/semua target, pesan mandiri, rich text, reload, autosave tertunda, kegagalan simpan/penerapan dan retry, pesan kosong, batas karakter, akses endpoint, serta pratinjau/PDF.
- `tests/parent_letter_rich_draft_test.php`: lulus; sanitasi, Unicode, penerapan atomik, akses unit/pemilik, kedaluwarsa, dan PDF panjang.
- `tests/du_psb_test.php`: 30 pemeriksaan kebijakan lulus.
- `tests/du_psb_transactions_test.php`: 66 pemeriksaan transaksi lulus; fixture di-rollback.
- `tests/du_advance_test.php`: 123 pemeriksaan lulus pada tiga unit, termasuk pergantian tahun, tagihan mendatang, kredit, pembatalan, dan replay; fixture di-rollback.
- Tampilan editor diperiksa pada desktop/ponsel, tema terang/gelap, tanpa kesalahan JavaScript. Modal diperiksa pada lebar 1440, 768, dan 390 px, termasuk pembatalan serta pengembalian fokus.
- Pembandingan Master DU sebelum/sesudah memastikan teks kartu kelas, nilai/status input tarif, dan total tagihan identik untuk ketiga unit, tahun mendatang, historis, dan tertutup. Pemeriksaan checksum membuktikan GET tidak mengubah seluruh 65 tabel; tutup/buka tahun diuji melalui formulir resmi pada salinan.
- Pemeriksaan sintaks PHP/JavaScript dan `git diff --check` lulus. Dua pengujian gabungan akun/surat existing juga disesuaikan agar mengikuti navigasi baru; cakupan akun di kedua pengujian tersebut tidak dijalankan pada salinan ini.

## Pengukuran Master DU

Median lima permintaan PHP per varian, sebelum/sesudah bergantian pada server dan data salinan yang sama. Waktu ini merupakan pemrosesan server, bukan durasi pemuatan seluruh aset di browser.

| Kasus | Sebelum (ms) | Sesudah (ms) | Kueri sebelum → sesudah |
| --- | ---: | ---: | ---: |
| SD 2026/2027 | 1.735 | 1.509 | 148 → 130 |
| SMP 2026/2027 | 1.617 | 1.645 | 142 → 124 |
| SMA 2026/2027 | 399 | 357 | 142 → 124 |
| SD mendatang 2027/2028 | 102 | 107 | 65 → 64 |
| SD historis 2025/2026 | 1.531 | 1.559 | 125 → 124 |
| SD 2026/2027 tertutup | 1.935 | 1.560 | 142 → 124 |

Kueri tahun berjalan berkurang 18 per permintaan. Waktu SD membaik sekitar 13% dan SMA sekitar 10%; pengukuran SMP, tahun mendatang, dan historis tidak menunjukkan percepatan. Perhitungan yang masih diperlukan kartu tarif dan peringatan tetap dijalankan.

Dump, bukti restore, metrik mentah, pembandingan, dan tangkapan layar disimpan privat di `C:/laragon/backups/spp-management-system/du_letters_20261102/`. Tidak ada perubahan skema database atau penerapan migrasi data.
