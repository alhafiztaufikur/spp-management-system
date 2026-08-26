# Profil dan Rencana Migrasi Tipe Uang

Profil dijalankan read-only pada database audit anonim tanggal 20 Agustus 2026 melalui `sql/profile_money_columns.sql`.

## Hasil profil

| Area | Kolom legacy `DOUBLE` | Row terprofil | NULL | Negatif | Lebih dari 2 desimal | Maksimum teramati |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Header `bayar` | 15 | 16 per kolom | 0 | 0 | 0 | Rp3.600.000 (`total_jumlah`) |
| Cache `tabungan` | 1 | 5 | 0 | 0 | 0 | Rp250.000 |
| Jurnal masuk `transaksi_m` | 2 | 7 per kolom | 0 | 0 | 0 | Rp200.000 |
| Jurnal keluar `transaksi_k` | 2 | 0 | tidak berlaku | tidak berlaku | tidak berlaku | tidak tersedia |

Total ada 20 kolom uang bertipe floating point. Snapshot ini tidak mempunyai nilai negatif, NULL, atau pecahan lebih dari dua desimal. Nilai maksimum teramati jauh di bawah kapasitas `DECIMAL(15,2)`, tetapi dataset audit kecil dan tidak membuktikan seluruh histori client.

## Rencana migrasi yang aman

1. Jalankan profiler pada snapshot produksi terbaru dan seluruh generasi database legacy yang didukung.
2. Tolak migrasi bila ada NULL, nilai negatif yang tidak diizinkan, pecahan lebih dari dua desimal, nilai nonfinite, atau nilai di luar range target. Jangan melakukan `ROUND()` diam-diam.
3. Rekonsiliasi control totals per kolom, `total_jumlah`, saldo tabungan, dan seluruh INV sebelum DDL.
4. Pada database disposable, ubah kolom ke `DECIMAL(15,2) NOT NULL DEFAULT 0.00` dalam maintenance window; ukur lock dan durasi.
5. Jalankan schema/data verifier, parity laporan/export, create/edit/delete/reversal, concurrency, dan fingerprint nilai `CAST(value AS DECIMAL(15,2))` sebelum/sesudah.
6. Ulangi migrasi untuk membuktikan idempotensi; rehearsal failure-midway harus menggunakan restore karena DDL MariaDB implicit commit.
7. Baru jadwalkan produksi setelah client menyetujui downtime/range dan seluruh snapshot historis lulus.

## Status

`Rencana siap; migrasi belum dijalankan.` Audit sengaja tidak mengubah tipe pada database utama atau memasukkan DDL konversi sebelum coverage histori, lock-time, dan keputusan client tersedia. Sampai migrasi dilakukan, backend/verifier mempertahankan validasi finite/nonnegatif dan toleransi perbandingan Rp0,01.
