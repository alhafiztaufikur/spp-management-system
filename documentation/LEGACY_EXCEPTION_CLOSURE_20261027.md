# Penutupan pengecualian Legacy atas keputusan klien

Keputusan pengguna yang menyampaikan persetujuan klien pada 27 Oktober 2026:
59 rincian histori biaya yang belum teridentifikasi diterima sebagai histori
tertutup; dua identitas uji `TEST` pada SMP dan SMA dikeluarkan dari daftar
operasional. Keputusan ini tidak menghapus penerimaan, mengubah nominal, atau
menerbitkan tagihan baru. Identitas `TEST` SD yang sebelumnya sudah disahkan
tidak termasuk keputusan ini.

## Mekanisme

Gunakan `scripts/legacy_migration_runner.php` dan `--settings` berisi objek
`exception_closure`. Jalankan preview terlebih dahulu, uji pada salinan terisolasi,
lalu apply dengan bukti backup/restore terbaru sesuai runbook. Semua perubahan
menggunakan transaksi per unit dan penguncian aplikasi runner existing.

Permintaan berisi `decision_id`, `reason`, serta daftar eksplisit `fees` dan
`owners`. Setiap entri terikat `unit_id`, `batch_id`, dan `source_key` (SHA-256
backup sumber). Entri biaya juga memuat `payment_id`, `version`, `detail_key`,
`row_key`, `column`, `category`, serta `amount`. Entri pemilik memuat `owner_id`,
`identity`, serta `row_key` identitas sumber. Tidak ada pilihan untuk menghapus
seluruh data berdasarkan nama atau kode biaya saja.

- Biaya hanya boleh berstatus `unresolved`, berkategori histori, dan tidak
  terhubung ke tagihan. Hasilnya `status=closed`, `rule=client_accepted_history`,
  label **Biaya Legacy (histori ditutup)**. Kode, slot, nominal, bukti kandidat,
  label semula, serta hash sumber tetap tersimpan. Status ini bukan pembuktian
  nama biaya dan tidak dihitung sebagai rincian belum selesai.
- Pemilik hanya boleh identitas uji yang tertahan, tanpa siswa, rekening,
  pembayaran, jurnal, tarif, kewajiban, maupun baris transaksi sumber. Baris
  operasionalnya dikeluarkan; pending ditandai selesai. Identitas sumber mentah
  tetap utuh. Referensi yang sudah digunakan menolak penghapusan.
- Audit `fee_closure`/`owner_closure` menyimpan aktor, alasan, keputusan, serta
  keadaan sebelum/sesudah. Manifest batch menyimpan keputusan terikat sumber.
  Fingerprint hasil diperbarui dan keputusan diverifikasi terhadap audit.
- Pembaca draf menggabungkan keputusan dari manifest yang telah diterapkan,
  sehingga cache GUI dan simulasi ulang tidak menghidupkan temuan yang ditutup.
  Penutupan tidak menambahkan menu atau tombol baru.
- Apply ulang permintaan yang sama wajib nol perubahan. Perubahan permintaan,
  versi awal, nominal, atau sumber ditolak. `verify` tetap memeriksa audit,
  penerimaan, tabungan, dan arsip asli.
- Koreksi/pembatalan struk melalui layanan koreksi existing tetap diakui setelah
  penutupan. Keadaan struk harus tepat cocok dengan audit koreksi terbaru yang
  dibuat sesudah audit penutupan. Perubahan tanpa audit tetap ditolak; keputusan
  penutupan tidak menghidupkan kembali struk yang sudah dibatalkan secara sah.

## Verifikasi

`tests/legacy_exception_closure_test.php` menguji perlindungan nominal/sumber,
status tertutup, seleksi identitas, serta cache yang idempoten. Pengujian transaksi
`tests/legacy_exception_closure_transactions_test.php` wajib berjalan hanya pada
salinan dengan penjagaan UUID, port, dan datadir. Permintaan diberikan melalui
`SPP_EXCEPTION_CLOSURE_REQUEST`; perubahan pengujian di-rollback.
`tests/legacy_exception_closure_correction_test.php` memeriksa pembatalan,
pemulihan, penolakan perubahan tanpa audit, dan replay setelah koreksi pada
fixture terpisah dengan penjagaan port/UUID/database; seluruh perubahan rollback.

Artefak penerapan, backup, pengujian, dan checksum berada di luar webroot:
`C:/laragon/data/spp-migrations/db_spp/20261027-close-exceptions/`.
