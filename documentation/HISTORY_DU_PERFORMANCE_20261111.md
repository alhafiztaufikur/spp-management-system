# Riwayat DU dan performa tiga halaman

Tanggal verifikasi: 11 November 2026. Baseline kode: `20e5ba2`.
Target localhost: `db_spp_test3`, MySQL 8.4.3, PHP 8.3.33.

## Hasil perubahan

Daftar dan detail Riwayat Daftar Ulang mengikuti pola Riwayat Pembayaran:
identitas, tahun kewajiban, kelas sumber, **Terbayar**, dan badge status.
Detail memisahkan kewajiban, terbayar, sisa, serta masing-masing transaksi.
Kelas/tarif yang belum terbukti tetap tidak diketahui. Nominal bagian DU
dipisahkan dari total transaksi campuran. Filter dan empat kartu ringkasan tetap.
Pemilihan detail mendukung Sebelumnya/Berikutnya/Tutup, Back/Forward,
pembatalan request lama, gagal muat/coba lagi, keyboard, dan tautan tanpa JS.

Pembaca server mengurangi penguraian JSON, agregasi bukti tarif berulang,
pembacaan waktu/audit seluruh arsip, serta pemindaian operator yang mahal.
Daftar operator tetap mempertahankan sumber jurnal dan byte identitas aslinya.
Detail DU mengambil satu grup unit/pemilik/tahun melalui endpoint existing,
tanpa menghitung ulang seluruh daftar. Parameter `selected_year` membedakan
satu transaksi yang memiliki kewajiban lebih dari satu tahun.
Cache tambahan hanya berlaku dalam request/koneksi. Tidak ada tabel ringkasan,
cache lintas request baru, rekonsiliasi, penerbitan, atau perubahan aturan kas.

## Benchmark HTTP

Salinan terkini dipulihkan pada server terpisah sebelum pengujian:

- Sumber: port 3306, UUID `e6e5c8d8-b81c-11f1-9be5-745d2224b37e`.
- Salinan: `db_spp_audit_masterperf2`, port 3320,
  UUID `9192dec5-dd41-11f1-a79a-745d2224b37e`, datadir terpisah,
  event scheduler **OFF**. Scheduler sumber tidak diubah.
- Baseline direkam sebelum empat indeks dipasang pada salinan.
  Pengukuran sesudah memakai kode akhir dan indeks yang diuji.
- Dua server PHP loopback memuat kode sebelum/sesudah pada database salinan.
  Tiap skenario memiliki satu request pertama dan lima pengulangan;
  tabel memakai median lima pengulangan. Request pertama bukan klaim OS cold cache.
- 24 skenario: dua peran operasional × SD/SMP/SMA/Semua Unit × tiga halaman.
  Riwayat Pembayaran memakai 1–22 Oktober 2026 yang berisi transaksi;
  DU memakai seluruh tahun; Biaya Lain memakai tampilan aktif bawaan.

Super Admin, SD:

| Halaman | Server sebelum → sesudah | Penurunan | TTFB | Total HTTP | Query | Puncak memori |
|---|---:|---:|---:|---:|---:|---:|
| Riwayat Pembayaran | 1.589 → 284 ms | 82% | 1.594 → 289 ms | 1.597 → 291 ms | 101 → 102 | 12 → 10 MiB |
| Riwayat DU | 1.164 → 506 ms | 57% | 1.170 → 509 ms | 1.174 → 511 ms | 91 → 94 | 12 → 12 MiB |
| Master Biaya Lain | 944 → 362 ms | 62% | 948 → 365 ms | 951 → 368 ms | 107 → 67 | 8 → 8 MiB |

Request pertama server, urutan halaman sama: 2.181 → 294 ms,
1.645 → 509 ms, dan 995 → 367 ms.
Administrator SD: 1.208 → 287 ms (76%), 1.201 → 498 ms (59%),
935 → 360 ms (61%). Seluruh skenario kontrol lebih cepat pada median;
target minimal 50% untuk masing-masing halaman SD lulus pada kedua peran.
Jumlah query dua riwayat sedikit bertambah karena pemeriksaan/proyeksi terikat;
pengurangan kerja query, JSON, dan baris yang dipindai menghasilkan percepatan.
Angka ini mengukur lingkungan salinan lokal, bukan jaringan produksi.

