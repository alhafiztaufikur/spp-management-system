# OUT otomatis dan Master SPP, 11 November 2026

## Perilaku operasional

Menyimpan kelas **OUT** melalui Master Siswa mencatat keputusan keluar, menonaktifkan
siswa dan membatalkan kewajiban yang memenuhi syarat dalam transaksi yang sama.
Tidak ada bulan efektif, menu, atau formulir baru.

- Selama OUT, pembayaran sekolah, setoran, penarikan, koreksi dan pembatalan
  finansial ditolak. Pemeriksaan mencakup endpoint native, Legacy, otorisasi,
  formulir lama dan trigger database. Riwayat, saldo, laporan dan cetak tetap dibaca.
- SPP dan Komite dibebaskan mulai bulan berikutnya menurut Asia/Jakarta,
  termasuk tahun berikutnya yang sudah diterbitkan. DU dibatalkan hanya untuk
  tahun ajaran setelah tahun keluar. Keputusan 11 November berarti awal bebas
  1 Desember, dengan tahun keluar 2026/2027.
- Tagihan yang memiliki alokasi/kredit native atau Legacy sah dipertahankan
  seluruhnya. Sisa cicilan tetap tercatat selama OUT dan tidak dapat dibayar.
  Tunggakan sebelum batas, DU tahun keluar, PSB dan Biaya Lain tetap tersimpan.
- Simpan ulang tidak menggeser batas, menggandakan audit, atau menghapus kas.
  Arsip manual, alumni dan rekening nonakademik tanpa keputusan OUT tidak
  otomatis mendapat kebijakan ini.
- Edit resmi ke kelas reguler menutup periode OUT dan memulihkan penagihan mulai
  bulan kembali. ID tagihan milik pembatalan OUT dipakai kembali. Pembatalan
  manual, bulan OUT dan snapshot terbayar dilindungi. Tagihan baru sebelum bulan
  kembali tidak diterbitkan oleh proses pemulihan. Pemilihan kelas nonakademik
  tidak menutup keputusan OUT yang masih terbuka.
- Tarif individual tahun/periode tujuan tetap berlaku; tagihan yang dipulihkan
  dan belum dibayar mengikuti master serta potongan existing. Komite mengikuti
  Master Siswa. Master tahun tertutup dan tagihan yang memiliki kredit dilindungi.

Penerbitan SPP, Komite, DU dan rencana PSB memakai batas yang sama. Hubungan
Tabungan mengikuti ID siswa/pemilik dan unit, termasuk rekening Legacy terkait.
Nama dan NIS tanpa unit tidak dipakai untuk menggabungkan rekening.

Impor arsip resmi tetap dapat **menambahkan bukti sumber** pada konteks installer
existing `@legacy_full_apply`; ini tidak dipakai oleh kasir, koreksi atau otorisasi.
Tidak menjalankan impor, rekonsiliasi Legacy, refund atau penyesuaian saldo dalam
pembaruan ini.

## Penyederhanaan halaman

Master SPP memiliki dua tab: **Tarif SPP** dan **Pilih Siswa**. Tab/panel Status
Tahun serta formulir pembatalan manual dihapus. Sisi kanan mempertahankan
tarif per tingkat dan tombol **Tutup Tahun/Buka Kembali**. Badge pada pemilih
tahun tetap tersedia. URL lama `#spp-year-panel` membuka Pilih Siswa.
POST `batalkan_mulai_bulan` ditolak tanpa membatalkan tagihan.

Mode antislop **During**, mengikuti pilihan pengguna. Design Read: formulir
operasional sekolah dengan identitas SistemSPP existing, **ENERGY 1 / RHYTHM 1 /
MOTION 1**. Pengurangan panel memberi ruang untuk tugas tarif dan pemilihan siswa.
Font, motif, radius, palet unit dan navigasi existing dipertahankan. Tinta tab
dibuat netral agar terbaca; garis aktif tetap memakai aksen unit. Tombol tahun
memakai amber lebih gelap pada tema terang dan amber dengan tinta gelap pada
tema gelap. Tidak menambah ikon, media, animasi atau paragraf bantuan.

## Skema dan pemasangan

- `student_out_periods_data` dan view berunit: identitas siswa, waktu keluar,
  awal bebas, tahun keluar, waktu/bulan kembali, aktor dan asal penetapan.
  Satu periode terbuka per siswa/unit; dasar periode dan histori tertutup dijaga.
- `out_period_id` nullable/FK pada tiga tabel tagihan SPP, Komite dan DU.
  Audit existing mencatat ID tagihan yang dibatalkan/dipulihkan.
