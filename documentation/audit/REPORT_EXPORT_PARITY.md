# Audit Parity Laporan, Struk, dan Export SistemSPP

> Baseline kode: branch `main`, commit awal `a446af3fbb89cb0933870443be5aedd85d34eaa3`  
> Database uji: `db_spp_audit_20260820_090000` (salinan anonim; `db_spp` tidak digunakan untuk test)  
> Tanggal audit: 20 Agustus 2026 WIB  
> Status Wave 5: **belum dapat dinyatakan GO**; parity data modular sudah mempunyai oracle otomatis dan 11 artefak PDF sudah lulus parse/render/inspeksi visual lokal, tetapi validasi browser target, client Excel, beban besar, dan kebijakan laporan yang masih terbuka tetap menjadi gate.

## 1. Batas dan aturan audit

Audit mencakup:

- laporan umum: `laporan/index.php`, `export_excel.php`, dan `export_pdf.php`;
- tujuh laporan modular: `global.php`, `template.php`, `export_global.php`, dan `includes/reports.php`;
- rekap kelas dilindungi: `rekap_kelas.php` dan `detail_siswa.php`;
- struk: `cetak_struk.php` dan `cetak_struk_tahunan.php`;
- sumber data finansial pada `bayar`, child DU/Biaya Lain, claim SPP, snapshot tahun ajaran, dan ledger tabungan manual.

`laporan/rekap_kelas.php` tetap menjadi referensi visual yang dilindungi. Perubahan dalam Wave 5 hanya memasang bootstrap session keamanan sebelum markup; markup, CSS, selector, alur, dan konsep tampilannya tidak diubah. Akurasi datanya tetap diaudit dan temuan tidak disembunyikan.

Istilah bukti:

| Status | Arti |
| --- | --- |
| PASS otomatis | Assertion terhadap fixture oracle independen berhasil pada DB audit dan fixture di-rollback |
| PASS read-only | Query agregat pada DB audit anonim cocok dengan kontrak |
| PASS statis | Source membuktikan jalur data/guard yang sama; belum membuktikan renderer/client |
| TERBATAS | Pemeriksaan berhasil hanya pada dataset kecil atau hanya struktur file |
| OPEN | Defect/keputusan/gate belum diselesaikan |
| TIDAK DIUJI | Tool atau lingkungan yang dibutuhkan tidak tersedia |

## 2. Sumber kebenaran dan rumus oracle

| Nilai laporan | Sumber resmi | Rumus/aturan |
| --- | --- | --- |
| Tanggal penerimaan | `bayar.TGL_BYR` | rentang selalu half-open: `>= awal 00:00:00` dan `< hari setelah akhir` |
| SPP terbayar per bulan | `bayar_spp_periode` + `bayar.U_SPP` | header tanpa claim tidak ditebak sebagai periode |
| Tarif SPP/Komite historis | `siswa_tahun_ajaran.*_snapshot` | bukan tarif aktif pada `siswa` |
| DU terbayar | `bayar_du` melalui `bayar_id` dan `tagihan_daftar_ulang_id` | jumlah per child dan per tagihan |
| Biaya Lain terbayar | `bayar_biaya_lain.nominal_snapshot` | tidak menjumlah ulang mirror `U_LAIN` |
| Total kuitansi aman | header + child | semua komponen primer + DU + Biaya Lain - `potong_spp` |
| Tabungan masuk | `transaksi_m WHERE bayar_id IS NULL` | linked saving bukan ledger manual |
| Tabungan keluar | `transaksi_k.KELUAR` | saldo = opening + masuk - keluar |
| Tunggakan SPP tahunan | per bulan | `SUM(MAX(0, tarif_snapshot_bulan - bayar_claim_bulan))`; overpay bulan lain tidak menutup bulan yang belum dibayar |
| Kas fisik | settlement | pembayaran Tunai + tabungan masuk - tabungan keluar |

Kontrak lengkap berada di `documentation/audit/DATA_CONTRACT.md`. Wave 5 memakai kontrak itu, bukan angka cache siswa, sebagai oracle.

## 3. Fixture oracle anonim

`tests/report_export_parity_test.php` membuat fixture di dalam satu transaksi dan selalu `ROLLBACK`. Guard test mewajibkan `SPP_APP_ENV=test` dan nama database berawalan `db_spp_audit_`; test menolak database produksi.

