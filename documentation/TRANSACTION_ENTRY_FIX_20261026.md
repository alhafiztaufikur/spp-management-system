# Perbaikan input transaksi — 26 Oktober 2026

Tanggal pada Input Pembayaran, Tabungan Masuk, dan Tabungan Keluar dapat dipilih
manual, termasuk tanggal lampau dan mendatang. Nilai awal tetap hari ini di
Asia/Jakarta. Hari kas divalidasi ketat dan tidak mengubah periode tagihan.
Tanggal pilihan dipulihkan bersama draft apabila penyimpanan gagal atau halaman
formulir dimuat ulang melalui pemulihan draft yang tersedia.

Pilihan siswa pada formulir input/edit pembayaran memakai nama dan NIS yang
unik. Klik/keyboard meneruskan opsi terpilih; nama ambigu tidak memilih siswa
pertama. Aditya Pratama `252607102` tetap 8C dan `242507056` tetap 9C.
Keterangan SPP/Komite cukup menyebut komponen dan periode. Untuk Achmad Zaky
`242507088`, SPP November 2026 tetap bersisa Rp655.000; perbaikan label tidak
mengubah nominal atau aturan pelunasan.

## Penyesuaian pengaman tanggal

Pengujian menemukan tiga trigger `lful_cutoff_*` yang juga melarang tanggal kas
sebelum peralihan. Penyesuaian diperlukan pada trigger INSERT `bayar_data`,
`transaksi_m_data`, dan `transaksi_k_data`, tanpa perubahan tabel atau data.
`transaction_entry_date_schema_upgrade()` di
`includes/transaction_entry_date.php` memperbarui ketiganya melalui CLI secara
idempoten; pemasangan skema Legacy existing juga memanggil fungsi ini.

Pengecualian hanya berlaku untuk timestamp yang ditetapkan sementara oleh
`transaction_entry_insert()` selama INSERT native yang sudah divalidasi.
Timestamp dibersihkan pada keberhasilan maupun kegagalan. Gerbang unit,
identitas siswa, status migrasi, cakupan tagihan, audit, dan pengaman transaksi
ganda tetap aktif. Flag penerapan migrasi `@legacy_full_apply` tidak digunakan
oleh alur input baru. Koreksi transaksi existing mempertahankan validasinya.

## Hasil verifikasi

- Backup database aktif dipulihkan pada MySQL lain dengan UUID, port, dan datadir
  berbeda serta event scheduler OFF. Checksum seluruh 62 tabel cocok.
- `tests/transaction_entry_date_test.php`: 28 pemeriksaan kalender, tipe input,
  jam server, retry, dan pemulihan tanggal/periode.
- `tests/transaction_entry_browser_test.js`: 253 pemeriksaan browser/HTTP pada
  tiga unit, termasuk Aditya, label tagihan, draft, tanggal 2020/2026/2028/2030,
  tabungan siswa/non-siswa, saldo, struk, input tanggal tidak valid, dan retry.
- `tests/transaction_entry_fixture.php guard-tests`: pengecualian timestamp
  terbatas, ID hasil INSERT, pembersihan flag, penolakan lintas unit, dan penolakan
  saat migrasi belum selesai; seluruh penulisan pengujian ini di-rollback.
- Sebanyak 41 pembacaan laporan pembayaran dan tabungan cocok dengan tanggal kas
  tersimpan. Pengiriman melalui tombol Simpan pada antarmuka pembayaran dan
  tabungan non-siswa juga lulus dengan tanggal custom tanpa galat JavaScript.
  Pengujian waktu transaksi, format tanggal, pesan gagal pembayaran,
  status SPP, serta rumus/urutan SPP existing juga lulus.
- Penyesuaian database aktif hanya mengubah tiga trigger tanggal; checksum
  seluruh 62 tabel tetap sama dan penerapan ulang menghasilkan nol perubahan.

Dump, bukti restore/identitas, checksum sebelum/sesudah, rincian trigger,
hasil browser, dan tangkapan layar disimpan di direktori privat
`C:/laragon/backups/spp-management-system/transaction_entry_20261026_7d79aa55/`.
Pengujian tulis tidak dijalankan pada database aktif.
