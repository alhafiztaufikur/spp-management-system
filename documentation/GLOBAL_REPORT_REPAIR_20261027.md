# Perbaikan pembaca Laporan Global — 27 Oktober 2026

Perbaikan A01–A07 memakai bukti audit privat `global_audit_20261027_012314`.
Tidak mengimpor ulang pembayaran atau membuat kas baru.

- PSB: ketidakpastian header tidak menghapus kredit rincian yang masih berlaku.
  Header valid tetap kumulatif dengan delta koreksi; tagihan/sisa belum terbukti
  tetap `null`. Kasir dan laporan menggunakan posisi PSB bersama.
- Filter Ada Pembayaran menggunakan nominal positif, termasuk pembayaran opsional;
  status Lunas tanpa nominal bukan bukti pembayaran.
- Tabungan: kelas sumber menjadi metadata eksplisit untuk tampilan/filter,
  tanpa mengambil master siswa terkini. Label akademik harus cocok utuh dan
  sesuai unit; alias kelompok khusus memakai relasi sumber yang sudah ada.
  Kelas kosong menjadi Tidak tercatat. Saldo antara dengan urutan tidak pasti
  tetap `null`, tanpa mengubah jurnal atau saldo akhir yang terbukti.
- Riwayat tagihan: hubungan tagihan dan kredit dicatat sebelum filter, termasuk
  pembatalan. Filter tidak boleh memunculkan fallback DU di kelas lama.
- Autocomplete mengikuti status Aktif/Arsip/Semua dan unit yang berwenang.
- Tarif SPP individual: readiness memeriksa empat FK dan CHECK aktif secara
  semantik. Installer existing menolak orphan, nominal negatif, konflik tipe,
  aturan referensi, dan nama constraint sebelum DDL. Tidak ada DDL dari HTTP.

## Pemasangan struktur

Jalankan `sql/install_student_spp_tariffs.php` dalam mode preview terlebih dahulu.
Apply memerlukan `--backup-proof` yang lolos `lmr_backup_check()`, yaitu backup
utuh, SHA-256, restore server berbeda, snapshot, checksum dan identitas target.
Ulangi apply: hasil harus `pending: 0`, `changes: 0`, `backfill: 0`.
Installer tidak memperbaiki data bermasalah dengan menghapus atau mengganti nilai.

## Regresi

`tests/global_audit_repair_test.php` membaca CSV kasus privat. Atur:
`SPP_GLOBAL_AUDIT_EVIDENCE`, `SPP_TEST_ISOLATED_INSTANCE`,
`SPP_TEST_ALLOW_MUTATION=1`, serta `SPP_DB_HOST/PORT/NAME` yang cocok manifest.
Mode baseline opsional memakai `SPP_REPAIR_BASELINE=1` dan
`SPP_REPAIR_BASELINE_ROOT` menuju salinan kode lama. Uji pembaca memakai transaksi
read-only; hasil privat disimpan di direktori manifest.

`tests/student_spp_tariff_constraint_repair_test.php` hanya berjalan pada server
terisolasi yang terverifikasi. Uji memasang constraint melalui engine resmi,
mengulang nol perubahan, lalu menggunakan database fixture terpisah untuk
orphan/negatif/konflik dan definisi ekuivalen. Fixture dihapus pada akhir uji.

`tests/psb_inconsistent_header_correction_test.php` menguji koreksi, replay dan
pembatalan melalui layanan resmi pada salinan, lalu rollback seluruh perubahan.

Pemeriksaan ulang meliputi sembilan rekap dan empat cakupan unit, browser,
rekonsiliasi kas/bulanan independen, layanan DU serta ekspor terfokus.
Jangan melonggarkan penjagaan UUID/port test lama agar cocok target lain.

## Pelestarian dan pemulihan

Perubahan operasional default nol; perubahan data Legacy yang kelak diperlukan
tetap melalui runner resmi, preview dahulu, dan bukti terpisah. Periode `20 2020`
serta tabungan tanpa pemilik tetap pengecualian. Riwayat, koreksi, pembatalan,
rekening khusus dan keputusan saldo lama dipertahankan.

Backup, salinan kode sebelumnya, manifest perubahan, hasil regresi dan fingerprint
tersimpan di direktori privat penerapan. DDL MySQL melakukan implicit commit;
catat setiap langkah. Untuk rollback kode, gunakan salinan berkas dari baseline
penerapan yang sama saat akses aplikasi terkunci. Constraint tambahan yang valid
kompatibel dengan kode lama dan tidak memerlukan penghapusan data.