Fixture mencakup:

- dua tahun ajaran (`2098/2099` dan `2099/2100`), dua rombel kelas 6, tiga siswa aktif dan satu siswa arsip;
- tarif aktif yang sengaja berbeda dari snapshot agar drift tarif dapat terdeteksi;
- pembayaran Tunai, VA, dan QRIS; SPP dua cicilan; DU dua cicilan; Biaya Lain dua cicilan; Komite; Uang Pangkal; dan potongan SPP;
- payment di `00:00:00`, `23:59:59`, serta tepat `00:00:00` hari berikutnya untuk mengunci batas half-open;
- overpay satu bulan yang nominal tahunannya sama dengan total tagihan, untuk membuktikan overpay tidak boleh menutup bulan lain;
- pembayaran legacy tanpa claim yang harus tetap tidak dipetakan otomatis;
- tabungan opening, masuk/keluar pada timestamp sama, transaksi hari berikutnya, dan linked saving sintetis yang wajib dikecualikan;
- siswa tanpa pembayaran dan nama yang diawali formula spreadsheet.

Expected utama dihitung sebagai konstanta independen dari helper produksi:

| Oracle | Expected |
| --- | ---: |
| Komponen pembayaran 15 Agustus 2099 | 9 baris, Rp1.170.000 |
| Tunai | Rp845.000 |
| VA | Rp325.000 |
| SPP siswa A Agustus | tagihan Rp500.000; bayar Rp500.000; Lunas |
| SPP siswa B Agustus | tagihan Rp600.000; bayar Rp500.000; Cicilan |
| DU siswa A | tagihan Rp1.000.000; bayar Rp500.000 |
| Biaya Lain siswa A | tagihan Rp200.000; bayar Rp75.000 |
| SPP tahunan siswa A | tagihan Rp6.000.000; bayar Rp6.000.000; tunggakan per-bulan Rp5.000.000; Rekonsiliasi |
| Tabungan siswa A Agustus | opening Rp100.000; masuk Rp60.000; keluar Rp30.000; closing Rp130.000 |
| Kas fisik 15 Agustus | Rp845.000 + Rp100.000 - Rp30.000 = Rp915.000 |

## 4. Matriks parity

### 4.1 Laporan modular

Web, print, PDF, dan Excel modular memanggil `report_filters()` lalu `report_build()` yang sama. Ini menghilangkan drift query antarformat, tetapi renderer dan client tetap harus diuji terpisah.

Smoke terautentikasi membuktikan ketujuh template merespons 200 pada web, print, Excel, dan PDF. Retest khusus juga membuktikan parameter `kelas` yang tidak dikirim menghasilkan default rombel yang sama pada web/print/Excel/PDF; `kelas=0` eksplisit tetap berarti semua rombel. Sebelas artefak PDF yang disimpan dari smoke tersebut kemudian diparse dan seluruh 17 halamannya dirender serta diinspeksi visual lokal tanpa halaman kosong, clipping, atau overlap yang terlihat. Bukti ini terbatas pada fixture dan artefak tersebut.

| Template | Row/total/filter/order | Web vs print/PDF/Excel | Status | Catatan |
| --- | --- | --- | --- | --- |
| Status Pembayaran | snapshot/claim, DU, Biaya Lain, ledger biaya awal | satu dataset helper | PASS otomatis + HTTP | format status/search/class/student status dicakup |
| Penerimaan Harian | half-open date, komponen, discount, metode, operator | satu dataset helper | PASS otomatis + HTTP | actor ditampilkan sebagai nama admin; fallback hanya untuk legacy |
| SPP Tahun Ajaran | Juli-Juni dan tunggakan per bulan | satu dataset helper | PASS otomatis + HTTP | overpay menghasilkan Rekonsiliasi dan tidak menutup bulan lain |
| Pembayaran per Item | snapshot SPP/Komite dan claim SPP | satu dataset helper | PASS otomatis + HTTP | kebijakan item satu-kali lintas rentang masih OPEN |
| Mutasi Tabungan per Kelas | opening, matriks masuk/keluar, closing | satu dataset helper | PASS otomatis + HTTP | zero-transaction historical membership masih OPEN |
| Tabungan Siswa | transaksi manual, running balance, deterministic tie | satu dataset helper | PASS otomatis + HTTP | urutan tie ditetapkan Masuk lalu Keluar |
| Setoran Kas Harian | Tunai/non-tunai/tabungan/kas fisik | satu dataset helper | PASS otomatis + HTTP | laporan tetap live, belum closing |

