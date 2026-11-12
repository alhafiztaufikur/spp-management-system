# Bentuk tombol dan label desktop

Seluruh tombol dan label informasi yang sebelumnya berupa pil/oval memakai
empat sudut 8 px pada layar mulai 1024 px. Aturan bersama berada di
`assets/css/desktop_actions.css` dalam cascade layer agar aturan lokal tidak
mengubahnya saat hover, fokus, aktif atau disabled.

Cakupan meliputi tombol berbagai elemen HTML, navigasi halaman aktif/nonaktif,
pilihan unit, kelompok tombol ekspor, NIS, kelas, unit, status, peran, jumlah data,
jam, dan nomor langkah. Label tetap mengikuti semantik dan perilaku sebelumnya.
Avatar, logo, ikon dekoratif, titik status, radio/checkbox, bagian mekanis switch,
kartu dan tabel tidak diubah.

Pratinjau Excel mandiri memuat aturan yang sama. Dokumen HTML di dalam iframe
mengikuti lebar halaman induk melalui pengaturan media khusus layer tersebut;
ini menangani desktop 1280 px yang area dokumennya lebih sempit dari 1024 px.
Pengaturan itu hanya untuk layar. Cetakan/PDF, workbook, data dan perhitungan
tidak diubah. Versi impor CSS diperbarui agar cache browser tidak memakai aturan
sebelumnya.

## Pemeriksaan

- `tests/desktop_component_shapes_browser_test.js`: inventaris halaman nyata,
  1280/1440/1920 px, terang/gelap, empat unit, kontrol nonaktif, dialog, dropdown,
  pagination hover/fokus serta perilaku perpindahan halaman. Memerlukan server
  salinan terverifikasi dan sesi/akun pengujian Super Admin untuk halaman akun
  serta otorisasi; tidak menyediakan endpoint untuk membuat sesi.
- `tests/desktop_component_shapes_surfaces_test.js`: 48 perbandingan dokumen
  independen memakai stylesheet aplikasi, termasuk batas 1023/1024 px dan cetak.
  Membandingkan warna, ukuran, font, semantik dan komponen yang dikecualikan;
  memeriksa label panjang serta perilaku tombol.
- `tests/desktop_component_shapes_preview_test.php` dan pasangannya
  `desktop_component_shapes_preview_browser_test.js`: renderer pratinjau biasa,
  global, serta shell/iframe pada tiga lebar desktop; mobile dan cetak tetap
  memakai bentuk sebelumnya. Fixture dibuat di direktori backup privat.

Pengujian menggunakan server MySQL salinan dengan UUID, port dan direktori data
berbeda dari sumber. Kelompok dashboard, master, siswa, pembayaran, tabungan,
laporan, akun, otorisasi, Legacy dan backup/restore diperiksa. Tahap lanjutan
mencatat 143 inventaris komponen tanpa radius yang menyimpang; pemeriksaan
halaman lainnya dan regresi pemformatan laporan juga lulus. Tidak ada perubahan
API, skema, migrasi, atau penulisan data keuangan dari penyesuaian ini.
