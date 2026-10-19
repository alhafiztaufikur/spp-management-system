# Hasil migrasi SMA — 20 Oktober 2026

Migrasi penuh operasional dijalankan sampai selesai di **lab copy / `db_spp_test2`**, berdasarkan instruksi pengguna untuk menerapkan backup SMA melalui sistem existing tanpa konfirmasi tambahan. Database utama tidak menjadi tujuan migrasi.

## Sumber dan hasil

| Pemeriksaan | Hasil |
| --- | --- |
| Backup | `sma17102026`, basis Legacy `smait_mh` |
| SHA-256 backup | `0b729e56d83fb6cacea11fa826fceedf74021daeebe0ed068f18facdce12aaac` |
| Profil pembaca | `legacy-2026-sma`, unit 3 |
| Pekerjaan | `77010ef091594597a7359c2e47a09ea6` |
| Sumber diarsipkan | 22.236 baris, termasuk 397 identitas master sumber |
| Status akhir | `imported` / batch `completed` |
| Waktu selesai | 20 Oktober 2026, 00:25:09 WIB |
| Siswa operasional | 252, termasuk 67 aktif |
| Pemilik non-siswa/arsip dengan ID internal | 528; bukan NIS siswa baru |
| Pembayaran operasional | 11.211, total Rp11.305.022.201 |
| Jurnal tabungan operasional | 8.938 |
| Rekening tabungan | 380 |
| Total saldo peralihan/berjalan | Rp115.108.300 |
| Pengecualian tertahan | 0 |

Sumber memiliki 10.071 pembayaran utama dan 1.143 DU. Tiga DU negatif tetap menjadi referensi anomali sesuai aturan existing; tidak diperlakukan sebagai kas masuk atau pengembalian uang. Catatan anonim, jurnal nol dan tanggal yang tidak tersedia tetap mengikuti pemisahan histori/referensi existing. Sumber asli seluruhnya dipertahankan.

## Penyelesaian yang diperlukan

- Identitas `TEST` disimpan sebagai pemilik rekening nonakademik menggunakan ID internal. Fingerprint kelompok sumber numerik `1` pada draf disahkan menggunakan kode string sumber yang sama dengan pemeriksaan kepemilikan. Tidak dibuat siswa atau NIS pengganti.
- Ditemukan dua header tabungan milik satu identitas unik: Rp1.000 dan Rp0. Delapan jurnal valid menghasilkan masuk Rp533.000 dan keluar Rp532.000, tepat cocok dengan header Rp1.000. Header nol tetap berada dalam arsip sumber; satu rekening operasional meneruskan Rp1.000, tanpa jurnal atau saldo tambahan.
- Penanganan header nol dibatasi pada satu pemilik sumber unik, tepat dua header (satu nol, satu positif), dan seluruh jurnal valid yang cocok persis dengan header positif. Header positif yang saling berbeda, lebih dari dua header, saldo/jurnal tidak valid, pemilik ganda, serta selisih yang belum terbukti tetap ditahan. Bukti kedua baris dan keputusan tersimpan pada manifest resolusi.
- Aturan migrasi menjadi versi 10. Perubahan dibatasi pada peninjauan/resolusi Legacy dan uji terkait; tidak mengubah perhitungan fitur pembayaran, tabungan baru, atau skema database. Worker lab dimulai ulang saat antrean masih idle, sebelum finalisasi SMA.

## Verifikasi dan perlindungan data

- Menggunakan alur existing: enqueue backup, ekstraksi worker, pengesahan massal, penyimpanan draf, simulasi, konfirmasi final, penerapan atomik, dan pemeriksaan hasil/backup oleh worker. Draf berakhir pada versi 3; tidak menulis transaksi operasional secara manual.
- Backup sebelum `c199b80f789104856c53804db77865bc` serta sesudah `6a652cae15c77ac7b16bb30f1ca7260c` berhasil dipulihkan dan terverifikasi.
- Jumlah/fingerprint arsip 22.236 baris cocok dengan sumber. Jumlah dan nominal pembayaran/jurnal, saldo, sumber kedua header, pemilik testing serta seluruh 252 dasar tarif SPP diperiksa. Jumlah jurnal bersih sama dengan saldo peralihan; kedua header asli tetap tersedia.
- Pembayaran/jurnal migrasi menggunakan representasi operasional Legacy. Tidak ada penerimaan baru pada tabel kasir `bayar`, `bayar_du`, `transaksi_m`, `transaksi_k`, dan tidak ada jurnal Legacy berjenis penerimaan baru.
- Fingerprint seluruh **51 tabel database utama** identik sebelum/sesudah. Baris unit SD/SMP pada **57 tabel berunit di lab** juga identik. Konfigurasi, arsip, data unit lain dan fitur di luar migrasi dipertahankan; tanpa commit/push/deployment.
- GET pada halaman hasil migrasi, rekening, formulir koreksi pembayaran, Master Kelas, Data Siswa, serta riwayat pembayaran unit SMA semuanya HTTP 200 tanpa fatal/warning/notice. Pemeriksaan web memakai sesi baca sementara yang kemudian dihapus; tidak mengoreksi transaksi pengguna untuk pengujian.
- Uji `tests/legacy_zero_header_test.php`, regresi resolusi, saldo FIX presisi, dan pengesahan massal lulus. Kasus penolakan pemilik/header ambigu, tiga header, dua saldo positif, tanggal/nominal salah, saldo tidak cocok, urutan sumber dan retry diuji tanpa mengubah database pengguna.

Bukti privat, laporan, fingerprint, backup draf dan hasil halaman tersimpan di `C:\laragon\backups\spp-management-system\sma_migration_20261020`. File backup Legacy asli tidak diubah.

Untuk menggunakan hasilnya, buka lab copy dan pilih **unit SMA**. Siswa, pembayaran serta tabungan sudah menjadi data kerja; koreksi Legacy menggunakan hak Super Admin dan mekanisme koreksi existing.