Ekspor modular dibatasi 10.000 baris dan laporan berbasis rentang tanggal dibatasi maksimal 366 hari kalender. Limit row saat ini diterapkan setelah dataset helper terbentuk; karena query belum DB-pagination, limit belum menyelesaikan risiko memory/rows examined untuk dataset besar.

### 4.2 Laporan umum

| Dimensi | Web | Excel HTML `.xls` | PDF | Status |
| --- | --- | --- | --- | --- |
| Rentang transaksi | half-open | half-open | half-open | PASS statis |
| Total header pembayaran | `SUM(total_jumlah)` | jumlah row `total_jumlah` | total per struk | PASS read-only pada 16 payment / Rp15.865.000 |
| DU | breakdown child | breakdown child setelah perbaikan | child milik `bayar_id` setelah perbaikan | PASS statis + oracle helper |
| Biaya Lain | snapshot detail | snapshot detail | snapshot detail per payment | PASS statis |
| Potongan SPP | total net + breakdown negatif | breakdown negatif | baris negatif per struk | PASS statis |
| Tabungan | manual masuk/keluar | manual masuk/keluar | tidak ada | **OPEN: PDF bukan laporan keuangan yang sama** |
| Urutan transaksi | whitelist; ID tie untuk terbaru | tanggal lalu ID | tanggal lalu ID | PASS statis untuk default |
| Filter report type/sort | web saja | tidak diteruskan | hanya period/selected IDs | **OPEN: format tidak ekuivalen** |

`export_pdf.php` secara produk adalah batch slip pembayaran, bukan representasi PDF dari seluruh laporan umum. Angka per struk dapat benar, tetapi parity row/subtotal/tabungan/filter dengan web/Excel tidak dapat diklaim. Opsi perbaikan perlu keputusan: ubah tombol menjadi “Cetak Struk PDF” dan tambahkan endpoint PDF laporan, atau ubah endpoint saat ini menjadi PDF laporan dan pertahankan struk pada route struk yang sudah ada.

### 4.3 Rekap kelas dilindungi dan detail siswa

| Aspek | Hasil |
| --- | --- |
| Ketersediaan route/alur | tetap ada; sidebar dan detail siswa tetap menunjang alur |
| Visual source | markup/CSS tidak diubah dalam Wave 5; hanya bootstrap session sebelum markup |
| Periode pembayaran | masih membaca `bayar.BULAN/TAHUN`, bukan claim |
| Tarif SPP | masih membaca `siswa.SPP_PERBULAN`, bukan snapshot tahun ajaran |
| Kelas | filter memakai `siswa.KELAS`, bukan rombel/snapshot historis |
| Detail transaksi | child DU/Biaya Lain ditautkan dengan `bayar_id`; total memakai header |
| Screenshot regression | TIDAK DIUJI; browser in-app tidak tersedia pada run ini |

Temuan akurasi tidak diperbaiki langsung karena halaman ini dilindungi. Perubahan data-source harus dirancang tanpa mengubah markup/visual dan kemudian disetujui dengan screenshot baseline.

### 4.4 Struk biasa dan tahunan

| Aspek | Biasa | Tahunan | Status |
| --- | --- | --- | --- |
| Lookup | payment ID prepared | batch token 32 hex prepared | PASS statis |
| DU current payment | `bayar_du.bayar_id` | `bayar_du.bayar_id` | PASS statis |
| Biaya Lain | `bayar_biaya_lain.bayar_id` | sama | PASS statis |
| Total | `bayar.total_jumlah` | sama | PASS oracle komponen untuk payment versi 1 |
| Kelas pada struk | snapshot payment, fallback header/current | sama setelah perbaikan | PASS statis |
| Operator | lookup `admin.id`, fallback text legacy | sama | PASS statis |
| Enumeration/object authorization | semua role kasir dapat mencoba ID berurutan; batch token berentropi tinggi | batch token | OPEN kebijakan object scope/audit log |
| HTTP smoke terautentikasi | 200 pada satu payment ID valid | belum diuji dengan batch valid | TERBATAS |
| Print/PDF visual | belum dirender pada browser/print client | belum dirender | TIDAK DIUJI |

