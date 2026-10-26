# Penerbitan, kredit pembayaran, dan struk Legacy

Perbaikan 20 Oktober 2026 untuk SD, SMP, dan SMA. Tidak mengulang migrasi, menyalin pembayaran ke tabel native, menerbitkan kas migrasi, atau mengubah skema.

## Perilaku aplikasi

- Status tahun ajaran **Terbit** tidak lagi dianggap bukti bahwa seluruh tagihan DU sudah terbentuk. **Master Daftar Ulang → pilih tahun → Simpan Tarif & Lengkapi Tagihan** hanya menambahkan tagihan yang belum tersedia berdasarkan penempatan dan bukti tarif. Pengulangan tidak menggandakan tagihan.
- Master SPP membedakan tagihan baru, tagihan existing, serta periode sebelum peralihan/tercakup Legacy. Batas peralihan November 2026 dipertahankan; tidak mengubah kelas atau menaikkan siswa otomatis.
- Tarif awal tetap Legacy. Perubahan master yang benar-benar disimpan sesudah perbaikan dicatat sebagai aturan tahun/tingkat dengan versi audit. Tagihan tanpa pembayaran mengikuti perubahan; cicilan native maupun Legacy mempertahankan snapshot. Aturan tahun juga berlaku ketika penempatan sasaran baru tersedia kemudian.
- Potongan individual hanya dihitung sekali. Penggantian master DU mempertahankan nominal potongan pada snapshot. Pengeditan identitas siswa saja tidak mengganti dasar DU Legacy dengan master lama.
- PSB membaca `tot_pangkal - potong_pangkal = PANGKAL`, serta header kumulatif `PANGKAL_BAYAR`. Header tersebut sudah mencakup rincian pembayaran: rincian tidak dijumlahkan lagi. Koreksi menambahkan selisih terhadap rincian awal yang tersimpan pada audit.
- DU memakai rincian kategori DU, atau total transaksi `bayar_du` yang memang khusus DU. Pembayaran campuran tidak dihitung seluruhnya sebagai DU. Tarif tahun/kelas sumber harus tunggal, periode sesuai tahun peralihan terbaru sumber, dan potongan eksplisit. Header siswa bukan bukti potongan tahun historis lainnya.
- Kredit SPP dicocokkan dengan bulan/tahun pembayaran asli. Termasuk pembayaran sumber untuk November 2026 sampai Juni 2027 yang sudah mempunyai tagihan operasional. Kredit ini mengurangi sisa dan mengunci snapshot; tidak membuat pembayaran native atau penerimaan kas baru.
- Pembayaran, koreksi, pembatalan, dan audit memperbarui sisa kewajiban dalam transaksi yang sama. Retry dan versi usang tetap mengikuti mekanisme existing.
- Input Pembayaran membawa `_operational_unit` eksplisit. Unit berbeda ditolak HTTP 409 dengan halaman pemulihan; isian disimpan untuk unit asal. CSRF tetap wajib. Tanpa JavaScript tersedia pemilih siswa/periode server dan formulir ke endpoint yang sama.
- Data Siswa menyediakan tombol **Pembayaran · jumlah** dan **Tabungan · jumlah**; kartu ponsel memakai alur vertikal tanpa tumpang tindih.
- Semua struk siswa menampilkan **Sisa PSB** dan **Sisa DU** terkini. Tahun DU mengikuti periode transaksi, bukan tanggal cetak. Bukti tidak lengkap ditulis **Belum dapat ditentukan**, pemilik non-siswa **Tidak berlaku**. Nominal/tanggal/operator sumber tetap asli.

## CLI

Pratinjau tidak mengubah data:

```powershell
php scripts/reconcile_legacy_billing.php --database=db_spp_test2 --port=3306
```

Penerapan lab setelah backup berhasil dipulihkan dan diperiksa:

```powershell
php scripts/reconcile_legacy_billing.php --database=db_spp_test2 --port=3306 --apply --backup="C:/lokasi/backup.sql" --output="C:/lokasi/hasil.json"
```

