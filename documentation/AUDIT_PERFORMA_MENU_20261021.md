# Audit Kecepatan Menu dan Tampilan Transaksi - 21 Oktober 2026

Implementasi mempertahankan navigasi PHP, aturan transaksi, parser nominal server, penguncian saldo, hak akses, serta renderer dokumen yang sudah ada. Perubahan tampilan dibatasi pada persetujuan Daftar Ulang, Tabungan, dan tabel Laporan Transaksi. Font Inter/Outfit yang sama kini disajikan lokal untuk menghilangkan ketergantungan jaringan font saat berpindah menu.

## Perubahan

- Input Pembayaran mengirim identitas terlebih dahulu. `status_spp.php?details=1` mengembalikan rincian siswa terpilih sebagai field tambahan; field API sebelumnya tetap tersedia. Ukuran halaman turun dari **1.699.238 menjadi 358.732 byte (78,9%)** pada data uji.
- Kredit migrasi dibaca menurut unit dan siswa pada endpoint baca. Pembacaan massal untuk rekap tetap tersedia. Permintaan status langsung dikirim saat siswa dipilih; debounce 220 ms tetap berlaku pada perubahan lain yang memerlukannya. Permintaan identik digabung, permintaan lama dibatalkan, respons terlambat diabaikan, dan permintaan gagal dapat dicoba kembali. Draf gagal simpan menunggu rincian sebelum memulihkan pilihan tagihan.
- Query histori, buku rekening, aktivitas pemilik, operator, dan laporan memakai proyeksi unit serta batas pemilik/tanggal/halaman. Penghitungan ringkasan dipisah dari rincian halaman jika memungkinkan. Pembacaan metadata/tarif berulang dikurangi hanya dalam satu permintaan; nominal/saldo/tagihan tidak disimpan dalam cache lintas permintaan. Jalur tulis tetap membaca dan memvalidasi data server.
- Persetujuan tunggakan Daftar Ulang menjadi blok label/checkbox yang mudah diklik dan terpisah dari tombol. Teks dan syarat konfirmasi tetap.
- Tabungan Masuk/Keluar mempertahankan dua panel; hierarki, jarak, saldo, tombol, dan pagination dirapikan. Panel menjadi vertikal pada layar kecil. Nominal berformat `100.000` dan `100.000,13`, dengan dukungan titik desimal yang sudah diterima server. Paste/format tidak valid tidak diam-diam diubah menjadi nominal lain.
- Saldo tidak mencukupi memakai popup dengan saldo tersedia dan nominal penarikan. Penolakan server memulihkan isian; server tetap menentukan hasil bila saldo berubah setelah formulir dibuka. Tombol riwayat dikelompokkan menjadi filter, transaksi, dan ekspor/cetak.
- Cetak tabungan dari daftar/detail Riwayat memakai `cetak_buku.php` untuk **seluruh mutasi pemilik**, sama dengan menu Cetak Tabungan. Endpoint struk lama tetap tersedia.
- Tabel pembayaran laporan tunggal/gabungan memakai renderer yang sama: identitas (termasuk NIS Diknas bila tersedia), kelas, periode, nominal, tanggal, dan cetak. Gulir horizontal berada di pembungkus tabel; aksi cetak melekat di kanan. Cetak native/Legacy memakai endpoint dan pemeriksaan akses masing-masing. Cetak pilihan mempertahankan dukungan transaksi native dari endpoint existing. Ditambahkan urutan **Nominal terkecil** dengan pemecah urutan stabil; filter/pencarian/cakupan/periode tetap terbawa ke pagination dan ekspor.
- Pengurutan riwayat tagihan memakai kunci yang dihitung sekali, dengan urutan persis sama dengan comparator awal. Pada 42.462 baris, tahap sorting terukur sekitar 1.266 -> 219 ms.

## Pengukuran

PHP 8.1.10 / MySQL 8.0.30 / Windows Laragon, HTTP loopback memakai server pengembangan PHP. Baseline berasal dari arsip Git sebelum perubahan. Kedua server memakai **salinan bersih yang sama** (`db_spp_audit_menu_final_20261021`), unit SD, sesi/periode/data yang sama, dan empat indeks tambahan yang sama untuk membandingkan perubahan kode. Setiap baris di bawah adalah median tiga permintaan per versi, dikirim bergantian; giliran awal dibalik pada pengulangan kedua. Metrik memisahkan waktu HTTP, waktu PHP, waktu SQL, jumlah query, dan ukuran HTML. Angka bukan jaminan waktu pada komputer/jaringan lain.