## 5. Perbaikan yang diimplementasikan pada Wave 5

| ID | Defect | Perbaikan |
| --- | --- | --- |
| REP-FIX-001 | Tunggakan tahunan memakai `max(total bill - total paid)` sehingga overpay bulan A dapat menutup bulan B | hitung shortfall per bulan dan tandai overpay sebagai Rekonsiliasi |
| REP-FIX-002 | Per-item SPP/Komite memakai tarif aktif dan SPP memakai header legacy | gunakan snapshot tahun ajaran; SPP terbayar memakai claim; kewajiban dihitung per bulan |
| REP-FIX-003 | Status biaya awal membaca cache `*_BAYAR` | baca agregat ledger `bayar.U_*`; cache tetap diverifikasi terpisah |
| REP-FIX-004 | Breakdown laporan umum tidak memasukkan DU/potongan secara parity dan tabungan dapat memasukkan linked saving | tambahkan DU dan potongan negatif; batasi masuk ke `bayar_id IS NULL` |
| REP-FIX-005 | PDF menggabungkan seluruh DU siswa/periode ke setiap struk dan membuat slip contoh saat periode kosong | join DU eksklusif melalui `bayar_id`; data kosong tidak lagi menghasilkan transaksi fiktif kecuali `contoh=1` eksplisit |
| REP-FIX-006 | Text Excel dapat diawali formula dan NIS leading zero dapat berubah | netralkan prefix formula dan paksa identifier sebagai text |
| REP-FIX-007 | Tanggal hanya lolos regex dan export tidak memiliki batas | validasi tanggal kalender, maksimum 366 hari, maksimum 10.000 row modular/Excel, maksimum 200 slip PDF |
| REP-FIX-008 | Operator berupa ID, kelas struk current, tie mutasi nondeterministik | lookup nama admin, snapshot kelas payment, dan tie `Masuk` lalu `Keluar` |
| REP-FIX-009 | Error report modular dapat mengekspos detail exception | pesan eksternal generik dengan reference ID untuk error internal |
| REP-FIX-010 | Web memilih rombel pertama ketika `kelas` tidak dikirim, tetapi export langsung memakai semua rombel | pusatkan default filter template; web dan seluruh export sekarang memakai rombel default yang sama, sedangkan `kelas=0` eksplisit tetap semua rombel |
| REP-FIX-011 | Daftar belum bayar umum memakai tarif siswa aktif dan membentuk Biaya Lain dari cross join siswa × master | gunakan snapshot penempatan untuk SPP/Komite/DU serta hanya tagihan Biaya Lain materialized berstatus open |

## 6. Temuan yang masih terbuka

