# Perapihan UI Master Daftar Ulang, 11 November 2026

## Koreksi tampilan sesuai permintaan pengguna

Deskripsi statis pada hero, judul bagian, kartu PSB, dan footer dihapus.
Banner tunggakan dan konflik DU–PSB juga dihapus dari Master DU. Kartu kelas awal
cukup menampilkan **Tercakup PSB**, tanpa hitungan peninjauan atau penjelasan
cicilan. Pesan perubahan belum disimpan dan hasil aksi tetap muncul sesuai keadaan.
Penghapusan ini hanya menyederhanakan presentasi; aturan dan data konflik tetap
dipertahankan pada layanan existing. Pembacaan tunggakan yang hanya dipakai banner
dihapus karena tidak lagi diperlukan oleh halaman.

**68 pemeriksaan koreksi UI lulus**: teks/panel yang diminta tidak ada,
18 kombinasi tiga unit × dua tema × lebar 320/390/1600 px tidak meluap,
jumlah field, nilai tersembunyi PSB, aksi simpan, penanda perubahan dan tahun depan
tetap bekerja. PHP lint, parser CSS, detector selector dan whitespace lulus.
Tidak ada kiriman finansial pada pengujian ini. Artefak: `clean-result.json`
dan `clean-*.png` di direktori privat yang sama dengan pengujian awal.

## Arah dan cakupan

Mode antislop: **During**, dipilih pengguna dalam chat. Arah berasal dari gambar
dan instruksi pengguna untuk mempertahankan konsep halaman existing.
Design Read: formulir operasional kasir/admin sekolah, memakai identitas SistemSPP
dan palet unit existing; **ENERGY 1 / RHYTHM 1 / MOTION 1**.

- Warna unit dan bidang tahun ajaran dipertahankan agar halaman tetap dikenali;
  amber pada badge menandai tarif yang belum ditetapkan.
- Kartu per kelas tetap dipakai untuk membandingkan tarif pada posisi yang sama;
  kartu kelas awal tetap berbeda karena mengikuti kebijakan PSB.
- Font aplikasi existing tetap dipakai agar konsisten dengan halaman pembayaran.
  Hierarki lokal: tahun 28–34 px, judul bagian 20 px, kelas 17 px,
  nominal input 18 px, total kartu 15 px, label/keterangan 12–14 px.
- Jarak 6–8 px mengikat label dengan isinya, 16–20 px memisahkan kelompok;
  hero dipadatkan agar formulir lebih cepat terlihat.
- Total kartu memakai tinta netral agar tidak bersaing dengan tombol simpan.
  Angka memakai tabular numerals untuk memudahkan pembacaan nominal.
- Label sumber tarif menjelaskan admin/Legacy; kelas awal cukup menyebut cakupan
  PSB. Deskripsi kebijakan dan peninjauan tidak ditampilkan pada formulir tarif.
- Tidak membuat gambar, ikon, font, navigasi, atau menu baru. Ikon existing
  tetap menunjukkan kalender, simpan, penguncian tahun, serta cakupan PSB.
- Tidak menambahkan animasi; transisi masuk kartu ditiadakan di halaman ini.
  Preferensi reduced motion dihormati.

Perubahan dibatasi pada template Master DU, stylesheet halaman, dan skrip UI
Master Workspace. Layanan penerbitan, perhitungan nominal, otorisasi,
struktur database, dan proses POST server tidak diubah. ID/nama field serta
nilai tersembunyi kelas awal tetap sama. Label tahun mengaktifkan picker existing;
tanpa JavaScript label tetap terhubung ke select native.

Simpan menampilkan status proses dan menahan submit berulang pada halaman yang
sama. Saat kembali melalui riwayat browser, status tombol dikembalikan sesuai
keadaan awal, termasuk pembatasan Semua Unit.

Semua Unit tetap memakai halaman ringkasan existing tanpa editor tarif;
guard server tetap menolak penulisan sebelum menangani aksi.

## Verifikasi

Artefak privat: `C:/laragon/backups/spp-management-system/du_ui_20261111/`.
Pemeriksaan dilakukan pada localhost dengan akun Super Admin existing.

