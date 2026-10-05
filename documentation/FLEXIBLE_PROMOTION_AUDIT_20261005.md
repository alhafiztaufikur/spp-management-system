# Kenaikan fleksibel — 5 Oktober 2026

## Status dan cakupan

Implementasi diuji pada clone db_spp_audit_promotion_20261005. Penerapan lokal dicatat setelah penggabungan dan pemeriksaan akhir. Tidak ada migrasi skema atau kenaikan siswa sungguhan sebagai fixture. Railway tidak diterapkan pada pekerjaan ini. Backup, hash, screenshot, PDF dan hasil rinci disimpan di C:\laragon\backups\spp-management-system\flexible_promotion_20261005, di luar Git.

## Temuan dan perubahan

Pengunci kelas tertinggi ditemukan pada tampilan Master Kelas serta helper batch, individual, dan massal. Query asal sebelumnya sudah memakai sebagian penempatan tahun asal, tetapi UI/POST masih menurunkan tahun proses dari kalender dan mengunci satu tahap global. Data utama saat diperiksa memiliki 210 penempatan pada 2026/2027; tidak memiliki hasil kenaikan untuk membuktikan bahwa looping pernah terjadi pada data tersebut. Regresi membuktikan kasus yang dilaporkan dengan fixture terisolasi.

- Pilih tahun asal yang tersedia pada unit, tingkat asal, dan rombel. Semua tingkat dapat diproses tanpa menunggu tingkat lainnya. Kenaikan tetap satu tingkat; kelulusan hanya tingkat terakhir unit.
- Tahun asal berjalan/lampau; tujuan tepat tahun berikutnya. Tahun tujuan belum menjadi asal sampai kalender tahun tersebut berjalan. Tidak beralih tahun/tingkat otomatis setelah submit.
- Tahun dan tingkat mengubah daftar melalui GET; kelas, pencarian, rombel dan konteks tetap setelah hasil batch. Konfirmasi menyatakan tahun asal/tujuan atau tahun kelulusan. Semua Unit tetap baca.
- POST membutuhkan source_year_id, source_level, source_placement_id dan target_tahun_ajaran; tahun/unit dan pasangan penempatan diperiksa ulang. Form lama tidak ditebak.
- Peserta aktif/non-Legacy/non-PSB, source aktif dan terbaru, tanpa penempatan tujuan/lebih baru; kelas aktif serta rombel asal konsisten. Data bertentangan ditahan dengan alasan.
- Kenaikan source pindah → satu penempatan target aktif, kelas aktif langsung berubah. Kelulusan source lulus → siswa tidak aktif, tanpa penempatan target fiktif.
- Satu core transaksi untuk individual/batch/massal. Actor → student PK order → target class ID order → placement/year; validasi locking read melihat perubahan committed. Savepoint per siswa; deadlock/timeout membatalkan dan mengulang seluruh transaksi maksimal tiga kali.
- Audit existing mencatat actor/unit/student, source/target IDs dan tahun/kelas, serta tarif hasil. Tidak menulis ulang pembayaran atau snapshot source.
- Tarif tujuan berdasarkan master tahun/tingkat dan potongan nominal. Master/tarif belum tersedia: informasi aktif/snapshot nol, status Belum disiapkan. Tidak menyalin tarif source menjadi tunggakan perkiraan.
- Komite tetap mengikuti penerbitan existing sesudah kenaikan resmi; SPP/DU melalui master. Default rombel mengikuti kode aktif yang sah, termasuk transfer rombel, atau memerlukan pilihan eksplisit.
- Massal memakai kandidat asal yang dibekukan, bukan loop terhadap kelas aktif yang terus berubah. Helper highest tetap informasi kompatibilitas, bukan pengunci mutasi.

## Bukti regresi

| Area | Bukti |
|---|---|
| Fleksibilitas tiga unit | Penultimate naik saat senior pending; tingkat kecil naik sebelum tingkat besar; dua kenaikan terpisah; lulus senior sebelum/sesudah kenaikan lain |
| Tidak looping | Baru menjadi 6/9/12 tidak tampil pada kelulusan source lama; replay/ID stale ditolak; future source ditolak; next cycle diterima saat fake calendar memang maju |
| Riwayat invalid | Tidak ada source, Legacy/PSB/archive, active class/source rombel salah, penempatan lebih baru, forged ID, target lintas unit/sama tingkat ditahan tanpa writes |
| Race | Dua kasir/two HTTP servers/same source: satu destination dan satu audit sukses. Deaktivasi target individual/bulk sesudah snapshot awal ditolak |
| Tarif/histori | Master tujuan + nominal discount; no master nol; asal immutable; edit sesudah kenaikan, perpindahan rombel berbayar dan massal menuju rombel aktif benar |
| Siklus | Reguler dan PSB SD/SMP/SMA sampai kelulusan dengan source context eksplisit, pembayaran dan laporan sepanjang perjalanan |
| Rekap | Tahun asal/tujuan, graduation active/archive/all, dua tahun Per Item, SPP cancelled/unissued, class transaksi sebelum/sesudah; sumber/layar/Excel/PDF biner cocok |
| Keuangan | Pembayaran/otorisasi, replay/CSRF/rollback, races SPP/Komite/DU/pembayaran/Tabungan; jurnal dan catatan aman |
| Akses | 307 role/unit/anonymous/CSRF checks; akun nonaktif, role/unit berubah, direct foreign IDs dan All-unit writes ditolak |
| UI | 60 keadaan (scope 0 satu view + tiga unit masing-masing tiga tingkat, dua tema, 1440/2560/390); free level selection, dialogs, search/context retention, no looping, no JS/overflow |
| Syntax/integritas | PHP/JS lint, CSS parser isolated page + reference shared CSS, health, 30 invariant, FK |