Opsional `--unit=1|2|3` dan `--actor=<id Super Admin aktif>`. Target koneksi aktual diperiksa ulang setelah bootstrap konfigurasi; perbedaan database/port menghentikan CLI. `db_spp` tidak dapat menjadi target. Tidak ada DDL melalui web atau CLI ini.

Transaksi per unit membentuk kewajiban dan tagihan yang terbukti, mengaudit setiap perubahan, serta memperbarui digest kerja. Sumber, draf, FIX, rekening, dan akun tidak ditulis ulang. Aturan tarif baru disimpan pada audit existing, karena arsip `legacy_full_students` memang tidak boleh diubah.

## Pemeriksaan dan bukti

Bukti lokal: `C:/laragon/backups/spp-management-system/legacy_billing_repair_20261020`.

- Backup SQL 454.669.284 byte dipulihkan ke MySQL terisolasi port 58246; hash pada `backup-verification.json`.
- Pengujian mencakup kelengkapan tahun terbit, retry, rollback, kredit kumulatif, periode sumber, snapshot cicilan, perubahan master/potongan, pembatalan, CSRF, dan unit antar-tab. Hasil tersimpan pada `backend-final.json`, `tariff-final.json`, `spp-credits.json`, `native-compat.json`, dan `readback.json`.
- Chromium **109.0.5412.0** benar-benar dijalankan secara headless: SD/SMP/SMA, tema terang/gelap, desktop/ponsel/tablet, keyboard, formulir JavaScript dan tanpa JavaScript, serta zoom CSS 200%. Screenshot akhir dan hasil pada `browser.json` / `final-ui.json`.
- PDF struk ketiga unit dibuka kembali, diperiksa teks PSB/DU dan dirender untuk inspeksi. Excel DU dibaca kembali untuk identitas, tahun, dan nominal numerik. Bukti pada `exports-readback.json`.
- Batas pengujian: printer fisik, sesi browser harian pengguna, dan zoom native browser interaktif tidak dijalankan. Zoom 200% diuji sebagai reflow CSS, bukan kontrol zoom jendela pengguna.

Data dengan tarif/periode/kelas/potongan tidak lengkap tetap **Belum dapat ditentukan**; bukan nol atau lunas. Kelompok ini tidak menghalangi penerbitan tahun berikutnya dengan penempatan dan tarif yang sah. Tidak mengarang tagihan historis bagi Alumni, OUT, PSB, atau kelas khusus.

Hasil pemasangan dan perbandingan fingerprint dicatat pada `LEGACY_BILLING_REPAIR_20261020.json` setelah penerapan selesai. Tanpa commit, push, deployment, atau finalisasi migrasi ulang.

## Hasil pemasangan lab

- **574** tagihan DU dilengkapi (SD 330, SMP 198, SMA 46); satu tagihan existing dikoreksi ke snapshot Legacy. Total kini 575 tagihan DU.
- **3.008** kewajiban terbukti tercatat: 2.282 PSB, 575 DU, dan 151 bulan SPP yang sudah dibayar dalam Legacy. Kas/pembayaran native tidak ditambah.
- Penerapan ulang menghasilkan **0 perubahan** pada setiap unit.
- **207** siswa kelas awal (SD kelas 1: 83; SMP kelas 7: 103; SMA kelas 10: 21) belum memiliki dasar DU sumber lengkap. Sistem tidak menebak bahwa PSB mencakup DU, tidak menetapkan nol, dan tidak menggandakan tagihan. Penerbitan tahun berikutnya tetap tersedia dengan penempatan/tarif yang sah.
- Lulus 103 pemeriksaan baru terkait billing, 6 kompatibilitas native, 54 pembacaan migrasi, 7 alur HTTP terisolasi, 13 pemeriksaan isi halaman lab terpasang, serta 51 kasus Chromium 109. PDF/Excel tiga unit dibuka kembali dan diperiksa.
- Fingerprint 51 tabel `db_spp` tetap sama. Di `db_spp_test2`, hanya `legacy_op_obligations_data`, `tagihan_daftar_ulang_data`, `legacy_op_audit_data`, dan `legacy_full_batches_data` yang berubah; pada manifest hanya `working_digest` berubah. Seluruh pembayaran, rekening/jurnal/saldo FIX, arsip, draf, akun, dan konfigurasi tetap sama.