| ID | Level | Temuan | Dampak | Acceptance |
| --- | ---: | --- | --- | --- |
| REP-001 | Tinggi | PDF laporan umum sebenarnya batch struk dan tidak mempunyai filter/report type/tabungan yang sama | tidak ada parity web/PDF/Excel untuk “Laporan Umum” | keputusan produk lalu endpoint/label dan test parity diperbarui |
| REP-002 | Tinggi | Excel adalah HTML ber-ekstensi `.xls`; nominal ditulis sebagai string berformat Indonesia | warning format, angka tidak typed, sorting/formula client tidak andal | gunakan writer XLSX nyata; test Excel client untuk leading zero, date, numeric type, encoding, formula injection |
| REP-003 | Tinggi | tujuh helper modular membentuk seluruh row di PHP; pagination baru `array_slice` | memory dan query cost tetap O(total rows) | DB pagination/count query, export streaming/chunk, explain/rows examined, load test dataset besar |
| REP-004 | Tinggi | rekap kelas dilindungi memakai tarif/current class/header period | histori dapat berubah atau salah periode | ubah query saja ke snapshot/claim tanpa mengubah visual; screenshot approval wajib |
| REP-005 | Sedang | siswa tanpa mutasi pada laporan tabungan kelas ditambahkan dari kelas aktif saat ini | rekap historis zero-row dapat memakai kelas masa kini | definisikan membership period lalu gunakan placement snapshot pada tanggal/periode |
| REP-006 | Tinggi | item satu-kali (Pangkal/Bangunan/Seragam/Kegiatan/Makan/Sorga/Infaq) dibandingkan dengan pembayaran hanya di rentang terpilih | status “sisa” dapat mengabaikan pembayaran di luar rentang | putuskan apakah laporan adalah activity atau outstanding lifetime; label/query/test mengikuti keputusan |
| REP-007 | Tinggi | kasir dapat membuka seluruh laporan modular dan struk ID berurutan | least privilege/object scope belum diputuskan | owner mengesahkan scope kasir; negative role/object tests dan audit export/receipt |
| REP-008 | Tinggi | laporan setoran bersifat live tanpa snapshot closing | koreksi concurrent dapat mengubah angka setelah dicetak | definisikan closing/snapshot atau terima live dengan timestamp/version konsisten |
| REP-009 | Tinggi | PDF smoke sudah mempunyai bukti parse/render/visual lokal, tetapi file Excel belum dibuka pada client target dan PDF belum diuji pada viewer target | warning/format client tetap belum diketahui | buka PDF dan spreadsheet pada client yang disepakati; uji payload formula sebagai teks inert |
| REP-010 | Sedang | limit row modular diterapkan setelah query/helper selesai | limit response bukan resource cap penuh | pindahkan limit ke SQL dan hentikan sebelum materialisasi berlebih |
| REP-011 | Sedang | histori kelas/tabungan bergantung invariant rentang tahun ajaran tidak overlap | overlap dapat menduplikasi join mutasi | constraint/prosedur publish + verifier wajib PASS sebelum report |

## 7. Bukti read-only database audit

Query agregat anonim pada `db_spp_audit_20260820_090000` setelah rekonsiliasi terkontrol menghasilkan:

| Pemeriksaan | Hasil |
| --- | ---: |
| Payment | 16 row; 3 Juli-5 Agustus 2026; total Rp15.865.000 |
| Payment versi aman dengan component mismatch | 16 diperiksa; 0 mismatch |
| SPP payment tanpa claim | 16 diperiksa; 0 missing |
| Operator payment aman tidak terpetakan | 0 |
| Manual tabungan masuk | Rp825.000 |
| Tabungan keluar | Rp0 |
| Cache saldo | Rp825.000 |
| Linked saving | 0 row / Rp0 |
| Total header vs total komponen seluruh payment | Rp15.865.000 vs Rp15.865.000 |
| Tahun ajaran overlap | 0 pair |

Angka tersebut hanya membuktikan dataset audit kecil saat ini, bukan scale, closing, atau seluruh histori client.

## 8. Benchmark read-only dataset kecil

`tests/support/report_readonly_benchmark.php` mengukur satu build setiap template dengan `Com_select`, wall time, row count, dan delta peak memory. Run ini bukan load test dan tidak boleh dipakai sebagai SLA.

| Template | Rows | SELECT | Wall time |
| --- | ---: | ---: | ---: |
| Status | 20 | 2 | 4.190 ms |
| Penerimaan | 45 | 3 | 2.692 ms |
| SPP Tahunan | 20 | 2 | 3.647 ms |
| Per Item | 20 | 3 | 3.024 ms |
| Tabungan Kelas | 20 | 3 | 7.877 ms |
| Tabungan Siswa | 7 | 2 | 3.466 ms |
| Setoran | 6 | 4 | 4.006 ms |

Delta peak memory tercatat 0 karena seluruh run berada pada blok alokasi PHP yang sama dan dataset sangat kecil; ini bukan bukti memory aman.

## 9. Bukti test dan cara menjalankan

Jalankan seluruh test melalui runner terisolasi:

```powershell
powershell -ExecutionPolicy Bypass -File tests/run_all.ps1 -Database db_spp_audit_20260820_090000
```

Jalankan benchmark read-only:

```powershell
# Gunakan environment audit yang sama dengan tests/run_all.ps1
C:\xampp\php\php.exe tests/support/report_readonly_benchmark.php
```

Riwayat oracle dipertahankan apa adanya: run awal gagal karena query ledger biaya awal sempat merujuk kolom cache yang tidak ada pada alias `bayar` (`b.PANGKAL_BAYAR`); mapping diperbaiki ke `b.U_PANGKAL` dan keluarga `U_*`. Run berikutnya menangkap timestamp fixture saldo pembuka yang masih berada di dalam bulan laporan; fixture dipindahkan ke 31 Juli. Final rerun setelah normalisasi default filter lulus dan rollback meninggalkan nol row berprefix `W5`.