| Halaman/fungsi | Sebelum (ms) | Sesudah (ms) | Query sebelum -> sesudah |
|---|---:|---:|---:|
| Dashboard | 153 | 149 | 74 -> 53 |
| Input Pembayaran | 969 | 158 | 157 -> 49 |
| Riwayat Pembayaran | 709 | 426 | 133 -> 83 |
| Riwayat Daftar Ulang | 1237 | 1075 | 103 -> 68 |
| Data Siswa | 2053 | 302 | 90 -> 61 |
| Master Kelas/Rombel | 321 | 362 | 61 -> 42 |
| Master SPP | 1015 | 735 | 923 -> 62 |
| Master Biaya Lain | 904 | 126 | 667 -> 144 |
| Master Daftar Ulang | 505 | 501 | 91 -> 58 |
| Tabungan Masuk | 126 | 131 | 53 -> 38 |
| Tabungan Keluar | 118 | 111 | 53 -> 38 |
| Riwayat Tabungan | 1010 | 491 | 108 -> 73 |
| Cetak Tabungan (daftar rekening) | 1159 | 437 | 58 -> 43 |
| Laporan Transaksi | 719 | 234 | 110 -> 64 |
| Laporan Global (pemilih) | 65 | 92 | 51 -> 38 |
| Surat Laporan | 73 | 74 | 45 -> 32 |
| Laporan Tabungan Siswa | 553 | 328 | 123 -> 86 |
| Riwayat Pembayaran halaman 2 | 557 | 416 | 133 -> 83 |
| Riwayat Tabungan halaman 2 | 857 | 427 | 104 -> 69 |
| Pencarian rekening | 97 | 99 | 53 -> 38 |
| Laporan Transaksi gabungan | 1155 | 691 | 483 -> 422 |

Pengukuran awal terpisah mencatat Input Pembayaran 1.549 -> 171 ms dan Data Siswa 2.189 -> 573 ms. Perbedaan terhadap uji bergantian menunjukkan pengaruh pemanasan database dan beban komputer; tabel bergantian dipakai sebagai perbandingan utama. Halaman ringan yang sudah cepat dapat berfluktuasi beberapa puluh milidetik meskipun query berkurang.

Endpoint Ringkasan (10 permintaan terpisah): **median HTTP 238 ms, estimasi p95 nearest-rank 401 ms**, termasuk sampel pertama 401 ms dan pengulangan sekitar 201-266 ms. Pembaca rincian siswa pada model PHP: median 84 ms, maksimum 154 ms. Uji browser terakhir mengukur 579-837 ms dari pengisian siswa sampai popup terlihat, dengan waktu PHP 439-648 ms. Browser memuat aplikasi beserta seluruh aset dan berbagi sumber daya komputer; angka model/API tidak menggantikan angka kesiapan popup yang benar-benar terlihat.

Template/otorisasi juga diperiksa (median tiga permintaan; pengambilan terpisah sebelum perbaikan akhir sorting/penggabungan arsip, bukan perbandingan bergantian):

| Rute | Sesudah (ms) |
|---|---:|
| `otorisasi_transaksi.php` | 120 |
| `laporan/template.php?template=status` | 999 |
| `laporan/template.php?template=penerimaan` | 631 |
| `laporan/template.php?template=spp-tahunan` | 1071 |
| `laporan/template.php?template=per-item` | 895 |
| `laporan/template.php?template=saldo-tabungan` | 764 |
| `laporan/template.php?template=riwayat-tagihan` | 3238 |
| `laporan/template.php?template=tunggakan-siswa` | 1453 |
| `laporan/template.php?template=setoran` | 627 |
| `laporan/template.php?template=kas-tabungan` | 363 |

**Batas performa yang belum tercapai:** target p95 semua halaman <=1 detik dan Ringkasan terlihat <=300 ms belum terpenuhi secara menyeluruh. Riwayat Daftar Ulang masih sekitar 1,08 detik pada uji bergantian; pemrosesan arsip/rekening/tunggakan besar dapat membutuhkan sekitar 1-3 detik. Sampel per halaman yang hanya tiga belum cukup untuk menyatakan SLA p95 yang andal. Sebagian rekap tetap perlu membaca seluruh bukti agar jumlah dan saldo tidak berubah. Dokumen besar tidak dimasukkan ke target navigasi. Tidak ada jeda buatan atau animasi yang menyembunyikan waktu server.

## Database dan indeks

Konfigurasi pengguna **`db_spp_test2` pada `koneksi.php` dipertahankan**, termasuk pengaman pemeliharaan. Dump dibuat sebelum indeks. Seluruh pengujian simpan/transaksi berjalan pada `db_spp_audit_menu_20261021`; pengukuran/perbandingan memakai salinan bersih lain. Empat indeks tambahan sudah diterapkan pada database lokal pengguna melalui migrasi CLI `scripts/migrate_menu_performance.php --apply`:

| Tabel | Indeks | Kolom |
|---|---|---|
| `legacy_op_journals_data` | `idx_menu_journal_date` | `unit_id, source_date, owner_id` |
| `legacy_op_journals_data` | `idx_menu_journal_operator` | `unit_id, batch_id, source_operator, origin` |
| `legacy_op_payments_data` | `idx_menu_payment_operator` | `unit_id, batch_id, source_operator` |
| `legacy_op_payments_data` | `idx_menu_payment_period` | `unit_id, cancelled, source_period, batch_id` |