Fixture lama future-year CLI diberi clock audit eksplisit; production tetap memakai kalender WIB. Assertion lama yang mengharapkan global-stage exception diubah menjadi hasil siswa ditolak. Gagal fixture ekspor karena belum mempersist fixture historis dijalankan ulang setelah prasyarat dipenuhi. Gagal assertion leading whitespace pencarian disesuaikan dengan normalisasi trim; tidak mengubah data utama. Pengujian ulang UI memakai fixture baru, tidak menaikkan siswa yang sama dua kali.

## Operasi dan batas

### Rujukan pengujian

- `tests/flexible_promotion_test.php`: urutan bebas, batas Juli/Januari WIB, tarif dan snapshot; transaksi fixture dibatalkan.
- `tests/flexible_promotion_http_test.php`: konteks HTTP, tahun lampau/mendatang, replay, tanpa master; dijalankan pada ketiga unit. Setiap pengulangan memilih jendela tahun fixture yang belum dipakai supaya tidak mewarisi master dari tes siklus lain.
- `tests/flexible_promotion_invalid_test.php`, `flexible_promotion_race_http_test.php`, `class_promotion_rombel_race_test.php`: data invalid dan proses bersamaan.
- `tests/full_student_lifecycle_http_test.php`, `psb_to_regular_http_test.php`: perjalanan reguler/PSB tiga unit sampai kelulusan.
- `tests/historical_reports_after_promotion_test.php`, `report_crossunit_exports_http_test.php`, `all_units_reports_test.php`, `all_units_exports_http_test.php`: sumber, layar, Excel, PDF dan identitas sekolah.
- `tests/flexible_promotion_browser_test.js`: matriks visual serta interaksi. Entry point lama `promotion_browser_flow.js` dan `promotion_browser_fixture.php` diperbarui; verifier terakhir membuktikan status asal, kelas aktif dan jumlah penempatan setiap fixture di tiga unit.
- `tests/endpoint_access_matrix_http_test.php`, `session_account_refresh_http_test.php`, `financial_mutation_guard_http_test.php`, `academic_payment_race_http_test.php`, `payment_role_access_test.php`, `savings_note_http_test.php`: akses dan regresi keuangan.
- `tests/readiness_integrity_audit.php`, `ui_css_regression_test.js`, PHP lint dan Node syntax: integritas serta sintaks. Artefak browser privat memuat 60 screenshot clone dan hasil JSON.

Pada 6 Oktober, ulang tes HTTP sempat menemukan master dari fixture siklus sebelumnya pada tahun yang diharapkan kosong. Ini kegagalan prasyarat tes, bukan bug aplikasi: fixture dipisahkan ke jendela tahun baru, lalu ketiga unit lulus kembali. Tidak ada tarif atau data utama yang dihapus untuk memenuhi tes.

Gunakan Master Kelas: pilih unit → tahun asal → tingkat → rombel/pencarian → siswa/rombel tujuan → konfirmasi. Jika riwayat/kelas aktif bertentangan, periksa audit dan Data Siswa; sistem tidak memperbaiki histori melalui tebakan.

Penempatan tahun mendatang boleh dibuat secara resmi untuk pembayaran setelah master diterbitkan, tetapi belum dapat digunakan sebagai asal tahun berikutnya. Riwayat dimulai dari penempatan pertama yang diketahui. Tidak ada tinggal kelas/lompat tingkat atau perubahan asal PSB.

Backup hashed dan restore clone diverifikasi sebelum pekerjaan. Database utama dibandingkan seluruh tabel terhadap baseline; hasil akhir dan cleanup hanya objek milik audit dicatat di bawah. Kesiapan deployment Railway atau migrasi histori keuangan Legacy tidak dinyatakan oleh pengujian ini.

## Penerapan akhir

Belum dicatat pada commit persiapan. Rincian ini diperbarui setelah kode dipindahkan ke main, health/integritas dan fingerprint utama diverifikasi.