Checklist bukti Wave 5:

| Bukti | Status run ini |
| --- | --- |
| PHP lint file report/test | PASS |
| `git diff --check` file report/test | PASS |
| Oracle transaksi/rollback | PASS; tujuh template, snapshot/claim, cicilan, boundary tanggal, filter, ordering, tabungan, setoran, limit, formula export, dan default filter; seluruh probe fixture sesudah rollback = 0. `REP-FORMULA-001` menambah coverage prefix `=`, `+`, `-`, `@`, whitespace Unicode, CR/LF/tab, dan NUL pada helper spreadsheet |
| Seluruh regression runner | TERBATAS namun final tercatat; run lintas wave sempat 10/11 karena assertion placeholder kelas yang stale, assertion diperbaiki, dan disposable runner terbaru `regression-20260827_023858` (`REG-FINAL-003`) lulus 18/18 |
| HTTP role/format | PASS terbatas untuk admin: 34/34 pemeriksaan utama web/print/Excel/PDF/struk/empty redirect/logout; 68 request pada log, 0 respons 500/fatal. Belum merupakan matriks role lengkap |
| PDF binary dapat dibuka | PASS pada 11 artefak tersimpan; parser membuka seluruh file dan menghitung 17 halaman; general batch mempunyai 4 halaman |
| PDF render seluruh halaman | PASS terbatas artefak; PyMuPDF merender 17/17 halaman ke PNG dan contact sheet kecil diinspeksi tanpa halaman kosong, clipping, atau overlap yang terlihat |
| PDF parser/text extraction | PASS terbatas artefak; `pypdf` mengekstrak teks nonkosong pada 17/17 halaman |
| Excel struktur | TERBATAS; 11 artefak berisi tabel HTML yang dapat diparse (minimal 3 tabel per file); tidak ditemukan sel fixture dengan prefix formula berbahaya. Export modular mempunyai BOM UTF-8, export umum memakai meta charset UTF-8 tanpa BOM. Oracle `REP-FORMULA-001` memverifikasi sanitasi helper, dan `REP-XLS-004` membuka seluruh artefak pada Excel lokal tanpa formula native; tipe sel, perilaku warning, dan payload formula pada client target tetap belum terbukti |
| Excel client | PARSIAL lokal; seluruh 11 `.xls` Wave 5 dibuka read-only melalui Excel COM tanpa formula native (`REP-XLS-004`), tetapi client target, tipe sel, warning format, dan payload formula belum diuji |
| Concurrent correction | TIDAK DIUJI; kebijakan closing belum diputuskan |
| Dataset besar + EXPLAIN | TIDAK DIUJI; arsitektur pagination saat ini masih blocker |

Bukti HTTP tersanitasi disimpan di luar web root pada `C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0\wave5-http`. Server port 8100 dihentikan setelah run, dua sesi audit orphan dihancurkan, dan tidak ada fixture W5 tersisa pada database audit.

## 10. Keputusan release Wave 5

Status saat ini **NO-GO untuk klaim “seluruh laporan/export parity dan siap client”** karena REP-001, REP-002, REP-003, REP-004, REP-006, REP-007, REP-008, dan sisi client dari REP-009 belum ditutup. Perbaikan yang sudah diterapkan mempersempit risiko angka salah dan formula injection, tetapi tidak menggantikan:

1. keputusan produk untuk PDF laporan umum dan status item satu-kali;
2. writer XLSX nyata dan test client;
3. DB pagination/export streaming serta load test;
4. perubahan data-source rekap dilindungi dengan screenshot regression;
5. model closing/setoran dan concurrency test;
6. validasi PDF/print pada viewer target dan browser UAT.

Excel lokal sudah diuji read-only untuk seluruh 11 artefak pada `REP-XLS-004`, tetapi belum ada klaim bahwa workbook tersebut lolos tipe sel, warning format, payload formula, atau workflow pada aplikasi spreadsheet client target. PDF juga belum lolos viewer/print client target. Klaim visual lokal hanya berlaku untuk 11 artefak tersimpan dan 17 halaman yang dirender pada 26 Agustus 2026.