Hasil versi awal: **164 pemeriksaan UI/browser/CSS lulus**, termasuk 30 kombinasi
visual. Pengujian tambahan memeriksa cakupan Semua Unit dan pemulihan halaman.
Sebanyak **8 pemeriksaan tambahan** tersebut lulus, termasuk penolakan HTTP 409
pada Semua Unit dan simulasi event `pageshow` untuk memulihkan status tombol.
Artefak utama: `full-result.json`, `scope-result.json`, dan screenshot `after-*`.

- Matriks 30 kombinasi: SD/SMP/SMA × terang/gelap × 320/390/768/1280/1600 px.
  Lebar dokumen, kartu, catatan dan footer tidak meluap; ukuran tarif tetap 18 px.
  Pengukuran kontras teks memeriksa latar solid serta kedua ujung gradien hero.
- Klik pilihan tahun mengubah tahun tujuan; Escape menutup picker.
  Label tahun mengaktifkan picker yang terlihat, bukan select tersembunyi.
- Input tarif memformat rupiah saat blur; penanda perubahan muncul/hilang
  sesuai perubahan nominal. Tab berpindah ke input berikutnya dengan fokus jelas.
- Tombol tutup tahun membuka konfirmasi; batal mempertahankan tahun terbuka.
  Pembukaan kembali tahun tetap memakai handler server existing yang tidak diedit;
  tindakan mengubah status tahun tidak dijalankan pada database operasional.
- Tahun berikutnya tetap menyediakan input tarif; tahun di luar jangkauan tetap
  mengunci input/tombol penerbitan. Penjelasan statis tagihan mendatang telah dihapus.
- Pada versi awal tautan konflik membuka Riwayat Daftar Ulang; banner beserta
  tautannya kini dihapus sesuai permintaan pengguna. Tombol tema benar-benar mengubah
  terang/gelap; navigasi ponsel dibuka dan ditutup melalui kontrol existing.
- Uji submit mengganti token CSRF menjadi token tidak sah sebelum request
  diteruskan. Server menolak sebelum transaksi database dimulai. Status sibuk,
  penahanan submit berulang, payload field, pemulihan input/tombol dan modal
  kesalahan diperiksa. Escape menutup modal dan memulihkan fokus.
- Form native tetap tersedia tanpa JavaScript. Pengujian ini tidak menerbitkan
  tagihan, mengganti tarif, atau membuka/menutup tahun pada database operasional.
  Tidak mengklaim pengujian ulang transaksi finansial sukses pada perubahan UI ini.
- PHP lint, JavaScript syntax check, parser CSS dan detector selector untuk
  stylesheet halaman beserta stylesheet bersama terkait dijalankan.
- Regresi murni existing: 30 pemeriksaan kebijakan DU–PSB dan 45 perencana DU lulus.
  Tidak menjalankan migrasi atau rekonsiliasi Legacy.

## Delivery Gate antislop

Laporan ini berlaku pada komponen Master DU yang diubah; sidebar, modal global,
dan halaman tujuan tetap memakai komponen existing. Performa lapangan, Lighthouse,
dan Core Web Vitals tidak diukur dan tidak diklaim.