- Dua prosedur dan 54 trigger menjaga unit, periode, identitas dan transaksi
  yang berbenturan dengan keputusan OUT. Prosedur mengambil lock siswa sebelum
  keputusan kas; layanan penempatan juga memakai lock siswa.
- Bootstrap baru, readiness dan kontrak pemulihan skema mengikuti struktur ini.
  Repair umum meminta installer OUT terlebih dahulu jika modul belum tersedia.
  Kontrak indeks Biaya Lain diselaraskan dengan `ofb_occurrence` existing agar
  repair tidak memasang kembali batas satu pembelian per siswa/master.

Installer `scripts/migrate_student_out.php` hanya CLI; default **preview**:

```text
php scripts/migrate_student_out.php --database=db_spp_test3 --port=3306
php scripts/migrate_student_out.php --database=db_spp_test3 --port=3306 --mode=apply --backup-proof=PRIVATE_JSON
```

Apply memverifikasi identitas target, hash backup, snapshot dan checksum,
mengunci akses aplikasi, lalu menjalankan layanan resmi dalam transaksi per unit.
Identitas diverifikasi pada seluruh kolom, dengan perubahan `is_active` yang
memang diperlukan saja. Kas, arsip, saldo dan jurnal harus identik. GET tidak
menjalankan DDL. Data OUT lama memakai waktu pemasangan, bukan dugaan tanggal
keluar historis. Pemeriksaan SQL baca saja: `sql/verify_student_out.sql`.

Target: **db_spp_test3**, MySQL 3306,
UUID `e6e5c8d8-b81c-11f1-9be5-745d2224b37e`.
Backup lengkap 623.198.932 byte:
`ee67dd398d83a10ff4a3042a3c8854ab9541588eb44f71816ccda360799a06cc` (SHA-256).
Seluruh **66 checksum tabel dan snapshot terlindungi** cocok setelah restore
pada server 3319, UUID `dd012ef3-d7b4-11f1-b78c-745d2224b37e`, datadir berbeda,
event scheduler OFF. Backup salinan tersebut juga diuji restore ke server 3320
dengan UUID/datadir berbeda dan scheduler OFF; 67 tabel cocok.

Artefak privat, dump dan kredensial tidak dimasukkan Git:
`C:/laragon/backups/spp-management-system/out_20261111/`.
Penerapan target selesai pada 11 November 2026, pukul 03:40:06 Asia/Jakarta:

| Unit | Periode OUT lama dicatat | Tagihan dibatalkan saat pemasangan |
|---|---:|---:|
| SD | 202 | 0 |
| SMP | 189 | 0 |
| SMA | 103 | 0 |
| Total | 494 | 0 |

Installer menjalankan 64 langkah skema; tidak ada struktur tertunda. Apply ulang
menghasilkan **0 perubahan skema dan 0 perubahan data**. Seluruh baris/kolom asal
pada **66 tabel lama** tetap identik dengan snapshot sebelum pemasangan, termasuk
kas, alokasi, arsip dan Tabungan. Audit baru diperiksa terpisah dari audit lama.
Pemeriksaan terakhir diulang sesudah verifikasi login localhost.

Pemeriksaan integritas menemukan 0 periode OUT terbuka yang tidak sah dan 0
relasi pembatalan tagihan yang salah. Read-only repair check tidak menemukan
modul, indeks, identitas atau constraint hilang, pelanggaran maupun ketidakcocokan.
Tidak menjalankan repair atau rekonsiliasi pada data target.

Verifikasi localhost lulus **12 pemeriksaan**: login existing menuju dashboard,
health HTTP 200 `ok`, dua tab Master SPP, formulir pembayaran dan laporan SPP
pada ketiga unit, serta tidak ada exception browser. Port, UUID dan datadir
server utama tetap sesuai target; event scheduler utama tetap ON. Server salinan
untuk pengujian menggunakan scheduler OFF.

## Hasil pengujian

