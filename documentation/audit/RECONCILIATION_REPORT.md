# Laporan Rekonsiliasi Data Finansial SistemSPP

Tanggal: 20 Agustus 2026 (WIB)  
Sumber kontrak: `DATA_CONTRACT.md`  
Verifier: `sql/verify_data_integrity.sql`  
Target yang sudah dimutasi: hanya `db_spp_audit_20260820_090000`  
Target produksi/lokal utama `db_spp`: belum dimutasi oleh run audit ini.

## 1. Proteksi sebelum rekonsiliasi

- Backup baseline berhasil direstore dan row count 19 tabel identik.
- Salinan audit dianonimkan dan orphan check menghasilkan nol.
- Backup tambahan tepat sebelum rekonsiliasi: 49.146 byte, SHA-256 `9CAE444F692B4A4BFE4DA317DC95D78B805FBC2D515BAF536E1821782AACA3DE`.
- Semua query perubahan dijalankan hanya pada nama database audit eksplisit; `sql/schema.sql` tidak digunakan.

## 2. Hasil awal verifier

| Invariant/queue | Jumlah awal | Interpretasi |
| --- | ---: | --- |
| INV-003 - pembayaran SPP tanpa claim periode | 16 | Seluruh header SPP baseline belum mempunyai `bayar_spp_periode`. |
| INV-010 - cache biaya awal berbeda dari ledger | 2 | Dua row siswa mempunyai `*_BAYAR` yang tidak sama dengan agregat `bayar`. |
| INV-017 - operator pembayaran aman bukan admin ID | 16 | Header menyimpan username legacy; seluruhnya exact-match ke `admin.username` pada baseline. |
| Queue operator tabungan teks | 7 | Jurnal masuk menyimpan username legacy; seluruhnya exact-match ke `admin.username` pada baseline. |
| Invariant lain | 0 | INV-001, 002, 004-009, dan 011-016 lulus sebelum rekonsiliasi. |

Nilai di atas adalah hitungan, tanpa NIS/nama/operator pada laporan repository.

## 3. Aturan rekonsiliasi

1. Claim SPP hanya dibuat untuk header dengan `U_SPP > 0`, tahun empat digit, dan bulan yang dapat dinormalisasi secara eksplisit.
2. Satu header hanya boleh mempunyai satu claim melalui primary key `bayar_id`; repeat tidak boleh membuat duplikasi.
3. Cache `PANGKAL_BAYAR`, `BANGUNAN_BAYAR`, `SERAGAM_BAYAR`, dan `KEGIATAN_BAYAR` diselaraskan dari `SUM()` ledger `bayar`, bukan sebaliknya.
4. Operator hanya dikonversi bila `user_id` cocok persis dengan unique `admin.username` dan belum merupakan admin ID valid.
5. Nilai operator kosong/tidak dikenal/ambigu, pembayaran legacy v0, dan child tanpa parent tidak ditebak. Item tersebut harus tetap berada pada queue manual.

## 4. Eksekusi pada salinan audit

| Langkah | Run 1 | Run 2 | Hasil |
| --- | ---: | ---: | --- |
| `add_annual_payment_receipts.sql` | 16 claim tersedia | tetap 16 | Nol duplikasi claim. |
| `allow_spp_installments.sql` | index/kontrak tersedia | identik logis | Repeat aman. |
| `sync_student_initial_fee_paid_totals.sql` | 2 mismatch menjadi 0 | tetap 0 | Cache mengikuti ledger. |
| `normalize_transaction_operators.sql` - bayar | 16 diubah | 0 | Idempoten. |
| Normalisasi - transaksi masuk | 7 diubah | 0 | Idempoten. |
| Normalisasi - transaksi keluar | 0 diubah | 0 | Tidak ada row terkait. |

Control totals sebelum/sesudah rekonsiliasi:

- header pembayaran: 16;
- total `bayar.total_jumlah`: Rp15.865.000;
- jurnal tabungan masuk/keluar: 7 row;
- saldo bersih ledger tabungan: Rp825.000;
- duplicate claim group: 0.

Rekonsiliasi menambah claim dan memperbaiki cache/identitas operator; tidak menambah header pembayaran, jurnal tabungan, atau nominal finansial.

## 5. Hasil akhir verifier

`INV-001` sampai `INV-017` seluruhnya `PASS` dengan `issue_count=0`. Queue berikut seluruhnya `CLEAR`:

- `payment_link_version_0`;
- `bayar_du_without_bayar_id`;
- `bayar_du_without_bill_id`;
- `other_fee_detail_without_bill_id`;
- `savings_operator_legacy_text`.

Hasil ini membuktikan salinan audit setelah rekonsiliasi, bukan database utama atau host client.

## 6. Gate sebelum menjalankan pada database utama

- [x] Backup baseline dan restore drill.
- [x] Preview count tanpa PII.
- [x] Migrasi dijalankan dua kali pada salinan audit.
- [x] Fresh schema + seluruh 19 migrasi dua pass lulus matrix (run kanonik 2026-08-26).
- [x] Schema/data verifier pasca-migrasi lulus di disposable matrix.
- [ ] Backup tepat sebelum maintenance produksi dan checksum disetujui dua orang.
- [ ] Maintenance window menghentikan mutasi aplikasi.
- [ ] Preview produksi ulang membuktikan count/exact-match tidak berubah sejak audit.
- [ ] Pemilik data menyetujui operator mapping dan residual queue bila ada.
- [ ] Eksekusi produksi, verifier, smoke, dan sign-off.

Jika preview terbaru berbeda, hentikan prosedur dan lakukan rekonsiliasi manusia. Jangan mengubah filter migrasi agar sekadar membuat verifier hijau.