## Empat indeks terukur

Penambahan indeks diizinkan pengguna setelah optimasi tanpa perubahan skema
belum mencapai target dua riwayat. Profil `history` menyediakan:

| Tabel | Indeks | Kegunaan |
|---|---|---|
| `legacy_op_journals_data` | `idx_history_journal_operator` | Operator terikat unit/batch/origin |
| `legacy_op_payments_data` | `idx_history_payment_operator` | Operator dan hitungan penerimaan aktif |
| `legacy_op_audit_data` | `idx_history_audit_entity` | Bukti waktu/audit per entitas |
| `legacy_op_payments_data` | `idx_history_du_candidate` | Kandidat DU sebelum penguraian JSON |

Indeks kandidat bersifat konservatif: kategori ber-escape/non-ASCII tetap dibaca
oleh pemeriksaan JSON lengkap. Format lama tidak dilewati. EXPLAIN SD memilih
6.116 kandidat dibanding 43.788 baris pembayaran. Introducer `_utf8mb4` pada
ekspresi SQL harus dipertahankan agar ekspresi cocok dengan indeks MySQL.
Tanpa indeks, pembaca tetap memiliki jalur lengkap yang kompatibel.

Installer CLI `scripts/migrate_history_performance.php` memakai preview default,
memeriksa identitas/konflik, dan meminta bukti backup terverifikasi saat apply.
GET tidak memasang indeks. Apply memegang kunci eksklusif akses aplikasi;
indeks biasa memakai LOCK NONE, indeks fungsional LOCK SHARED.
DDL MySQL tidak atomik untuk keempat indeks; jika terhenti, periksa preview
dan lanjutkan installer resmi dengan backup aktual yang masih valid.

```powershell
php scripts/migrate_history_performance.php --database=db_spp_test3 --port=3306
php scripts/migrate_history_performance.php --database=db_spp_test3 --port=3306 --mode=apply --backup-proof=PRIVATE_PROOF.json
```

Backup penuh resmi job `af062c492adb5459640ee67b9a29c3d8`:
624.046.241 byte, SHA-256 dan restore terverifikasi pada server lain,
dengan pemeriksaan skema, 67 tabel, FK/CHECK, health/readiness dan snapshot.
Proof serta dump disimpan di area backup privat, tidak masuk Git.
Setelah pengujian salinan lulus, target dipasang: **4 perubahan, backfill 0**.
Penerapan ulang: **0 perubahan**; keempat indeks berstatus ready.

## Perlindungan data dan pemeriksaan

Sesudah pemasangan dan login localhost, **66 checksum tabel fisik identik**
dengan backup. Indeks fungsional menambah kolom virtual tersembunyi sehingga
CHECKSUM TABLE fisik tabel pembayaran dapat berubah; seluruh kolom pembayaran
asli dibandingkan dengan fingerprint SHA-256 dan jumlah baris backup, identik.
Snapshot finansial terlindungi juga identik. Tidak ada penyesuaian saldo,
pengubahan tanggal kas, atau penyalinan penerimaan Legacy ke pembayaran native.
Hash `koneksi.php` tetap sama dengan awal pekerjaan; `.gitignore`, dump dan cache
pengguna tidak disentuh atau dimasukkan ke commit.

Hasil aktual:

- **176 perbandingan model lengkap** (136 dasar + 40 filter kompleks),
  mencakup seluruh hasil SQL, tarif, ringkasan, status, operator, izin,
  pagination, tahun historis, kosong, arsip, dan pencarian/kelas/operator.
  Kesetaraan model juga diperiksa dalam 24 skenario benchmark.
- **40 perbandingan ekspor/struk**: Excel DU dan pembayaran seluruh hasil,
  PDF pembayaran, HTML dan PDF struk; XML workbook dan teks PDF dibandingkan.
