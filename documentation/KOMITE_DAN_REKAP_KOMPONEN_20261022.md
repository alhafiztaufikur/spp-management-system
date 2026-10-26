# Pemetaan Komite Legacy dan perbaikan rekap komponen

Tanggal pemeriksaan: 22 Oktober 2026.

## Perubahan yang terlihat oleh pengguna

Kolom pembayaran Legacy `bayar.U_SORGA` telah dikonfirmasi sebagai pembayaran Komite. Struk, detail pembayaran, rincian ekspor, serta rekap penerimaan kini menampilkan **Komite**. Penerimaan U_SORGA pada hasil impor lama digabungkan dengan Komite native dan hasil impor baru; setiap nominal tetap dihitung satu kali.

Panel komponen Laporan Transaksi kembali membaca seluruh hasil filter, termasuk ketika tabel transaksi sedang berada di halaman berikutnya. Reproduksi kasus SD pada periode 1–22 Oktober 2026 menghasilkan **240 transaksi, 9 komponen, dan total Rp156.508.200**. Desain tiga tab dan tata letak kuitansi tetap.

## Aturan migrasi baru dan kompatibilitas pekerjaan lama

- Pekerjaan baru menangkap `komite_mapping_version: 1` sejak unggahan diantrekan. Penanda privat mengikat versi aturan dengan ID pekerjaan, unit, dan hash sumber. Jalur migrasi operasional maupun penuh menggunakan penanda ini; pekerjaan yang sudah diantrekan sebelum perubahan tetap memakai aturan sebelumnya.
- Katalog, saran profil, pengesahan kelompok, dan persiapan pembayaran memasangkan U_SORGA secara otomatis dengan `komite`. Profil otomatis lama tidak mengembalikannya menjadi Histori Saja. Pemetaan manual yang sudah disahkan tetap dihormati.
- POMG tetap menjadi sumber tarif bulanan siswa. Pembayaran U_SORGA dan slot POMG mempertahankan nominal masing-masing; keduanya tidak dibuatkan master biaya tambahan. POMG juga tidak ditimpa oleh inferensi biaya lain.
- Periode kosong tetap kosong dan tanggal yang tidak tercatat tetap tidak tercatat. Komponen pembayaran saja tidak menciptakan tagihan atau bukti kelunasan yang tidak tersedia.
- JSON rincian operasional menyimpan kode asli, referensi baris sumber, periode, status pencocokan, dan aturan `sorga_is_komite`. Pada format penuh, aturan disimpan di konfigurasi manifest bersama kolom sumber dan referensi baris yang sudah tersedia. Tidak ada penambahan skema.
- Versi worker/pratinjau operasional naik dari 11 menjadi 12. Pembaca referensi tetap mendukung versi sebelumnya. Versi metadata rekonsiliasi DU tetap 1 dan versi aturan format penuh tetap 8 agar format serta fingerprint lama tidak berubah.

Helper bersama menghasilkan `display_label` dan `cash_category`, tanpa mengganti kategori sumber. Identifikasi menggunakan kolom sumber U_SORGA atau kunci histori yang terverifikasi; tulisan label yang kebetulan sama tidak cukup.

Pembaca komponen laporan menyediakan proyeksi penerimaan yang secara default nonaktif. Hanya laporan penerimaan yang mengaktifkannya. Pembaca kewajiban, kredit bulanan, koreksi, dan verifikasi fingerprint tetap memakai kategori mentah. Filter histori lama tetap dapat digunakan. Adapter baca untuk struk format penuh memakai renderer kuitansi yang sudah ada.

## Penyebab dan perbaikan rekap kosong

Pada MySQL 8.0.30, bentuk CTE `chosen` yang digunakan ulang dalam beberapa cabang UNION dapat menghasilkan rekap kosong ketika optimizer aktif, walaupun daftar transaksi berisi pembayaran. Kasus ini direproduksi sebelum perubahan pada 240 transaksi SD tersebut.

CTE sekarang membentuk `SELECT DISTINCT p.id, p.unit_id` setelah seluruh filter diterapkan. Daftar pasangan ID/unit yang unik menjadi batas optimasi untuk cabang native, Legacy penuh, dan Legacy operasional. Konfigurasi optimizer server tidak diubah.

Agregat dan rincian ekspor tetap menggunakan satu proyeksi item. Kunci serta label setiap cabang UNION disamakan menjadi teks `utf8mb4_general_ci`. Perhitungan tetap memakai DECIMAL/sen, termasuk potongan negatif; master berbeda tetap terpisah meskipun namanya sama.

Panel juga membedakan tiga keadaan:

- Tidak ada transaksi: “Tidak ada pembayaran sesuai filter.”
- Ada transaksi tanpa rincian bernominal: pesan khusus rincian kosong.
- Pembacaan komponen gagal: daftar transaksi tetap tampil, tersedia tautan muat ulang, dan total komponen menjadi `—`. Kesalahan teknis dicatat pada log server. Hasil kosong dengan total pembayaran bukan nol diperlakukan sebagai kegagalan pembacaan.

Versi cache dokumen ikut memperhitungkan kode proyeksi komponen dan helper label. Dokumen dengan pemetaan lama tidak digunakan kembali setelah perubahan ini.