| Pemeriksaan | Hasil aktual |
|---|---|
| OUT, kembali, OUT lagi | 240 pemeriksaan: tiga unit, dua peran, NIS sama, batas bulan/tahun, ID tetap, pembatalan manual, bulan yang sebelumnya belum diterbitkan, rekening terkait dan kredit Legacy asli |
| Benturan dua koneksi | 4 pemeriksaan: kas lebih dulu disimpan; OUT lebih dulu menolak kas; kolom finansial sesuai hasil yang sah |
| Browser/HTTP | 195 pemeriksaan: dua peran, tiga unit, Semua Unit, formulir lama, pembayaran/edit, setoran/penarikan/koreksi/hapus, riwayat/struk, dua tab, keyboard, tutup/buka tahun, dua tema dan 320/390/1600 px |
| Laporan dan ekspor | 17 pemeriksaan endpoint/model, ditambah 8 pemeriksaan isi PDF/Excel yang diekstrak; nominal cocok, pembatalan bukan Lunas, kas dan saldo tidak berubah |
| SPP mendatang | 248 pemeriksaan siklus, tarif, pasangan, urutan, laporan, peran, unit dan kenaikan; rollback |
| PSB SPP | 142 pemeriksaan penerimaan, pembayaran, tahun, penempatan, laporan dan unit; rollback |
| DU mendatang | 123 pemeriksaan, termasuk rollover dan tiga unit; rollback |
| DU tercakup PSB | 66 transaksi dan 30 pemeriksaan kebijakan murni; rollback |
| SPP/Komite reguler | Penerbitan ulang, urutan, nominal, snapshot, pindahan dan lulusan lulus |
| PSB placement | Tiga unit, All Unit, batch parsial, pengiriman ulang, identitas dan pembayaran lama lulus |
| Riwayat tagihan | Integrasi baca saja, pagination, pengelompokan dan 10 pemeriksaan periode mendatang lulus |
| Tabungan | 15 pemeriksaan ketelitian nominal; guard OUT diuji melalui saldo/jurnal asli dan endpoint |
| Instalasi baru | Bootstrap multiunit, seed pembayaran langsung, kolasi Unicode dan replay lulus |
| Kontrak skema | Tidak ada constraint/index/identity/module hilang, pelanggaran atau ketidakcocokan pada salinan |

Tes DU pada arsip penuh membutuhkan batas memori CLI lebih besar dari 512 MB;
pengujian arsip memakai `-d memory_limit=1536M`. Konfigurasi memori aplikasi tidak
diubah. Fixture DU lama diperbaiki agar dapat memakai master yang sudah ada pada
salinan. Ekspektasi tes PSB lama diselaraskan dengan kebijakan existing: penempatan
PSB baru tidak otomatis membebaskan SPP. Kasus UI pergantian viewport menunggu
transisi existing selesai sebelum mengukur lebar.

Tidak mengklaim semua kemungkinan bebas bug. Hasil di atas merujuk pemeriksaan
yang benar-benar dijalankan; arsip mentah dan alur rekonsiliasi tidak diproses ulang.

## Antislop Delivery Gate: PASS

Lingkup gate adalah UI yang diubah: dua tab Master SPP, penghapusan panel/formulir
lama, tombol tahun dan penyembunyian aksi finansial OUT. Ini bukan audit ulang
semua komponen bersama aplikasi. Bukti privat: `out-browser-result.json` (195
pemeriksaan), `ui-final-result.json` (21), `ui-contrast-result.json`, screenshot
`final-unit-theme-width.png` dan `localhost-result.json` (12).

