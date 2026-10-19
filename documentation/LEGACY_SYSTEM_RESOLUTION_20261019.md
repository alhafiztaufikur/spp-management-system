# Penyelesaian sistem untuk pengecualian Migrasi Legacy

## Kebijakan yang disahkan

Pengguna meminta penyelesaian otomatis untuk 18 catatan tersisa pada pekerjaan SD171026. Perubahan memakai aturan operasional 7 dan workflow 8, tanpa skema MySQL baru. Database utama, data lab, penempatan, pemetaan komponen, koreksi jurnal dan saldo FIX yang sudah tersimpan dipertahankan.

| Jenis temuan | Penyelesaian |
| --- | --- |
| Jurnal masuk/keluar bernilai nol | Referensi Jurnal Nol; raw, tanggal dan row key tetap ada. Tidak dibuat mutasi uang baru. Koreksi jurnal yang sudah disahkan tetap digunakan. |
| Residu floating point pada saldo | Normalisasi decimal-string hanya bila jarak ke dua desimal maksimal Rp0,000001 dan nominal dua desimal tepat sama dengan hasil jurnal. Nilai mentah tetap ada di arsip/manifest. Selisih nyata atau jurnal tidak valid tetap ditinjau. |
| Header rekening unik berbeda dengan jurnal yang valid | Saldo akhir menggunakan SALDO backup. Selisih terhadap jurnal menjadi Penyesuaian Saldo Migrasi dengan bukti keputusan sistem dan audit, bukan kas baru. Saldo FIX manual selalu didahulukan. |
| Rekening tambahan unik tanpa rekening utama | Jika identitas tunggal dan rekonsiliasi lulus, rekening utama disiapkan dari ledger tersebut. Row key/jurnal dipakai sekali; tidak membuat setoran kedua atau mengganti rekening utama existing. |
| DU bernominal negatif | Referensi Anomali DU. Nominal minus dan tanggal/ketiadaan tanggal tetap asli; tidak menjadi penerimaan, refund, atau tagihan. |
| Sumber tanpa identitas 2020+ atau tanpa tanggal | Catatan Belum Teridentifikasi. Tetap belum memiliki pemilik, disimpan terpisah dari saldo/kas aktif dan tidak menghalangi data yang siap. |
| Sumber tanpa identitas bertanggal sebelum 2020 | Tetap Histori Legacy Tanpa Pemilik menurut kebijakan tanggal yang telah disahkan sebelumnya. |

Aturan tidak menggabungkan identitas ambigu, membuat NIS palsu, menebak tanggal, menutup tunggakan yang belum terbukti, atau mengubah sumber mentah. Pembaruan ini menyelesaikan temuan yang memiliki dasar aturan; masalah lain pada sumber lain tetap ditampilkan.

## Tampilan dan penerapan

- Jalankan ulang simulasi agar versi keputusan dan konfirmasi memakai aturan terbaru. Persetujuan final tetap melalui alur migrasi existing.
- Direktori tabungan menampilkan rekening siap berikut saldo akhirnya. Dasar pemeriksaan tersedia pada ringkasan penyelesaian sistem. Koreksi eksplisit selanjutnya memakai Jumlah Sebenarnya atau alur koreksi rekening sesudah publikasi.
- Daftar terpisah mendukung pencarian, tanggal, rincian dan CSV. Nilai tanpa tanggal tampil Tanggal tidak tercatat dan tidak dimasukkan ke periode tertentu. Nilai kosong tidak diubah menjadi nol.
- Keputusan disimpan pada digest simulasi dan manifest JSON batch: row key referensi, presisi awal/akhir, saldo header/jurnal, serta fingerprint ledger yang dipromosikan. Raw archive tetap menjadi bukti.
- Kontrak API existing tetap berlaku; summary menambah separate_references, unidentified_records, du_anomalies, zero_references, normalized_balances, source_balance_fixes dan promoted_accounts.
- Catatan terpisah tidak berarti dana atau kewajiban dihapus. Penyelesaian identitas di masa depan memerlukan pencocokan yang dapat dipertanggungjawabkan; tahap ini tidak memasukkan saldo anonim ke rekening aktif.

## Pengujian

- Enam ekstraksi 2019/2026: jumlah pembayaran dan total pembayaran tidak berubah. Regresi identitas/periode, komponen, penyesuaian, pemetaan, saldo FIX existing dan raw preservation lulus.
- Presisi Rp0,10000000009313226 menjadi Rp0,10, Rp0,39999999990686774 menjadi Rp0,40, dan Rp0,099999994039535522 menjadi Rp0,10 ketika cocok jurnal. Rp0,10004 tetap ditahan karena melampaui toleransi.
- Unit-test memeriksa saldo FIX naik/turun, saldo manual tetap didahulukan, tanggal invalid, jurnal nol, tidak ada penggandaan rekening tambahan, serta hasil ulang deterministik.
- Publikasi fixture, rollback sebelum commit, retry, audit, arsip dan register sesudah publikasi, laporan tanpa kas baru serta backup/pemulihan dijalankan pada instance MySQL terisolasi di loopback. Tidak ada publikasi pada db_spp atau db_spp_test2.
- HTTP/browser mencakup peran/unit/CSRF, konfirmasi, daftar terpisah/CSV, rekening promosi, Chrome terpasang dan Chromium 109, desktop/ponsel, JavaScript aktif/nonaktif. Perangkat Windows 7 fisik belum diuji.

## Hasil simulasi ulang lab copy

Pekerjaan SD171026 pada lab copy sudah disimulasikan ulang dengan aturan 7. Draf tetap versi 7 dengan seluruh pengaturan dan 11 saldo FIX manual tidak berubah; pekerjaan lainnya juga tidak berubah.

- 18 catatan yang dibahas telah ditangani: **0 catatan tertahan dan 0 kelompok masalah**.
- 46.852 pembayaran, total **Rp38.123.313.002,00**, tetap sama; 7.881 pembayaran dengan rincian Lain-Lain diperiksa sistem tanpa penghitungan ganda.
- Saldo peralihan hasil simulasi **Rp594.880.495,60**. Pemeriksaan mencatat 3 normalisasi presisi, 6 saldo akhir sumber, dan 1 rekening tambahan yang siap menjadi rekening utama. Sebanyak 17 penyesuaian terdiri dari 11 keputusan manual existing dan 6 keputusan sumber otomatis.
- 8 catatan referensi terpisah: 3 belum teridentifikasi, 3 anomali DU, dan 2 jurnal nol. Sebanyak 16 histori sebelum 2020 tetap tersedia. Pemilik atau tanggal yang tidak tersedia tidak dibuat-buat.
- Kewajiban yang belum dibuktikan tidak dibuat menjadi tagihan dan tidak dianggap lunas. Penyelesaian temuan ini tidak mengesahkan utang yang belum tersedia buktinya.
- Ringkasan, daftar histori/referensi, CSV, serta rekening utama hasil pemeriksaan telah diperiksa melalui aplikasi lab copy. Konfirmasi final migrasi belum dijalankan.

Tidak ada commit/push, migrasi final pengguna, penghapusan data, pembaruan deployment, instalasi browser atau pembersihan runtime importer.