Tanpa `--apply`, migrasi hanya memeriksa. Pengulangan melaporkan keempat indeks `READY`, tanpa duplikasi. `EXPLAIN` pembacaan jurnal unit/periode berurutan memilih `idx_menu_journal_date`, tipe `range`, `Using where; Using index`, estimasi 512 baris pada periode pengujian. Tidak ada indeks percobaan yang dipasang pada database pengguna.

Tidak ada penulisan ulang saldo, tagihan, transaksi, atau arsip pada database pengguna. SHA-256 seluruh baris `INSERT` dump sebelum/sesudah identik: `077a9cca141ced8e8798051247ae9bbd02cb3e7f3b70158385431a0a60dce537`. Perubahan skema hanya empat indeks di atas.

## Bukti pengujian

- **265 pemeriksaan model**: status/kredit PSB, SPP, Komite, DU, tagihan tahunan, rincian per siswa versus pembacaan massal di SD/SMP/SMA, urutan nominal setelah kredit historis, serta pagination lintas bagian dengan total tetap.
- **100 pemeriksaan HTTP pada salinan mutasi**: pemilik siswa/non-siswa, simpan pecahan bertitik/koma, replay tanpa duplikasi, saldo kurang, pemulihan isian, saldo berubah oleh penarikan lain setelah formulir dibuka, CSRF, serta PDF buku seluruh rekening. Penarikan server menolak draf dengan saldo lama; saldo asli dipulihkan oleh pasangan setoran/penarikan uji.
- **Kesetaraan model laporan**: sepuluh himpunan laporan SD dan sepuluh Semua Unit mempunyai record/hash yang persis sama dengan baseline. Riwayat Tagihan seluruh periode: **42.462 baris serta urutan persis sama**. Uji tambahan membandingkan 1.400 baris sintetis dengan angka/nama/tanggal/kunci sama untuk kestabilan sorting.
- **Browser Chrome**: respons lama tidak menimpa siswa terbaru, jaringan gagal dapat dicoba lagi, format saat mengetik/paste/blur, popup saldo dan fokus, seluruh enam urutan laporan tunggal/gabungan, cetak terlihat di pembungkus tabel, riwayat mengarah ke buku, serta tanpa error JavaScript/permintaan font eksternal. Tabungan/Daftar Ulang diperiksa pada lebar 1440/900/390 dan tema terang/gelap; laporan juga diperiksa pada tablet/ponsel tanpa overflow halaman.
- **Perbandingan visual di luar perubahan yang diminta**: Dashboard, Input Pembayaran dan Master SPP sama persis dengan baseline; Data Siswa berbeda 0,0002% piksel. Jam dinamis disamarkan dan font baseline yang sama disediakan lokal untuk perbandingan.
- Test existing `payment_form_feedback_test`, `spp_payment_status_test`, `savings_precision_test` (15), `savings_book_test`, dan `savings_book_pdf_test` lulus. PDF buku kosong satu halaman dan buku panjang dua halaman diperiksa; tautan cetak Legacy menghasilkan PDF valid. Sintaks 45 file PHP yang berubah/baru, empat file JS, dan `git diff --check` lulus.
- Skrip lama `payment_process_integration_test.php` belum lulus sebagai suite utuh: fixture awal tidak menyediakan aktor, dan setelah fixture QA dipenuhi skrip berhenti pada asumsi lama tentang formulir edit langsung yang kini memerlukan konteks/akses existing. Handler simpan pembayaran tidak diubah dalam pekerjaan ini. Hasil ini tidak dihitung sebagai pengujian lulus.

## Artefak dan pengulangan

Artefak privat berada di `C:\laragon\backups\spp-management-system\menu_speed_20261021`: dump sebelum indeks, baseline source, JSON waktu/query/ukuran, `paired-timings.json`, `summary-timings.json`, perbandingan record, `source-data-verification.json`, `final-verification.json`, rencana eksekusi jurnal, screenshot, dan PDF. Artefak menyertakan sesi/fixture QA privat; jangan dipublikasikan sebagai bundel web.

Skrip yang dapat diulang dari repository:

- `tests/menu_speed_audit.py`: matriks HTTP baca pada server salinan yang dipilih; simpan matriks inti dan `extended`.
- `tests/menu_performance_model_test.php`, `tests/menu_report_equivalence_test.php`, `tests/menu_refinement_http_test.php`: memakai pengaman `tests/http_form_scope.php`. Wajib nama `SPP_DB_NAME=db_spp_audit_*`, `SPP_TEST_ALLOW_MUTATION=1`, dan untuk HTTP server loopback yang identitas salinannya cocok. Jangan menjalankan tes mutasi terhadap `db_spp_test2`.
- `tests/billing_sort_performance_test.php`: kesetaraan comparator tanpa database.
- `tests/menu_refinements_browser_test.js`: browser pada server salinan privat; `SPP_PLAYWRIGHT_MODULE` dapat menunjuk instalasi Playwright lokal. Tidak mengirim transaksi valid ke database pengguna.

Server PHP QA sementara ditutup setelah verifikasi. Tidak melakukan commit, push, deployment, penggantian aplikasi menjadi SPA, atau perubahan perhitungan transaksi.
