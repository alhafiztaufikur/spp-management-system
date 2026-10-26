# Tampilan struk dan ringkasan Komite, 26 Oktober 2026

## Penyebab dan perubahan

`payment_obligations()` sebelumnya hanya menghitung pembayaran Komite native,
sedangkan rincian formulir sudah menghitung kredit Legacy. Pada Raisa SMP
`252607034`, 14 periode Agustus 2025–September 2026 dengan pembayaran Legacy
Rp15.000 per bulan tampil keliru sebagai tunggakan Rp210.000.

Posisi Komite kini memakai `komite_bill_position()` bersama pada rincian,
payload formulir dan ringkasan. Ringkasan hanya memasukkan periode dengan sisa
positif; periode paling awal dan total berasal dari daftar yang sudah disaring.
SPP dan Komite Oktober Raisa tetap terutang Rp327.500 + Rp15.000. Aturan pembayaran
berpasangan, larangan pembayaran ganda dan pelestarian pembatalan tetap berlaku.
Pembacaan ringkasan memakai optimasi lingkup unit existing (`hp_sql`).

Label item pada riwayat, struk, serta rincian ekspor hanya memakai nama item.
ID transaksi sumber, kode asli, slot, master dan bukti keputusan tetap tersimpan
pada metadata/rantai sumber; tidak ada perubahan kategori atau penerimaan.
Sisa DU yang belum diketahui tetap null, dengan tampilan `-` pada dokumen.

## Verifikasi pada salinan terisolasi

Server: MySQL port 3319, UUID `8d21f5ab-d0ab-11f1-86d7-745d2224b37e`,
datadir privat berbeda dari server pengguna. HTTP pengujian: loopback port 8833.
Fixture perubahan finansial di-rollback atau dibersihkan hanya pada salinan.

- `legacy_receipt_komite_test.php`: **1.768 pemeriksaan**, termasuk **1.731 siswa**
  dengan tagihan Komite: SD 741, SMP 828, SMA 162. Bandingkan seluruh periode
  historis dalam ringkasan terhadap payload formulir, bukan hanya contoh Raisa.
- Uji pembayaran native + Legacy, sisa nyata, koreksi/pembatalan, periode
  mendatang per unit, metadata sumber dan nama item pada rincian/ekspor.
- `legacy_monthly_integration_test.php`: **30 pemeriksaan** lulus, termasuk
  pasangan SPP–Komite, penolakan pembayaran ganda, pembatalan dan tarif historis.
- Browser Chrome: **17 pemeriksaan** lulus. Pemilihan Raisa tidak memunculkan
  pop-up Komite semu; struk sumber `55313` menampilkan Formulir Pendaftaran
  Rp75.000, Sisa DU `-`, tanpa ID/kode/slot pada label. PDF dan PNG sungguhan
  diunduh; fixture tahunan menghasilkan ZIP berisi 12 gambar.
- Fixture PDF: normal, tahunan, rincian panjang, identitas opsional, terbilang
  panjang, Sisa DU belum diketahui, dan 12 struk dengan DU belum diketahui lulus.
- Fixture pelacakan biaya: **56 pemeriksaan** lulus; helper metadata tetap ada.
- Regresi Master Biaya Lain: **48 pemeriksaan HTTP** lulus setelah label tampilan
  disesuaikan. Tautan komponen, nominal ekspor dan pengamanan hapus tetap berlaku.
- Pemeriksaan baca saja pada server pengguna: ringkasan Raisa kosong, periode
  penghalang null, dan checksum **seluruh 62 tabel** sama sebelum/sesudah kerja.
  Bukti tersimpan pada `final-result.json` di direktori artefak privat.

Artefak privat:
`C:/laragon/data/spp-migrations/db_spp_test3/20261026-receipt-komite`.
Tidak ada migrasi ulang, backfill atau perubahan skema pada database pengguna.
`.gitignore` dan `koneksi.php` pengguna dipertahankan.