| Item | Hasil dan bukti |
|---|---|
| R-02 | PASS: teks UI yang diedit tidak memakai em dash. |
| R-03 | PASS: 30 kombinasi viewport/unit/tema tidak meluap secara horizontal. |
| R-17 | PASS: angka siswa dan nominal tetap berasal dari read model existing. |
| R-18 | PASS: tidak ada testimoni atau identitas rekaan. |
| R-23 | PASS: konsep mengikuti arahan pengguna; tidak ada aset/navigasi baru. |
| R-24 | PASS: tidak menambah tautan; navigasi existing tetap tersedia melalui menu. |
| R-25 | PASS: kontras teks diukur pada 30 kombinasi, termasuk ujung gradien. |
| R-26 | PASS: picker, input, simpan, konfirmasi, tautan dan toggle diuji nyata. |
| R-27 | PASS: badge tarif kosong, keadaan siswa kosong, simpan sibuk dan error tersedia. |
| R-28 | PASS: tidak membuat FAQ. |
| R-32 | PASS: Tab input, fokus terlihat, Escape picker/modal dan pemulihan fokus diuji. |
| R-33 | PASS: implementasi langsung melalui patch source; tidak ada skrip penulis CSS. |
| R-34 | PASS: seluruh matriks mencakup kedua tema dan tiga palet unit. |
| R-35 | PASS: localhost dijalankan; daftar klik dan batas pengujian tercatat di atas. |
| R-36 | PASS: tidak membuat klaim performa, keamanan, atau statistik tanpa bukti. |
| R-37 | PASS: arahan gambar/konsep pengguna dan Design Read dicatat sebelum implementasi. |
| R-38 | PASS: memakai data aplikasi existing; tidak menambahkan isi fiktif. |
| R-39 | PASS: field berlabel, kontrol native/komponen existing, cursor/fokus/disabled jelas. |
| R-40 | PASS: tidak menambah media, font, skrip pihak ketiga, atau klaim skor performa. |
| R-01 | PASS: gradien tahun mempertahankan identitas existing; amber menandai tarif kosong. |
| R-04 | PASS: ikon existing dipakai sesuai kalender/simpan/penguncian/cakupan. |
| R-06 | PASS: font existing dengan skala lokal untuk membedakan tarif dan keterangan. |
| R-07 | PASS: tidak menambahkan pola latar; orbs halaman disembunyikan. |
| R-08 | PASS: tidak menambahkan panah dekoratif. |
| R-09 | PASS: badge hanya menunjukkan status tahun, sumber tarif, atau kebijakan PSB. |
| R-10 | PASS: tidak menambahkan glassmorphism. |
| R-12 | PASS: kartu dan hero tanpa bayangan tambahan. |
| R-13 | PASS: tidak menambahkan glow dekoratif; fokus menggunakan outline. |
| R-14 | PASS: kartu kelas sebanding; kartu PSB berbeda karena jenis kewajibannya berbeda. |
| R-19 | PASS: tidak ada animasi baru; reduced motion memiliki aturan lokal. |
| R-22 | PASS: tidak membuat ilustrasi. |
| Dials | PASS: ENERGY 1 / RHYTHM 1 / MOTION 1 sesuai formulir operasional. |
| Focal point | PASS: nominal input menonjol terhadap label dan sumber tarif. |
| Whitespace | PASS: kelompok kartu, keterangan, dan aksi dipisahkan secara konsisten. |
| Accent | PASS: aksi simpan memakai aksen unit; nominal kartu memakai tinta netral. |
| Identity motif | PASS: palet unit, kartu kelas, dan perlakuan PSB mempertahankan SistemSPP. |
| Design Read | PASS: sumber arahan dan pembacaan desain dicatat di awal dokumen. |
| C-1 | PASS: alasan warna, tipografi, kartu, jarak dan ikon tercatat di atas. |
| C-2 | PASS: tidak ada kontrol baru tanpa perilaku. |
| C-3 | PASS: seluruh bagian melayani pengaturan tarif, pemeriksaan tagihan, atau aksi simpan. |
| C-4 | PASS: tema, ukuran layar, input kosong/terkunci, error dan keyboard diperiksa. |
| C-5 | PASS: nilai ditampilkan dari data existing dan hasil tes tidak menyatakan transaksi sukses. |
| R-05 | PASS: struktur mengikuti tugas tarif per kelas, dengan ritme grid yang disengaja. |
| R-11 | PASS: radius 6–13 px membedakan badge, input, kartu dan hero. |
| R-15 | PASS: nama aksi simpan menyebut tarif dan pelengkapan tagihan. |
| R-16 | PASS: teks instruksi spesifik pada operasional sekolah. |
| R-20 | PASS: konsep SistemSPP dipertahankan sesuai permintaan, tanpa desain pengganti. |
| R-21 | PASS: toggle existing bekerja; kedua tema diuji. |
| R-29 | PASS: palet unit + netral; amber dipakai untuk peringatan yang nyata. |
| R-30 | PASS: tidak meniru produk lain. |
| R-31 | PASS: alasan setiap keputusan utama dicatat pada bagian arah. |

Checklist tambahan antislop-ui: PASS untuk palet, aksen, komposisi formulir,
badge faktual, navigasi nyata, motion, angka bersumber, placeholder jujur,
keadaan kosong/sibuk/error, keyboard, tema, dan tidak adanya media/aset baru.