- **83 pemeriksaan integritas**: pemilihan grup terbatas, waktu/audit,
  kategori escaped/Unicode/format lama, transaksi campuran dan tahun ganda,
  nilai tidak diketahui, NIS sama lintas unit, serta kemampuan aksi OUT,
  pending, dihapus, Super Admin/Administrator. Fixture dibatalkan dengan rollback.
- **31 regresi pemakaian Master Biaya Lain**: native/Legacy, pembatalan,
  audit-only, rincian/nominal, master terlindungi, slot sumber, dan kunci tulis.
  Fixture dibatalkan dengan rollback.
- Model workspace existing, 36 uji waktu, 11 kebijakan filter tanpa tanggal,
  dan pembaca DU lengkap vs ringkas juga lulus. Pembaca DU membandingkan
  12.917 baris tahun/transaksi pada empat cakupan unit.
- Audit readiness/integritas salinan: seluruh pemeriksaan relasi/saldo lulus.
- **43 pemeriksaan browser DU**: 24 layout (empat unit × dua tema ×
  1600/900/390 px), navigasi keyboard/history, fokus/scroll, request berlomba,
  HTTP 503 yang sengaja disimulasikan dan retry, tanpa JS, unit salah,
  kosong, serta kompatibilitas detail native/arsip. Tidak ada error JS tak terduga.
- Pemeriksa overflow dan kontras teks baru: seluruh 24 layout lulus AA.
  Parser css-tree, PostCSS dan selector parser menerima 71 aturan CSS.
- **16 pasangan screenshot** Riwayat Pembayaran/Master Biaya Lain identik
  piksel setelah hanya jam berjalan dimask. Screenshot memakai HTML HTTP sukses
  sebelum/sesudah yang dirender dengan aset lokal; bukan klaim uji setiap aksi tulis.
- PHP lint, Node syntax, Python compile, dan `git diff --check` lulus.
- **14 pemeriksaan localhost target**: login existing, health 200/ok, ketiga
  halaman pada empat cakupan unit, pemilihan detail AJAX DU, dan overflow.
  Favicon root browser 404 dicatat terpisah; tidak ada error aplikasi tak terduga.

Artefak mentah privat berada di folder audit `historyperf_20261111` pada area
backup lokal: benchmark, equality, ekspor, browser, kontras, screenshot,
hasil indeks/replay dan verifikasi data. Artefak mengandung data siswa/sesi,
sehingga tidak dipublikasikan. Skrip pengujian terkait tersedia di `tests/history_workspace_*`.
Tidak dilakukan pembayaran nyata atau pengulangan seluruh siklus tulis PSB,
kenaikan, dan OUT pada target. Jalur tulis finansial tidak diubah; fixture dan
regresi salinan memeriksa pembaca/izin yang bersinggungan dengan perubahan.

## Antislop Delivery Gate

Mode During dari pilihan sesi. Design Read: workspace riwayat keuangan untuk
kasir/admin sekolah, mengikuti SistemSPP dan Riwayat Pembayaran existing.
ENERGY 1 / RHYTHM 1 / MOTION 1. Gate berikut berlaku pada bagian UI yang diubah.

### Hard Gate

- R-02 PASS: teks UI baru tidak memakai em dash.
- R-03 PASS: detector browser tidak menemukan overflow pada 24 layout.
- R-17 PASS: angka di UI bersumber dari model database, bukan statistik promosi.
- R-18 PASS: tidak menambahkan testimoni atau persona rekaan.
- R-23 PASS: memakai aset/menu existing; tidak menciptakan logo/gambar baru.
- R-24 PASS: tautan detail, transaksi, struk dan pagination memakai route existing.
- R-25 PASS: seluruh teks list/detail baru lulus pengukuran AA pada 24 layout.
- R-26 PASS: pemilihan, navigasi, retry, reset/filter dan ekspor memiliki aksi nyata yang diuji.
- R-27 PASS: keadaan kosong, loading, gagal 503 dan retry diuji browser.
- R-28 PASS: tidak ada FAQ baru.
- R-32 PASS: Tab/Enter, fokus terlihat, close mengembalikan fokus, navigasi diuji.
- R-33 PASS: perubahan ditulis pada PHP/CSS/JS source, tanpa patch eksternal saat runtime.
- R-34 PASS: tema terang/gelap lulus pada seluruh cakupan unit dan tiga ukuran.
- R-35 PASS: HTTP, klik workspace browser, struk/ekspor dan localhost dijalankan.
- R-36 PASS: klaim performa berasal dari 24 benchmark terukur, batas pengukuran dicatat.
- R-37 PASS: arah visual mengikuti permintaan pengguna dan dials sesi sebelum implementasi.
- R-38 PASS: nilai/konten siswa berasal dari sumber existing; fixture hanya pada salinan dan rollback.
- R-39 PASS: link/button/summary native berlabel, aria-current dan aria-live, fokus serta target aksi 44px; tanpa motion baru.
- R-40 PASS: loading mereservasi tinggi detail, aset CSS diberi versi filemtime; tanpa media/dependensi jaringan baru.