## Hasil verifikasi

Seluruh pengujian yang menulis data dijalankan pada instance MySQL terisolasi, port 63831, dengan pemeriksaan nama database dan UUID server. Salinan data dan fixture penerapan menggunakan database uji yang berbeda. Tidak ada migrasi yang dijalankan pada database aktif.

| Cakupan | Hasil |
| --- | --- |
| Aturan Komite baru/lama | 27 pemeriksaan lulus: tiga unit, U_SORGA tunggal/campuran, POMG, nominal nol, periode kosong, pemetaan manual, bukti sumber, dan fingerprint lama |
| Model laporan | 403 pemeriksaan lulus: optimizer aktif/nonaktif, kolasi koneksi berbeda, seluruh unit, pagination, pencarian, filter siswa/periode, transaksi tanpa tanggal, kategori mentah/proyeksi penerimaan, dan filter histori lama |
| Fixture komponen | Lulus: native + kedua format Legacy, pecahan sen, potongan negatif, master berbeda bernama sama, struk format penuh, dan rollback seluruh penulisan fixture |
| Migrasi operasional | Lulus: aturan sejak enqueue, profil lama, metadata, pengaitan Komite bulanan yang telah dibuktikan, rollback sebelum commit, penerapan atomik, dan pengulangan tanpa duplikasi |
| Migrasi penuh | Lulus: simulasi, rollback sebelum commit, penerapan, metadata manifest, fingerprint hasil, dan pengulangan tanpa duplikasi |
| Tiga arsip sumber | 108.956 pembayaran diperiksa; seluruh 78.603 komponen U_SORGA dipetakan sebagai Komite pada simulasi baru dengan nominal tetap; regresi DU, biaya lain, dan tabungan lulus |
| Chrome desktop | 63 pemeriksaan lulus, tanpa kesalahan JavaScript |
| Edge desktop | 63 pemeriksaan lulus, tanpa kesalahan JavaScript |
| HTML/PDF/Excel | 23 pemeriksaan lulus; nama Komite, nominal struk, total numerik Excel, teks PDF, dan filter penerimaan global sesuai |
| Struk hasil migrasi penuh | HTML dan PDF dari hasil penerapan uji lulus: Komite, total Rp545.000, tanpa kesalahan PHP |
| Kegagalan pembacaan rekap | Lulus: gangguan kolom hanya pada salinan uji tidak menghilangkan 240 transaksi; pesan muat ulang dan total `—` tampil; struktur uji dipulihkan |

Hasil rekap periode 1–22 Oktober 2026:

| Unit | Transaksi | Komponen | Total penerimaan |
| --- | ---: | ---: | ---: |
| SD | 240 | 9 | Rp156.508.200 |
| SMP | 194 | 7 | Rp117.931.300 |
| SMA | 44 | 4 | Rp25.970.000 |
| Semua Unit | 478 | 16 | Rp300.409.500 |

Regresi murni untuk rekonsiliasi migrasi, persiapan operasional, resolusi sumber, pengesahan kelompok, komponen penuh, dan POMG juga lulus. Pemeriksaan sintaks PHP, JavaScript, Python, serta pemeriksaan whitespace Git dilakukan sebelum commit.

Dump baca sebelum dan sesudah pengujian mempunyai hash SHA-256 identik setelah hanya mengabaikan baris waktu selesainya dump: `f933edb15e75a71971c05515272f7d3f053db0289e4bdc2e0a9281bc2419a784`. Ini memverifikasi bahwa skema dan isi database aktif tetap. Dump, sesi, backup pengujian, serta bukti berisi identitas siswa disimpan secara privat di luar repositori.

## Status worker dan penerapan

Tidak ditemukan worker Legacy operasional yang sedang berjalan saat pemeriksaan akhir. Folder importer untuk `db_spp` dan `db_spp_test2` juga tidak memiliki konfigurasi instance atau penanda proses aktif; antrean lokal `db_spp_test2` kosong.

Worker operasional tidak dimulai ulang pada lingkungan ini. Pengaman yang sudah ada pada `scripts/legacy_worker.php` menolak target lokal `db_spp_test2`; pengaman tersebut tidak diubah atau dilewati. Pada lingkungan penerapan, jalankan worker dengan target importer yang memang dikonfigurasi setelah memastikan tidak ada pekerjaan aktif. Worker yang sudah berjalan sebelum kode diperbarui perlu dimulai ulang agar memuat versi 12. Pembaruan ini tidak menjadwalkan migrasi atau mengubah batch yang sudah terbit.

## Berkas pengujian

Pengujian khusus ditambahkan di `tests/legacy_komite_mapping_test.php`, `tests/legacy_komite_transactions_test.php`, `tests/legacy_komite_full_test.php`, dan berkas `tests/komite_components_*`. Pengujian HTTP/ekspor memerlukan sesi privat serta endpoint yang mengarah ke salinan uji; jangan menjalankannya terhadap database aktif. Uji penerapan penuh menggunakan pekerjaan format penuh yang disiapkan oleh uji transaksi Komite pada database uji kosong.