| Item | Status dan bukti |
|---|---|
| R-02 | PASS: teks kontrol yang diubah tidak memakai em dash. |
| R-03 | PASS: tidak ada overflow pada tiga unit, tema terang/gelap, 320/390/1440 px; 18 kombinasi layout akhir. |
| R-17 | PASS: tidak menambah statistik; angka ringkasan existing tetap berasal dari database. |
| R-18 | PASS: tidak ada testimonial atau identitas rekaan pada UI yang diubah. |
| R-23 | PASS: struktur dua tab dan penghapusan panel sesuai permintaan; tidak membuat aset visual baru. |
| R-24 | PASS: kedua tab mempunyai panel; hash lama membuka Pilih Siswa pada tiga unit. |
| R-25 | PASS: kontras teks kontrol yang diubah 5,91–15,76:1 pada terang dan 7,59–14,09:1 pada gelap. |
| R-26 | PASS: tab berpindah panel; tombol tahun benar-benar menutup/membuka; POST pembatalan lama ditolak tanpa perubahan data. |
| R-27 | PASS: keadaan kosong hasil pencarian, tombol nonaktif saat belum memilih, status tahun tertutup, serta feedback POST existing dipertahankan; pergantian tab lokal tidak memuat data asinkron baru. |
| R-28 | PASS: tidak menambah FAQ. |
| R-32 | PASS: ArrowRight/Home/End berpindah tab, fokus terlihat; tiga pemeriksaan keyboard akhir lulus. |
| R-33 | PASS: perubahan UI ditulis langsung pada PHP, CSS dan JavaScript sumber; tidak memakai skrip penulis ulang UI. |
| R-34 | PASS: dua tema lulus matriks layout dan pengukuran kontras kontrol yang diubah. |
| R-35 | PASS: 195 pemeriksaan browser/HTTP mencatat tindakan dan hasil; 12 pemeriksaan localhost target lulus tanpa exception browser. |
| R-36 | PASS: hanya melaporkan angka pemeriksaan yang dijalankan; tidak menyatakan bebas bug atau klaim performa. |
| R-37 | PASS: Design Read dan dials During 1/1/1 dicatat pada bagian penyederhanaan. |
| R-38 | PASS: tidak menambah konten rekaan; fixture pengujian hanya berada pada salinan terisolasi. |
| R-39 | PASS: tab memakai role/aria-selected/tabindex existing, tombol tahun native bernama, fokus tidak dihapus; tidak menambah gerakan atau field. |
| R-40 | PASS: tidak menambah media, font, skrip pihak ketiga atau konten terlambat; tidak melaporkan metrik performa yang tidak diukur. |
| R-01 | PASS: aksen unit existing menandai tab aktif; amber menandai tindakan tahun, tanpa gradien/glow baru. |
| R-04 | PASS: ikon existing tetap menunjukkan tarif, siswa dan kunci tahun; tidak menambah ikon dekoratif. |
| R-06 | PASS: font SistemSPP dipertahankan agar konsisten dengan formulir administrasi lain. |
| R-07 | PASS: tidak menambah grid atau pola latar dekoratif. |
| R-08 | PASS: panah existing hanya mengarahkan pengguna ke pengaturan tarif. |
| R-09 | PASS: badge tahun existing menyatakan status database; kartu status berulang dihapus. |
| R-10 | PASS: tidak menambah permukaan glass atau blur. |
| R-12 | PASS: tombol tahun tidak lagi dibungkus kartu penjelas; tidak menambah shadow. |
| R-13 | PASS: tidak menambah glow. |
| R-14 | PASS: kedua tab mengelompokkan tugas tarif dan siswa; tidak menambah kisi kartu seragam. |
| R-19 | PASS: tidak menambah animasi; perpindahan tab dan state kontrol mengikuti MOTION 1. |
| R-22 | PASS: tidak menambah ilustrasi. |
| Dials | PASS: ENERGY 1 / RHYTHM 1 / MOTION 1 tertulis dan sesuai formulir operasional yang tenang. |
| Focal point | PASS: isi tab tarif atau daftar siswa menjadi fokus; panel penjelasan yang bersaing dihapus. |
| Whitespace | PASS: grid dua tab dan aside tombol memisahkan tugas, tanpa kartu kosong bekas panel. |
| Accent | PASS: aksen unit menandai tab aktif, amber khusus tindakan tahun. |
| Identity motif | PASS: navigasi unit, garis tab aktif, font dan bentuk formulir SistemSPP dipertahankan. |
| Design Read | PASS: arah During mengacu permintaan pengguna untuk mempertahankan konsep dan menyederhanakan tampilan. |
| C-1 | PASS: setiap perubahan melayani pengurangan langkah, keterbacaan tab atau perlindungan transaksi OUT. |
| C-2 | PASS: kontrol yang tersisa bekerja; alur pembatalan manual lama dihapus dan POST-nya ditolak. |
| C-3 | PASS: hanya menyisakan tugas tarif, pemilihan siswa dan tindakan tahun. |
| C-4 | PASS: matriks tiga unit/dua tema/tiga lebar, keyboard dan formulir kedaluwarsa lulus. |
| C-5 | PASS: hasil pemeriksaan dan snapshot tercatat, tanpa data atau klaim UI rekaan. |
| R-05 | PASS: komposisi mengikuti formulir administrasi existing, bukan template halaman pemasaran. |
| R-11 | PASS: radius kontrol existing dipertahankan; tidak menyamakan semua elemen menjadi pil. |
| R-15 | PASS: label tindakan spesifik Tarif SPP, Pilih Siswa, Tutup Tahun dan Buka Kembali. |
| R-16 | PASS: tidak menambah jargon pemasaran. |
| R-20 | PASS: pemilih unit/tahun, tarif tingkat dan identitas siswa tetap membentuk konteks pembayaran sekolah. |
| R-21 | PASS: pemilih tema existing tetap bekerja; tidak memaksakan tema gelap. |
| R-29 | PASS: warna unit dan netral existing dipertahankan, amber dibatasi pada tindakan tahun. |
| R-30 | PASS: perubahan meneruskan desain SistemSPP, tanpa meniru produk lain. |
| R-31 | PASS: tab dipersempit sesuai dua tugas; tinta digelapkan untuk kontras; panel duplikat dihapus untuk mengurangi kebingungan. |