### Purpose Gate

- R-01 PASS: tidak menambahkan glow/gradien dekoratif; latar aksen menandai pilihan dan rekap.
- R-04 PASS: tidak menambahkan set ikon dekoratif baru.
- R-06 PASS: tipografi existing; angka tabular membantu membandingkan nominal.
- R-07 PASS: tanpa pola background baru.
- R-08 PASS: tanpa panah hias baru; navigasi existing tetap bermakna.
- R-09 PASS: badge selalu berlabel status/konteks kelas, tidak berisi klaim pemasaran.
- R-10 PASS: tanpa glassmorphism baru.
- R-12 PASS: border memisahkan catatan; tidak menambahkan bayangan besar per komponen.
- R-13 PASS: tanpa glow dekoratif baru.
- R-14 PASS: struktur catatan berulang mendukung pemindaian riwayat, detail terpilih memiliki hierarki tersendiri.
- R-19 PASS: tanpa animasi template; loading/request hanya mengikuti interaksi pengguna.
- R-22 PASS: tanpa ilustrasi generik.

### Liveliness

- Dials PASS: ENERGY 1 / RHYTHM 1 / MOTION 1 dipertahankan pada list dan detail.
- Konsistensi PASS: workspace tenang dan berulang sesuai pekerjaan administratif.
- Focal point PASS: nama/nominal dan detail terpilih memimpin hierarki.
- Whitespace PASS: jarak memisahkan identitas, periode, nominal, dan bukti transaksi.
- Accent PASS: warna unit pada pilihan dan nominal; status memakai warna semantik berteks.
- Motif PASS: palet unit, badge kelas, border dan pola workspace SistemSPP dipertahankan.
- Design Read PASS: arah mengikuti Riwayat Pembayaran yang dipilih pengguna.

### Craftsmanship dan Quality Locks

- C-1 PASS: warna/hierarki ditujukan untuk membedakan nominal terbayar dari status kewajiban.
- C-2 PASS: kontrol workspace, filter, ekspor dan tautan detail diuji sesuai cakupan di atas.
- C-3 PASS: tidak menambahkan banner atau bagian bantuan umum.
- C-4 PASS: browser menguji tema, ukuran, keyboard, error/retry dan fallback tanpa JS.
- C-5 PASS: benchmark dan kesetaraan tercatat; nilai belum terbukti tetap tidak diketahui.
- R-05 PASS: komposisi list/detail melayani riwayat, bukan template landing page.
- R-11 PASS: radius membedakan catatan, kontrol dan badge status.
- R-15 PASS: aksi menyebut tujuan: Terbayar, Lihat Transaksi, Cetak Bukti Transaksi, Coba Lagi.
- R-16 PASS: tidak menambahkan buzzword pemasaran.
- R-20 PASS: identitas keuangan sekolah terlihat dari tahun kewajiban, kelas, unit dan sumber pembayaran.
- R-21 PASS: dua tema existing tetap berfungsi; tidak memaksakan tema baru.
- R-29 PASS: palet unit existing dengan hijau/amber/netral sesuai status.
- R-30 PASS: mengikuti pola aplikasi sendiri, tidak meniru produk eksternal.
- R-31 PASS: setiap perubahan visual terkait keterbacaan identitas, status atau nominal.
